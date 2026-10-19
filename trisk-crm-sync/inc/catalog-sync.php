<?php
/**
 * Procedural API for Catalog Synchronization with KeyCRM
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-trisk-crm-catalog.php';

/**
 * Ensure deterministic SKUs (<parent_sku>-<weight>-<grind>) for all variations of curated products.
 *
 * @return array{products: int, variations: int, updated: int}
 */
function trisk_crm_ensure_variation_skus(): array {
	return Trisk_CRM_Catalog::ensure_variation_skus();
}

/**
 * Find product or variation IDs matching a raw SKU.
 *
 * @param string $raw_sku
 * @return int[]
 */
function trisk_crm_find_products_by_sku( string $raw_sku ): array {
	return Trisk_CRM_Catalog::find_products_by_sku( $raw_sku );
}

/**
 * Export a batch of curated products and offers to KeyCRM, pushing stocks on the final batch.
 *
 * @param int $offset
 * @param int $batch_size
 * @return array{total: int, processed: int, next_offset: int, done: bool, stocks_pushed: bool, errors: string[], updated_at: string}
 */
function trisk_crm_export_catalog_batch( int $offset = 0, int $batch_size = 3 ): array {
	return Trisk_CRM_Catalog::export_catalog_batch( $offset, $batch_size );
}

/**
 * Synchronize regular & sale prices from an array of offers.
 *
 * @param array<array{sku: string, price: float|int}> $offers
 * @return array{updated: int, skipped: int, duplicates: int}
 */
function trisk_crm_sync_prices_from_offers( array $offers ): array {
	return Trisk_CRM_Catalog::sync_prices_from_offers( $offers );
}

/**
 * Pull prices from KeyCRM API and synchronize regular and sale prices.
 *
 * @return array{updated: int, skipped: int, duplicates: int}
 */
function trisk_crm_pull_prices(): array {
	return Trisk_CRM_Catalog::pull_prices();
}
