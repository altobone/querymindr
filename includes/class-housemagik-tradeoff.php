<?php
/**
 * Name the clash when a search cannot be met as one home.
 * Two honest piles beat eight strained matches.
 */
class Housemajik_Tradeoff {

	const PER_PILE = 3;

	/**
	 * @param array    $params          Sanitized search params.
	 * @param array    $strict_listings Homes that already passed hard filters.
	 * @param callable $fetcher         function( array $filters ): array.
	 * @return array|null
	 */
	public static function detect( $params, $strict_listings, $fetcher ) {
		$params          = is_array( $params ) ? $params : array();
		$strict_listings = is_array( $strict_listings ) ? $strict_listings : array();

		if ( $strict_listings && self::has_honest_match( $strict_listings, $params ) ) {
			return null;
		}

		$pools = self::pools( $params, $fetcher );
		$priced = self::from_price_dream( $params, $strict_listings, $pools );
		if ( $priced ) {
			return $priced;
		}
		if ( $strict_listings ) {
			$found = self::from_must_conflict( $params, $strict_listings, $pools );
			if ( $found ) {
				return $found;
			}
		}

		$wide = array_merge(
			isset( $pools['city'] ) && is_array( $pools['city'] ) ? $pools['city'] : array(),
			isset( $pools['state'] ) && is_array( $pools['state'] ) ? $pools['state'] : array()
		);
		if ( self::must_text( $params ) !== '' && ! self::has_honest_match( $wide, $params ) ) {
			return null;
		}

		return self::from_axes( $params, $pools );
	}

