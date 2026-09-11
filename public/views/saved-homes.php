<?php
/**
 * Buyer saved and commented listings.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

$session_id = Housemajik_Security::get_session_id();
$homes      = Housemajik_Saved::homes_for_buyer( $session_id );
$heading    = Housemajik_Saved::page_heading( $homes );
$search_url = Housemajik_Security::get_search_page_url();

get_header();

wp_enqueue_style(
	'housemajik-fonts',
	'https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700&display=swap',
	array(),
	null
);
wp_enqueue_style( 'housemajik-public', HOUSEMAJIK_PLUGIN_URL . 'public/css/housemagik-public.css', array( 'housemajik-fonts' ), HOUSEMAJIK_VERSION );
wp_enqueue_script( 'housemajik-public', HOUSEMAJIK_PLUGIN_URL . 'public/js/housemagik-public.js', array( 'jquery' ), HOUSEMAJIK_VERSION, true );
wp_localize_script(
	'housemajik-public',
	'housemajik',
	array(
		'ajax_url'   => admin_url( 'admin-ajax.php' ),
		'nonce'      => wp_create_nonce( 'housemajik_ajax' ),
		'home_url'   => home_url(),
		'search_url' => $search_url,
	)
);
?>

<div class="housemajik-detail housemajik-saved-page">
	<div class="housemajik-detail-content">
		<div class="housemajik-detail-main">
			<h1><?php echo esc_html( $heading ); ?></h1>

			<?php if ( empty( $homes ) ) : ?>
				<div class="housemajik-empty" style="display: block;">
					<h3>Nothing saved yet</h3>
					<p class="housemajik-empty-copy">Search, then tap Save this property or leave a comment. They will show up here.</p>
					<p class="housemajik-empty-actions">
						<a class="housemajik-btn housemajik-btn-primary" href="<?php echo esc_url( $search_url ); ?>">Back to Search</a>
					</p>
				</div>
			<?php else : ?>
				<div class="housemajik-listings">
					<?php foreach ( $homes as $item ) :
						$listing = $item['listing'];
						$photo   = ! empty( $listing['photos'][0] ) ? $listing['photos'][0] : '';
						$url     = home_url( '/property/' . rawurlencode( $listing['id'] ) );
						$tags    = array();
						if ( $item['saved'] ) {
							$tags[] = 'Saved';
						}
						if ( $item['notes'] ) {
							$tags[] = 'Comments';
						}
						?>
						<article class="housemajik-listing-card">
							<?php if ( $photo ) : ?>
								<a href="<?php echo esc_url( $url ); ?>">
									<img class="housemajik-listing-image" src="<?php echo esc_url( $photo ); ?>" alt="<?php echo esc_attr( $listing['address'] ); ?>">
								</a>
							<?php endif; ?>
							<div class="housemajik-listing-content">
								<p class="housemajik-saved-tags"><?php echo esc_html( implode( ' · ', $tags ) ); ?></p>
								<h2 class="housemajik-listing-address">
									<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $listing['address'] ); ?></a>
								</h2>
								<p class="housemajik-listing-city"><?php echo esc_html( $listing['city'] ); ?></p>
								<p class="housemajik-listing-price">$<?php echo number_format( $listing['price'] ); ?></p>
								<?php if ( $item['like'] !== '' ) : ?>
									<p class="housemajik-listing-why"><strong>Liked:</strong> <?php echo esc_html( $item['like'] ); ?></p>
								<?php endif; ?>
								<?php if ( $item['dislike'] !== '' ) : ?>
									<p class="housemajik-listing-why"><strong>Did not like:</strong> <?php echo esc_html( $item['dislike'] ); ?></p>
								<?php endif; ?>
								<div class="housemajik-listing-actions">
									<a class="housemajik-btn-link" href="<?php echo esc_url( $url ); ?>">View Details</a>
									<?php if ( $item['saved'] ) : ?>
										<button type="button" class="housemajik-btn housemajik-btn-secondary housemajik-save-property" data-listing-id="<?php echo esc_attr( $listing['id'] ); ?>" data-saved="1">Saved</button>
									<?php endif; ?>
								</div>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
				<p class="housemajik-saved-back">
					<a href="<?php echo esc_url( $search_url ); ?>">← Back to Search</a>
				</p>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php
get_footer();
