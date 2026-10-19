<?php
/**
 * Procedural API for KeyCRM Client & Action Scheduler Retries
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-trisk-crm-client.php';

/**
 * Perform HTTP request to KeyCRM OpenAPI.
 *
 * @param string     $method
 * @param string     $endpoint
 * @param array|null $body
 * @param array      $context
 * @return array{ok: bool, code: int, data: mixed, error: string, log_id: int, retryable: bool}
 */
function trisk_crm_api_request( string $method, string $endpoint, ?array $body = null, array $context = array() ): array {
	return Trisk_CRM_Client::request( $method, $endpoint, $body, $context );
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
function trisk_crm_schedule_retry( string $hook, array $args, int $attempt, int $log_id ): bool {
	return Trisk_CRM_Client::schedule_retry( $hook, $args, $attempt, $log_id );
}

/**
 * Retry log entry manually or via recovery.
 *
 * @param int $log_id
 * @return bool
 */
function trisk_crm_retry_log_entry( int $log_id ): bool {
	return Trisk_CRM_Client::retry_log_entry( $log_id );
}
