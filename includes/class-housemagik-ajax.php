<?php
/**
 * AJAX request handlers.
 */
class Housemajik_Ajax {

	/**
	 * Handle search request.
	 */
	public function handle_search() {
		Housemajik_Security::verify_ajax_nonce();
		Housemajik_Security::check_honeypot();
		Housemajik_Security::check_rate_limit( 'search' );
		
		$params = Housemajik_Security::sanitize_search_params( $_POST );
		
		// Validate required fields
		if ( $params['must_have'] === '' && $params['would_like'] === '' ) {
			wp_send_json_error( array( 'message' => 'Please tell us what you must have or would like.' ) );
		}
		
		if ( empty( $params['location'] ) ) {
			wp_send_json_error( array( 'message' => 'Please choose at least one city.' ) );
		}
		
		$data_source = get_option( 'housemajik_data_source', 'sample' );
		$fetcher     = $this->listings_fetcher( $data_source );
		$listings    = call_user_func( $fetcher, $params );

		$session_id  = Housemajik_Security::get_session_id();
		$taste_notes = Housemajik_Saved::taste_notes( $session_id );
		$search_url  = isset( $_POST['search_url'] ) ? esc_url_raw( wp_unslash( $_POST['search_url'] ) ) : '';
		Housemajik_Security::set_search_page_url( $search_url );

		$tradeoff = Housemajik_Tradeoff::detect( $params, $listings, $fetcher );
		if ( $tradeoff ) {
			$payload = $this->tradeoff_payload( $tradeoff, $params, $session_id );
			$this->persist_search( $session_id, $params, $payload['results'] );
			wp_send_json_success( $payload );
		}

		if ( empty( $listings ) ) {
			$this->persist_search( $session_id, $params, array() );
			wp_send_json_success( $this->empty_search_payload( $params, $fetcher, $taste_notes ) );
		}

		$listings = $this->honest_listings( $listings, $params );
		if ( empty( $listings ) ) {
			$this->persist_search( $session_id, $params, array() );
			wp_send_json_success( $this->empty_search_payload( $params, $fetcher, $taste_notes ) );
		}

		$results = $this->present_listings( $listings, $params, $taste_notes );
		
		$this->persist_search( $session_id, $params, $results );
		
		// Rankings can miss a lone listing (1-based index). Keep the filtered homes.
		if ( empty( $results ) && ! empty( $listings ) ) {
			$results = array_slice( $listings, 0, 8 );
		}

		if ( empty( $results ) ) {
			wp_send_json_success( $this->empty_search_payload( $params, $fetcher, $taste_notes ) );
		}
		
		wp_send_json_success( array(
			'results' => $results,
			'session_id' => $session_id,
			'show_registration' => false,
		) );
	}

	/**
	 * Handle save reaction.
	 */
	public function handle_save_reaction() {
		Housemajik_Security::verify_ajax_nonce();
		
		$session_id = Housemajik_Security::get_session_id();
		$listing_id = isset( $_POST['listing_id'] ) ? sanitize_text_field( $_POST['listing_id'] ) : '';
		$what_like = isset( $_POST['what_like'] ) ? sanitize_textarea_field( $_POST['what_like'] ) : '';
		$what_dislike = isset( $_POST['what_dislike'] ) ? sanitize_textarea_field( $_POST['what_dislike'] ) : '';
		
		if ( empty( $listing_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid listing.' ) );
		}
		
		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_reactions';
		
		// Check if reaction exists
		$agent_id = Housemajik_Agent::id();
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM $table WHERE session_id = %s AND listing_id = %s AND agent_id = %s",
			$session_id,
			$listing_id,
			$agent_id
		) );
		
