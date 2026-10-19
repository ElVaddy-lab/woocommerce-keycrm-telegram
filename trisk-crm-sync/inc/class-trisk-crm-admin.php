<?php
/**
 * Trisk CRM Admin UI & Action Handlers
 */

defined( 'ABSPATH' ) || exit;

class Trisk_CRM_Admin {
	protected static bool $initialized = false;

	/**
	 * Initialize admin hooks and action handlers.
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );

		// Admin post action handlers
		add_action( 'admin_post_trisk_crm_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_trisk_crm_export_catalog', array( __CLASS__, 'handle_export_catalog' ) );
		add_action( 'admin_post_trisk_crm_sync_prices_now', array( __CLASS__, 'handle_sync_prices_now' ) );
		add_action( 'admin_post_trisk_crm_poll_tg', array( __CLASS__, 'handle_poll_tg' ) );
		add_action( 'admin_post_trisk_crm_fetch_keycrm_meta', array( __CLASS__, 'handle_fetch_keycrm_meta' ) );
		add_action( 'admin_post_trisk_crm_detect_tg_chat', array( __CLASS__, 'handle_detect_tg_chat' ) );
		add_action( 'admin_post_trisk_crm_register_tg_webhook', array( __CLASS__, 'handle_register_tg_webhook' ) );
		add_action( 'admin_post_trisk_crm_retry_log', array( __CLASS__, 'handle_retry_log' ) );
	}

	/**
	 * Register WooCommerce submenu page: WooCommerce -> Тріск CRM.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			'Тріск CRM',
			'Тріск CRM',
			'manage_woocommerce',
			'trisk-crm-sync',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render admin page.
	 */
	public static function render_page(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && function_exists( 'current_user_can' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Недостатньо прав для перегляду цієї сторінки.' );
		}

		$current_tab = isset( $_GET['tab'] ) && $_GET['tab'] === 'settings' ? 'settings' : 'status';
		$notice      = sanitize_text_field( (string) ( $_GET['notice'] ?? '' ) );

		?>
		<div class="wrap trisk-crm-admin-wrap">
			<h1 class="wp-heading-inline">Тріск CRM — Інтеграція з KeyCRM та Telegram</h1>
			<hr class="wp-header-end">

			<?php self::render_notices( $notice ); ?>

			<nav class="nav-tab-wrapper woo-nav-tab-wrapper" style="margin-bottom: 20px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=trisk-crm-sync&tab=status' ) ); ?>" class="nav-tab <?php echo 'status' === $current_tab ? 'nav-tab-active' : ''; ?>">
					Стан інтеграції
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=trisk-crm-sync&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $current_tab ? 'nav-tab-active' : ''; ?>">
					Налаштування
				</a>
			</nav>

			<?php
			if ( 'settings' === $current_tab ) {
				self::render_settings_tab();
			} else {
				self::render_status_tab();
			}
			?>
		</div>
		<style>
			.trisk-crm-cards {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
				gap: 16px;
				margin-bottom: 24px;
			}
			.trisk-crm-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 16px 20px;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
			}
			.trisk-crm-card h3 {
				margin-top: 0;
				margin-bottom: 12px;
				font-size: 15px;
				border-bottom: 1px solid #f0f0f1;
				padding-bottom: 8px;
			}
			.trisk-crm-badge {
				display: inline-block;
				padding: 3px 8px;
				border-radius: 3px;
				font-size: 12px;
				font-weight: 600;
				line-height: 1.2;
			}
			.badge-success { background: #d1e7dd; color: #0f5132; }
			.badge-warning { background: #fff3cd; color: #664d03; }
			.badge-danger  { background: #f8d7da; color: #842029; }
			.badge-neutral { background: #e2e3e5; color: #41464b; }
			.trisk-actions-bar {
				display: flex;
				flex-wrap: wrap;
				gap: 12px;
				align-items: center;
				margin-bottom: 24px;
				padding: 12px 16px;
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
			}
			.trisk-copy-box {
				background: #f6f7f7;
				padding: 8px 12px;
				border: 1px solid #c3c4c7;
				border-radius: 3px;
				font-family: monospace;
				word-break: break-all;
				display: inline-block;
				margin-top: 4px;
			}
		</style>
		<?php
	}

	/**
	 * Render admin notices.
	 *
	 * @param string $notice
	 */
	protected static function render_notices( string $notice ): void {
		if ( empty( $notice ) ) {
			return;
		}

		$messages = array(
			'saved'                 => array( 'success', 'Налаштування успішно збережено.' ),
			'export_done'           => array( 'success', 'Каталог успішно вивантажено в KeyCRM.' ),
			'prices_synced'         => array( 'success', 'Ціни успішно синхронізовано з KeyCRM.' ),
			'tg_polled'             => array( 'success', 'Telegram опитано (getUpdates). Зміни оброблено.' ),
			'meta_fetched'          => array( 'success', 'Метадані (джерела та статуси) успішно отримано з KeyCRM.' ),
			'meta_fetch_failed'     => array( 'error', 'Не вдалося отримати метадані з KeyCRM. Перевірте зʼєднання та API-ключ.' ),
			'tg_detected'           => array( 'success', 'Чат і тему Telegram успішно визначено та збережено.' ),
			'tg_detect_failed'      => array( 'error', 'Не вдалося визначити чат Telegram. Переконайтеся, що бот доданий у групу і в ній є повідомлення.' ),
			'tg_webhook_registered' => array( 'success', 'HTTPS-вебхук успішно зареєстровано в Telegram.' ),
			'tg_webhook_failed'     => array( 'error', 'Не вдалося зареєструвати HTTPS-вебхук у Telegram. Перевірте публічний HTTPS-домен та токен бота.' ),
			'retry_success'         => array( 'success', 'Запит успішно надіслано повторно.' ),
			'retry_failed'          => array( 'error', 'Помилка при повторній відправці запиту.' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			list( $type, $text ) = $messages[ $notice ];
			$class               = 'error' === $type ? 'notice-error' : 'notice-success';
			?>
			<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
				<p><strong><?php echo esc_html( $text ); ?></strong></p>
			</div>
			<?php
		}
	}

	/**
	 * Render Tab 1: «Стан інтеграції».
	 */
	public static function render_status_tab(): void {
		global $wpdb;

		$settings = trisk_crm_get_settings();

		// Статистика записів журналу
		$logs_table = Trisk_CRM_Logger::get_table_name();
		$counts     = array(
			'success'  => 0,
			'retrying' => 0,
			'error'    => 0,
			'pending'  => 0,
		);

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) ) === $logs_table ) {
			$res_counts = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM {$logs_table} GROUP BY status", ARRAY_A ) ?: array();
			foreach ( $res_counts as $r ) {
				$st = $r['status'] ?? '';
				if ( isset( $counts[ $st ] ) ) {
					$counts[ $st ] = (int) $r['cnt'];
				}
			}
		}

		// Активні завдання у Action Scheduler групи trisk-crm-sync
		$as_pending_count = 0;
		$as_table         = $wpdb->prefix . 'actionscheduler_actions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_table ) ) === $as_table ) {
			$as_group_table = $wpdb->prefix . 'actionscheduler_groups';
			$group_id       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$as_group_table} WHERE slug = %s", 'trisk-crm-sync' ) );
			if ( $group_id > 0 ) {
				$as_pending_count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$as_table} WHERE status = 'pending' AND group_id = %d",
						$group_id
					)
				);
			} else {
				$as_pending_count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$as_table} WHERE status = 'pending' AND hook LIKE %s",
						'%trisk_crm_%'
					)
				);
			}
		}

		// Прогрес експорту каталогу
		$export_state = get_option( 'trisk_crm_catalog_export_state', array() );
		$catalog_info = '18 товарів у каталозі';
		if ( ! empty( $export_state['total'] ) ) {
			$catalog_info = sprintf( 'Опрацьовано %d з %d товарів', (int) ( $export_state['processed'] ?? 0 ), (int) $export_state['total'] );
		}

		$keycrm_connected = trisk_crm_is_configured();
		$tg_connected     = trisk_crm_tg_is_configured();

		?>
		<h2>Стан інтеграції</h2>

		<div class="trisk-crm-cards">
			<!-- KeyCRM Card -->
			<div class="trisk-crm-card">
				<h3>KeyCRM</h3>
				<p>
					<strong>Статус:</strong>
					<?php if ( $keycrm_connected ) : ?>
						<span class="trisk-crm-badge badge-success">Увімкнено</span>
					<?php else : ?>
						<span class="trisk-crm-badge badge-neutral">Не налаштовано</span>
					<?php endif; ?>
				</p>
				<p><strong>ID джерела:</strong> <code><?php echo esc_html( $settings['source_id'] ); ?></code></p>
				<p><strong>ID складу:</strong> <code><?php echo esc_html( $settings['warehouse_id'] ); ?></code></p>
			</div>

			<!-- Telegram Card -->
			<div class="trisk-crm-card">
				<h3>Telegram</h3>
				<p>
					<strong>Статус:</strong>
					<?php if ( $tg_connected ) : ?>
						<span class="trisk-crm-badge badge-success">Увімкнено</span>
					<?php else : ?>
						<span class="trisk-crm-badge badge-neutral">Не налаштовано</span>
					<?php endif; ?>
				</p>
				<p><strong>Chat ID:</strong> <code><?php echo esc_html( $settings['tg_chat_id'] ?: '—' ); ?></code></p>
				<p><strong>Thread ID:</strong> <code><?php echo esc_html( $settings['tg_thread_id'] ?: '0 (Основна тема)' ); ?></code></p>
			</div>

			<!-- Statistics & Queue Card -->
			<div class="trisk-crm-card">
				<h3>Статистика подій / черга</h3>
				<p><strong>Успішно:</strong> <span class="trisk-crm-badge badge-success"><?php echo esc_html( $counts['success'] ); ?></span></p>
				<p><strong>У черзі / Повтор:</strong> <span class="trisk-crm-badge badge-warning"><?php echo esc_html( $counts['pending'] + $counts['retrying'] ); ?></span></p>
				<p><strong>Помилки:</strong> <span class="trisk-crm-badge <?php echo $counts['error'] > 0 ? 'badge-danger' : 'badge-neutral'; ?>"><?php echo esc_html( $counts['error'] ); ?></span></p>
				<p><strong>Завдань Action Scheduler:</strong> <code><?php echo esc_html( $as_pending_count ); ?></code></p>
			</div>

			<!-- Catalog Export Card -->
			<div class="trisk-crm-card">
				<h3>Експорт каталогу</h3>
				<p><strong>Прогрес:</strong> <?php echo esc_html( $catalog_info ); ?></p>
				<p>
					<strong>Стан залишків:</strong>
					<?php if ( ! empty( $export_state['stocks_pushed'] ) ) : ?>
						<span class="trisk-crm-badge badge-success">Залишки передано</span>
					<?php else : ?>
						<span class="trisk-crm-badge badge-neutral">Очікує синхронізації</span>
					<?php endif; ?>
				</p>
				<?php if ( ! empty( $export_state['updated_at'] ) ) : ?>
					<p><small>Оновлено: <?php echo esc_html( $export_state['updated_at'] ); ?></small></p>
				<?php endif; ?>
			</div>
		</div>

		<!-- Quick Actions Bar -->
		<div class="trisk-actions-bar">
			<strong>Швидкі дії:</strong>

			<!-- Action 1: Export Catalog -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin:0;">
				<input type="hidden" name="action" value="trisk_crm_export_catalog">
				<?php wp_nonce_field( 'trisk_crm_export_catalog' ); ?>
				<button type="submit" class="button button-primary">Вивантажити каталог (18 товарів)</button>
			</form>

			<!-- Action 2: Sync Prices -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin:0;">
				<input type="hidden" name="action" value="trisk_crm_sync_prices_now">
				<?php wp_nonce_field( 'trisk_crm_sync_prices_now' ); ?>
				<button type="submit" class="button">Синхронізувати ціни зараз</button>
			</form>

			<!-- Action 3: Poll Telegram -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin:0;">
				<input type="hidden" name="action" value="trisk_crm_poll_tg">
				<?php wp_nonce_field( 'trisk_crm_poll_tg' ); ?>
				<button type="submit" class="button">Опитати Telegram (getUpdates)</button>
			</form>
		</div>

		<!-- Log Table Section -->
		<h3>Журнал подій (wp_trisk_crm_logs)</h3>

		<?php
		$status_filter    = sanitize_text_field( (string) ( $_GET['status'] ?? '' ) );
		$direction_filter = sanitize_text_field( (string) ( $_GET['direction'] ?? '' ) );

		$query_args = array( 'limit' => 50 );
		if ( ! empty( $status_filter ) ) {
			$query_args['status'] = $status_filter;
		}
		if ( ! empty( $direction_filter ) ) {
			$query_args['direction'] = $direction_filter;
		}

		$logs = trisk_crm_log_query( $query_args );

		$statuses_labels = array(
			''         => 'Усі статуси',
			'success'  => 'Успішно',
			'retrying' => 'Повтор',
			'error'    => 'Помилки',
			'pending'  => 'Очікує',
			'skipped'  => 'Пропущено',
		);

		$directions_labels = array(
			''        => 'Усі напрямки',
			'out_crm' => 'Магазин → CRM',
			'in_crm'  => 'CRM → Магазин',
			'out_tg'  => 'Магазин → Telegram',
			'in_tg'   => 'Telegram → Магазин',
		);
		?>

		<ul class="subsubsub" style="margin-bottom: 12px;">
			<?php
			$links = array();
			foreach ( $statuses_labels as $st_key => $st_name ) {
				$is_current = ( $status_filter === $st_key );
				$url        = add_query_arg(
					array(
						'page'      => 'trisk-crm-sync',
						'tab'       => 'status',
						'status'    => $st_key ?: null,
						'direction' => $direction_filter ?: null,
					),
					admin_url( 'admin.php' )
				);
				$links[] = sprintf(
					'<li><a href="%s" class="%s">%s</a></li>',
					esc_url( $url ),
					$is_current ? 'current' : '',
					esc_html( $st_name )
				);
			}
			echo implode( ' | ', $links );
			?>
		</ul>
		<div class="clear"></div>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 50px;">ID</th>
					<th style="width: 140px;">Час (UTC)</th>
					<th style="width: 140px;">Напрямок</th>
					<th style="width: 150px;">Подія</th>
					<th style="width: 120px;">Об'єкт</th>
					<th style="width: 90px;">Статус</th>
					<th style="width: 60px;">HTTP</th>
					<th style="width: 60px;">Спроб</th>
					<th>Повідомлення / Деталі</th>
					<th style="width: 160px;">Дії</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $logs ) ) : ?>
					<tr>
						<td colspan="10" style="text-align: center; color: #646970; padding: 20px;">
							Записів у журналі поки що немає.
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $logs as $log ) : ?>
						<?php
						$status_val = (string) ( $log['status'] ?? 'pending' );
						$badge_cls  = 'badge-neutral';
						$badge_lbl  = 'Очікує';
						if ( 'success' === $status_val ) {
							$badge_cls = 'badge-success';
							$badge_lbl = 'Успішно';
						} elseif ( 'retrying' === $status_val ) {
							$badge_cls = 'badge-warning';
							$badge_lbl = 'Повтор';
						} elseif ( 'error' === $status_val ) {
							$badge_cls = 'badge-danger';
							$badge_lbl = 'Помилка';
						} elseif ( 'skipped' === $status_val ) {
							$badge_lbl = 'Пропущено';
						}

						$dir_val = (string) ( $log['direction'] ?? '' );
						$dir_lbl = $directions_labels[ $dir_val ] ?? $dir_val;

						$details_text = '';
						if ( ! empty( $log['error_message'] ) ) {
							$details_text = $log['error_message'];
						} elseif ( ! empty( $log['response'] ) ) {
							$details_text = is_array( $log['response'] )
								? wp_json_encode( $log['response'], JSON_UNESCAPED_UNICODE )
								: (string) $log['response'];
						}

						$is_retryable = in_array( $status_val, array( 'error', 'retrying', 'pending' ), true );
						?>
						<tr>
							<td>#<?php echo esc_html( $log['id'] ); ?></td>
							<td><small><?php echo esc_html( $log['created_at'] ); ?></small></td>
							<td><small><?php echo esc_html( $dir_lbl ); ?></small></td>
							<td><code><?php echo esc_html( $log['event'] ); ?></code></td>
							<td>
								<small><?php echo esc_html( $log['entity_type'] ); ?> #<?php echo esc_html( $log['entity_id'] ); ?></small>
							</td>
							<td>
								<span class="trisk-crm-badge <?php echo esc_attr( $badge_cls ); ?>">
									<?php echo esc_html( $badge_lbl ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $log['http_code'] ?: '—' ); ?></td>
							<td><?php echo esc_html( $log['attempts'] ); ?></td>
							<td>
								<div style="max-height: 60px; overflow-y: auto; font-size: 12px; font-family: monospace;">
									<?php echo esc_html( $details_text ?: '—' ); ?>
								</div>
							</td>
							<td>
								<?php if ( $is_retryable ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin:0;">
										<input type="hidden" name="action" value="trisk_crm_retry_log">
										<input type="hidden" name="log_id" value="<?php echo esc_attr( $log['id'] ); ?>">
										<?php wp_nonce_field( 'trisk_crm_retry_log_' . $log['id'] ); ?>
										<button type="submit" class="button button-small button-secondary">Надіслати повторно</button>
									</form>
								<?php else : ?>
									<span style="color:#a7aaad;">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render Tab 2: «Налаштування».
	 */
	public static function render_settings_tab(): void {
		$settings = trisk_crm_get_settings();

		$keycrm_webhook_url = rest_url( 'trisk-crm/v1/webhook/keycrm' ) . '?token=' . rawurlencode( $settings['webhook_secret'] );
		$tg_webhook_url     = rest_url( 'trisk-crm/v1/webhook/telegram' );

		// Метадані статусів з KeyCRM, якщо раніше підтягнуті
		$meta         = get_option( 'trisk_crm_keycrm_meta', array() );
		$crm_statuses = $meta['statuses'] ?? array();

		$default_crm_statuses = array(
			'1' => 'Новий (Lead / New)',
			'2' => 'В роботі (Processing)',
			'3' => 'Відправлено (Shipped)',
			'4' => 'Виконано (Completed)',
			'5' => 'Скасовано (Cancelled)',
		);

		if ( ! empty( $crm_statuses ) && is_array( $crm_statuses ) ) {
			$default_crm_statuses = array();
			foreach ( $crm_statuses as $st ) {
				if ( isset( $st['id'], $st['name'] ) ) {
					$default_crm_statuses[ (string) $st['id'] ] = $st['name'];
				}
			}
		}

		$status_map = $settings['status_map'] ?? array();

		$wc_statuses = array(
			'on-hold'    => 'Очікує оплати (on-hold)',
			'processing' => 'В обробці (processing)',
			'shipped'    => 'Відправлено (shipped / processing)',
			'completed'  => 'Виконано (completed)',
			'cancelled'  => 'Скасовано (cancelled)',
		);

		?>
		<h2>Налаштування</h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="trisk_crm_save_settings">
			<?php wp_nonce_field( 'trisk_crm_save_settings' ); ?>

			<!-- SECTION 1: KeyCRM -->
			<div class="trisk-crm-card" style="margin-bottom: 24px;">
				<h3>1. Інтеграція з KeyCRM</h3>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">Активність</th>
							<td>
								<label for="trisk_crm_enabled">
									<input name="trisk_crm_settings[enabled]" type="checkbox" id="trisk_crm_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
									Увімкнути синхронізацію з KeyCRM
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="trisk_crm_api_key">API-ключ KeyCRM</label></th>
							<td>
								<input name="trisk_crm_settings[api_key]" type="password" id="trisk_crm_api_key" value="<?php echo esc_attr( $settings['api_key'] ? '••••••••' : '' ); ?>" class="regular-text" autocomplete="new-password">
								<p class="description">Знайдіть API-ключ у KeyCRM: <em>Налаштування → Основні → API</em>. Залиште порожнім, щоб не змінювати збережений ключ.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="trisk_crm_source_id">ID джерела (source_id)</label></th>
							<td>
								<input name="trisk_crm_settings[source_id]" type="number" id="trisk_crm_source_id" value="<?php echo esc_attr( $settings['source_id'] ); ?>" class="small-text">
								<p class="description">ID джерела замовлень у KeyCRM (за замовчуванням 1).</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="trisk_crm_warehouse_id">ID складу (warehouse_id)</label></th>
							<td>
								<input name="trisk_crm_settings[warehouse_id]" type="number" id="trisk_crm_warehouse_id" value="<?php echo esc_attr( $settings['warehouse_id'] ); ?>" class="small-text">
								<p class="description">ID складу в KeyCRM для синхронізації залишків (за замовчуванням 1).</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="trisk_crm_webhook_secret">Секретний токен вебхука KeyCRM</label></th>
							<td>
								<input name="trisk_crm_settings[webhook_secret]" type="text" id="trisk_crm_webhook_secret" value="<?php echo esc_attr( $settings['webhook_secret'] ); ?>" class="regular-text">
								<p class="description">Використовується для перевірки автентичності вхідних запитів від KeyCRM.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">URL вхідного вебхука KeyCRM</th>
							<td>
								<div class="trisk-copy-box"><?php echo esc_html( $keycrm_webhook_url ); ?></div>
								<p class="description">Скопіюйте цей URL у KeyCRM: <em>Налаштування → Додатково → Вебхуки</em> для подій «Зміна статусу замовлення» та «Зміна залишків».</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Відповідність статусів KeyCRM ↔ WooCommerce</th>
							<td>
								<table class="widefat fixed striped" style="max-width: 650px;">
									<thead>
										<tr>
											<th style="width: 140px;">ID статусу в CRM</th>
											<th>Назва в CRM</th>
											<th>Статус WooCommerce</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $default_crm_statuses as $crm_st_id => $crm_st_name ) : ?>
											<?php $selected_wc = $status_map[ $crm_st_id ] ?? 'processing'; ?>
											<tr>
												<td><code><?php echo esc_html( $crm_st_id ); ?></code></td>
												<td><?php echo esc_html( $crm_st_name ); ?></td>
												<td>
													<select name="trisk_crm_settings[status_map][<?php echo esc_attr( $crm_st_id ); ?>]">
														<?php foreach ( $wc_statuses as $wc_slug => $wc_label ) : ?>
															<option value="<?php echo esc_attr( $wc_slug ); ?>" <?php selected( $selected_wc, $wc_slug ); ?>>
																<?php echo esc_html( $wc_label ); ?>
															</option>
														<?php endforeach; ?>
													</select>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- SECTION 2: Telegram Bot -->
			<div class="trisk-crm-card" style="margin-bottom: 24px;">
				<h3>2. Telegram-бот для менеджерів</h3>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">Активність</th>
							<td>
								<label for="trisk_crm_tg_enabled">
									<input name="trisk_crm_settings[tg_enabled]" type="checkbox" id="trisk_crm_tg_enabled" value="1" <?php checked( ! empty( $settings['tg_enabled'] ) ); ?>>
									Увімкнути Telegram-бота для менеджерів
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="trisk_crm_tg_bot_token">Токен Telegram-бота</label></th>
							<td>
								<input name="trisk_crm_settings[tg_bot_token]" type="password" id="trisk_crm_tg_bot_token" value="<?php echo esc_attr( $settings['tg_bot_token'] ? '••••••••' : '' ); ?>" class="regular-text" autocomplete="new-password">
								<p class="description">Токен бота від @BotFather (наприклад: <code>123456:ABC-DEF1234...</code>). Залиште порожнім, щоб не змінювати.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="trisk_crm_tg_chat_id">ID групи / каналу (Chat ID)</label></th>
							<td>
								<input name="trisk_crm_settings[tg_chat_id]" type="text" id="trisk_crm_tg_chat_id" value="<?php echo esc_attr( $settings['tg_chat_id'] ); ?>" class="regular-text">
								<p class="description">ID супергрупи менеджерів (наприклад: <code>-1001234567890</code>). Можна визначити кнопкою нижче.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="trisk_crm_tg_thread_id">ID теми (Message Thread ID)</label></th>
							<td>
								<input name="trisk_crm_settings[tg_thread_id]" type="number" id="trisk_crm_tg_thread_id" value="<?php echo esc_attr( $settings['tg_thread_id'] ); ?>" class="small-text">
								<p class="description">ID теми «Замовлення» у супергрупі (0 — якщо теми не використовуються).</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="trisk_crm_tg_allowed_user_ids">Дозволені Telegram user ID</label></th>
							<td>
								<input name="trisk_crm_settings[tg_allowed_user_ids]" type="text" id="trisk_crm_tg_allowed_user_ids" value="<?php echo esc_attr( $settings['tg_allowed_user_ids'] ?? '' ); ?>" class="regular-text" placeholder="123456789, 987654321">
								<p class="description">Необов'язково. ID менеджерів через кому, яким дозволено натискати кнопки статусу. Якщо порожньо — кнопки працюють для всіх учасників налаштованої групи.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="trisk_crm_tg_webhook_secret">Секретний токен вебхука Telegram</label></th>
							<td>
								<input name="trisk_crm_settings[tg_webhook_secret]" type="text" id="trisk_crm_tg_webhook_secret" value="<?php echo esc_attr( $settings['tg_webhook_secret'] ); ?>" class="regular-text">
								<p class="description">Передається в заголовку <code>X-Telegram-Bot-Api-Secret-Token</code> при реєстрації вебхука.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">URL вхідного вебхука Telegram</th>
							<td>
								<div class="trisk-copy-box"><?php echo esc_html( $tg_webhook_url ); ?></div>
								<p class="description">Адреса для обробки натискань інлайн-кнопок менеджерів через HTTPS-вебхук.</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary button-large">Зберегти налаштування</button>
			</p>
		</form>

		<hr style="margin: 30px 0;">

		<!-- Helper Action Buttons Forms -->
		<h3>Допоміжні інструменти налаштування</h3>
		<div style="display: flex; flex-wrap: wrap; gap: 16px; margin-top: 12px;">
			<!-- Button: Fetch KeyCRM Meta -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="trisk_crm_fetch_keycrm_meta">
				<?php wp_nonce_field( 'trisk_crm_fetch_keycrm_meta' ); ?>
				<button type="submit" class="button">
					Підтягнути джерела й статуси з KeyCRM
				</button>
			</form>

			<!-- Button: Detect Telegram Chat & Thread -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="trisk_crm_detect_tg_chat">
				<?php wp_nonce_field( 'trisk_crm_detect_tg_chat' ); ?>
				<button type="submit" class="button">
					Визначити ID групи й теми автоматично
				</button>
			</form>

			<!-- Button: Register Telegram Webhook -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="trisk_crm_register_tg_webhook">
				<?php wp_nonce_field( 'trisk_crm_register_tg_webhook' ); ?>
				<button type="submit" class="button">
					Зареєструвати HTTPS-вебхук у Telegram
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Action: Retry failed log entry.
	 */
	public static function handle_retry_log(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Недостатньо прав для виконання цієї дії.' );
		}

		$log_id = absint( $_REQUEST['log_id'] ?? 0 );
		check_admin_referer( 'trisk_crm_retry_log_' . $log_id );

		$ok     = trisk_crm_retry_log_entry( $log_id );
		$notice = $ok ? 'retry_success' : 'retry_failed';

		wp_safe_redirect( add_query_arg(
			array(
				'page'   => 'trisk-crm-sync',
				'tab'    => 'status',
				'notice' => $notice,
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Action: Save settings form.
	 */
	public static function handle_save_settings(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Недостатньо прав для виконання цієї дії.' );
		}

		check_admin_referer( 'trisk_crm_save_settings' );

		$raw      = $_POST['trisk_crm_settings'] ?? array();
		$existing = trisk_crm_get_settings();

		$clean = array(
			'enabled'             => ! empty( $raw['enabled'] ),
			'tg_enabled'          => ! empty( $raw['tg_enabled'] ),
			'source_id'           => absint( $raw['source_id'] ?? $existing['source_id'] ),
			'warehouse_id'        => absint( $raw['warehouse_id'] ?? $existing['warehouse_id'] ),
			'tg_thread_id'        => absint( $raw['tg_thread_id'] ?? 0 ),
			'tg_chat_id'          => sanitize_text_field( (string) ( $raw['tg_chat_id'] ?? '' ) ),
			'tg_allowed_user_ids' => Trisk_CRM_Settings::sanitize_user_ids( $raw['tg_allowed_user_ids'] ?? '' ),
		);

		// KeyCRM API Key
		$new_api_key = trim( (string) ( $raw['api_key'] ?? '' ) );
		if ( '' === $new_api_key || strpos( $new_api_key, '••••' ) !== false ) {
			$clean['api_key'] = $existing['api_key'];
		} else {
			$clean['api_key'] = sanitize_text_field( $new_api_key );
		}

		// Telegram Bot Token
		$new_bot_token = trim( (string) ( $raw['tg_bot_token'] ?? '' ) );
		if ( '' === $new_bot_token || strpos( $new_bot_token, '••••' ) !== false ) {
			$clean['tg_bot_token'] = $existing['tg_bot_token'];
		} else {
			$clean['tg_bot_token'] = sanitize_text_field( $new_bot_token );
		}

		// Secrets
		if ( ! empty( $raw['webhook_secret'] ) ) {
			$clean['webhook_secret'] = sanitize_text_field( (string) $raw['webhook_secret'] );
		}
		if ( ! empty( $raw['tg_webhook_secret'] ) ) {
			$clean['tg_webhook_secret'] = sanitize_text_field( (string) $raw['tg_webhook_secret'] );
		}

		// Status map
		if ( isset( $raw['status_map'] ) && is_array( $raw['status_map'] ) ) {
			$map = array();
			foreach ( $raw['status_map'] as $crm_id => $wc_slug ) {
				$map[ sanitize_text_field( (string) $crm_id ) ] = sanitize_text_field( (string) $wc_slug );
			}
			$clean['status_map'] = $map;
		}

		trisk_crm_update_settings( $clean );

		wp_safe_redirect( add_query_arg(
			array(
				'page'   => 'trisk-crm-sync',
				'tab'    => 'settings',
				'notice' => 'saved',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Action: Export curated catalog (18 products) to KeyCRM.
	 */
	public static function handle_export_catalog(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Недостатньо прав для виконання цієї дії.' );
		}

		check_admin_referer( 'trisk_crm_export_catalog' );

		if ( function_exists( 'trisk_crm_ensure_variation_skus' ) ) {
			trisk_crm_ensure_variation_skus();
		}

		if ( function_exists( 'trisk_crm_export_catalog_batch' ) ) {
			$offset      = 0;
			$done        = false;
			$max_batches = 10;
			$b           = 0;
			while ( ! $done && $b < $max_batches ) {
				$state  = trisk_crm_export_catalog_batch( $offset, 6 );
				$done   = ! empty( $state['done'] );
				$offset = (int) ( $state['next_offset'] ?? 0 );
				$b++;
			}
		}

		wp_safe_redirect( add_query_arg(
			array(
				'page'   => 'trisk-crm-sync',
				'tab'    => 'status',
				'notice' => 'export_done',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Action: Synchronize regular and sale prices now.
	 */
	public static function handle_sync_prices_now(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Недостатньо прав для виконання цієї дії.' );
		}

		check_admin_referer( 'trisk_crm_sync_prices_now' );

		if ( function_exists( 'trisk_crm_pull_prices' ) ) {
			trisk_crm_pull_prices();
		}

		wp_safe_redirect( add_query_arg(
			array(
				'page'   => 'trisk-crm-sync',
				'tab'    => 'status',
				'notice' => 'prices_synced',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Action: Poll Telegram getUpdates.
	 */
	public static function handle_poll_tg(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Недостатньо прав для виконання цієї дії.' );
		}

		check_admin_referer( 'trisk_crm_poll_tg' );

		if ( function_exists( 'trisk_crm_tg_poll_callbacks' ) ) {
			trisk_crm_tg_poll_callbacks();
		}

		wp_safe_redirect( add_query_arg(
			array(
				'page'   => 'trisk-crm-sync',
				'tab'    => 'status',
				'notice' => 'tg_polled',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Action: Fetch metadata (statuses and sources) from KeyCRM.
	 */
	public static function handle_fetch_keycrm_meta(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Недостатньо прав для виконання цієї дії.' );
		}

		check_admin_referer( 'trisk_crm_fetch_keycrm_meta' );

		// Без limit KeyCRM віддає лише першу сторінку (15 записів), а стандартних статусів 19.
		$res_statuses = trisk_crm_api_request( 'GET', '/order/status?limit=50' );
		$res_sources  = trisk_crm_api_request( 'GET', '/order/source?limit=50' );

		if ( $res_statuses['ok'] || $res_sources['ok'] ) {
			$meta = array(
				'statuses'   => $res_statuses['data']['data'] ?? ( $res_statuses['data'] ?? array() ),
				'sources'    => $res_sources['data']['data'] ?? ( $res_sources['data'] ?? array() ),
				'updated_at' => current_time( 'mysql' ),
			);
			update_option( 'trisk_crm_keycrm_meta', $meta, false );
			$notice = 'meta_fetched';
		} else {
			$notice = 'meta_fetch_failed';
		}

		wp_safe_redirect( add_query_arg(
			array(
				'page'   => 'trisk-crm-sync',
				'tab'    => 'settings',
				'notice' => $notice,
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Action: Detect Telegram supergroup chat ID and topic thread ID from recent updates.
	 */
	public static function handle_detect_tg_chat(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Недостатньо прав для виконання цієї дії.' );
		}

		check_admin_referer( 'trisk_crm_detect_tg_chat' );

		$res = trisk_crm_tg_detect_chat_and_topic();
		if ( ! empty( $res['ok'] ) && ! empty( $res['chat_id'] ) ) {
			$settings                 = trisk_crm_get_settings();
			$settings['tg_chat_id']   = (string) $res['chat_id'];
			$settings['tg_thread_id'] = absint( $res['thread_id'] ?? 0 );
			trisk_crm_update_settings( $settings );
			$notice = 'tg_detected';
		} else {
			$notice = 'tg_detect_failed';
		}

		wp_safe_redirect( add_query_arg(
			array(
				'page'   => 'trisk-crm-sync',
				'tab'    => 'settings',
				'notice' => $notice,
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Action: Register Telegram HTTPS webhook URL.
	 */
	public static function handle_register_tg_webhook(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Недостатньо прав для виконання цієї дії.' );
		}

		check_admin_referer( 'trisk_crm_register_tg_webhook' );

		$settings = trisk_crm_get_settings();
		$url      = rest_url( 'trisk-crm/v1/webhook/telegram' );
		$secret   = (string) ( $settings['tg_webhook_secret'] ?? '' );

		$params = array( 'url' => $url );
		if ( ! empty( $secret ) ) {
			$params['secret_token'] = $secret;
		}

		$res    = trisk_crm_tg_api_request( 'setWebhook', $params );
		$notice = ( ! empty( $res['ok'] ) ) ? 'tg_webhook_registered' : 'tg_webhook_failed';

		wp_safe_redirect( add_query_arg(
			array(
				'page'   => 'trisk-crm-sync',
				'tab'    => 'settings',
				'notice' => $notice,
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
