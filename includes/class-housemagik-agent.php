<?php
/**
 * Current agent on this WordPress site.
 */
class Housemajik_Agent {

	public static function id() {
		$id = get_option( 'housemajik_agent_id', 'suzanne' );
		$id = sanitize_title( $id );
		return $id !== '' ? $id : 'suzanne';
	}

	public static function maybe_upgrade() {
		if ( ! get_option( 'housemajik_agent_id' ) ) {
			add_option( 'housemajik_agent_id', 'suzanne' );
		}
		$tables = array(
			'housemajik_reactions',
			'housemajik_sessions',
			'housemajik_alerts',
		);
		foreach ( $tables as $suffix ) {
			self::ensure_agent_column( $suffix );
		}
	}

	private static function ensure_agent_column( $suffix ) {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		$col   = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", 'agent_id' ) );
		if ( empty( $col ) ) {
			$wpdb->query( "ALTER TABLE $table ADD agent_id varchar(64) NOT NULL DEFAULT ''" );
		}
		$agent = self::id();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET agent_id = %s WHERE agent_id = ''",
				$agent
			)
		);
	}
}
