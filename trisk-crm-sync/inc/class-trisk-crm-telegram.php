<?php
/**
 * Telegram Manager Bot Integration for Trisk Shop
 * Handles forum topic order cards, inline buttons, deduplication, and in-place updates.
 */

defined( 'ABSPATH' ) || exit;

class Trisk_CRM_Telegram {
	const TELEGRAM_API_BASE   = 'https://api.telegram.org/bot';
	const AS_HOOK             = 'trisk_crm_tg_sync_order_job';
	const AS_GROUP            = 'trisk-crm-sync';
	const MAX_RETRY_ATTEMPTS  = 4;

	protected static bool $initialized = false;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'enqueue_order_sync' ), 25, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'enqueue_order_sync' ), 25, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 25, 4 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'on_order_updated' ), 25, 1 );
		add_action( self::AS_HOOK, array( __CLASS__, 'handle_sync_job' ), 10, 2 );
	}

	/**
	 * Check if Telegram integration is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$settings = trisk_crm_get_settings();
		return ! empty( $settings['tg_enabled'] )
			&& ! empty( $settings['tg_bot_token'] )
			&& ! empty( $settings['tg_chat_id'] );
	}

	/**
	 * Handle order status changed event.
	 *
	 * @param int      $order_id
	 * @param string   $old_status
	 * @param string   $new_status
	 * @param WC_Order $order
	 */
	public static function on_order_status_changed( $order_id, $old_status = '', $new_status = '', $order = null ): void {
		self::enqueue_order_sync( $order ?: $order_id );
	}

	/**
	 * Handle order updated in admin.
	 *
	 * @param int $order_id
	 */
	public static function on_order_updated( $order_id ): void {
		self::enqueue_order_sync( $order_id );
	}

	/**
	 * Enqueue order sync to Telegram via Action Scheduler.
	 *
	 * @param int|WC_Order $order_id_or_order
	 */
	public static function enqueue_order_sync( $order_id_or_order ): void {
		if ( ! empty( Trisk_CRM_Sync::$syncing_from_tg ) ) {
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

		if ( ! self::is_configured() ) {
			return;
		}

		$settings  = trisk_crm_get_settings();
		$tg_msg_id = $order->get_meta( '_trisk_tg_message_id' );

		// Historical order protection
		if ( empty( $tg_msg_id ) && ! empty( $settings['activated_at'] ) && ! $order->get_meta( '_trisk_crm_force_sync' ) ) {
			$created = $order->get_date_created();
			if ( $created && $created->getTimestamp() < strtotime( $settings['activated_at'] ) ) {
				return;
			}
		}

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
	public static function handle_sync_job( $order_id, $attempt = 1 ): void {
		self::sync_order_message( (int) $order_id, (int) $attempt );
	}

	/**
	 * Build clean Ukrainian HTML formatted order message for Telegram.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function format_order_message( WC_Order $order ): string {
		$order_id = $order->get_id();

		// Статус або кастомний етап
		$stage = (string) $order->get_meta( '_trisk_crm_stage' );
		if ( empty( $stage ) ) {
			$stage = $order->get_status();
		}

		$stage_labels = array(
			'processing' => '⚙️ В роботі',
			'shipped'    => '📦 Відправлено',
			'completed'  => '✅ Виконано',
			'cancelled'  => '❌ Скасовано',
			'on-hold'    => '⏳ В очікуванні',
			'pending'    => '🆕 Нове',
			'failed'     => '❌ Не вдалось',
			'refunded'   => '↩️ Повернено',
		);

		$status_label = $stage_labels[ $stage ] ?? ( wc_get_order_status_name( $order->get_status() ) ?: $stage );

		$lines = array();

		// Заголовок
		$lines[] = sprintf( '☕ <b>Замовлення #%d</b> — <b>%s</b>', $order_id, esc_html( $status_label ) );

		// Покупець
		$buyer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $buyer_name ) {
			$buyer_name = 'Покупець #' . $order_id;
		}
		$phone = trim( (string) $order->get_billing_phone() );
		$email = trim( (string) $order->get_billing_email() );

		$buyer_line = '👤 <b>Клієнт:</b> ' . esc_html( $buyer_name );
		if ( '' !== $phone ) {
			$buyer_line .= ' · <code>' . esc_html( $phone ) . '</code>';
		}
		$lines[] = $buyer_line;
		if ( '' !== $email ) {
			$lines[] = '✉️ <b>Email:</b> ' . esc_html( $email );
		}

		// Доставка
		$shipping_city   = trim( (string) ( $order->get_meta( '_trisk_np_city' ) ?: $order->get_billing_city() ) );
		$shipping_branch = trim( (string) ( $order->get_meta( '_trisk_np_branch' ) ?: $order->get_billing_address_1() ) );
		$shipping_title  = trim( (string) $order->get_shipping_method() );
		if ( '' === $shipping_title ) {
			$shipping_title = $order->has_shipping_method( 'local_pickup' )
				? 'Самовивіз з обсмажувальні (Київ)'
				: 'Нова Пошта, відділення';
		}

		$shipping_parts = array_filter( array( $shipping_city, $shipping_branch ) );
		$shipping_details = ! empty( $shipping_parts ) ? ': ' . implode( ', ', $shipping_parts ) : '';
		$lines[] = '🚚 <b>Доставка:</b> ' . esc_html( $shipping_title ) . esc_html( $shipping_details );

		// Оплата
		$payment_title = $order->get_payment_method_title() ?: (string) $order->get_payment_method();
		if ( '' === $payment_title ) {
			$payment_title = 'Оплата карткою';
		}
		$is_paid     = $order->is_paid() || ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) && $order->get_payment_method() !== 'cod' );
		$paid_status = $is_paid ? 'оплачено' : 'не оплачено';
		$total       = number_format( (float) $order->get_total(), 0, '', ' ' );

		$lines[] = sprintf( '💳 <b>Оплата:</b> %s — <b>%s ₴</b> (%s)', esc_html( $payment_title ), esc_html( $total ), esc_html( $paid_status ) );

		// Склад замовлення
		$lines[] = '';
		$lines[] = '📦 <b>Склад замовлення:</b>';

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

			// Для варіації — назва батьківського товару, а вага й помел ідуть у дужках (у назві варіації вони вже є).
			$display_name = Trisk_CRM_Order_Sync::clean_item_name( $item->get_name() );
			$attrs_desc   = array();
			if ( $product && $product->is_type( 'variation' ) ) {
				$parent_product = wc_get_product( $product->get_parent_id() );
				if ( $parent_product ) {
					$display_name = Trisk_CRM_Order_Sync::clean_item_name( $parent_product->get_name() );
				}
				$attrs = $product->get_attributes();
				foreach ( $attrs as $attr_slug => $attr_val ) {
					$display_val = $attr_val;
					if ( taxonomy_exists( $attr_slug ) ) {
						$term = get_term_by( 'slug', $attr_val, $attr_slug );
						if ( $term && ! is_wp_error( $term ) ) {
							$display_val = $term->name;
						}
					}
					$attrs_desc[] = $display_val;
				}
			}

			$attrs_str  = ! empty( $attrs_desc ) ? ' (' . implode( ', ', $attrs_desc ) . ')' : '';
			$sku_str    = ! empty( $sku ) ? ' [<code>' . esc_html( $sku ) . '</code>]' : '';
			$qty        = (int) $item->get_quantity();
			$line_total = number_format( (float) $order->get_line_total( $item, false, true ), 0, '', ' ' );

			$lines[] = sprintf(
				'• %s%s%s × %d — %s ₴',
				esc_html( $display_name ),
				esc_html( $attrs_str ),
				$sku_str,
				$qty,
				esc_html( $line_total )
			);
		}

		// Підсумок
		$lines[] = '';
		$lines[] = sprintf( '💰 <b>Разом: %s ₴</b>', esc_html( $total ) );

		// Коментар клієнта
		$note = trim( (string) $order->get_customer_note() );
		if ( '' !== $note ) {
			$lines[] = '💬 <b>Коментар:</b> ' . esc_html( $note );
		}

		// KeyCRM ID
		$crm_id = $order->get_meta( '_trisk_keycrm_order_id' );
		if ( ! empty( $crm_id ) ) {
			$lines[] = '🔗 <b>KeyCRM ID:</b> #' . esc_html( (string) $crm_id );
		}

		// Відмітка менеджера
		$manager_note = $order->get_meta( '_trisk_tg_manager_note' );
		if ( ! empty( $manager_note ) ) {
			$lines[] = '👨‍💼 <b>Обробив:</b> ' . esc_html( (string) $manager_note );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build Telegram inline keyboard with status buttons (2x2 layout).
	 *
	 * @param int    $order_id
	 * @param string $current_stage
	 * @return array
	 */
	public static function order_keyboard( int $order_id, string $current_stage = '' ): array {
		$labels = array(
			'processing' => '⚙️ В роботі',
			'shipped'    => '📦 Відправлено',
			'completed'  => '✅ Виконано',
			'cancelled'  => '❌ Скасовано',
		);

		return array(
			'inline_keyboard' => array(
				array(
					array(
						'text'          => ( $current_stage === 'processing' ? '• ' : '' ) . $labels['processing'],
						'callback_data' => "trisk_st:{$order_id}:processing",
					),
					array(
						'text'          => ( $current_stage === 'shipped' ? '• ' : '' ) . $labels['shipped'],
						'callback_data' => "trisk_st:{$order_id}:shipped",
					),
				),
				array(
					array(
						'text'          => ( $current_stage === 'completed' ? '• ' : '' ) . $labels['completed'],
						'callback_data' => "trisk_st:{$order_id}:completed",
					),
					array(
						'text'          => ( $current_stage === 'cancelled' ? '• ' : '' ) . $labels['cancelled'],
						'callback_data' => "trisk_st:{$order_id}:cancelled",
					),
				),
			),
		);
	}

	/**
	 * Send HTTP request to Telegram Bot API.
	 *
	 * @param string $method
	 * @param array  $params
	 * @param array  $context
	 * @return array
	 */
	public static function api_request( string $method, array $params = array(), array $context = array() ): array {
		$settings  = trisk_crm_get_settings();
		$bot_token = (string) ( $context['bot_token'] ?? ( $settings['tg_bot_token'] ?? '' ) );
		if ( empty( $bot_token ) ) {
			return array(
				'ok'        => false,
				'code'      => 0,
				'error'     => 'Telegram bot token is not configured',
				'retryable' => false,
			);
		}

		$url     = self::TELEGRAM_API_BASE . $bot_token . '/' . ltrim( $method, '/' );
		$attempt = absint( $context['attempt'] ?? 1 );
		if ( $attempt < 1 ) {
			$attempt = 1;
		}

		$log_id = ! empty( $context['log_id'] ) ? absint( $context['log_id'] ) : 0;
		if ( ! $log_id ) {
			$log_id = trisk_crm_log_create( array(
				'direction'   => 'out_tg',
				'event'       => sanitize_text_field( (string) ( $context['event'] ?? 'tg.' . $method ) ),
				'entity_type' => sanitize_text_field( (string) ( $context['entity_type'] ?? 'system' ) ),
				'entity_id'   => sanitize_text_field( (string) ( $context['entity_id'] ?? '' ) ),
				'status'      => 'pending',
				'attempts'    => $attempt,
				'payload'     => $params,
			) );
		}

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode( $params ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$status        = ( $attempt >= self::MAX_RETRY_ATTEMPTS ) ? 'error' : 'retrying';

			trisk_crm_log_update( $log_id, array(
				'status'        => $status,
				'http_code'     => 0,
				'response'      => array( 'error' => $error_message ),
				'error_message' => $error_message,
			) );

			return array(
				'ok'        => false,
				'code'      => 0,
				'data'      => null,
				'error'     => $error_message,
				'log_id'    => $log_id,
				'retryable' => true,
			);
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );
		$data     = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : $raw_body;

		// 2xx успіх
		if ( $code >= 200 && $code < 300 && is_array( $data ) && ! empty( $data['ok'] ) ) {
			trisk_crm_log_update( $log_id, array(
				'status'        => 'success',
				'http_code'     => $code,
				'response'      => $data,
				'error_message' => null,
			) );

			return array(
				'ok'        => true,
				'code'      => $code,
				'data'      => $data,
				'error'     => '',
				'log_id'    => $log_id,
				'retryable' => false,
			);
		}

		$desc = is_array( $data ) ? ( $data['description'] ?? '' ) : (string) $data;

		// Benign 400 Bad Request: message is not modified — текст і кнопки ті самі, редагувати нічого
		if ( 400 === $code && strpos( strtolower( $desc ), 'message is not modified' ) !== false ) {
			trisk_crm_log_update( $log_id, array(
				'status'        => 'skipped',
				'http_code'     => $code,
				'response'      => $data,
				'error_message' => null,
			) );

			return array(
				'ok'              => true,
				'is_not_modified' => true,
				'code'            => $code,
				'data'            => $data,
				'error'           => '',
				'log_id'          => $log_id,
				'retryable'       => false,
			);
		}

		$is_retryable = in_array( $code, array( 429, 500, 502, 503, 504 ), true ) || ( $code >= 500 && $code <= 599 );
		$status       = $is_retryable ? ( ( $attempt >= self::MAX_RETRY_ATTEMPTS ) ? 'error' : 'retrying' ) : 'error';

		trisk_crm_log_update( $log_id, array(
			'status'        => $status,
			'http_code'     => $code,
			'response'      => $data,
			'error_message' => $desc ?: "HTTP {$code}",
		) );

		return array(
			'ok'        => false,
			'code'      => $code,
			'data'      => $data,
			'error'     => $desc ?: "HTTP {$code}",
			'log_id'    => $log_id,
			'retryable' => $is_retryable,
		);
	}

	/**
	 * Send or edit Telegram message for an order without creating duplicate messages.
	 *
	 * @param int $order_id
	 * @param int $attempt
	 * @return array
	 */
	public static function sync_order_message( int $order_id, int $attempt = 1 ): array {
		if ( ! self::is_configured() ) {
			return array(
				'ok'      => false,
				'skipped' => 'not_configured',
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'ok'        => false,
				'error'     => 'Order not found',
				'retryable' => false,
			);
		}

		$settings  = trisk_crm_get_settings();
		$tg_msg_id = $order->get_meta( '_trisk_tg_message_id' );

		// Захист від історичних замовлень (створених до активації інтеграції)
		if ( empty( $tg_msg_id ) ) {
			if ( ! empty( $settings['activated_at'] ) && ! $order->get_meta( '_trisk_crm_force_sync' ) ) {
				$created = $order->get_date_created();
				if ( $created && $created->getTimestamp() < strtotime( $settings['activated_at'] ) ) {
					return array(
						'ok'      => false,
						'skipped' => 'old_order',
					);
				}
			}
		}

		$stage    = (string) ( $order->get_meta( '_trisk_crm_stage' ) ?: $order->get_status() );
		$text     = self::format_order_message( $order );
		$keyboard = self::order_keyboard( $order_id, $stage );

		// 1. Первинна відправка (sendMessage)
		if ( empty( $tg_msg_id ) ) {
			$chat_id   = (string) $settings['tg_chat_id'];
			$thread_id = (int) ( $settings['tg_thread_id'] ?? 0 );

			$params = array(
				'chat_id'      => $chat_id,
				'text'         => $text,
				'parse_mode'   => 'HTML',
				'reply_markup' => $keyboard,
			);

			if ( $thread_id > 0 ) {
				$params['message_thread_id'] = $thread_id;
			}

			$res = self::api_request( 'sendMessage', $params, array(
				'event'       => 'tg.send',
				'entity_type' => 'order',
				'entity_id'   => (string) $order_id,
				'attempt'     => $attempt,
			) );

			if ( $res['ok'] ) {
				$res_data   = $res['data']['result'] ?? $res['data'];
				$new_msg_id = (int) ( $res_data['message_id'] ?? 0 );

				if ( $new_msg_id > 0 ) {
					$order->update_meta_data( '_trisk_tg_message_id', $new_msg_id );
					$order->update_meta_data( '_trisk_tg_chat_id', $chat_id );
					$order->save();

					return array(
						'ok'         => true,
						'action'     => 'sent',
						'message_id' => $new_msg_id,
						'log_id'     => $res['log_id'] ?? 0,
					);
				}
			}

			if ( ! empty( $res['retryable'] ) && $attempt < self::MAX_RETRY_ATTEMPTS ) {
				trisk_crm_schedule_retry(
					self::AS_HOOK,
					array( 'order_id' => $order_id, 'attempt' => $attempt + 1 ),
					$attempt,
					$res['log_id'] ?? 0
				);
			}

			return $res;
		}

		// 2. Редагування на місці (editMessageText)
		$chat_id = (string) ( $order->get_meta( '_trisk_tg_chat_id' ) ?: $settings['tg_chat_id'] );
		$params  = array(
			'chat_id'      => $chat_id,
			'message_id'   => (int) $tg_msg_id,
			'text'         => $text,
			'parse_mode'   => 'HTML',
			'reply_markup' => $keyboard,
		);

		$res = self::api_request( 'editMessageText', $params, array(
			'event'       => 'tg.edit',
			'entity_type' => 'order',
			'entity_id'   => (string) $order_id,
			'attempt'     => $attempt,
		) );

		if ( $res['ok'] ) {
			if ( ! empty( $res['is_not_modified'] ) ) {
				return array(
					'ok'         => true,
					'action'     => 'not_modified',
					'message_id' => (int) $tg_msg_id,
					'log_id'     => $res['log_id'] ?? 0,
				);
			}

			return array(
				'ok'         => true,
				'action'     => 'edited',
				'message_id' => (int) $tg_msg_id,
				'log_id'     => $res['log_id'] ?? 0,
			);
		}

		if ( ! empty( $res['retryable'] ) && $attempt < self::MAX_RETRY_ATTEMPTS ) {
			trisk_crm_schedule_retry(
				self::AS_HOOK,
				array( 'order_id' => $order_id, 'attempt' => $attempt + 1 ),
				$attempt,
				$res['log_id'] ?? 0
			);
		}

		return $res;
	}

	/**
	 * Handle incoming Telegram update (e.g. callback_query from manager inline button).
	 *
	 * @param array $update
	 * @return array
	 */
	public static function handle_update( array $update ): array {
		if ( empty( $update['callback_query'] ) ) {
			return array(
				'ok'      => true,
				'skipped' => 'no_callback_query',
			);
		}

		$cb          = $update['callback_query'];
		$callback_id = (string) ( $cb['id'] ?? '' );
		$data        = (string) ( $cb['data'] ?? '' );
		$from        = (array) ( $cb['from'] ?? array() );

		$manager_display = ! empty( $from['username'] )
			? '@' . $from['username']
			: ( ! empty( $from['first_name'] ) ? $from['first_name'] : 'Менеджер' );

		if ( ! preg_match( '/^trisk_st:(\d+):(processing|shipped|completed|cancelled)$/', $data, $matches ) ) {
			if ( $callback_id ) {
				self::api_request( 'answerCallbackQuery', array(
					'callback_query_id' => $callback_id,
					'text'              => 'Невідома дія',
				) );
			}
			return array(
				'ok'      => true,
				'skipped' => 'invalid_callback_data',
			);
		}

		$order_id = (int) $matches[1];
		$action   = $matches[2];

		// Кнопки приймаємо лише з налаштованої групи менеджерів і (якщо задано) лише від дозволених user ID
		$settings         = trisk_crm_get_settings();
		$cb_chat_id       = (string) ( $cb['message']['chat']['id'] ?? '' );
		$cb_from_id       = (string) ( $from['id'] ?? '' );
		$allowed_user_ids = array_filter( array_map( 'trim', explode( ',', (string) ( $settings['tg_allowed_user_ids'] ?? '' ) ) ) );

		$denied = '' === $cb_chat_id || (string) $settings['tg_chat_id'] !== $cb_chat_id;
		if ( ! $denied && ! empty( $allowed_user_ids ) && ! in_array( $cb_from_id, $allowed_user_ids, true ) ) {
			$denied = true;
		}

		if ( $denied ) {
			if ( $callback_id ) {
				self::api_request( 'answerCallbackQuery', array(
					'callback_query_id' => $callback_id,
					'text'              => 'Немає доступу',
					'show_alert'        => true,
				) );
			}

			trisk_crm_log_create( array(
				'direction'   => 'in_tg',
				'event'       => 'tg.callback',
				'entity_type' => 'order',
				'entity_id'   => (string) $order_id,
				'status'      => 'skipped',
				'http_code'   => 200,
				'payload'     => array(
					'chat_id' => $cb_chat_id,
					'from_id' => $cb_from_id,
					'data'    => $data,
				),
				'response'    => array( 'skipped' => 'forbidden' ),
			) );

			return array(
				'ok'      => true,
				'skipped' => 'forbidden',
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			if ( $callback_id ) {
				self::api_request( 'answerCallbackQuery', array(
					'callback_query_id' => $callback_id,
					'text'              => 'Замовлення не знайдено',
					'show_alert'        => true,
				) );
			}
			return array(
				'ok'    => false,
				'error' => 'Order not found',
			);
		}

		$status_map = $settings['status_map'] ?? array();

		$action_map = array(
			'processing' => array(
				'wc_status' => 'processing',
				'stage'     => 'processing',
				'label'     => 'В роботі',
				'crm_id'    => Trisk_CRM_Order_Sync::map_wc_status_to_crm( 'processing', $status_map ),
			),
			'shipped'    => array(
				'wc_status' => 'processing',
				'stage'     => 'shipped',
				'label'     => 'Відправлено',
				'crm_id'    => Trisk_CRM_Order_Sync::map_wc_status_to_crm( 'shipped', $status_map ),
			),
			'completed'  => array(
				'wc_status' => 'completed',
				'stage'     => 'completed',
				'label'     => 'Виконано',
				'crm_id'    => Trisk_CRM_Order_Sync::map_wc_status_to_crm( 'completed', $status_map ),
			),
			'cancelled'  => array(
				'wc_status' => 'cancelled',
				'stage'     => 'cancelled',
				'label'     => 'Скасовано',
				'crm_id'    => Trisk_CRM_Order_Sync::map_wc_status_to_crm( 'cancelled', $status_map ),
			),
		);

		$info      = $action_map[ $action ];
		$wc_status = $info['wc_status'];
		$stage     = $info['stage'];
		$label     = $info['label'];
		$crm_id    = $info['crm_id'];

		$note_text = "{$manager_display} — {$label}";

		$order->update_meta_data( '_trisk_crm_stage', $stage );
		$order->update_meta_data( '_trisk_tg_manager_note', $note_text );

		Trisk_CRM_Sync::$syncing_from_tg = true;
		try {
			// Оновлення статусу у WooCommerce
			if ( $order->get_status() !== $wc_status ) {
				$order->update_status(
					$wc_status,
					sprintf( 'Статус оновлено з Telegram (%s): %s', $manager_display, $label )
				);
			} else {
				$order->save();
				$order->add_order_note( sprintf( 'Етап замовлення оновлено з Telegram (%s): %s', $manager_display, $label ) );
			}

			// Push у KeyCRM
			$crm_order_id = (int) $order->get_meta( '_trisk_keycrm_order_id' );
			if ( $crm_order_id > 0 ) {
				trisk_crm_api_request(
					'PUT',
					'/order/' . $crm_order_id,
					array( 'status_id' => $crm_id ),
					array(
						'event'       => 'order.status_update',
						'entity_type' => 'order',
						'entity_id'   => (string) $order_id,
					)
				);
			} elseif ( function_exists( 'trisk_crm_push_order_now' ) ) {
				trisk_crm_push_order_now( $order_id );
			}

			// Редагуємо повідомлення в Telegram на місці
			self::sync_order_message( $order_id );

			// Відповідь на callback query
			if ( $callback_id ) {
				self::api_request( 'answerCallbackQuery', array(
					'callback_query_id' => $callback_id,
					'text'              => "Статус змінено: {$label}",
				) );
			}
		} finally {
			Trisk_CRM_Sync::$syncing_from_tg = false;
		}

		trisk_crm_log_create( array(
			'direction'   => 'in_tg',
			'event'       => 'tg.callback',
			'entity_type' => 'order',
			'entity_id'   => (string) $order_id,
			'status'      => 'success',
			'http_code'   => 200,
			'payload'     => $update,
			'response'    => array(
				'order_id' => $order_id,
				'stage'    => $stage,
				'label'    => $label,
				'manager'  => $manager_display,
			),
		) );

		return array(
			'ok'       => true,
			'order_id' => $order_id,
			'stage'    => $stage,
			'label'    => $label,
			'manager'  => $manager_display,
		);
	}

	/**
	 * Detect supergroup/group chat and forum topic id from recent updates.
	 *
	 * @return array
	 */
	public static function detect_chat_and_topic(): array {
		$res = self::api_request( 'getUpdates', array( 'limit' => 50 ) );
		if ( ! $res['ok'] ) {
			return array(
				'ok'    => false,
				'error' => $res['error'] ?? 'Failed to get updates',
			);
		}

		$updates = $res['data']['result'] ?? array();
		if ( ! is_array( $updates ) ) {
			$updates = array();
		}

		foreach ( array_reverse( $updates ) as $up ) {
			$msg = $up['message'] ?? ( $up['channel_post'] ?? ( $up['callback_query']['message'] ?? null ) );
			if ( ! is_array( $msg ) || empty( $msg['chat'] ) ) {
				continue;
			}

			$chat      = $msg['chat'];
			$chat_type = (string) ( $chat['type'] ?? '' );
			if ( in_array( $chat_type, array( 'group', 'supergroup', 'channel' ), true ) ) {
				return array(
					'ok'         => true,
					'chat_id'    => (string) $chat['id'],
					'chat_title' => (string) ( $chat['title'] ?? '' ),
					'thread_id'  => (int) ( $msg['message_thread_id'] ?? 0 ),
					'updates'    => $updates,
				);
			}
		}

		return array(
			'ok'      => false,
			'error'   => 'No group or supergroup message found in recent updates',
			'updates' => $updates,
		);
	}

	/**
	 * Helper for local Docker testing: poll getUpdates and process any callback_query updates.
	 *
	 * @return array
	 */
	public static function poll_callbacks(): array {
		$last_id = (int) get_option( 'trisk_crm_tg_last_update_id', 0 );
		$params  = array(
			'limit' => 50,
		);
		if ( $last_id > 0 ) {
			$params['offset'] = $last_id + 1;
		}

		$res = self::api_request( 'getUpdates', $params );
		if ( ! $res['ok'] ) {
			return array(
				'ok'    => false,
				'error' => $res['error'] ?? 'getUpdates failed',
			);
		}

		$updates = $res['data']['result'] ?? array();
		if ( ! is_array( $updates ) ) {
			$updates = array();
		}

		$processed = 0;
		foreach ( $updates as $up ) {
			$up_id = (int) ( $up['update_id'] ?? 0 );
			if ( $up_id > $last_id ) {
				$last_id = $up_id;
				update_option( 'trisk_crm_tg_last_update_id', $last_id, false );
			}

			if ( ! empty( $up['callback_query'] ) ) {
				self::handle_update( $up );
				$processed++;
			}
		}

		return array(
			'ok'        => true,
			'processed' => $processed,
		);
	}
}
