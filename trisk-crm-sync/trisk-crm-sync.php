<?php
/**
 * Plugin Name: Тріск — синхронізація з KeyCRM та Telegram
 * Description: Двостороння інтеграція WooCommerce з KeyCRM (замовлення, статуси, залишки, ціни) та інтерактивний Telegram-бот для менеджерів.
 * Version: 0.1.0
 * Requires Plugins: woocommerce
 * Text Domain: trisk-crm-sync
 */

defined( 'ABSPATH' ) || exit;

define( 'TRISK_CRM_SYNC_VERSION', '0.1.0' );
define( 'TRISK_CRM_SYNC_DIR', __DIR__ );
define( 'TRISK_CRM_SYNC_FILE', __FILE__ );

// Завантаження базових модулів
require_once TRISK_CRM_SYNC_DIR . '/inc/settings.php';
require_once TRISK_CRM_SYNC_DIR . '/inc/logger.php';

// Опціональні модулі наступних завдань
foreach ( array( 'keycrm-client', 'catalog-sync', 'order-sync', 'webhooks', 'telegram', 'admin-ui' ) as $module ) {
	$file = TRISK_CRM_SYNC_DIR . "/inc/{$module}.php";
	if ( is_readable( $file ) ) {
		require_once $file;
	}
}

/**
 * Головний координатор плагіна trisk-crm-sync.
 */
class Trisk_CRM_Sync {
	/**
	 * Прапорець запобігання циклічним оновленням статусу з CRM.
	 *
	 * @var bool
	 */
	public static bool $syncing_from_crm = false;

	/**
	 * Прапорець запобігання циклічним оновленням статусу з Telegram.
	 *
	 * @var bool
	 */
	public static bool $syncing_from_tg = false;

	/**
	 * Ініціалізація плагіна.
	 */
	public static function init(): void {
		// Періодична синхронізація цін (щогодини)
		add_action( 'trisk_crm_sync_prices_cron', 'trisk_crm_pull_prices' );

		if ( class_exists( 'Trisk_CRM_Order_Sync' ) ) {
			Trisk_CRM_Order_Sync::init();
		}

		if ( class_exists( 'Trisk_CRM_Webhooks' ) ) {
			Trisk_CRM_Webhooks::init();
		}

		if ( class_exists( 'Trisk_CRM_Telegram' ) ) {
			Trisk_CRM_Telegram::init();
		}

		if ( class_exists( 'Trisk_CRM_Admin' ) ) {
			Trisk_CRM_Admin::init();
		}

		if ( ! wp_next_scheduled( 'trisk_crm_sync_prices_cron' ) ) {
			if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'trisk_crm_sync_prices_cron' ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, 'trisk_crm_sync_prices_cron', array(), 'trisk-crm-sync' );
			} elseif ( ! function_exists( 'as_has_scheduled_action' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'trisk_crm_sync_prices_cron' );
			}
		}
	}

	/**
	 * Активація плагіна.
	 */
	public static function activate(): void {
		trisk_crm_install_schema();

		$settings = trisk_crm_get_settings();
		if ( empty( $settings['activated_at'] ) ) {
			$settings['activated_at'] = gmdate( 'Y-m-d H:i:s' );
			trisk_crm_update_settings( $settings );
		}

		if ( function_exists( 'trisk_crm_ensure_variation_skus' ) ) {
			trisk_crm_ensure_variation_skus();
		}
	}
}

register_activation_hook( TRISK_CRM_SYNC_FILE, array( 'Trisk_CRM_Sync', 'activate' ) );
add_action( 'plugins_loaded', array( 'Trisk_CRM_Sync', 'init' ) );
