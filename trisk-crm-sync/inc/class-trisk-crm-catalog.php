<?php
/**
 * Catalog Synchronization with KeyCRM:
 * - Deterministic variation SKU generator (<parent_sku>-<weight>-<grind>)
 * - Batch catalog & offers export (POST /products, POST /products/{id}/offers, PUT /offers/stocks)
 * - Smart regular/sale price synchronization from KeyCRM offers
 */

defined( 'ABSPATH' ) || exit;

class Trisk_CRM_Catalog {
	const OPTION_EXPORT_STATE = 'trisk_crm_catalog_export_state';

	/**
	 * Normalize weight slug or text to SKU token: 250g, 500g, 1kg.
	 *
	 * @param string $val
	 * @return string
	 */
	public static function normalize_weight_sku( string $val ): string {
		$val = strtolower( trim( $val ) );
		if ( in_array( $val, array( '250-g', '250g', '250', '250 г' ), true ) ) {
			return '250g';
		}
		if ( in_array( $val, array( '500-g', '500g', '500', '500 г' ), true ) ) {
			return '500g';
		}
		if ( in_array( $val, array( '1-kg', '1kg', '1000g', '1000-g', '1000', '1 кг' ), true ) ) {
			return '1kg';
		}

		$cleaned = (string) preg_replace( '/[^a-z0-9]/', '', $val );
		return $cleaned ?: '250g';
	}

	/**
	 * Normalize grind slug or text to SKU token: beans, filter, espresso.
	 *
	 * @param string $val
	 * @return string
	 */
	public static function normalize_grind_sku( string $val ): string {
		$val = strtolower( trim( $val ) );
		if ( in_array( $val, array( 'zerno', 'beans', 'whole-bean', 'whole_bean', 'зерно' ), true ) ) {
			return 'beans';
		}
		if ( in_array( $val, array( 'filtr', 'pid-filtr', 'filter', 'фільтр' ), true ) ) {
			return 'filter';
		}
		if ( in_array( $val, array( 'espreso', 'espresso', 'еспресо' ), true ) ) {
			return 'espresso';
		}

		$cleaned = (string) preg_replace( '/[^a-z0-9]/', '', $val );
		return $cleaned ?: 'beans';
	}

	/**
	 * Ensure deterministic SKUs for all variations of curated products (menu_order == 0).
	 * Format: <parent_sku>-<weight>-<grind> (e.g. TR-guji-uraga-250g-beans).
	 * Also initializes _trisk_crm_base_regular_price meta on variations.
	 *
	 * @return array{products: int, variations: int, updated: int}
	 */
	public static function ensure_variation_skus(): array {
		$products = get_posts( array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'menu_order'  => 0,
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		) );

		$products_count   = count( $products );
		$variations_count = 0;
		$updated_count    = 0;

		foreach ( $products as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product || ! $product->is_type( 'variable' ) ) {
				continue;
			}

			$parent_sku = (string) $product->get_sku();
			if ( '' === $parent_sku ) {
				$parent_sku = 'TR-' . sanitize_title( $product->get_name() );
			}

			$children = $product->get_children();
			$variations_count += count( $children );

