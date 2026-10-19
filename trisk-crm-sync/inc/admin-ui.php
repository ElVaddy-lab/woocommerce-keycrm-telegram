<?php
/**
 * Procedural API for Trisk CRM Admin UI
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-trisk-crm-admin.php';

// Ініціалізація адмін-панелі
Trisk_CRM_Admin::init();

/**
 * Render Trisk CRM Admin Page HTML.
 */
function trisk_crm_render_admin_page(): void {
	Trisk_CRM_Admin::render_page();
}
