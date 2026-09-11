<?php
/**
 * ARMLS/Spark Web API client interface.
 * Ready for integration when credentials arrive.
 */
class Housemajik_ARMLS {

	/**
	 * Get listings from ARMLS feed.
	 */
	public static function get_listings( $filters = array() ) {
		$endpoint = get_option( 'housemajik_armls_endpoint', '' );
		$username = get_option( 'housemajik_armls_username', '' );
		$password = get_option( 'housemajik_armls_password', '' );
		
		if ( empty( $endpoint ) || empty( $username ) || empty( $password ) ) {
			// No credentials configured
			return new WP_Error( 'no_credentials', 'ARMLS credentials not configured.' );
		}
		
		// Build RETS/Spark query from filters
		$query = self::build_armls_query( $filters );
		
		// Make API request
		$response = self::call_armls_api( $endpoint, $username, $password, $query );
		
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		
		// Parse and normalize response
		return self::parse_armls_response( $response );
	}

	/**
	 * Get single listing by MLS number.
	 */
	public static function get_listing( $mls_number ) {
		$endpoint = get_option( 'housemajik_armls_endpoint', '' );
		$username = get_option( 'housemajik_armls_username', '' );
		$password = get_option( 'housemajik_armls_password', '' );
		
		if ( empty( $endpoint ) || empty( $username ) || empty( $password ) ) {
			return new WP_Error( 'no_credentials', 'ARMLS credentials not configured.' );
		}
		
		$query = array(
			'ListingKey' => $mls_number,
		);
		
		$response = self::call_armls_api( $endpoint, $username, $password, $query );
		
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		
		$listings = self::parse_armls_response( $response );
		
		return ! empty( $listings ) ? $listings[0] : null;
	}

	/**
	 * Build ARMLS query from search filters.
	 */
	private static function build_armls_query( $filters ) {
		$query = array(
			'StandardStatus' => 'Active',
			'PropertyType' => 'Residential',
		);
		
		// Location
		if ( ! empty( $filters['location'] ) && ! Housemajik_Sample_Data::is_statewide_location( $filters['location'] ) ) {
			$cities = Housemajik_Locations::parse( $filters['location'] );
			if ( count( $cities ) === 1 ) {
				$query['City'] = $cities[0];
			} elseif ( count( $cities ) > 1 ) {
				$query['City'] = $cities;
			}
		}
		
		$land = class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $filters );
		if ( $land ) {
			$query['PropertyType'] = 'Land';
		}

		// Bedrooms
		if ( ! $land && ! empty( $filters['beds'] ) ) {
			if ( $filters['beds_mode'] === 'exactly' ) {
				$query['BedroomsTotal'] = $filters['beds'];
			} else {
				$query['BedroomsTotal_Min'] = $filters['beds'];
			}
		}
		
		// Bathrooms
		if ( ! $land && ! empty( $filters['baths'] ) ) {
			if ( $filters['baths_mode'] === 'exactly' ) {
				$query['BathroomsTotalInteger'] = $filters['baths'];
			} else {
				$query['BathroomsTotalInteger_Min'] = $filters['baths'];
			}
		}
		
		// Price
		if ( ! empty( $filters['max_price'] ) ) {
			$query['ListPrice_Max'] = $filters['max_price'];
		}
		
		if ( ! empty( $filters['acres'] ) && (float) $filters['acres'] > 0 ) {
			$query['LotSizeAcres_Min'] = (float) $filters['acres'];
		}

		// Garage
		if ( ! $land && ! empty( $filters['garage'] ) && $filters['garage'] !== 'dont_care' ) {
			if ( $filters['garage'] === 'yes' ) {
				$query['GarageSpaces_Min'] = 1;
			} else {
				$query['GarageSpaces'] = 0;
			}
		}
		
		// Limit results
		$query['Limit'] = 50;
		
		return $query;
	}

	/**
	 * Call ARMLS API.
	 */
	private static function call_armls_api( $endpoint, $username, $password, $query ) {
		// Construct API URL with query parameters
		$url = add_query_arg( $query, $endpoint );
		
		// Make authenticated request
		$response = wp_remote_get( $url, array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
				'Accept' => 'application/json',
			),
		) );
		
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		
		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error( 'api_error', 'ARMLS API error: ' . $body );
		}
		
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', 'Invalid JSON response from ARMLS.' );
		}
		
		return $data;
	}

	/**
	 * Parse ARMLS response into normalized format.
	 */
	private static function parse_armls_response( $data ) {
		$listings = array();
		
		// This will vary based on actual ARMLS/Spark API response structure
		// Placeholder structure - update when actual API format is known
		$results = isset( $data['value'] ) ? $data['value'] : $data;
		
		foreach ( $results as $item ) {
			$listing = array(
				'id' => isset( $item['ListingKey'] ) ? $item['ListingKey'] : '',
				'address' => isset( $item['UnparsedAddress'] ) ? $item['UnparsedAddress'] : '',
				'city' => isset( $item['City'] ) ? $item['City'] : '',
				'state' => isset( $item['StateOrProvince'] ) ? $item['StateOrProvince'] : 'AZ',
				'zip' => isset( $item['PostalCode'] ) ? $item['PostalCode'] : '',
				'price' => isset( $item['ListPrice'] ) ? (int) $item['ListPrice'] : 0,
				'beds' => isset( $item['BedroomsTotal'] ) ? (int) $item['BedroomsTotal'] : 0,
				'baths' => isset( $item['BathroomsTotalInteger'] ) ? (float) $item['BathroomsTotalInteger'] : 0,
				'sqft' => isset( $item['LivingArea'] ) ? (int) $item['LivingArea'] : 0,
				'garage' => isset( $item['GarageSpaces'] ) && $item['GarageSpaces'] > 0 ? $item['GarageSpaces'] . '-car' : '',
				'lot_size' => isset( $item['LotSizeArea'] ) ? $item['LotSizeArea'] . ' sq ft' : '',
				'year_built' => isset( $item['YearBuilt'] ) ? (int) $item['YearBuilt'] : 0,
				'property_type' => isset( $item['PropertyType'] ) ? $item['PropertyType'] : 'Single Family',
				'land' => isset( $item['PropertyType'] ) && strcasecmp( (string) $item['PropertyType'], 'Land' ) === 0,
				'photos' => isset( $item['Media'] ) ? self::extract_photos( $item['Media'] ) : array(),
				'remarks' => isset( $item['PublicRemarks'] ) ? $item['PublicRemarks'] : '',
				'lat' => isset( $item['Latitude'] ) ? (float) $item['Latitude'] : 0,
				'lng' => isset( $item['Longitude'] ) ? (float) $item['Longitude'] : 0,
			);
			
			$listings[] = $listing;
		}
		
		return $listings;
	}

	/**
	 * Extract photo URLs from media array.
	 */
	private static function extract_photos( $media ) {
		$photos = array();
		
		if ( is_array( $media ) ) {
			foreach ( $media as $item ) {
				if ( isset( $item['MediaURL'] ) ) {
					$photos[] = $item['MediaURL'];
				}
			}
		}
		
		return $photos;
	}

	/**
	 * Check if ARMLS is configured.
	 */
	public static function is_configured() {
		$endpoint = get_option( 'housemajik_armls_endpoint', '' );
		$username = get_option( 'housemajik_armls_username', '' );
		$password = get_option( 'housemajik_armls_password', '' );
		
		return ! empty( $endpoint ) && ! empty( $username ) && ! empty( $password );
	}
}
