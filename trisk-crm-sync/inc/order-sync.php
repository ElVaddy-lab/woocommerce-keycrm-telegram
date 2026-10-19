<?php
/**
 * Procedural API for WooCommerce Order Synchronization with KeyCRM
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-trisk-crm-order-sync.php';

// Ініціалізація хуків
Trisk_CRM_Order_Sync::init();

/**
 * Enqueue order push to KeyCRM via Action Scheduler.
 *
 * @param int|WC_Order $order_id
 */
function trisk_crm_enqueue_order_push( $order_id ): void {
	Trisk_CRM_Order_Sync::enqueue_order_push( $order_id );
}

/**
 * Push or update order in KeyCRM immediately.
 *
 * @param int       $order_id
 * @param int|array $attempt
 * @return array{ok: bool, crm_order_id?: int, error?: string, retryable?: bool, log_id?: int}
 */
function trisk_crm_push_order_now( int $order_id, $attempt = 1 ): array {
	return Trisk_CRM_Order_Sync::push_order_now( $order_id, $attempt );
}

/**
 * Map WooCommerce status to KeyCRM status ID.
 *
 * @param string $wc_status
 * @param array  $status_map
 * @return int
 */
function trisk_crm_map_wc_status_to_crm( string $wc_status, array $status_map = array() ): int {
	return Trisk_CRM_Order_Sync::map_wc_status_to_crm( $wc_status, $status_map );
}

/**
 * Map KeyCRM status ID to WooCommerce status slug.
 *
 * @param int|string $crm_status_id
 * @param array      $status_map
 * @return string
 */
function trisk_crm_map_crm_status_to_wc( $crm_status_id, array $status_map = array() ): string {
	return Trisk_CRM_Order_Sync::map_crm_status_to_wc( $crm_status_id, $status_map );
}
