<?php
/**
 * One-step search relaxations when hard filters return nothing.
 */
class Housemajik_Suggest {

	/**
	 * First slight change that actually opens listings.
	 *
	 * @param array    $params  Sanitized search params.
	 * @param callable $fetcher function( array $filters ): array.
	 * @param bool     $stop_on_first Stop after the first hit (ARMLS).
	 * @return array|null
	 */
	public static function from_empty( $params, $fetcher, $stop_on_first = false ) {
		$strict     = self::displayable( call_user_func( $fetcher, $params ), $params );
		$strict_ids = self::listing_ids( $strict );

		foreach ( self::candidates( $params ) as $candidate ) {
			$listings = self::displayable(
				call_user_func( $fetcher, $candidate['filters'] ),
				$candidate['filters']
			);
			if ( ! $listings ) {
				continue;
			}

			$added = array();
			foreach ( $listings as $listing ) {
				$id = isset( $listing['id'] ) ? (string) $listing['id'] : '';
				if ( $id === '' || ! isset( $strict_ids[ $id ] ) ) {
					$added[] = $listing;
				}
			}

			if ( ! $added ) {
				continue;
			}

			$count = count( $listings );
			$land  = class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $params );
			if ( $land ) {
				$cta = $count === 1 ? 'Show me that land' : sprintf( 'Show me those %d parcels', $count );
			} else {
				$cta = $count === 1 ? 'Show that home' : sprintf( 'Show those %d homes', $count );
			}
			return array(
				'count'      => $count,
				'label'      => $candidate['label']( $count ),
				'cta'        => $cta,
				'apply'      => $candidate['apply'],
				'listings'   => $listings,
				'sacrificed' => self::sacrifice_list( $params, $candidate['filters'] ),
			);
		}

