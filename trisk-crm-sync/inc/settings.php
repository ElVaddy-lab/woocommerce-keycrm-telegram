<?php
/**
 * Trisk CRM Settings procedural API
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-trisk-crm-settings.php';

function trisk_crm_get_settings(): array {
	return Trisk_CRM_Settings::get_settings();
}

function trisk_crm_update_settings( array $new ): array {
	return Trisk_CRM_Settings::update_settings( $new );
}

function trisk_crm_is_configured(): bool {
	return Trisk_CRM_Settings::is_configured();
}

function trisk_crm_tg_is_configured(): bool {
	return Trisk_CRM_Settings::tg_is_configured();
}