	/**
	 * A home is honest when a must is actually in the listing facts.
	 */
	public static function has_honest_match( $listings, $params ) {
		$must = self::must_text( $params );
		if ( $must === '' ) {
			return ! empty( $listings );
		}

		foreach ( $listings as $listing ) {
			if ( self::listing_meets_must( $listing, $must ) && ! self::listing_hits_never( $listing, $params ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * When the must exists, but only above the cap, name that price from listings.
	 */
	private static function from_price_dream( $params, $strict_listings, $pools ) {
		$must = self::must_text( $params );
		$max  = isset( $params['max_price'] ) ? (int) $params['max_price'] : 0;
		if ( $must === '' || $max <= 0 ) {
			return null;
		}

		$dream = self::filter_listings( $pools['city'], array( 'must' => $must ), $params );
		if ( ! $dream ) {
			$dream = self::filter_listings( $pools['state'], array( 'must' => $must ), $params );
		}
		if ( ! $dream ) {
			return null;
		}

		foreach ( $dream as $listing ) {
			if ( isset( $listing['price'] ) && (int) $listing['price'] <= $max ) {
				return null;
			}
		}

		$band = self::price_band( $dream );
		if ( ! $band ) {
			return null;
		}

		$compromises = self::without_ids( $strict_listings, $dream );
		if ( ! $compromises ) {
			$compromises = self::without_ids(
				self::filter_listings( $pools['city'], array( 'max_price' => $max ), $params ),
				$dream
			);
		}
		if ( ! $compromises ) {
			return null;
		}

		$keep = self::must_label( $must );
		$cap  = 'the ' . self::money( $max ) . ' cap';
		return self::payload(
			self::price_dream_sentence( $band, $max, $params ),
			array(
				self::pile( 'Keep ' . $keep, $dream, $keep, $cap ),
				self::pile( 'Keep ' . $cap, $compromises, $cap, $keep ),
			),
			self::is_land( $params ) ? 'What land like this costs' : 'What homes like this cost'
		);
	}

	private static function from_must_conflict( $params, $strict_listings, $pools ) {
		$must = self::must_text( $params );
		if ( $must === '' ) {
			return null;
		}

		$must_homes = self::filter_listings( $pools['city'], array( 'must' => $must ), $params );
		if ( ! $must_homes ) {
			$must_homes = self::filter_listings( $pools['state'], array( 'must' => $must ), $params );
		}
		if ( ! $must_homes ) {
			return null;
		}

		$filter_homes = self::without_ids( $strict_listings, $must_homes );
		if ( ! $filter_homes ) {
			return null;
		}

		$must_homes = self::without_ids( $must_homes, $filter_homes );
		if ( ! $must_homes ) {
			return null;
		}

		$right = self::filter_bundle_phrase( $params );
		if ( $right === '' ) {
			$right = 'the filters you set';
		}

		$keep_must = self::must_label( $must );
		$city      = self::city_label( $params );
		return self::payload(
			self::together_sentence( $city, $keep_must, $right, true ),
			array(
				self::pile( 'Keep ' . $keep_must, $must_homes, $keep_must, $right ),
				self::pile( 'Keep ' . $right, $filter_homes, $right, $keep_must ),
			)
		);
	}

	private static function from_axes( $params, $pools ) {
		$pairs = self::axis_pairs( $params );
		foreach ( $pairs as $pair ) {
			$left_pool  = $pair[0]['id'] === 'city' ? $pools['city'] : $pools['state'];
			$right_pool = $pair[1]['id'] === 'city' ? $pools['city'] : $pools['state'];
			if ( $pair[0]['id'] !== 'city' && $pair[1]['id'] !== 'city' ) {
				$left_pool  = $pools['city'];
				$right_pool = $pools['city'];
			}

			$left  = self::filter_listings( $left_pool, $pair[0], $params );
			$right = self::filter_listings( $right_pool, $pair[1], $params );
			if ( $pair[0]['id'] !== 'city' && ! $left ) {
				$left = self::filter_listings( $pools['state'], $pair[0], $params );
			}
			if ( $pair[1]['id'] !== 'city' && ! $right ) {
				$right = self::filter_listings( $pools['state'], $pair[1], $params );
			}

			if ( ! $left || ! $right || self::share_ids( $left, $right ) ) {
				continue;
			}

			$city     = self::city_label( $params );
			$use_in   = $pair[0]['id'] !== 'city' && $pair[1]['id'] !== 'city';
			$sentence = self::together_sentence( $city, $pair[0]['noun'], $pair[1]['noun'], $use_in );
			return self::payload(
				$sentence,
				array(
					self::pile( 'Keep ' . $pair[0]['noun'], $left, $pair[0]['noun'], $pair[1]['noun'] ),
					self::pile( 'Keep ' . $pair[1]['noun'], $right, $pair[1]['noun'], $pair[0]['noun'] ),
				)
			);
		}

		return null;
	}

	private static function pools( $params, $fetcher ) {
		$city_filters           = $params;
		$city_filters['garage'] = 'dont_care';
		unset( $city_filters['max_price'], $city_filters['beds'], $city_filters['baths'] );

		$city = self::fetch( $fetcher, $city_filters );

		$state_filters             = $city_filters;
		$state_filters['location'] = 'Arizona';
		$state              = $city;
		$city_ids           = self::listing_ids( $city );
		if ( ! Housemajik_Locations::is_statewide( isset( $params['location'] ) ? $params['location'] : '' ) ) {
			foreach ( self::fetch( $fetcher, $state_filters ) as $listing ) {
				$id = self::listing_id( $listing );
				if ( $id !== '' && ! isset( $city_ids[ $id ] ) ) {
					$state[] = $listing;
				}
			}
		}

		return array(
			'city'  => $city,
			'state' => $state,
		);
	}

	private static function fetch( $fetcher, $filters ) {
		$listings = call_user_func( $fetcher, $filters );
		return is_array( $listings ) ? $listings : array();
	}

	private static function axis_pairs( $params ) {
		$axes = self::axes( $params );
		$order = array(
			array( 'must', 'garage' ),
			array( 'must', 'price' ),
			array( 'must', 'beds' ),
			array( 'garage', 'price' ),
			array( 'beds', 'price' ),
			array( 'garage', 'beds' ),
			array( 'city', 'beds' ),
			array( 'city', 'garage' ),
			array( 'city', 'price' ),
		);

		$pairs = array();
		foreach ( $order as $ids ) {
			if ( isset( $axes[ $ids[0] ], $axes[ $ids[1] ] ) ) {
				$pairs[] = array( $axes[ $ids[0] ], $axes[ $ids[1] ] );
			}
		}
		return $pairs;
	}

	private static function axes( $params ) {
		$out  = array();
		$land = class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $params );
		$must = self::must_text( $params );
		if ( $must !== '' ) {
			$preview        = self::preview( $must );
			$out['must']    = array(
				'id'    => 'must',
				'must'  => $must,
				'noun'  => $preview,
				'title' => ( $land ? 'Land with ' : 'Homes with ' ) . $preview,
				'why'   => 'This one has what you said you must have.',
			);
		}

		if ( ! $land && ! empty( $params['garage'] ) && $params['garage'] === 'yes' ) {
			$out['garage'] = array(
				'id'     => 'garage',
				'garage' => true,
				'noun'   => 'a garage',
				'title'  => 'Homes with a garage',
				'why'    => 'This one has a garage.',
			);
		}

		if ( ! empty( $params['max_price'] ) ) {
			$money           = self::money( $params['max_price'] );
			$kind            = $land ? 'land' : 'home';
			$out['price']    = array(
				'id'        => 'price',
				'max_price' => (int) $params['max_price'],
				'noun'      => ( $land ? '' : 'a ' ) . $kind . ' under ' . $money,
				'title'     => ( $land ? 'Land' : 'Homes' ) . ' under ' . $money,
				'why'       => 'This one stays under ' . $money . '.',
			);
		}

		if ( ! empty( $params['acres'] ) && (float) $params['acres'] > 0 ) {
			$acres        = (float) $params['acres'];
			$word         = Housemajik_Security::acres_phrase( $acres );
			$out['acres'] = array(
				'id'    => 'acres',
				'acres' => $acres,
				'noun'  => $word,
				'title' => ( $land ? 'Land with ' : 'Homes with ' ) . $word,
				'why'   => 'This one has at least ' . $word . '.',
			);
		}

		if ( ! $land && ! empty( $params['beds'] ) && (float) $params['beds'] >= 2 ) {
			$beds         = (int) $params['beds'];
			$word         = $beds === 1 ? '1 bedroom' : $beds . ' bedrooms';
			$out['beds']  = array(
				'id'   => 'beds',
				'beds' => $beds,
				'noun' => $word,
				'title' => 'Homes with ' . $word,
				'why'  => 'This one has ' . $word . '.',
			);
		}

		$city = self::city_label( $params );
		if ( $city !== '' ) {
			$out['city'] = array(
				'id'    => 'city',
				'city'  => true,
				'noun'  => $city,
				'title' => ( self::is_land( $params ) ? 'Land in ' : 'Homes in ' ) . $city,
				'why'   => 'This one is in ' . $city . '.',
			);
		}

		return $out;
	}

	private static function filter_listings( $listings, $axis, $params ) {
		$out = array();
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			if ( ! empty( $axis['must'] ) && ! self::listing_meets_must( $listing, $axis['must'] ) ) {
				continue;
			}
			if ( ! empty( $axis['garage'] ) && empty( $listing['garage'] ) ) {
				continue;
			}
			if ( ! empty( $axis['max_price'] ) && (int) $listing['price'] > (int) $axis['max_price'] ) {
				continue;
			}
			if ( ! empty( $axis['beds'] ) && (int) $listing['beds'] < (int) $axis['beds'] ) {
				continue;
			}
			if ( ! empty( $axis['acres'] ) ) {
				$have = Housemajik_Security::acres_from_lot_size( isset( $listing['lot_size'] ) ? $listing['lot_size'] : '' );
				if ( $have + 0.0001 < (float) $axis['acres'] ) {
					continue;
				}
			}
			if ( ! empty( $axis['city'] ) && ! self::listing_in_search_city( $listing, $params ) ) {
				continue;
			}
			$out[] = $listing;
		}
		return $out;
	}

	private static function listing_in_search_city( $listing, $params ) {
		$wanted = isset( $params['location'] ) ? strtolower( (string) $params['location'] ) : '';
		$city   = isset( $listing['city'] ) ? strtolower( (string) $listing['city'] ) : '';
		if ( $wanted === '' || Housemajik_Locations::is_statewide( $wanted ) ) {
			return true;
		}
		foreach ( Housemajik_Locations::parse( $wanted ) as $part ) {
			$part = strtolower( trim( (string) $part ) );
			if ( $part !== '' && strpos( $city, $part ) !== false ) {
				return true;
			}
		}
		return false;
	}

	private static function listing_meets_must( $listing, $must ) {
		$piles = Housemajik_AI::evaluate_piles( $listing, array( 'must_have' => $must ) );
		return isset( $piles['must']['status'] ) && $piles['must']['status'] === 'met';
	}

	private static function listing_hits_never( $listing, $params ) {
		$never = isset( $params['never'] ) ? trim( (string) $params['never'] ) : '';
		if ( $never === '' && ! empty( $params['dont_want'] ) ) {
			$never = trim( (string) $params['dont_want'] );
		}
		if ( $never === '' ) {
			return false;
		}
		$piles = Housemajik_AI::evaluate_piles( $listing, array( 'never' => $never ) );
		return isset( $piles['never']['status'] ) && $piles['never']['status'] === 'hit';
	}

	private static function must_text( $params ) {
		$must = isset( $params['must_have'] ) ? trim( (string) $params['must_have'] ) : '';
		if ( $must === '' && ! empty( $params['dream_home'] ) && empty( $params['would_like'] ) ) {
			$must = trim( (string) $params['dream_home'] );
		}
		return $must;
	}

	private static function filter_bundle_phrase( $params ) {
		$bits = array();
		if ( ! empty( $params['garage'] ) && $params['garage'] === 'yes' ) {
			$bits[] = 'a garage';
		}
		if ( ! empty( $params['beds'] ) ) {
			$n      = (int) $params['beds'];
			$bits[] = $n === 1 ? '1 bedroom' : $n . ' bedrooms';
		}
		if ( ! empty( $params['acres'] ) && (float) $params['acres'] > 0 ) {
			$bits[] = Housemajik_Security::acres_phrase( $params['acres'] );
		}
		if ( ! empty( $params['max_price'] ) ) {
			$money = self::money( $params['max_price'] );
			if ( $bits ) {
				return implode( ' and ', $bits ) . ' under ' . $money;
			}
			return ( self::is_land( $params ) ? 'land under ' : 'a home under ' ) . $money;
		}
		return implode( ' and ', $bits );
	}

	private static function together_sentence( $city, $left, $right, $use_in_city ) {
		$left  = trim( (string) $left );
		$right = trim( (string) $right );
		$many  = $city !== '' && substr_count( $city, ',' ) >= 1;
		if ( $many ) {
			return sprintf( '%s and %s almost never appear together in the cities you chose.', $left, $right );
		}
		if ( $use_in_city && $city !== '' ) {
			return sprintf( 'In %s, %s and %s almost never appear together.', $city, $left, $right );
		}
		return sprintf( '%s and %s almost never appear together.', $left, $right );
	}

	private static function must_label( $must ) {
		$lower = strtolower( (string) $must );
		$bits  = array();
		if ( false !== strpos( $lower, 'cabin' ) ) {
			$bits[] = 'the cabin';
		}
		if ( false !== strpos( $lower, 'acre' ) || false !== strpos( $lower, 'wooded' ) ) {
			$bits[] = 'the acreage';
		}
		if ( false !== strpos( $lower, 'secluded' ) || false !== strpos( $lower, 'quiet' ) ) {
			if ( ! in_array( 'the cabin', $bits, true ) ) {
				$bits[] = 'the secluded setting';
			}
		}
		if ( false !== strpos( $lower, 'pool' ) ) {
			$bits[] = 'the pool';
		}
		if ( false !== strpos( $lower, 'view' ) ) {
			$bits[] = 'the view';
		}
		if ( $bits ) {
			return implode( ' and ', array_slice( $bits, 0, 2 ) );
		}
		return self::preview( $must );
	}

	private static function city_label( $params ) {
		if ( empty( $params['location'] ) || Housemajik_Locations::is_statewide( $params['location'] ) ) {
			return '';
		}
		return Housemajik_Locations::display( $params['location'] );
	}

	private static function price_band( $listings ) {
		$prices = array();
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) || empty( $listing['price'] ) ) {
				continue;
			}
			$prices[] = (int) $listing['price'];
		}
		if ( ! $prices ) {
			return null;
		}
		return array(
			'low'  => min( $prices ),
			'high' => max( $prices ),
			'count'=> count( $prices ),
		);
	}

	private static function price_dream_sentence( $band, $max, $params = array() ) {
		$at   = self::money( $max );
		$land = self::is_land( $params );
		if ( (int) $band['low'] === (int) $band['high'] ) {
			$listed = self::money( $band['low'] );
			if ( (int) $band['count'] === 1 ) {
				return $land
					? sprintf( 'Land like this is listed at %s. You can keep that kind of land, or stay at %s.', $listed, $at )
					: sprintf( 'A home like this is listed at %s. You can keep that kind of home, or stay at %s.', $listed, $at );
			}
			return $land
				? sprintf( 'Land like this has been listing at %s. You can keep that kind of land, or stay at %s.', $listed, $at )
				: sprintf( 'Homes like this have been listing at %s. You can keep that kind of home, or stay at %s.', $listed, $at );
		}
		return $land
			? sprintf(
				'Land like this has been listing for %s–%s. You can keep that kind of land, or stay at %s.',
				self::money( $band['low'] ),
				self::money( $band['high'] ),
				$at
			)
			: sprintf(
				'Homes like this have been listing for %s–%s. You can keep that kind of home, or stay at %s.',
				self::money( $band['low'] ),
				self::money( $band['high'] ),
				$at
			);
	}

	private static function is_land( $params ) {
		return class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $params );
	}

	private static function payload( $message, $piles, $headline = '' ) {
		return array(
			'headline' => $headline !== '' ? $headline : 'These rarely appear together',
			'message'  => $message,
			'piles'    => $piles,
		);
	}

	private static function pile( $title, $listings, $keep, $give_up ) {
		$keep    = trim( (string) $keep );
		$give_up = trim( (string) $give_up );
		return array(
			'title'    => $title,
			'keep'     => $keep,
			'give_up'  => $give_up,
			'why'      => sprintf( 'You keep %s. You give up %s.', $keep, $give_up ),
			'listings' => array_slice( $listings, 0, self::PER_PILE ),
		);
	}

	private static function without_ids( $listings, $other ) {
		$skip = self::listing_ids( $other );
		$out  = array();
		foreach ( $listings as $listing ) {
			$id = self::listing_id( $listing );
			if ( $id === '' || isset( $skip[ $id ] ) ) {
				continue;
			}
			$out[] = $listing;
		}
		return $out;
	}

	private static function share_ids( $left, $right ) {
		$skip = self::listing_ids( $right );
		foreach ( $left as $listing ) {
			$id = self::listing_id( $listing );
			if ( $id !== '' && isset( $skip[ $id ] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function listing_ids( $listings ) {
		$ids = array();
		foreach ( $listings as $listing ) {
			$id = self::listing_id( $listing );
			if ( $id !== '' ) {
				$ids[ $id ] = true;
			}
		}
		return $ids;
	}

	private static function listing_id( $listing ) {
		return ( is_array( $listing ) && ! empty( $listing['id'] ) ) ? (string) $listing['id'] : '';
	}

	private static function preview( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
		if ( strlen( $text ) > 72 ) {
			return rtrim( substr( $text, 0, 69 ) ) . '…';
		}
		return $text;
	}

	private static function money( $n ) {
		return '$' . number_format( (int) $n );
	}
}
