<?php
/**
 * Plugin Name: Housemagik
 * Plugin URI: https://housemagik.ai
 * Description: Natural-language home search powered by AI. Find your dream home by describing it.
 * Version: 1.0.87
 * Author: Redlake Marketing
 * Author URI: https://housemagik.ai
 * Copyright: 2026 Redlake Marketing
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: housemajik
 * Domain Path: /languages
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'HOUSEMAGIK_BOOTSTRAP_FILE' ) ) {
	define( 'HOUSEMAGIK_BOOTSTRAP_FILE', __FILE__ );
}

require_once __DIR__ . '/includes/load.php';
