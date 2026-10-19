import json


def test_crm_plugin_active_and_table_exists(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        global $wpdb;
        $table = $wpdb->prefix . 'trisk_crm_logs';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        echo wp_json_encode( array(
            'active' => is_plugin_active( 'trisk-crm-sync/trisk-crm-sync.php' ),
            'table'  => $exists,
        ) );
    """))
    assert out == {"active": True, "table": True}


def test_crm_logger_crud_and_settings(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        $log_id = trisk_crm_log_create( array(
            'direction'   => 'out_crm',
            'event'       => 'order.push',
            'entity_type' => 'order',
            'entity_id'   => '99999',
            'status'      => 'pending',
            'payload'     => array( 'source_uuid' => '99999' ),
        ) );
        trisk_crm_log_update( $log_id, array(
            'status'    => 'success',
            'http_code' => 201,
            'response'  => array( 'id' => 777 ),
        ) );
        $row = trisk_crm_log_get( $log_id );
        $settings = trisk_crm_get_settings();

        // Перевіряємо, що оновлення payload в null не перетворює його на "null"
        trisk_crm_log_update( $log_id, array( 'payload' => null ) );
        $row_null_payload = trisk_crm_log_get( $log_id );

        // Перевіряємо, що секрет з ENV застосовується динамічно, коли в опції порожньо, і не персиститься в БД
        $backup_opt = get_option( 'trisk_crm_sync_settings', array() );
        $temp_opt = $backup_opt;
        unset( $temp_opt['webhook_secret'] );
        update_option( 'trisk_crm_sync_settings', $temp_opt );

        putenv( 'TRISK_KEYCRM_WEBHOOK_SECRET=env_secret_123' );
        $settings_with_env = trisk_crm_get_settings();
        $raw_option_after = get_option( 'trisk_crm_sync_settings', array() );
        putenv( 'TRISK_KEYCRM_WEBHOOK_SECRET' );
        update_option( 'trisk_crm_sync_settings', $backup_opt );

        echo wp_json_encode( array(
            'status'          => $row['status'] ?? null,
            'http_code'       => (int) ( $row['http_code'] ?? 0 ),
            'has_secret'      => ! empty( $settings['webhook_secret'] ) && ! empty( $settings['tg_webhook_secret'] ),
            'payload_is_null' => is_null( $row_null_payload['payload'] ),
            'env_in_settings' => ( $settings_with_env['webhook_secret'] === 'env_secret_123' ),
            'env_in_option'   => isset( $raw_option_after['webhook_secret'] ),
        ) );
    """))
    assert out["status"] == "success"
    assert out["http_code"] == 201
    assert out["has_secret"] is True
    assert out["payload_is_null"] is True
    assert out["env_in_settings"] is True
    assert out["env_in_option"] is False


def test_crm_log_ring_buffer(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        global $wpdb;
        $table = $wpdb->prefix . 'trisk_crm_logs';
        $wpdb->query( "DELETE FROM {$table} WHERE event = 'test.ring_buffer'" );
        for ( $i = 1; $i <= 10; $i++ ) {
            trisk_crm_log_create( array(
                'direction'   => 'out_crm',
                'event'       => 'test.ring_buffer',
                'entity_type' => 'system',
                'entity_id'   => (string) $i,
                'status'      => 'success',
            ) );
        }
        $zero_pruned = trisk_crm_log_prune( 0 );
        $deleted = trisk_crm_log_prune( 5 );
        $remaining = trisk_crm_log_query( array( 'event' => 'test.ring_buffer', 'limit' => 20 ) );
        echo wp_json_encode( array(
            'zero_pruned'=> $zero_pruned,
            'pruned'     => $deleted >= 5,
            'count'      => count( $remaining ),
            'newest_id'  => $remaining[0]['entity_id'] ?? null,
        ) );
    """))
    assert out["zero_pruned"] == 0
    assert out["pruned"] is True
    assert out["count"] == 5
    assert out["newest_id"] == "10"


def test_keycrm_client_rate_limit_and_retry_scheduling(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        // Налаштовуємо тестовий API-ключ
        $orig_settings = trisk_crm_get_settings();
        $test_settings = $orig_settings;
        $test_settings['api_key'] = 'test_keycrm_token_xyz';
        trisk_crm_update_settings( $test_settings );

        $captured_headers = null;
        $mock_mode = '429';

        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$captured_headers, &$mock_mode ) {
            if ( strpos( $url, 'openapi.keycrm.app' ) !== false ) {
                $captured_headers = $args['headers'] ?? array();
                if ( '429' === $mock_mode ) {
                    return array(
                        'response' => array( 'code' => 429, 'message' => 'Too Many Requests' ),
                        'body'     => json_encode( array( 'message' => 'Too Many Requests' ) ),
                    );
                }
                if ( '422' === $mock_mode ) {
                    return array(
                        'response' => array( 'code' => 422, 'message' => 'Unprocessable Entity' ),
                        'body'     => json_encode( array( 'message' => 'The source uuid has already been taken' ) ),
                    );
                }
                if ( 'wp_error' === $mock_mode ) {
                    return new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' );
                }
                if ( '200' === $mock_mode ) {
                    return array(
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                        'body'     => json_encode( array( 'data' => array( 'id' => 987 ) ) ),
                    );
                }
            }
            return $preempt;
        }, 10, 3 );

        // 1. Запит з помилкою 429
        $res_429 = trisk_crm_api_request( 'GET', '/order', null, array( 'skip_rate_limit' => true ) );
        $log_id_429 = $res_429['log_id'] ?? 0;
        $log_429 = trisk_crm_log_get( $log_id_429 );

        // 2. Планування повтору через Action Scheduler (attempt 1 -> 30s)
        $scheduled_1 = trisk_crm_schedule_retry( 'trisk_crm_test_retry_hook', array( 'order_id' => 555 ), 1, $log_id_429 );
        $next_ts_1 = as_next_scheduled_action( 'trisk_crm_test_retry_hook', array( 'order_id' => 555 ), 'trisk-crm-sync' );
        $expected_delay_1 = ( $next_ts_1 && $next_ts_1 >= time() + 25 && $next_ts_1 <= time() + 35 );
        $log_retry_1 = trisk_crm_log_get( $log_id_429 );

        // Attempt 2 -> 120s
        $scheduled_2 = trisk_crm_schedule_retry( 'trisk_crm_test_retry_hook_2', array( 'order_id' => 555 ), 2, $log_id_429 );
        $next_ts_2 = as_next_scheduled_action( 'trisk_crm_test_retry_hook_2', array( 'order_id' => 555 ), 'trisk-crm-sync' );
        $expected_delay_2 = ( $next_ts_2 && $next_ts_2 >= time() + 115 && $next_ts_2 <= time() + 125 );

        // Attempt 5 -> перевищення ліміту (макс. 4) -> повертає false, статус error
        $scheduled_5 = trisk_crm_schedule_retry( 'trisk_crm_test_retry_hook_5', array( 'order_id' => 555 ), 5, $log_id_429 );
        $log_after_5 = trisk_crm_log_get( $log_id_429 );

        // 3. Неповторюваний 422
        $mock_mode = '422';
        $res_422 = trisk_crm_api_request( 'POST', '/order', array( 'source_uuid' => '555' ), array( 'skip_rate_limit' => true ) );
        $log_422 = trisk_crm_log_get( $res_422['log_id'] ?? 0 );

        // 4. Помилка мережі (WP_Error timeout) -> retryable
        $mock_mode = 'wp_error';
        $res_timeout = trisk_crm_api_request( 'GET', '/offers', null, array( 'skip_rate_limit' => true ) );
        $log_timeout = trisk_crm_log_get( $res_timeout['log_id'] ?? 0 );

        // 5. Ручний повтор через trisk_crm_retry_log_entry для log_id_429
        $mock_mode = '200';
        $retry_success = trisk_crm_retry_log_entry( $log_id_429 );
        $log_after_retry = trisk_crm_log_get( $log_id_429 );

        // 6. Перевірка пейсингу Rate Limiter: ставимо last_ts на 3.0с назад, перевіряємо паузу ~0.2с
        update_option( 'trisk_crm_last_request_ts', microtime( true ) - 3.0, false );
        $t_start = microtime( true );
        trisk_crm_api_request( 'GET', '/order', null );
        $elapsed = microtime( true ) - $t_start;
        $last_ts = (float) get_option( 'trisk_crm_last_request_ts', 0 );

        // Прибирання дій
        as_unschedule_action( 'trisk_crm_test_retry_hook', array( 'order_id' => 555 ), 'trisk-crm-sync' );
        as_unschedule_action( 'trisk_crm_test_retry_hook_2', array( 'order_id' => 555 ), 'trisk-crm-sync' );
        trisk_crm_update_settings( $orig_settings );

        echo wp_json_encode( array(
            'auth_header'            => $captured_headers['Authorization'] ?? '',
            'res_429_ok'             => $res_429['ok'],
            'res_429_code'           => $res_429['code'],
            'res_429_retryable'      => $res_429['retryable'],
            'log_429_status'         => $log_429['status'] ?? '',
            'log_429_code'           => (int) ( $log_429['http_code'] ?? 0 ),
            'scheduled_1'            => $scheduled_1,
            'expected_delay_1'       => $expected_delay_1,
            'log_retry_status'       => $log_retry_1['status'] ?? '',
            'log_retry_attempts'     => (int) ( $log_retry_1['attempts'] ?? 0 ),
            'scheduled_2'            => $scheduled_2,
            'expected_delay_2'       => $expected_delay_2,
            'scheduled_5'            => $scheduled_5,
            'log_after_5_status'     => $log_after_5['status'] ?? '',
            'res_422_retryable'      => $res_422['retryable'],
            'log_422_status'         => $log_422['status'] ?? '',
            'res_timeout_retryable'  => $res_timeout['retryable'],
            'log_timeout_status'     => $log_timeout['status'] ?? '',
            'retry_success'          => $retry_success,
            'log_after_retry_status' => $log_after_retry['status'] ?? '',
            'log_after_retry_code'   => (int) ( $log_after_retry['http_code'] ?? 0 ),
            'pacing_waited'          => ( $elapsed >= 0.15 && $elapsed <= 1.5 ),
            'has_last_ts'            => $last_ts > 0,
        ) );
    """))

    assert out["auth_header"] == "Bearer test_keycrm_token_xyz"
    assert out["res_429_ok"] is False
    assert out["res_429_code"] == 429
    assert out["res_429_retryable"] is True
    assert out["log_429_status"] == "retrying"
    assert out["log_429_code"] == 429
    assert out["scheduled_1"] is True
    assert out["expected_delay_1"] is True
    assert out["log_retry_status"] == "retrying"
    assert out["log_retry_attempts"] == 1
    assert out["scheduled_2"] is True
    assert out["expected_delay_2"] is True
    assert out["scheduled_5"] is False
    assert out["log_after_5_status"] == "error"
    assert out["res_422_retryable"] is False
    assert out["log_422_status"] == "error"
    assert out["res_timeout_retryable"] is True
    assert out["log_timeout_status"] == "retrying"
    assert out["retry_success"] is True
    assert out["log_after_retry_status"] == "success"
    assert out["log_after_retry_code"] == 200
    assert out["pacing_waited"] is True
    assert out["has_last_ts"] is True


