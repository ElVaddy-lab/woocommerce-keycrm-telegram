<?php
/**
 * Procedural API for Telegram Manager Bot Integration
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-trisk-crm-telegram.php';

// Ініціалізація хуків Telegram
Trisk_CRM_Telegram::init();

if ( ! function_exists( 'trisk_crm_tg_is_configured' ) ) {
	/**
	 * Check if Telegram integration is configured.
	 *
	 * @return bool
	 */
	function trisk_crm_tg_is_configured(): bool {
		return Trisk_CRM_Telegram::is_configured();
	}
}

/**
 * Build clean Ukrainian HTML formatted order message for Telegram.
 *
 * @param WC_Order $order
 * @return string
 */
function trisk_crm_tg_format_order_message( WC_Order $order ): string {
	return Trisk_CRM_Telegram::format_order_message( $order );
}

/**
 * Build Telegram inline keyboard with status buttons (2x2 layout).
 *
 * @param int    $order_id
 * @param string $current_stage
 * @return array
 */
function trisk_crm_tg_order_keyboard( int $order_id, string $current_stage = '' ): array {
	return Trisk_CRM_Telegram::order_keyboard( $order_id, $current_stage );
}

/**
 * Send HTTP request to Telegram Bot API.
 *
 * @param string $method
 * @param array  $params
 * @param array  $context
 * @return array
 */
function trisk_crm_tg_api_request( string $method, array $params = array(), array $context = array() ): array {
	return Trisk_CRM_Telegram::api_request( $method, $params, $context );
}

/**
 * Send or edit Telegram message for an order without creating duplicate messages.
 *
 * @param int $order_id
 * @param int $attempt
 * @return array
 */
function trisk_crm_tg_sync_order_message( int $order_id, int $attempt = 1 ): array {
	return Trisk_CRM_Telegram::sync_order_message( $order_id, $attempt );
}

/**
 * Handle incoming Telegram update (e.g. callback_query from manager inline button).
 *
 * @param array $update
 * @return array
 */
function trisk_crm_tg_handle_update( array $update ): array {
	return Trisk_CRM_Telegram::handle_update( $update );
}

/**
 * Detect supergroup/group chat and forum topic id from recent updates.
 *
 * @return array
 */
function trisk_crm_tg_detect_chat_and_topic(): array {
	return Trisk_CRM_Telegram::detect_chat_and_topic();
}

/**
 * Helper for local Docker testing: poll getUpdates and process any callback_query updates.
 *
 * @return array
 */
function trisk_crm_tg_poll_callbacks(): array {
	return Trisk_CRM_Telegram::poll_callbacks();
}
