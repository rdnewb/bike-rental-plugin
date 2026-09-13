<?php
/** Two-table storage and non-destructive, per-site schema installation. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class Database {

	const VERSION = '1';
	const OPTION = 'brp_db_version';
	const ERROR = 'brp_db_error';
	private static $token = null;
	private static $now = null;

	public static function table( $kind ) {
		global $wpdb;
		if ( ! in_array( $kind, array( 'reservations', 'availability' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown rental table.' );
		}
		return $wpdb->prefix . 'brp_' . $kind;
	}

	/** dbDelta requires its particular key/line formatting. No destructive DDL. */
	public static function definitions() {
		global $wpdb;
		$r = $wpdb->prepare( '%i', self::table( 'reservations' ) );
		$a = $wpdb->prepare( '%i', self::table( 'availability' ) );
		$collate = $wpdb->get_charset_collate();
		return array(
			"CREATE TABLE $r (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
reference varchar(40) NOT NULL,
order_id bigint(20) unsigned DEFAULT NULL,
order_item_id bigint(20) unsigned DEFAULT NULL,
package_product_id bigint(20) unsigned NOT NULL,
quantity int(10) unsigned NOT NULL,
start_utc datetime NOT NULL,
end_utc datetime NOT NULL,
occupied_start_utc datetime NOT NULL,
occupied_end_utc datetime NOT NULL,
timezone varchar(64) NOT NULL,
status varchar(20) NOT NULL,
hold_expires_at datetime DEFAULT NULL,
request_key varchar(64) DEFAULT NULL,
request_hash varchar(64) DEFAULT NULL,
session_hash varchar(64) DEFAULT NULL,
snapshot longtext NOT NULL,
revision bigint(20) unsigned NOT NULL DEFAULT 1,
issue_code varchar(64) DEFAULT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY reference (reference),
UNIQUE KEY request_key (request_key),
KEY status_interval (status,occupied_start_utc,occupied_end_utc),
KEY occupied_end (occupied_end_utc),
KEY hold_expiry (status,hold_expires_at),
KEY order_id (order_id),
KEY order_item_id (order_item_id)
) ENGINE=InnoDB $collate;",
			"CREATE TABLE $a (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
record_type varchar(20) NOT NULL,
quantity int(10) unsigned NOT NULL,
start_utc datetime DEFAULT NULL,
end_utc datetime DEFAULT NULL,
reason varchar(240) NOT NULL DEFAULT '',
active tinyint(1) unsigned NOT NULL DEFAULT 1,
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY type_interval (record_type,active,start_utc,end_utc),
KEY block_end (end_utc)
) ENGINE=InnoDB $collate;",
		);
	}

	/** Runs on normal init, including the first request after SFTP replacement. */
	public static function install() {
		global $wpdb;
		$version = (string) get_option( self::OPTION, '' );
		if ( self::VERSION === $version && ! get_option( self::ERROR, '' ) ) {
			return;
		}
		if ( '' !== $version && version_compare( $version, self::VERSION, '>' ) ) {
			self::fail( 'The database schema is newer than this plugin. Restore a compatible plugin version.' );
			return;
		}
		// Serialize DDL/seed across simultaneous first requests; DDL implicitly commits.
		$lock = 'brp_schema_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix ), 0, 40 );
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
		if ( '1' !== (string) $acquired ) {
			self::fail( 'Schema installation could not acquire its database lock. Reload after other requests finish; ask the host to enable MySQL named locks if this continues.' );
			return;
		}
		$old_suppression = $wpdb->suppress_errors( true );
		try {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			foreach ( self::definitions() as $sql ) {
				dbDelta( $sql );
			}
			if ( ! self::verify_schema() ) {
				self::fail( 'Rental tables or indexes could not be verified as InnoDB. Check database CREATE/ALTER/INDEX permissions and the database error log, then reload. Existing data has been retained.' );
				return;
			}
			$now = gmdate( 'Y-m-d H:i:s' );
			// Reserved primary key 1 is the only capacity row; never update its quantity.
			$seed = $wpdb->query( $wpdb->prepare(
				'INSERT INTO %i (id,record_type,quantity,active,created_by,created_at,updated_at) VALUES (1,%s,10,1,0,%s,%s) ON DUPLICATE KEY UPDATE id=id',
				self::table( 'availability' ), 'capacity', $now, $now
			) );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table( 'availability' ), 1 ), ARRAY_A );
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE record_type = %s', self::table( 'availability' ), 'capacity' ) );
			if ( false === $seed || ! $row || 'capacity' !== $row['record_type'] || ! self::positive( $row['quantity'] ) || '1' !== (string) $count || '1' !== (string) $row['active'] ) {
				self::fail( 'The fleet capacity row could not be initialized or is inconsistent. Ask the site administrator to inspect rental availability row 1 and duplicate capacity rows; no existing row has been replaced.' );
				return;
			}
			update_option( self::OPTION, self::VERSION, false );
			delete_option( self::ERROR );
		} finally {
			$wpdb->suppress_errors( $old_suppression );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function verify_schema() {
		global $wpdb;
		foreach ( self::definitions() as $i => $sql ) {
			$table = self::table( 0 === $i ? 'reservations' : 'availability' );
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( 'innodb' !== strtolower( (string) $engine ) ) {
				return false;
			}
			$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
			preg_match_all( '/^([a-z_]+) (?:bigint|int|varchar|datetime|longtext|tinyint)/m', $sql, $matches );
			if ( array_diff( $matches[1], $columns ) ) {
				return false;
			}
			$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
			$actual = array();
			foreach ( $indexes as $index ) {
				$actual[ $index['Key_name'] ]['columns'][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
				$actual[ $index['Key_name'] ]['unique'] = 0 === (int) $index['Non_unique'];
			}
			preg_match_all( '/^(PRIMARY KEY|UNIQUE KEY [a-z_]+|KEY [a-z_]+) +\(([^)]+)\)/m', $sql, $keys, PREG_SET_ORDER );
			foreach ( $keys as $key ) {
				$name = 'PRIMARY KEY' === $key[1] ? 'PRIMARY' : substr( $key[1], strrpos( $key[1], ' ' ) + 1 );
				if ( ! isset( $actual[ $name ] ) ) { return false; }
				ksort( $actual[ $name ]['columns'] );
				if ( explode( ',', $key[2] ) !== array_values( $actual[ $name ]['columns'] ) || ( ! str_starts_with( $key[1], 'KEY ' ) && ! $actual[ $name ]['unique'] ) ) { return false; }
			}
		}
		return true;
	}

	private static function fail( $message ) { update_option( self::ERROR, $message, false ); }

	public static function notice() {
		if ( Settings::can_manage() && get_option( self::ERROR, '' ) ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Bike Rentals database:', 'bike-rental-plugin' ) . '</strong> ' . esc_html( get_option( self::ERROR ) ) . '</p></div>';
		}
	}

	public static function gate( $cleanup = false ) {
		if ( ! Settings::can_manage() && ! ( $cleanup && doing_action( 'brp_expire_holds' ) ) ) { return self::error( 'permission', 'You do not have permission to manage rental data.' ); }
		if ( self::VERSION !== (string) get_option( self::OPTION, '' ) || get_option( self::ERROR, '' ) ) {
			return self::error( 'schema', 'Rental storage is unavailable. Reload administration and resolve the database notice before saving.' );
		}
		return true;
	}

	public static function error( $code, $message ) { return new \WP_Error( 'brp_' . $code, $message ); }
	public static function positive( $value ) {
		return ( is_int( $value ) || is_string( $value ) ) && preg_match( '/^[1-9][0-9]{0,9}$/D', (string) $value ) && (float) $value <= 2147483647;
	}

	/** One attempt, one connection, one shared row lock. Never nest transactions. */
	public static function locked( $callback, $cleanup = false ) {
		global $wpdb;
		$gate = self::gate( $cleanup );
		if ( is_wp_error( $gate ) ) { return $gate; }
		if ( null !== self::$token ) { return self::error( 'transaction', 'Nested rental transactions are not supported.' ); }
		$committed = false;
		try {
			self::$token = bin2hex( random_bytes( 16 ) );
			if ( false === $wpdb->query( $wpdb->prepare( 'SET @brp_inventory_token = %s', self::$token ) ) ) { return self::retry_error(); }
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::retry_error(); }
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', self::table( 'availability' ), 1 ), ARRAY_A );
			if ( $wpdb->last_error || ! self::connection_valid() ) { return self::retry_error(); }
			if ( ! $row || 'capacity' !== $row['record_type'] || ! self::positive( $row['quantity'] ) || 1 !== (int) $row['active'] ) {
				return self::error( 'capacity', 'Fleet capacity is missing or invalid. Resolve the database setup before saving.' );
			}
			self::$now = $wpdb->get_var( 'SELECT UTC_TIMESTAMP()' );
			if ( ! self::$now || $wpdb->last_error ) { return self::retry_error(); }
			$result = $callback( (int) $row['quantity'] );
			if ( is_wp_error( $result ) ) { return $result; }
			if ( ! self::connection_valid() || false === $wpdb->query( 'COMMIT' ) || ! self::connection_valid() ) { return self::retry_error(); }
			$committed = true;
			return $result;
		} finally {
			if ( ! $committed && false === $wpdb->query( 'ROLLBACK' ) ) { $wpdb->close(); }
			self::$token = null;
			self::$now = null;
		}
	}

	public static function retry_error() { return new \WP_Error( 'brp_retry', 'Rental storage was busy or the transaction failed. Reload and retry; verify the current record before resubmitting.', array( 'retryable' => true ) ); }
	public static function in_transaction() { return null !== self::$token && null !== self::$now; }
	public static function now() { return self::$now; }
	private static function connection_valid() {
		global $wpdb;
		return null !== self::$token && self::$token === $wpdb->get_var( 'SELECT @brp_inventory_token' );
	}

	/** Guard the SQL itself against wpdb transparently reconnecting after a lost lock. */
	public static function insert( $kind, $data ) {
		global $wpdb;
		if ( ! self::in_transaction() ) { return false; }
		$columns = array(); $values = array();
		foreach ( $data as $key => $value ) {
			$columns[] = $wpdb->prepare( '%i', $key );
			$values[] = null === $value ? 'NULL' : $wpdb->prepare( '%s', $value );
		}
		$sql = $wpdb->prepare( 'INSERT INTO %i ', self::table( $kind ) ) . '(' . implode( ',', $columns ) . ') SELECT ' . implode( ',', $values ) . $wpdb->prepare( ' WHERE @brp_inventory_token = %s', self::$token );
		$result = $wpdb->query( $sql );
		$id = $wpdb->insert_id;
		$valid = self::connection_valid();
		$wpdb->insert_id = $id;
		return 1 === $result && $valid ? 1 : false;
	}

	public static function update( $kind, $data, $where ) {
		global $wpdb;
		if ( ! self::in_transaction() ) { return false; }
		$set = array(); $conditions = array();
		foreach ( $data as $key => $value ) { $set[] = $wpdb->prepare( '%i = ', $key ) . ( null === $value ? 'NULL' : $wpdb->prepare( '%s', $value ) ); }
		foreach ( $where as $key => $value ) { $conditions[] = $wpdb->prepare( '%i = %s', $key, $value ); }
		$conditions[] = $wpdb->prepare( '@brp_inventory_token = %s', self::$token );
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET ', self::table( $kind ) ) . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $conditions ) );
		return self::connection_valid() ? $result : false;
	}

	public static function read( $kind, $id ) {
		global $wpdb;
		$gate = self::gate();
		if ( is_wp_error( $gate ) ) { return $gate; }
		if ( ! self::positive( $id ) ) { return self::error( 'id', 'Invalid record ID.' ); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table( $kind ), $id ) . ( self::in_transaction() ? ' FOR UPDATE' : '' ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::retry_error(); }
		return $row ?: self::error( 'record', 'Record unavailable. Check the ID and database connection.' );
	}

	public static function listing( $kind, $page = 1 ) {
		global $wpdb;
		$gate = self::gate();
		if ( is_wp_error( $gate ) ) { return $gate; }
		if ( ! self::positive( $page ) || (int) $page > 1000000 ) { return self::error( 'page', 'Invalid page number.' ); }
		$type = 'availability' === $kind ? 'block' : '';
		$where = $type ? $wpdb->prepare( ' WHERE record_type = %s', $type ) : '';
		// The only SQL fragment is generated above from a fixed, prepared predicate.
		$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table( $kind ) ) . $where );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', self::table( $kind ) ) . $where . $wpdb->prepare( ' ORDER BY id DESC LIMIT %d OFFSET %d', 25, ( (int) $page - 1 ) * 25 ), ARRAY_A );
		if ( null === $total || null === $rows || $wpdb->last_error ) { return self::error( 'database', 'Could not load rental records. Check the database connection.' ); }
		return array( 'rows' => $rows, 'total' => (int) $total, 'page' => (int) $page );
	}
}
