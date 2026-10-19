<?php
/**
 * WooCommerce Order Synchronization to KeyCRM
 */

defined( 'ABSPATH' ) || exit;

class Trisk_CRM_Order_Sync {
	const AS_HOOK = 'trisk_crm_push_order_job';
	const AS_GROUP = 'trisk-crm-sync';
	const META_PAYMENT_SYNCED = '_trisk_keycrm_payment_synced';

	protected static bool $initialized = false;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'enqueue_order_push' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'enqueue_order_push' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'enqueue_order_push' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 20, 4 );
		add_action( self::AS_HOOK, array( __CLASS__, 'handle_push_job' ), 10, 2 );
	}

	/**
	 * Hook handler for order status changed.
	 *
	 * @param int      $order_id
	 * @param string   $old_status
	 * @param string   $new_status
	 * @param WC_Order $order
	 */
	public static function on_order_status_changed( $order_id, $old_status = '', $new_status = '', $order = null ): void {
		self::enqueue_order_push( $order ?: $order_id );
	}

	/**
	 * Enqueue order push to KeyCRM via Action Scheduler.
	 *
	 * @param int|WC_Order $order_id_or_order
	 */
	public static function enqueue_order_push( $order_id_or_order ): void {
		// Захист від зациклення при оновленні з KeyCRM або з Telegram
		// (статус із Telegram уже відправляється в KeyCRM напряму в обробнику кнопки)
		if ( ! empty( Trisk_CRM_Sync::$syncing_from_crm ) || ! empty( Trisk_CRM_Sync::$syncing_from_tg ) ) {
			return;
		}

		if ( $order_id_or_order instanceof WC_Order ) {
			$order    = $order_id_or_order;
			$order_id = $order->get_id();
		} else {
			$order_id = absint( $order_id_or_order );
			$order    = wc_get_order( $order_id );
		}

		if ( ! $order || ! $order_id ) {
			return;
		}

		$settings = trisk_crm_get_settings();

		// Захист від історичних замовлень (створених до активації інтеграції)
		if ( ! empty( $settings['activated_at'] ) && ! $order->get_meta( '_trisk_crm_force_sync' ) ) {
			$created = $order->get_date_created();
			if ( $created && $created->getTimestamp() < strtotime( $settings['activated_at'] ) ) {
				return;
			}
		}

		// Дедуплікація черги Action Scheduler
		$action_args = array(
			'order_id' => $order_id,
			'attempt'  => 1,
		);

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::AS_HOOK, $action_args, self::AS_GROUP ) ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::AS_HOOK, $action_args, self::AS_GROUP );
		}
	}

	/**
	 * Action Scheduler worker callback.
	 *
	 * @param int $order_id
	 * @param int $attempt
	 */
	public static function handle_push_job( $order_id, $attempt = 1 ): void {
		self::push_order_now( (int) $order_id, (int) $attempt );
	}

	/**
	 * Push or update order in KeyCRM immediately.
	 *
	 * @param int       $order_id
	 * @param int|array $attempt
	 * @return array
	 */
	public static function push_order_now( int $order_id, $attempt = 1 ): array {
		$log_id      = 0;
		$attempt_num = 1;
		if ( is_array( $attempt ) ) {
			$log_id      = absint( $attempt['log_id'] ?? 0 );
			$attempt_num = absint( $attempt['attempt'] ?? 1 );
		} else {
			$attempt_num = absint( $attempt );
		}
		if ( $attempt_num < 1 ) {
			$attempt_num = 1;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'ok'        => false,
				'error'     => 'Order not found',
				'retryable' => false,
			);
		}

		$settings = trisk_crm_get_settings();
		if ( empty( $settings['api_key'] ) ) {
			return array(
				'ok'        => false,
				'error'     => 'KeyCRM API key is not configured',
				'retryable' => false,
			);
		}

		$crm_order_id = (int) $order->get_meta( '_trisk_keycrm_order_id' );

		// 1. Якщо замовлення вже синхронізовано з KeyCRM — оновлюємо статус через PUT /order/{id}
		if ( $crm_order_id > 0 ) {
			$stage         = (string) $order->get_meta( '_trisk_crm_stage' );
			$wc_status     = ( 'shipped' === $stage && 'processing' === $order->get_status() ) ? 'shipped' : $order->get_status();
			$status_map    = $settings['status_map'] ?? array();
			$crm_status_id = self::map_wc_status_to_crm( $wc_status, $status_map );

			$put_payload = array(
				'status_id' => (int) $crm_status_id,
			);

			$res = trisk_crm_api_request(
				'PUT',
				'/order/' . $crm_order_id,
				$put_payload,
				array(
					'event'       => 'order.status_update',
					'entity_type' => 'order',
					'entity_id'   => (string) $order_id,
					'attempt'     => $attempt_num,
					'log_id'      => $log_id,
				)
			);

			if ( $res['ok'] ) {
				$order->update_meta_data( '_trisk_keycrm_synced_at', current_time( 'mysql' ) );
				$order->save();
				$order->add_order_note( sprintf( 'Статус оновлено в KeyCRM (status_id: %d)', $crm_status_id ) );

				if ( function_exists( 'trisk_crm_tg_sync_order_message' ) ) {
					trisk_crm_tg_sync_order_message( $order_id );
				}

				// Замовлення вже в CRM, а оплата з'явилась пізніше (LiqPay) — передаємо її окремо
				$pay_res = self::maybe_sync_payment( $order, $crm_order_id, $attempt_num );
				if ( null !== $pay_res && empty( $pay_res['ok'] ) ) {
					if ( ! empty( $pay_res['retryable'] ) ) {
						trisk_crm_schedule_retry(
							self::AS_HOOK,
							array( 'order_id' => $order_id, 'attempt' => $attempt_num + 1 ),
							$attempt_num,
							$pay_res['log_id'] ?? 0
						);
					}
					return $pay_res;
				}

				return array(
					'ok'           => true,
					'crm_order_id' => $crm_order_id,
					'log_id'       => $res['log_id'] ?? 0,
				);
			}

			if ( ! empty( $res['retryable'] ) ) {
				trisk_crm_schedule_retry(
					self::AS_HOOK,
					array( 'order_id' => $order_id, 'attempt' => $attempt_num + 1 ),
					$attempt_num,
					$res['log_id'] ?? 0
				);
			}

			return $res;
		}

		// 2. Нове замовлення — створюємо через POST /order
		$payload = self::build_order_payload( $order, $settings );

		$res = trisk_crm_api_request(
			'POST',
			'/order',
			$payload,
			array(
				'event'       => 'order.push',
				'entity_type' => 'order',
				'entity_id'   => (string) $order_id,
				'attempt'     => $attempt_num,
				'log_id'      => $log_id,
			)
		);

		// Обробка 422: дублікат source_uuid -> знаходимо існуюче замовлення в KeyCRM
		if ( 422 === ( $res['code'] ?? 0 ) ) {
			$err_string = strtolower( is_string( $res['error'] ?? '' ) ? $res['error'] : wp_json_encode( $res['data'] ?? '' ) );
			if ( strpos( $err_string, 'source uuid' ) !== false || strpos( $err_string, 'source_uuid' ) !== false ) {
				$find_res     = trisk_crm_api_request(
					'GET',
					'/order?filter[source_uuid]=' . rawurlencode( (string) $order_id ),
					null,
					array(
						'event'       => 'order.find',
						'entity_type' => 'order',
						'entity_id'   => (string) $order_id,
					)
				);
				$found_crm_id = self::extract_order_id_from_response( $find_res );

				if ( $found_crm_id > 0 ) {
					$order->update_meta_data( '_trisk_keycrm_order_id', $found_crm_id );
					$order->update_meta_data( '_trisk_keycrm_synced_at', current_time( 'mysql' ) );
					$order->save();
					$order->add_order_note( sprintf( 'KeyCRM: замовлення знайдено за source_uuid (CRM ID: #%d)', $found_crm_id ) );

					if ( function_exists( 'trisk_crm_tg_sync_order_message' ) ) {
						trisk_crm_tg_sync_order_message( $order_id );
					}

					if ( ! empty( $res['log_id'] ) ) {
						trisk_crm_log_update( $res['log_id'], array(
							'status'        => 'success',
							'http_code'     => 200,
							'response'      => array(
								'resolved_via' => 'source_uuid_lookup',
								'crm_order_id' => $found_crm_id,
							),
							'error_message' => null,
						) );
					}

					return array(
						'ok'           => true,
						'crm_order_id' => $found_crm_id,
						'log_id'       => $res['log_id'] ?? 0,
					);
				}
			}
		}

		// Успішне створення
		if ( $res['ok'] ) {
			$created_crm_id = self::extract_order_id_from_response( $res );
			$order->update_meta_data( '_trisk_keycrm_order_id', $created_crm_id );
			$order->update_meta_data( '_trisk_keycrm_synced_at', current_time( 'mysql' ) );
			// Якщо замовлення створене вже оплаченим — оплата пішла в payload, окремо її слати не треба
			if ( 'paid' === ( $payload['payments'][0]['status'] ?? '' ) ) {
				$order->update_meta_data( self::META_PAYMENT_SYNCED, '1' );
			}
			$order->save();
			$order->add_order_note( sprintf( 'Замовлення передано в KeyCRM (CRM ID: #%d)', $created_crm_id ) );

			if ( function_exists( 'trisk_crm_tg_sync_order_message' ) ) {
				trisk_crm_tg_sync_order_message( $order_id );
			}

			return array(
				'ok'           => true,
				'crm_order_id' => $created_crm_id,
				'log_id'       => $res['log_id'] ?? 0,
			);
		}

		// Повторювана помилка
		if ( ! empty( $res['retryable'] ) ) {
			trisk_crm_schedule_retry(
				self::AS_HOOK,
				array( 'order_id' => $order_id, 'attempt' => $attempt_num + 1 ),
				$attempt_num,
				$res['log_id'] ?? 0
			);
			return $res;
		}

		// Фатальна помилка
		$order->add_order_note( sprintf( 'Помилка передачі в KeyCRM: %s', $res['error'] ?? 'Unknown error' ) );
		return $res;
	}

	/**
	 * Чи сплачене замовлення (та сама логіка, що й у payload створення замовлення).
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	protected static function is_order_paid( WC_Order $order ): bool {
		return $order->is_paid() || ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) && $order->get_payment_method() !== 'cod' );
	}

	/**
	 * Передати оплату в KeyCRM для замовлення, яке вже там створене, один раз.
	 *
	 * Повертає null, якщо робити нічого не треба (не сплачено або вже передано).
	 *
	 * @param WC_Order $order
	 * @param int      $crm_order_id
	 * @param int      $attempt_num
	 * @return array|null
	 */
	protected static function maybe_sync_payment( WC_Order $order, int $crm_order_id, int $attempt_num ): ?array {
		if ( $order->get_meta( self::META_PAYMENT_SYNCED ) || ! self::is_order_paid( $order ) ) {
			return null;
		}

		$res = self::sync_payment( $order, $crm_order_id, $attempt_num );
		if ( ! empty( $res['ok'] ) ) {
			$order->update_meta_data( self::META_PAYMENT_SYNCED, '1' );
			$order->save();
			$order->add_order_note( 'Оплату передано в KeyCRM' );
		}

		return $res;
	}

	/**
	 * Позначити оплату замовлення в KeyCRM як сплачену.
	 *
	 * Документація (docs.keycrm.app/api.yaml):
	 * - GET /order/{orderId}?include=payments — платежі замовлення з їхніми id;
	 * - PUT /order/{orderId}/payment/{paymentId} (updateExistingOrderPayment) — змінити статус наявного платежу;
	 * - POST /order/{orderId}/payment (createNewOrderPayment) — додати новий платіж, якщо наявного немає.
	 * Якщо наявний платіж not_paid — оновлюємо його, щоб не дублювати суму в CRM.
	 *
	 * @param WC_Order $order
	 * @param int      $crm_order_id
	 * @param int      $attempt_num
	 * @return array
	 */
	protected static function sync_payment( WC_Order $order, int $crm_order_id, int $attempt_num ): array {
		$order_id = $order->get_id();
		$context  = array(
			'event'       => 'order.payment',
			'entity_type' => 'order',
			'entity_id'   => (string) $order_id,
			'attempt'     => $attempt_num,
		);

		$transaction_id = $order->get_transaction_id();
		$description    = $transaction_id ? 'Transaction ID: ' . $transaction_id : '';

		$get_res = trisk_crm_api_request( 'GET', '/order/' . $crm_order_id . '?include=payments', null, $context );
		if ( empty( $get_res['ok'] ) ) {
			return $get_res;
		}

		$data     = is_array( $get_res['data'] ?? null ) ? $get_res['data'] : array();
		$payments = $data['payments'] ?? ( $data['data']['payments'] ?? array() );
		if ( ! is_array( $payments ) ) {
			$payments = array();
		}

		$not_paid_id = 0;
		foreach ( $payments as $payment ) {
			if ( ! is_array( $payment ) ) {
				continue;
			}
			if ( 'paid' === ( $payment['status'] ?? '' ) ) {
				// Уже сплачено в CRM (наприклад, менеджер відмітив вручну) — нічого не дублюємо
				return array(
					'ok'        => true,
					'code'      => 200,
					'data'      => $payment,
					'error'     => '',
					'log_id'    => $get_res['log_id'] ?? 0,
					'retryable' => false,
				);
			}
			if ( ! $not_paid_id && 'not_paid' === ( $payment['status'] ?? '' ) && ! empty( $payment['id'] ) ) {
				$not_paid_id = (int) $payment['id'];
			}
		}

		if ( $not_paid_id > 0 ) {
			$body = array( 'status' => 'paid' );
			if ( '' !== $description ) {
				$body['description'] = $description;
			}
			return trisk_crm_api_request( 'PUT', '/order/' . $crm_order_id . '/payment/' . $not_paid_id, $body, $context );
		}

		$body = array(
			'payment_method' => $order->get_payment_method_title() ?: (string) $order->get_payment_method(),
			'amount'         => (float) $order->get_total(),
			'status'         => 'paid',
		);
		if ( '' !== $description ) {
			$body['description'] = $description;
		}

		return trisk_crm_api_request( 'POST', '/order/' . $crm_order_id . '/payment', $body, $context );
	}

	/**
	 * Build KeyCRM OpenAPI POST /order payload.
	 *
	 * @param WC_Order $order
	 * @param array    $settings
	 * @return array
	 */
	public static function build_order_payload( WC_Order $order, array $settings ): array {
		$order_id  = $order->get_id();
		$source_id = ! empty( $settings['source_id'] ) ? (int) $settings['source_id'] : 1;

		$date_created = $order->get_date_created();
		// KeyCRM працює в UTC, а $date_created->date() дає час сайту
		$ordered_at   = $date_created ? gmdate( 'Y-m-d H:i:s', $date_created->getTimestamp() ) : gmdate( 'Y-m-d H:i:s' );

		$buyer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $buyer_name ) {
			$buyer_name = 'Покупець #' . $order_id;
		}

		$buyer = array(
			'full_name' => $buyer_name,
			'email'     => (string) $order->get_billing_email(),
			'phone'     => (string) $order->get_billing_phone(),
		);

		$shipping_city   = (string) ( $order->get_meta( '_trisk_np_city' ) ?: $order->get_billing_city() );
		$shipping_branch = (string) ( $order->get_meta( '_trisk_np_branch' ) ?: $order->get_billing_address_1() );
		$shipping_title  = (string) $order->get_shipping_method();
		if ( '' === $shipping_title ) {
			$shipping_title = $order->has_shipping_method( 'local_pickup' ) ? 'Самовивіз з обсмажувальні (Київ)' : 'Нова Пошта, відділення';
		}

		$shipping = array(
			'shipping_service'         => $shipping_title,
			'shipping_address_city'    => $shipping_city,
			'shipping_receive_point'   => $shipping_branch,
			'shipping_address_country' => 'UA',
		);

		$products = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$sku     = '';
			if ( $product ) {
				$sku = $product->get_sku( 'edit' );
				if ( empty( $sku ) && $product->is_type( 'variation' ) ) {
					$parent = wc_get_product( $product->get_parent_id() );
					$sku    = $parent ? $parent->get_sku( 'edit' ) : '';
				}
			}

			$price    = (float) $order->get_item_total( $item, false, true );
			$quantity = (int) $item->get_quantity();
			$name     = self::clean_item_name( $item->get_name() );

			$picture = '';
			if ( $product ) {
				$img_id = $product->get_image_id();
				if ( ! $img_id && $product->is_type( 'variation' ) ) {
					$parent = wc_get_product( $product->get_parent_id() );
					$img_id = $parent ? $parent->get_image_id() : 0;
				}
				if ( $img_id ) {
					$picture = wp_get_attachment_image_url( $img_id, 'full' ) ?: '';
				}
			}

			$properties = array();
			if ( $product && $product->is_type( 'variation' ) ) {
				$attrs = $product->get_attributes();
				foreach ( $attrs as $attr_slug => $attr_val ) {
					$label       = wc_attribute_label( $attr_slug );
					$display_val = $attr_val;
					if ( taxonomy_exists( $attr_slug ) ) {
						$term = get_term_by( 'slug', $attr_val, $attr_slug );
						if ( $term && ! is_wp_error( $term ) ) {
							$display_val = $term->name;
						}
					}
					$properties[] = array(
						'name'  => $label ?: $attr_slug,
						'value' => (string) $display_val,
					);
				}
			}

			$products[] = array(
				'sku'        => $sku,
				'price'      => $price,
				'quantity'   => $quantity,
				'name'       => $name,
				'picture'    => $picture,
				'properties' => $properties,
			);
		}

		$is_paid              = self::is_order_paid( $order );
		$payment_method_title = $order->get_payment_method_title() ?: (string) $order->get_payment_method();
		$transaction_id       = $order->get_transaction_id();
		$payment_desc         = $transaction_id ? 'Transaction ID: ' . $transaction_id : '';

		$payments = array(
			array(
				'payment_method' => $payment_method_title,
				'amount'         => (float) $order->get_total(),
				'status'         => $is_paid ? 'paid' : 'not_paid',
				'description'    => $payment_desc,
			),
		);

		return array(
			'source_id'     => $source_id,
			'source_uuid'   => (string) $order_id,
			'ordered_at'    => $ordered_at,
			'buyer_comment' => (string) $order->get_customer_note(),
			'buyer'         => $buyer,
			'shipping'      => $shipping,
			'products'      => $products,
			'payments'      => $payments,
		);
	}

	/**
	 * Назва позиції без HTML: у назвах варіацій Тріска роздільник збережено як `<span> - </span>`.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function clean_item_name( string $name ): string {
		$name = wp_strip_all_tags( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) );
		return trim( preg_replace( '/\s+/u', ' ', $name ) );
	}

	/**
	 * Extract order ID from KeyCRM API response.
	 *
	 * @param array $res
	 * @return int
	 */
	public static function extract_order_id_from_response( array $res ): int {
		$data = $res['data'] ?? null;
		if ( is_array( $data ) ) {
			if ( isset( $data['id'] ) && is_numeric( $data['id'] ) ) {
				return (int) $data['id'];
			}
			if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
				if ( isset( $data['data']['id'] ) && is_numeric( $data['data']['id'] ) ) {
					return (int) $data['data']['id'];
				}
				if ( isset( $data['data'][0]['id'] ) && is_numeric( $data['data'][0]['id'] ) ) {
					return (int) $data['data'][0]['id'];
				}
			}
			if ( isset( $data[0]['id'] ) && is_numeric( $data[0]['id'] ) ) {
				return (int) $data[0]['id'];
			}
		}
		return 0;
	}

	/**
	 * Bidirectional mapping: WooCommerce status slug -> KeyCRM status_id.
	 *
	 * @param string $wc_status
	 * @param array  $status_map
	 * @return int
	 */
	public static function map_wc_status_to_crm( string $wc_status, array $status_map = array() ): int {
		$wc_slug = preg_replace( '/^wc-/', '', trim( $wc_status ) );

		// 1. Прямий випадок: status_map = [ wc_slug => crm_status_id ]
		if ( isset( $status_map[ $wc_slug ] ) && is_numeric( $status_map[ $wc_slug ] ) ) {
			return (int) $status_map[ $wc_slug ];
		}

		// 2. Зворотний випадок (типовий): status_map = [ crm_status_id => wc_slug ]
		// Неуспішні й повернені замовлення в CRM — ті самі скасування.
		$lookup_slug = in_array( $wc_slug, array( 'failed', 'refunded' ), true ) ? 'cancelled' : $wc_slug;
		$candidates  = array();
		foreach ( $status_map as $crm_id => $val ) {
			$val_slug = preg_replace( '/^wc-/', '', trim( (string) $val ) );
			if ( $val_slug === $lookup_slug && is_numeric( $crm_id ) ) {
				$candidates[] = (int) $crm_id;
			}
		}

		if ( ! empty( $candidates ) ) {
			// Кільком статусам CRM відповідає один статус сайту (напр. 13–19 → cancelled).
			// Беремо той, чий системний alias у KeyCRM збігається зі статусом сайту, інакше — перший.
			$preferred_aliases = array(
				'completed' => array( 'completed' ),
				'cancelled' => array( 'canceled', 'cancelled' ),
				'shipped'   => array( 'delivered_to_delivery' ),
			);
			if ( count( $candidates ) > 1 && isset( $preferred_aliases[ $lookup_slug ] ) ) {
				$meta = get_option( 'trisk_crm_keycrm_meta', array() );
				foreach ( (array) ( $meta['statuses'] ?? array() ) as $st ) {
					$alias = strtolower( (string) ( $st['alias'] ?? ( $st['name'] ?? '' ) ) );
					$st_id = (int) ( $st['id'] ?? 0 );
					if ( in_array( $st_id, $candidates, true ) && in_array( $alias, $preferred_aliases[ $lookup_slug ], true ) ) {
						return $st_id;
					}
				}
			}
			return $candidates[0];
		}

		// За замовчуванням
		$fallbacks = array(
			'pending'    => 1,
			'on-hold'    => 1,
			'processing' => 2,
			'shipped'    => 3,
			'completed'  => 4,
			'cancelled'  => 5,
			'failed'     => 5,
			'refunded'   => 5,
		);

		return $fallbacks[ $wc_slug ] ?? 2;
	}

	/**
	 * Bidirectional mapping: KeyCRM status_id -> WooCommerce status slug.
	 * Повертає '' для статусу, якого немає в таблиці відповідності.
	 *
	 * @param int|string $crm_status_id
	 * @param array      $status_map
	 * @return string
	 */
	public static function map_crm_status_to_wc( $crm_status_id, array $status_map = array() ): string {
		$id_str = (string) $crm_status_id;

		// 1. Прямий випадок (типовий): status_map = [ crm_status_id => wc_slug ]
		if ( isset( $status_map[ $id_str ] ) ) {
			return preg_replace( '/^wc-/', '', trim( (string) $status_map[ $id_str ] ) );
		}

		// 2. Зворотний випадок: status_map = [ wc_slug => crm_status_id ]
		foreach ( $status_map as $wc_slug => $val ) {
			if ( (string) $val === $id_str ) {
				return preg_replace( '/^wc-/', '', trim( (string) $wc_slug ) );
			}
		}

		// ID статусів у KeyCRM свої для кожного акаунта: невідомий статус не мапимо
		// (порожній рядок = нічого не змінювати у WooCommerce)
		return '';
	}
}