def test_variation_skus_generated_for_curated_coffees(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        $res = trisk_crm_ensure_variation_skus();

        $parent = wc_get_product( wc_get_product_id_by_sku( 'TR-guji-uraga' ) );
        $parent_sku = $parent ? $parent->get_sku() : '';

        $child_skus = array();
        $base_prices = array();
        foreach ( $parent ? $parent->get_children() : array() as $vid ) {
            $v = wc_get_product( $vid );
            $attrs = $v->get_attributes();
            $key = ( $attrs['pa_vaha'] ?? '' ) . '__' . ( $attrs['pa_pomel'] ?? '' );
            $child_skus[$key] = $v->get_sku( 'edit' );
            $base_prices[$key] = $v->get_meta( '_trisk_crm_base_regular_price' );
        }

        echo wp_json_encode( array(
            'res'                 => $res,
            'parent_sku'          => $parent_sku,
            'guji_250_beans_sku'  => $child_skus['250-g__zerno'] ?? '',
            'guji_500_filter_sku' => $child_skus['500-g__filtr'] ?? '',
            'guji_1kg_espreso_sku'=> $child_skus['1-kg__espreso'] ?? '',
            'guji_250_beans_base' => $base_prices['250-g__zerno'] ?? '',
        ) );
    """))

    assert out["parent_sku"] == "TR-guji-uraga"
    assert out["guji_250_beans_sku"] == "TR-guji-uraga-250g-beans"
    assert out["guji_500_filter_sku"] == "TR-guji-uraga-500g-filter"
    assert out["guji_1kg_espreso_sku"] == "TR-guji-uraga-1kg-espresso"
    assert out["guji_250_beans_base"] == "465"
    assert out["res"]["products"] >= 12
    assert out["res"]["variations"] >= 108


def test_price_sync_sets_and_clears_sale_price(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        trisk_crm_ensure_variation_skus();

        $sku_with_spaces = "   TR-guji-uraga-250g-beans   ";
        $found_ids = trisk_crm_find_products_by_sku( $sku_with_spaces );
        $var_id = ! empty( $found_ids ) ? $found_ids[0] : 0;
        $var = wc_get_product( $var_id );
        $orig_sale = $var->get_sale_price( 'edit' );
        $orig_price = $var->get_price( 'edit' );

        // Починаємо з чистого стану без акції (на живому сайті там може бути акція з реального KeyCRM)
        $var->set_regular_price( '465' );
        $var->set_sale_price( '' );
        $var->set_price( '465' );
        $var->update_meta_data( '_trisk_crm_base_regular_price', '465' );
        $var->save();

        // 1. Зниження ціни до 395 при базі 465 -> встановлює sale_price = 395
        $sync_res_1 = trisk_crm_sync_prices_from_offers( array(
            array( 'sku' => $sku_with_spaces, 'price' => 395 ),
        ) );

        $var_discounted = wc_get_product( $var_id );
        $parent_discounted = wc_get_product( $var_discounted->get_parent_id() );

        $state_1 = array(
            'regular_price' => $var_discounted->get_regular_price( 'edit' ),
            'sale_price'    => $var_discounted->get_sale_price( 'edit' ),
            'price'         => $var_discounted->get_price( 'edit' ),
            'is_on_sale'    => $var_discounted->is_on_sale(),
            'parent_on_sale'=> $parent_discounted->is_on_sale(),
        );

        // 1б. Та сама ціна вдруге -> нічого не змінилось, товар не зберігається й не лічиться
        $sync_res_1b = trisk_crm_sync_prices_from_offers( array(
            array( 'sku' => 'TR-guji-uraga-250g-beans', 'price' => 395 ),
        ) );

        // 2. Повернення ціни до 465 (>= base_regular) -> акція знімається
        $sync_res_2 = trisk_crm_sync_prices_from_offers( array(
            array( 'sku' => 'TR-guji-uraga-250g-beans', 'price' => 465 ),
        ) );

        $var_restored = wc_get_product( $var_id );
        $parent_restored = wc_get_product( $var_restored->get_parent_id() );

        $state_2 = array(
            'regular_price' => $var_restored->get_regular_price( 'edit' ),
            'sale_price'    => $var_restored->get_sale_price( 'edit' ),
            'price'         => $var_restored->get_price( 'edit' ),
            'is_on_sale'    => $var_restored->is_on_sale(),
            'parent_on_sale'=> $parent_restored->is_on_sale(),
        );

        // Відновлюємо вихідний стан варіації
        $var_restored->set_regular_price( '465' );
        $var_restored->set_sale_price( $orig_sale );
        $var_restored->set_price( $orig_price ?: '465' );
        $var_restored->update_meta_data( '_trisk_crm_base_regular_price', '465' );
        $var_restored->save();
        WC_Product_Variable::sync( $var_restored->get_parent_id() );
        wc_delete_product_transients( $var_restored->get_parent_id() );

        echo wp_json_encode( array(
            'found_count' => count( $found_ids ),
            'sync_1'      => $sync_res_1,
            'sync_1b'     => $sync_res_1b,
            'state_1'     => $state_1,
            'sync_2'      => $sync_res_2,
            'state_2'     => $state_2,
        ) );
    """))

    assert out["found_count"] >= 1
    assert out["sync_1"]["updated"] >= 1
    assert out["state_1"]["regular_price"] == "465"
    assert out["state_1"]["sale_price"] == "395"
    assert out["state_1"]["price"] == "395"
    assert out["state_1"]["is_on_sale"] is True
    assert out["state_1"]["parent_on_sale"] is True

    assert out["sync_1b"]["updated"] == 0

    assert out["sync_2"]["updated"] >= 1
    assert out["state_2"]["regular_price"] == "465"
    assert out["state_2"]["sale_price"] == ""
    assert out["state_2"]["price"] == "465"
    assert out["state_2"]["is_on_sale"] is False


def test_catalog_export_batch_sends_products_offers_and_stocks(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        trisk_crm_ensure_variation_skus();
        $orig_export_state = get_option( 'trisk_crm_catalog_export_state', null );

        $calls = array();
        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$calls ) {
            if ( strpos( $url, 'openapi.keycrm.app' ) !== false ) {
                $path = parse_url( $url, PHP_URL_PATH );
                $method = $args['method'] ?? 'GET';
                $body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
                $calls[] = array(
                    'method' => $method,
                    'path'   => $path,
                    'body'   => $body,
                );

                if ( 'POST' === $method && preg_match( '#/v1/products$#', $path ) ) {
                    return array(
                        'response' => array( 'code' => 201, 'message' => 'Created' ),
                        'body'     => json_encode( array( 'data' => array( 'id' => 8881 ) ) ),
                    );
                }
                if ( 'POST' === $method && preg_match( '#/v1/products/\d+/offers$#', $path ) ) {
                    return array(
                        'response' => array( 'code' => 201, 'message' => 'Created' ),
                        'body'     => json_encode( array( 'data' => array( array( 'id' => 9991 ) ) ) ),
                    );
                }
                if ( 'PUT' === $method && preg_match( '#/v1/offers/stocks$#', $path ) ) {
                    return array(
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                        'body'     => json_encode( array( 'data' => array( 'updated' => true ) ) ),
                    );
                }
                return array(
                    'response' => array( 'code' => 200, 'message' => 'OK' ),
                    'body'     => json_encode( array( 'data' => array() ) ),
                );
            }
            return $preempt;
        }, 10, 3 );

        // 1. Перший батч (offset 0, batch_size 2)
        $batch_1 = trisk_crm_export_catalog_batch( 0, 2 );

        // 2. Фінальний крок: запускаємо батч для останнього товару, щоб викликати відправку залишків
        $total = $batch_1['total'];
        $final_batch = trisk_crm_export_catalog_batch( $total - 1, 2 );

        $state = get_option( 'trisk_crm_catalog_export_state', array() );
        if ( null !== $orig_export_state ) {
            update_option( 'trisk_crm_catalog_export_state', $orig_export_state, false );
        }

        $has_post_product = false;
        $has_post_offers = false;
        $has_put_stocks = false;
        $offers_payload = null;
        $stocks_chunks = 0;
        $total_stocks_count = 0;

        foreach ( $calls as $c ) {
            if ( $c['method'] === 'POST' && preg_match( '#/v1/products$#', $c['path'] ) ) {
                $has_post_product = true;
            }
            if ( $c['method'] === 'POST' && preg_match( '#/v1/products/\d+/offers$#', $c['path'] ) ) {
                $has_post_offers = true;
                $offers_payload = $c['body'];
            }
            if ( $c['method'] === 'PUT' && preg_match( '#/v1/offers/stocks$#', $c['path'] ) ) {
                $has_put_stocks = true;
                $stocks_chunks++;
                $total_stocks_count += count( $c['body']['stocks'] ?? array() );
            }
        }

        echo wp_json_encode( array(
            'batch_1'            => $batch_1,
            'final_batch'        => $final_batch,
            'has_post_product'   => $has_post_product,
            'has_post_offers'    => $has_post_offers,
            'has_put_stocks'     => $has_put_stocks,
            'offer_sample'       => $offers_payload['offers'][0] ?? null,
            'stocks_chunks'      => $stocks_chunks,
            'total_stocks_count' => $total_stocks_count,
            'saved_state_done'   => ! empty( $state['done'] ),
        ) );
    """))

    assert out["has_post_product"] is True
    assert out["has_post_offers"] is True
    assert out["has_put_stocks"] is True
    assert out["final_batch"]["done"] is True
    assert out["final_batch"]["stocks_pushed"] is True
    assert out["saved_state_done"] is True
    assert out["stocks_chunks"] >= 2
    assert out["total_stocks_count"] >= 100
    assert "sku" in out["offer_sample"]
    assert "price" in out["offer_sample"]
    assert "weight" in out["offer_sample"]
    assert "properties" in out["offer_sample"]


def test_order_push_to_keycrm_payload_and_hpos_meta(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        trisk_crm_ensure_variation_skus();

        $orig_settings = trisk_crm_get_settings();
        $test_settings = $orig_settings;
        $test_settings['enabled'] = true;
        $test_settings['api_key'] = 'test_order_push_key';
        $test_settings['source_id'] = 1;
        $test_settings['status_map'] = Trisk_CRM_Settings::get_defaults()['status_map'];
        trisk_crm_update_settings( $test_settings );

        // 1. Створюємо тестове замовлення
        $order = wc_create_order();
        $order->set_billing_first_name( 'Тарас' );
        $order->set_billing_last_name( 'Шевченко' );
        $order->set_billing_email( 'taras@example.com' );
        $order->set_billing_phone( '+380501234567' );
        $order->set_customer_note( 'Залишити біля дверей' );
        $order->set_payment_method( 'morkva-liqpay' );
        $order->set_payment_method_title( 'Оплата карткою онлайн (LiqPay)' );

        // Мета Нової Пошти
        $order->update_meta_data( '_trisk_np_city', 'Львів' );
        $order->update_meta_data( '_trisk_np_branch', '№12, вул. Городоцька, 48' );

        // Додаємо варіацію
        $var_ids = trisk_crm_find_products_by_sku( 'TR-guji-uraga-250g-beans' );
        $var = wc_get_product( $var_ids[0] );
        $expected_unit_price = (float) $var->get_price();
        $order->add_product( $var, 2 );

        // Доставка
        $item_shipping = new WC_Order_Item_Shipping();
        $item_shipping->set_method_title( 'Нова Пошта, відділення' );
        $item_shipping->set_total( 80 );
        $order->add_item( $item_shipping );

        $order->set_status( 'processing' );
        $order->calculate_totals();
        $order->save();
        $order_id = $order->get_id();

        // 2. Мокаємо HTTP-запити до KeyCRM
        $captured_requests = array();
        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$captured_requests ) {
            if ( strpos( $url, 'openapi.keycrm.app' ) !== false ) {
                $path = parse_url( $url, PHP_URL_PATH );
                $method = $args['method'] ?? 'GET';
                $body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
                $captured_requests[] = array(
                    'method' => $method,
                    'path'   => $path,
                    'body'   => $body,
                );
                if ( 'POST' === $method && preg_match( '#/v1/order$#', $path ) ) {
                    return array(
                        'response' => array( 'code' => 201, 'message' => 'Created' ),
                        'body'     => json_encode( array( 'data' => array( 'id' => 77701 ) ) ),
                    );
                }
                if ( 'PUT' === $method && preg_match( '#/v1/order/\d+$#', $path ) ) {
                    return array(
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                        'body'     => json_encode( array( 'data' => array( 'id' => 77701 ) ) ),
                    );
                }
            }
            return $preempt;
        }, 10, 3 );

        // 3. Відправляємо замовлення
        $push_res = trisk_crm_push_order_now( $order_id );

        $order_after_push = wc_get_order( $order_id );
        $crm_order_id_meta = $order_after_push->get_meta( '_trisk_keycrm_order_id' );
        $synced_at_meta = $order_after_push->get_meta( '_trisk_keycrm_synced_at' );

        // 4. Оновлюємо статус і відправляємо знову -> має піти PUT
        $order_after_push->set_status( 'completed' );
        $order_after_push->save();
        $put_res = trisk_crm_push_order_now( $order_id );

        // Прибирання замовлення та налаштувань
        $order_after_push->delete( true );
        trisk_crm_update_settings( $orig_settings );

        echo wp_json_encode( array(
            'push_res'            => $push_res,
            'put_res'             => $put_res,
            'crm_order_id_meta'   => $crm_order_id_meta,
            'has_synced_at'       => ! empty( $synced_at_meta ),
            'requests_count'      => count( $captured_requests ),
            'post_request'        => $captured_requests[0] ?? null,
            'put_request'         => $captured_requests[1] ?? null,
            'expected_unit_price' => $expected_unit_price,
        ) );
    """))

    assert out["push_res"]["ok"] is True
    assert out["push_res"]["crm_order_id"] == 77701
    assert str(out["crm_order_id_meta"]) == "77701"
    assert out["has_synced_at"] is True

    # Перевірка тіла POST /order
    post_req = out["post_request"]
    assert post_req["method"] == "POST"
    assert post_req["path"].endswith("/order")
    payload = post_req["body"]
    assert payload["source_id"] == 1
    assert str(payload["source_uuid"]) != ""
    assert payload["buyer"]["full_name"] == "Тарас Шевченко"
    assert payload["buyer"]["email"] == "taras@example.com"
    assert payload["buyer"]["phone"] == "+380501234567"
    assert payload["buyer_comment"] == "Залишити біля дверей"
    assert payload["shipping"]["shipping_service"] == "Нова Пошта, відділення"
    assert payload["shipping"]["shipping_address_city"] == "Львів"
    assert payload["shipping"]["shipping_receive_point"] == "№12, вул. Городоцька, 48"
    assert payload["shipping"]["shipping_address_country"] == "UA"
    assert len(payload["products"]) == 1
    assert payload["products"][0]["sku"] == "TR-guji-uraga-250g-beans"
    assert payload["products"][0]["quantity"] == 2
    assert float(payload["products"][0]["price"]) == float(out["expected_unit_price"])
    assert len(payload["payments"]) == 1
    assert payload["payments"][0]["status"] == "paid"
    assert payload["payments"][0]["amount"] > 0

    # Перевірка тіла PUT /order/77701
    put_req = out["put_request"]
    assert put_req["method"] == "PUT"
    assert "77701" in put_req["path"]
    assert put_req["body"]["status_id"] == 4
    assert out["put_res"]["ok"] is True


