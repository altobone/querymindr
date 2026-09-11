<?php
/**
 * Shared plugin bootstrap. Not a WordPress plugin header file.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( defined( 'HOUSEMAGIK_LOADED' ) ) {
	return;
}
define( 'HOUSEMAGIK_LOADED', true );

if ( ! defined( 'HOUSEMAGIK_BOOTSTRAP_FILE' ) ) {
	define( 'HOUSEMAGIK_BOOTSTRAP_FILE', dirname( __DIR__ ) . '/housemajik.php' );
}

define( 'HOUSEMAJIK_VERSION', '1.0.87' );
define( 'HOUSEMAJIK_PLUGIN_DIR', plugin_dir_path( HOUSEMAGIK_BOOTSTRAP_FILE ) );
define( 'HOUSEMAJIK_PLUGIN_URL', plugin_dir_url( HOUSEMAGIK_BOOTSTRAP_FILE ) );
define( 'HOUSEMAJIK_PLUGIN_BASENAME', plugin_basename( HOUSEMAGIK_BOOTSTRAP_FILE ) );

function activate_housemajik() {
	require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-activator.php';
	Housemajik_Activator::activate();
}

function deactivate_housemajik() {
	require_once HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-deactivator.php';
	Housemajik_Deactivator::deactivate();
}

register_activation_hook( HOUSEMAGIK_BOOTSTRAP_FILE, 'activate_housemajik' );
register_deactivation_hook( HOUSEMAGIK_BOOTSTRAP_FILE, 'deactivate_housemajik' );

if ( ! get_option( 'housemajik_rate_limit_raised_1' ) ) {
	update_option( 'housemajik_rate_limit_searches', 100 );
	update_option( 'housemajik_rate_limit_raised_1', 1 );
}

if ( ! get_option( 'housemajik_ai_cap_lowered_1' ) ) {
	if ( (int) get_option( 'housemajik_ai_daily_cap', 1000 ) >= 1000 ) {
		update_option( 'housemajik_ai_daily_cap', 200 );
	}
	update_option( 'housemajik_ai_cap_lowered_1', 1 );
}

require HOUSEMAJIK_PLUGIN_DIR . 'includes/class-housemagik-core.php';

function run_housemajik() {
	$plugin = new Housemajik_Core();
	$plugin->run();
}

run_housemajik();
