<?php
/**
 * Trisk CRM Logger procedural API
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-trisk-crm-logger.php';

function trisk_crm_install_schema(): void {
	Trisk_CRM_Logger::install_schema();
}

function trisk_crm_log_create( array $data ): int {
	return Trisk_CRM_Logger::create( $data );
}

function trisk_crm_log_update( int $log_id, array $data ): void {
	Trisk_CRM_Logger::update( $log_id, $data );
}

function trisk_crm_log_get( int $log_id ): ?array {
	return Trisk_CRM_Logger::get( $log_id );
}

function trisk_crm_log_query( array $args = array() ): array {
	return Trisk_CRM_Logger::query( $args );
}

function trisk_crm_log_prune( int $keep = 500 ): int {
	return Trisk_CRM_Logger::prune( $keep );
}
