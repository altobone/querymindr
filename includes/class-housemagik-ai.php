<?php
/**
 * Anthropic Claude AI integration.
 */
class Housemajik_AI {

	const DEFAULT_MODEL = 'claude-haiku-4-5';

	/**
	 * Parse dream home and don't-want descriptions into structured search parameters.
	 */
	public static function parse_search_intent( $dream_home, $dont_want ) {
		$api_key = self::get_api_key();
		
		if ( ! $api_key ) {
			return array(
				'hard_filters' => array(),
				'soft_preferences' => array(),
				'exclusions' => array(),
				'ambiguous' => array(),
			);
		}
		
		if ( ! Housemajik_Security::check_ai_cap() ) {
			return array(
				'error' => 'Daily AI usage limit reached. Please try again tomorrow.',
			);
		}
		
		$prompt = self::build_parse_prompt( $dream_home, $dont_want );
		$response = self::call_anthropic_api( $prompt, array(
			'type' => 'parse',
			'max_tokens' => 1000,
		) );
		
		if ( is_wp_error( $response ) ) {
			self::record_error( $response->get_error_message() );
			return array(
				'error' => $response->get_error_message(),
			);
		}

		if ( ! is_array( $response ) ) {
			self::record_error( 'Claude returned a non-JSON parse response.' );
			return array(
				'error' => 'Invalid AI parse response.',
			);
		}
		
		Housemajik_Security::increment_ai_usage();
		self::clear_error();
		
		return $response;
	}

	/**
	 * Rank listings and generate why-lines.
	 */
	public static function rank_listings( $listings, $search_params, $ai_preferences, $taste_notes = array() ) {
		$api_key = self::get_api_key();
		$taste_notes = self::normalize_taste_notes( $taste_notes );
		
		if ( ! $api_key || empty( $listings ) ) {
			// Fallback: return listings as-is with simple why-lines
			return self::generate_fallback_rankings( $listings, $search_params, $taste_notes );
		}

		if ( ! Housemajik_Security::allow_ai_for_request() ) {
			return self::generate_fallback_rankings( $listings, $search_params, $taste_notes );
		}
		
		if ( ! Housemajik_Security::check_ai_cap() ) {
			self::record_error( 'Daily AI usage cap reached. Why-lines used the local fallback.' );
			return self::generate_fallback_rankings( $listings, $search_params, $taste_notes );
		}
		
		$prompt = self::build_ranking_prompt( $listings, $search_params, $ai_preferences, $taste_notes );
		$response = self::call_anthropic_api( $prompt, array(
			'type' => 'rank',
			'land' => class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $search_params ),
			'max_tokens' => 3500,
		) );
		
		if ( is_wp_error( $response ) ) {
			self::record_error( $response->get_error_message() );
			return self::generate_fallback_rankings( $listings, $search_params, $taste_notes );
		}

		if ( ! is_array( $response ) || empty( $response['rankings'] ) ) {
			self::record_error( 'Claude ranking response was missing rankings.' );
			return self::generate_fallback_rankings( $listings, $search_params, $taste_notes );
		}
		
		Housemajik_Security::increment_ai_usage();
		Housemajik_Security::record_ai_for_request();
		self::clear_error();
		