def test_order_push_handles_duplicate_source_uuid_422(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        trisk_crm_ensure_variation_skus();

        $orig_settings = trisk_crm_get_settings();
        $test_settings = $orig_settings;
        $test_settings['enabled'] = true;
        $test_settings['api_key'] = 'test_dup_key';
        trisk_crm_update_settings( $test_settings );

        $order = wc_create_order();
        $order->set_billing_first_name( 'Леся' );
        $order->set_billing_last_name( 'Українка' );
        $order->set_status( 'processing' );
        $order->save();
        $order_id = $order->get_id();

        $captured_paths = array();
        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$captured_paths ) {
            if ( strpos( $url, 'openapi.keycrm.app' ) !== false ) {
                $path = parse_url( $url, PHP_URL_PATH );
                $query = parse_url( $url, PHP_URL_QUERY );
                $method = $args['method'] ?? 'GET';
                $captured_paths[] = $method . ' ' . $path . ( $query ? '?' . $query : '' );

                if ( 'POST' === $method && preg_match( '#/v1/order$#', $path ) ) {
                    return array(
                        'response' => array( 'code' => 422, 'message' => 'Unprocessable Entity' ),
                        'body'     => json_encode( array( 'message' => 'The source uuid has already been taken' ) ),
                    );
                }
                if ( 'GET' === $method && preg_match( '#/v1/order#', $path ) ) {
                    return array(
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                        'body'     => json_encode( array( 'data' => array( array( 'id' => 88802, 'source_uuid' => (string) parse_url( $url, PHP_URL_QUERY ) ) ) ) ),
                    );
                }
            }
            return $preempt;
        }, 10, 3 );

        $res = trisk_crm_push_order_now( $order_id );
        $order_reloaded = wc_get_order( $order_id );
        $saved_crm_id = $order_reloaded->get_meta( '_trisk_keycrm_order_id' );

        $logs = trisk_crm_log_query( array( 'entity_type' => 'order', 'entity_id' => (string) $order_id, 'limit' => 1 ) );
        $log_status = $logs[0]['status'] ?? '';

        $order_reloaded->delete( true );
        trisk_crm_update_settings( $orig_settings );

        echo wp_json_encode( array(
            'res'            => $res,
            'saved_crm_id'   => $saved_crm_id,
            'log_status'     => $log_status,
            'captured_paths' => $captured_paths,
        ) );
    """))

    assert out["res"]["ok"] is True
    assert out["res"]["crm_order_id"] == 88802
    assert str(out["saved_crm_id"]) == "88802"
    assert out["log_status"] == "success"
    assert any("GET /v1/order" in p for p in out["captured_paths"])


def test_keycrm_webhook_updates_order_status(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        $orig_settings = trisk_crm_get_settings();
        $token = $orig_settings['webhook_secret'];

        // Таблиця відповідності задана явно: ID статусів у KeyCRM свої для кожного акаунта
        $test_settings = $orig_settings;
        $test_settings['status_map'] = Trisk_CRM_Settings::get_defaults()['status_map'];
        trisk_crm_update_settings( $test_settings );

        // 1. Створюємо замовлення
        $order = wc_create_order();
        $order->set_status( 'on-hold' );
        $order->update_meta_data( '_trisk_keycrm_order_id', 99903 );
        $order->save();
        $order_id = $order->get_id();

        // 2. Запит з невірним токеном -> 403
        $req_bad = new WP_REST_Request( 'POST', '/trisk-crm/v1/webhook/keycrm' );
        $req_bad->set_query_params( array( 'token' => 'wrong_token' ) );
        $req_bad->set_header( 'Content-Type', 'application/json' );
        $req_bad->set_body( wp_json_encode( array(
            'event'   => 'order.change_order_status',
            'context' => array( 'id' => 99903, 'source_uuid' => (string) $order_id, 'status_id' => 4 ),
        ) ) );
        $res_bad = rest_do_request( $req_bad );

        // 3. Запит з вірним токеном -> 200, статус змінюється на completed
        $req_ok = new WP_REST_Request( 'POST', '/trisk-crm/v1/webhook/keycrm' );
        $req_ok->set_query_params( array( 'token' => $token ) );
        $req_ok->set_header( 'Content-Type', 'application/json' );
        $req_ok->set_body( wp_json_encode( array(
            'event'   => 'order.change_order_status',
            'context' => array( 'id' => 99903, 'source_uuid' => (string) $order_id, 'status_id' => 4 ),
        ) ) );
        $res_ok = rest_do_request( $req_ok );

        $order_reloaded = wc_get_order( $order_id );
        $new_status = $order_reloaded->get_status();

        // Перевіряємо захист від циклу: після обробки флаг syncing_from_crm має бути скинутий
        $syncing_flag = Trisk_CRM_Sync::$syncing_from_crm;

        $order_reloaded->delete( true );
        trisk_crm_update_settings( $orig_settings );

        echo wp_json_encode( array(
            'bad_status'   => $res_bad->get_status(),
            'ok_status'    => $res_ok->get_status(),
            'ok_data'      => $res_ok->get_data(),
            'new_status'   => $new_status,
            'syncing_flag' => $syncing_flag,
        ) );
    """))

    assert out["bad_status"] == 403
    assert out["ok_status"] == 200
    assert out["new_status"] == "completed"
    assert out["syncing_flag"] is False


