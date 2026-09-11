<?php
/**
 * Fired when the plugin is uninstalled.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete custom tables (optional - uncomment to remove all data on uninstall)
/*
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}housemajik_alerts" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}housemajik_sent_listings" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}housemajik_reactions" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}housemajik_sessions" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}housemajik_cron_log" );
*/

// Delete options
$options = array(
	'housemajik_ai_model',
	'housemajik_ai_daily_cap',
	'housemajik_ai_ip_daily',
	'housemajik_ai_cap_lowered_1',
	'housemajik_ai_last_error',
	'housemajik_last_mail',
	'housemajik_agent_id',
	'housemajik_search_cities',
	'housemajik_broker_email',
	'housemajik_broker_name',
	'housemajik_brokerage_name',
	'housemajik_alert_frequency',
	'housemajik_data_source',
	'housemajik_idx_disclaimer',
	'housemajik_sender_email',
	'housemajik_buyer_sender_email',
	'housemajik_sender_name',
	'housemajik_reply_to_email',
	'housemajik_armls_endpoint',
	'housemajik_armls_username',
	'housemajik_armls_password',
	'housemajik_google_maps_key',
	'housemajik_search_page_url',
	'housemajik_rate_limit_searches',
	'housemajik_rate_limit_registers',
	'housemajik_rate_limit_window',
	'housemajik_mail_daily_cap',
	'housemajik_mail_usage_today',
	'housemajik_mail_usage_date',
	'housemajik_rate_limit_raised_1',
	'housemajik_ai_usage_today',
	'housemajik_ai_usage_date',
	'housemajik_rewrite_version',
	'housemajik_db_version',
	'housemajik_last_weekly_report',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
