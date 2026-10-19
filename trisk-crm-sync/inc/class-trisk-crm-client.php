<?php
/**
 * KeyCRM HTTP Client with Rate Limiting and Action Scheduler Retry Queue
 */

defined( 'ABSPATH' ) || exit;

class Trisk_CRM_Client {
	const BASE_URL = 'https://openapi.keycrm.app/v1';
	const MIN_INTERVAL = 3.2; // 20 req/min limit -> >= 3.0s, safe interval 3.2s
	const MAX_RETRY_ATTEMPTS = 4;
	const OPTION_LAST_REQUEST_TS = 'trisk_crm_last_request_ts';
	const AS_GROUP = 'trisk-crm-sync';

	/**
	 * Retryable HTTP status codes.
	 *
	 * @var int[]
	 */
	const RETRYABLE_CODES = array( 429, 500, 502, 503, 504 );

	/**
	 * Perform HTTP request to KeyCRM OpenAPI.
	 *
	 * @param string     $method
	 * @param string     $endpoint
	 * @param array|null $body
	 * @param array      $context
	 * @return array{ok: bool, code: int, data: mixed, error: string, log_id: int, retryable: bool}
	 */
	public static function request( string $method, string $endpoint, ?array $body = null, array $context = array() ): array {
		$method   = strtoupper( trim( $method ) );
		$base_url = (string) apply_filters( 'trisk_crm_api_base_url', self::BASE_URL );
		$url      = rtrim( $base_url, '/' ) . '/' . ltrim( $endpoint, '/' );

		$settings = trisk_crm_get_settings();
		$api_key  = (string) ( $context['api_key'] ?? ( $settings['api_key'] ?? '' ) );

		$attempt = absint( $context['attempt'] ?? 1 );
		if ( $attempt < 1 ) {
			$attempt = 1;
		}

		// Логування запиту
		$log_id = ! empty( $context['log_id'] ) ? absint( $context['log_id'] ) : 0;
		if ( ! $log_id ) {
			$log_payload = array(
				'_method'   => $method,
				'_endpoint' => $endpoint,
			);
			if ( null !== $body ) {
				$log_payload['body'] = $body;
			}

			$log_id = trisk_crm_log_create( array(
				'direction'   => 'out_crm',
				'event'       => sanitize_text_field( (string) ( $context['event'] ?? 'crm.api_request' ) ),
				'entity_type' => sanitize_text_field( (string) ( $context['entity_type'] ?? 'system' ) ),
				'entity_id'   => sanitize_text_field( (string) ( $context['entity_id'] ?? '' ) ),
				'status'      => 'pending',
				'attempts'    => $attempt,
				'payload'     => $log_payload,
			) );
		} else {
			trisk_crm_log_update( $log_id, array(
				'status'   => 'pending',
				'attempts' => $attempt,
			) );
		}

		// Контроль рейт-ліміту (20 зап/хв -> пауза 3,2 с)
		self::enforce_rate_limit( $endpoint, $method, $context );

		$headers = array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);