		return $response;
	}

	/**
	 * Generate why-line for alert email.
	 */
	public static function generate_alert_why_line( $listing, $search_params, $user_reactions = array() ) {
		$api_key = self::get_api_key();
		
		if ( ! $api_key ) {
			return 'New listing matching your saved search.';
		}
		
		if ( ! Housemajik_Security::check_ai_cap() ) {
			return 'New listing matching your saved search.';
		}
		
		$prompt = self::build_alert_why_prompt( $listing, $search_params, $user_reactions );
		$response = self::call_anthropic_api( $prompt, array(
			'type' => 'why',
			'land' => class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $search_params ),
			'max_tokens' => 200,
		) );
		
		if ( is_wp_error( $response ) ) {
			self::record_error( $response->get_error_message() );
			return 'New listing matching your saved search.';
		}
		
		Housemajik_Security::increment_ai_usage();
		self::clear_error();

		$why = is_string( $response ) ? trim( $response ) : '';
		if ( self::is_rejection_why( $why ) ) {
			return '';
		}

		return $why !== '' ? $why : 'New listing matching your saved search.';
	}

	/**
	 * True when copy says the home is a miss, not a reason to send.
	 */
	public static function is_rejection_why( $why ) {
		$why = strtolower( trim( wp_strip_all_tags( (string) $why ) ) );
		if ( $why === '' || $why === 'no_match' || $why === 'nomatch' ) {
			return true;
		}

		return (bool) preg_match(
			'/\b(doesn\'t match|does not match|do not match|not a match|poor fit|wouldn\'t (fit|recommend)|this doesn\'t)\b/i',
			$why
		);
	}

	/**
	 * Summarize user preferences for broker brief.
	 */
	public static function summarize_for_broker( $search_params, $reactions ) {
		$api_key = self::get_api_key();
		
		if ( ! $api_key || empty( $reactions ) ) {
			return array(
				'summary' => '',
				'patterns' => array(),
			);
		}
		
		if ( ! Housemajik_Security::check_ai_cap() ) {
			return array(
				'summary' => '',
				'patterns' => array(),
			);
		}
		
		if ( empty( $search_params['dream_home'] ) && empty( $reactions ) ) {
			return array(
				'summary' => '',
				'patterns' => array(),
			);
		}

		$prompt = self::build_broker_summary_prompt( $search_params, $reactions );
		$response = self::call_anthropic_api( $prompt, array(
			'type' => 'summarize',
			'max_tokens' => 1500,
		) );
		
		if ( is_wp_error( $response ) ) {
			return array(
				'summary' => '',
				'patterns' => array(),
			);
		}
		
		Housemajik_Security::increment_ai_usage();
		
		return $response;
	}

	/**
	 * Call Anthropic API.
	 */
	private static function call_anthropic_api( $prompt, $options = array() ) {
		$api_key = self::get_api_key();
		
		if ( ! $api_key ) {
			return new WP_Error( 'no_api_key', 'Anthropic API key not configured.' );
		}
		
		$model = self::get_model();
		$max_tokens = isset( $options['max_tokens'] ) ? $options['max_tokens'] : 1000;
		
		$body = array(
			'model' => $model,
			'max_tokens' => $max_tokens,
			'messages' => array(
				array(
					'role' => 'user',
					'content' => $prompt,
				),
			),
		);

		if ( ! empty( $options['system'] ) ) {
			$body['system'] = $options['system'];
		} elseif ( ! empty( $options['type'] ) && in_array( $options['type'], array( 'rank', 'why' ), true ) ) {
			$body['system'] = self::why_voice_system_prompt( ! empty( $options['land'] ) );
		}
		
		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
				'x-api-key' => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			'body' => wp_json_encode( $body ),
		) );
		
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		
		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error( 'api_error', 'Anthropic API error: ' . $body );
		}
		
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		
		if ( ! isset( $data['content'][0]['text'] ) ) {
			return new WP_Error( 'invalid_response', 'Invalid API response.' );
		}
		
		$text = $data['content'][0]['text'];
		
		// Parse JSON response if expected
		if ( in_array( $options['type'], array( 'parse', 'rank', 'summarize' ), true ) ) {
			$parsed = self::parse_json_response( $text );
			if ( is_array( $parsed ) ) {
				return $parsed;
			}
			return new WP_Error( 'invalid_response', 'Claude returned text that was not valid JSON.' );
		}
		
		return $text;
	}

	/**
	 * Build parse prompt.
	 */
	private static function build_parse_prompt( $dream_home, $dont_want ) {
		$prompt = "You are a real estate search assistant. Parse the buyer's natural language descriptions into structured search criteria.\n\n";
		$prompt .= "BUYER'S DREAM HOME:\n" . $dream_home . "\n\n";
		
		if ( ! empty( $dont_want ) ) {
			$prompt .= "WHAT BUYER DOESN'T WANT:\n" . $dont_want . "\n\n";
		}
		
		$prompt .= "Extract the following in strict JSON format:\n";
		$prompt .= "{\n";
		$prompt .= '  "hard_filters": ["Clear requirements that can be filtered: pool, single-story, etc."],';
		$prompt .= "\n";
		$prompt .= '  "soft_preferences": ["Preferences for ranking: quiet, natural light, modern, etc."],';
		$prompt .= "\n";
		$prompt .= '  "exclusions": ["Things to avoid from don\'t-want section"],';
		$prompt .= "\n";
		$prompt .= '  "ambiguous": ["Unclear terms that need clarification"]';
		$prompt .= "\n}\n\n";
		$prompt .= "Return ONLY valid JSON. Be specific and actionable.";
		
		return $prompt;
	}

	/**
	 * Voice for public-facing why-lines.
	 */
	private static function why_voice_system_prompt( $land = false ) {
		if ( $land ) {
			return 'You confirm why this land matches the buyer’s search. Speak like a good Arizona agent: warm, plain, useful. One or two short sentences. Walk must-have, would-like, and never, plus city, acreage, and budget. Do not mention bedrooms, bathrooms, or a garage. Confirm only what this listing’s facts actually meet. If a never item is in the listing facts, say so plainly. If they left notes on earlier land, prefer listings whose facts match what they liked and demote listings whose facts match what they did not like. You may mention one prior note only if this listing’s facts support it. Do not retell the MLS remarks unless the buyer asked for that. Never write “you asked about X, and this city listing has that.” Never invent listing facts. Avoid brochure words and deal-closers such as "seal it", "cinches it", or "checks every box".';
		}
		return 'You confirm why this home matches the buyer’s search. Speak like a good Arizona agent: warm, plain, useful. One or two short sentences. Walk must-have, would-like, and never, plus city, beds, baths, budget, and garage. Confirm only what this listing’s facts actually meet. If a never item is in the listing facts, say so plainly. If they left notes on earlier homes, prefer listings whose facts match what they liked and demote listings whose facts match what they did not like. You may mention one prior note only if this listing’s facts support it. Do not retell the MLS remarks, granite, loft, patio, square footage, or year unless the buyer asked for that. Never write “you asked about X, and this city listing has that.” Never invent listing facts. Avoid brochure words and deal-closers such as "seal it", "cinches it", or "checks every box".';
	}

	/**
	 * Build ranking prompt.
	 */
	private static function build_ranking_prompt( $listings, $search_params, $ai_preferences, $taste_notes = array() ) {
		$prompt = "Rank these listings for this buyer and write a why-line for each.\n\n";
		$prompt .= self::search_criteria_block( $search_params );
		$prompt .= self::taste_notes_block( $taste_notes );
		
		$land = class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $search_params );
		$prompt .= "LISTINGS (use only these facts):\n";
		foreach ( $listings as $idx => $listing ) {
			if ( $land ) {
				$prompt .= sprintf(
					"%d. %s, %s - $%s - raw land%s - %s\n",
					$idx + 1,
					$listing['address'],
					$listing['city'],
					number_format( $listing['price'] ),
					! empty( $listing['lot_size'] ) ? ' - ' . $listing['lot_size'] : '',
					substr( $listing['remarks'], 0, 500 )
				);
			} else {
				$prompt .= sprintf(
					"%d. %s, %s - $%s - %d bed, %.1f bath%s%s%s%s - %s\n",
					$idx + 1,
					$listing['address'],
					$listing['city'],
					number_format( $listing['price'] ),
					$listing['beds'],
					$listing['baths'],
					! empty( $listing['sqft'] ) ? ' - ' . number_format( $listing['sqft'] ) . ' sq ft' : '',
					! empty( $listing['lot_size'] ) ? ' - lot ' . $listing['lot_size'] : '',
					! empty( $listing['garage'] ) ? ' - ' . $listing['garage'] : '',
					! empty( $listing['year_built'] ) ? ' - built ' . $listing['year_built'] : '',
					substr( $listing['remarks'], 0, 500 )
				);
			}
		}
		
		$prompt .= "\nReturn strict JSON:\n";
		$prompt .= "{\n";
		$prompt .= '  "rankings": [';
		$prompt .= "\n";
		$prompt .= '    {"listing_index": 0, "score": 95, "why": "A human why-line"},';
		$prompt .= "\n    ...\n";
		$prompt .= "  ]\n}\n\n";
		$prompt .= "Rules:\n";
		$prompt .= "- Score 0-100 based on match quality\n";
		$prompt .= "- If buyer notes exist, raise the score when this listing’s facts match what they liked, and lower it when the facts match what they did not like\n";
		$prompt .= "- Treat Must have as deal-breakers, Would like as bonuses, and Never as misses when the listing facts support that\n";
		$prompt .= "- why confirms the SEARCH, not the listing brochure\n";
		$prompt .= "- You may mention one prior note in the why-line only if this listing’s facts support that note. Do not invent facts from their notes\n";
		if ( $land ) {
			$prompt .= "- One or two short sentences. Name the criteria this land meets (city they picked, acreage, budget, and the must / would-like / never language)\n";
			$prompt .= "- Only mention a listing detail if it proves a search criterion. Do not mention bedrooms, bathrooms, or a garage\n";
			$prompt .= "- Bad: “You asked about acreage, and this New River listing has that.”\n";
			$prompt .= "- Good: “This land is in New River, which you selected, and it meets your search for at least 3 acres under your cap.”\n";
			$prompt .= "- Do not copy remarks. Do not end with seal it, cinches it, or checks every box\n";
			$prompt .= "- Never claim features not in the listing\n";
			$prompt .= "- Score under 50 if the land is a poor fit (too little acreage, HOA vs no HOA, wrong setting)\n";
			$prompt .= "- why must only explain a real fit. Never write that the land does not match. Omit a poor fit or score it under 50 with an empty why\n";
		} else {
			$prompt .= "- One or two short sentences. Name the criteria this home meets (city they picked, beds/baths/budget/garage, and the dream-home or don’t-want language)\n";
			$prompt .= "- Only mention a listing detail if it proves a search criterion (quiet cul-de-sac proves “no busy roads”). Do not list granite, loft, patio, sq ft, or year unless they asked for those\n";
			$prompt .= "- Bad: “You asked about a spot off the busy roads, and this Cave Creek listing has that.”\n";
			$prompt .= "- Bad: “Cave Creek family home with a loft, granite kitchen, and a tandem three-car garage.”\n";
			$prompt .= "- Good: “This is in Cave Creek, which you selected, and it sits off the busy roads you wanted to avoid. It meets your 4-bed / 2.5-bath search under your $2,000,000 cap.”\n";
			$prompt .= "- Do not copy remarks. Do not end with seal it, cinches it, or checks every box\n";
			$prompt .= "- Never claim features not in the listing\n";
			$prompt .= "- Score under 50 if the home is a poor fit (wrong setting, HOA vs no HOA, cabin vs subdivision, size far off)\n";
			$prompt .= "- why must only explain a real fit. Never write that the home does not match. Omit a poor fit or score it under 50 with an empty why\n";
		}
		$prompt .= "- Each listing's why should sound a little different\n";
		$prompt .= "- Return ONLY valid JSON";
		
		return $prompt;
	}

	/**
	 * Build alert why prompt.
	 */
	private static function build_alert_why_prompt( $listing, $search_params, $user_reactions ) {
		$land   = class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $search_params );
		$kind   = $land ? 'land' : 'home';
		$prompt = "You are a real estate assistant. Decide if this listing is a genuine fit. If it is not, reply with exactly NO_MATCH and nothing else. If it is, confirm the search criteria this " . $kind . " meets in one or two short sentences";
		
		if ( ! empty( $user_reactions ) ) {
			$prompt .= ", using their previous feedback";
		}
		
		$prompt .= ".\n\n";
		$prompt .= self::search_criteria_block( $search_params );
		
		if ( ! empty( $user_reactions ) ) {
			$prompt .= "BUYER'S PREVIOUS FEEDBACK:\n";
			foreach ( $user_reactions as $reaction ) {
				if ( ! empty( $reaction['what_like'] ) ) {
					$prompt .= "Liked: " . $reaction['what_like'] . "\n";
				}
				if ( ! empty( $reaction['what_dislike'] ) ) {
					$prompt .= "Disliked: " . $reaction['what_dislike'] . "\n";
				}
			}
			$prompt .= "\n";
		}
		
		if ( $land ) {
			$prompt .= sprintf(
				"NEW LISTING:\n%s, %s - $%s - raw land%s\n%s\n\n",
				$listing['address'],
				$listing['city'],
				number_format( $listing['price'] ),
				! empty( $listing['lot_size'] ) ? ' - ' . $listing['lot_size'] : '',
				$listing['remarks']
			);
			$prompt .= "If this land conflicts with what they asked for (for example HOA vs no HOA, too little acreage, wrong setting), reply NO_MATCH. Otherwise write one or two short sentences that confirm the search criteria this land meets. Do not mention bedrooms, bathrooms, or a garage. Do not retell the remarks. Never write “you asked about X, and this city listing has that.” Use only listing facts. Never say it does not match. No filler closer.";
		} else {
			$prompt .= sprintf(
				"NEW LISTING:\n%s, %s - $%s - %d bed, %.1f bath\n%s\n\n",
				$listing['address'],
				$listing['city'],
				number_format( $listing['price'] ),
				$listing['beds'],
				$listing['baths'],
				$listing['remarks']
			);
			$prompt .= "If this home conflicts with what they asked for (for example HOA vs no HOA, subdivision vs secluded cabin, much larger than they want), reply NO_MATCH. Otherwise write one or two short sentences that confirm the search criteria this home meets. Do not retell the remarks. Never write “you asked about X, and this city listing has that.” Use only listing facts. Never say it does not match. No filler closer.";
		}
		
		return $prompt;
	}

	/**
	 * Build broker summary prompt.
	 */
	private static function build_broker_summary_prompt( $search_params, $reactions ) {
		$prompt = "You are a real estate broker's assistant. Analyze this buyer's search and feedback to help the broker understand their preferences.\n\n";
		$prompt .= "SEARCH CRITERIA:\n";
		$prompt .= "Dream home: " . $search_params['dream_home'] . "\n";
		$prompt .= "Don't want: " . $search_params['dont_want'] . "\n";
		$prompt .= "Location: " . $search_params['location'] . "\n";
		$prompt .= sprintf( "Beds: %d (%s) | Baths: %.1f (%s) | Max price: $%s\n\n",
			$search_params['beds'],
			$search_params['beds_mode'],
			$search_params['baths'],
			$search_params['baths_mode'],
			number_format( $search_params['max_price'] )
		);
		
		$prompt .= "FEEDBACK ON SHOWN LISTINGS:\n";
		foreach ( $reactions as $reaction ) {
			$prompt .= "Property: " . $reaction['address'] . "\n";
			if ( ! empty( $reaction['what_like'] ) ) {
				$prompt .= "  Liked: " . $reaction['what_like'] . "\n";
			}
			if ( ! empty( $reaction['what_dislike'] ) ) {
				$prompt .= "  Disliked: " . $reaction['what_dislike'] . "\n";
			}
			$prompt .= "\n";
		}
		
		$prompt .= "Return strict JSON:\n";
		$prompt .= "{\n";
		$prompt .= '  "summary": "2-3 sentence overview of what this buyer wants",';
		$prompt .= "\n";
		$prompt .= '  "patterns": ["Bullet point pattern 1", "Bullet point pattern 2", ...]';
		$prompt .= "\n}\n\n";
		$prompt .= "Patterns should identify themes in their feedback (e.g., 'Consistently prefers updated kitchens', 'Dislikes busy streets').";
		
		return $prompt;
	}

	/**
	 * Fallback rankings without AI.
	 */
	private static function generate_fallback_rankings( $listings, $search_params, $taste_notes = array() ) {
		$rankings = array();

		foreach ( $listings as $idx => $listing ) {
			$score = 70 + self::taste_score_delta( $listing, $taste_notes ) + self::piles_score_delta( $listing, $search_params );
			if ( $score < 35 ) {
				$score = 35;
			}
			if ( $score > 98 ) {
				$score = 98;
			}
			$rankings[] = array(
				'listing_index' => $idx,
				'score' => $score,
				'why' => self::sanitize_why_line( self::human_why_line( $listing, $search_params, $taste_notes ), $listing, $search_params, $taste_notes ),
			);
		}

		return array( 'rankings' => $rankings );
	}

	/**
	 * Trim empty closers and copied listing copy. Keep the rest of Claude’s line.
	 * Use the local fill-in only when nothing useful is left.
	 */
	public static function sanitize_why_line( $why, $listing = array(), $search_params = array(), $taste_notes = array() ) {
		$why = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $why ) ) );
		$why = preg_replace( '/[.!?]?\s*That is what stands out on this one\.?/i', '', $why );
		$why = preg_replace( '/\s+—\s+that fits .+ you mentioned\.?$/i', '', $why );
		$why = preg_replace( '/\s+(that )?(really )?(seals|cinches) it\.?$/i', '.', $why );
		$why = preg_replace( '/\s+seal it\.?$/i', '.', $why );
		$why = preg_replace( '/\s+(that )?(really )?(checks|ticks) (all|every) the boxes\.?$/i', '.', $why );
		$why = preg_replace( '/[^.!?]*you asked about .+ and this .+ listing has that[.!]?\s*/i', '', $why );
		$why = trim( preg_replace( '/\s+/', ' ', (string) $why ), " \t\n\r-" );

		$remarks = isset( $listing['remarks'] ) ? trim( $listing['remarks'] ) : '';
		$first   = '';
		if ( $remarks ) {
			$parts = preg_split( '/(?<=[.!?])\s+/', $remarks, 2 );
			$first = isset( $parts[0] ) ? rtrim( $parts[0], ". \t\n\r" ) : '';
		}

		$sentences = preg_split( '/(?<=[.!?])\s+/', $why, -1, PREG_SPLIT_NO_EMPTY );
		$kept      = array();
		foreach ( $sentences as $sentence ) {
			$clean = rtrim( $sentence, ". \t\n\r" );
			if ( $clean === '' ) {
				continue;
			}
			if ( $first && strcasecmp( $clean, $first ) === 0 ) {
				continue;
			}
			$kept[] = rtrim( $sentence, " \t\n\r" );
		}

		$why = trim( implode( ' ', $kept ) );
		if ( $why !== '' && ! preg_match( '/[.!?]$/', $why ) ) {
			$why .= '.';
		}

		$words = preg_split( '/\s+/', $why, -1, PREG_SPLIT_NO_EMPTY );
		if ( $why === '' || count( $words ) < 4 ) {
			return self::human_why_line( $listing, $search_params, $taste_notes );
		}

		return $why;
	}

	/**
	 * Confirm the search criteria this listing meets.
	 * Used only if Claude cannot write the why-line.
	 */
	private static function human_why_line( $listing, $search_params, $taste_notes = array() ) {
		$remarks = isset( $listing['remarks'] ) ? trim( $listing['remarks'] ) : '';
		$dream   = strtolower( isset( $search_params['dream_home'] ) ? $search_params['dream_home'] : '' );
		$avoid   = strtolower( isset( $search_params['dont_want'] ) ? $search_params['dont_want'] : '' );
		$city    = isset( $listing['city'] ) ? $listing['city'] : '';
		$hits    = array();

		$areas = class_exists( 'Housemajik_Locations' )
			? Housemajik_Locations::parse( isset( $search_params['location'] ) ? $search_params['location'] : '' )
			: array();
		if ( $city && $areas ) {
			foreach ( $areas as $area ) {
				if ( strcasecmp( $city, $area ) === 0 || ( strtolower( $area ) === 'desert hills' && stripos( $city, 'phoenix' ) !== false ) ) {
					$hits[] = 'in ' . $city . ', which you selected';
					break;
				}
			}
		}

		if ( $avoid && ( false !== strpos( $avoid, 'highway' ) || false !== strpos( $avoid, 'traffic' ) || false !== strpos( $avoid, 'busy' ) ) ) {
			if ( false !== stripos( $remarks, 'cul-de-sac' ) || false !== stripos( $remarks, 'quiet' ) || false !== stripos( $remarks, 'preserve' ) ) {
				$hits[] = 'off the busy roads you wanted to avoid';
			}
		} elseif ( $dream && ( false !== strpos( $dream, 'quiet' ) || false !== strpos( $dream, 'secluded' ) ) ) {
			if ( false !== stripos( $remarks, 'quiet' ) || false !== stripos( $remarks, 'cul-de-sac' ) || false !== stripos( $remarks, 'preserve' ) ) {
				$hits[] = 'the quieter setting you described';
			}
		}

		$land = class_exists( 'Housemajik_Security' ) && Housemajik_Security::wants_land( $search_params );
		if ( ! $land ) {
			if ( ! empty( $search_params['beds'] ) ) {
				$mode = isset( $search_params['beds_mode'] ) && $search_params['beds_mode'] === 'exactly' ? 'exactly' : 'at least';
				$hits[] = $mode . ' ' . (int) $search_params['beds'] . ' bedrooms';
			}
			if ( ! empty( $search_params['baths'] ) ) {
				$mode  = isset( $search_params['baths_mode'] ) && $search_params['baths_mode'] === 'exactly' ? 'exactly' : 'at least';
				$baths = rtrim( rtrim( number_format( (float) $search_params['baths'], 1, '.', '' ), '0' ), '.' );
				$hits[] = $mode . ' ' . $baths . ' baths';
			}
		}
		if ( ! empty( $search_params['max_price'] ) ) {
			$hits[] = 'under $' . number_format( (int) $search_params['max_price'] );
		}
		if ( ! $land && ! empty( $search_params['garage'] ) && $search_params['garage'] === 'yes' && ! empty( $listing['garage'] ) ) {
			$hits[] = 'a garage, as you asked';
		}
		if ( ! empty( $search_params['acres'] ) && (float) $search_params['acres'] > 0 ) {
			$hits[] = 'at least ' . Housemajik_Security::acres_phrase( $search_params['acres'] );
		}

		$kind = $land ? 'land' : 'home';
		if ( count( $hits ) >= 2 ) {
			$first = array_shift( $hits );
			$rest  = self::join_and( array_slice( $hits, 0, 3 ) );
			$why   = 'This ' . $kind . ' is ' . $first . '. It also meets your search for ' . $rest . '.';
		} elseif ( count( $hits ) === 1 ) {
			$why = 'This ' . $kind . ' matches your search: ' . $hits[0] . '.';
		} else {
			$why = $city
				? sprintf( 'This %s %s matches the filters you set.', $city, $kind )
				: 'This ' . $kind . ' matches the filters you set.';
		}

		$taste = self::taste_why_clause( $listing, $taste_notes );
		return $taste !== '' ? rtrim( $why, ' .' ) . '. ' . $taste : $why;
	}

	/**
	 * Score shift from prior notes. Public so tests can check it.
	 * Likes raise the score only when listing facts contain the liked words.
	 * Dislikes lower it the same way. Notes never invent listing facts.
	 */
	public static function taste_score_delta( $listing, $taste_notes ) {
		$taste_notes = self::normalize_taste_notes( $taste_notes );
		if ( empty( $taste_notes ) || ! is_array( $listing ) ) {
			return 0;
		}

		$haystack = self::listing_taste_haystack( $listing );
		$delta    = 0;
		foreach ( $taste_notes as $note ) {
			if ( ! empty( $note['like'] ) && self::taste_text_hits( $note['like'], $haystack ) ) {
				$delta += 10;
			}
			if ( ! empty( $note['dislike'] ) && self::taste_text_hits( $note['dislike'], $haystack ) ) {
				$delta -= 14;
			}
		}

		if ( $delta > 24 ) {
			return 24;
		}
		if ( $delta < -28 ) {
			return -28;
		}
		return $delta;
	}

	/**
	 * Must / want / never against listing facts only.
	 */
	public static function evaluate_piles( $listing, $search_params ) {
		$must  = isset( $search_params['must_have'] ) ? trim( (string) $search_params['must_have'] ) : '';
		$want  = isset( $search_params['would_like'] ) ? trim( (string) $search_params['would_like'] ) : '';
		$never = isset( $search_params['never'] ) ? trim( (string) $search_params['never'] ) : '';
		if ( $must === '' && ! empty( $search_params['dream_home'] ) && $want === '' ) {
			$must = trim( (string) $search_params['dream_home'] );
		}
		if ( $never === '' && ! empty( $search_params['dont_want'] ) ) {
			$never = trim( (string) $search_params['dont_want'] );
		}

		$out = array();
		if ( $must !== '' ) {
			$out['must'] = array(
				'text'   => self::pile_preview( $must ),
				'status' => self::pile_positive_status( $listing, $must, true ),
			);
		}
		if ( $want !== '' ) {
			$out['want'] = array(
				'text'   => self::pile_preview( $want ),
				'status' => self::pile_positive_status( $listing, $want, false ) === 'met' ? 'yes' : 'unknown',
			);
		}
		if ( $never !== '' ) {
			$out['never'] = array(
				'text'   => self::pile_preview( $never ),
				'status' => self::pile_never_status( $listing, $never ),
			);
		}

		return $out;
	}

	public static function piles_score_delta( $listing, $search_params ) {
		$piles = self::evaluate_piles( $listing, $search_params );
		$delta = 0;
		if ( ! empty( $piles['must']['status'] ) && $piles['must']['status'] === 'met' ) {
			$delta += 8;
		}
		if ( ! empty( $piles['must']['status'] ) && $piles['must']['status'] === 'missed' ) {
			$delta -= 18;
		}
		if ( ! empty( $piles['want']['status'] ) && $piles['want']['status'] === 'yes' ) {
			$delta += 6;
		}
		if ( ! empty( $piles['never']['status'] ) && $piles['never']['status'] === 'hit' ) {
			$delta -= 16;
		}
		return $delta;
	}

	private static function pile_preview( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
		if ( strlen( $text ) > 72 ) {
			return rtrim( substr( $text, 0, 69 ) ) . '…';
		}
		return $text;
	}

	private static function pile_positive_status( $listing, $text, $allow_missed ) {
		$haystack = self::listing_taste_haystack( $listing );
		$lower    = strtolower( $text );

		if ( $allow_missed && ( false !== strpos( $lower, 'garage' ) ) && self::must_asks_more_than_garage( $text, $haystack ) ) {
			return empty( $listing['garage'] ) ? 'missed' : 'unknown';
		}

		if ( self::taste_text_hits( $text, $haystack ) ) {
			return 'met';
		}

		if ( $allow_missed ) {
			$tokens = self::taste_tokens( $text );
			foreach ( $tokens as $token ) {
				if ( self::haystack_has_token( $haystack, $token ) && self::mention_is_denied( $token, $haystack ) ) {
					return 'missed';
				}
			}
		}

		if ( $allow_missed && ( false !== strpos( $lower, 'garage' ) ) ) {
			if ( empty( $listing['garage'] ) ) {
				return 'missed';
			}
			return 'met';
		}

		return 'unknown';
	}

	/**
	 * A garage alone can confirm a must. Cabin, acres, or a view cannot be inferred from it.
	 */
	private static function must_asks_more_than_garage( $text, $haystack ) {
		$themes = array(
			'secluded', 'quiet', 'acre', 'acres', 'pool', 'view', 'wooded',
			'preserve', 'culdesac', 'cabin', 'hoa',
		);
		$tokens = self::taste_tokens( $text );
		$asked  = array();
		foreach ( $tokens as $token ) {
			if ( in_array( $token, $themes, true ) ) {
				$asked[] = $token;
			}
		}
		if ( ! $asked ) {
			return false;
		}
		foreach ( $asked as $token ) {
			if ( false !== strpos( $haystack, $token ) ) {
				return false;
			}
		}
		return true;
	}

	private static function pile_never_status( $listing, $text ) {
		$haystack = self::listing_taste_haystack( $listing );
		$lower    = strtolower( $text );
		if ( false !== strpos( $lower, 'hoa' ) ) {
			if ( false !== strpos( $haystack, 'no hoa' ) || false !== strpos( $haystack, 'without an hoa' ) ) {
				return 'clear';
			}
			if ( false !== strpos( $haystack, 'hoa' ) ) {
				return 'hit';
			}
			return 'unknown';
		}
		if ( self::taste_text_hits( $text, $haystack ) ) {
			return 'hit';
		}
		return 'unknown';
	}

	private static function taste_why_clause( $listing, $taste_notes ) {
		$taste_notes = self::normalize_taste_notes( $taste_notes );
		if ( empty( $taste_notes ) ) {
			return '';
		}

		$haystack = self::listing_taste_haystack( $listing );
		foreach ( $taste_notes as $note ) {
			if ( empty( $note['like'] ) || ! self::taste_text_hits( $note['like'], $haystack ) ) {
				continue;
			}
			$place = ! empty( $note['city'] ) ? $note['city'] : 'a home you already viewed';
			return 'It also has something you liked on the ' . $place . ' listing.';
		}

		return '';
	}

	private static function taste_notes_block( $taste_notes ) {
		$taste_notes = self::normalize_taste_notes( $taste_notes );
		if ( empty( $taste_notes ) ) {
			return '';
		}

		$prompt = "BUYER NOTES ON HOMES THEY ALREADY VIEWED (use only if this listing’s facts support it):\n";
		foreach ( $taste_notes as $note ) {
			$place = trim( ( ! empty( $note['address'] ) ? $note['address'] : 'Earlier listing' ) . ( ! empty( $note['city'] ) ? ', ' . $note['city'] : '' ) );
			if ( ! empty( $note['like'] ) ) {
				$prompt .= '- Liked about ' . $place . ': ' . $note['like'] . "\n";
			}
			if ( ! empty( $note['dislike'] ) ) {
				$prompt .= '- Did not like about ' . $place . ': ' . $note['dislike'] . "\n";
			}
		}
		$prompt .= "\n";
		return $prompt;
	}

	private static function normalize_taste_notes( $taste_notes ) {
		if ( ! is_array( $taste_notes ) ) {
			return array();
		}
		$out = array();
		foreach ( $taste_notes as $note ) {
			if ( ! is_array( $note ) ) {
				continue;
			}
			$like    = isset( $note['like'] ) ? trim( wp_strip_all_tags( (string) $note['like'] ) ) : '';
			$dislike = isset( $note['dislike'] ) ? trim( wp_strip_all_tags( (string) $note['dislike'] ) ) : '';
			if ( $like === '' && $dislike === '' ) {
				continue;
			}
			$out[] = array(
				'listing_id' => isset( $note['listing_id'] ) ? (string) $note['listing_id'] : '',
				'address'    => isset( $note['address'] ) ? (string) $note['address'] : '',
				'city'       => isset( $note['city'] ) ? (string) $note['city'] : '',
				'like'       => $like,
				'dislike'    => $dislike,
			);
		}
		return $out;
	}

	private static function listing_taste_haystack( $listing ) {
		$raw = strtolower(
			implode(
				' ',
				array(
					isset( $listing['city'] ) ? $listing['city'] : '',
					isset( $listing['address'] ) ? $listing['address'] : '',
					isset( $listing['remarks'] ) ? $listing['remarks'] : '',
					isset( $listing['lot_size'] ) ? $listing['lot_size'] : '',
					isset( $listing['garage'] ) ? $listing['garage'] : '',
				)
			)
		);
		$raw = preg_replace( '/[^a-zA-Z0-9 ]/', ' ', $raw );
		$raw = preg_replace( '/\bcul\s+de\s+sac\b/', 'culdesac', $raw );
		return trim( preg_replace( '/\s+/', ' ', (string) $raw ) );
	}

	private static function taste_text_hits( $note, $haystack ) {
		$tokens = self::taste_tokens( $note );
		if ( empty( $tokens ) || $haystack === '' ) {
			return false;
		}

		$strong = array(
			'hoa', 'secluded', 'quiet', 'acre', 'acres', 'traffic', 'neighbors',
			'garage', 'pool', 'highway', 'flood', 'view', 'busy', 'wooded',
			'preserve', 'culdesac', 'cabin', 'well', 'septic', 'electric',
		);
		$hits = 0;
		foreach ( $tokens as $token ) {
			if ( ! self::haystack_has_token( $haystack, $token ) ) {
				continue;
			}
			if ( self::mention_is_denied( $token, $haystack ) ) {
				continue;
			}
			if ( in_array( $token, $strong, true ) || count( $tokens ) === 1 ) {
				return true;
			}
			$hits++;
		}

		return $hits >= 2;
	}

	private static function haystack_has_token( $haystack, $token ) {
		return (bool) preg_match( '/\b' . preg_quote( (string) $token, '/' ) . '\b/', $haystack );
	}

	/**
	 * "No well yet" / "a well would need to be drilled" is not having a well.
	 */
	private static function mention_is_denied( $token, $haystack ) {
		$token = strtolower( (string) $token );
		if ( $token === '' || $haystack === '' ) {
			return false;
		}
		$quoted   = preg_quote( $token, '/' );
		$patterns = array(
			'/\bno\s+' . $quoted . '\b/',
			'/\bnot\s+(a\s+|an\s+)?' . $quoted . '\b/',
			'/\bwithout\s+(a\s+|an\s+)?' . $quoted . '\b/',
			'/\b' . $quoted . '\s+would\s+need\b/',
			'/\b' . $quoted . '\s+needs?\s+to\b/',
			'/\b' . $quoted . '\s+yet\b/',
			'/\bneed(?:s|ed)?\s+(a\s+|an\s+)?' . $quoted . '\b/',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $haystack ) ) {
				return true;
			}
		}
		return false;
	}

	private static function taste_tokens( $text ) {
		$text  = strtolower( preg_replace( '/[^a-zA-Z0-9 ]/', ' ', (string) $text ) );
		$text  = preg_replace( '/\bcul\s+de\s+sac\b/', 'culdesac', $text );
		$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$stop  = array(
			'this', 'that', 'with', 'from', 'have', 'been', 'very', 'really', 'about',
			'they', 'them', 'their', 'home', 'house', 'property', 'listing', 'just',
			'also', 'like', 'dont', 'want', 'would', 'could', 'should', 'there',
			'here', 'were', 'when', 'what', 'your', 'ours',
		);
		$strong = array(
			'hoa', 'secluded', 'quiet', 'acre', 'acres', 'traffic', 'neighbors',
			'garage', 'pool', 'highway', 'flood', 'view', 'busy', 'wooded',
			'preserve', 'culdesac', 'cabin', 'well', 'septic', 'electric',
		);
		$out = array();
		foreach ( $words as $word ) {
			if ( in_array( $word, $strong, true ) ) {
				$out[] = $word;
				continue;
			}
			if ( strlen( $word ) < 4 || in_array( $word, $stop, true ) ) {
				continue;
			}
			$out[] = $word;
		}
		return array_values( array_unique( $out ) );
	}

	private static function search_criteria_block( $search_params ) {
		$prompt  = "SEARCH CRITERIA TO CONFIRM:\n";
		if ( ! empty( $search_params['must_have'] ) ) {
			$prompt .= 'Must have: ' . $search_params['must_have'] . "\n";
		}
		if ( ! empty( $search_params['would_like'] ) ) {
			$prompt .= 'Would like: ' . $search_params['would_like'] . "\n";
		}
		if ( ! empty( $search_params['never'] ) ) {
			$prompt .= 'Never: ' . $search_params['never'] . "\n";
		} elseif ( ! empty( $search_params['dont_want'] ) ) {
			$prompt .= "Don't want: " . $search_params['dont_want'] . "\n";
		} elseif ( ! empty( $search_params['dream_home'] ) && empty( $search_params['must_have'] ) ) {
			$prompt .= 'Dream home: ' . $search_params['dream_home'] . "\n";
		}
		if ( ! empty( $search_params['land_only'] ) ) {
			$prompt .= "Land only: yes. Do not mention bedrooms, bathrooms, or a garage.\n";
		}
		if ( ! empty( $search_params['location'] ) ) {
			$area    = class_exists( 'Housemajik_Locations' )
				? Housemajik_Locations::display( $search_params['location'] )
				: $search_params['location'];
			$prompt .= 'Cities: ' . $area . "\n";
		}
		$land = ! empty( $search_params['land_only'] );
		if ( ! $land && ! empty( $search_params['beds'] ) ) {
			$mode    = isset( $search_params['beds_mode'] ) && $search_params['beds_mode'] === 'exactly' ? 'exactly' : 'at least';
			$prompt .= 'Bedrooms: ' . $mode . ' ' . (int) $search_params['beds'] . "\n";
		}
		if ( ! $land && ! empty( $search_params['baths'] ) ) {
			$mode    = isset( $search_params['baths_mode'] ) && $search_params['baths_mode'] === 'exactly' ? 'exactly' : 'at least';
			$prompt .= 'Bathrooms: ' . $mode . ' ' . $search_params['baths'] . "\n";
		}
		if ( ! empty( $search_params['max_price'] ) ) {
			$prompt .= 'Max price: $' . number_format( (int) $search_params['max_price'] ) . "\n";
		}
		if ( ! $land && ! empty( $search_params['garage'] ) && $search_params['garage'] !== 'dont_care' ) {
			$prompt .= 'Garage: ' . $search_params['garage'] . "\n";
		}
		if ( ! empty( $search_params['acres'] ) && (float) $search_params['acres'] > 0 ) {
			$prompt .= 'Acres: at least ' . Housemajik_Security::acres_phrase( $search_params['acres'] ) . "\n";
		}
		$prompt .= "\n";
		return $prompt;
	}

	/**
	 * Join 1-2 phrases without an Oxford-comma list.
	 */
	private static function join_and( $items ) {
		$items = array_values( $items );
		if ( count( $items ) === 1 ) {
			return $items[0];
		}
		return $items[0] . ' and ' . $items[1];
	}

	/**
	 * Prefer a factual remarks sentence over brochure openers.
	 */
	private static function concrete_remark_sentence( $remarks ) {
		if ( ! $remarks ) {
			return '';
		}

		$sentences = preg_split( '/(?<=[.!?])\s+/', $remarks );
		if ( ! is_array( $sentences ) ) {
			return '';
		}

		$skip = '/^(stunning|beautiful|gorgeous|spectacular|luxurious|amazing|incredible)\b/i';
		foreach ( $sentences as $sentence ) {
			$sentence = trim( $sentence );
			if ( strlen( $sentence ) < 20 ) {
				continue;
			}
			if ( preg_match( $skip, $sentence ) ) {
				continue;
			}
			return rtrim( $sentence, " \t\n\r" );
		}

		$first = trim( $sentences[0] );
		return $first ? rtrim( $first, " \t\n\r" ) : '';
	}

	/**
	 * Get API key from wp-config.php constant.
	 */
	private static function get_api_key() {
		if ( defined( 'ANTHROPIC_API_KEY' ) && ANTHROPIC_API_KEY ) {
			return trim( (string) ANTHROPIC_API_KEY );
		}
		
		return false;
	}

	/**
	 * Current Claude model, mapping retired IDs to live ones.
	 */
	public static function get_model() {
		$stored   = get_option( 'housemajik_ai_model', self::DEFAULT_MODEL );
		$resolved = self::resolve_model( $stored );
		if ( $resolved !== $stored ) {
			update_option( 'housemajik_ai_model', $resolved );
		}
		return $resolved;
	}

	/**
	 * Map retired Anthropic model IDs to current aliases.
	 */
	public static function resolve_model( $model ) {
		$aliases = array(
			'claude-3-haiku-20240307'     => 'claude-haiku-4-5',
			'claude-3-5-haiku-20241022'   => 'claude-haiku-4-5',
			'claude-3-sonnet-20240229'    => 'claude-sonnet-5',
			'claude-3-5-sonnet-20240620'  => 'claude-sonnet-5',
			'claude-3-5-sonnet-20241022'  => 'claude-sonnet-5',
			'claude-3-7-sonnet-20250219'  => 'claude-sonnet-5',
			'claude-3-opus-20240229'      => 'claude-opus-5',
		);

		return isset( $aliases[ $model ] ) ? $aliases[ $model ] : $model;
	}

	/**
	 * Extract a JSON object from Claude text, including fenced markdown.
	 */
	private static function parse_json_response( $text ) {
		$text = trim( (string) $text );
		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $text, $matches ) ) {
			$text = $matches[1];
		} elseif ( preg_match( '/\{.*\}/s', $text, $matches ) ) {
			$text = $matches[0];
		}

		$parsed = json_decode( $text, true );
		return ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) ? $parsed : null;
	}

	/**
	 * Store a sanitized last-error for the AI settings screen.
	 */
	private static function record_error( $message ) {
		$message = preg_replace( '/sk-ant-[A-Za-z0-9_-]+/', '[redacted]', (string) $message );
		$message = wp_strip_all_tags( $message );
		if ( strlen( $message ) > 500 ) {
			$message = substr( $message, 0, 497 ) . '...';
		}

		update_option(
			'housemajik_ai_last_error',
			array(
				'time'    => current_time( 'mysql' ),
				'message' => $message,
			),
			false
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Housemagik AI: ' . $message );
		}
	}

	private static function clear_error() {
		delete_option( 'housemajik_ai_last_error' );
	}
}
