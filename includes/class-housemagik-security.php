<?php
/**
 * Security utilities.
 */
class Housemajik_Security {

	/**
	 * Verify nonce for AJAX requests.
	 */
	public static function verify_ajax_nonce() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'housemajik_ajax' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
			exit;
		}
	}

	/**
	 * Check honeypot field.
	 */
	public static function check_honeypot() {
		if ( ! empty( $_POST['website'] ) ) {
			wp_send_json_error( array( 'message' => 'Invalid submission.' ), 403 );
			exit;
		}
	}

	/**
	 * Rate limit check.
	 */
	public static function check_rate_limit( $action = 'search' ) {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		$ip = self::get_client_ip();
		$transient_key = 'housemajik_rl2_' . $action . '_' . md5( $ip );
		
		$attempts = get_transient( $transient_key );
		$limit = self::rate_limit_for_action( $action );
		$window = (int) get_option( 'housemajik_rate_limit_window', 3600 );
		
		if ( $attempts === false ) {
			set_transient( $transient_key, 1, $window );
			return true;
		}
		
		if ( $attempts >= $limit ) {
			wp_send_json_error( array( 
				'message' => 'Too many requests. Please try again later.' 
			), 429 );
			exit;
		}
		
		set_transient( $transient_key, $attempts + 1, $window );
		return true;
	}

	/**
	 * Search can be frequent. Register and alert send mail, so they stay low.
	 */
	private static function rate_limit_for_action( $action ) {
		$search = (int) get_option( 'housemajik_rate_limit_searches', 100 );
		$mail   = (int) get_option( 'housemajik_rate_limit_registers', 5 );
		if ( $search < 1 ) {
			$search = 100;
		}
		if ( $mail < 1 ) {
			$mail = 5;
		}

		if ( $action === 'register' || $action === 'alert' ) {
			return $mail;
		}

		return $search;
	}

	/**
	 * True when this address may receive a Register or alert letter.
	 * One send per address per day, and a site-wide daily mail cap.
	 */
	public static function allow_outbound_mail( $email ) {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		$email = strtolower( self::sanitize_email( $email ) );
		if ( ! is_email( $email ) ) {
			return false;
		}

		if ( get_transient( 'housemajik_mail_' . md5( $email ) ) ) {
			return false;
		}

		$today = gmdate( 'Y-m-d' );
		if ( get_option( 'housemajik_mail_usage_date', '' ) !== $today ) {
			update_option( 'housemajik_mail_usage_date', $today, false );
			update_option( 'housemajik_mail_usage_today', 0, false );
		}

		$used = (int) get_option( 'housemajik_mail_usage_today', 0 );
		$cap  = (int) get_option( 'housemajik_mail_daily_cap', 50 );
		if ( $cap < 1 ) {
			$cap = 50;
		}

		return $used < $cap;
	}

	/**
	 * Record that a Register or alert letter was attempted for this address.
	 */
	public static function record_outbound_mail( $email ) {
		$email = strtolower( self::sanitize_email( $email ) );
		if ( ! is_email( $email ) ) {
			return;
		}

		$window = (int) get_option( 'housemajik_mail_address_window', DAY_IN_SECONDS );
		if ( $window < 1 ) {
			$window = DAY_IN_SECONDS;
		}

		set_transient( 'housemajik_mail_' . md5( $email ), 1, $window );

		$today = gmdate( 'Y-m-d' );
		if ( get_option( 'housemajik_mail_usage_date', '' ) !== $today ) {
			update_option( 'housemajik_mail_usage_date', $today, false );
			update_option( 'housemajik_mail_usage_today', 1, false );
			return;
		}

		update_option( 'housemajik_mail_usage_today', (int) get_option( 'housemajik_mail_usage_today', 0 ) + 1, false );
	}

	/**
	 * True when this visitor may call Claude for a search ranking.
	 * Cron and administrators skip the per-IP cap. The site daily cap still applies.
	 */
	public static function allow_ai_for_request() {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		$limit = (int) get_option( 'housemajik_ai_ip_daily', 30 );
		if ( $limit < 1 ) {
			$limit = 30;
		}

		return (int) get_transient( self::ai_ip_key() ) < $limit;
	}

	/**
	 * Count a frontend Claude ranking against this IP for today.
	 */
	public static function record_ai_for_request() {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return;
		}
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		$key = self::ai_ip_key();
		set_transient( $key, (int) get_transient( $key ) + 1, DAY_IN_SECONDS );
	}

	private static function ai_ip_key() {
		return 'housemajik_ai_ip_' . md5( self::get_client_ip() . gmdate( 'Y-m-d' ) );
	}

	/**
	 * Check AI daily usage cap.
	 */
	public static function check_ai_cap() {
		$today = date( 'Y-m-d' );
		$usage_date = get_option( 'housemajik_ai_usage_date', '' );
		$usage_count = (int) get_option( 'housemajik_ai_usage_today', 0 );
		
		// Reset counter if new day
		if ( $usage_date !== $today ) {
			update_option( 'housemajik_ai_usage_date', $today );
			update_option( 'housemajik_ai_usage_today', 0 );
			$usage_count = 0;
		}
		
		$daily_cap = (int) get_option( 'housemajik_ai_daily_cap', 1000 );
		
		if ( $usage_count >= $daily_cap ) {
			return false;
		}
		
		return true;
	}

	/**
	 * Increment AI usage counter.
	 */
	public static function increment_ai_usage() {
		$usage_count = (int) get_option( 'housemajik_ai_usage_today', 0 );
		update_option( 'housemajik_ai_usage_today', $usage_count + 1 );
	}

	/**
	 * Get client IP address.
	 */
	public static function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$ip = trim( (string) $ip );
		if ( $ip !== '' && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}

		return '0.0.0.0';
	}

	/**
	 * Sanitize search parameters.
	 */
	public static function sanitize_search_params( $params ) {
		$must  = isset( $params['must_have'] ) ? sanitize_textarea_field( wp_unslash( $params['must_have'] ) ) : '';
		$want  = isset( $params['would_like'] ) ? sanitize_textarea_field( wp_unslash( $params['would_like'] ) ) : '';
		$never = isset( $params['never'] ) ? sanitize_textarea_field( wp_unslash( $params['never'] ) ) : '';
		if ( $must === '' && $want === '' && ! empty( $params['dream_home'] ) ) {
			$must = sanitize_textarea_field( wp_unslash( $params['dream_home'] ) );
		}
		if ( $never === '' && ! empty( $params['dont_want'] ) ) {
			$never = sanitize_textarea_field( wp_unslash( $params['dont_want'] ) );
		}
		$dream = trim( $must . ( $must !== '' && $want !== '' ? "\n" : '' ) . $want );
		$land  = self::wants_land( $params );

		$beds  = $land ? 0 : ( isset( $params['beds'] ) ? absint( $params['beds'] ) : 0 );
		$baths = $land ? 0 : ( isset( $params['baths'] ) ? floatval( $params['baths'] ) : 0 );
		$garage = $land
			? 'dont_care'
			: ( isset( $params['garage'] ) && in_array( $params['garage'], array( 'yes', 'no', 'dont_care' ), true ) ? $params['garage'] : 'dont_care' );

		return array(
			'must_have'  => $must,
			'would_like' => $want,
			'never'      => $never,
			'dream_home' => $dream,
			'dont_want'  => $never,
			'land_only'  => $land,
			'location' => Housemajik_Locations::sanitize( $params['location'] ?? '' ),
			'beds' => $beds,
			'beds_mode' => isset( $params['beds_mode'] ) && in_array( $params['beds_mode'], array( 'exactly', 'at_least' ) ) ? $params['beds_mode'] : 'exactly',
			'baths' => $baths,
			'baths_mode' => isset( $params['baths_mode'] ) && in_array( $params['baths_mode'], array( 'exactly', 'at_least' ) ) ? $params['baths_mode'] : 'exactly',
			'max_price' => isset( $params['max_price'] ) ? absint( preg_replace( '/[^0-9]/', '', (string) $params['max_price'] ) ) : 0,
			'garage' => $garage,
			'acres' => self::acres_value( $params ),
		);
	}

	/**
	 * Minimum acres from the form. Blank or zero means do not filter.
	 */
	public static function acres_value( $params ) {
		if ( ! is_array( $params ) || ! isset( $params['acres'] ) ) {
			return 0.0;
		}
		$raw = trim( (string) $params['acres'] );
		if ( $raw === '' ) {
			return 0.0;
		}
		$n = (float) preg_replace( '/[^0-9.]/', '', $raw );
		if ( $n < 0 ) {
			return 0.0;
		}
		if ( $n > 10000 ) {
			$n = 10000;
		}
		return round( $n, 2 );
	}

	/**
	 * Acres from a listing lot_size string such as "2.1 acres" or "8,500 sq ft".
	 */
	public static function acres_from_lot_size( $lot_size ) {
		$raw = strtolower( trim( (string) $lot_size ) );
		if ( $raw === '' || $raw === 'condo' ) {
			return 0.0;
		}
		$raw = str_replace( ',', '', $raw );
		if ( preg_match( '/([0-9]*\.?[0-9]+)\s*acres?/', $raw, $match ) ) {
			return (float) $match[1];
		}
		if ( preg_match( '/([0-9]*\.?[0-9]+)\s*sq\s*ft/', $raw, $match ) ) {
			return (float) $match[1] / 43560;
		}
		return 0.0;
	}

	/**
	 * "2 acres" / "1 acre" for copy and mail.
	 */
	public static function acres_phrase( $acres ) {
		$n    = (float) $acres;
		$text = rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' );
		if ( $text === '' ) {
			$text = '0';
		}
		return $text . ( abs( $n - 1 ) < 0.001 ? ' acre' : ' acres' );
	}

	/**
	 * True when the buyer asked for raw land, not a house.
	 */
	public static function wants_land( $params ) {
		if ( ! is_array( $params ) || ! isset( $params['land_only'] ) ) {
			return false;
		}
		$value = $params['land_only'];
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( (string) $value ), array( '1', 'yes', 'on', 'true' ), true );
	}

	/**
	 * Sanitize email.
	 */
	public static function sanitize_email( $email ) {
		return sanitize_email( trim( $email ) );
	}

	/**
	 * One or more inboxes, comma or semicolon separated.
	 */
	public static function sanitize_email_list( $raw ) {
		$parts = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( $parts as $part ) {
			$email = strtolower( trim( $part ) );
			if ( function_exists( 'sanitize_email' ) ) {
				$email = sanitize_email( $email );
			}
			if ( is_email( $email ) ) {
				$out[ $email ] = $email;
			}
		}
		return implode( ', ', $out );
	}

	/**
	 * Sanitize phone.
	 */
	public static function sanitize_phone( $phone ) {
		return sanitize_text_field( trim( $phone ) );
	}

	/**
	 * Get or create session ID.
	 */
	public static function get_session_id() {
		if ( ! empty( $_COOKIE['housemajik_session_id'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['housemajik_session_id'] ) );
		}

		$session_id = wp_generate_uuid4();

		if ( ! headers_sent() ) {
			setcookie(
				'housemajik_session_id',
				$session_id,
				array(
					'expires'  => time() + WEEK_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
			$_COOKIE['housemajik_session_id'] = $session_id;
		}

		return $session_id;
	}

	/**
	 * Remember the page that hosts the search shortcode.
	 */
	public static function set_search_page_url( $url ) {
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return;
		}

		$safe = wp_validate_redirect( $url, false );
		if ( ! $safe ) {
			return;
		}

		if ( get_option( 'housemajik_search_page_url', '' ) !== $safe ) {
			update_option( 'housemajik_search_page_url', $safe, false );
		}

		if ( ! headers_sent() ) {
			setcookie(
				'housemajik_search_url',
				$safe,
				array(
					'expires'  => time() + WEEK_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
			$_COOKIE['housemajik_search_url'] = $safe;
		}
	}

	/**
	 * Persist the current page as the search URL when the shortcode renders.
	 */
	public static function remember_search_page() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$url = get_permalink();
		if ( $url ) {
			self::set_search_page_url( $url );
		}
	}

	/**
	 * URL to return to after a listing detail view.
	 */
	public static function get_search_page_url() {
		$candidates = array();

		if ( ! empty( $_COOKIE['housemajik_search_url'] ) ) {
			$candidates[] = esc_url_raw( wp_unslash( $_COOKIE['housemajik_search_url'] ) );
		}

		$option = get_option( 'housemajik_search_page_url', '' );
		if ( $option ) {
			$candidates[] = esc_url_raw( $option );
		}

		foreach ( $candidates as $candidate ) {
			$safe = wp_validate_redirect( preg_replace( '/#.*$/', '', $candidate ), false );
			if ( $safe && url_to_postid( $safe ) ) {
				return $safe;
			}
		}

		return self::discover_search_page_url();
	}

	/**
	 * Find the published page or post that contains the search shortcode.
	 */
	public static function discover_search_page_url() {
		global $wpdb;

		$id = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_status = 'publish'
			AND post_type IN ('page','post')
			AND (post_content LIKE '%[housemagik%' OR post_content LIKE '%[housemajik%')
			ORDER BY FIELD(post_type,'page','post'), ID ASC
			LIMIT 1"
		);

		if ( $id ) {
			$url = get_permalink( (int) $id );
			if ( $url ) {
				update_option( 'housemajik_search_page_url', esc_url_raw( $url ), false );
				return $url;
			}
		}

		return home_url( '/' );
	}

	public static function maybe_upgrade() {
		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_sessions';
		$col   = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", 'name' ) );
		if ( empty( $col ) ) {
			$wpdb->query( "ALTER TABLE $table ADD name varchar(100) DEFAULT NULL" );
		}
		$last = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", 'last_name' ) );
		if ( empty( $last ) ) {
			$wpdb->query( "ALTER TABLE $table ADD last_name varchar(100) DEFAULT NULL" );
		}
	}

	public static function sanitize_person_name( $name ) {
		$name = trim( preg_replace( '/\s+/', ' ', (string) $name ) );
		$name = sanitize_text_field( $name );
		$name = str_replace( array( "\r", "\n", ';' ), '', $name );
		return substr( $name, 0, 80 );
	}

	public static function posted_names() {
		$first = isset( $_POST['first_name'] ) ? self::sanitize_person_name( wp_unslash( $_POST['first_name'] ) ) : '';
		$last  = isset( $_POST['last_name'] ) ? self::sanitize_person_name( wp_unslash( $_POST['last_name'] ) ) : '';
		if ( $first === '' && ! empty( $_POST['name'] ) ) {
			$legacy = self::sanitize_person_name( wp_unslash( $_POST['name'] ) );
			$first  = self::first_name( $legacy );
		}
		$first = self::first_name( $first );
		$full  = trim( $first . ' ' . $last );
		return array( $first, $last, $full );
	}

	public static function first_name( $name ) {
		$name = trim( preg_replace( '/\s+/', ' ', (string) $name ) );
		if ( $name === '' ) {
			return '';
		}
		$parts = explode( ' ', $name );
		$first = sanitize_text_field( $parts[0] );
		$first = str_replace( array( "\r", "\n", ';', ',' ), '', $first );
		return substr( $first, 0, 40 );
	}

	public static function remember_buyer( $name, $last_name = '' ) {
		$first = self::first_name( $name );
		$last  = self::sanitize_person_name( $last_name );
		if ( $first === '' ) {
			return;
		}

		self::set_buyer_cookie( $first, 365 );

		global $wpdb;
		$session_id = self::get_session_id();
		if ( ! $session_id ) {
			return;
		}
		$wpdb->update(
			$wpdb->prefix . 'housemajik_sessions',
			array(
				'name'      => $first,
				'last_name' => $last,
			),
			array(
				'session_id' => $session_id,
				'agent_id'   => class_exists( 'Housemajik_Agent' ) ? Housemajik_Agent::id() : 'suzanne',
			),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);
	}

	public static function returning_first_name() {
		self::maybe_clear_test_reset();

		if ( ! empty( $_COOKIE['housemajik_buyer_name'] ) ) {
			$first = self::first_name( wp_unslash( $_COOKIE['housemajik_buyer_name'] ) );
			if ( $first !== '' ) {
				return $first;
			}
		}

		global $wpdb;
		$session_id = isset( $_COOKIE['housemajik_session_id'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['housemajik_session_id'] ) ) : '';
		if ( ! $session_id ) {
			return '';
		}

		$name = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}housemajik_sessions WHERE session_id = %s AND name != '' ORDER BY created_at DESC LIMIT 1",
				$session_id
			)
		);

		return self::first_name( $name );
	}

	public static function maybe_clear_test_reset() {
		if ( empty( $_GET['housemajik_reset'] ) ) {
			return;
		}
		self::set_buyer_cookie( '', -1 );
		if ( ! headers_sent() ) {
			setcookie( 'housemajik_lead', '', time() - 3600, '/' );
			setcookie( 'housemajik_registered', '', time() - 3600, '/' );
		}
		unset( $_COOKIE['housemajik_buyer_name'], $_COOKIE['housemajik_lead'], $_COOKIE['housemajik_registered'] );
	}

	private static function set_buyer_cookie( $value, $days ) {
		if ( headers_sent() ) {
			if ( $days > 0 ) {
				$_COOKIE['housemajik_buyer_name'] = $value;
			} else {
				unset( $_COOKIE['housemajik_buyer_name'] );
			}
			return;
		}

		setcookie(
			'housemajik_buyer_name',
			$value,
			array(
				'expires'  => time() + ( (int) $days * DAY_IN_SECONDS ),
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);

		if ( $days > 0 ) {
			$_COOKIE['housemajik_buyer_name'] = $value;
		} else {
			unset( $_COOKIE['housemajik_buyer_name'] );
		}
	}
}