def test_keycrm_webhook_updates_variation_stock_with_reserve(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        trisk_crm_ensure_variation_skus();

        $settings = trisk_crm_get_settings();
        $token = $settings['webhook_secret'];

        $var_ids = trisk_crm_find_products_by_sku( 'TR-guji-uraga-250g-beans' );
        $var_id = $var_ids[0];

        // Встановлюємо 50
        wc_update_product_stock( $var_id, 50, 'set' );

        // Відправляємо вебхук KeyCRM із залишками: 16 на складі, 3 в резерві -> доступно 13
        $req = new WP_REST_Request( 'POST', '/trisk-crm/v1/webhook/keycrm' );
        $req->set_query_params( array( 'token' => $token ) );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( array(
            array(
                'offer_id'   => 130827,
                'sku'        => '   TR-guji-uraga-250g-beans   ',
                'in_stock'   => 16,
                'in_reserve' => 3,
            ),
        ) ) );

        $res = rest_do_request( $req );

        $var_after = wc_get_product( $var_id );
        $stock_after = $var_after->get_stock_quantity();

        // Відновлюємо еталонний залишок 50
        wc_update_product_stock( $var_id, 50, 'set' );
        $var_restored = wc_get_product( $var_id );
        $stock_restored = $var_restored->get_stock_quantity();

        echo wp_json_encode( array(
            'res_status'     => $res->get_status(),
            'res_data'       => $res->get_data(),
            'stock_after'    => $stock_after,
            'stock_restored' => $stock_restored,
        ) );
    """))

    assert out["res_status"] == 200
    assert out["stock_after"] == 13
    assert out["stock_restored"] == 50


def test_telegram_sends_new_order_to_topic_and_edits_in_place_on_update(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        trisk_crm_ensure_variation_skus();

        $orig_settings = trisk_crm_get_settings();
        $test_settings = $orig_settings;
        $test_settings['tg_enabled'] = true;
        $test_settings['tg_bot_token'] = '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11';
        $test_settings['tg_chat_id'] = '-1001987654321';
        $test_settings['tg_thread_id'] = 42;
        trisk_crm_update_settings( $test_settings );

        $captured_requests = array();
        $edit_mock_status = 200;

        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$captured_requests, &$edit_mock_status ) {
            if ( strpos( $url, 'api.telegram.org' ) !== false ) {
                $path = parse_url( $url, PHP_URL_PATH );
                $raw_body = $args['body'] ?? '';
                $body = is_array( $raw_body ) ? $raw_body : json_decode( $raw_body, true );
                $captured_requests[] = array(
                    'url'    => $url,
                    'path'   => $path,
                    'method' => $args['method'] ?? 'POST',
                    'body'   => $body,
                );

                if ( strpos( $path, '/sendMessage' ) !== false ) {
                    return array(
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                        'body'     => json_encode( array(
                            'ok'     => true,
                            'result' => array(
                                'message_id'        => 9901,
                                'chat'              => array( 'id' => -1001987654321, 'title' => 'Тріск Замовлення' ),
                                'message_thread_id' => 42,
                                'text'              => $body['text'] ?? '',
                            ),
                        ) ),
                    );
                }

                if ( strpos( $path, '/editMessageText' ) !== false ) {
                    if ( 400 === $edit_mock_status ) {
                        return array(
                            'response' => array( 'code' => 400, 'message' => 'Bad Request' ),
                            'body'     => json_encode( array(
                                'ok'          => false,
                                'error_code'  => 400,
                                'description' => 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same as a current content and reply markup of the message',
                            ) ),
                        );
                    }

                    return array(
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                        'body'     => json_encode( array(
                            'ok'     => true,
                            'result' => array(
                                'message_id' => 9901,
                                'chat'       => array( 'id' => -1001987654321 ),
                                'text'       => $body['text'] ?? '',
                            ),
                        ) ),
                    );
                }
            }
            return $preempt;
        }, 10, 3 );

        // 1. Створюємо нове замовлення
        $order = wc_create_order();
        $order->set_billing_first_name( 'Тарас' );
        $order->set_billing_last_name( 'Шевченко' );
        $order->set_billing_phone( '+380501234567' );
        $order->set_billing_email( 'taras@example.com' );
        $order->set_customer_note( 'Дзвонити після 14:00' );
        $order->set_payment_method( 'cod' );
        $order->set_payment_method_title( 'Накладений платіж' );
        $order->set_status( 'processing' );
        $order->update_meta_data( '_trisk_np_city', 'Львів' );
        $order->update_meta_data( '_trisk_np_branch', '№12, вул. Городоцька, 48' );

        $var_ids = trisk_crm_find_products_by_sku( 'TR-guji-uraga-250g-beans' );
        $var = wc_get_product( $var_ids[0] );
        $order->add_product( $var, 2, array( 'subtotal' => 930, 'total' => 930 ) );
        $order->calculate_totals();
        $order->save();
        $order_id = $order->get_id();

        // 2. Відправляємо первинне повідомлення в Telegram (sendMessage)
        $res_send = trisk_crm_tg_sync_order_message( $order_id );
        $order_after_send = wc_get_order( $order_id );
        $saved_msg_id = $order_after_send->get_meta( '_trisk_tg_message_id' );
        $saved_chat_id = $order_after_send->get_meta( '_trisk_tg_chat_id' );

        // 3. Оновлюємо статус і прив'язку KeyCRM -> має викликатися editMessageText (без дублів!)
        $order_after_send->update_meta_data( '_trisk_keycrm_order_id', 77701 );
        $order_after_send->set_status( 'completed' );
        $order_after_send->save();

        $res_edit = trisk_crm_tg_sync_order_message( $order_id );

        // 4. Перевірка benign 400 Bad Request: message is not modified
        $edit_mock_status = 400;
        $res_not_modified = trisk_crm_tg_sync_order_message( $order_id );

        // Журнал
        $logs_send = trisk_crm_log_query( array( 'direction' => 'out_tg', 'event' => 'tg.send', 'entity_id' => (string) $order_id ) );
        $logs_edit = trisk_crm_log_query( array( 'direction' => 'out_tg', 'event' => 'tg.edit', 'entity_id' => (string) $order_id ) );

        $order_after_send->delete( true );
        trisk_crm_update_settings( $orig_settings );

        echo wp_json_encode( array(
            'res_send'          => $res_send,
            'saved_msg_id'      => $saved_msg_id,
            'saved_chat_id'     => $saved_chat_id,
            'res_edit'          => $res_edit,
            'res_not_modified'  => $res_not_modified,
            'captured_count'    => count( $captured_requests ),
            'first_req'         => $captured_requests[0] ?? null,
            'second_req'        => $captured_requests[1] ?? null,
            'log_send_status'   => $logs_send[0]['status'] ?? '',
            'log_edit_status'   => $logs_edit[0]['status'] ?? '',
            'log_edit_statuses' => array_column( $logs_edit, 'status' ),
        ) );
    """))

    assert out["res_send"]["ok"] is True
    assert out["res_send"]["action"] == "sent"
    assert out["saved_msg_id"] == "9901"
    assert out["saved_chat_id"] == "-1001987654321"

    # Перевірка першого запиту (sendMessage)
    first = out["first_req"]
    assert "sendMessage" in first["path"]
    assert first["body"]["chat_id"] == "-1001987654321"
    assert first["body"]["message_thread_id"] == 42
    assert first["body"]["parse_mode"] == "HTML"
    assert "Замовлення #" in first["body"]["text"]
    assert "Тарас Шевченко" in first["body"]["text"]
    assert "+380501234567" in first["body"]["text"]
    assert "Львів" in first["body"]["text"]
    assert "Городоцька" in first["body"]["text"]
    assert "TR-guji-uraga-250g-beans" in first["body"]["text"]
    assert "930" in first["body"]["text"]
    assert "Дзвонити після 14:00" in first["body"]["text"]
    # Клавіатура з 4 кнопками у 2 ряди
    kb = first["body"]["reply_markup"]["inline_keyboard"]
    assert len(kb) == 2
    assert len(kb[0]) == 2
    assert len(kb[1]) == 2
    assert any("trisk_st:" in btn["callback_data"] for row in kb for btn in row)

    # Перевірка другого запиту (editMessageText)
    assert out["res_edit"]["ok"] is True
    assert out["res_edit"]["action"] == "edited"
    second = out["second_req"]
    assert "editMessageText" in second["path"]
    assert second["body"]["chat_id"] == "-1001987654321"
    assert second["body"]["message_id"] == 9901
    assert "KeyCRM ID:" in second["body"]["text"]
    assert "77701" in second["body"]["text"]
    assert "Виконано" in second["body"]["text"]

    # Перевірка harmless 400 not modified
    assert out["res_not_modified"]["ok"] is True
    assert out["res_not_modified"]["action"] == "not_modified"

    # Перевірка журналу
    assert out["log_send_status"] == "success"
    # Справжнє редагування — success, повтор без змін («message is not modified») — skipped
    assert sorted(out["log_edit_statuses"]) == ["skipped", "success"]


