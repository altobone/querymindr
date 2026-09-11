<?php
/**
 * Fired during plugin deactivation.
 */
class Housemajik_Deactivator {

	/**
	 * Deactivation tasks.
	 */
	public static function deactivate() {
		// Clear scheduled cron events
		$timestamp = wp_next_scheduled( 'housemajik_daily_alerts' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'housemajik_daily_alerts' );
		}
		require_once dirname( __FILE__ ) . '/class-housemagik-buyer-activity.php';
		Housemajik_Buyer_Activity::unschedule_weekly();
		
		// Flush rewrite rules
		flush_rewrite_rules();
	}
}
