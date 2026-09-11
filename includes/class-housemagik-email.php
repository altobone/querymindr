<?php
/**
 * Email functionality using wp_mail.
 */
class Housemajik_Email {

	private static $current_audience = 'broker';
	private static $mail_active      = false;

	/**
	 * Buyer mail is always From homealerts@. Broker mail is always From leads@.
	 */
	private static function sender_for( $audience ) {
		$name = get_option( 'housemajik_sender_name', get_bloginfo( 'name' ) );
		if ( $audience === 'buyer' ) {
			$email = get_option( 'housemajik_buyer_sender_email', 'homealerts@housemagik.ai' );
			if ( ! is_email( $email ) ) {
				$email = 'homealerts@housemagik.ai';
			}
		} else {
			$email = get_option( 'housemajik_sender_email', 'leads@housemagik.ai' );
			if ( ! is_email( $email ) ) {
				$email = 'leads@housemagik.ai';
			}
		}

		return array( $name, $email );
	}

	private static function listing_url( $listing_id ) {
		if ( $listing_id === '' || $listing_id === null ) {
			return home_url( '/' );
		}
		return home_url( '/property/' . rawurlencode( (string) $listing_id ) );
	}

	private static function listing_anchor( $label, $listing_id ) {
		$label = (string) $label;
		if ( $listing_id === '' || $listing_id === null ) {
			return esc_html( $label );
		}
		return '<a href="' . esc_url( self::listing_url( $listing_id ) ) . '" style="color: #4299e1; text-decoration: underline;">' . esc_html( $label ) . '</a>';
	}

	private static function from_header( $audience ) {
		list( $name, $email ) = self::sender_for( $audience );
		$name = str_replace( array( "\r", "\n", '<', '>', '"', ',' ), '', (string) $name );
		if ( $name === '' ) {
			return 'From: ' . $email;
		}
		return 'From: ' . $name . ' <' . $email . '>';
	}

	/**
	 * After WP Mail SMTP runs, set the visible From only. Do not change Sender
	 * (the SMTP login stays leads@).
	 */
	public static function apply_mailer( $phpmailer ) {
		if ( ! self::$mail_active ) {
			return;
		}
		list( $name, $email ) = self::sender_for( self::$current_audience );
		$name = str_replace( array( "\r", "\n", '<', '>', '"', ',' ), '', (string) $name );
		if ( method_exists( $phpmailer, 'setFrom' ) ) {
			$phpmailer->setFrom( $email, $name, false );
		} else {
			$phpmailer->From     = $email;
			$phpmailer->FromName = $name;
		}
	}