		if ( $existing ) {
			// Update
			$wpdb->update(
				$table,
				array(
					'what_like' => $what_like,
					'what_dislike' => $what_dislike,
					'agent_id' => $agent_id,
					'updated_at' => current_time( 'mysql' ),
				),
				array(
					'id' => $existing->id,
				),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// Insert
			$wpdb->insert(
				$table,
				array(
					'session_id' => $session_id,
					'listing_id' => $listing_id,
					'agent_id' => $agent_id,
					'what_like' => $what_like,
					'what_dislike' => $what_dislike,
					'created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
		
		wp_send_json_success( array(
			'message' => 'Notes saved.',
			'show_registration' => $this->should_show_registration( $session_id ),
		) );
	}

	/**
	 * Save or unsave a listing.
	 */
	public function handle_save_property() {
		Housemajik_Security::verify_ajax_nonce();

		$session_id = Housemajik_Security::get_session_id();
		$listing_id = isset( $_POST['listing_id'] ) ? sanitize_text_field( wp_unslash( $_POST['listing_id'] ) ) : '';
		$saved      = ! empty( $_POST['saved'] );

		if ( empty( $listing_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid listing.' ) );
		}

		$is_saved = Housemajik_Saved::toggle( $session_id, $listing_id, $saved );
		$items    = Housemajik_Saved::homes_for_buyer( $session_id );
		$word     = Housemajik_Saved::collection_word( $items );
		if ( $is_saved ) {
			$message = 'Property saved.';
		} elseif ( empty( $items ) ) {
			$message = 'Property removed.';
		} else {
			$message = 'Property removed from your ' . $word . '.';
		}
		wp_send_json_success( array(
			'saved'           => $is_saved,
			'message'         => $message,
			'url'             => Housemajik_Saved::page_url(),
			'saved_heading'   => Housemajik_Saved::page_heading( $items ),
			'saved_link'      => Housemajik_Saved::page_link_label( $items ),
			'saved_link_your' => Housemajik_Saved::page_link_label( $items, true ),
		) );
	}

	/**
	 * Handle registration.
	 */
	public function handle_register() {
		Housemajik_Security::verify_ajax_nonce();
		Housemajik_Security::check_honeypot();
		Housemajik_Security::check_rate_limit( 'register' );
		
		$session_id = Housemajik_Security::get_session_id();
		list( $first_name, $last_name, $name ) = Housemajik_Security::posted_names();
		$email = Housemajik_Security::sanitize_email( $_POST['email'] ?? '' );
		$phone = Housemajik_Security::sanitize_phone( $_POST['phone'] ?? '' );
		
		if ( empty( $first_name ) || empty( $email ) ) {
			wp_send_json_error( array( 'message' => 'First name and email are required.' ) );
		}
		
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'Invalid email address.' ) );
		}
		
		global $wpdb;
		$sessions_table = $wpdb->prefix . 'housemajik_sessions';
		$agent_id = Housemajik_Agent::id();
		$session = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM $sessions_table WHERE session_id = %s AND agent_id = %s ORDER BY created_at DESC LIMIT 1",
			$session_id,
			$agent_id
		) );

