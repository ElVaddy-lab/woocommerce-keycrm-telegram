<?php
/**
 * Trisk CRM Settings Handler
 */

defined( 'ABSPATH' ) || exit;

class Trisk_CRM_Settings {
	const OPTION_KEY = 'trisk_crm_sync_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function get_defaults(): array {
		return array(
			'enabled'             => false,
			'api_key'             => '',
			'source_id'           => 1,
			'warehouse_id'        => 1,
			'webhook_secret'      => '',
			'status_map'          => array(
				'1' => 'on-hold',
				'2' => 'processing',
				'3' => 'processing',
				'4' => 'completed',
				'5' => 'cancelled',
			),
			'tg_enabled'          => false,
			'tg_bot_token'        => '',
			'tg_chat_id'          => '',
			'tg_thread_id'        => 0,
			'tg_allowed_user_ids' => '',
			'tg_webhook_secret'   => '',
			'activated_at'        => '',
		);
	}

	/**
	 * Get current settings with defaults and ENV/constant fallbacks.
	 *
	 * @return array
	 */
	public static function get_settings(): array {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$defaults = self::get_defaults();
		$settings = array_merge( $defaults, $saved );

		// Fallbacks for secrets:
		// 1) If set in ENV/constants, use dynamically without persisting to DB.
		// 2) If not in DB and not in ENV/constants, generate a random token and persist it.
		$dirty = false;
		if ( empty( $settings['webhook_secret'] ) ) {
			$secret = defined( 'TRISK_KEYCRM_WEBHOOK_SECRET' ) ? TRISK_KEYCRM_WEBHOOK_SECRET : ( getenv( 'TRISK_KEYCRM_WEBHOOK_SECRET' ) ?: '' );
			if ( ! empty( $secret ) ) {
				$settings['webhook_secret'] = (string) $secret;
			} else {
				$generated                  = wp_generate_password( 32, false );
				$settings['webhook_secret'] = $generated;
				$saved['webhook_secret']    = $generated;
				$dirty                      = true;
			}
		}

		if ( empty( $settings['tg_webhook_secret'] ) ) {
			$tg_secret = defined( 'TRISK_TG_WEBHOOK_SECRET' ) ? TRISK_TG_WEBHOOK_SECRET : ( getenv( 'TRISK_TG_WEBHOOK_SECRET' ) ?: '' );
			if ( ! empty( $tg_secret ) ) {
				$settings['tg_webhook_secret'] = (string) $tg_secret;
			} else {
				$generated                     = wp_generate_password( 32, false );
				$settings['tg_webhook_secret'] = $generated;
				$saved['tg_webhook_secret']    = $generated;
				$dirty                         = true;
			}
		}

		if ( $dirty ) {
			update_option( self::OPTION_KEY, $saved );
		}

		// Fallbacks for API credentials from constants or env
		if ( empty( $settings['api_key'] ) ) {
			$api_key = defined( 'TRISK_KEYCRM_API_KEY' ) ? TRISK_KEYCRM_API_KEY : ( getenv( 'TRISK_KEYCRM_API_KEY' ) ?: '' );
			if ( ! empty( $api_key ) ) {
				$settings['api_key'] = $api_key;
			}
		}

		if ( empty( $settings['tg_bot_token'] ) ) {
			$tg_token = defined( 'TRISK_TG_BOT_TOKEN' ) ? TRISK_TG_BOT_TOKEN : ( getenv( 'TRISK_TG_BOT_TOKEN' ) ?: '' );
			if ( ! empty( $tg_token ) ) {
				$settings['tg_bot_token'] = $tg_token;
			}
		}

		if ( empty( $settings['tg_chat_id'] ) ) {
			$tg_chat = defined( 'TRISK_TG_CHAT_ID' ) ? TRISK_TG_CHAT_ID : ( getenv( 'TRISK_TG_CHAT_ID' ) ?: '' );
			if ( ! empty( $tg_chat ) ) {
				$settings['tg_chat_id'] = (string) $tg_chat;
			}
		}

		if ( empty( $settings['tg_thread_id'] ) ) {
			$tg_thread = defined( 'TRISK_TG_THREAD_ID' ) ? TRISK_TG_THREAD_ID : ( getenv( 'TRISK_TG_THREAD_ID' ) ?: 0 );
			if ( ! empty( $tg_thread ) ) {
				$settings['tg_thread_id'] = absint( $tg_thread );
			}
		}

		if ( empty( $settings['source_id'] ) || 1 === (int) $settings['source_id'] ) {
			$source_id = defined( 'TRISK_KEYCRM_SOURCE_ID' ) ? TRISK_KEYCRM_SOURCE_ID : ( getenv( 'TRISK_KEYCRM_SOURCE_ID' ) ?: 0 );
			if ( ! empty( $source_id ) ) {
				$settings['source_id'] = absint( $source_id );
			}
		}

		if ( empty( $settings['warehouse_id'] ) || 1 === (int) $settings['warehouse_id'] ) {
			$warehouse_id = defined( 'TRISK_KEYCRM_WAREHOUSE_ID' ) ? TRISK_KEYCRM_WAREHOUSE_ID : ( getenv( 'TRISK_KEYCRM_WAREHOUSE_ID' ) ?: 0 );
			if ( ! empty( $warehouse_id ) ) {
				$settings['warehouse_id'] = absint( $warehouse_id );
			}
		}

		return $settings;
	}

	/**
	 * Update settings.
	 *
	 * @param array $new
	 * @return array
	 */
	public static function update_settings( array $new ): array {
		$existing = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$clean = $existing;

		if ( array_key_exists( 'enabled', $new ) ) {
			$clean['enabled'] = (bool) $new['enabled'];
		}
		if ( array_key_exists( 'api_key', $new ) ) {
			$clean['api_key'] = sanitize_text_field( (string) $new['api_key'] );
		}
		if ( array_key_exists( 'source_id', $new ) ) {
			$clean['source_id'] = absint( $new['source_id'] );
		}
		if ( array_key_exists( 'warehouse_id', $new ) ) {
			$clean['warehouse_id'] = absint( $new['warehouse_id'] );
		}
		if ( array_key_exists( 'webhook_secret', $new ) ) {
			$clean['webhook_secret'] = sanitize_text_field( (string) $new['webhook_secret'] );
		}
		if ( array_key_exists( 'status_map', $new ) && is_array( $new['status_map'] ) ) {
			$map = array();
			foreach ( $new['status_map'] as $k => $v ) {
				$map[ sanitize_text_field( (string) $k ) ] = sanitize_text_field( (string) $v );
			}
			$clean['status_map'] = $map;
		}
		if ( array_key_exists( 'tg_enabled', $new ) ) {
			$clean['tg_enabled'] = (bool) $new['tg_enabled'];
		}
		if ( array_key_exists( 'tg_bot_token', $new ) ) {
			$clean['tg_bot_token'] = sanitize_text_field( (string) $new['tg_bot_token'] );
		}
		if ( array_key_exists( 'tg_chat_id', $new ) ) {
			$clean['tg_chat_id'] = sanitize_text_field( (string) $new['tg_chat_id'] );
		}
		if ( array_key_exists( 'tg_thread_id', $new ) ) {
			$clean['tg_thread_id'] = absint( $new['tg_thread_id'] );
		}
		if ( array_key_exists( 'tg_allowed_user_ids', $new ) ) {
			$clean['tg_allowed_user_ids'] = self::sanitize_user_ids( $new['tg_allowed_user_ids'] );
		}
		if ( array_key_exists( 'tg_webhook_secret', $new ) ) {
			$clean['tg_webhook_secret'] = sanitize_text_field( (string) $new['tg_webhook_secret'] );
		}
		if ( array_key_exists( 'activated_at', $new ) ) {
			$clean['activated_at'] = sanitize_text_field( (string) $new['activated_at'] );
		}

		update_option( self::OPTION_KEY, $clean );

		return self::get_settings();
	}

	/**
	 * Sanitize a list of Telegram user IDs (comma-separated string or array) to "123,456".
	 *
	 * @param string|array $raw
	 * @return string
	 */
	public static function sanitize_user_ids( $raw ): string {
		$parts = is_array( $raw ) ? $raw : preg_split( '/[\s,;]+/', (string) $raw );
		$ids   = array();
		foreach ( (array) $parts as $part ) {
			$part = trim( (string) $part );
			if ( preg_match( '/^\d+$/', $part ) && (int) $part > 0 ) {
				$ids[ $part ] = $part;
			}
		}
		return implode( ',', array_values( $ids ) );
	}

	/**
	 * Check if KeyCRM is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$s = self::get_settings();
		return ! empty( $s['enabled'] ) && ! empty( $s['api_key'] );
	}

	/**
	 * Check if Telegram is configured.
	 *
	 * @return bool
	 */
	public static function tg_is_configured(): bool {
		$s = self::get_settings();
		return ! empty( $s['tg_enabled'] ) && ! empty( $s['tg_bot_token'] ) && ! empty( $s['tg_chat_id'] );
	}
}
