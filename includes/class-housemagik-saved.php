<?php
/**
 * Saved and commented listings for a buyer.
 */
class Housemajik_Saved {

	public static function maybe_upgrade() {
		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_reactions';
		$col   = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", 'saved' ) );
		if ( empty( $col ) ) {
			$wpdb->query( "ALTER TABLE $table ADD saved tinyint(1) NOT NULL DEFAULT 0" );
		}
	}

	public static function page_url() {
		return home_url( '/saved-homes/' );
	}

	/**
	 * A listing is land when the sample/MLS row says so.
	 */
	public static function listing_is_land( $listing ) {
		if ( ! is_array( $listing ) ) {
			return false;
		}
		if ( class_exists( 'Housemajik_Sample_Data' ) && method_exists( 'Housemajik_Sample_Data', 'is_land' ) ) {
			return Housemajik_Sample_Data::is_land( $listing );
		}
		$type = strtolower( isset( $listing['property_type'] ) ? (string) $listing['property_type'] : '' );
		return $type === 'land' || ! empty( $listing['land'] );
	}

	/**
	 * Word for a set of listings: homes, land, or properties when mixed.
	 */
	public static function collection_word( $items ) {
		$has_land = false;
		$has_home = false;
		foreach ( (array) $items as $item ) {
			$listing = ( is_array( $item ) && isset( $item['listing'] ) && is_array( $item['listing'] ) )
				? $item['listing']
				: $item;
			if ( ! is_array( $listing ) ) {
				continue;
			}
			if ( self::listing_is_land( $listing ) ) {
				$has_land = true;
			} else {
				$has_home = true;
			}
		}
		if ( $has_land && $has_home ) {
			return 'properties';
		}
		if ( $has_land ) {
			return 'land';
		}
		return 'homes';
	}

	public static function page_heading( $items ) {
		$word = self::collection_word( $items );
		if ( $word === 'land' ) {
			return 'Saved land';
		}
		if ( $word === 'properties' ) {
			return 'Saved properties';
		}
		return 'Saved homes';
	}

	public static function page_link_label( $items, $your = false ) {
		$heading = self::page_heading( $items );
		return $your ? ( 'Your ' . lcfirst( $heading ) ) : $heading;
	}

	public static function listing( $listing_id ) {
		$data_source = get_option( 'housemajik_data_source', 'sample' );
		if ( $data_source === 'armls' && Housemajik_ARMLS::is_configured() ) {
			$listing = Housemajik_ARMLS::get_listing( $listing_id );
			if ( ! is_wp_error( $listing ) && $listing ) {
				return $listing;
			}
		}
		return Housemajik_Sample_Data::get_listing( $listing_id );
	}

