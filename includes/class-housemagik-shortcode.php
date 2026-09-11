<?php
/**
 * [housemagik] shortcode.
 */
class Housemajik_Shortcode {

	/**
	 * Render shortcode.
	 */
	public function render( $atts ) {
		Housemajik_Security::remember_search_page();
		Housemajik_Security::maybe_clear_test_reset();

		$atts = shortcode_atts( array(
			'title' => 'Let us help you find your dream home',
		), $atts, 'housemagik' );
		$welcome_name = Housemajik_Security::returning_first_name();
		
		ob_start();
		include HOUSEMAJIK_PLUGIN_DIR . 'public/views/search-form.php';
		return ob_get_clean();
	}

	public function render_saved() {
		$this->enqueue_styles();
		$url   = Housemajik_Saved::page_url();
		$label = Housemajik_Saved::page_link_label( Housemajik_Saved::homes_for_buyer( Housemajik_Security::get_session_id() ), true );
		return '<p class="housemajik-saved-shortcode"><a class="housemajik-btn housemajik-btn-primary housemajik-saved-homes-btn" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * Enqueue styles.
	 */
	public function enqueue_styles() {
		wp_enqueue_style(
			'housemajik-fonts',
			'https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700&display=swap',
			array(),
			null
		);

		wp_enqueue_style( 
			'housemajik-public', 
			HOUSEMAJIK_PLUGIN_URL . 'public/css/housemagik-public.css', 
			array( 'housemajik-fonts' ), 
			HOUSEMAJIK_VERSION 
		);
	}

	/**
	 * Enqueue scripts.
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 
			'housemajik-public', 
			HOUSEMAJIK_PLUGIN_URL . 'public/js/housemagik-public.js', 
			array( 'jquery' ), 
			HOUSEMAJIK_VERSION, 
			true 
		);
		
		wp_localize_script( 'housemajik-public', 'housemajik', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'housemajik_ajax' ),
			'home_url' => home_url(),
			'search_url' => get_permalink(),
			'listing_id' => '',
			'is_detail' => false,
			'data_source' => get_option( 'housemajik_data_source', 'sample' ),
			'idx_disclaimer' => get_option( 'housemajik_idx_disclaimer', '' ),
		) );
	}
}