def test_telegram_inline_button_updates_woo_and_keycrm_and_marks_manager(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        $orig_settings = trisk_crm_get_settings();
        $test_settings = $orig_settings;
        $test_settings['tg_enabled'] = true;
        $test_settings['tg_bot_token'] = '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11';
        $test_settings['tg_chat_id'] = '-1001987654321';
        $test_settings['tg_thread_id'] = 42;
        $test_settings['tg_webhook_secret'] = 'tg_test_secret_abc123';
        $test_settings['api_key'] = 'test_keycrm_token';
        $test_settings['status_map'] = Trisk_CRM_Settings::get_defaults()['status_map'];
        trisk_crm_update_settings( $test_settings );

        $order = wc_create_order();
        $order->set_billing_first_name( 'Іван' );
        $order->set_billing_last_name( 'Франко' );
        $order->set_status( 'processing' );
        $order->update_meta_data( '_trisk_tg_message_id', 9901 );
        $order->update_meta_data( '_trisk_tg_chat_id', '-1001987654321' );
        $order->update_meta_data( '_trisk_keycrm_order_id', 77701 );
        $order->save();
        $order_id = $order->get_id();

        $captured_tg_calls = array();
        $captured_crm_calls = array();

        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$captured_tg_calls, &$captured_crm_calls ) {
            if ( strpos( $url, 'api.telegram.org' ) !== false ) {
                $path = parse_url( $url, PHP_URL_PATH );
                $raw_body = $args['body'] ?? '';
                $body = is_array( $raw_body ) ? $raw_body : json_decode( $raw_body, true );
                $captured_tg_calls[] = array(
                    'path' => $path,
                    'body' => $body,
                );

                if ( strpos( $path, '/answerCallbackQuery' ) !== false ) {
                    return array(
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                        'body'     => json_encode( array( 'ok' => true, 'result' => true ) ),
                    );
                }
                if ( strpos( $path, '/editMessageText' ) !== false ) {
                    return array(
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                        'body'     => json_encode( array( 'ok' => true, 'result' => array( 'message_id' => 9901 ) ) ),
                    );
                }
                if ( strpos( $path, '/getUpdates' ) !== false ) {
                    return array(
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                        'body'     => json_encode( array(
                            'ok'     => true,
                            'result' => array(
                                array(
                                    'update_id'      => 20002,
                                    'callback_query' => array(
                                        'id'      => 'cb_polled_888',
                                        'from'    => array(
                                            'id'         => 87654321,
                                            'is_bot'     => false,
                                            'first_name' => 'Оксана',
                                            'username'   => 'oksana_manager',
                                        ),
                                        'message' => array(
                                            'message_id' => 9901,
                                            'chat'       => array( 'id' => -1001987654321 ),
                                        ),
                                        'data'    => "trisk_st:{$GLOBALS['order_id_for_poll']}:completed",
                                    ),
                                ),
                            ),
                        ) ),
                    );
                }
            }

            if ( strpos( $url, 'openapi.keycrm.app' ) !== false ) {
                $path = parse_url( $url, PHP_URL_PATH );
                $raw_body = $args['body'] ?? '';
                $body = is_array( $raw_body ) ? $raw_body : json_decode( $raw_body, true );
                $captured_crm_calls[] = array(
                    'path'   => $path,
                    'method' => $args['method'] ?? 'GET',
                    'body'   => $body,
                );

                return array(
                    'response' => array( 'code' => 200, 'message' => 'OK' ),
                    'body'     => json_encode( array( 'data' => array( 'id' => 77701, 'status_id' => 3 ) ) ),
                );
            }

            return $preempt;
        }, 10, 3 );

        // 1. Запит до REST-ендпоінту з невірним секретом -> 403
        $req_bad = new WP_REST_Request( 'POST', '/trisk-crm/v1/webhook/telegram' );
        $req_bad->set_header( 'X-Telegram-Bot-Api-Secret-Token', 'wrong_secret' );
        $res_bad = rest_do_request( $req_bad );

        // 2. Запит до REST-ендпоінту з вірним секретом: менеджер тисне "Відправлено"
        $callback_payload = array(
            'update_id'      => 10001,
            'callback_query' => array(
                'id'      => 'cb_query_999',
                'from'    => array(
                    'id'         => 12345678,
                    'is_bot'     => false,
                    'first_name' => 'Остап',
                    'username'   => 'ostap_manager',
                ),
                'message' => array(
                    'message_id'        => 9901,
                    'chat'              => array( 'id' => -1001987654321, 'type' => 'supergroup' ),
                    'message_thread_id' => 42,
                ),
                'data'    => "trisk_st:{$order_id}:shipped",
            ),
        );

        $req_ok = new WP_REST_Request( 'POST', '/trisk-crm/v1/webhook/telegram' );
        $req_ok->set_header( 'X-Telegram-Bot-Api-Secret-Token', 'tg_test_secret_abc123' );
        $req_ok->set_header( 'Content-Type', 'application/json' );
        $req_ok->set_body( wp_json_encode( $callback_payload ) );
        $res_ok = rest_do_request( $req_ok );

        $order_after_click = wc_get_order( $order_id );
        $stage = $order_after_click->get_meta( '_trisk_crm_stage' );
        $manager_note = $order_after_click->get_meta( '_trisk_tg_manager_note' );

        // 3. Тестування локального опитування trisk_crm_tg_poll_callbacks()
        $GLOBALS['order_id_for_poll'] = $order_id;
        $poll_result = trisk_crm_tg_poll_callbacks();
        $order_after_poll = wc_get_order( $order_id );
        $stage_after_poll = $order_after_poll->get_meta( '_trisk_crm_stage' );
        $status_after_poll = $order_after_poll->get_status();
        $note_after_poll = $order_after_poll->get_meta( '_trisk_tg_manager_note' );

        // Логи
        $logs_tg = trisk_crm_log_query( array( 'direction' => 'in_tg', 'event' => 'tg.callback', 'entity_id' => (string) $order_id ) );

        $order_after_poll->delete( true );
        trisk_crm_update_settings( $orig_settings );

        echo wp_json_encode( array(
            'bad_status'         => $res_bad->get_status(),
            'ok_status'          => $res_ok->get_status(),
            'stage'              => $stage,
            'manager_note'       => $manager_note,
            'poll_result'        => $poll_result,
            'stage_after_poll'   => $stage_after_poll,
            'status_after_poll'  => $status_after_poll,
            'note_after_poll'    => $note_after_poll,
            'captured_tg'        => $captured_tg_calls,
            'captured_crm'       => $captured_crm_calls,
            'log_tg_status'      => $logs_tg[0]['status'] ?? '',
        ) );
    """))

    assert out["bad_status"] == 403
    assert out["ok_status"] == 200
    assert out["stage"] == "shipped"
    assert "@ostap_manager" in out["manager_note"]
    assert "Відправлено" in out["manager_note"]

    # Перевірка дзвінків до Telegram
    tg_paths = [c["path"] for c in out["captured_tg"]]
    assert any("answerCallbackQuery" in p for p in tg_paths)
    assert any("editMessageText" in p for p in tg_paths)

    # Перевірка тексту answerCallbackQuery
    ans = next(c for c in out["captured_tg"] if "answerCallbackQuery" in c["path"])
    assert ans["body"]["callback_query_id"] == "cb_query_999"
    assert "Відправлено" in ans["body"]["text"]

    # Перевірка виклику KeyCRM PUT /order/77701
    assert len(out["captured_crm"]) >= 1
    crm_call = out["captured_crm"][0]
    assert crm_call["method"] == "PUT"
    assert "77701" in crm_call["path"]
    assert crm_call["body"]["status_id"] == 3

    # Перевірка poll callbacks
    assert out["stage_after_poll"] == "completed"
    assert out["status_after_poll"] == "completed"
    assert "@oksana_manager" in out["note_after_poll"]

    # Журнал
    assert out["log_tg_status"] == "success"


def test_telegram_webhook_skips_non_callback_updates(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        $orig_settings = trisk_crm_get_settings();
        $test_settings = $orig_settings;
        $test_settings['tg_enabled'] = true;
        $test_settings['tg_webhook_secret'] = 'tg_test_secret_abc123';
        trisk_crm_update_settings( $test_settings );

        // 1. Звичайне текстове повідомлення без callback_query -> 200 OK, skipped: no_callback_query
        $req_msg = new WP_REST_Request( 'POST', '/trisk-crm/v1/webhook/telegram' );
        $req_msg->set_header( 'X-Telegram-Bot-Api-Secret-Token', 'tg_test_secret_abc123' );
        $req_msg->set_header( 'Content-Type', 'application/json' );
        $req_msg->set_body( wp_json_encode( array(
            'update_id' => 10000,
            'message'   => array(
                'message_id' => 1234,
                'chat'       => array( 'id' => -1001987654321 ),
                'text'       => 'Менеджер написав коментар у темі',
            ),
        ) ) );
        $res_msg = rest_do_request( $req_msg );

        // 2. Сторонній або невалідний callback -> 200 OK, skipped: invalid_callback_data
        $req_inv = new WP_REST_Request( 'POST', '/trisk-crm/v1/webhook/telegram' );
        $req_inv->set_header( 'X-Telegram-Bot-Api-Secret-Token', 'tg_test_secret_abc123' );
        $req_inv->set_header( 'Content-Type', 'application/json' );
        $req_inv->set_body( wp_json_encode( array(
            'update_id'      => 10002,
            'callback_query' => array(
                'id'   => 'cb_other',
                'data' => 'foreign_action_xyz',
            ),
        ) ) );
        $res_inv = rest_do_request( $req_inv );

        trisk_crm_update_settings( $orig_settings );

        echo wp_json_encode( array(
            'msg_status' => $res_msg->get_status(),
            'msg_data'   => $res_msg->get_data(),
            'inv_status' => $res_inv->get_status(),
            'inv_data'   => $res_inv->get_data(),
        ) );
    """))

    assert out["msg_status"] == 200
    assert out["msg_data"]["ok"] is True
    assert out["msg_data"]["skipped"] == "no_callback_query"

    assert out["inv_status"] == 200
    assert out["inv_data"]["ok"] is True
    assert out["inv_data"]["skipped"] == "invalid_callback_data"


