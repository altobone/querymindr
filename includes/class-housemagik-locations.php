<?php
/**
 * Search cities for the Where field.
 * Broker backend can replace the list via housemajik_search_cities.
 */
class Housemajik_Locations {

	public static function defaults() {
		$cities = array(
			'Anthem',
			'Black Canyon City',
			'Carefree',
			'Cave Creek',
			'Desert Hills',
			'Glendale',
			'New River',
			'Paradise Valley',
			'Peoria',
			'Prescott',
			'Scottsdale',
		);
		sort( $cities, SORT_NATURAL | SORT_FLAG_CASE );
		return $cities;
	}

	/**
	 * Add Prescott to a stored city list that predates land search.
	 */
	public static function maybe_upgrade() {
		$stored = get_option( 'housemajik_search_cities', array() );
		if ( empty( $stored ) ) {
			return;
		}
		if ( is_string( $stored ) ) {
			$stored = preg_split( '/\r\n|\r|\n/', $stored );
		}
		if ( ! is_array( $stored ) ) {
			return;
		}
		foreach ( $stored as $city ) {
			if ( strcasecmp( trim( (string) $city ), 'Prescott' ) === 0 ) {
				return;
			}
		}
		$stored[] = 'Prescott';
		update_option( 'housemajik_search_cities', $stored, false );
	}

	public static function all() {
		$stored = get_option( 'housemajik_search_cities', array() );
		if ( is_string( $stored ) ) {
			$stored = preg_split( '/\r\n|\r|\n/', $stored );
		}

		$cities = array();
		if ( is_array( $stored ) ) {
			foreach ( $stored as $city ) {
				$city = trim( (string) $city );
				if ( $city !== '' ) {
					$cities[] = $city;
				}
			}
		}

		if ( empty( $cities ) ) {
			$cities = self::defaults();
		}

		$cities = array_values( array_unique( $cities ) );
		sort( $cities, SORT_NATURAL | SORT_FLAG_CASE );
		return $cities;
	}

	/**
	 * Split a stored location string or array into city names.
	 */
	public static function parse( $raw ) {
		if ( is_array( $raw ) ) {
			$parts = $raw;
		} else {
			$parts = preg_split( '/\s*,\s*/', (string) $raw );
		}

		$out = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( $part !== '' ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	public static function display( $raw ) {
		$parts = self::parse( $raw );
		$count = count( $parts );
		if ( $count === 0 ) {
			return '';
		}
		if ( $count === 1 ) {
			return $parts[0];
		}
		if ( $count === 2 ) {
			return $parts[0] . ' and ' . $parts[1];
		}
		$last = array_pop( $parts );
		return implode( ', ', $parts ) . ', and ' . $last;
	}

	public static function is_statewide( $raw ) {
		$parts = self::parse( $raw );
		return count( $parts ) === 1 && in_array( strtolower( $parts[0] ), array( 'arizona', 'az', 'any' ), true );
	}

	/**
	 * Keep allowed cities, plus Arizona for empty-state relaxation.
	 */
	public static function sanitize( $raw ) {
		$parts   = self::parse( $raw );
		$allowed = self::all();
		$lookup  = array();
		foreach ( $allowed as $city ) {
			$lookup[ strtolower( $city ) ] = $city;
		}

		$out = array();
		foreach ( $parts as $part ) {
			$key = strtolower( $part );
			if ( in_array( $key, array( 'arizona', 'az', 'any' ), true ) ) {
				return 'Arizona';
			}
			if ( isset( $lookup[ $key ] ) ) {
				$out[] = $lookup[ $key ];
			}
		}

		return implode( ', ', array_unique( $out ) );
	}
}
