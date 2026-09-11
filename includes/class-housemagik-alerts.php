<?php
/**
 * Alert cron system.
 */
class Housemajik_Alerts {

	/**
	 * Run daily alerts check.
	 */
	public function run_daily_alerts() {
		$start_time = current_time( 'mysql' );
		$alerts_processed = 0;
		$matches_sent = 0;
		$errors = array();
		
		global $wpdb;
		$alerts_table = $wpdb->prefix . 'housemajik_alerts';
		
		// Get active alerts
		$alerts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $alerts_table WHERE active = 1 AND frequency = 'daily' AND agent_id = %s",
				Housemajik_Agent::id()
			),
			ARRAY_A
		);
		
		if ( empty( $alerts ) ) {
			$this->log_cron_run( $start_time, 0, 0, 'success', 'No active alerts.' );
			return;
		}
		
		foreach ( $alerts as $alert ) {
			$alerts_processed++;
			
			try {
				$new_matches = $this->find_new_matches( $alert );
				
				if ( ! empty( $new_matches ) ) {
					// Send alert email
					$sent = Housemajik_Email::send_alert_email( $alert, $new_matches );
					
					if ( $sent ) {
						$matches_sent += count( $new_matches );
						
						// Mark listings as sent
						$this->mark_listings_sent( $alert['id'], $new_matches );
						
						// Update last_sent_at
						$wpdb->update(
							$alerts_table,
							array( 'last_sent_at' => current_time( 'mysql' ) ),
							array( 'id' => $alert['id'] ),
							array( '%s' ),
							array( '%d' )
						);
					} else {
						$errors[] = sprintf( 'Failed to send email to %s', $alert['email'] );
					}
				}
			} catch ( Exception $e ) {
				$errors[] = sprintf( 'Error processing alert %d: %s', $alert['id'], $e->getMessage() );
			}
		}
		
		$status = empty( $errors ) ? 'success' : 'partial';
		$message = sprintf( 
			'Processed %d alerts, sent %d matches. %s', 
			$alerts_processed,
			$matches_sent,
			! empty( $errors ) ? 'Errors: ' . implode( '; ', $errors ) : ''
		);
		
		$this->log_cron_run( $start_time, $alerts_processed, $matches_sent, $status, $message );
	}

	/**
	 * Find new matches for an alert.
	 */
	private function find_new_matches( $alert ) {
		$search_params = json_decode( $alert['search_params'], true );
		
		// Get data source
		$data_source = get_option( 'housemajik_data_source', 'sample' );
		
		// Get listings
		if ( $data_source === 'armls' && Housemajik_ARMLS::is_configured() ) {
			$listings = Housemajik_ARMLS::get_listings( $search_params );
			if ( is_wp_error( $listings ) ) {
				$listings = Housemajik_Sample_Data::get_listings( $search_params );
			}
		} else {
			$listings = Housemajik_Sample_Data::get_listings( $search_params );
		}
		
		if ( empty( $listings ) ) {
			return array();
		}
		
		$candidates = array();
		foreach ( $listings as $listing ) {
			if ( ! $this->was_listing_sent( $alert['id'], $listing['id'] ) ) {
				$candidates[] = $listing;
			}
		}

		if ( empty( $candidates ) ) {
			return array();
		}

		$taste  = class_exists( 'Housemajik_Saved' ) ? Housemajik_Saved::taste_notes( '', $alert['email'] ) : array();
		$ranked = Housemajik_AI::rank_listings( $candidates, $search_params, array(), $taste );
		$matches = array();

		if ( ! empty( $ranked['rankings'] ) && is_array( $ranked['rankings'] ) ) {
			foreach ( $ranked['rankings'] as $ranking ) {
				$score = isset( $ranking['score'] ) ? (int) $ranking['score'] : 0;
				if ( $score < 60 ) {
					continue;
				}

				$listing = $this->listing_from_ranking( $candidates, $ranking );
				if ( ! $listing ) {
					continue;
				}

				$why = Housemajik_AI::sanitize_why_line(
					isset( $ranking['why'] ) ? $ranking['why'] : '',
					$listing,
					$search_params,
					$taste
				);
				if ( Housemajik_AI::is_rejection_why( $why ) ) {
					continue;
				}

				$listing['why']   = $why;
				$listing['score'] = $score;
				$matches[]        = $listing;
			}
		}

		usort( $matches, function( $a, $b ) {
			return (int) $b['score'] - (int) $a['score'];
		} );

		return array_slice( $matches, 0, 5 );
	}

	private function listing_from_ranking( $listings, $ranking ) {
		if ( isset( $ranking['listing'] ) && is_array( $ranking['listing'] ) ) {
			return $ranking['listing'];
		}

		$idx   = isset( $ranking['listing_index'] ) ? (int) $ranking['listing_index'] : -1;
		$count = count( $listings );
		if ( $idx >= 0 && $idx < $count ) {
			return $listings[ $idx ];
		}
		if ( $idx === $count && $count > 0 ) {
			return $listings[ $count - 1 ];
		}

		return null;
	}

	/**
	 * Check if listing was already sent.
	 */
	private function was_listing_sent( $alert_id, $listing_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_sent_listings';
		
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table WHERE alert_id = %d AND listing_id = %s",
			$alert_id,
			$listing_id
		) );
		
		return (int) $count > 0;
	}

	/**
	 * Mark listings as sent.
	 */
	private function mark_listings_sent( $alert_id, $listings ) {
		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_sent_listings';
		
		foreach ( $listings as $listing ) {
			$wpdb->insert(
				$table,
				array(
					'alert_id' => $alert_id,
					'listing_id' => $listing['id'],
					'sent_at' => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Log cron run.
	 */
	private function log_cron_run( $run_at, $alerts_processed, $matches_sent, $status, $message ) {
		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_cron_log';
		
		$wpdb->insert(
			$table,
			array(
				'run_at' => $run_at,
				'alerts_processed' => $alerts_processed,
				'matches_sent' => $matches_sent,
				'status' => $status,
				'message' => $message,
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);
		
		// Clean up old logs (keep last 30 days)
		$wpdb->query(
			"DELETE FROM $table WHERE run_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
		);
	}

	/**
	 * Get cron status.
	 */
	public static function get_cron_status() {
		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_cron_log';
		
		$last_run = $wpdb->get_row(
			"SELECT * FROM $table ORDER BY run_at DESC LIMIT 1",
			ARRAY_A
		);
		
		$next_run = wp_next_scheduled( 'housemajik_daily_alerts' );
		
		return array(
			'last_run' => $last_run,
			'next_run' => $next_run ? date( 'Y-m-d H:i:s', $next_run ) : 'Not scheduled',
			'is_scheduled' => (bool) $next_run,
		);
	}

	/**
	 * Manual trigger for testing.
	 */
	public static function trigger_manual() {
		$alerts = new self();
		$alerts->run_daily_alerts();
		return self::get_cron_status();
	}
}
