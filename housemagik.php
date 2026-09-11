<?php
/**
 * Not a second plugin. WordPress only lists housemajik.php.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'HOUSEMAGIK_BOOTSTRAP_FILE' ) ) {
	define( 'HOUSEMAGIK_BOOTSTRAP_FILE', __DIR__ . '/housemajik.php' );
}

require_once __DIR__ . '/includes/load.php';