			foreach ( $children as $vid ) {
				$variation = wc_get_product( $vid );
				if ( ! $variation ) {
					continue;
				}

				$attrs = $variation->get_attributes();
				$raw_w = $attrs['pa_vaha'] ?? $attrs['pa_weight'] ?? get_post_meta( $vid, 'attribute_pa_vaha', true ) ?: get_post_meta( $vid, 'attribute_pa_weight', true );
				$raw_g = $attrs['pa_pomel'] ?? $attrs['pa_grind'] ?? get_post_meta( $vid, 'attribute_pa_pomel', true ) ?: get_post_meta( $vid, 'attribute_pa_grind', true );

				$weight_token = self::normalize_weight_sku( (string) $raw_w );
				$grind_token  = self::normalize_grind_sku( (string) $raw_g );

				$expected_sku = "{$parent_sku}-{$weight_token}-{$grind_token}";
				$needs_save   = false;

				if ( $variation->get_sku( 'edit' ) !== $expected_sku ) {
					$variation->set_sku( $expected_sku );
					$needs_save = true;
				}

				$base_price = $variation->get_meta( '_trisk_crm_base_regular_price' );
				if ( '' === $base_price || false === $base_price ) {
					$current_regular = $variation->get_regular_price( 'edit' );
					if ( '' !== $current_regular ) {
						$variation->update_meta_data( '_trisk_crm_base_regular_price', (string) $current_regular );
						$needs_save = true;
					}
				}

				if ( $needs_save ) {
					$variation->save();
					$updated_count++;
				}
			}
		}

		if ( $updated_count > 0 ) {
			if ( function_exists( 'trisk_feed_flush' ) ) {
				trisk_feed_flush();
			}
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache();
			}
		}

		return array(
			'products'   => $products_count,
			'variations' => $variations_count,
			'updated'    => $updated_count,
		);
	}

	/**
	 * Find product or variation IDs by SKU with whitespace normalization and case-insensitivity.
	 *
	 * @param string $raw_sku
	 * @return int[]
	 */
	public static function find_products_by_sku( string $raw_sku ): array {
		global $wpdb;

		$normalized = (string) preg_replace( '/\s+/', '', trim( $raw_sku ) );
		if ( '' === $normalized ) {
			return array();
		}

		$sql = "
			SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON ( p.ID = pm.post_id AND pm.meta_key = '_sku' )
			WHERE p.post_type IN ('product', 'product_variation')
			  AND p.post_status IN ('publish', 'private')
			  AND (
			      LOWER(TRIM(pm.meta_value)) = LOWER(%s)
			      OR LOWER(REPLACE(pm.meta_value, ' ', '')) = LOWER(%s)
			  )
			ORDER BY p.ID ASC
		";

		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $normalized, $normalized ) );
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( count( $ids ) > 1 ) {
			trisk_crm_log_create( array(
				'direction'     => 'in_crm',
				'event'         => 'sku.duplicate',
				'entity_type'   => 'variation',
				'entity_id'     => $normalized,
				'status'        => 'warning',
				'payload'       => array( 'raw_sku' => $raw_sku, 'found_ids' => $ids ),
				'error_message' => sprintf( 'Знайдено %d товарів з однаковим SKU: %s', count( $ids ), $normalized ),
			) );
		}

		return $ids;
	}

	/**
	 * Batch export curated products and their variation offers to KeyCRM.
	 * Sends stocks on the final batch.
	 *
	 * @param int $offset
	 * @param int $batch_size
	 * @return array{total: int, processed: int, next_offset: int, done: bool, stocks_pushed: bool, errors: string[], updated_at: string}
	 */
	public static function export_catalog_batch( int $offset = 0, int $batch_size = 3 ): array {
		// Ensure variation SKUs are deterministic first
		self::ensure_variation_skus();

		$products = get_posts( array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'menu_order'  => 0,
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		) );

		$total = count( $products );
		$batch = array_slice( $products, $offset, $batch_size );

		$processed = 0;
		$errors    = array();
		$settings  = trisk_crm_get_settings();

		foreach ( $batch as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}

			// Збір зображень
			$pictures = array();
			$image_id = $product->get_image_id();
			if ( $image_id ) {
				$img_url = wp_get_attachment_url( $image_id );
				if ( $img_url ) {
					$pictures[] = $img_url;
				}
			}
			foreach ( $product->get_gallery_image_ids() as $gal_id ) {
				$gal_url = wp_get_attachment_url( $gal_id );
				if ( $gal_url && ! in_array( $gal_url, $pictures, true ) ) {
					$pictures[] = $gal_url;
				}
			}

			// Створення/оновлення товару в KeyCRM
			$product_payload = array(
				'name'          => $product->get_name(),
				'sku'           => $product->get_sku(),
				'description'   => $product->get_description() ?: $product->get_short_description(),
				'currency_code' => 'UAH',
			);
			if ( ! empty( $pictures ) ) {
				$product_payload['pictures'] = $pictures;
			}

			$res = trisk_crm_api_request( 'POST', '/products', $product_payload, array(
				'event'       => 'catalog.export_product',
				'entity_type' => 'product',
				'entity_id'   => (string) $product->get_id(),
			) );

			$crm_product_id = null;
			$res_data       = is_array( $res['data'] ?? null ) ? ( $res['data']['data'] ?? $res['data'] ) : array();
			if ( $res['ok'] && ! empty( $res_data['id'] ) ) {
				$crm_product_id = (int) $res_data['id'];
				$product->update_meta_data( '_trisk_keycrm_product_id', $crm_product_id );
				$product->save_meta_data();
			} elseif ( $product->get_meta( '_trisk_keycrm_product_id' ) ) {
				$crm_product_id = (int) $product->get_meta( '_trisk_keycrm_product_id' );
			} else {
				$errors[] = sprintf( 'Не вдалося створити товар #%d: %s', $product->get_id(), $res['error'] );
			}

			// Створення офферів для варіацій
			if ( $product->is_type( 'variable' ) && $crm_product_id ) {
				$offers = array();
				foreach ( $product->get_children() as $vid ) {
					$variation = wc_get_product( $vid );
					if ( ! $variation ) {
						continue;
					}

					$attrs = $variation->get_attributes();
					$raw_w = $attrs['pa_vaha'] ?? $attrs['pa_weight'] ?? '';
					$raw_g = $attrs['pa_pomel'] ?? $attrs['pa_grind'] ?? '';

					$w_token = self::normalize_weight_sku( (string) $raw_w );
					$g_token = self::normalize_grind_sku( (string) $raw_g );

					$w_kg    = 1.0;
					$w_label = '1 кг';
					if ( '250g' === $w_token ) {
						$w_kg    = 0.25;
						$w_label = '250 г';
					} elseif ( '500g' === $w_token ) {
						$w_kg    = 0.5;
						$w_label = '500 г';
					}

					$g_label = 'Еспресо';
					if ( 'beans' === $g_token ) {
						$g_label = 'Зерно';
					} elseif ( 'filter' === $g_token ) {
						$g_label = 'Фільтр';
					}

					$w_term = get_term_by( 'slug', (string) $raw_w, 'pa_vaha' );
					if ( $w_term ) {
						$w_label = $w_term->name;
					}
					$g_term = get_term_by( 'slug', (string) $raw_g, 'pa_pomel' );
					if ( $g_term ) {
						$g_label = $g_term->name;
					}

					$price = (float) ( $variation->get_regular_price( 'edit' ) ?: $variation->get_price( 'edit' ) );

					$offers[] = array(
						'sku'        => $variation->get_sku( 'edit' ),
						'price'      => $price,
						'weight'     => $w_kg,
						'properties' => array(
							array( 'name' => 'Вага', 'value' => $w_label ),
							array( 'name' => 'Помел', 'value' => $g_label ),
						),
					);
				}

				if ( ! empty( $offers ) ) {
					$res_offers = trisk_crm_api_request( 'POST', "/products/{$crm_product_id}/offers", array( 'offers' => $offers ), array(
						'event'       => 'catalog.export_offers',
						'entity_type' => 'product',
						'entity_id'   => (string) $product->get_id(),
					) );

					if ( ! $res_offers['ok'] ) {
						$errors[] = sprintf( 'Не вдалося створити оффери для товару #%d: %s', $product->get_id(), $res_offers['error'] );
					}
				}
			}

			$processed++;
		}

		$next_offset   = $offset + count( $batch );
		$is_done       = ( $next_offset >= $total );
		$stocks_pushed = false;

		// Фінальний крок — відправка залишків для всіх 162 варіацій/товарів
		if ( $is_done ) {
			$warehouse_id = ! empty( $settings['warehouse_id'] ) ? (int) $settings['warehouse_id'] : 1;
			$all_stocks   = array();

			foreach ( $products as $p_post ) {
				$p = wc_get_product( $p_post->ID );
				if ( ! $p ) {
					continue;
				}

				if ( $p->is_type( 'variable' ) ) {
					foreach ( $p->get_children() as $vid ) {
						$v = wc_get_product( $vid );
						if ( ! $v ) {
							continue;
						}
						$sku = $v->get_sku( 'edit' );
						if ( '' !== $sku ) {
							$qty = $v->get_stock_quantity();
							$all_stocks[] = array(
								'sku'      => $sku,
								'quantity' => ( null !== $qty ) ? (int) $qty : ( $v->is_in_stock() ? 10 : 0 ),
							);
						}
					}
				} else {
					$sku = $p->get_sku( 'edit' );
					if ( '' !== $sku ) {
						$qty = $p->get_stock_quantity();
						$all_stocks[] = array(
							'sku'      => $sku,
							'quantity' => ( null !== $qty ) ? (int) $qty : ( $p->is_in_stock() ? 10 : 0 ),
						);
					}
				}
			}

			$chunks = array_chunk( $all_stocks, 100 );
			foreach ( $chunks as $chunk ) {
				$res_stocks = trisk_crm_api_request( 'PUT', '/offers/stocks', array(
					'warehouse_id' => $warehouse_id,
					'stocks'       => $chunk,
				), array(
					'event'       => 'catalog.export_stocks',
					'entity_type' => 'catalog',
					'entity_id'   => 'curated_stocks',
				) );

				if ( ! $res_stocks['ok'] ) {
					$errors[] = 'Не вдалося відправити залишки: ' . $res_stocks['error'];
				}
			}
			$stocks_pushed = true;
		}

		$prev_state      = get_option( self::OPTION_EXPORT_STATE, array() );
		$total_processed = ( $prev_state['processed'] ?? 0 ) + $processed;
		if ( 0 === $offset ) {
			$total_processed = $processed;
		}

		$state = array(
			'total'         => $total,
			'processed'     => $total_processed,
			'next_offset'   => $next_offset,
			'done'          => $is_done,
			'stocks_pushed' => $stocks_pushed,
			'errors'        => $errors,
			'updated_at'    => current_time( 'mysql' ),
		);

		update_option( self::OPTION_EXPORT_STATE, $state, false );

		return $state;
	}

	/**
	 * Sync regular & sale prices from an array of offers [{sku, price}].
	 * Follows Smart Sale Price Rule (Decision #2):
	 * - if price < base_regular: regular_price = base_regular, sale_price = price
	 * - if price >= base_regular: regular_price = price, sale_price = '', base_regular = price
	 *
	 * @param array<array{sku: string, price: float|int}> $offers
	 * @return array{updated: int, skipped: int, duplicates: int}
	 */
	public static function sync_prices_from_offers( array $offers ): array {
		$updated    = 0;
		$skipped    = 0;
		$duplicates = 0;
		$parent_ids = array();

		foreach ( $offers as $offer ) {
			$raw_sku   = (string) ( $offer['sku'] ?? '' );
			$new_price = isset( $offer['price'] ) ? (float) $offer['price'] : 0.0;

			if ( '' === trim( $raw_sku ) || $new_price <= 0 ) {
				$skipped++;
				continue;
			}

			$product_ids = self::find_products_by_sku( $raw_sku );
			if ( empty( $product_ids ) ) {
				$skipped++;
				continue;
			}

			if ( count( $product_ids ) > 1 ) {
				$duplicates++;
			}

			foreach ( $product_ids as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}

				$meta_changed = false;
				$base_meta    = $product->get_meta( '_trisk_crm_base_regular_price' );
				if ( '' === $base_meta || false === $base_meta ) {
					$current_regular = $product->get_regular_price( 'edit' );
					$base_regular    = (float) ( $current_regular ?: $new_price );
					$product->update_meta_data( '_trisk_crm_base_regular_price', (string) $base_regular );
					$meta_changed = true;
				} else {
					$base_regular = (float) $base_meta;
				}

				if ( $base_regular <= 0 ) {
					$base_regular = $new_price;
					$product->update_meta_data( '_trisk_crm_base_regular_price', (string) $base_regular );
					$meta_changed = true;
				}

				if ( $new_price < $base_regular ) {
					$target_regular = (string) $base_regular;
					$target_sale    = (string) $new_price;
				} else {
					$target_regular = (string) $new_price;
					$target_sale    = '';
					if ( (float) $base_meta !== $new_price ) {
						$product->update_meta_data( '_trisk_crm_base_regular_price', (string) $new_price );
						$meta_changed = true;
					}
				}

				// Зберігаємо лише якщо ціна справді змінилась (інакше щогодини перезберігався б весь каталог)
				$current_regular = (string) $product->get_regular_price( 'edit' );
				$current_sale    = (string) $product->get_sale_price( 'edit' );
				$current_price   = (string) $product->get_price( 'edit' );
				$target_price    = '' !== $target_sale ? $target_sale : $target_regular;

				$price_changed = (float) $current_regular !== (float) $target_regular
					|| ( '' === $current_sale ) !== ( '' === $target_sale )
					|| ( '' !== $target_sale && (float) $current_sale !== (float) $target_sale )
					|| (float) $current_price !== (float) $target_price;

				if ( ! $price_changed && ! $meta_changed ) {
					continue;
				}

				if ( $price_changed ) {
					$product->set_regular_price( $target_regular );
					$product->set_sale_price( $target_sale );
					$product->set_price( $target_price );
				}

				$product->save();

				if ( ! $price_changed ) {
					// змінилась лише службова базова ціна — на сайті нічого оновлювати не треба
					continue;
				}

				$updated++;

				$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
				if ( $parent_id ) {
					$parent_ids[$parent_id] = true;
				}
			}
		}

		foreach ( array_keys( $parent_ids ) as $parent_id ) {
			if ( class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $parent_id );
			}
			wc_delete_product_transients( $parent_id );
		}

		if ( $updated > 0 ) {
			if ( function_exists( 'trisk_feed_flush' ) ) {
				trisk_feed_flush();
			}
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache();
			}
		}

		trisk_crm_log_create( array(
			'direction'   => 'in_crm',
			'event'       => 'prices.sync',
			'entity_type' => 'catalog',
			'entity_id'   => 'offers',
			'status'      => 'success',
			'payload'     => array( 'offers_count' => count( $offers ) ),
			'response'    => array( 'updated' => $updated, 'skipped' => $skipped, 'duplicates' => $duplicates ),
		) );

		return array(
			'updated'    => $updated,
			'skipped'    => $skipped,
			'duplicates' => $duplicates,
		);
	}

	/**
	 * Pull offers with prices from KeyCRM API and synchronize regular & sale prices.
	 *
	 * @return array{updated: int, skipped: int, duplicates: int}
	 */
	public static function pull_prices(): array {
		$page       = 1;
		$all_offers = array();
		$max_pages  = 20;

		do {
			$res = trisk_crm_api_request( 'GET', "/offers?limit=50&page={$page}&include=product", null, array(
				'event'       => 'prices.pull',
				'entity_type' => 'catalog',
				'entity_id'   => "page_{$page}",
			) );

			if ( ! $res['ok'] || empty( $res['data'] ) ) {
				break;
			}

			$data  = $res['data'];
			$items = array();
			if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
				$items = $data['data'];
			} elseif ( is_array( $data ) && isset( $data[0] ) ) {
				$items = $data;
			}

			if ( empty( $items ) ) {
				break;
			}

			foreach ( $items as $it ) {
				if ( ! empty( $it['sku'] ) && isset( $it['price'] ) ) {
					$all_offers[] = array(
						'sku'   => (string) $it['sku'],
						'price' => (float) $it['price'],
					);
				}
			}

			$page++;
		} while ( $page <= $max_pages );

		if ( empty( $all_offers ) ) {
			return array( 'updated' => 0, 'skipped' => 0, 'duplicates' => 0 );
		}

		return self::sync_prices_from_offers( $all_offers );
	}
}
