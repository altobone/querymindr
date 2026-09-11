<?php
/**
 * Fired during plugin activation.
 */
class Housemajik_Activator {

	/**
	 * Activation tasks.
	 */
	public static function activate() {
		// Create custom tables
		self::create_tables();
		
		// Create alert cron schedule if it doesn't exist
		if ( ! wp_next_scheduled( 'housemajik_daily_alerts' ) ) {
			wp_schedule_event( time(), 'daily', 'housemajik_daily_alerts' );
		}
		require_once dirname( __FILE__ ) . '/class-housemagik-buyer-activity.php';
		Housemajik_Buyer_Activity::maybe_schedule_weekly();
		
		// Set default options
		self::set_default_options();

		add_rewrite_rule(
			'^property/([^/]+)/?$',
			'index.php?housemajik_listing=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^saved-homes/?$',
			'index.php?housemajik_saved=1',
			'top'
		);
		add_rewrite_tag( '%housemajik_listing%', '([^&]+)' );
		add_rewrite_tag( '%housemajik_saved%', '([0-9]+)' );
		flush_rewrite_rules();
	}
	
	/**
	 * Create custom database tables.
	 */
	private static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		
		// Saved searches/alerts table
		$table_name = $wpdb->prefix . 'housemajik_alerts';
		$sql = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			email varchar(255) NOT NULL,
			agent_id varchar(64) NOT NULL DEFAULT '',
			phone varchar(50) DEFAULT NULL,
			search_params text NOT NULL,
			dream_home text DEFAULT NULL,
			dont_want text DEFAULT NULL,
			active tinyint(1) DEFAULT 1,
			frequency varchar(20) DEFAULT 'daily',
			created_at datetime NOT NULL,
			last_sent_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY active (active),
			KEY agent_id (agent_id)
		) $charset_collate;";
		dbDelta( $sql );
		
		// Sent listings tracking (deduplication)
		$table_name = $wpdb->prefix . 'housemajik_sent_listings';
		$sql = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			alert_id bigint(20) UNSIGNED NOT NULL,
			listing_id varchar(100) NOT NULL,
			sent_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY alert_listing (alert_id, listing_id)
		) $charset_collate;";
		dbDelta( $sql );
		
		// User reactions table (likes/dislikes)
		$table_name = $wpdb->prefix . 'housemajik_reactions';
		$sql = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id varchar(255) NOT NULL,
			email varchar(255) DEFAULT NULL,
			agent_id varchar(64) NOT NULL DEFAULT '',
			listing_id varchar(100) NOT NULL,
			what_like text DEFAULT NULL,
			what_dislike text DEFAULT NULL,
			saved tinyint(1) DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_listing (session_id, listing_id),
			KEY email (email),
			KEY agent_id (agent_id)
		) $charset_collate;";
		dbDelta( $sql );
		
		// Search sessions table
		$table_name = $wpdb->prefix . 'housemajik_sessions';
		$sql = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id varchar(255) NOT NULL,
			email varchar(255) DEFAULT NULL,
			name varchar(100) DEFAULT NULL,
			last_name varchar(100) DEFAULT NULL,
			agent_id varchar(64) NOT NULL DEFAULT '',
			search_params text NOT NULL,
			results_shown text DEFAULT NULL,
			registered tinyint(1) DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY email (email),
			KEY agent_id (agent_id)
		) $charset_collate;";
		dbDelta( $sql );
		
		// Alert cron log
		$table_name = $wpdb->prefix . 'housemajik_cron_log';
		$sql = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			run_at datetime NOT NULL,
			alerts_processed int DEFAULT 0,
			matches_sent int DEFAULT 0,
			status varchar(20) NOT NULL,
			message text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY run_at (run_at)
		) $charset_collate;";
		dbDelta( $sql );
	}
	
	/**
	 * Set default options.
	 */
	private static function set_default_options() {
		$defaults = array(
			'housemajik_ai_model' => 'claude-haiku-4-5',
			'housemajik_ai_daily_cap' => 200,
			'housemajik_ai_ip_daily' => 30,
			'housemajik_agent_id' => 'suzanne',
			'housemajik_broker_email' => 'mlake@redlake.tv',
			'housemajik_broker_name' => 'Suzanne Gonzalez',
			'housemajik_brokerage_name' => 'Keys of Dreams Brokery',
			'housemajik_alert_frequency' => 'daily',
			'housemajik_data_source' => 'sample',
			'housemajik_idx_disclaimer' => 'The data relating to real estate for sale on this website comes from the Arizona Regional Multiple Listing Service. Listings last updated: [DATE]. This information is deemed reliable but not guaranteed.',
			'housemajik_sender_email' => 'leads@housemagik.ai',
			'housemajik_buyer_sender_email' => 'homealerts@housemagik.ai',
			'housemajik_sender_name' => get_bloginfo( 'name' ),
			'housemajik_reply_to_email' => get_option( 'admin_email' ),
			'housemajik_rate_limit_searches' => 100,
			'housemajik_rate_limit_registers' => 5,
			'housemajik_rate_limit_window' => 3600,
			'housemajik_mail_daily_cap' => 50,
		);
		
		foreach ( $defaults as $key => $value ) {
			if ( ! get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}
}
