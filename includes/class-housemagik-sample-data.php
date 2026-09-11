<?php
/**
 * Sample listings data provider.
 * North Valley demo homes, plus desert and Prescott pine-country land.
 */
class Housemajik_Sample_Data {

	/**
	 * Get sample listings.
	 */
	public static function get_listings( $filters = array() ) {
		$all_listings = self::get_all_sample_listings();

		$filtered = array();
		foreach ( $all_listings as $listing ) {
			if ( self::matches_filters( $listing, $filters ) ) {
				$filtered[] = $listing;
			}
		}

		return $filtered;
	}

	/**
	 * Check if listing matches filters.
	 */
	public static function is_land( $listing ) {
		$type = strtolower( isset( $listing['property_type'] ) ? (string) $listing['property_type'] : '' );
		return $type === 'land' || ! empty( $listing['land'] );
	}

	private static function matches_filters( $listing, $filters ) {
		$wants_land = class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $filters );
		if ( $wants_land && ! self::is_land( $listing ) ) {
			return false;
		}
		if ( ! $wants_land && self::is_land( $listing ) ) {
			return false;
		}

		if ( ! empty( $filters['location'] ) && ! self::is_statewide_location( $filters['location'] ) ) {
			if ( ! self::location_matches( $listing, $filters['location'] ) ) {
				return false;
			}
		}

		if ( $wants_land ) {
			if ( ! empty( $filters['max_price'] ) && $listing['price'] > $filters['max_price'] ) {
				return false;
			}
			return self::meets_acres( $listing, $filters );
		}

		if ( ! empty( $filters['beds'] ) ) {
			if ( $filters['beds_mode'] === 'exactly' ) {
				if ( $listing['beds'] != $filters['beds'] ) {
					return false;
				}
			} elseif ( $listing['beds'] < $filters['beds'] ) {
				return false;
			}
		}

		if ( ! empty( $filters['baths'] ) ) {
			$wanted = (float) $filters['baths'];
			$have   = (float) $listing['baths'];
			if ( $filters['baths_mode'] === 'exactly' ) {
				if ( $have < $wanted || $have >= $wanted + 1 ) {
					return false;
				}
			} elseif ( $have < $wanted ) {
				return false;
			}
		}

		if ( ! empty( $filters['max_price'] ) ) {
			if ( $listing['price'] > $filters['max_price'] ) {
				return false;
			}
		}

		if ( ! empty( $filters['garage'] ) && $filters['garage'] !== 'dont_care' ) {
			$has_garage = ! empty( $listing['garage'] );
			if ( $filters['garage'] === 'yes' && ! $has_garage ) {
				return false;
			}
			if ( $filters['garage'] === 'no' && $has_garage ) {
				return false;
			}
		}

