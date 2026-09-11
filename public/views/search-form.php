<?php
/**
 * Search form template.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

$data_source = get_option( 'housemajik_data_source', 'sample' );
?>

<div class="housemajik-container" id="housemajik-app">
	<!--
		THESIS: the form is a North Valley search instrument, not a SaaS card.
		OWN-WORLD: Lato, #847252 gold, charcoal chrome, 0-radius fields, white listing paper.
		STORY: describe a home; matches read like her listing site.
		FIRST VIEWPORT: dark sharp form, tracked title, gold Show me houses closest to this.
		FORM: pinned to mynorthvalleyhome.com search widget; brief beats the roll.
		FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, and DESIGN.md
	-->
	<!-- Search Form -->
	<div class="housemajik-search-form" id="housemajik-form">
		<div class="housemajik-header">
			<img
				class="housemajik-header-logo"
				src="<?php echo esc_url( HOUSEMAJIK_PLUGIN_URL . 'public/images/keys-of-dreams-logo.webp' ); ?>"
				alt="Keys of Dreams Brokery"
				width="200"
				height="170"
			>
			<h2><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php if ( ! empty( $welcome_name ) ) : ?>
				<p class="housemajik-welcome">Welcome back, <?php echo esc_html( $welcome_name ); ?></p>
			<?php endif; ?>
		</div>
		
		<form id="housemajik-search" class="housemajik-form">
			<input type="hidden" name="website" value="" class="housemajik-honeypot">

			<div class="housemajik-field housemajik-land-field">
				<label class="housemajik-checkbox" for="land_only">
					<input type="checkbox" name="land_only" id="land_only" value="1">
					<span>I'm looking for land only</span>
				</label>
			</div>
			
			<div class="housemajik-field">
				<label for="must_have">Must have</label>
				<textarea
					id="must_have"
					name="must_have"
					rows="3"
					placeholder="Without this, skip the house. (e.g., a garage, at least two acres, no 4-wheel drive needed)"
				></textarea>
			</div>

			<div class="housemajik-field">
				<label for="would_like">Would like</label>
				<textarea
					id="would_like"
					name="would_like"
					rows="3"
					placeholder="Nice if you can get it. (e.g., a quiet street, a view, a wooded lot)"
				></textarea>
			</div>

			<div class="housemajik-field">
				<label for="never">Never</label>
				<textarea
					id="never"
					name="never"
					rows="2"
					placeholder="If it has this, it is a miss. (e.g., HOA, close neighbors, heavy traffic)"
				></textarea>
			</div>
			
			<div class="housemajik-row">
				<div class="housemajik-field housemajik-col-half">
					<label id="location-label" for="location-toggle">Where?</label>
					<div class="housemajik-multiselect" id="housemajik-location">
						<button type="button" id="location-toggle" class="housemajik-multiselect-toggle is-placeholder" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="location-label">
							<span class="housemajik-multiselect-value">Select cities</span>
						</button>
						<div class="housemajik-multiselect-panel" hidden>
							<ul class="housemajik-multiselect-list" role="listbox" aria-multiselectable="true" aria-labelledby="location-label">
								<?php foreach ( Housemajik_Locations::all() as $city ) : ?>
									<li role="option">
										<label class="housemajik-multiselect-option">
											<input type="checkbox" name="location[]" value="<?php echo esc_attr( $city ); ?>">
											<span><?php echo esc_html( $city ); ?></span>
										</label>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					</div>
				</div>
				<div class="housemajik-field housemajik-col-half">
					<label for="max_price">Max Price</label>
					<div class="housemajik-price-field">
						<span class="housemajik-price-prefix" aria-hidden="true">$</span>
						<input 
							type="text" 
							id="max_price" 
							name="max_price" 
							inputmode="numeric" 
							autocomplete="off"
							placeholder="500,000"
							required
						>
					</div>
				</div>
			</div>
			
			<div class="housemajik-row housemajik-row-rooms">
				<div class="housemajik-field housemajik-col-third housemajik-room-field">
					<label for="beds">Bedrooms</label>
					<div class="housemajik-input-group">
						<div class="housemajik-stepper">
							<button type="button" class="housemajik-stepper-btn" data-stepper="down" aria-label="Decrease bedrooms">&minus;</button>
							<input 
								type="number" 
								id="beds" 
								name="beds" 
								min="1" 
								max="10" 
								step="1" 
								value="3"
								inputmode="numeric"
							>
							<button type="button" class="housemajik-stepper-btn" data-stepper="up" aria-label="Increase bedrooms">+</button>
						</div>
						<select name="beds_mode" id="beds_mode">
							<option value="at_least">at least</option>
							<option value="exactly">exactly</option>
						</select>
					</div>
				</div>
				
				<div class="housemajik-field housemajik-col-third housemajik-room-field">
					<label for="baths">Bathrooms</label>
					<div class="housemajik-input-group">
						<div class="housemajik-stepper">
							<button type="button" class="housemajik-stepper-btn" data-stepper="down" aria-label="Decrease bathrooms">&minus;</button>
							<input 
								type="number" 
								id="baths" 
								name="baths" 
								min="1" 
								max="10" 
								step="0.5" 
								value="2"
								inputmode="decimal"
							>
							<button type="button" class="housemajik-stepper-btn" data-stepper="up" aria-label="Increase bathrooms">+</button>
						</div>
						<select name="baths_mode" id="baths_mode">
							<option value="at_least">at least</option>
							<option value="exactly">exactly</option>
						</select>
					</div>
				</div>

				<div class="housemajik-field housemajik-col-third housemajik-col-garage housemajik-room-field">
					<label for="garage">Garage</label>
					<select name="garage" id="garage">
						<option value="dont_care">Don't care</option>
						<option value="yes">Yes</option>
						<option value="no">No</option>
					</select>
				</div>

				<div class="housemajik-field housemajik-col-acres">
					<label for="acres">Minimum acreage</label>
					<input
						type="number"
						id="acres"
						name="acres"
						min="0"
						max="10000"
						step="0.1"
						value=""
						inputmode="decimal"
						placeholder="Any"
					>
				</div>
			</div>
			
			<div class="housemajik-submit">
				<button type="submit" class="housemajik-btn housemajik-btn-primary" id="housemajik-submit">
					<span class="housemajik-btn-text">Show me houses closest to this</span>
					<span class="housemajik-spinner" style="display: none;"></span>
				</button>
			</div>
		</form>
	</div>
	
	<!-- Results -->
	<div class="housemajik-results" id="housemajik-results" style="display: none;">
		<div class="housemajik-results-header">
			<h3 id="housemajik-results-title">Here are your top matches</h3>
			<p class="housemajik-tradeoff-copy" id="housemajik-tradeoff-copy" style="display: none;"></p>
		</div>
		<div class="housemajik-listings" id="housemajik-listings"></div>
		<div class="housemajik-tradeoff" id="housemajik-tradeoff" style="display: none;"></div>
	</div>
	
	<!-- Empty State -->
	<div class="housemajik-empty" id="housemajik-empty" style="display: none;">
		<h3>Nothing met every filter</h3>
		<p class="housemajik-empty-copy">Nothing currently listed met every filter you set.</p>
		<div class="housemajik-empty-actions">
			<button type="button" class="housemajik-btn housemajik-btn-primary" id="housemajik-empty-suggest" style="display: none;"></button>
			<button type="button" class="housemajik-btn housemajik-btn-secondary" id="housemajik-empty-edit">
				Change the search
			</button>
		</div>
	</div>
	
	<p class="housemajik-saved-homes-search">
		<a href="<?php echo esc_url( Housemajik_Saved::page_url() ); ?>"><?php echo esc_html( Housemajik_Saved::page_link_label( Housemajik_Saved::homes_for_buyer( Housemajik_Security::get_session_id() ) ) ); ?></a>
	</p>

	<?php include HOUSEMAJIK_PLUGIN_DIR . 'public/views/register-modal.php'; ?>

</div>

<?php
// Arizona calendar date for the footer stamp (avoids UTC showing the next day).
$as_of = wp_date( 'F j, Y', time(), new DateTimeZone( 'America/Phoenix' ) );
if ( $data_source === 'sample' ) {
	$idx_disclaimer = 'These are demonstration listings for testing. They are not live MLS data. Shown ' . $as_of . '.';
} else {
	$idx_disclaimer = get_option( 'housemajik_idx_disclaimer', '' );
	$idx_disclaimer = str_replace( '[DATE]', $as_of, $idx_disclaimer );
}
if ( ! empty( $idx_disclaimer ) ) :
?>
	<div class="housemajik-idx">
		<?php echo wp_kses_post( wpautop( $idx_disclaimer ) ); ?>
	</div>
<?php endif; ?>
