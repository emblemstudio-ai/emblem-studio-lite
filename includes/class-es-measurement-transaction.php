<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ES_Measurement_Transaction {
	public static function query_failed(): bool {
		global $wpdb;
		return $wpdb->last_error !== '';
	}
	public static function tables_transactional( array $tables ): bool {
		global $wpdb;
		$tables = array_values( array_unique( $tables ) );
		if ( $tables === [] ) { return false; }
		$in = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)", ...$tables ), ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error !== '' || count( $rows ) !== count( $tables ) ) { return false; }
		foreach ( $rows as $row ) { if ( $row['ENGINE'] !== 'InnoDB' ) { return false; } }
		return true;
	}

	public static function active(): ?bool {
		global $wpdb;
		$name = 'es_measurement_probe_' . bin2hex( random_bytes( 8 ) );
		$previous = $wpdb->suppress_errors( true );
		try {
			if ( $wpdb->query( "SAVEPOINT $name" ) === false ) { return null; }
			if ( $wpdb->query( "ROLLBACK TO SAVEPOINT $name" ) !== false ) {
				return $wpdb->query( "RELEASE SAVEPOINT $name" ) === false ? null : true;
			}
			$warning = $wpdb->get_row( 'SHOW WARNINGS', ARRAY_A );
			return $warning && (int) $warning['Code'] === 1305 ? false : null;
		} finally { $wpdb->suppress_errors( $previous ); }
	}

	public static function run( callable $operation ) {
		global $wpdb;
		$name = 'es_measurement_' . bin2hex( random_bytes( 8 ) );
		$previous = $wpdb->suppress_errors( true );
		$own = false;
		$opened = false;
		try {
			$state = self::active();
			if ( $state === null ) { return false; }
			if ( ! $state ) {
				if ( $wpdb->query( 'START TRANSACTION' ) === false ) { return false; }
				$own = true;
			} elseif ( $wpdb->query( "SAVEPOINT $name" ) === false ) {
				return false;
			}
			$opened = true;
			$result = $operation();
			if ( $result === false ) {
				$wpdb->query( $own ? 'ROLLBACK' : "ROLLBACK TO SAVEPOINT $name" );
				if ( ! $own ) { $wpdb->query( "RELEASE SAVEPOINT $name" ); }
				$opened = false;
				return false;
			}
			if ( $wpdb->query( $own ? 'COMMIT' : "RELEASE SAVEPOINT $name" ) === false ) { return false; }
			$opened = false;
			return $result;
		} catch ( Throwable $error ) {
			return false;
		} finally {
			if ( $opened ) {
				$wpdb->query( $own ? 'ROLLBACK' : "ROLLBACK TO SAVEPOINT $name" );
				if ( ! $own ) { $wpdb->query( "RELEASE SAVEPOINT $name" ); }
			}
			$wpdb->suppress_errors( $previous );
		}
	}
}
