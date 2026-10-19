<?php
/**
 * KeyCRM Inbound Webhooks Handler (Status changes and Warehouse stock)
 */

defined( 'ABSPATH' ) || exit;

class Trisk_CRM_Webhooks {
	const REST_NAMESPACE = 'trisk-crm/v1';
	const REST_ROUTE = '/webhook/keycrm';
	const REST_ROUTE_TELEGRAM = '/webhook/telegram';

	protected static bool $initialized = false;

	/**
	 * Register REST routes.
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register KeyCRM and Telegram webhook endpoints.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => array( __CLASS__, 'verify_token' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_TELEGRAM,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_telegram_webhook' ),
				'permission_callback' => array( __CLASS__, 'verify_telegram_token' ),
			)
		);
	}

	/**
	 * Verify Telegram webhook authentication token.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public static function verify_telegram_token( WP_REST_Request $request ) {
		$settings = trisk_crm_get_settings();
		$secret   = (string) ( $settings['tg_webhook_secret'] ?? '' );

		// Тільки заголовок: токен у query string потрапляв би в журнали веб-сервера
		$token = $request->get_header( 'x-telegram-bot-api-secret-token' );

		if ( empty( $secret ) || empty( $token ) || ! hash_equals( $secret, (string) $token ) ) {
			trisk_crm_log_create( array(
				'direction'     => 'in_tg',
				'event'         => 'tg.webhook.auth',
				'entity_type'   => 'system',
				'status'        => 'error',
				'http_code'     => 403,
				'error_message' => 'Invalid Telegram webhook secret',
				'payload'       => array(
					'has_token' => ! empty( $token ),
				),
			) );

			return new WP_Error(
				'trisk_crm_forbidden',
				'Invalid Telegram webhook secret',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle incoming Telegram webhook updates.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_telegram_webhook( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$raw  = $request->get_body();
			$body = json_decode( $raw, true );
		}

		if ( ! is_array( $body ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'Invalid JSON payload',
				),
				400
			);
		}

		if ( function_exists( 'trisk_crm_tg_handle_update' ) ) {
			$res = trisk_crm_tg_handle_update( $body );
			$http_code = ! empty( $res['ok'] ) ? 200 : ( $res['code'] ?? 400 );
			return new WP_REST_Response( $res, $http_code );
		}

		return new WP_REST_Response(
			array(
				'ok'    => false,
				'error' => 'Telegram module is not available',
			),
			500
		);
	}

	/**
	 * Verify webhook authentication token.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public static function verify_token( WP_REST_Request $request ) {
		$settings = trisk_crm_get_settings();
		$secret   = (string) ( $settings['webhook_secret'] ?? '' );

		$token = $request->get_param( 'token' );
		if ( empty( $token ) ) {
			$token = $request->get_header( 'x-trisk-token' );
		}
		if ( empty( $token ) ) {
			$auth = (string) $request->get_header( 'authorization' );
			if ( preg_match( '/Bearer\s+(.*)$/i', $auth, $matches ) ) {
				$token = trim( $matches[1] );
			}
		}

		if ( empty( $secret ) || empty( $token ) || ! hash_equals( $secret, (string) $token ) ) {
			trisk_crm_log_create( array(
				'direction'     => 'in_crm',
				'event'         => 'webhook.auth',
				'entity_type'   => 'system',
				'status'        => 'error',
				'http_code'     => 403,
				'error_message' => 'Invalid webhook token',
				'payload'       => array(
					'has_token' => ! empty( $token ),
				),
			) );

			return new WP_Error(
				'trisk_crm_forbidden',
				'Invalid webhook token',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Dispatch inbound webhook.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$raw  = $request->get_body();
			$body = json_decode( $raw, true );
		}

		if ( ! is_array( $body ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'Invalid JSON payload',
				),
				400
			);
		}

		// Сценарій А: Зміна статусу замовлення
		if ( isset( $body['event'] ) && 'order.change_order_status' === $body['event'] ) {
			return self::handle_status_change( $body );
		}

		// Сценарій Б: Оновлення залишків товарів / варіацій
		if ( self::is_stock_payload( $body ) ) {
			return self::handle_stock_update( $body );
		}

		trisk_crm_log_create( array(
			'direction'     => 'in_crm',
			'event'         => 'webhook.unknown',
			'entity_type'   => 'system',
			'status'        => 'error',
			'http_code'     => 400,
			'error_message' => 'Unrecognized webhook event',
			'payload'       => $body,
		) );

		return new WP_REST_Response(
			array(
				'ok'    => false,
				'error' => 'Unrecognized webhook payload structure',
			),
			400
		);
	}

	/**
	 * Detect if payload represents stock updates.
	 *
	 * @param array $body
	 * @return bool
	 */
	protected static function is_stock_payload( array $body ): bool {
		if ( isset( $body[0] ) && is_array( $body[0] ) ) {
			return isset( $body[0]['sku'] ) || isset( $body[0]['in_stock'] ) || isset( $body[0]['offer_id'] );
		}
		if ( isset( $body['stocks'] ) && is_array( $body['stocks'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Handle order status change webhook.
	 *
	 * @param array $body
	 * @return WP_REST_Response
	 */
	protected static function handle_status_change( array $body ): WP_REST_Response {
		$context       = (array) ( $body['context'] ?? array() );
		$source_uuid   = (string) ( $context['source_uuid'] ?? '' );
		$crm_order_id  = (int) ( $context['id'] ?? 0 );
		$crm_status_id = (int) ( $context['status_id'] ?? 0 );

		$order = null;
		if ( '' !== $source_uuid && is_numeric( $source_uuid ) ) {
			$order = wc_get_order( (int) $source_uuid );
		}

		if ( ! $order && $crm_order_id > 0 ) {
			$orders = wc_get_orders( array(
				'limit'      => 1,
				'meta_key'   => '_trisk_keycrm_order_id',
				'meta_value' => $crm_order_id,
			) );
			if ( ! empty( $orders ) ) {
				$order = $orders[0];
			}
		}

		if ( ! $order ) {
			trisk_crm_log_create( array(
				'direction'     => 'in_crm',
				'event'         => 'webhook.status',
				'entity_type'   => 'order',
				'entity_id'     => $source_uuid ?: (string) $crm_order_id,
				'status'        => 'error',
				'http_code'     => 404,
				'error_message' => 'Order not found for status update',
				'payload'       => $body,
			) );

			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'Order not found',
				),
				404
			);
		}

		$settings         = trisk_crm_get_settings();
		$target_wc_status = Trisk_CRM_Order_Sync::map_crm_status_to_wc( $crm_status_id, $settings['status_map'] ?? array() );

		// Статусу немає в таблиці відповідності: замовлення не чіпаємо, але відповідаємо 200, щоб KeyCRM не повторював вебхук
		if ( '' === $target_wc_status ) {
			trisk_crm_log_create( array(
				'direction'     => 'in_crm',
				'event'         => 'webhook.status',
				'entity_type'   => 'order',
				'entity_id'     => (string) $order->get_id(),
				'status'        => 'skipped',
				'http_code'     => 200,
				'payload'       => $body,
				'response'      => array(
					'skipped'       => 'unmapped_status',
					'crm_status_id' => $crm_status_id,
				),
			) );

			return new WP_REST_Response(
				array(
					'ok'       => true,
					'skipped'  => 'unmapped_status',
					'order_id' => $order->get_id(),
				),
				200
			);
		}

		if ( 'shipped' === $target_wc_status ) {
			$order->update_meta_data( '_trisk_crm_stage', 'shipped' );
			$wc_status_to_set = 'processing';
		} else {
			$order->update_meta_data( '_trisk_crm_stage', $target_wc_status );
			$wc_status_to_set = $target_wc_status;
		}

		// Захист від петлі: ставимо прапорець, щоб woocommerce_order_status_changed не надсилав назад у KeyCRM
		Trisk_CRM_Sync::$syncing_from_crm = true;
		try {
			$order->update_status(
				$wc_status_to_set,
				sprintf( 'Статус оновлено з KeyCRM (status_id: %d)', $crm_status_id )
			);
			$order->save();
		} finally {
			Trisk_CRM_Sync::$syncing_from_crm = false;
		}

		if ( function_exists( 'trisk_crm_tg_sync_order_message' ) ) {
			trisk_crm_tg_sync_order_message( $order->get_id() );
		}

		trisk_crm_log_create( array(
			'direction'   => 'in_crm',
			'event'       => 'webhook.status',
			'entity_type' => 'order',
			'entity_id'   => (string) $order->get_id(),
			'status'      => 'success',
			'http_code'   => 200,
			'payload'     => $body,
			'response'    => array(
				'order_id'      => $order->get_id(),
				'status'        => $target_wc_status,
				'crm_status_id' => $crm_status_id,
			),
		) );

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'order_id' => $order->get_id(),
				'status'   => $target_wc_status,
			),
			200
		);
	}