	public static function record_mail_failure( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}
		self::log_result( false, '', '', '', '', $error->get_error_message() );
	}

	public static function last_status() {
		$status = get_option( 'housemajik_last_mail', array() );
		return is_array( $status ) ? $status : array();
	}

	public static function send_test( $to, $audience = 'buyer' ) {
		$body = '<p>This is a Housemagik test. If you received this, outbound mail is working.</p>';
		return self::send( $to, 'Housemagik test email', $body, $audience );
	}

	private static function log_result( $sent, $to, $audience, $subject, $from, $error = '' ) {
		$prev = self::last_status();
		if ( ! $sent && $error === '' ) {
			$error = ! empty( $prev['error'] ) ? $prev['error'] : 'wp_mail returned false';
		}
		update_option(
			'housemajik_last_mail',
			array(
				'ok'       => (bool) $sent,
				'to'       => $to,
				'from'     => $from,
				'audience' => $audience,
				'subject'  => $subject,
				'error'    => $sent ? '' : $error,
				'time'     => current_time( 'mysql' ),
			),
			false
		);
	}

	/**
	 * Broker To addresses from settings. One inbox or several, comma-separated.
	 */
	public static function broker_addresses() {
		$raw = get_option( 'housemajik_broker_email', 'mlake@redlake.tv' );
		if ( class_exists( 'Housemajik_Security' ) && method_exists( 'Housemajik_Security', 'sanitize_email_list' ) ) {
			$raw = Housemajik_Security::sanitize_email_list( $raw );
		}
		$parts = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( $parts as $part ) {
			$email = strtolower( trim( $part ) );
			if ( is_email( $email ) ) {
				$out[ $email ] = $email;
			}
		}
		if ( ! $out ) {
			$out[] = 'mlake@redlake.tv';
		}
		return array_values( $out );
	}

	public static function broker_contact_mailto() {
		$tos = array();
		foreach ( self::broker_addresses() as $email ) {
			if ( strcasecmp( $email, 'leads@housemagik.ai' ) === 0 ) {
				continue;
			}
			$tos[] = $email;
		}
		return $tos ? implode( ',', $tos ) : 'mlake@redlake.tv';
	}

	private static function send_to_brokers( $subject, $message, $extra_headers = array(), $skip = '' ) {
		$sent = false;
		foreach ( self::broker_addresses() as $to ) {
			if ( $skip !== '' && strcasecmp( $to, $skip ) === 0 ) {
				continue;
			}
			if ( self::send( $to, $subject, $message, 'broker', $extra_headers ) ) {
				$sent = true;
			}
		}
		return $sent;
	}

	private static function send( $to, $subject, $message, $audience, $extra_headers = array() ) {
		if ( ! is_email( $to ) ) {
			self::log_result( false, $to, $audience, $subject, '', 'Invalid To address' );
			return false;
		}

		self::$current_audience = $audience;
		self::$mail_active      = true;
		list( $from_name, $from_email ) = self::sender_for( $audience );

		// Never rewrite the buyer's To. If they typed leads@, that letter
		// still goes there (Brevo may drop it). Only the broker copy is
		// moved off a From=To loop, and only to mlake@.
		if ( $audience === 'broker' && strcasecmp( $to, $from_email ) === 0 ) {
			$to = 'mlake@redlake.tv';
		}

		$from_name = str_replace( array( "\r", "\n", '<', '>', '"', ',' ), '', (string) $from_name );
		$from_line = $from_name === '' ? 'From: ' . $from_email : 'From: ' . $from_name . ' <' . $from_email . '>';

		$headers = array_merge(
			array(
				'Content-Type: text/html; charset=UTF-8',
				$from_line,
			),
			$extra_headers
		);
		$sent = wp_mail( $to, $subject, $message, $headers );
		self::$mail_active      = false;
		self::$current_audience = 'broker';
		self::log_result( $sent, $to, $audience, $subject, $from_email );
		return $sent;
	}

	/**
	 * Send broker brief on registration.
	 */
	public static function send_broker_brief( $name, $email, $phone, $session_id, $listing = array(), $what_like = '', $what_dislike = '' ) {
		global $wpdb;
		
		// Get session data
		$sessions_table = $wpdb->prefix . 'housemajik_sessions';
		$session = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $sessions_table WHERE session_id = %s AND agent_id = %s ORDER BY created_at DESC LIMIT 1",
			$session_id,
			Housemajik_Agent::id()
		) );
		
		$search_params = array();
		$results_shown = array();
		if ( $session ) {
			$search_params = json_decode( $session->search_params, true );
			$results_shown = json_decode( $session->results_shown, true );
		}
		if ( ! is_array( $search_params ) ) {
			$search_params = array();
		}
		if ( ! is_array( $results_shown ) ) {
			$results_shown = array();
		}
		
		// Get reactions
		$reactions_table = $wpdb->prefix . 'housemajik_reactions';
		$reactions = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $reactions_table WHERE session_id = %s AND agent_id = %s",
			$session_id,
			Housemajik_Agent::id()
		), ARRAY_A );
		if ( ! is_array( $reactions ) ) {
			$reactions = array();
		}
		foreach ( $reactions as $i => $reaction ) {
			if ( empty( $reaction['listing_id'] ) ) {
				continue;
			}
			$found = Housemajik_Sample_Data::get_listing( $reaction['listing_id'] );
			if ( $found ) {
				$reactions[ $i ]['address'] = $found['address'] . ', ' . $found['city'];
			}
		}

		if ( is_array( $listing ) && ! empty( $listing['address'] ) ) {
			$place = $listing['address'] . ( ! empty( $listing['city'] ) ? ', ' . $listing['city'] : '' );
			$already = false;
			foreach ( $reactions as $reaction ) {
				if ( ! empty( $reaction['listing_id'] ) && ! empty( $listing['id'] ) && $reaction['listing_id'] === $listing['id'] ) {
					$already = true;
					break;
				}
			}
			if ( ! $already ) {
				array_unshift(
					$reactions,
					array(
						'listing_id'    => isset( $listing['id'] ) ? $listing['id'] : '',
						'address'       => $place,
						'what_like'     => $what_like,
						'what_dislike'  => $what_dislike,
					)
				);
			}
		}

		$ai_summary = array(
			'summary'  => '',
			'patterns' => array(),
		);
		
		$subject = sprintf( 'New Lead: %s - Housemagik', $name );
		$message = self::build_broker_brief_html( 
			$name, 
			$email, 
			$phone, 
			$search_params, 
			$results_shown, 
			$reactions,
			$ai_summary
		);

		return self::send_to_brokers( $subject, $message, array( 'Reply-To: ' . $email ) );
	}

	/**
	 * Send alert confirmation to buyer.
	 */
	public static function send_alert_confirmation( $name, $email, $search_params ) {
		$subject = 'Your Housemagik Search Alert is Active';
		
		$message = sprintf(
			"<html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>\n" .
			"<h2 style='color: #4a5568;'>Your Search Alert is Active</h2>\n" .
			"<p>Hi %s,</p>\n" .
			"<p>We'll email you when new listings match your search:</p>\n" .
			"<div style='background: #f7fafc; padding: 15px; border-left: 4px solid #4299e1; margin: 20px 0;'>\n" .
			"<strong>What you're looking for:</strong><br>\n%s\n</div>\n" .
			"<p><strong>Location:</strong> %s</p>\n" .
			"<p><strong>Budget:</strong> Up to $%s</p>\n" .
			"<p><strong>Bedrooms:</strong> %d (%s) | <strong>Bathrooms:</strong> %.1f (%s)</p>\n" .
			"<p style='margin-top: 30px; font-size: 12px; color: #718096;'>To unsubscribe or manage your alerts, <a href='%s'>click here</a>.</p>\n" .
			"<p style='font-size: 12px; color: #718096;'>%s<br>%s</p>\n" .
			"</body></html>",
			esc_html( $name ),
			nl2br( esc_html( $search_params['dream_home'] ) ),
			esc_html( Housemajik_Locations::display( $search_params['location'] ) ),
			number_format( $search_params['max_price'] ),
			$search_params['beds'],
			$search_params['beds_mode'] === 'exactly' ? 'exactly' : 'at least',
			$search_params['baths'],
			$search_params['baths_mode'] === 'exactly' ? 'exactly' : 'at least',
			home_url( '/manage-alerts/' ),
			get_option( 'housemajik_broker_name', 'Suzanne Gonzalez' ),
			get_option( 'housemajik_brokerage_name', 'Keys of Dreams Brokery' )
		);
		
		return self::send( $email, $subject, $message, 'buyer' );

	}

	/**
	 * Buyer mail after they register from a listing note.
	 */
	public static function send_buyer_registration( $name, $email, $listing, $what_like, $what_dislike, $alerts_on ) {
		$is_land = class_exists( 'Housemajik_Saved' ) && method_exists( 'Housemajik_Saved', 'listing_is_land' )
			? Housemajik_Saved::listing_is_land( $listing )
			: ( ! empty( $listing['land'] ) || ( isset( $listing['property_type'] ) && strtolower( (string) $listing['property_type'] ) === 'land' ) );
		$address = ! empty( $listing['address'] ) ? $listing['address'] : ( $is_land ? 'the land you viewed' : 'the home you viewed' );
		$items   = ( class_exists( 'Housemajik_Saved' ) && method_exists( 'Housemajik_Saved', 'homes_for_buyer' ) )
			? Housemajik_Saved::homes_for_buyer( '', $email )
			: array();
		$word    = ( class_exists( 'Housemajik_Saved' ) && method_exists( 'Housemajik_Saved', 'collection_word' ) )
			? Housemajik_Saved::collection_word( $items )
			: 'homes';
		if ( empty( $items ) && $is_land ) {
			$word = 'land';
		}
		$city    = ! empty( $listing['city'] ) ? $listing['city'] : '';
		$place   = $city ? $address . ', ' . $city : $address;

		$notes = '';
		if ( $what_like !== '' ) {
			$notes .= '<p><strong>What you liked:</strong> ' . nl2br( esc_html( $what_like ) ) . "</p>\n";
		}
		if ( $what_dislike !== '' ) {
			$notes .= '<p><strong>What you did not like:</strong> ' . nl2br( esc_html( $what_dislike ) ) . "</p>\n";
		}

		$alert_line = $alerts_on
			? "<p>We'll email you when new listings meet your search.</p>\n"
			: '';

		$listing_id = ! empty( $listing['id'] ) ? $listing['id'] : '';
		$place_html = self::listing_anchor( $place, $listing_id );

		$subject = sprintf( 'We saved your notes on %s', $address );
		$message = sprintf(
			"<html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>\n" .
			"<h2 style='color: #4a5568;'>We saved your comments</h2>\n" .
			"<p>Hi %s,</p>\n" .
			"<p>We saved your notes on <strong>%s</strong> so we can match %s to your taste.</p>\n" .
			'%s%s' .
			"<p><a href='%s'>See the %s you've saved or commented on</a></p>\n" .
			"<p style='margin-top: 30px; font-size: 12px; color: #718096;'>%s<br>%s</p>\n" .
			"</body></html>",
			esc_html( $name ),
			$place_html,
			esc_html( $word ),
			$notes,
			$alert_line,
			esc_url( Housemajik_Saved::page_url() ),
			esc_html( $word ),
			esc_html( get_option( 'housemajik_broker_name', 'Suzanne Gonzalez' ) ),
			esc_html( get_option( 'housemajik_brokerage_name', 'Keys of Dreams Brokery' ) )
		);

		return self::send( $email, $subject, $message, 'buyer' );
	}

	/**
	 * Send alert email with new matches.
	 */
	public static function send_alert_email( $alert, $listings ) {
		if ( empty( $listings ) ) {
			return false;
		}
		
		$search_params = json_decode( $alert['search_params'], true );
		
		// Get user's previous reactions for context
		global $wpdb;
		$reactions_table = $wpdb->prefix . 'housemajik_reactions';
		$agent_id = ! empty( $alert['agent_id'] ) ? $alert['agent_id'] : Housemajik_Agent::id();
		$reactions = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $reactions_table WHERE email = %s AND agent_id = %s ORDER BY created_at DESC LIMIT 10",
			$alert['email'],
			$agent_id
		), ARRAY_A );

		$kept = array();
		foreach ( $listings as $listing ) {
			$why = ! empty( $listing['why'] )
				? $listing['why']
				: Housemajik_AI::generate_alert_why_line( $listing, $search_params, $reactions );
			if ( Housemajik_AI::is_rejection_why( $why ) ) {
				continue;
			}
			$listing['why'] = $why;
			$kept[]         = $listing;
		}

		if ( empty( $kept ) ) {
			return false;
		}

		$listings = $kept;
		
		$land    = ! empty( $search_params['land_only'] );
		$subject = sprintf(
			$land ? 'New Land Matches Your Search (%d %s)' : 'New Homes Match Your Search (%d %s)',
			count( $listings ),
			count( $listings ) === 1 ? 'listing' : 'listings'
		);
		
		$message = self::build_alert_email_html( $alert, $listings, $search_params, $reactions );
		
		$buyer_sent = self::send( $alert['email'], $subject, $message, 'buyer' );

		$broker_subject = sprintf( 'Alert Sent: %s - %d New Matches', $alert['name'], count( $listings ) );
		self::send_to_brokers( $broker_subject, self::build_broker_alert_copy_html( $alert, $listings ), array(), isset( $alert['email'] ) ? $alert['email'] : '' );
		
		return $buyer_sent;
	}

	/**
	 * Build broker brief HTML.
	 */
	private static function build_broker_brief_html( $name, $email, $phone, $search_params, $results_shown, $reactions, $ai_summary ) {
		$html = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>\n";
		$html .= "<h2 style='color: #4a5568;'>New Lead from Housemagik</h2>\n";
		
		// Contact info
		$html .= "<div style='background: #edf2f7; padding: 15px; margin: 20px 0;'>\n";
		$html .= sprintf( "<p><strong>Name:</strong> %s</p>\n", esc_html( $name ) );
		$html .= sprintf( "<p><strong>Email:</strong> <a href='mailto:%s'>%s</a></p>\n", esc_attr( $email ), esc_html( $email ) );
		$html .= sprintf( "<p><strong>Buyer confirmation sent to:</strong> %s</p>\n", esc_html( $email ) );
		if ( ! empty( $phone ) ) {
			$html .= sprintf( "<p><strong>Phone:</strong> %s</p>\n", esc_html( $phone ) );
		}
		$html .= "</div>\n";
		
		// AI Summary
		if ( ! empty( $ai_summary['summary'] ) ) {
			$html .= "<h3 style='color: #2d3748;'>What They Want</h3>\n";
			$html .= "<p>" . esc_html( $ai_summary['summary'] ) . "</p>\n";
			
			if ( ! empty( $ai_summary['patterns'] ) ) {
				$html .= "<h4>Patterns from Their Feedback:</h4>\n<ul>\n";
				foreach ( $ai_summary['patterns'] as $pattern ) {
					$html .= "<li>" . esc_html( $pattern ) . "</li>\n";
				}
				$html .= "</ul>\n";
			}
		}
		
		$html .= "<h3 style='color: #2d3748;'>Search Criteria</h3>\n";
		$html .= "<div style='background: #f7fafc; padding: 15px; border-left: 4px solid #4299e1;'>\n";
		if ( ! empty( $search_params['must_have'] ) || ! empty( $search_params['would_like'] ) || ! empty( $search_params['never'] ) ) {
			if ( ! empty( $search_params['must_have'] ) ) {
				$html .= "<p><strong>Must have:</strong><br>" . nl2br( esc_html( $search_params['must_have'] ) ) . "</p>\n";
			}
			if ( ! empty( $search_params['would_like'] ) ) {
				$html .= "<p><strong>Would like:</strong><br>" . nl2br( esc_html( $search_params['would_like'] ) ) . "</p>\n";
			}
			if ( ! empty( $search_params['never'] ) ) {
				$html .= "<p><strong>Never:</strong><br>" . nl2br( esc_html( $search_params['never'] ) ) . "</p>\n";
			}
		} elseif ( ! empty( $search_params['dream_home'] ) ) {
			$html .= "<p><strong>Dream Home:</strong><br>" . nl2br( esc_html( $search_params['dream_home'] ) ) . "</p>\n";
			if ( ! empty( $search_params['dont_want'] ) ) {
				$html .= "<p><strong>Don't Want:</strong><br>" . nl2br( esc_html( $search_params['dont_want'] ) ) . "</p>\n";
			}
		} else {
			$html .= "<p>No saved search yet. They registered from a listing.</p>\n";
		}
		if ( ! empty( $search_params['location'] ) ) {
			$html .= sprintf( "<p><strong>Location:</strong> %s</p>\n", esc_html( Housemajik_Locations::display( $search_params['location'] ) ) );
		}
		if ( ! empty( $search_params['land_only'] ) ) {
			$html .= "<p><strong>Looking for:</strong> Land only</p>\n";
		}
		if ( isset( $search_params['beds'], $search_params['baths'], $search_params['max_price'] ) && empty( $search_params['land_only'] ) ) {
			$html .= sprintf(
				"<p><strong>Beds:</strong> %d (%s) | <strong>Baths:</strong> %.1f (%s) | <strong>Max Price:</strong> $%s</p>\n",
				$search_params['beds'],
				( isset( $search_params['beds_mode'] ) && $search_params['beds_mode'] === 'exactly' ) ? 'exactly' : 'at least',
				$search_params['baths'],
				( isset( $search_params['baths_mode'] ) && $search_params['baths_mode'] === 'exactly' ) ? 'exactly' : 'at least',
				number_format( $search_params['max_price'] )
			);
		} elseif ( ! empty( $search_params['land_only'] ) && ! empty( $search_params['max_price'] ) ) {
			$html .= sprintf( "<p><strong>Max Price:</strong> $%s</p>\n", number_format( (int) $search_params['max_price'] ) );
		}
		if ( ! empty( $search_params['acres'] ) && (float) $search_params['acres'] > 0 ) {
			$html .= sprintf(
				"<p><strong>Minimum acreage:</strong> at least %s</p>\n",
				esc_html( Housemajik_Security::acres_phrase( $search_params['acres'] ) )
			);
		}
		$html .= "</div>\n";
		
		// Listings shown
		$html .= sprintf( "<h3 style='color: #2d3748;'>Listings Shown (%d)</h3>\n", count( $results_shown ) );
		
		// Reactions
		if ( ! empty( $reactions ) ) {
			$html .= "<h4>Their Feedback:</h4>\n";
			foreach ( $reactions as $reaction ) {
				$html .= "<div style='background: #fff; border: 1px solid #e2e8f0; padding: 10px; margin: 10px 0;'>\n";
				$property_label = ! empty( $reaction['address'] ) ? $reaction['address'] : $reaction['listing_id'];
				$property_id    = ! empty( $reaction['listing_id'] ) ? $reaction['listing_id'] : '';
				$html .= sprintf( "<p><strong>Property:</strong> %s</p>\n", self::listing_anchor( $property_label, $property_id ) );
				if ( ! empty( $reaction['what_like'] ) ) {
					$html .= sprintf( "<p style='color: #38a169;'><strong>Liked:</strong> %s</p>\n", esc_html( $reaction['what_like'] ) );
				}
				if ( ! empty( $reaction['what_dislike'] ) ) {
					$html .= sprintf( "<p style='color: #e53e3e;'><strong>Disliked:</strong> %s</p>\n", esc_html( $reaction['what_dislike'] ) );
				}
				$html .= "</div>\n";
			}
		} else {
			$html .= "<p>No feedback provided yet.</p>\n";
		}
		
		$html .= "<p style='margin-top: 30px; font-size: 12px; color: #718096;'>Lead generated: " . current_time( 'mysql' ) . "</p>\n";
		$html .= "</body></html>";
		
		return $html;
	}

	/**
	 * Internal copy for the broker: not written to the buyer.
	 */
	private static function build_broker_alert_copy_html( $alert, $listings ) {
		$params = is_array( $alert['search_params'] ?? null )
			? $alert['search_params']
			: json_decode( isset( $alert['search_params'] ) ? $alert['search_params'] : '', true );
		$land   = is_array( $params ) && ! empty( $params['land_only'] );
		$html  = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>\n";
		$html .= "<h2 style='color: #4a5568;'>Buyer alert copy</h2>\n";
		$html .= '<p>This is for you, not the buyer. Housemagik just emailed matching ' . ( $land ? 'land' : 'homes' ) . " to:</p>\n";
		$html .= sprintf(
			"<p><strong>%s</strong> &lt;<a href='mailto:%s'>%s</a>&gt;</p>\n",
			esc_html( $alert['name'] ),
			esc_attr( $alert['email'] ),
			esc_html( $alert['email'] )
		);
		$html .= sprintf(
			'<p><strong>%s we sent (%d):</strong></p>\n<ul>\n',
			$land ? 'Land' : 'Homes',
			count( $listings )
		);
		foreach ( $listings as $listing ) {
			$label = $listing['address'] . ', ' . $listing['city'] . ' — $' . number_format( $listing['price'] );
			$id    = ! empty( $listing['id'] ) ? $listing['id'] : '';
			$html .= '<li>' . self::listing_anchor( $label, $id ) . "</li>\n";
		}
		$html .= "</ul>\n";
		$html .= "<p style='font-size: 12px; color: #718096;'>The buyer received a separate email that begins “Hi " . esc_html( $alert['name'] ) . ".”</p>\n";
		$html .= "</body></html>";

		return $html;
	}

	/**
	 * Build alert email HTML.
	 */
	private static function build_alert_email_html( $alert, $listings, $search_params, $reactions ) {
		$html = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>\n";
		$land  = ! empty( $search_params['land_only'] );
		$html .= sprintf(
			"<h2 style='color: #4a5568;'>Hi %s, we found new %s for you!</h2>\n",
			esc_html( $alert['name'] ),
			$land ? 'land' : 'homes'
		);
		
		foreach ( $listings as $listing ) {
			$why = ! empty( $listing['why'] )
				? $listing['why']
				: Housemajik_AI::generate_alert_why_line( $listing, $search_params, $reactions );
			if ( Housemajik_AI::is_rejection_why( $why ) ) {
				continue;
			}
			
			$html .= "<div style='background: #fff; border: 1px solid #e2e8f0; padding: 20px; margin: 20px 0;'>\n";
			
			// Photo
			if ( ! empty( $listing['photos'][0] ) ) {
				$html .= sprintf( 
					"<img src='%s' alt='%s' style='width: 100%%; max-width: 600px; height: auto;'>\n",
					esc_url( $listing['photos'][0] ),
					esc_attr( $listing['address'] )
				);
			}
			
			// Details
			$heading = $listing['address'] . ', ' . $listing['city'];
			$html .= '<h3 style="color: #2d3748; margin-top: 15px;">' . self::listing_anchor( $heading, $listing['id'] ) . "</h3>\n";
			$html .= sprintf( "<p style='font-size: 24px; color: #4299e1; font-weight: bold;'>$%s</p>\n",
				number_format( $listing['price'] )
			);
			if ( $land ) {
				$html .= sprintf(
					"<p>Raw land%s</p>\n",
					! empty( $listing['lot_size'] ) ? ' | ' . esc_html( $listing['lot_size'] ) : ''
				);
			} else {
				$html .= sprintf( "<p>%d beds | %.1f baths%s</p>\n",
					$listing['beds'],
					$listing['baths'],
					! empty( $listing['garage'] ) ? ' | ' . esc_html( $listing['garage'] ) : ''
				);
			}
			
			// Why line
			$html .= sprintf( 
				"<p style='background: #ebf8ff; padding: 10px; border-left: 3px solid #4299e1;'><strong>Why we sent this:</strong> %s</p>\n",
				esc_html( $why )
			);
			
			// View button
			$html .= sprintf( 
				"<p><a href='%s' style='display: inline-block; background: #4299e1; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 4px;'>View Details</a></p>\n",
				esc_url( home_url( '/property/' . $listing['id'] ) )
			);
			
			$html .= "</div>\n";
		}
		
		$saved_label = 'Your saved homes';
		if ( class_exists( 'Housemajik_Saved' ) && method_exists( 'Housemajik_Saved', 'page_link_label' ) ) {
			$saved_items = Housemajik_Saved::homes_for_buyer( '', isset( $alert['email'] ) ? $alert['email'] : '' );
			$saved_label = Housemajik_Saved::page_link_label( $saved_items, true );
		}

		// Footer
		$html .= sprintf( 
			"<p style='margin-top: 30px; font-size: 12px; color: #718096;'>%s<br>%s<br><a href='%s'>%s</a> | <a href='%s'>Manage your alerts</a> | <a href='%s'>Unsubscribe</a></p>\n",
			get_option( 'housemajik_broker_name', 'Suzanne Gonzalez' ),
			get_option( 'housemajik_brokerage_name', 'Keys of Dreams Brokery' ),
			esc_url( Housemajik_Saved::page_url() ),
			esc_html( $saved_label ),
			home_url( '/manage-alerts/' ),
			home_url( '/unsubscribe/?email=' . urlencode( $alert['email'] ) )
		);
		
		$html .= "</body></html>";
		
		return $html;
	}

	/**
	 * Broker-only: a registered buyer changed a search that matters.
	 */
	public static function send_search_updated( $buyer, $search_params, $changes ) {
		$name    = isset( $buyer['name'] ) ? trim( (string) $buyer['name'] ) : 'Buyer';
		$email   = isset( $buyer['email'] ) ? $buyer['email'] : '';
		$subject = sprintf( 'Search updated: %s', $name );
		$message = self::build_search_updated_html( $buyer, $search_params, $changes );
		$headers = is_email( $email ) ? array( 'Reply-To: ' . $email ) : array();
		return self::send_to_brokers( $subject, $message, $headers );
	}

	/**
	 * Broker-only Friday recap.
	 */
	public static function send_weekly_activity( $buyers, $since, $until ) {
		$week_of = $since instanceof DateTimeInterface ? $since->format( 'M j' ) : '';
		$subject = $week_of !== ''
			? 'Weekly buyer activity — week of ' . $week_of
			: 'Weekly buyer activity';
		$message = self::build_weekly_activity_html( $buyers, $since, $until );
		return self::send_to_brokers( $subject, $message );
	}

	private static function build_search_updated_html( $buyer, $search_params, $changes ) {
		$name  = isset( $buyer['name'] ) ? $buyer['name'] : 'Buyer';
		$email = isset( $buyer['email'] ) ? $buyer['email'] : '';
		$html  = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>\n";
		$html .= "<h2 style='color: #4a5568;'>Search updated</h2>\n";
		$html .= "<p>A registered buyer changed a search that matters.</p>\n";
		$html .= "<div style='background: #edf2f7; padding: 15px; margin: 20px 0;'>\n";
		$html .= sprintf( "<p><strong>Name:</strong> %s</p>\n", esc_html( $name ) );
		if ( is_email( $email ) ) {
			$html .= sprintf( "<p><strong>Email:</strong> <a href='mailto:%s'>%s</a></p>\n", esc_attr( $email ), esc_html( $email ) );
		}
		$html .= "</div>\n";
		$html .= "<h3 style='color: #2d3748;'>What changed</h3>\n<ul>\n";
		foreach ( $changes as $change ) {
			$html .= sprintf(
				"<li><strong>%s:</strong> %s → %s</li>\n",
				esc_html( $change['label'] ),
				esc_html( $change['from'] ),
				esc_html( $change['to'] )
			);
		}
		$html .= "</ul>\n";
		$html .= "<h3 style='color: #2d3748;'>Their search now</h3>\n";
		$html .= self::search_criteria_html( $search_params );
		$html .= "<p style='margin-top: 30px; font-size: 12px; color: #718096;'>The buyer was not emailed. Sent " . current_time( 'mysql' ) . ".</p>\n";
		$html .= "</body></html>";
		return $html;
	}

	private static function build_weekly_activity_html( $buyers, $since, $until ) {
		$from = $since instanceof DateTimeInterface ? $since->format( 'M j' ) : '';
		$to   = $until instanceof DateTimeInterface ? $until->format( 'M j, Y' ) : '';
		$html = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>\n";
		$html .= "<h2 style='color: #4a5568;'>Weekly buyer activity</h2>\n";
		if ( $from !== '' && $to !== '' ) {
			$html .= sprintf( "<p>%s – %s (Phoenix).</p>\n", esc_html( $from ), esc_html( $to ) );
		}
		if ( empty( $buyers ) ) {
			$html .= "<p>No registered buyers searched this week.</p>\n";
			$html .= "</body></html>";
			return $html;
		}

		$html .= sprintf( "<p><strong>%d</strong> registered buyer%s searched this week.</p>\n", count( $buyers ), count( $buyers ) === 1 ? '' : 's' );
		foreach ( $buyers as $buyer ) {
			$name  = isset( $buyer['name'] ) ? $buyer['name'] : 'Buyer';
			$email = isset( $buyer['email'] ) ? $buyer['email'] : '';
			$html .= "<div style='background: #f7fafc; padding: 15px; margin: 16px 0; border-left: 4px solid #847252;'>\n";
			$html .= sprintf( "<p><strong>%s</strong> &lt;<a href='mailto:%s'>%s</a>&gt; — %d search%s</p>\n", esc_html( $name ), esc_attr( $email ), esc_html( $email ), (int) $buyer['searches'], (int) $buyer['searches'] === 1 ? '' : 'es' );
			if ( ! empty( $buyer['changes'] ) ) {
				$html .= "<p><strong>What moved:</strong></p>\n<ul>\n";
				foreach ( $buyer['changes'] as $change ) {
					$html .= sprintf(
						"<li><strong>%s:</strong> %s → %s</li>\n",
						esc_html( $change['label'] ),
						esc_html( $change['from'] ),
						esc_html( $change['to'] )
					);
				}
				$html .= "</ul>\n";
			} else {
				$html .= "<p>Same search as the start of the week.</p>\n";
			}
			$latest = isset( $buyer['latest'] ) ? $buyer['latest'] : array();
			$html .= self::search_criteria_html( $latest );
			$html .= "</div>\n";
		}
		$html .= "<p style='margin-top: 30px; font-size: 12px; color: #718096;'>Buyers were not emailed this report.</p>\n";
		$html .= "</body></html>";
		return $html;
	}

	private static function search_criteria_html( $search_params ) {
		$search_params = is_array( $search_params ) ? $search_params : array();
		$html          = "<div style='background: #fff; padding: 12px 15px; border: 1px solid #e2e8f0;'>\n";
		if ( ! empty( $search_params['must_have'] ) ) {
			$html .= "<p><strong>Must have:</strong><br>" . nl2br( esc_html( $search_params['must_have'] ) ) . "</p>\n";
		}
		if ( ! empty( $search_params['would_like'] ) ) {
			$html .= "<p><strong>Would like:</strong><br>" . nl2br( esc_html( $search_params['would_like'] ) ) . "</p>\n";
		}
		if ( ! empty( $search_params['never'] ) ) {
			$html .= "<p><strong>Never:</strong><br>" . nl2br( esc_html( $search_params['never'] ) ) . "</p>\n";
		}
		if ( ! empty( $search_params['location'] ) ) {
			$html .= sprintf( "<p><strong>Cities:</strong> %s</p>\n", esc_html( Housemajik_Locations::display( $search_params['location'] ) ) );
		}
		if ( ! empty( $search_params['land_only'] ) ) {
			$html .= "<p><strong>Looking for:</strong> Land only</p>\n";
		}
		if ( ! empty( $search_params['max_price'] ) ) {
			$html .= sprintf( "<p><strong>Max price:</strong> $%s</p>\n", number_format( (int) $search_params['max_price'] ) );
		}
		if ( empty( $search_params['land_only'] ) && ! empty( $search_params['beds'] ) ) {
			$mode = ( isset( $search_params['beds_mode'] ) && $search_params['beds_mode'] === 'exactly' ) ? 'exactly' : 'at least';
			$html .= sprintf( "<p><strong>Beds:</strong> %s %d</p>\n", esc_html( $mode ), (int) $search_params['beds'] );
		}
		if ( empty( $search_params['land_only'] ) && ! empty( $search_params['baths'] ) ) {
			$mode  = ( isset( $search_params['baths_mode'] ) && $search_params['baths_mode'] === 'exactly' ) ? 'exactly' : 'at least';
			$baths = rtrim( rtrim( number_format( (float) $search_params['baths'], 1, '.', '' ), '0' ), '.' );
			$html .= sprintf( "<p><strong>Baths:</strong> %s %s</p>\n", esc_html( $mode ), esc_html( $baths ) );
		}
		if ( empty( $search_params['land_only'] ) && ! empty( $search_params['garage'] ) && $search_params['garage'] !== 'dont_care' ) {
			$html .= sprintf( "<p><strong>Garage:</strong> %s</p>\n", $search_params['garage'] === 'yes' ? 'Yes' : 'No' );
		}
		if ( ! empty( $search_params['acres'] ) && (float) $search_params['acres'] > 0 ) {
			$html .= sprintf(
				"<p><strong>Minimum acreage:</strong> at least %s</p>\n",
				esc_html( Housemajik_Security::acres_phrase( $search_params['acres'] ) )
			);
		}
		$html .= "</div>\n";
		return $html;
	}
}