	public static function session_email( $session_id ) {
		if ( ! $session_id ) {
			return '';
		}
		global $wpdb;
		$email = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT email FROM {$wpdb->prefix}housemajik_sessions WHERE session_id = %s AND agent_id = %s AND email != '' ORDER BY created_at DESC LIMIT 1",
				$session_id,
				Housemajik_Agent::id()
			)
		);
		return is_email( $email ) ? $email : '';
	}

	public static function is_saved( $session_id, $listing_id ) {
		$row = self::row_for( $session_id, $listing_id );
		return $row && ! empty( $row->saved );
	}

	public static function row_for( $session_id, $listing_id ) {
		if ( ! $session_id || ! $listing_id ) {
			return null;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_reactions';
		$email = self::session_email( $session_id );
		if ( $email ) {
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $table WHERE listing_id = %s AND agent_id = %s AND (session_id = %s OR email = %s) ORDER BY updated_at DESC LIMIT 1",
					$listing_id,
					Housemajik_Agent::id(),
					$session_id,
					$email
				)
			);
		}
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE session_id = %s AND listing_id = %s AND agent_id = %s LIMIT 1",
				$session_id,
				$listing_id,
				Housemajik_Agent::id()
			)
		);
	}

	public static function toggle( $session_id, $listing_id, $saved ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'housemajik_reactions';
		$saved   = $saved ? 1 : 0;
		$now     = current_time( 'mysql' );
		$email   = self::session_email( $session_id );
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM $table WHERE session_id = %s AND listing_id = %s AND agent_id = %s LIMIT 1",
				$session_id,
				$listing_id,
				Housemajik_Agent::id()
			)
		);

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'saved'      => $saved,
					'email'      => $email ? $email : null,
					'agent_id'   => Housemajik_Agent::id(),
					'updated_at' => $now,
				),
				array( 'id' => $existing->id ),
				array( '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'session_id'   => $session_id,
					'email'        => $email ? $email : null,
					'agent_id'     => Housemajik_Agent::id(),
					'listing_id'   => $listing_id,
					'what_like'    => '',
					'what_dislike' => '',
					'saved'        => $saved,
					'created_at'   => $now,
					'updated_at'   => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
		}

		return (bool) $saved;
	}

	/**
	 * Recent like/dislike notes for ranking the next search.
	 */
	public static function taste_notes( $session_id = '', $email = '' ) {
		if ( ! $session_id && ! $email ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_reactions';
		$agent = Housemajik_Agent::id();

		if ( $session_id ) {
			$from_session = self::session_email( $session_id );
			if ( $from_session ) {
				$email = $from_session;
			}
		}

		if ( $session_id && $email && is_email( $email ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT listing_id, what_like, what_dislike, updated_at FROM $table
					WHERE agent_id = %s AND (session_id = %s OR email = %s)
					AND ( (what_like IS NOT NULL AND what_like != '') OR (what_dislike IS NOT NULL AND what_dislike != '') )
					ORDER BY updated_at DESC LIMIT 12",
					$agent,
					$session_id,
					$email
				)
			);
		} elseif ( $email && is_email( $email ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT listing_id, what_like, what_dislike, updated_at FROM $table
					WHERE agent_id = %s AND email = %s
					AND ( (what_like IS NOT NULL AND what_like != '') OR (what_dislike IS NOT NULL AND what_dislike != '') )
					ORDER BY updated_at DESC LIMIT 12",
					$agent,
					$email
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT listing_id, what_like, what_dislike, updated_at FROM $table
					WHERE session_id = %s AND agent_id = %s
					AND ( (what_like IS NOT NULL AND what_like != '') OR (what_dislike IS NOT NULL AND what_dislike != '') )
					ORDER BY updated_at DESC LIMIT 12",
					$session_id,
					$agent
				)
			);
		}

		$notes = array();
		$seen  = array();
		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $row ) {
			if ( isset( $seen[ $row->listing_id ] ) ) {
				continue;
			}
			$like    = trim( (string) $row->what_like );
			$dislike = trim( (string) $row->what_dislike );
			if ( $like === '' && $dislike === '' ) {
				continue;
			}
			$listing = self::listing( $row->listing_id );
			$seen[ $row->listing_id ] = true;
			$notes[] = array(
				'listing_id' => (string) $row->listing_id,
				'address'    => $listing && ! empty( $listing['address'] ) ? $listing['address'] : '',
				'city'       => $listing && ! empty( $listing['city'] ) ? $listing['city'] : '',
				'like'       => $like,
				'dislike'    => $dislike,
			);
			if ( count( $notes ) >= 8 ) {
				break;
			}
		}

		return $notes;
	}

	public static function homes_for_buyer( $session_id, $email = '' ) {
		if ( ! $session_id && ! is_email( $email ) ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_reactions';
		if ( ! is_email( $email ) && $session_id ) {
			$email = self::session_email( $session_id );
		}

		if ( $email && $session_id ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $table WHERE agent_id = %s AND (session_id = %s OR email = %s) ORDER BY updated_at DESC",
					Housemajik_Agent::id(),
					$session_id,
					$email
				)
			);
		} elseif ( $email ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $table WHERE agent_id = %s AND email = %s ORDER BY updated_at DESC",
					Housemajik_Agent::id(),
					$email
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $table WHERE session_id = %s AND agent_id = %s ORDER BY updated_at DESC",
					$session_id,
					Housemajik_Agent::id()
				)
			);
		}

		$homes  = array();
		$seen   = array();
		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $row ) {
			$has_notes = ( $row->what_like !== '' && $row->what_like !== null ) || ( $row->what_dislike !== '' && $row->what_dislike !== null );
			if ( empty( $row->saved ) && ! $has_notes ) {
				continue;
			}
			if ( isset( $seen[ $row->listing_id ] ) ) {
				continue;
			}
			$listing = self::listing( $row->listing_id );
			if ( ! $listing ) {
				continue;
			}
			$seen[ $row->listing_id ] = true;
			$homes[] = array(
				'listing' => $listing,
				'saved'   => ! empty( $row->saved ),
				'notes'   => $has_notes,
				'like'    => (string) $row->what_like,
				'dislike' => (string) $row->what_dislike,
			);
		}

		return $homes;
	}
}