		if ( $session ) {
			$wpdb->update(
				$sessions_table,
				array( 'email' => $email, 'name' => $first_name, 'last_name' => $last_name, 'registered' => 1, 'agent_id' => $agent_id ),
				array( 'session_id' => $session_id, 'agent_id' => $agent_id ),
				array( '%s', '%s', '%s', '%d', '%s' ),
				array( '%s', '%s' )
			);
		} else {
			$wpdb->insert(
				$sessions_table,
				array(
					'session_id'    => $session_id,
					'email'         => $email,
					'name'          => $first_name,
					'last_name'     => $last_name,
					'agent_id'      => $agent_id,
					'search_params' => wp_json_encode( array() ),
					'results_shown' => wp_json_encode( array() ),
					'registered'    => 1,
					'created_at'    => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
		}

		Housemajik_Security::remember_buyer( $first_name, $last_name );

		$reactions_table = $wpdb->prefix . 'housemajik_reactions';
		$wpdb->update(
			$reactions_table,
			array( 'email' => $email ),
			array( 'session_id' => $session_id, 'agent_id' => $agent_id ),
			array( '%s' ),
			array( '%s', '%s' )
		);

		$listing_id = isset( $_POST['listing_id'] ) ? sanitize_text_field( wp_unslash( $_POST['listing_id'] ) ) : '';
		$listing    = $listing_id ? $this->lookup_listing( $listing_id ) : null;
		$notes      = $listing_id ? $wpdb->get_row( $wpdb->prepare(
			"SELECT what_like, what_dislike FROM $reactions_table WHERE session_id = %s AND listing_id = %s AND agent_id = %s",
			$session_id,
			$listing_id,
			$agent_id
		) ) : null;

		$alerts_on   = ! empty( $_POST['alert'] );
		$like        = $notes ? (string) $notes->what_like : '';
		$dislike     = $notes ? (string) $notes->what_dislike : '';
		$listing_arr = is_array( $listing ) ? $listing : array();
		$may_mail    = Housemajik_Security::allow_outbound_mail( $email );

		$buyer_sent = false;
		if ( $may_mail ) {
			$buyer_sent = Housemajik_Email::send_buyer_registration( $first_name, $email, $listing_arr, $like, $dislike, $alerts_on );
			Housemajik_Email::send_broker_brief( $name, $email, $phone, $session_id, $listing_arr, $like, $dislike );
			Housemajik_Security::record_outbound_mail( $email );
		}

		if ( ! $may_mail ) {
			$message = 'Registration complete. A confirmation was already sent to this address recently.';
		} elseif ( $buyer_sent ) {
			$buyer_status = Housemajik_Email::last_status();
			$from = ! empty( $buyer_status['from'] ) ? $buyer_status['from'] : 'homealerts@housemagik.ai';
			$message = 'Registration complete. Confirmation sent to ' . ( $buyer_status['to'] ?? $email ) . ' from ' . $from . '.';
		} else {
			$message = 'Registration saved, but the confirmation email did not send.';
		}

		wp_send_json_success( array(
			'message' => $message,
		) );
	}

	/**
	 * Handle save alert.
	 */
	public function handle_save_alert() {
		Housemajik_Security::verify_ajax_nonce();
		Housemajik_Security::check_honeypot();
		Housemajik_Security::check_rate_limit( 'alert' );
		
		$session_id = Housemajik_Security::get_session_id();
		list( $first_name, $last_name, $name ) = Housemajik_Security::posted_names();
		$email = Housemajik_Security::sanitize_email( $_POST['email'] ?? '' );
		$phone = Housemajik_Security::sanitize_phone( $_POST['phone'] ?? '' );
		
		if ( empty( $first_name ) || empty( $email ) ) {
			wp_send_json_error( array( 'message' => 'First name and email are required.' ) );
		}
		
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'Invalid email address.' ) );
		}
		
		// Get session search params
		global $wpdb;
		$sessions_table = $wpdb->prefix . 'housemajik_sessions';
		$agent_id = Housemajik_Agent::id();
		$session = $wpdb->get_row( $wpdb->prepare(
			"SELECT search_params FROM $sessions_table WHERE session_id = %s AND agent_id = %s ORDER BY created_at DESC LIMIT 1",
			$session_id,
			$agent_id
		) );
		
		if ( ! $session ) {
			wp_send_json_error( array( 'message' => 'Session not found.' ) );
		}
		
		$search_params = json_decode( $session->search_params, true );
		
		// Save alert
		$alerts_table = $wpdb->prefix . 'housemajik_alerts';
		$wpdb->insert(
			$alerts_table,
			array(
				'name' => $name,
				'email' => $email,
				'agent_id' => $agent_id,
				'phone' => $phone,
				'search_params' => wp_json_encode( $search_params ),
				'dream_home' => $search_params['dream_home'],
				'dont_want' => $search_params['dont_want'],
				'active' => 1,
				'frequency' => get_option( 'housemajik_alert_frequency', 'daily' ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		
		if ( empty( $_POST['silent'] ) && Housemajik_Security::allow_outbound_mail( $email ) ) {
			Housemajik_Email::send_alert_confirmation( $name, $email, $search_params );
			Housemajik_Security::record_outbound_mail( $email );
		}
		
		wp_send_json_success( array( 
			'message' => 'Alert saved! You\'ll receive email updates when new matches appear.',
		) );
	}

	/**
	 * Store this search and, if they already registered, tell the broker when it matters.
	 */
	private function persist_search( $session_id, $params, $results ) {
		$previous = Housemajik_Buyer_Activity::latest_params( $session_id );
		$buyer    = Housemajik_Buyer_Activity::buyer_for_session( $session_id );
		$this->save_search_session( $session_id, $params, $results, $buyer );
		if ( $buyer ) {
			Housemajik_Buyer_Activity::notify_if_changed( $buyer, $previous, $params );
		}
	}

	/**
	 * Save search session.
	 */
	private function save_search_session( $session_id, $params, $results, $buyer = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_sessions';
		
		$result_ids = array_map( function( $listing ) {
			return $listing['id'];
		}, $results );

		$row = array(
			'session_id'    => $session_id,
			'agent_id'      => Housemajik_Agent::id(),
			'search_params' => wp_json_encode( $params ),
			'results_shown' => wp_json_encode( $result_ids ),
			'created_at'    => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s' );

		if ( is_array( $buyer ) && ! empty( $buyer['email'] ) ) {
			$row['email']      = $buyer['email'];
			$row['name']       = isset( $buyer['name'] ) ? $buyer['name'] : '';
			$row['last_name']  = isset( $buyer['last_name'] ) ? $buyer['last_name'] : '';
			$row['registered'] = 1;
			$formats[]         = '%s';
			$formats[]         = '%s';
			$formats[]         = '%s';
			$formats[]         = '%d';
		}
		
		$wpdb->insert( $table, $row, $formats );
	}

	/**
	 * Check if registration popup should show.
	 */
	private function should_show_registration( $session_id ) {
		if ( ! empty( $_COOKIE['housemajik_lead'] ) ) {
			return false;
		}

		// Check if already registered this session
		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_sessions';
		$registered = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table WHERE session_id = %s AND agent_id = %s AND registered = 1",
			$session_id,
			Housemajik_Agent::id()
		) );
		
		return (int) $registered === 0;
	}

	private function listings_fetcher( $data_source ) {
		$use_armls = ( $data_source === 'armls' && Housemajik_ARMLS::is_configured() );
		return function( $filters ) use ( $use_armls ) {
			if ( $use_armls ) {
				$listings = Housemajik_ARMLS::get_listings( $filters );
				if ( is_wp_error( $listings ) ) {
					return Housemajik_Sample_Data::get_listings( $filters );
				}
				return is_array( $listings ) ? $listings : array();
			}
			return Housemajik_Sample_Data::get_listings( $filters );
		};
	}

	/**
	 * Two honest piles when the combination does not exist.
	 */
	private function tradeoff_payload( $tradeoff, $params, $session_id ) {
		$results = array();
		$piles   = array();
		foreach ( $tradeoff['piles'] as $pile ) {
			$why  = isset( $pile['why'] ) ? $pile['why'] : '';
			$rows = array();
			foreach ( $pile['listings'] as $listing ) {
				if ( ! is_array( $listing ) ) {
					continue;
				}
				$listing['why']      = $why;
				$listing['tradeoff'] = array(
					'keep'    => isset( $pile['keep'] ) ? $pile['keep'] : '',
					'give_up' => isset( $pile['give_up'] ) ? $pile['give_up'] : '',
				);
				$listing['piles']    = Housemajik_AI::evaluate_piles( $listing, $params );
				$rows[]              = $listing;
				$results[]           = $listing;
			}
			$pile['listings'] = $rows;
			$piles[]          = $pile;
		}
		$tradeoff['piles'] = $piles;

		return array(
			'results'           => $results,
			'tradeoff'          => $tradeoff,
			'session_id'        => $session_id,
			'show_registration' => false,
		);
	}

	/**
	 * Empty-state payload with one verified relaxation, if any.
	 */
	private function honest_listings( $listings, $params ) {
		$out = array();
		foreach ( (array) $listings as $listing ) {
			if ( is_array( $listing ) && Housemajik_Tradeoff::has_honest_match( array( $listing ), $params ) ) {
				$out[] = $listing;
			}
		}
		return $out;
	}

	private function present_listings( $listings, $params, $taste_notes ) {
		$ranked = Housemajik_AI::rank_listings( $listings, $params, array(), $taste_notes );
		if ( ! isset( $ranked['rankings'] ) ) {
			return array_slice( $listings, 0, 8 );
		}

		usort(
			$ranked['rankings'],
			function( $a, $b ) {
				return $b['score'] - $a['score'];
			}
		);

		$results = array();
		foreach ( array_slice( $ranked['rankings'], 0, 8 ) as $ranking ) {
			$listing = $this->listing_from_ranking( $listings, $ranking );
			if ( ! $listing ) {
				continue;
			}
			$listing['why']   = Housemajik_AI::sanitize_why_line( isset( $ranking['why'] ) ? $ranking['why'] : '', $listing, $params, $taste_notes );
			$listing['score'] = isset( $ranking['score'] ) ? $ranking['score'] : 0;
			$listing['piles'] = Housemajik_AI::evaluate_piles( $listing, $params );
			$results[]        = $listing;
		}

		return $results;
	}

	private function attach_sacrifice( $listings, $sacrificed ) {
		$text = is_array( $sacrificed ) ? implode( ', ', $sacrificed ) : trim( (string) $sacrificed );
		if ( $text === '' ) {
			return $listings;
		}

		$out = array();
		foreach ( (array) $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			if ( ! isset( $listing['piles'] ) || ! is_array( $listing['piles'] ) ) {
				$listing['piles'] = array();
			}
			$listing['piles']['sacrificed'] = array(
				'text'   => $text,
				'status' => 'given',
			);
			$out[] = $listing;
		}
		return $out;
	}

	private function cards_from_listings( $listings, $params, $taste_notes ) {
		$out = array();
		foreach ( array_slice( (array) $listings, 0, 8 ) as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$listing['why']   = Housemajik_AI::sanitize_why_line( '', $listing, $params, $taste_notes );
			$listing['piles'] = Housemajik_AI::evaluate_piles( $listing, $params );
			$out[]            = $listing;
		}
		return $out;
	}

	private function empty_search_payload( $params, $fetcher, $taste_notes = array() ) {
		$city = isset( $params['location'] ) ? Housemajik_Locations::display( $params['location'] ) : '';
		$message = $city
			? sprintf( 'Nothing in %s met every filter you set.', $city )
			: 'Nothing currently listed met every filter you set.';

		$suggestion = Housemajik_Suggest::from_empty( $params, $fetcher );
		if ( $suggestion && ! empty( $suggestion['listings'] ) ) {
			$relaxed = $params;
			foreach ( (array) $suggestion['apply'] as $key => $value ) {
				$relaxed[ $key ] = $value;
			}
			$suggestion['results'] = $this->present_listings( $suggestion['listings'], $relaxed, $taste_notes );
			if ( empty( $suggestion['results'] ) ) {
				$suggestion['results'] = $this->cards_from_listings( $suggestion['listings'], $relaxed, $taste_notes );
			}
			if ( empty( $suggestion['results'] ) ) {
				$suggestion = null;
			} else {
				$suggestion['results'] = $this->attach_sacrifice( $suggestion['results'], isset( $suggestion['sacrificed'] ) ? $suggestion['sacrificed'] : array() );
			}
		}
		if ( $suggestion ) {
			$message .= ' ' . $suggestion['label'];
		} else {
			$land = class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $params );
			$message .= $land
				? ' No listed land is close enough to name a next step.'
				: ' Try one fewer bedroom, a little more budget, or a nearby city.';
		}

		return array(
			'results'     => array(),
			'empty'       => true,
			'message'     => $message,
			'suggestion'  => $suggestion,
		);
	}

	/**
	 * Map a ranking row onto a listing. Claude sometimes sends 1-based indexes.
	 */
	private function listing_from_ranking( $listings, $ranking ) {
		if ( ! is_array( $listings ) || ! is_array( $ranking ) ) {
			return null;
		}

		$idx   = isset( $ranking['listing_index'] ) ? (int) $ranking['listing_index'] : -1;
		$count = count( $listings );

		if ( isset( $listings[ $idx ] ) ) {
			return $listings[ $idx ];
		}

		if ( $idx === $count && $idx > 0 && isset( $listings[ $idx - 1 ] ) ) {
			return $listings[ $idx - 1 ];
		}

		return null;
	}

	private function lookup_listing( $listing_id ) {
		$data_source = get_option( 'housemajik_data_source', 'sample' );
		if ( $data_source === 'armls' && Housemajik_ARMLS::is_configured() ) {
			$listing = Housemajik_ARMLS::get_listing( $listing_id );
			if ( ! is_wp_error( $listing ) && $listing ) {
				return $listing;
			}
		}
		return Housemajik_Sample_Data::get_listing( $listing_id );
	}
}
