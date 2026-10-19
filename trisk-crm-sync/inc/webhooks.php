<?php
/**
 * Procedural API for Inbound KeyCRM Webhooks
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-trisk-crm-webhooks.php';

// Ініціалізація маршрутів REST API
Trisk_CRM_Webhooks::init();
