<?php
/**
 * ADD THIS BLOCK to the-loft.php
 *
 * Paste these lines into the-loft.php alongside the other require_once blocks
 * (e.g. right after the class-loft-submission-form.php require_once).
 *
 * ---------------------------------------------------------
 * Do NOT paste the entire contents of this file into the-loft.php.
 * Only paste the require_once block shown below.
 * ---------------------------------------------------------
 */

// Paste this into the-loft.php:

if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-loft-rest-api.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-loft-rest-api.php';
}
