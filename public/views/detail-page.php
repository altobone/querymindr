<?php
/**
 * Listing detail page template.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

$listing_id = get_query_var( 'housemajik_listing' );

// Get listing data
$data_source = get_option( 'housemajik_data_source', 'sample' );

if ( $data_source === 'armls' && Housemajik_ARMLS::is_configured() ) {
	$listing = Housemajik_ARMLS::get_listing( $listing_id );
	if ( is_wp_error( $listing ) || ! $listing ) {
		$listing = Housemajik_Sample_Data::get_listing( $listing_id );
	}
} else {
	$listing = Housemajik_Sample_Data::get_listing( $listing_id );
}

$search_back = Housemajik_Security::get_search_page_url();

if ( ! $listing ) {
	wp_safe_redirect( $search_back );
	exit;
}

$is_land = Housemajik_Sample_Data::is_land( $listing );

$maps_key = sanitize_text_field( get_option( 'housemajik_google_maps_key', '' ) );
$listing_lat = isset( $listing['lat'] ) ? (float) $listing['lat'] : 0;
$listing_lng = isset( $listing['lng'] ) ? (float) $listing['lng'] : 0;
$show_map = ( $listing_lat && $listing_lng );

$photos = array();
if ( ! empty( $listing['photos'] ) && is_array( $listing['photos'] ) ) {
	foreach ( $listing['photos'] as $photo ) {
		$clean = esc_url_raw( $photo );
		if ( $clean ) {
			$photos[] = $clean;
		}
	}
}

get_header();

wp_enqueue_style(
	'housemajik-fonts',
	'https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700&display=swap',
	array(),
	null
);
wp_enqueue_style( 'housemajik-public', HOUSEMAJIK_PLUGIN_URL . 'public/css/housemagik-public.css', array( 'housemajik-fonts' ), HOUSEMAJIK_VERSION );
wp_enqueue_script( 'housemajik-public', HOUSEMAJIK_PLUGIN_URL . 'public/js/housemagik-public.js', array( 'jquery' ), HOUSEMAJIK_VERSION, true );
wp_localize_script( 'housemajik-public', 'housemajik', array(
	'ajax_url'    => admin_url( 'admin-ajax.php' ),
	'nonce'       => wp_create_nonce( 'housemajik_ajax' ),
	'home_url'    => home_url(),
	'search_url'  => $search_back,
	'listing_id'  => $listing_id,
	'is_detail'   => true,
	'data_source' => $data_source,
) );

if ( $show_map && $maps_key ) {
	wp_register_script( 'housemajik-listing-map-init', false, array(), HOUSEMAJIK_VERSION, true );
	wp_enqueue_script( 'housemajik-listing-map-init' );
	wp_add_inline_script(
		'housemajik-listing-map-init',
		'function housemajikInitListingMap(){var el=document.getElementById("housemajik-listing-map");if(!el||typeof google==="undefined"||!google.maps){return;}var lat=parseFloat(el.getAttribute("data-lat"));var lng=parseFloat(el.getAttribute("data-lng"));var title=el.getAttribute("data-title")||"";var position={lat:lat,lng:lng};var map=new google.maps.Map(el,{center:position,zoom:15,mapTypeControl:false,streetViewControl:false,fullscreenControl:true});new google.maps.Marker({position:position,map:map,title:title});}'
	);

	wp_enqueue_script(
		'housemajik-google-maps',
		'https://maps.googleapis.com/maps/api/js?key=' . rawurlencode( $maps_key ) . '&callback=housemajikInitListingMap',
		array( 'housemajik-listing-map-init' ),
		null,
		true
	);
	wp_script_add_data( 'housemajik-google-maps', 'async', true );
	wp_script_add_data( 'housemajik-google-maps', 'defer', true );
}
?>

<div class="housemajik-detail">
	
	<!-- Photos -->
	<div class="housemajik-detail-photos">
		<?php if ( ! empty( $photos ) ) : ?>
			<div class="housemajik-photo-stage housemajik-photo-stage-detail">
				<img
					src="<?php echo esc_url( $photos[0] ); ?>"
					alt="<?php echo esc_attr( $listing['address'] ); ?>"
					class="housemajik-detail-photo-main"
					id="main-photo"
				>
				<span class="housemajik-photo-nav housemajik-photo-prev" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M15 5L8 12l7 7" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="miter"/></svg></span>
				<span class="housemajik-photo-nav housemajik-photo-next" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="miter"/></svg></span>
				<span class="housemajik-photo-count">1 / 4</span>
			</div>
		<?php endif; ?>
	</div>
	
	<!-- Content -->
	<div class="housemajik-detail-content">
		
		<div class="housemajik-detail-main">
			<h1><?php echo esc_html( $listing['address'] ); ?></h1>
			<p class="housemajik-detail-sub">
				<?php echo esc_html( $listing['city'] . ', ' . $listing['state'] . ' ' . $listing['zip'] ); ?>
			</p>
			
			<div class="housemajik-detail-price">
				$<?php echo number_format( $listing['price'] ); ?>
			</div>
			
			<!-- Facts -->
			<div class="housemajik-detail-facts">
				<?php if ( $is_land ) : ?>
					<div class="housemajik-detail-fact">
						<span class="housemajik-detail-fact-label">Type</span>
						<span class="housemajik-detail-fact-value">Raw land</span>
					</div>
					<?php if ( ! empty( $listing['lot_size'] ) ) : ?>
						<div class="housemajik-detail-fact">
							<span class="housemajik-detail-fact-label">Lot Size</span>
							<span class="housemajik-detail-fact-value"><?php echo esc_html( $listing['lot_size'] ); ?></span>
						</div>
					<?php endif; ?>
				<?php else : ?>
					<div class="housemajik-detail-fact">
						<span class="housemajik-detail-fact-label">Bedrooms</span>
						<span class="housemajik-detail-fact-value"><?php echo esc_html( $listing['beds'] ); ?></span>
					</div>
					<div class="housemajik-detail-fact">
						<span class="housemajik-detail-fact-label">Bathrooms</span>
						<span class="housemajik-detail-fact-value"><?php echo esc_html( $listing['baths'] ); ?></span>
					</div>
					<div class="housemajik-detail-fact">
						<span class="housemajik-detail-fact-label">Square Feet</span>
						<span class="housemajik-detail-fact-value"><?php echo number_format( $listing['sqft'] ); ?></span>
					</div>
					<div class="housemajik-detail-fact">
						<span class="housemajik-detail-fact-label">Year Built</span>
						<span class="housemajik-detail-fact-value"><?php echo esc_html( $listing['year_built'] ); ?></span>
					</div>
					<?php if ( ! empty( $listing['garage'] ) ) : ?>
						<div class="housemajik-detail-fact">
							<span class="housemajik-detail-fact-label">Garage</span>
							<span class="housemajik-detail-fact-value"><?php echo esc_html( $listing['garage'] ); ?></span>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $listing['lot_size'] ) ) : ?>
						<div class="housemajik-detail-fact">
							<span class="housemajik-detail-fact-label">Lot Size</span>
							<span class="housemajik-detail-fact-value"><?php echo esc_html( $listing['lot_size'] ); ?></span>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			
			<!-- Description -->
			<h2 class="housemajik-detail-heading"><?php echo $is_land ? 'About this land' : 'About This Home'; ?></h2>
			<p class="housemajik-detail-copy">
				<?php echo nl2br( esc_html( $listing['remarks'] ) ); ?>
			</p>

			<?php
			$session_id    = Housemajik_Security::get_session_id();
			$saved_like    = '';
			$saved_dislike = '';
			$is_saved      = false;
			if ( $session_id ) {
				$saved_row = Housemajik_Saved::row_for( $session_id, $listing_id );
				if ( $saved_row ) {
					$saved_like    = $saved_row->what_like;
					$saved_dislike = $saved_row->what_dislike;
					$is_saved      = ! empty( $saved_row->saved );
				}
			}
			?>
			<div class="housemajik-save-property-row">
				<button
					type="button"
					class="housemajik-btn <?php echo $is_saved ? 'housemajik-btn-secondary' : 'housemajik-btn-primary'; ?> housemajik-save-property"
					data-listing-id="<?php echo esc_attr( $listing_id ); ?>"
					data-saved="<?php echo $is_saved ? '1' : '0'; ?>"
				><?php echo $is_saved ? 'Saved' : 'Save this property'; ?></button>
				<a class="housemajik-btn housemajik-btn-primary housemajik-saved-homes-btn" href="<?php echo esc_url( Housemajik_Saved::page_url() ); ?>"><?php echo esc_html( Housemajik_Saved::page_link_label( Housemajik_Saved::homes_for_buyer( $session_id ), true ) ); ?></a>
			</div>
			<div class="housemajik-listing-reactions" data-listing-id="<?php echo esc_attr( $listing_id ); ?>">
				<h4>Your thoughts on this property (optional)</h4>
				<textarea
					name="what_like"
					class="housemajik-reaction-like"
					placeholder="What I like about this one..."
				><?php echo esc_textarea( $saved_like ); ?></textarea>
				<textarea
					name="what_dislike"
					class="housemajik-reaction-dislike"
					placeholder="What I don't like..."
				><?php echo esc_textarea( $saved_dislike ); ?></textarea>
				<button type="button" class="housemajik-btn housemajik-btn-secondary housemajik-reaction-save">Save Notes</button>
			</div>
			
			<!-- Map -->
			<?php if ( $show_map ) : ?>
				<h2 class="housemajik-detail-heading">Location</h2>
				<?php if ( $maps_key ) : ?>
					<div
						class="housemajik-detail-map"
						id="housemajik-listing-map"
						data-lat="<?php echo esc_attr( $listing_lat ); ?>"
						data-lng="<?php echo esc_attr( $listing_lng ); ?>"
						data-title="<?php echo esc_attr( $listing['address'] ); ?>"
					></div>
				<?php else :
					$pad  = 0.012;
					$bbox = implode(
						',',
						array(
							$listing_lng - $pad,
							$listing_lat - $pad,
							$listing_lng + $pad,
							$listing_lat + $pad,
						)
					);
					$osm_embed = add_query_arg(
						array(
							'bbox'   => $bbox,
							'layer'  => 'mapnik',
							'marker' => $listing_lat . ',' . $listing_lng,
						),
						'https://www.openstreetmap.org/export/embed.html'
					);
					$osm_link = add_query_arg(
						array(
							'mlat' => $listing_lat,
							'mlon' => $listing_lng,
						),
						'https://www.openstreetmap.org/'
					) . '#map=15/' . rawurlencode( (string) $listing_lat ) . '/' . rawurlencode( (string) $listing_lng );
					?>
					<div class="housemajik-detail-map">
						<iframe
							title="<?php echo esc_attr( sprintf( 'Map of %s', $listing['address'] ) ); ?>"
							src="<?php echo esc_url( $osm_embed ); ?>"
							loading="lazy"
							referrerpolicy="no-referrer-when-downgrade"
						></iframe>
					</div>
					<p class="housemajik-map-attrib">
						<a href="<?php echo esc_url( $osm_link ); ?>" target="_blank" rel="noopener noreferrer">View larger map</a>
						· © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>
					</p>
				<?php endif; ?>
			<?php endif; ?>
			
			<!-- MLS Info -->
			<?php if ( $data_source === 'armls' && ! empty( $listing['id'] ) && strpos( $listing['id'], 'SAMPLE-' ) === false ) : ?>
				<p class="housemajik-mls">
					<strong>MLS#:</strong> <?php echo esc_html( $listing['id'] ); ?>
				</p>
			<?php endif; ?>
			
			<?php if ( $data_source === 'sample' ) : ?>
				<p class="housemajik-sample-callout">
					<strong>Sample Listing:</strong> This is demonstration data for testing purposes.
				</p>
			<?php endif; ?>
			
			<!-- IDX Disclaimer -->
			<?php
			$as_of = wp_date( 'F j, Y', time(), new DateTimeZone( 'America/Phoenix' ) );
			if ( $data_source === 'sample' ) {
				$idx_disclaimer = 'These are demonstration listings for testing. They are not live MLS data. Shown ' . $as_of . '.';
			} else {
				$idx_disclaimer = get_option( 'housemajik_idx_disclaimer', '' );
				$idx_disclaimer = str_replace( '[DATE]', $as_of, $idx_disclaimer );
			}
			if ( ! empty( $idx_disclaimer ) ) :
			?>
				<div class="housemajik-detail-idx">
					<?php echo wp_kses_post( wpautop( $idx_disclaimer ) ); ?>
				</div>
			<?php endif; ?>
		</div>
		
		<!-- Sidebar -->
		<div class="housemajik-detail-sidebar">
			<h3 class="housemajik-sidebar-title">
				Interested in this property?
			</h3>
			<p class="housemajik-sidebar-copy">
				Contact <?php echo esc_html( get_option( 'housemajik_broker_name', 'Suzanne Gonzalez' ) ); ?> 
				to schedule a showing or learn more.
			</p>
			
			<div>
				<strong class="housemajik-sidebar-name">
					<?php echo esc_html( get_option( 'housemajik_broker_name', 'Suzanne Gonzalez' ) ); ?>
				</strong>
				<span class="housemajik-sidebar-firm">
					<?php echo esc_html( get_option( 'housemajik_brokerage_name', 'Keys of Dreams Brokery' ) ); ?>
				</span>
			</div>
			
			<a 
				href="mailto:<?php echo esc_attr( Housemajik_Email::broker_contact_mailto() ); ?>?subject=<?php echo rawurlencode( 'Inquiry about ' . $listing['address'] ); ?>" 
				class="housemajik-btn housemajik-btn-primary housemajik-sidebar-ask"
			>
				Ask Suzanne
			</a>
			
			<a 
				href="<?php echo esc_url( preg_replace( '/#.*$/', '', $search_back ) ); ?>" 
				class="housemajik-btn housemajik-btn-secondary housemajik-back-to-listings housemajik-sidebar-back"
			>
				Back to Search
			</a>
		</div>
		
	</div>
	
</div>

<?php include HOUSEMAJIK_PLUGIN_DIR . 'public/views/register-modal.php'; ?>

<?php
get_footer();
?>
