<?php
/**
 * Registered-buyer search changes for the broker.
 * Immediate mail on a material change. Weekly recap Friday 3pm Phoenix.
 */
class Housemajik_Buyer_Activity {

	const WEEKLY_HOOK = 'housemajik_weekly_activity';
	const PRICE_FLOOR = 25000;

	/**
	 * Fields that matter to Suzanne. Tiny wording / price noise is ignored.
	 */
	public static function changes( $before, $after ) {
		$before = is_array( $before ) ? $before : array();
		$after  = is_array( $after ) ? $after : array();
		$out    = array();

		$old_cities = self::city_key( isset( $before['location'] ) ? $before['location'] : '' );
		$new_cities = self::city_key( isset( $after['location'] ) ? $after['location'] : '' );
		if ( $old_cities !== $new_cities ) {
			$out['cities'] = array(
				'label' => 'Cities',
				'from'  => self::city_label( $before ),
				'to'    => self::city_label( $after ),
			);
		}

		$old_price = isset( $before['max_price'] ) ? (int) $before['max_price'] : 0;
		$new_price = isset( $after['max_price'] ) ? (int) $after['max_price'] : 0;
		if ( self::price_moved( $old_price, $new_price ) ) {
			$out['max_price'] = array(
				'label' => 'Max price',
				'from'  => self::price_label( $old_price ),
				'to'    => self::price_label( $new_price ),
			);
		}

		$was_land = ! empty( $before['land_only'] );
		$now_land = ! empty( $after['land_only'] );
		if ( $was_land !== $now_land ) {
			$out['land_only'] = array(
				'label' => 'Looking for',
				'from'  => $was_land ? 'Land only' : 'A house',
				'to'    => $now_land ? 'Land only' : 'A house',
			);
		}

		if ( ! $now_land && ( self::int_changed( $before, $after, 'beds' ) || self::mode_changed( $before, $after, 'beds_mode' ) ) ) {
			$out['beds'] = array(
				'label' => 'Bedrooms',
				'from'  => self::rooms_label( $before, 'beds', 'beds_mode' ),
				'to'    => self::rooms_label( $after, 'beds', 'beds_mode' ),
			);
		}

		if ( ! $now_land && ( self::float_changed( $before, $after, 'baths' ) || self::mode_changed( $before, $after, 'baths_mode' ) ) ) {
			$out['baths'] = array(
				'label' => 'Bathrooms',
				'from'  => self::rooms_label( $before, 'baths', 'baths_mode' ),
				'to'    => self::rooms_label( $after, 'baths', 'baths_mode' ),
			);
		}

		$old_garage = isset( $before['garage'] ) ? (string) $before['garage'] : 'dont_care';
		$new_garage = isset( $after['garage'] ) ? (string) $after['garage'] : 'dont_care';
		if ( ! $now_land && $old_garage !== $new_garage ) {
			$out['garage'] = array(
				'label' => 'Garage',
				'from'  => self::garage_label( $old_garage ),
				'to'    => self::garage_label( $new_garage ),
			);
		}

		if ( self::float_changed( $before, $after, 'acres' ) ) {
			$out['acres'] = array(
				'label' => 'Minimum acreage',
				'from'  => self::acres_label( $before ),
				'to'    => self::acres_label( $after ),
			);
		}

		foreach ( array( 'must_have' => 'Must have', 'would_like' => 'Would like', 'never' => 'Never' ) as $key => $label ) {
			$old = self::norm_text( isset( $before[ $key ] ) ? $before[ $key ] : '' );
			$new = self::norm_text( isset( $after[ $key ] ) ? $after[ $key ] : '' );
			if ( $old !== $new ) {
				$out[ $key ] = array(
					'label' => $label,
					'from'  => self::preview_text( isset( $before[ $key ] ) ? $before[ $key ] : '' ),
					'to'    => self::preview_text( isset( $after[ $key ] ) ? $after[ $key ] : '' ),
				);
			}
		}

		return $out;
	}

	public static function is_material( $before, $after ) {
		return ! empty( self::changes( $before, $after ) );
	}

