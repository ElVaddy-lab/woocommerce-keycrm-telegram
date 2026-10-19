<?php
/**
 * Trisk CRM Logger
 */

defined( 'ABSPATH' ) || exit;

class Trisk_CRM_Logger {
	const TABLE_NAME = 'trisk_crm_logs';

	/**
	 * Get full table name with prefix.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Install or update database schema.
	 */
	public static function install_schema(): void {
		global $wpdb;
		$table           = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  direction VARCHAR(16) NOT NULL,
  event VARCHAR(64) NOT NULL,
  entity_type VARCHAR(32) NOT NULL,
  entity_id VARCHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL,
  http_code SMALLINT UNSIGNED DEFAULT 0,
  attempts SMALLINT UNSIGNED DEFAULT 1,
  payload LONGTEXT,
  response LONGTEXT,
  error_message TEXT,
  PRIMARY KEY  (id),
  KEY idx_status_created (status, created_at),
  KEY idx_entity (entity_type, entity_id),
  KEY idx_event (event)
) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Create log entry.
	 *
	 * @param array $data
	 * @return int
	 */
	public static function create( array $data ): int {
		global $wpdb;
		$table = self::get_table_name();
		$now   = current_time( 'mysql', true );

		$payload = isset( $data['payload'] )
			? ( is_scalar( $data['payload'] ) ? (string) $data['payload'] : wp_json_encode( $data['payload'] ) )
			: null;
		$response = isset( $data['response'] )
			? ( is_scalar( $data['response'] ) ? (string) $data['response'] : wp_json_encode( $data['response'] ) )
			: null;

		$row = array(
			'created_at'    => $data['created_at'] ?? $now,
			'updated_at'    => $data['updated_at'] ?? $now,
			'direction'     => sanitize_text_field( (string) ( $data['direction'] ?? 'out_crm' ) ),
			'event'         => sanitize_text_field( (string) ( $data['event'] ?? 'unknown' ) ),
			'entity_type'   => sanitize_text_field( (string) ( $data['entity_type'] ?? 'order' ) ),
			'entity_id'     => sanitize_text_field( (string) ( $data['entity_id'] ?? '' ) ),
			'status'        => sanitize_text_field( (string) ( $data['status'] ?? 'pending' ) ),
			'http_code'     => absint( $data['http_code'] ?? 0 ),
			'attempts'      => absint( $data['attempts'] ?? 1 ),
			'payload'       => $payload,
			'response'      => $response,
			'error_message' => isset( $data['error_message'] ) ? (string) $data['error_message'] : null,
		);

		$inserted = $wpdb->insert( $table, $row );
		if ( false === $inserted ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		self::prune( 500 );
		return $id;
	}

	/**
	 * Update log entry.
	 *
	 * @param int   $log_id
	 * @param array $data
	 */
	public static function update( int $log_id, array $data ): void {
		global $wpdb;
		$table  = self::get_table_name();
		$update = array(
			'updated_at' => current_time( 'mysql', true ),
		);

		if ( isset( $data['status'] ) ) {
			$update['status'] = sanitize_text_field( (string) $data['status'] );
		}
		if ( isset( $data['http_code'] ) ) {
			$update['http_code'] = absint( $data['http_code'] );
		}
		if ( isset( $data['attempts'] ) ) {
			$update['attempts'] = absint( $data['attempts'] );
		}
		if ( array_key_exists( 'payload', $data ) ) {
			if ( null === $data['payload'] ) {
				$update['payload'] = null;
			} else {
				$update['payload'] = is_scalar( $data['payload'] ) ? (string) $data['payload'] : wp_json_encode( $data['payload'] );
			}
		}
		if ( array_key_exists( 'response', $data ) ) {
			if ( null === $data['response'] ) {
				$update['response'] = null;
			} else {
				$update['response'] = is_scalar( $data['response'] ) ? (string) $data['response'] : wp_json_encode( $data['response'] );
			}
		}
		if ( array_key_exists( 'error_message', $data ) ) {
			$update['error_message'] = (string) $data['error_message'];
		}

		$wpdb->update( $table, $update, array( 'id' => $log_id ) );
	}

	/**
	 * Get single log entry by ID.
	 *
	 * @param int $log_id
	 * @return array|null
	 */
	public static function get( int $log_id ): ?array {
		global $wpdb;
		$table = self::get_table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $log_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return self::decode_row( $row );
	}

	/**
	 * Query log entries.
	 *
	 * @param array $args
	 * @return array
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$table = self::get_table_name();

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}
		if ( ! empty( $args['direction'] ) ) {
			$where[]  = 'direction = %s';
			$values[] = $args['direction'];
		}
		if ( ! empty( $args['event'] ) ) {
			$where[]  = 'event = %s';
			$values[] = $args['event'];
		}
		if ( ! empty( $args['entity_type'] ) ) {
			$where[]  = 'entity_type = %s';
			$values[] = $args['entity_type'];
		}
		if ( ! empty( $args['entity_id'] ) ) {
			$where[]  = 'entity_id = %s';
			$values[] = $args['entity_id'];
		}

		$where_clause = implode( ' AND ', $where );
		$limit        = absint( $args['limit'] ?? 50 );
		$offset       = absint( $args['offset'] ?? 0 );
		$order        = ( isset( $args['order'] ) && strtoupper( $args['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

		$sql      = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY id {$order} LIMIT %d OFFSET %d";
		$values[] = $limit;
		$values[] = $offset;

		$prepared = $wpdb->prepare( $sql, $values );
		$rows     = $wpdb->get_results( $prepared, ARRAY_A ) ?: array();

		return array_map( array( __CLASS__, 'decode_row' ), $rows );
	}

	/**
	 * Ring buffer: prune oldest entries keeping only newest $keep records.
	 *
	 * @param int $keep
	 * @return int Number of deleted rows
	 */
	public static function prune( int $keep = 500 ): int {
		if ( $keep < 1 ) {
			return 0;
		}

		global $wpdb;
		$table = self::get_table_name();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count <= $keep ) {
			return 0;
		}

		$boundary_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
			$keep - 1
		) );

		if ( $boundary_id ) {
			return (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} WHERE id < %d",
				$boundary_id
			) );
		}

		return 0;
	}

	/**
	 * Decode JSON fields in log row.
	 *
	 * @param array $row
	 * @return array
	 */
	public static function decode_row( array $row ): array {
		if ( isset( $row['payload'] ) && is_string( $row['payload'] ) ) {
			$decoded = json_decode( $row['payload'], true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				$row['payload'] = $decoded;
			}
		}
		if ( isset( $row['response'] ) && is_string( $row['response'] ) ) {
			$decoded = json_decode( $row['response'], true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				$row['response'] = $decoded;
			}
		}
		return $row;
	}
}