def test_old_orders_not_sent_to_telegram(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        $orig_settings = trisk_crm_get_settings();
        $test_settings = $orig_settings;
        $test_settings['tg_enabled'] = true;
        $test_settings['tg_bot_token'] = '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11';
        $test_settings['tg_chat_id'] = '-1001987654321';
        $test_settings['activated_at'] = gmdate( 'Y-m-d H:i:s', time() + 3600 );
        trisk_crm_update_settings( $test_settings );

        $captured_calls = array();
        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$captured_calls ) {
            if ( strpos( $url, 'api.telegram.org' ) !== false ) {
                $captured_calls[] = $url;
                return array(
                    'response' => array( 'code' => 200, 'message' => 'OK' ),
                    'body'     => json_encode( array(
                        'ok'     => true,
                        'result' => array( 'message_id' => 9999 ),
                    ) ),
                );
            }
            return $preempt;
        }, 10, 3 );

        // Замовлення створене ДО активації плагіна
        $order = wc_create_order();
        $order->set_date_created( time() - 3600 );
        $order->set_billing_first_name( 'Старе' );
        $order->set_billing_last_name( 'Замовлення' );
        $order->set_status( 'processing' );
        $order->save();
        $order_id = $order->get_id();

        // Перший виклик: старе замовлення без force_sync -> ігнорується, Telegram API не викликається
        $res_skip = trisk_crm_tg_sync_order_message( $order_id );
        $msg_id_after_skip = $order->get_meta( '_trisk_tg_message_id' );
        $calls_count_after_skip = count( $captured_calls );

        // Другий виклик: з _trisk_crm_force_sync = 1 -> надсилається в Telegram
        $order->update_meta_data( '_trisk_crm_force_sync', '1' );
        $order->save();
        $res_forced = trisk_crm_tg_sync_order_message( $order_id );
        $order_reloaded = wc_get_order( $order_id );
        $msg_id_after_forced = $order_reloaded->get_meta( '_trisk_tg_message_id' );

        $order_reloaded->delete( true );
        trisk_crm_update_settings( $orig_settings );

        echo wp_json_encode( array(
            'res_skip'                => $res_skip,
            'msg_id_after_skip'       => $msg_id_after_skip,
            'calls_count_after_skip'  => $calls_count_after_skip,
            'res_forced'              => $res_forced,
            'msg_id_after_forced'     => $msg_id_after_forced,
            'total_calls'             => count( $captured_calls ),
        ) );
    """))

    assert out["res_skip"]["ok"] is False
    assert out["res_skip"]["skipped"] == "old_order"
    assert out["msg_id_after_skip"] == ""
    assert out["calls_count_after_skip"] == 0

    assert out["res_forced"]["ok"] is True
    assert out["res_forced"]["action"] == "sent"
    assert out["msg_id_after_forced"] == "9999"
    assert out["total_calls"] == 1


def test_admin_status_page_renders_and_retries_failed_log(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        trisk_crm_ensure_variation_skus();

        $orig_settings = trisk_crm_get_settings();
        $test_settings = $orig_settings;
        $test_settings['enabled'] = true;
        $test_settings['api_key'] = 'keycrm_test_admin_key';
        $test_settings['source_id'] = 2;
        $test_settings['warehouse_id'] = 5;
        $test_settings['tg_enabled'] = true;
        $test_settings['tg_bot_token'] = '987654:ABC-DEF1234ghIkl-zyx57W2v1u123ew99';
        $test_settings['tg_chat_id'] = '-1001234567890';
        $test_settings['tg_thread_id'] = 42;
        trisk_crm_update_settings( $test_settings );

        $order_id = 0;
        try {
            // 1. Створюємо замовлення та невдалий запис логу
            $order = wc_create_order();
            $order->set_billing_first_name( 'Іван' );
            $order->set_billing_last_name( 'Франко' );
            $order->set_status( 'processing' );
            $order->save();
            $order_id = $order->get_id();

            $failed_log_id = trisk_crm_log_create( array(
                'direction'     => 'out_crm',
                'event'         => 'order.push',
                'entity_type'   => 'order',
                'entity_id'     => (string) $order_id,
                'status'        => 'error',
                'http_code'     => 500,
                'attempts'      => 4,
                'error_message' => 'Internal Server Error (500)',
            ) );

            // 2. Рендеримо сторінку «Стан інтеграції» (tab=status)
            $_GET['page'] = 'trisk-crm-sync';
            $_GET['tab']  = 'status';
            ob_start();
            if ( function_exists( 'trisk_crm_render_admin_page' ) ) {
                trisk_crm_render_admin_page();
            }
            $html_status = ob_get_clean();

            // 3. Рендеримо сторінку «Налаштування» (tab=settings)
            $_GET['tab'] = 'settings';
            ob_start();
            if ( function_exists( 'trisk_crm_render_admin_page' ) ) {
                trisk_crm_render_admin_page();
            }
            $html_settings = ob_get_clean();

            // 4. Тестуємо повторну відправку запису з помилкою через trisk_crm_retry_log_entry
            $captured_requests = array();
            add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$captured_requests ) {
                if ( strpos( $url, 'openapi.keycrm.app' ) !== false ) {
                    $captured_requests[] = array(
                        'method' => $args['method'] ?? 'GET',
                        'url'    => $url,
                    );
                    return array(
                        'response' => array( 'code' => 201, 'message' => 'Created' ),
                        'body'     => json_encode( array( 'data' => array( 'id' => 88123 ) ) ),
                    );
                }
                if ( strpos( $url, 'api.telegram.org' ) !== false ) {
                    return array(
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                        'body'     => json_encode( array( 'ok' => true, 'result' => array( 'message_id' => 9991 ) ) ),
                    );
                }
                return $preempt;
            }, 10, 3 );

            $retry_success = false;
            if ( function_exists( 'trisk_crm_retry_log_entry' ) ) {
                $retry_success = trisk_crm_retry_log_entry( $failed_log_id );
            }

            $log_after = trisk_crm_log_get( $failed_log_id );
            $order_after = wc_get_order( $order_id );
            $crm_order_id_meta = $order_after->get_meta( '_trisk_keycrm_order_id' );
        } finally {
            if ( $order_id ) {
                global $wpdb;
                $wpdb->delete( $wpdb->prefix . 'trisk_crm_logs', array( 'entity_id' => (string) $order_id ) );
                $o = wc_get_order( $order_id );
                if ( $o ) {
                    $o->delete( true );
                }
            }
            trisk_crm_update_settings( $orig_settings );
        }

        echo wp_json_encode( array(
            'has_render_func'   => function_exists( 'trisk_crm_render_admin_page' ),
            'html_status_len'   => strlen( $html_status ),
            'html_settings_len' => strlen( $html_settings ),
            'html_status'       => $html_status,
            'html_settings'     => $html_settings,
            'retry_success'     => $retry_success,
            'log_status_after'  => $log_after['status'] ?? null,
            'log_code_after'    => (int) ( $log_after['http_code'] ?? 0 ),
            'crm_order_id_meta' => (string) $crm_order_id_meta,
            'captured_requests' => $captured_requests,
        ) );
    """))

    assert out["has_render_func"] is True
    assert out["html_status_len"] > 0
    assert out["html_settings_len"] > 0

    # Перевірка вкладки «Стан інтеграції»
    status_html = out["html_status"]
    assert "Стан інтеграції" in status_html
    assert "KeyCRM" in status_html
    assert "Telegram" in status_html
    assert "Вивантажити каталог" in status_html
    assert "Синхронізувати ціни зараз" in status_html
    assert "Опитати Telegram (getUpdates)" in status_html
    assert "Журнал подій" in status_html
    assert "Надіслати повторно" in status_html

    # Перевірка вкладки «Налаштування»
    settings_html = out["html_settings"]
    assert "Налаштування" in settings_html
    assert "trisk_crm_settings[enabled]" in settings_html or 'name="trisk_crm_settings[enabled]"' in settings_html
    assert "trisk_crm_settings[api_key]" in settings_html or 'name="trisk_crm_settings[api_key]"' in settings_html
    assert "/trisk-crm/v1/webhook/keycrm" in settings_html
    assert "/trisk-crm/v1/webhook/telegram" in settings_html
    assert "Підтягнути джерела й статуси з KeyCRM" in settings_html
    assert "Визначити ID групи й теми автоматично" in settings_html
    assert "Зареєструвати HTTPS-вебхук у Telegram" in settings_html

    # Перевірка повторної відправки (Retry)
    assert out["retry_success"] is True
    assert out["log_status_after"] == "success"
    assert out["log_code_after"] == 201
    assert out["crm_order_id_meta"] == "88123"
    assert len(out["captured_requests"]) >= 1


def test_push_retry_from_manual_button_schedules_next_attempt(wp_cli):
    """Кнопка «Надіслати повторно» передає attempt масивом: повтор нового замовлення не має падати з TypeError."""
    out = json.loads(wp_cli("eval", r"""
        $orig_settings = trisk_crm_get_settings();
        $test_settings = $orig_settings;
        $test_settings['enabled'] = true;
        $test_settings['api_key'] = 'test_retry_key';
        $test_settings['tg_enabled'] = false;
        $test_settings['activated_at'] = '';
        trisk_crm_update_settings( $test_settings );

        add_filter( 'trisk_crm_skip_rate_limit', '__return_true' );
        add_filter( 'pre_http_request', function( $preempt, $args, $url ) {
            if ( strpos( $url, 'openapi.keycrm.app' ) !== false ) {
                return array(
                    'response' => array( 'code' => 429, 'message' => 'Too Many Requests' ),
                    'body'     => json_encode( array( 'message' => 'Too Many Requests' ) ),
                );
            }
            if ( strpos( $url, 'api.telegram.org' ) !== false ) {
                return new WP_Error( 'blocked', 'Telegram blocked in test' );
            }
            return $preempt;
        }, 10, 3 );

        $order_id = 0;
        $log_id = 0;
        try {
            $order = wc_create_order();
            $order->set_billing_first_name( 'Марко' );
            $order->set_billing_last_name( 'Вовчок' );
            $order->set_status( 'processing' );
            $order->save();
            $order_id = $order->get_id();

            // Прибираємо чергу, яку створили хуки зміни статусу
            as_unschedule_all_actions( 'trisk_crm_push_order_job', array( 'order_id' => $order_id, 'attempt' => 1 ), 'trisk-crm-sync' );

            $log_id = trisk_crm_log_create( array(
                'direction'   => 'out_crm',
                'event'       => 'order.push',
                'entity_type' => 'order',
                'entity_id'   => (string) $order_id,
                'status'      => 'error',
            ) );

            $res = trisk_crm_push_order_now( $order_id, array( 'log_id' => $log_id, 'attempt' => 1 ) );
            $scheduled_attempt_2 = as_has_scheduled_action( 'trisk_crm_push_order_job', array( 'order_id' => $order_id, 'attempt' => 2 ), 'trisk-crm-sync' );
            $log = trisk_crm_log_get( $log_id );

            // attempt = 2 -> наступний повтор має бути attempt = 3
            $res_2 = trisk_crm_push_order_now( $order_id, array( 'log_id' => $log_id, 'attempt' => 2 ) );
            $scheduled_attempt_3 = as_has_scheduled_action( 'trisk_crm_push_order_job', array( 'order_id' => $order_id, 'attempt' => 3 ), 'trisk-crm-sync' );
        } finally {
            foreach ( array( 1, 2, 3 ) as $n ) {
                as_unschedule_all_actions( 'trisk_crm_push_order_job', array( 'order_id' => $order_id, 'attempt' => $n ), 'trisk-crm-sync' );
            }
            global $wpdb;
            $wpdb->delete( $wpdb->prefix . 'trisk_crm_logs', array( 'entity_id' => (string) $order_id ) );
            $o = wc_get_order( $order_id );
            if ( $o ) {
                $o->delete( true );
            }
            trisk_crm_update_settings( $orig_settings );
        }

        echo wp_json_encode( array(
            'res_ok'              => $res['ok'],
            'res_retryable'       => $res['retryable'],
            'scheduled_attempt_2' => $scheduled_attempt_2,
            'log_status'          => $log['status'] ?? '',
            'res_2_ok'            => $res_2['ok'],
            'scheduled_attempt_3' => $scheduled_attempt_3,
        ) );
    """))

    assert out["res_ok"] is False
    assert out["res_retryable"] is True
    assert out["scheduled_attempt_2"] is True
    assert out["log_status"] == "retrying"
    assert out["res_2_ok"] is False
    assert out["scheduled_attempt_3"] is True