	/**
	 * Latest named buyer on this browser session, if they registered.
	 */
	public static function buyer_for_session( $session_id ) {
		$session_id = (string) $session_id;
		if ( $session_id === '' ) {
			return null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_sessions';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT email, name, last_name FROM $table
				WHERE session_id = %s AND agent_id = %s AND registered = 1
				AND email IS NOT NULL AND email != ''
				ORDER BY created_at DESC LIMIT 1",
				$session_id,
				Housemajik_Agent::id()
			)
		);

		if ( ! $row || ! is_email( $row->email ) ) {
			return null;
		}

		return array(
			'email'     => $row->email,
			'name'      => $row->name ? $row->name : 'Buyer',
			'last_name' => $row->last_name ? $row->last_name : '',
		);
	}

	/**
	 * Most recent search on this session, or empty.
	 */
	public static function latest_params( $session_id ) {
		$session_id = (string) $session_id;
		if ( $session_id === '' ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_sessions';
		$json  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT search_params FROM $table
				WHERE session_id = %s AND agent_id = %s
				ORDER BY created_at DESC, id DESC LIMIT 1",
				$session_id,
				Housemajik_Agent::id()
			)
		);

		$params = json_decode( (string) $json, true );
		return is_array( $params ) ? $params : array();
	}

	/**
	 * Mail the broker only when this registered buyer changed something that matters.
	 */
	public static function notify_if_changed( $buyer, $before, $after ) {
		if ( ! is_array( $buyer ) || empty( $buyer['email'] ) || ! is_email( $buyer['email'] ) ) {
			return false;
		}
		if ( empty( $before ) ) {
			return false;
		}

		$changes = self::changes( $before, $after );
		if ( empty( $changes ) ) {
			return false;
		}

		return Housemajik_Email::send_search_updated( $buyer, $after, $changes );
	}

	public static function next_friday_3pm_phoenix() {
		$tz     = new DateTimeZone( 'America/Phoenix' );
		$now    = new DateTimeImmutable( 'now', $tz );
		$target = $now->setTime( 15, 0, 0 );
		$weekday = (int) $target->format( 'N' );
		if ( $weekday !== 5 ) {
			$target = $target->modify( 'next friday' )->setTime( 15, 0, 0 );
		} elseif ( $now >= $target ) {
			$target = $target->modify( '+7 days' );
		}
		return $target->getTimestamp();
	}

	public static function maybe_schedule_weekly() {
		if ( ! wp_next_scheduled( self::WEEKLY_HOOK ) ) {
			wp_schedule_event( self::next_friday_3pm_phoenix(), 'weekly', self::WEEKLY_HOOK );
		}
	}

	public static function unschedule_weekly() {
		$timestamp = wp_next_scheduled( self::WEEKLY_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::WEEKLY_HOOK );
		}
	}

	/**
	 * Friday recap of registered buyers who searched this week.
	 */
	public static function run_weekly_report() {
		$tz    = new DateTimeZone( 'America/Phoenix' );
		$until = new DateTimeImmutable( 'now', $tz );
		$since = $until->modify( '-7 days' );

		$buyers = self::weekly_buyers( $since->format( 'Y-m-d H:i:s' ), $until->format( 'Y-m-d H:i:s' ) );
		$sent   = Housemajik_Email::send_weekly_activity( $buyers, $since, $until );

		update_option(
			'housemajik_last_weekly_report',
			array(
				'ok'      => (bool) $sent,
				'time'    => current_time( 'mysql' ),
				'buyers'  => count( $buyers ),
				'since'   => $since->format( 'Y-m-d H:i:s' ),
			),
			false
		);

		return $sent;
	}

	public static function weekly_buyers( $since, $until ) {
		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_sessions';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT email, name, last_name, search_params, created_at
				FROM $table
				WHERE agent_id = %s
				AND created_at >= %s AND created_at <= %s
				AND email IS NOT NULL AND email != ''
				ORDER BY email ASC, created_at ASC, id ASC",
				Housemajik_Agent::id(),
				$since,
				$until
			),
			ARRAY_A
		);

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$email = isset( $row['email'] ) ? $row['email'] : '';
			if ( ! is_email( $email ) ) {
				continue;
			}
			if ( ! isset( $grouped[ $email ] ) ) {
				$grouped[ $email ] = array(
					'email'     => $email,
					'name'      => ! empty( $row['name'] ) ? $row['name'] : 'Buyer',
					'last_name' => isset( $row['last_name'] ) ? $row['last_name'] : '',
					'searches'  => 0,
					'first'     => array(),
					'latest'    => array(),
					'changes'   => array(),
				);
			}
			$params = json_decode( isset( $row['search_params'] ) ? $row['search_params'] : '', true );
			if ( ! is_array( $params ) ) {
				$params = array();
			}
			$grouped[ $email ]['searches']++;
			if ( empty( $grouped[ $email ]['first'] ) ) {
				$grouped[ $email ]['first'] = $params;
			} else {
				$step = self::changes( $grouped[ $email ]['latest'], $params );
				foreach ( $step as $key => $change ) {
					$grouped[ $email ]['changes'][ $key ] = $change;
				}
			}
			$grouped[ $email ]['latest'] = $params;
		}

		return array_values( $grouped );
	}

	private static function price_moved( $old, $new ) {
		if ( $old === $new ) {
			return false;
		}
		if ( $old === 0 || $new === 0 ) {
			return true;
		}
		return abs( $new - $old ) >= self::PRICE_FLOOR;
	}

	private static function int_changed( $before, $after, $key ) {
		$old = isset( $before[ $key ] ) ? (int) $before[ $key ] : 0;
		$new = isset( $after[ $key ] ) ? (int) $after[ $key ] : 0;
		return $old !== $new;
	}

	private static function float_changed( $before, $after, $key ) {
		$old = isset( $before[ $key ] ) ? (float) $before[ $key ] : 0.0;
		$new = isset( $after[ $key ] ) ? (float) $after[ $key ] : 0.0;
		return abs( $old - $new ) > 0.05;
	}

	private static function mode_changed( $before, $after, $key ) {
		$old = isset( $before[ $key ] ) ? (string) $before[ $key ] : 'at_least';
		$new = isset( $after[ $key ] ) ? (string) $after[ $key ] : 'at_least';
		return $old !== $new;
	}

	private static function city_key( $raw ) {
		$parts = class_exists( 'Housemajik_Locations' )
			? Housemajik_Locations::parse( $raw )
			: preg_split( '/\s*,\s*/', (string) $raw );
		$parts = array_map( 'strtolower', array_map( 'trim', (array) $parts ) );
		$parts = array_values( array_filter( $parts ) );
		sort( $parts, SORT_STRING );
		return implode( ',', $parts );
	}

	private static function city_label( $params ) {
		$raw = isset( $params['location'] ) ? $params['location'] : '';
		if ( class_exists( 'Housemajik_Locations' ) ) {
			$label = Housemajik_Locations::display( $raw );
			return $label !== '' ? $label : '—';
		}
		return $raw !== '' ? $raw : '—';
	}

	private static function price_label( $price ) {
		$price = (int) $price;
		return $price > 0 ? '$' . number_format( $price ) : '—';
	}

	private static function rooms_label( $params, $count_key, $mode_key ) {
		$count = isset( $params[ $count_key ] ) ? $params[ $count_key ] : 0;
		$mode  = isset( $params[ $mode_key ] ) && $params[ $mode_key ] === 'exactly' ? 'exactly' : 'at least';
		if ( $count_key === 'baths' ) {
			$n = rtrim( rtrim( number_format( (float) $count, 1, '.', '' ), '0' ), '.' );
			return $mode . ' ' . $n;
		}
		return $mode . ' ' . (int) $count;
	}

	private static function acres_label( $params ) {
		$n = isset( $params['acres'] ) ? (float) $params['acres'] : 0.0;
		if ( $n <= 0 ) {
			return 'Any';
		}
		return 'At least ' . Housemajik_Security::acres_phrase( $n );
	}

	private static function garage_label( $value ) {
		if ( $value === 'yes' ) {
			return 'Yes';
		}
		if ( $value === 'no' ) {
			return 'No';
		}
		return 'Does not matter';
	}

	private static function norm_text( $text ) {
		$text = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $text ) ) );
		return $text;
	}

	private static function preview_text( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( $text === '' ) {
			return '—';
		}
		if ( strlen( $text ) > 160 ) {
			return substr( $text, 0, 157 ) . '...';
		}
		return $text;
	}
}