		return self::meets_acres( $listing, $filters );
	}

	/**
	 * Blank acres is ignored. A number is a minimum.
	 */
	private static function meets_acres( $listing, $filters ) {
		$wanted = class_exists( 'Housemajik_Security' )
			? Housemajik_Security::acres_value( $filters )
			: ( isset( $filters['acres'] ) ? (float) $filters['acres'] : 0 );
		if ( $wanted <= 0 ) {
			return true;
		}
		$have = class_exists( 'Housemajik_Security' )
			? Housemajik_Security::acres_from_lot_size( isset( $listing['lot_size'] ) ? $listing['lot_size'] : '' )
			: 0.0;
		return $have + 0.0001 >= $wanted;
	}

	/**
	 * City match, plus North Valley / North Phoenix as the whole demo map.
	 */
	private static function location_matches( $listing, $query ) {
		$city  = strtolower( $listing['city'] );
		$parts = Housemajik_Locations::parse( $query );

		foreach ( $parts as $part ) {
			$q = strtolower( trim( (string) $part ) );
			if ( $q === '' ) {
				continue;
			}
			if ( in_array( $q, array( 'north valley', 'north phoenix', 'nv' ), true ) ) {
				return true;
			}
			if ( $q === 'desert hills' && ( strpos( $city, 'phoenix' ) !== false || strpos( $city, 'desert hills' ) !== false ) ) {
				return true;
			}
			if ( strpos( $city, $q ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * "Arizona" is a statewide pass so the form can stay required.
	 */
	public static function is_statewide_location( $location ) {
		return Housemajik_Locations::is_statewide( $location );
	}

	/**
	 * Get single listing by ID.
	 */
	public static function get_listing( $listing_id ) {
		foreach ( self::get_all_sample_listings() as $listing ) {
			if ( $listing['id'] === $listing_id ) {
				return $listing;
			}
		}

		return null;
	}

	/**
	 * Demo gallery from bundled sample images.
	 */
	private static function sample_photos( $indices ) {
		$base   = HOUSEMAJIK_PLUGIN_URL . 'public/images/';
		$photos = array();
		foreach ( $indices as $index ) {
			$photos[] = $base . 'sample-' . absint( $index ) . '-main.jpg';
		}
		return $photos;
	}

	private static function land_photos( $index ) {
		return array( HOUSEMAJIK_PLUGIN_URL . 'public/images/sample-land-' . absint( $index ) . '.jpg' );
	}

	/**
	 * All sample listings. Cities match Suzanne's featured North Valley map.
	 */
	private static function get_all_sample_listings() {
		return array(
			array(
				'id' => 'SAMPLE-001',
				'address' => '28640 N 17th Drive',
				'city' => 'Phoenix',
				'state' => 'AZ',
				'zip' => '85085',
				'price' => 485000,
				'beds' => 3,
				'baths' => 2.5,
				'sqft' => 2100,
				'garage' => '2-car attached',
				'lot_size' => '8,500 sq ft',
				'year_built' => 2018,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 1 ) ),
				'remarks' => 'North Phoenix desert contemporary in the Sonoran Foothills. Open floor plan, mountain views, and a covered patio. Quartz kitchen, walk-in closet, low-maintenance desert yard. Community pool and trails. I-17 is a short drive.',
				'lat' => 33.7462,
				'lng' => -112.1064,
			),
			array(
				'id' => 'SAMPLE-009',
				'address' => '20418 N 19th Avenue',
				'city' => 'Phoenix',
				'state' => 'AZ',
				'zip' => '85027',
				'price' => 469000,
				'beds' => 3,
				'baths' => 2,
				'sqft' => 1680,
				'garage' => '2-car attached',
				'lot_size' => '2.1 acres',
				'year_built' => 1988,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 5 ) ),
				'remarks' => 'Deer Valley cabin-style home on a little over two acres of desert trees, set back from the road. Wood-beamed ceilings, a wood stove, and a wraparound porch. No HOA. Hiking access nearby without sitting on a highway.',
				'lat' => 33.6738,
				'lng' => -112.1002,
			),
			array(
				'id' => 'SAMPLE-002',
				'address' => '20815 N 82nd Street',
				'city' => 'Scottsdale',
				'state' => 'AZ',
				'zip' => '85255',
				'price' => 725000,
				'beds' => 4,
				'baths' => 3,
				'sqft' => 2850,
				'garage' => '3-car attached',
				'lot_size' => '12,200 sq ft',
				'year_built' => 2015,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 2 ) ),
				'remarks' => 'North Scottsdale home with a resort backyard, pool, and ramada. Gourmet kitchen, split floor plan, and a finished three-car garage. Lot backs to a desert wash. Loop 101 is close.',
				'lat' => 33.6754,
				'lng' => -111.9068,
			),
			array(
				'id' => 'SAMPLE-003',
				'address' => '42116 N Celebration Way',
				'city' => 'Anthem',
				'state' => 'AZ',
				'zip' => '85086',
				'price' => 395000,
				'beds' => 3,
				'baths' => 2,
				'sqft' => 1820,
				'garage' => '2-car attached',
				'lot_size' => '6,800 sq ft',
				'year_built' => 2020,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 3 ) ),
				'remarks' => 'Like-new Anthem ranch. Open kitchen, covered patio, and a low-maintenance backyard. Community playground and trails. Easy I-17 access.',
				'lat' => 33.8688,
				'lng' => -112.1402,
			),
			array(
				'id' => 'SAMPLE-004',
				'address' => '37605 N Hidden Valley Drive',
				'city' => 'Cave Creek',
				'state' => 'AZ',
				'zip' => '85331',
				'price' => 550000,
				'beds' => 4,
				'baths' => 2.5,
				'sqft' => 2450,
				'garage' => '3-car tandem',
				'lot_size' => '9,200 sq ft',
				'year_built' => 2017,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 4 ) ),
				'remarks' => 'Cave Creek family home with a loft, granite kitchen, and a tandem three-car garage. Covered patio and a grassy backyard. Town and the preserve are a few minutes away.',
				'lat' => 33.8246,
				'lng' => -111.9621,
			),
			array(
				'id' => 'SAMPLE-005',
				'address' => '36620 N Tranquil Trail',
				'city' => 'Carefree',
				'state' => 'AZ',
				'zip' => '85377',
				'price' => 425000,
				'beds' => 3,
				'baths' => 2,
				'sqft' => 1950,
				'garage' => '2-car attached',
				'lot_size' => '10,500 sq ft',
				'year_built' => 2016,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 5 ) ),
				'remarks' => 'Carefree home with boulder views and a covered patio for evening light. Split bedrooms, updated kitchen, and native desert plantings. Quiet cul-de-sac.',
				'lat' => 33.8224,
				'lng' => -111.9186,
			),
			array(
				'id' => 'SAMPLE-006',
				'address' => '47812 N 16th Street',
				'city' => 'New River',
				'state' => 'AZ',
				'zip' => '85087',
				'price' => 495000,
				'beds' => 4,
				'baths' => 3,
				'sqft' => 2300,
				'garage' => '2-car attached',
				'lot_size' => '0.75 acre',
				'year_built' => 2019,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 6 ) ),
				'remarks' => 'New River two-story with a downstairs bedroom, loft, and desert views. Open kitchen, smart-home wiring, and room for a shop later. Dark-sky nights.',
				'lat' => 33.9124,
				'lng' => -112.0648,
			),
			array(
				'id' => 'SAMPLE-007',
				'address' => '22218 N 44th Street',
				'city' => 'Phoenix',
				'state' => 'AZ',
				'zip' => '85050',
				'price' => 365000,
				'beds' => 3,
				'baths' => 2,
				'sqft' => 1680,
				'garage' => '2-car attached',
				'lot_size' => '6,200 sq ft',
				'year_built' => 2021,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 7 ) ),
				'remarks' => 'Desert Ridge–area ranch. Open layout, breakfast bar, and easy-care landscaping. Near the 101, Mayo Clinic, and the Desert Ridge shops.',
				'lat' => 33.6882,
				'lng' => -111.9864,
			),
			array(
				'id' => 'SAMPLE-008',
				'address' => '5412 E Cheney Drive',
				'city' => 'Paradise Valley',
				'state' => 'AZ',
				'zip' => '85253',
				'price' => 875000,
				'beds' => 5,
				'baths' => 4,
				'sqft' => 3600,
				'garage' => '3-car attached',
				'lot_size' => '15,800 sq ft',
				'year_built' => 2014,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 8 ) ),
				'remarks' => 'Paradise Valley estate on a premium lot with mountain and city views. Chef kitchen, great room with fireplace, and a casita. Pool, spa, and outdoor kitchen.',
				'lat' => 33.5318,
				'lng' => -111.9632,
			),
			array(
				'id' => 'SAMPLE-010',
				'address' => '28614 N 21st Avenue',
				'city' => 'Phoenix',
				'state' => 'AZ',
				'zip' => '85085',
				'price' => 499000,
				'beds' => 4,
				'baths' => 2.5,
				'sqft' => 2380,
				'garage' => '2-car attached',
				'lot_size' => '7,800 sq ft',
				'year_built' => 2016,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 2 ) ),
				'remarks' => 'Norterra two-story with a downstairs guest room, open kitchen, and a covered patio facing the wash. Four bedrooms and a loft. HOA covers common-area landscaping.',
				'lat' => 33.7421,
				'lng' => -112.1048,
			),
			array(
				'id' => 'SAMPLE-011',
				'address' => '20850 N Tatum Boulevard',
				'city' => 'Phoenix',
				'state' => 'AZ',
				'zip' => '85050',
				'price' => 329000,
				'beds' => 2,
				'baths' => 2,
				'sqft' => 1180,
				'garage' => '1-car attached',
				'lot_size' => 'condo',
				'year_built' => 2005,
				'property_type' => 'Townhome',
				'photos' => self::sample_photos( array( 3 ) ),
				'remarks' => 'Desert Ridge townhome. Two bedrooms, two baths, a small patio, and a one-car garage. Low-maintenance. Walkable to the shops and the canal path.',
				'lat' => 33.6764,
				'lng' => -111.9782,
			),
			array(
				'id' => 'SAMPLE-012',
				'address' => '41808 N Venture Road',
				'city' => 'Anthem',
				'state' => 'AZ',
				'zip' => '85086',
				'price' => 445000,
				'beds' => 3,
				'baths' => 2,
				'sqft' => 1860,
				'garage' => '2-car attached',
				'lot_size' => '6,400 sq ft',
				'year_built' => 2003,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 4 ) ),
				'remarks' => 'Anthem ranch with a wide backyard and mountain views from the kitchen. Three bedrooms, two baths, community pool and trails.',
				'lat' => 33.8670,
				'lng' => -112.1468,
			),
			array(
				'id' => 'SAMPLE-013',
				'address' => '39612 N 12th Street',
				'city' => 'Phoenix',
				'state' => 'AZ',
				'zip' => '85086',
				'price' => 375000,
				'beds' => 3,
				'baths' => 2,
				'sqft' => 1540,
				'garage' => '',
				'lot_size' => '0.6 acre',
				'year_built' => 1974,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 6 ) ),
				'remarks' => 'Desert Hills carport home — no garage. Block construction, mature palo verdes, and a large backyard. Three bedrooms, two baths. No HOA.',
				'lat' => 33.8546,
				'lng' => -112.0568,
			),
			array(
				'id' => 'SAMPLE-014',
				'address' => '37622 N School House Road',
				'city' => 'Cave Creek',
				'state' => 'AZ',
				'zip' => '85331',
				'price' => 625000,
				'beds' => 4,
				'baths' => 3,
				'sqft' => 2610,
				'garage' => '3-car attached',
				'lot_size' => '1.1 acres',
				'year_built' => 1999,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 8 ) ),
				'remarks' => 'Cave Creek home on just over an acre with a three-car garage and a workshop bay. Four bedrooms, three baths, and no backyard neighbors. Town is a few minutes down the road.',
				'lat' => 33.8334,
				'lng' => -111.9508,
			),
			array(
				'id' => 'SAMPLE-015',
				'address' => '48420 N 17th Avenue',
				'city' => 'New River',
				'state' => 'AZ',
				'zip' => '85087',
				'price' => 519000,
				'beds' => 5,
				'baths' => 3,
				'sqft' => 2720,
				'garage' => '3-car tandem',
				'lot_size' => '1.0 acre',
				'year_built' => 2018,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 1 ) ),
				'remarks' => 'New River two-story with five bedrooms and a downstairs bedroom. Open kitchen, loft, tandem three-car garage, and room for horses later if the next owner wants them.',
				'lat' => 33.9186,
				'lng' => -112.1012,
			),
			array(
				'id' => 'SAMPLE-016',
				'address' => '34618 N 70th Street',
				'city' => 'Scottsdale',
				'state' => 'AZ',
				'zip' => '85266',
				'price' => 589000,
				'beds' => 3,
				'baths' => 2,
				'sqft' => 1760,
				'garage' => '2-car attached',
				'lot_size' => '0.8 acre',
				'year_built' => 1978,
				'property_type' => 'Single Family',
				'photos' => self::sample_photos( array( 7 ) ),
				'remarks' => 'North Scottsdale ranch near Carefree Highway. Citrus tree, two-car garage, and a 2022 roof. Three bedrooms, two baths, and a screened Arizona room.',
				'lat' => 33.7984,
				'lng' => -111.9326,
			),
			array(
				'id' => 'SAMPLE-LAND-001',
				'address' => 'New River desert parcel',
				'city' => 'New River',
				'state' => 'AZ',
				'zip' => '85087',
				'price' => 189000,
				'beds' => 0,
				'baths' => 0,
				'sqft' => 0,
				'garage' => '',
				'lot_size' => '5.2 acres',
				'year_built' => '',
				'property_type' => 'Land',
				'land' => true,
				'photos' => self::land_photos( 1 ),
				'remarks' => 'Raw desert land on a little over five acres in New River. No house. Dirt-road access without 4-wheel drive in dry weather. Power is at the road. A well would need to be drilled. No HOA. Set back from the highway with room for a future home or horses.',
				'lat' => 33.9148,
				'lng' => -112.0844,
			),
			array(
				'id' => 'SAMPLE-LAND-002',
				'address' => 'Cave Creek ridge parcel',
				'city' => 'Cave Creek',
				'state' => 'AZ',
				'zip' => '85331',
				'price' => 275000,
				'beds' => 0,
				'baths' => 0,
				'sqft' => 0,
				'garage' => '',
				'lot_size' => '2.4 acres',
				'year_built' => '',
				'property_type' => 'Land',
				'land' => true,
				'photos' => self::land_photos( 2 ),
				'remarks' => 'Vacant high-desert ridge lot of 2.4 acres in Cave Creek. No house and no neighbors in the backyard. Electric at the street. No well yet. No HOA. Views across the valley. Town is a few minutes down the road.',
				'lat' => 33.8412,
				'lng' => -111.9618,
			),
			array(
				'id' => 'SAMPLE-LAND-003',
				'address' => 'Prescott pines parcel',
				'city' => 'Prescott',
				'state' => 'AZ',
				'zip' => '86305',
				'price' => 225000,
				'beds' => 0,
				'baths' => 0,
				'sqft' => 0,
				'garage' => '',
				'lot_size' => '3.8 acres',
				'year_built' => '',
				'property_type' => 'Land',
				'land' => true,
				'photos' => self::land_photos( 3 ),
				'remarks' => 'High-elevation raw land at about 5,200 feet outside Prescott. 3.8 acres of ponderosa pine and granite. No house. A well is on the parcel. Seasonal dirt access. Quiet, no HOA, and cooler than the Valley in summer.',
				'lat' => 34.5612,
				'lng' => -112.5128,
			),
			array(
				'id' => 'SAMPLE-LAND-004',
				'address' => 'Black Canyon desert parcel',
				'city' => 'Black Canyon City',
				'state' => 'AZ',
				'zip' => '85324',
				'price' => 155000,
				'beds' => 0,
				'baths' => 0,
				'sqft' => 0,
				'garage' => '',
				'lot_size' => '10.0 acres',
				'year_built' => '',
				'property_type' => 'Land',
				'land' => true,
				'photos' => self::land_photos( 4 ),
				'remarks' => 'Ten acres of raw Sonoran desert outside Black Canyon City. No house. A well is on the parcel. Power is at the road. Dirt-road access without 4-wheel drive in dry weather. No HOA. The lot sits near I-17 with highway traffic.',
				'lat' => 34.0684,
				'lng' => -112.1486,
			),
			array(
				'id' => 'SAMPLE-LAND-005',
				'address' => 'New River wash parcel',
				'city' => 'New River',
				'state' => 'AZ',
				'zip' => '85087',
				'price' => 198000,
				'beds' => 0,
				'baths' => 0,
				'sqft' => 0,
				'garage' => '',
				'lot_size' => '12.4 acres',
				'year_built' => '',
				'property_type' => 'Land',
				'land' => true,
				'photos' => self::land_photos( 5 ),
				'remarks' => 'Large raw desert wash parcel of 12.4 acres in New River. No house and no neighboring homes. Power is at the road. No well yet. A four-wheel-drive vehicle is recommended after rain. No HOA. Room for horses later.',
				'lat' => 33.9286,
				'lng' => -112.0912,
			),
			array(
				'id' => 'SAMPLE-LAND-006',
				'address' => 'Cave Creek street lot',
				'city' => 'Cave Creek',
				'state' => 'AZ',
				'zip' => '85331',
				'price' => 289000,
				'beds' => 0,
				'baths' => 0,
				'sqft' => 0,
				'garage' => '',
				'lot_size' => '0.9 acre',
				'year_built' => '',
				'property_type' => 'Land',
				'land' => true,
				'photos' => self::land_photos( 6 ),
				'remarks' => 'Small vacant desert lot of 0.9 acre in Cave Creek. No house. Paved street access. Electric at the street. No well yet. Neighboring homes sit on both sides. Septic would be needed. No HOA. Town is a few minutes down the road.',
				'lat' => 33.8298,
				'lng' => -111.9574,
			),
			array(
				'id' => 'SAMPLE-LAND-007',
				'address' => 'Prescott forest parcel',
				'city' => 'Prescott',
				'state' => 'AZ',
				'zip' => '86303',
				'price' => 345000,
				'beds' => 0,
				'baths' => 0,
				'sqft' => 0,
				'garage' => '',
				'lot_size' => '6.2 acres',
				'year_built' => '',
				'property_type' => 'Land',
				'land' => true,
				'photos' => self::land_photos( 7 ),
				'remarks' => 'Thick ponderosa pine and granite at about 5,600 feet outside Prescott. 6.2 acres. No house. No well yet. Seasonal dirt access. A four-wheel-drive vehicle is recommended in winter. Quiet, no HOA, and no neighboring homes.',
				'lat' => 34.5126,
				'lng' => -112.4682,
			),
			array(
				'id' => 'SAMPLE-LAND-008',
				'address' => 'Prescott county-road parcel',
				'city' => 'Prescott',
				'state' => 'AZ',
				'zip' => '86305',
				'price' => 179000,
				'beds' => 0,
				'baths' => 0,
				'sqft' => 0,
				'garage' => '',
				'lot_size' => '2.1 acres',
				'year_built' => '',
				'property_type' => 'Land',
				'land' => true,
				'photos' => self::land_photos( 8 ),
				'remarks' => 'Two acres of pine and juniper just outside Prescott. No house. A well is on the parcel. Paved county-road access. Electric at the street. Neighboring homes sit on one side. No HOA. Year-round access without 4-wheel drive.',
				'lat' => 34.5488,
				'lng' => -112.4916,
			),
			array(
				'id' => 'SAMPLE-LAND-009',
				'address' => 'Prescott meadow parcel',
				'city' => 'Prescott',
				'state' => 'AZ',
				'zip' => '86305',
				'price' => 265000,
				'beds' => 0,
				'baths' => 0,
				'sqft' => 0,
				'garage' => '',
				'lot_size' => '11.5 acres',
				'year_built' => '',
				'property_type' => 'Land',
				'land' => true,
				'photos' => self::land_photos( 9 ),
				'remarks' => 'Eleven and a half acres of meadow and ponderosa pine outside Prescott. No house and no neighboring homes. A well is on the parcel. Dirt-road access without 4-wheel drive in dry weather. Quiet, no HOA, and cooler than the Valley in summer.',
				'lat' => 34.5794,
				'lng' => -112.5348,
			),
		);
	}
}