def test_keycrm_webhook_ignores_unmapped_status(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        $orig_settings = trisk_crm_get_settings();
        $token = $orig_settings['webhook_secret'];
        $test_settings = $orig_settings;
        $test_settings['status_map'] = Trisk_CRM_Settings::get_defaults()['status_map'];
        trisk_crm_update_settings( $test_settings );

        $order_id = 0;
        try {
            $order = wc_create_order();
            $order->set_status( 'on-hold' );
            $order->update_meta_data( '_trisk_keycrm_order_id', 99904 );
            $order->save();
            $order_id = $order->get_id();

            $unmapped = Trisk_CRM_Order_Sync::map_crm_status_to_wc( 987654, $test_settings['status_map'] );
            $mapped   = Trisk_CRM_Order_Sync::map_crm_status_to_wc( 4, $test_settings['status_map'] );

            // Статус 987654 відсутній у таблиці відповідності
            $req = new WP_REST_Request( 'POST', '/trisk-crm/v1/webhook/keycrm' );
            $req->set_query_params( array( 'token' => $token ) );
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( wp_json_encode( array(
                'event'   => 'order.change_order_status',
                'context' => array( 'id' => 99904, 'source_uuid' => (string) $order_id, 'status_id' => 987654 ),
            ) ) );
            $res = rest_do_request( $req );

            $after = wc_get_order( $order_id );
            $logs  = trisk_crm_log_query( array( 'direction' => 'in_crm', 'event' => 'webhook.status', 'entity_id' => (string) $order_id ) );
        } finally {
            global $wpdb;
            $wpdb->delete( $wpdb->prefix . 'trisk_crm_logs', array( 'entity_id' => (string) $order_id ) );
            $o = wc_get_order( $order_id );
            if ( $o ) {
                $o->delete( true );
            }
            trisk_crm_update_settings( $orig_settings );
        }

        echo wp_json_encode( array(
            'unmapped'   => $unmapped,
            'mapped'     => $mapped,
            'status'     => $res->get_status(),
            'data'       => $res->get_data(),
            'wc_status'  => $after->get_status(),
            'stage'      => (string) $after->get_meta( '_trisk_crm_stage' ),
            'log_status' => $logs[0]['status'] ?? '',
            'log_code'   => (int) ( $logs[0]['http_code'] ?? 0 ),
        ) );
    """))

    assert out["unmapped"] == ""
    assert out["mapped"] == "completed"
    assert out["status"] == 200
    assert out["data"]["ok"] is True
    assert out["data"]["skipped"] == "unmapped_status"
    assert out["wc_status"] == "on-hold"
    assert out["stage"] == ""
    assert out["log_status"] == "skipped"
    assert out["log_code"] == 200


def test_paid_order_payment_is_sent_to_keycrm_exactly_once(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        trisk_crm_ensure_variation_skus();

        $orig_settings = trisk_crm_get_settings();
        $test_settings = $orig_settings;
        $test_settings['enabled'] = true;
        $test_settings['api_key'] = 'test_payment_key';
        $test_settings['tg_enabled'] = false;
        $test_settings['activated_at'] = '';
        $test_settings['status_map'] = Trisk_CRM_Settings::get_defaults()['status_map'];
        trisk_crm_update_settings( $test_settings );

        add_filter( 'trisk_crm_skip_rate_limit', '__return_true' );

        $calls = array();
        $crm_payments = array();
        $telegram_calls = 0;
        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$calls, &$crm_payments, &$telegram_calls ) {
            if ( strpos( $url, 'api.telegram.org' ) !== false ) {
                $telegram_calls++;
                return new WP_Error( 'blocked', 'Telegram blocked in test' );
            }
            if ( strpos( $url, 'openapi.keycrm.app' ) === false ) {
                return $preempt;
            }
            $path   = parse_url( $url, PHP_URL_PATH );
            $method = $args['method'] ?? 'GET';
            $body   = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
            $calls[] = array( 'method' => $method, 'path' => $path, 'body' => $body );

            if ( 'POST' === $method && preg_match( '#/v1/order$#', $path ) ) {
                return array( 'response' => array( 'code' => 201, 'message' => 'Created' ), 'body' => json_encode( array( 'data' => array( 'id' => 77703 ) ) ) );
            }
            if ( 'GET' === $method && preg_match( '#/v1/order/(\d+)$#', $path ) ) {
                return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => json_encode( array( 'id' => 77701, 'payments' => $crm_payments ) ) );
            }
            if ( 'PUT' === $method && preg_match( '#/v1/order/\d+/payment/\d+$#', $path ) ) {
                return array( 'response' => array( 'code' => 202, 'message' => 'Accepted' ), 'body' => json_encode( array( 'status' => 'paid' ) ) );
            }
            if ( 'POST' === $method && preg_match( '#/v1/order/\d+/payment$#', $path ) ) {
                return array( 'response' => array( 'code' => 201, 'message' => 'Created' ), 'body' => json_encode( array( 'status' => 'paid' ) ) );
            }
            if ( 'PUT' === $method && preg_match( '#/v1/order/\d+$#', $path ) ) {
                return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => json_encode( array( 'data' => array( 'id' => 1 ) ) ) );
            }
            return array( 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'body' => '{}' );
        }, 10, 3 );

        $summ = function( $calls ) {
            return array_map( function( $c ) { return $c['method'] . ' ' . preg_replace( '#^.*/v1#', '', $c['path'] ); }, $calls );
        };
        $mk_order = function( $crm_id, $status ) {
            $order = wc_create_order();
            $order->set_billing_first_name( 'Ольга' );
            $order->set_billing_last_name( 'Кобилянська' );
            $order->set_payment_method( 'morkva-liqpay' );
            $order->set_payment_method_title( 'Оплата карткою онлайн (LiqPay)' );
            $item_shipping = new WC_Order_Item_Shipping();
            $item_shipping->set_method_title( 'Нова Пошта, відділення' );
            $item_shipping->set_total( 100 );
            $order->add_item( $item_shipping );
            $order->calculate_totals();
            $order->set_status( $status );
            if ( $crm_id ) {
                $order->update_meta_data( '_trisk_keycrm_order_id', $crm_id );
            }
            $order->save();
            return $order->get_id();
        };

        $ids = array();
        try {
            // A. Замовлення вже в CRM з платежем not_paid; спочатку не оплачене
            $crm_payments = array( array( 'id' => 555, 'status' => 'not_paid', 'amount' => 100 ) );
            $a = $mk_order( 77701, 'pending' );
            $ids[] = $a;

            $calls = array();
            trisk_crm_push_order_now( $a );
            $a_calls_unpaid = $summ( $calls );
            $a_meta_unpaid = (string) wc_get_order( $a )->get_meta( '_trisk_keycrm_payment_synced' );

            // Оплата LiqPay
            $calls = array();
            wc_get_order( $a )->payment_complete( 'LP-TX-1' );
            $a_is_paid = wc_get_order( $a )->is_paid();
            trisk_crm_push_order_now( $a );
            $a_calls_paid = $summ( $calls );
            $a_payment_put = null;
            foreach ( $calls as $c ) {
                if ( 'PUT' === $c['method'] && preg_match( '#/payment/555$#', $c['path'] ) ) {
                    $a_payment_put = $c['body'];
                }
            }
            $a_meta_paid = (string) wc_get_order( $a )->get_meta( '_trisk_keycrm_payment_synced' );

            // Повторний push не дублює оплату
            $calls = array();
            trisk_crm_push_order_now( $a );
            $a_calls_again = $summ( $calls );

            // B. Замовлення в CRM без жодного платежу -> POST /order/{id}/payment
            $crm_payments = array();
            $b = $mk_order( 77702, 'processing' );
            $ids[] = $b;
            $calls = array();
            trisk_crm_push_order_now( $b );
            $b_calls = $summ( $calls );
            $b_payment_post = null;
            foreach ( $calls as $c ) {
                if ( 'POST' === $c['method'] && preg_match( '#/payment$#', $c['path'] ) ) {
                    $b_payment_post = $c['body'];
                }
            }
            $b_meta = (string) wc_get_order( $b )->get_meta( '_trisk_keycrm_payment_synced' );

            // C. Замовлення створюється в CRM уже оплаченим -> прапорець ставиться одразу, окремих платежів немає
            $c_id = $mk_order( 0, 'processing' );
            $ids[] = $c_id;
            $calls = array();
            trisk_crm_push_order_now( $c_id );
            $c_calls_create = $summ( $calls );
            $c_meta = (string) wc_get_order( $c_id )->get_meta( '_trisk_keycrm_payment_synced' );
            $calls = array();
            trisk_crm_push_order_now( $c_id );
            $c_calls_update = $summ( $calls );
        } finally {
            foreach ( $ids as $oid ) {
                foreach ( array( 1, 2 ) as $n ) {
                    as_unschedule_all_actions( 'trisk_crm_push_order_job', array( 'order_id' => $oid, 'attempt' => $n ), 'trisk-crm-sync' );
                }
                global $wpdb;
                $wpdb->delete( $wpdb->prefix . 'trisk_crm_logs', array( 'entity_id' => (string) $oid ) );
                $o = wc_get_order( $oid );
                if ( $o ) {
                    $o->delete( true );
                }
            }
            trisk_crm_update_settings( $orig_settings );
        }

        echo wp_json_encode( array(
            'a_calls_unpaid' => $a_calls_unpaid,
            'a_meta_unpaid'  => $a_meta_unpaid,
            'a_is_paid'      => $a_is_paid,
            'a_calls_paid'   => $a_calls_paid,
            'a_payment_put'  => $a_payment_put,
            'a_meta_paid'    => $a_meta_paid,
            'a_calls_again'  => $a_calls_again,
            'b_calls'        => $b_calls,
            'b_payment_post' => $b_payment_post,
            'b_meta'         => $b_meta,
            'c_calls_create' => $c_calls_create,
            'c_meta'         => $c_meta,
            'c_calls_update' => $c_calls_update,
            'telegram_calls' => $telegram_calls,
        ) );
    """))

    # A: до оплати передається лише статус
    assert out["a_calls_unpaid"] == ["PUT /order/77701"]
    assert out["a_meta_unpaid"] == ""

    # A: після payment_complete — статус + пошук платежу + оновлення наявного платежу рівно один раз
    assert out["a_is_paid"] is True
    assert out["a_calls_paid"] == ["PUT /order/77701", "GET /order/77701", "PUT /order/77701/payment/555"]
    assert out["a_payment_put"]["status"] == "paid"
    assert "LP-TX-1" in out["a_payment_put"]["description"]
    assert out["a_meta_paid"] == "1"
    assert out["a_calls_again"] == ["PUT /order/77701"]

    # B: платежу в CRM немає — додається новий
    assert out["b_calls"] == ["PUT /order/77702", "GET /order/77702", "POST /order/77702/payment"]
    assert out["b_payment_post"]["status"] == "paid"
    assert out["b_payment_post"]["amount"] == 100
    assert out["b_payment_post"]["payment_method"] == "Оплата карткою онлайн (LiqPay)"
    assert out["b_meta"] == "1"

    # C: створене вже оплаченим — прапорець одразу, зайвих запитів немає
    assert out["c_calls_create"] == ["POST /order"]
    assert out["c_meta"] == "1"
    assert out["c_calls_update"] == ["PUT /order/77703"]

    assert out["telegram_calls"] == 0