		return self::nearest( $params, $fetcher );
	}

	/**
	 * Same keep-or-drop rule as the search results: Must must be in the facts.
	 */
	private static function displayable( $listings, $params ) {
		$out = array();
		foreach ( (array) $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			if ( class_exists( 'Housemajik_Tradeoff' ) && ! Housemajik_Tradeoff::has_honest_match( array( $listing ), $params ) ) {
				continue;
			}
			$out[] = $listing;
		}
		return $out;
	}

	private static function listing_ids( $listings ) {
		$ids = array();
		foreach ( $listings as $listing ) {
			if ( ! empty( $listing['id'] ) ) {
				$ids[ (string) $listing['id'] ] = true;
			}
		}
		return $ids;
	}

	/**
	 * Slightest-first relaxations. One change each.
	 */
	private static function candidates( $params ) {
		$city = isset( $params['location'] ) ? Housemajik_Locations::display( $params['location'] ) : '';
		$out  = array();
		$land = class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $params );

		if ( ! $land && isset( $params['beds_mode'] ) && $params['beds_mode'] === 'exactly' && ! empty( $params['beds'] ) ) {
			$out[] = self::make_candidate(
				$params,
				array( 'beds_mode' => 'at_least' ),
				function( $count ) use ( $params ) {
					return self::lead(
						$count,
						sprintf(
							'%s if you allow at least %s bedrooms instead of exactly %s.',
							self::homes_phrase( $count ),
							self::beds_word( $params['beds'] ),
							self::beds_word( $params['beds'] )
						)
					);
				}
			);
		}

		if ( ! $land && isset( $params['baths_mode'] ) && $params['baths_mode'] === 'exactly' && ! empty( $params['baths'] ) ) {
			$out[] = self::make_candidate(
				$params,
				array( 'baths_mode' => 'at_least' ),
				function( $count ) use ( $params ) {
					return self::lead(
						$count,
						sprintf(
							'%s if you allow at least %s bathrooms instead of exactly %s.',
							self::homes_phrase( $count ),
							self::baths_word( $params['baths'] ),
							self::baths_word( $params['baths'] )
						)
					);
				}
			);
		}

		if ( ! $land && ! empty( $params['garage'] ) && $params['garage'] !== 'dont_care' ) {
			$wanted_garage = $params['garage'] === 'yes';
			$out[] = self::make_candidate(
				$params,
				array( 'garage' => 'dont_care' ),
				function( $count ) use ( $wanted_garage ) {
					$clause = $wanted_garage
						? 'if a garage is optional.'
						: 'if a garage is allowed.';
					return self::lead( $count, self::homes_phrase( $count ) . ' ' . $clause );
				}
			);
		}

		if ( ! $land && ! empty( $params['beds'] ) && (float) $params['beds'] > 1 ) {
			$next_beds = max( 1, (int) $params['beds'] - 1 );
			$out[] = self::make_candidate(
				$params,
				array(
					'beds'      => $next_beds,
					'beds_mode' => 'at_least',
				),
				function( $count ) use ( $next_beds ) {
					return self::lead(
						$count,
						sprintf(
							'%s if you can accept at least %s bedrooms.',
							self::homes_phrase( $count ),
							self::beds_word( $next_beds )
						)
					);
				}
			);
		}

		if ( ! $land && ! empty( $params['baths'] ) && (float) $params['baths'] > 1 ) {
			$next_baths = max( 1, (float) $params['baths'] - 0.5 );
			$out[] = self::make_candidate(
				$params,
				array(
					'baths'      => $next_baths,
					'baths_mode' => 'at_least',
				),
				function( $count ) use ( $next_baths ) {
					return self::lead(
						$count,
						sprintf(
							'%s if you can accept at least %s bathrooms.',
							self::homes_phrase( $count ),
							self::baths_word( $next_baths )
						)
					);
				}
			);
		}

		if ( ! empty( $params['acres'] ) && (float) $params['acres'] > 0.5 ) {
			$next_acres = (float) $params['acres'] > 1.5
				? max( 0.5, (float) $params['acres'] - 1 )
				: max( 0.5, (float) $params['acres'] - 0.5 );
			$out[]      = self::make_candidate(
				$params,
				array( 'acres' => $next_acres ),
				function( $count ) use ( $next_acres, $land ) {
					return self::lead(
						$count,
						sprintf(
							'%s if you can accept at least %s.',
							self::homes_phrase( $count, $land ),
							Housemajik_Security::acres_phrase( $next_acres )
						)
					);
				}
			);
		}

		if ( ! empty( $params['max_price'] ) ) {
			$base = (int) $params['max_price'];
			foreach ( array( 0.10, 0.25, 0.50 ) as $bump ) {
				$raised = self::round_price_up( $base * ( 1 + $bump ) );
				if ( $raised <= $base ) {
					continue;
				}
				$out[] = self::make_candidate(
					$params,
					array( 'max_price' => $raised ),
					function( $count ) use ( $raised, $land ) {
						return self::lead(
							$count,
							sprintf(
								'%s if you can go up to %s.',
								self::homes_phrase( $count, $land ),
								self::money( $raised )
							)
						);
					}
				);
			}
		}

		if ( ! empty( $params['acres'] ) && (float) $params['acres'] > 0 ) {
			$out[] = self::make_candidate(
				$params,
				array( 'acres' => 0 ),
				function( $count ) use ( $land ) {
					return self::lead(
						$count,
						sprintf(
							'%s if acreage can be any size.',
							self::homes_phrase( $count, $land )
						)
					);
				}
			);
		}

		if ( $city !== '' && ! Housemajik_Sample_Data::is_statewide_location( $city ) ) {
			$out[] = self::make_candidate(
				$params,
				array( 'location' => 'Arizona' ),
				function( $count ) use ( $city, $land ) {
					$noun = $land
						? ( $count === 1 ? 'is 1 piece of land' : 'are ' . $count . ' pieces of land' )
						: ( $count === 1 ? 'is 1 home' : 'are ' . $count . ' homes' );
					return sprintf(
						'There %s if you look beyond %s.',
						$noun,
						$city
					);
				}
			);
		}

		return $out;
	}

	/**
	 * Last resort: show the closest listing even if Must have is not in the facts.
	 */
	private static function nearest( $params, $fetcher ) {
		$land  = class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $params );
		$must  = isset( $params['must_have'] ) ? trim( (string) $params['must_have'] ) : '';
		$city  = isset( $params['location'] ) && class_exists( 'Housemajik_Locations' )
			? Housemajik_Locations::display( $params['location'] )
			: ( isset( $params['location'] ) ? $params['location'] : '' );
		$steps = array();

		$open_must = $params;
		$open_must['must_have']  = '';
		$open_must['dream_home'] = '';
		if ( $must !== '' ) {
			$steps[] = array(
				'filters' => $open_must,
				'apply'   => array(),
				'label'   => function( $count ) use ( $land, $must, $city ) {
					$where = $city !== '' ? ' in ' . $city : '';
					return self::lead(
						$count,
						sprintf(
							'%s%s if %s is optional.',
							self::homes_phrase( $count, $land ),
							$where,
							self::preview( $must )
						)
					);
				},
			);
		}

		$open_acres = $open_must;
		$open_acres['acres'] = 0;
		if ( ! empty( $params['acres'] ) ) {
			$steps[] = array(
				'filters' => $open_acres,
				'apply'   => array( 'acres' => '' ),
				'label'   => function( $count ) use ( $land, $city ) {
					$where = $city !== '' ? ' in ' . $city : '';
					return self::lead(
						$count,
						sprintf( '%s%s if acreage can be any size.', self::homes_phrase( $count, $land ), $where )
					);
				},
			);
		}

		if ( $city !== '' && ! Housemajik_Sample_Data::is_statewide_location( $city ) ) {
			$wide            = $open_acres;
			$wide['location'] = 'Arizona';
			$steps[]         = array(
				'filters' => $wide,
				'apply'   => array( 'location' => 'Arizona' ),
				'label'   => function( $count ) use ( $city, $land ) {
					$noun = $land
						? ( $count === 1 ? 'is 1 piece of land' : 'are ' . $count . ' pieces of land' )
						: ( $count === 1 ? 'is 1 home' : 'are ' . $count . ' homes' );
					return sprintf( 'There %s if you look beyond %s.', $noun, $city );
				},
			);
		}

		$open_price = isset( $wide ) ? $wide : $open_acres;
		$open_price['max_price'] = 0;
		$open_price['location']  = 'Arizona';
		$steps[] = array(
			'filters' => $open_price,
			'apply'   => array(
				'location'  => 'Arizona',
				'acres'     => '',
				'max_price' => '',
			),
			'label'   => function( $count ) use ( $land ) {
				return self::lead(
					$count,
					sprintf( '%s closest to this search.', self::homes_phrase( $count, $land ) )
				);
			},
		);

		foreach ( $steps as $step ) {
			$listings = call_user_func( $fetcher, $step['filters'] );
			if ( ! is_array( $listings ) || ! $listings ) {
				continue;
			}
			$count = count( $listings );
			$cta   = $land
				? ( $count === 1 ? 'Show me that land' : sprintf( 'Show me those %d parcels', $count ) )
				: ( $count === 1 ? 'Show that home' : sprintf( 'Show those %d homes', $count ) );
			return array(
				'count'      => $count,
				'label'      => $step['label']( $count ),
				'cta'        => $cta,
				'apply'      => $step['apply'],
				'listings'   => $listings,
				'sacrificed' => self::sacrifice_list( $params, $step['filters'] ),
			);
		}

		return null;
	}

	/**
	 * What the buyer gave up so these closest listings could appear.
	 */
	public static function sacrifice_list( $original, $used ) {
		$original = is_array( $original ) ? $original : array();
		$used     = is_array( $used ) ? $used : array();
		$items    = array();
		$land     = class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $original );

		$must      = isset( $original['must_have'] ) ? trim( (string) $original['must_have'] ) : '';
		$used_must = isset( $used['must_have'] ) ? trim( (string) $used['must_have'] ) : '';
		if ( $must === '' && ! empty( $original['dream_home'] ) ) {
			$must = trim( (string) $original['dream_home'] );
		}
		if ( $used_must === '' && ! empty( $used['dream_home'] ) ) {
			$used_must = trim( (string) $used['dream_home'] );
		}
		if ( $must !== '' && $used_must === '' ) {
			$items[] = self::preview( $must );
		}

		if ( ! $land ) {
			$orig_beds = isset( $original['beds'] ) ? (int) $original['beds'] : 0;
			$used_beds = isset( $used['beds'] ) ? (int) $used['beds'] : 0;
			$orig_bm   = isset( $original['beds_mode'] ) ? $original['beds_mode'] : '';
			$used_bm   = isset( $used['beds_mode'] ) ? $used['beds_mode'] : '';
			if ( $orig_beds > 0 && $orig_bm === 'exactly' && $used_bm === 'at_least' && $used_beds === $orig_beds ) {
				$items[] = 'exactly ' . self::beds_word( $orig_beds ) . ' bedrooms';
			} elseif ( $orig_beds > 0 && $used_beds > 0 && $used_beds < $orig_beds ) {
				$items[] = 'at least ' . self::beds_word( $orig_beds ) . ' bedrooms';
			}

			$orig_baths = isset( $original['baths'] ) ? (float) $original['baths'] : 0;
			$used_baths = isset( $used['baths'] ) ? (float) $used['baths'] : 0;
			$orig_am    = isset( $original['baths_mode'] ) ? $original['baths_mode'] : '';
			$used_am    = isset( $used['baths_mode'] ) ? $used['baths_mode'] : '';
			if ( $orig_baths > 0 && $orig_am === 'exactly' && $used_am === 'at_least' && abs( $used_baths - $orig_baths ) < 0.01 ) {
				$items[] = 'exactly ' . self::baths_word( $orig_baths ) . ' bathrooms';
			} elseif ( $orig_baths > 0 && $used_baths > 0 && $used_baths + 0.01 < $orig_baths ) {
				$items[] = 'at least ' . self::baths_word( $orig_baths ) . ' bathrooms';
			}

			$orig_garage = isset( $original['garage'] ) ? $original['garage'] : '';
			$used_garage = isset( $used['garage'] ) ? $used['garage'] : '';
			if ( $orig_garage && $orig_garage !== 'dont_care' && $used_garage === 'dont_care' ) {
				$items[] = $orig_garage === 'yes' ? 'a garage' : 'no garage';
			}
		}

		$orig_acres = class_exists( 'Housemajik_Security' ) ? Housemajik_Security::acres_value( $original ) : 0.0;
		$used_acres = class_exists( 'Housemajik_Security' ) ? Housemajik_Security::acres_value( $used ) : 0.0;
		if ( $orig_acres > 0 && $used_acres + 0.0001 < $orig_acres ) {
			$items[] = 'at least ' . Housemajik_Security::acres_phrase( $orig_acres );
		}

		$orig_price = isset( $original['max_price'] ) ? (int) $original['max_price'] : 0;
		$used_price = isset( $used['max_price'] ) ? (int) $used['max_price'] : 0;
		if ( $orig_price > 0 && ( $used_price <= 0 || $used_price > $orig_price ) ) {
			$items[] = 'a ' . self::money( $orig_price ) . ' budget';
		}

		$orig_city = isset( $original['location'] ) ? $original['location'] : '';
		$used_city = isset( $used['location'] ) ? $used['location'] : '';
		if (
			$orig_city !== ''
			&& class_exists( 'Housemajik_Sample_Data' )
			&& ! Housemajik_Sample_Data::is_statewide_location( $orig_city )
			&& ( $used_city === '' || Housemajik_Sample_Data::is_statewide_location( $used_city ) )
		) {
			$place = class_exists( 'Housemajik_Locations' )
				? Housemajik_Locations::display( $orig_city )
				: $orig_city;
			$items[] = 'staying in ' . $place;
		}

		return array_values( array_unique( $items ) );
	}

	private static function preview( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( strlen( $text ) > 48 ) {
			return rtrim( substr( $text, 0, 45 ) ) . '…';
		}
		return $text !== '' ? $text : 'that must-have';
	}

	private static function make_candidate( $params, $apply, $label ) {
		$filters = $params;
		foreach ( $apply as $key => $value ) {
			$filters[ $key ] = $value;
		}
		return array(
			'apply'   => $apply,
			'filters' => $filters,
			'label'   => $label,
		);
	}

	private static function lead( $count, $rest ) {
		return ( $count === 1 ? 'There is ' : 'There are ' ) . $rest;
	}

	private static function homes_phrase( $count, $land = false ) {
		if ( $land ) {
			return $count === 1 ? '1 piece of land' : $count . ' pieces of land';
		}
		return $count === 1 ? '1 home' : $count . ' homes';
	}

	private static function beds_word( $n ) {
		$n = (int) $n;
		return (string) $n;
	}

	private static function baths_word( $n ) {
		$n = (float) $n;
		if ( abs( $n - (int) $n ) < 0.01 ) {
			return (string) (int) $n;
		}
		return rtrim( rtrim( number_format( $n, 1, '.', '' ), '0' ), '.' );
	}

	private static function money( $n ) {
		return '$' . number_format( (int) $n );
	}

	private static function round_price_up( $n ) {
		return (int) ( ceil( $n / 25000 ) * 25000 );
	}
}