	/**
	 * Handle warehouse stock webhook.
	 *
	 * @param array $body
	 * @return WP_REST_Response
	 */
	protected static function handle_stock_update( array $body ): WP_REST_Response {
		$items = isset( $body['stocks'] ) ? $body['stocks'] : ( isset( $body['context'] ) ? $body['context'] : $body );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$updated_count = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$raw_sku = trim( (string) ( $item['sku'] ?? '' ) );
			if ( '' === $raw_sku ) {
				continue;
			}

			$matching_ids = trisk_crm_find_products_by_sku( $raw_sku );
			if ( empty( $matching_ids ) ) {
				continue;
			}

			$in_stock   = (int) ( $item['in_stock'] ?? 0 );
			$in_reserve = (int) ( $item['in_reserve'] ?? 0 );
			$available  = max( 0, $in_stock - $in_reserve );

			foreach ( $matching_ids as $pid ) {
				wc_update_product_stock( $pid, $available, 'set' );
				$updated_count++;
			}
		}

		if ( function_exists( 'trisk_feed_flush' ) ) {
			trisk_feed_flush();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		trisk_crm_log_create( array(
			'direction'   => 'in_crm',
			'event'       => 'webhook.stock',
			'entity_type' => 'catalog',
			'status'      => 'success',
			'http_code'   => 200,
			'payload'     => $body,
			'response'    => array(
				'updated' => $updated_count,
			),
		) );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'updated' => $updated_count,
			),
			200
		);
	}
}
