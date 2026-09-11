<?php
/**
 * The core plugin class.
 */
class Housemajik_Core {

	protected $version;

	public function __construct() {
		$this->version = HOUSEMAJIK_VERSION;
		$this->load_dependencies();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	private function load_dependencies() {
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-security.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-locations.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-sample-data.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-suggest.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-tradeoff.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-armls.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-ai.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-email.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-buyer-activity.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-agent.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-saved.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-alerts.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-ajax.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-shortcode.php';
		require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-admin.php';
	}

	private function define_admin_hooks() {
		$admin = new Housemajik_Admin();
		
		add_action( 'admin_menu', array( $admin, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $admin, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_scripts' ) );
		add_action( 'admin_notices', array( $admin, 'show_admin_notices' ) );
	}

	private function define_public_hooks() {
		$shortcode = new Housemajik_Shortcode();
		$ajax = new Housemajik_Ajax();
		$alerts = new Housemajik_Alerts();
		
		// Shortcode (housemajik kept as a typo-safe alias)
		add_shortcode( 'housemagik', array( $shortcode, 'render' ) );
		add_shortcode( 'housemajik', array( $shortcode, 'render' ) );
		add_shortcode( 'housemagik_saved', array( $shortcode, 'render_saved' ) );
		
		// Enqueue assets
		add_action( 'wp_enqueue_scripts', array( $shortcode, 'enqueue_styles' ) );
		add_action( 'wp_enqueue_scripts', array( $shortcode, 'enqueue_scripts' ) );
		
		// AJAX handlers
		add_action( 'wp_ajax_housemajik_search', array( $ajax, 'handle_search' ) );
		add_action( 'wp_ajax_nopriv_housemajik_search', array( $ajax, 'handle_search' ) );
		
		add_action( 'wp_ajax_housemajik_save_reaction', array( $ajax, 'handle_save_reaction' ) );
		add_action( 'wp_ajax_nopriv_housemajik_save_reaction', array( $ajax, 'handle_save_reaction' ) );
		
		add_action( 'wp_ajax_housemajik_register', array( $ajax, 'handle_register' ) );
		add_action( 'wp_ajax_nopriv_housemajik_register', array( $ajax, 'handle_register' ) );
		
		add_action( 'wp_ajax_housemajik_save_alert', array( $ajax, 'handle_save_alert' ) );
		add_action( 'wp_ajax_nopriv_housemajik_save_alert', array( $ajax, 'handle_save_alert' ) );
		add_action( 'wp_ajax_housemajik_save_property', array( $ajax, 'handle_save_property' ) );
		add_action( 'wp_ajax_nopriv_housemajik_save_property', array( $ajax, 'handle_save_property' ) );
		add_action( 'phpmailer_init', array( 'Housemajik_Email', 'apply_mailer' ), 100 );
		add_action( 'wp_mail_failed', array( 'Housemajik_Email', 'record_mail_failure' ) );
		
		// Cron hooks
		add_action( 'housemajik_daily_alerts', array( $alerts, 'run_daily_alerts' ) );
		add_action( Housemajik_Buyer_Activity::WEEKLY_HOOK, array( 'Housemajik_Buyer_Activity', 'run_weekly_report' ) );
		
		// Custom rewrite rules for detail pages
		add_action( 'init', array( 'Housemajik_Security', 'maybe_clear_test_reset' ), 1 );
		add_action( 'init', array( $this, 'maybe_schedule_alerts' ), 20 );
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_detail_page' ) );
	}

	public function maybe_schedule_alerts() {
		if ( ! wp_next_scheduled( 'housemajik_daily_alerts' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'housemajik_daily_alerts' );
		}
		Housemajik_Buyer_Activity::maybe_schedule_weekly();
	}

	public function add_rewrite_rules() {
		Housemajik_Saved::maybe_upgrade();
		Housemajik_Agent::maybe_upgrade();
		Housemajik_Security::maybe_upgrade();
		Housemajik_Locations::maybe_upgrade();
		add_rewrite_rule(
			'^property/([^/]+)/?$',
			'index.php?housemajik_listing=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^saved-homes/?$',
			'index.php?housemajik_saved=1',
			'top'
		);
		if ( get_option( 'housemajik_rewrite_version' ) !== HOUSEMAJIK_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'housemajik_rewrite_version', HOUSEMAJIK_VERSION, false );
		}
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'housemajik_listing';
		$vars[] = 'housemajik_saved';
		return $vars;
	}

	public function handle_detail_page() {
		if ( get_query_var( 'housemajik_saved' ) ) {
			require_once HOUSEMAJIK_PLUGIN_DIR . 'public/views/saved-homes.php';
			exit;
		}

		$listing_id = get_query_var( 'housemajik_listing' );
		
		if ( $listing_id ) {
			require_once HOUSEMAJIK_PLUGIN_DIR . 'public/views/detail-page.php';
			exit;
		}
	}

	public function run() {
		// Plugin is loaded and hooks are registered
	}

	public function get_version() {
		return $this->version;
	}
}