		$request_args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => (int) apply_filters( 'trisk_crm_api_timeout', 20 ),
		);

		if ( null !== $body ) {
			if ( 'GET' === $method && is_array( $body ) ) {
				$url = add_query_arg( $body, $url );
			} else {
				$request_args['body'] = wp_json_encode( $body );
			}
		}

		$response = wp_remote_request( $url, $request_args );

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

		// 2xx — успіх
		if ( $code >= 200 && $code < 300 ) {
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

		// Помилки
		$error_text = '';
		if ( is_array( $data ) && ! empty( $data['message'] ) ) {
			$error_text = (string) $data['message'];
		} elseif ( is_string( $data ) && '' !== trim( $data ) ) {
			$error_text = wp_strip_all_tags( $data );
		} else {
			$error_text = "HTTP {$code}";
		}

		$is_retryable = in_array( $code, self::RETRYABLE_CODES, true ) || ( $code >= 500 && $code <= 599 );

		if ( $is_retryable ) {
			$status = ( $attempt >= self::MAX_RETRY_ATTEMPTS ) ? 'error' : 'retrying';
		} else {
			$status = 'error';
		}

		trisk_crm_log_update( $log_id, array(
			'status'        => $status,
			'http_code'     => $code,
			'response'      => $data,
			'error_message' => $error_text,
		) );

		return array(
			'ok'        => false,
			'code'      => $code,
			'data'      => $data,
			'error'     => $error_text,
			'log_id'    => $log_id,
			'retryable' => $is_retryable,
		);
	}

	/**
	 * Enforce rate limiter (min interval 3.2s between requests).
	 *
	 * @param string $endpoint
	 * @param string $method
	 * @param array  $context
	 */
	protected static function enforce_rate_limit( string $endpoint, string $method, array $context ): void {
		$skip = ! empty( $context['skip_rate_limit'] )
			|| (bool) apply_filters( 'trisk_crm_skip_rate_limit', false, $endpoint, $method, $context );

		if ( ! $skip ) {
			$min_interval = (float) apply_filters( 'trisk_crm_min_request_interval', self::MIN_INTERVAL );
			$last_ts      = (float) get_option( self::OPTION_LAST_REQUEST_TS, 0 );
			$now          = microtime( true );

			if ( $last_ts > 0 && ( $now - $last_ts ) < $min_interval ) {
				$wait_sec = $min_interval - ( $now - $last_ts );
				if ( $wait_sec > 0 ) {
					usleep( (int) ( $wait_sec * 1000000 ) );
				}
			}
		}

		update_option( self::OPTION_LAST_REQUEST_TS, microtime( true ), false );
	}

	/**
	 * Schedule retry via Action Scheduler with exponential backoff: 30 * ( 4 ** ( attempt - 1 ) )s.
	 * Max attempts: 4.
	 *
	 * @param string $hook
	 * @param array  $args
	 * @param int    $attempt
	 * @param int    $log_id
	 * @return bool
	 */
	public static function schedule_retry( string $hook, array $args, int $attempt, int $log_id ): bool {
		$attempt = absint( $attempt );
		if ( $attempt > self::MAX_RETRY_ATTEMPTS ) {
			trisk_crm_log_update( $log_id, array(
				'status'   => 'error',
				'attempts' => $attempt,
			) );
			return false;
		}

		// Затримка: attempt 1 = 30s, 2 = 120s, 3 = 480s, 4 = 1920s
		$delay = (int) ( 30 * ( 4 ** ( $attempt - 1 ) ) );
		$when  = time() + $delay;

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $when, $hook, $args, self::AS_GROUP );
		} else {
			wp_schedule_single_event( $when, $hook, $args );
		}

		trisk_crm_log_update( $log_id, array(
			'status'   => 'retrying',
			'attempts' => $attempt,
		) );

		return true;
	}

	/**
	 * Retry log entry (manual from UI or recovery).
	 *
	 * @param int $log_id
	 * @return bool
	 */
	public static function retry_log_entry( int $log_id ): bool {
		$log = trisk_crm_log_get( $log_id );
		if ( ! $log ) {
			return false;
		}

		$event     = (string) ( $log['event'] ?? '' );
		$entity_id = (string) ( $log['entity_id'] ?? '' );
		$payload   = $log['payload'] ?? array();

		// Скидаємо статус перед перезапуском
		trisk_crm_log_update( $log_id, array(
			'status'   => 'pending',
			'attempts' => 1,
		) );

		// Подія push замовлення (Task 4)
		if ( 'order.push' === $event && ! empty( $entity_id ) ) {
			if ( function_exists( 'trisk_crm_push_order_now' ) ) {
				$res = trisk_crm_push_order_now( (int) $entity_id, array( 'log_id' => $log_id, 'attempt' => 1 ) );
				return ! empty( $res['ok'] );
			}
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time(), 'trisk_crm_async_push_order', array( 'order_id' => (int) $entity_id, 'attempt' => 1, 'log_id' => $log_id ), self::AS_GROUP );
				return true;
			}
			do_action( 'trisk_crm_async_push_order', (int) $entity_id, 1, $log_id );
			return true;
		}

		// Подія push статусу (Task 4)
		if ( ( 'order.status_push' === $event || 'order.status_update' === $event ) && ! empty( $entity_id ) ) {
			if ( function_exists( 'trisk_crm_push_order_now' ) ) {
				$res = trisk_crm_push_order_now( (int) $entity_id, array( 'log_id' => $log_id, 'attempt' => 1 ) );
				return ! empty( $res['ok'] );
			}
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time(), 'trisk_crm_async_push_status', array( 'order_id' => (int) $entity_id, 'attempt' => 1, 'log_id' => $log_id ), self::AS_GROUP );
				return true;
			}
			do_action( 'trisk_crm_async_push_status', (int) $entity_id, 1, $log_id );
			return true;
		}

		// Подія відправки/редагування в Telegram (Task 5)
		if ( ( 'tg.send' === $event || 'tg.edit' === $event || 'tg.sendMessage' === $event || 'tg.editMessageText' === $event ) && ! empty( $entity_id ) ) {
			if ( function_exists( 'trisk_crm_tg_sync_order_message' ) ) {
				$res = trisk_crm_tg_sync_order_message( (int) $entity_id );
				return (bool) ( $res['ok'] ?? false );
			}
		}

		// Загальний повтор API-запиту, якщо збережено endpoint і method
		if ( is_array( $payload ) && ! empty( $payload['_endpoint'] ) ) {
			$method   = (string) ( $payload['_method'] ?? 'GET' );
			$endpoint = (string) $payload['_endpoint'];
			$body     = $payload['body'] ?? null;

			$res = self::request( $method, $endpoint, $body, array(
				'log_id'          => $log_id,
				'attempt'         => 1,
				'event'           => $event,
				'entity_type'     => $log['entity_type'] ?? 'system',
				'entity_id'       => $entity_id,
				'skip_rate_limit' => true,
			) );

			return (bool) ( $res['ok'] ?? false );
		}

		// Кастомні фільтри/хуки повтору
		$handled = apply_filters( 'trisk_crm_retry_log_entry', false, $log );
		if ( $handled ) {
			return true;
		}

		if ( has_action( 'trisk_crm_retry_log_entry' ) ) {
			do_action( 'trisk_crm_retry_log_entry', $log );
			return true;
		}

		return false;
	}
}