def test_telegram_callback_rejected_from_foreign_chat_or_unlisted_user(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        $orig_settings = trisk_crm_get_settings();
        $test_settings = $orig_settings;
        $test_settings['enabled'] = true;
        $test_settings['api_key'] = 'test_keycrm_token';
        $test_settings['tg_enabled'] = true;
        $test_settings['tg_bot_token'] = '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11';
        $test_settings['tg_chat_id'] = '-1001987654321';
        $test_settings['tg_thread_id'] = 42;
        $test_settings['tg_allowed_user_ids'] = '';
        $test_settings['activated_at'] = '';
        $test_settings['status_map'] = Trisk_CRM_Settings::get_defaults()['status_map'];
        trisk_crm_update_settings( $test_settings );

        add_filter( 'trisk_crm_skip_rate_limit', '__return_true' );

        $tg_calls = array();
        $crm_calls = array();
        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$tg_calls, &$crm_calls ) {
            if ( strpos( $url, 'api.telegram.org' ) !== false ) {
                $raw_body = $args['body'] ?? '';
                $tg_calls[] = array(
                    'path' => parse_url( $url, PHP_URL_PATH ),
                    'body' => is_array( $raw_body ) ? $raw_body : json_decode( $raw_body, true ),
                );
                return array(
                    'response' => array( 'code' => 200, 'message' => 'OK' ),
                    'body'     => json_encode( array( 'ok' => true, 'result' => array( 'message_id' => 9901 ) ) ),
                );
            }
            if ( strpos( $url, 'openapi.keycrm.app' ) !== false ) {
                $crm_calls[] = array( 'method' => $args['method'] ?? 'GET', 'path' => parse_url( $url, PHP_URL_PATH ) );
                return array(
                    'response' => array( 'code' => 200, 'message' => 'OK' ),
                    'body'     => json_encode( array( 'data' => array( 'id' => 77701 ) ) ),
                );
            }
            return $preempt;
        }, 10, 3 );

        $mk_update = function( $order_id, $chat_id, $from_id, $action = 'completed' ) {
            return array(
                'update_id'      => 30001,
                'callback_query' => array(
                    'id'      => 'cb_access_' . $from_id,
                    'from'    => array( 'id' => $from_id, 'is_bot' => false, 'first_name' => 'Тест', 'username' => 'test_manager' ),
                    'message' => array(
                        'message_id' => 9901,
                        'chat'       => array( 'id' => $chat_id, 'type' => 'supergroup' ),
                    ),
                    'data'    => "trisk_st:{$order_id}:{$action}",
                ),
            );
        };

        $order_id = 0;
        try {
            $sanitized = Trisk_CRM_Settings::sanitize_user_ids( ' 222, abc ,333;222 -5 0 ' );

            $order = wc_create_order();
            $order->set_billing_first_name( 'Іван' );
            $order->set_billing_last_name( 'Франко' );
            $order->set_status( 'processing' );
            $order->update_meta_data( '_trisk_tg_message_id', 9901 );
            $order->update_meta_data( '_trisk_tg_chat_id', '-1001987654321' );
            $order->update_meta_data( '_trisk_keycrm_order_id', 77701 );
            $order->save();
            $order_id = $order->get_id();
            as_unschedule_all_actions( 'trisk_crm_push_order_job', array( 'order_id' => $order_id, 'attempt' => 1 ), 'trisk-crm-sync' );

            $snapshot = function() use ( $order_id ) {
                $o = wc_get_order( $order_id );
                return array( 'status' => $o->get_status(), 'stage' => (string) $o->get_meta( '_trisk_crm_stage' ) );
            };

            // 1. Callback з чужого чату
            $res_foreign = trisk_crm_tg_handle_update( $mk_update( $order_id, -1009999999999, 111 ) );
            $after_foreign = $snapshot();
            $crm_after_foreign = count( $crm_calls );
            $answer_foreign = $tg_calls[0]['body']['text'] ?? '';
            $tg_paths_foreign = array_map( function( $c ) { return $c['path']; }, $tg_calls );

            // 2. Правильний чат, але список дозволених user ID заданий і менеджера в ньому немає
            $test_settings['tg_allowed_user_ids'] = '222, 333';
            trisk_crm_update_settings( $test_settings );
            $saved_allowed = trisk_crm_get_settings()['tg_allowed_user_ids'];
            $tg_calls = array();
            $res_unlisted = trisk_crm_tg_handle_update( $mk_update( $order_id, '-1001987654321', 111 ) );
            $after_unlisted = $snapshot();
            $crm_after_unlisted = count( $crm_calls );
            $answer_unlisted = $tg_calls[0]['body']['text'] ?? '';

            // 3. Менеджер зі списку в правильному чаті — зміна проходить, у CRM рівно один PUT, у чергу нічого не потрапляє
            $tg_calls = array();
            $res_allowed = trisk_crm_tg_handle_update( $mk_update( $order_id, '-1001987654321', 222 ) );
            $after_allowed = $snapshot();
            $put_calls = array_values( array_filter( $crm_calls, function( $c ) { return 'PUT' === $c['method']; } ) );
            $queued_push = as_has_scheduled_action( 'trisk_crm_push_order_job', array( 'order_id' => $order_id, 'attempt' => 1 ), 'trisk-crm-sync' );

            $logs = trisk_crm_log_query( array( 'direction' => 'in_tg', 'event' => 'tg.callback', 'entity_id' => (string) $order_id, 'limit' => 10 ) );
            $log_statuses = array_map( function( $l ) { return $l['status']; }, $logs );
        } finally {
            as_unschedule_all_actions( 'trisk_crm_push_order_job', array( 'order_id' => $order_id, 'attempt' => 1 ), 'trisk-crm-sync' );
            global $wpdb;
            $wpdb->delete( $wpdb->prefix . 'trisk_crm_logs', array( 'entity_id' => (string) $order_id ) );
            $o = wc_get_order( $order_id );
            if ( $o ) {
                $o->delete( true );
            }
            trisk_crm_update_settings( $orig_settings );
        }

        echo wp_json_encode( array(
            'sanitized'          => $sanitized,
            'saved_allowed'      => $saved_allowed,
            'res_foreign'        => $res_foreign,
            'after_foreign'      => $after_foreign,
            'crm_after_foreign'  => $crm_after_foreign,
            'answer_foreign'     => $answer_foreign,
            'tg_paths_foreign'   => $tg_paths_foreign,
            'res_unlisted'       => $res_unlisted,
            'after_unlisted'     => $after_unlisted,
            'crm_after_unlisted' => $crm_after_unlisted,
            'answer_unlisted'    => $answer_unlisted,
            'res_allowed'        => $res_allowed,
            'after_allowed'      => $after_allowed,
            'put_calls'          => $put_calls,
            'queued_push'        => $queued_push,
            'log_statuses'       => $log_statuses,
        ) );
    """))

    assert out["sanitized"] == "222,333"
    assert out["saved_allowed"] == "222,333"

    assert out["res_foreign"] == {"ok": True, "skipped": "forbidden"}
    assert out["after_foreign"] == {"status": "processing", "stage": ""}
    assert out["crm_after_foreign"] == 0
    assert out["answer_foreign"] == "Немає доступу"
    assert len(out["tg_paths_foreign"]) == 1 and "answerCallbackQuery" in out["tg_paths_foreign"][0]

    assert out["res_unlisted"] == {"ok": True, "skipped": "forbidden"}
    assert out["after_unlisted"] == {"status": "processing", "stage": ""}
    assert out["crm_after_unlisted"] == 0
    assert out["answer_unlisted"] == "Немає доступу"

    assert out["res_allowed"]["ok"] is True
    assert out["res_allowed"]["stage"] == "completed"
    assert out["after_allowed"] == {"status": "completed", "stage": "completed"}
    # Статус з Telegram іде в CRM напряму; повторно через чергу (woocommerce_order_status_changed) — ні
    assert len(out["put_calls"]) == 1
    assert out["put_calls"][0]["path"].endswith("/order/77701")
    assert out["queued_push"] is False
    assert out["log_statuses"].count("skipped") == 2
    assert out["log_statuses"].count("success") == 1


def test_enqueue_order_push_skipped_while_syncing_from_telegram_or_crm(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        $orig_settings = trisk_crm_get_settings();
        $test_settings = $orig_settings;
        $test_settings['enabled'] = true;
        $test_settings['api_key'] = 'test_enqueue_key';
        $test_settings['activated_at'] = '';
        trisk_crm_update_settings( $test_settings );

        $args = array();
        $order_id = 0;
        try {
            $order = wc_create_order();
            $order->set_status( 'processing' );
            $order->save();
            $order_id = $order->get_id();
            $args = array( 'order_id' => $order_id, 'attempt' => 1 );
            $has = function() use ( &$args ) {
                return as_has_scheduled_action( 'trisk_crm_push_order_job', $args, 'trisk-crm-sync' );
            };
            $clear = function() use ( &$args ) {
                as_unschedule_all_actions( 'trisk_crm_push_order_job', $args, 'trisk-crm-sync' );
            };

            $clear();
            Trisk_CRM_Sync::$syncing_from_tg = true;
            Trisk_CRM_Order_Sync::enqueue_order_push( $order_id );
            $queued_from_tg = $has();
            Trisk_CRM_Sync::$syncing_from_tg = false;

            Trisk_CRM_Sync::$syncing_from_crm = true;
            Trisk_CRM_Order_Sync::enqueue_order_push( $order_id );
            $queued_from_crm = $has();
            Trisk_CRM_Sync::$syncing_from_crm = false;

            Trisk_CRM_Order_Sync::enqueue_order_push( $order_id );
            $queued_normal = $has();
        } finally {
            Trisk_CRM_Sync::$syncing_from_tg = false;
            Trisk_CRM_Sync::$syncing_from_crm = false;
            if ( $args ) {
                as_unschedule_all_actions( 'trisk_crm_push_order_job', $args, 'trisk-crm-sync' );
            }
            $o = wc_get_order( $order_id );
            if ( $o ) {
                $o->delete( true );
            }
            trisk_crm_update_settings( $orig_settings );
        }

        echo wp_json_encode( array(
            'queued_from_tg'  => $queued_from_tg,
            'queued_from_crm' => $queued_from_crm,
            'queued_normal'   => $queued_normal,
        ) );
    """))

    assert out["queued_from_tg"] is False
    assert out["queued_from_crm"] is False
    assert out["queued_normal"] is True


def test_order_payload_sends_ordered_at_in_utc(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        $order = wc_create_order();
        $timestamp = 1760000000;
        $order->set_date_created( $timestamp );
        $order->save();

        $payload = Trisk_CRM_Order_Sync::build_order_payload( $order, trisk_crm_get_settings() );
        $order->delete( true );

        echo wp_json_encode( array(
            'ordered_at' => $payload['ordered_at'],
            'expected'   => gmdate( 'Y-m-d H:i:s', $timestamp ),
        ) );
    """))

    assert out["ordered_at"] == out["expected"]


def test_wc_to_crm_status_prefers_matching_keycrm_alias(wp_cli):
    out = json.loads(wp_cli("eval", r"""
        $orig_meta = get_option( 'trisk_crm_keycrm_meta', null );
        $map = array(
            '1' => 'on-hold', '2' => 'processing', '5' => 'processing',
            '8' => 'shipped', '9' => 'shipped',
            '12' => 'completed',
            '13' => 'cancelled', '14' => 'cancelled', '19' => 'cancelled',
        );
        $res = array();
        try {
            update_option( 'trisk_crm_keycrm_meta', array( 'statuses' => array(
                array( 'id' => 2, 'name' => 'presence_confirmed', 'alias' => 'presence_confirmed' ),
                array( 'id' => 5, 'name' => 'transferred_to_production', 'alias' => 'transferred_to_production' ),
                array( 'id' => 8, 'name' => 'delivered_to_delivery', 'alias' => 'delivered_to_delivery' ),
                array( 'id' => 9, 'name' => 'delivered', 'alias' => 'delivered' ),
                array( 'id' => 12, 'name' => 'completed', 'alias' => 'completed' ),
                array( 'id' => 13, 'name' => 'incorrect_data', 'alias' => 'incorrect_data' ),
                array( 'id' => 14, 'name' => 'underbid', 'alias' => 'underbid' ),
                array( 'id' => 19, 'name' => 'canceled', 'alias' => 'canceled' ),
            ) ), false );
            foreach ( array( 'cancelled', 'failed', 'refunded', 'completed', 'shipped', 'processing', 'on-hold' ) as $s ) {
                $res[ $s ] = Trisk_CRM_Order_Sync::map_wc_status_to_crm( $s, $map );
            }
            delete_option( 'trisk_crm_keycrm_meta' );
            $res['cancelled_without_meta'] = Trisk_CRM_Order_Sync::map_wc_status_to_crm( 'cancelled', $map );
            $res['clean_name'] = Trisk_CRM_Order_Sync::clean_item_name( 'Гуджі Ураґа<span> - </span>250 г, Зерно' );
        } finally {
            if ( null === $orig_meta ) {
                delete_option( 'trisk_crm_keycrm_meta' );
            } else {
                update_option( 'trisk_crm_keycrm_meta', $orig_meta, false );
            }
        }
        echo wp_json_encode( $res );
    """))

    assert out["cancelled"] == 19
    assert out["failed"] == 19
    assert out["refunded"] == 19
    assert out["completed"] == 12
    assert out["shipped"] == 8
    assert out["processing"] == 2
    assert out["on-hold"] == 1
    # Без підтягнутих статусів KeyCRM — перший збіг, як і раніше
    assert out["cancelled_without_meta"] == 13
    assert out["clean_name"] == "Гуджі Ураґа - 250 г, Зерно"
