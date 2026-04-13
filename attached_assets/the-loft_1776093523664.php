<?php
/**
 * Plugin Name: The Loft
 * Description: Phase 1 of a WordPress plugin for member submission and feedback.
 * Version: 1.0.0
 * Author: Music Savvy
 * Text Domain: the-loft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Open submission period (any logged-in user may submit).
 * In wp-config.php add: define( 'THE_LOFT_PUBLIC_SUBMISSIONS', true );
 */
if ( ! defined( 'THE_LOFT_PUBLIC_SUBMISSIONS' ) ) {
	define( 'THE_LOFT_PUBLIC_SUBMISSIONS', false );
}

/**
 * @return bool
 */
function the_loft_public_submissions_enabled() {
	return (bool) THE_LOFT_PUBLIC_SUBMISSIONS;
}

// 0. Composer Autoload & Libraries Integration
if ( file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
}

if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-loft-s3-integration.php' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-loft-s3-integration.php';
}

if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-loft-submission-form.php' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-loft-submission-form.php';
}

if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-loft-submission-page.php' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-loft-submission-page.php';
}

if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-loft-library.php' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-loft-library.php';
}

if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-loft-account-prompt.php' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-loft-account-prompt.php';
}

/**
 * 1. Register Custom Post Type & Taxonomy
 */
function the_loft_register_structures() {

	// Register Submissions CPT
	$cpt_labels = array(
		'name'               => 'Loft Submissions',
		'singular_name'      => 'Loft Submission',
		'menu_name'          => 'Loft Submissions',
		'name_admin_bar'     => 'Loft Submission',
		'add_new'            => 'Add New',
		'add_new_item'       => 'Add New Loft Submission',
		'new_item'           => 'New Loft Submission',
		'edit_item'          => 'Edit Loft Submission',
		'view_item'          => 'View Loft Submission',
		'all_items'          => 'All Loft Submissions',
		'search_items'       => 'Search Loft Submissions',
		'not_found'          => 'No Loft submissions found.',
		'not_found_in_trash' => 'No Loft submissions found in Trash.',
	);

	$cpt_args = array(
		'labels'             => $cpt_labels,
		'public'             => true,
		'publicly_queryable' => true,
		'show_ui'            => true,
		'show_in_menu'       => true,
		'query_var'          => true,
		'rewrite'            => array( 'slug' => 'loft-submission' ),
		'capability_type'    => 'post',
		'has_archive'        => true,
		'hierarchical'       => false,
		'menu_position'      => null,
		'supports'           => array( 'title', 'author' ),
		'show_in_rest'       => true,
	);

	register_post_type( 'loft-submission', $cpt_args );

	// Register Instrument Taxonomy
	$tax_labels = array(
		'name'              => 'Instruments',
		'singular_name'     => 'Instrument',
		'search_items'      => 'Search Instruments',
		'all_items'         => 'All Instruments',
		'parent_item'       => 'Parent Instrument',
		'parent_item_colon' => 'Parent Instrument:',
		'edit_item'         => 'Edit Instrument',
		'update_item'       => 'Update Instrument',
		'add_new_item'      => 'Add New Instrument',
		'new_item_name'     => 'New Instrument Name',
		'menu_name'         => 'Instrument',
	);

	$tax_args = array(
		'hierarchical'      => true,
		'labels'            => $tax_labels,
		'show_ui'           => true,
		'show_admin_column' => true,
		'query_var'         => true,
		'rewrite'           => array( 'slug' => 'loft-instrument' ),
		'public'            => true,
		'show_in_rest'      => true,
	);

	register_taxonomy( 'loft-instrument', array( 'loft-submission' ), $tax_args );
}
add_action( 'init', 'the_loft_register_structures' );

/**
 * Pre-populate Taxonomy Terms on Activation
 */
function the_loft_activate() {
	// Need to register structures before inserting terms
	the_loft_register_structures();

	$default_instruments = array(
		'Piano',
		'Guitar',
		'Bass',
		'Saxophone',
		'Trumpet',
		'Trombone',
		'Other Strings',
		'Other Woodwind',
		'Other Brass',
		'Other'
	);

	foreach ( $default_instruments as $instrument ) {
		if ( ! term_exists( $instrument, 'loft-instrument' ) ) {
			wp_insert_term( $instrument, 'loft-instrument' );
		}
	}
}
register_activation_hook( __FILE__, 'the_loft_activate' );

/**
 * 3. Register ACF Field Group
 */
function the_loft_register_acf_fields() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group(
		array(
			'key'                   => 'group_loft_submission',
			'title'                 => 'Submission Details',
			'fields'                => array(
				array(
					'key'          => 'field_s3_object_key',
					'label'        => 'S3 Object Key',
					'name'         => 's3_object_key',
					'type'         => 'text',
					'instructions' => 'Read only in admin',
					'readonly'     => 1,
				),
				array(
					'key'          => 'field_submission_video_url',
					'label'        => 'Submitted video link',
					'name'         => 'submission_video_url',
					'type'         => 'url',
					'instructions' => 'If the player submitted a video URL instead of (or in addition to) an audio upload, it appears here.',
				),
				array(
					'key'          => 'field_submission_video_sample_start',
					'label'        => 'Sample starts at (in video)',
					'name'         => 'submission_video_sample_start',
					'type'         => 'text',
					'instructions' => 'Timecode the submitter gave for where their playing begins in the linked video (e.g. 4:15). Display only on the public submission page.',
				),
				array(
					'key'      => 'field_what_doesnt_feel_natural',
					'label'    => 'What Doesn\'t Feel Natural',
					'name'     => 'what_doesnt_feel_natural',
					'type'     => 'textarea',
					'required' => 1,
				),
				array(
					'key'      => 'field_what_were_you_trying',
					'label'    => 'What Were You Trying to Hear/Play',
					'name'     => 'what_were_you_trying',
					'type'     => 'textarea',
					'required' => 1,
				),
				array(
					'key'          => 'field_diagnosis',
					'label'        => 'Diagnosis',
					'name'         => 'diagnosis',
					'type'         => 'textarea',
					'instructions' => 'Admin only — freeform written assessment by site owner after listening',
				),
				array(
					'key'   => 'field_if_this_is_you',
					'label' => 'If This Is You',
					'name'  => 'if_this_is_you',
					'type'  => 'textarea',
				),
				array(
					'key'          => 'field_loft_next_steps_v2',
					'label'        => 'Next Steps',
					'name'         => 'loft_next_steps',
					'type'         => 'repeater',
					'layout'       => 'block',
					'button_label' => 'Add Next Step',
					'instructions' => 'Add a row for each recommended link (lesson, post, exercise, or video).',
					'sub_fields'   => array(
						array(
							'key'   => 'field_rep_next_step_label',
							'label' => 'Next Step Label',
							'name'  => 'next_step_label',
							'type'  => 'text',
						),
						array(
							'key'   => 'field_rep_next_step_url',
							'label' => 'Next Step URL',
							'name'  => 'next_step_url',
							'type'  => 'url',
						),
						array(
							'key'          => 'field_rep_next_step_desc',
							'label'        => 'Next Step Description',
							'name'         => 'next_step_description',
							'type'         => 'textarea',
							'instructions' => 'Write out the name or description of the lesson so the user knows what you are recommending.',
						),
					),
				),
				array(
					'key'          => 'field_next_step_label',
					'label'        => 'Next Step Label',
					'name'         => 'next_step_label',
					'type'         => 'text',
					'instructions' => 'Used only when the repeater above has no rows.',
				),
				array(
					'key'          => 'field_next_step_url',
					'label'        => 'Next Step URL',
					'name'         => 'next_step_url',
					'type'         => 'url',
				),
				array(
					'key'          => 'field_next_step_description',
					'label'        => 'Next Step Description',
					'name'         => 'next_step_description',
					'type'         => 'textarea',
					'instructions' => 'Write out the name or description of the lesson so the user knows what you are recommending.',
				),
				array(
					'key'   => 'field_your_next_3_steps',
					'label' => 'Your Next 3 Steps',
					'name'  => 'your_next_3_steps',
					'type'  => 'textarea',
				),
				array(
					'key'           => 'field_featured',
					'label'         => 'Featured',
					'name'          => 'featured',
					'type'          => 'true_false',
					'default_value' => 0,
					'ui'            => 1,
				),
				array(
					'key'           => 'field_submission_status',
					'label'         => 'Submission Status',
					'name'          => 'submission_status',
					'type'          => 'select',
					'choices'       => array(
						'Pending'   => 'Pending',
						'In Review' => 'In Review',
						'Published' => 'Published',
					),
					'default_value' => 'Pending',
					'return_format' => 'value',
				),
				array(
					'key'          => 'field_timestamped_critique_v2',
					'label'        => 'Timestamped Critique',
					'name'         => 'timestamped_critique',
					'type'         => 'repeater',
					'layout'       => 'block',
					'button_label' => 'Add Critique Note',
					'sub_fields'   => array(
						array(
							'key'         => 'field_critique_time_v2',
							'label'       => 'Time',
							'name'        => 'time',
							'type'        => 'text',
							'placeholder' => '0:00',
						),
						array(
							'key'   => 'field_critique_note_v2',
							'label' => 'Note',
							'name'  => 'note',
							'type'  => 'textarea',
						),
					),
				),
				array(
					'key'           => 'field_series_parent',
					'label'         => 'Series Parent',
					'name'          => 'series_parent',
					'type'          => 'relationship',
					'post_type'     => array( 'loft-submission' ),
					'max'           => 1,
					'filters'       => array( 'search' ),
					'return_format' => 'object',
					'instructions'  => 'Optional. The earlier Loft submission this one follows (same student / thread). When set, the public page can show Previous / Next between linked submissions.',
				),
			),
			'location'              => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'loft-submission',
					),
				),
			),
			'menu_order'            => 0,
			'position'              => 'normal',
			'style'                 => 'default',
			'label_placement'       => 'top',
			'instruction_placement' => 'label',
			'hide_on_screen'        => '',
			'active'                => true,
			'description'           => '',
		)
	);
}
add_action( 'acf/init', 'the_loft_register_acf_fields' );

/**
 * Force S3 Object Key to be strictly readonly in Admin UI
 */
function the_loft_s3_key_readonly( $field ) {
	if ( ! is_array( $field ) ) {
		return $field;
	}
	$field['readonly'] = 1;
	return $field;
}
add_filter( 'acf/load_field/key=field_s3_object_key', 'the_loft_s3_key_readonly' );

/**
 * Exclude current post from Series Parent Relationship
 */
function the_loft_exclude_current_post_series_parent( $args, $field, $post_id ) {
	if ( ! is_array( $args ) ) {
		$args = array();
	}
	if ( $post_id ) {
		$args['post__not_in'] = array( (int) $post_id );
	}
	return $args;
}
add_filter( 'acf/fields/relationship/query/name=series_parent', 'the_loft_exclude_current_post_series_parent', 10, 3 );


/**
 * 4. Admin Dashboard Widget
 */
function the_loft_add_dashboard_widgets() {
	wp_add_dashboard_widget(
		'the_loft_pending_submissions_widget',
		'The Loft — Pending Submissions',
		'the_loft_dashboard_widget_display'
	);
}
add_action( 'wp_dashboard_setup', 'the_loft_add_dashboard_widgets' );

function the_loft_dashboard_widget_display() {
	$args = array(
		'post_type'      => 'loft-submission',
		'posts_per_page' => -1,
		'post_status'    => array('publish', 'pending', 'draft', 'private'),
		'meta_query'     => array(
			'relation' => 'OR',
			array(
				'key'     => 'submission_status',
				'value'   => 'Pending',
				'compare' => '='
			),
			array(
				'key'     => 'submission_status',
				'compare' => 'NOT EXISTS' // Catches new submissions where the field hasn't saved a value explicitly yet
			)
		)
	);

	$query = new WP_Query( $args );

	if ( $query->have_posts() ) {
		echo '<ul style="margin: 0; padding: 0; list-style: none;">';
		while ( $query->have_posts() ) {
			$query->the_post();
			
			$post_id   = get_the_ID();
			$author_id = get_post_field( 'post_author', $post_id );
			$author    = get_userdata( $author_id );
			
			$display_name = $author ? esc_html( $author->display_name ) : 'Unknown Submitter';
			$date         = get_the_date();
			$edit_url     = get_edit_post_link( $post_id, 'raw' );

			echo '<li style="margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #ddd;">';
			echo '<div style="margin-bottom: 4px;"><strong>' . $display_name . '</strong> &mdash; <span style="color:#666;">' . esc_html( $date ) . '</span></div>';
			echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small">Edit Post</a>';
			echo '</li>';
		}
		echo '</ul>';
		wp_reset_postdata();
	} else {
		echo '<p>No pending submissions.</p>';
	}
}

/**
 * 5. Admin Email Notification on Save (Draft)
 */
function the_loft_send_submission_email_on_save( $post_id ) {
	
	// Bail if not our CPT
	if ( get_post_type( $post_id ) !== 'loft-submission' ) {
		return;
	}

	// Bail during autosave
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	// Must be draft status per requirements
	if ( get_post_status( $post_id ) !== 'draft' ) {
		return;
	}

	// Ensure we only send this once per submission
	$email_sent = get_post_meta( $post_id, '_loft_submission_email_sent', true );
	if ( $email_sent ) {
		return;
	}

	// Get ACF fields. We use get_post_meta for robustness alongside get_field
	$what_doesnt_feel_natural = function_exists('get_field') ? get_field('what_doesnt_feel_natural', $post_id) : get_post_meta( $post_id, 'what_doesnt_feel_natural', true );
	$what_were_you_trying     = function_exists('get_field') ? get_field('what_were_you_trying', $post_id) : get_post_meta( $post_id, 'what_were_you_trying', true );
	$submission_video_url         = function_exists('get_field') ? get_field('submission_video_url', $post_id) : get_post_meta( $post_id, 'submission_video_url', true );
	$submission_video_sample_start = function_exists('get_field') ? get_field('submission_video_sample_start', $post_id) : get_post_meta( $post_id, 'submission_video_sample_start', true );

	$author_id    = get_post_field( 'post_author', $post_id );
	$author       = get_userdata( $author_id );
	$display_name = $author ? $author->display_name : 'Unknown Submitter';
	
	$date     = get_the_date( '', $post_id );
	$edit_url = get_edit_post_link( $post_id, 'raw' );

	$subject = 'New Loft Submission — ' . $display_name;

	$body  = "A new Loft Submission has been created.\n\n";
	$body .= "Submitter: " . $display_name . "\n";
	$body .= "Submission Date: " . $date . "\n\n";
	$body .= "What Doesn't Feel Natural: \n" . strip_tags($what_doesnt_feel_natural) . "\n\n";
	$body .= "What Were You Trying to Hear/Play: \n" . strip_tags($what_were_you_trying) . "\n\n";
	if ( $submission_video_url ) {
		$body .= "Submitted video link: \n" . esc_url_raw( $submission_video_url ) . "\n";
		if ( $submission_video_sample_start ) {
			$body .= 'Sample starts at (in video): ' . sanitize_text_field( $submission_video_sample_start ) . "\n";
		}
		$body .= "\n";
	}
	$body .= "Direct WP Admin Edit Link: \n" . $edit_url;

	$admin_email = get_option( 'admin_email' );

	wp_mail( $admin_email, $subject, $body );

	// Mark as sent
	update_post_meta( $post_id, '_loft_submission_email_sent', 'yes' );
}
// Run priority 20 so ACF fields are saved to database before we grab them.
add_action( 'acf/save_post', 'the_loft_send_submission_email_on_save', 20 );

// For robustness, also hook into standard save_post just in case ACF isn't dictating the save
function the_loft_send_submission_email_native_save( $post_id, $post, $update ) {
	if ( function_exists('acf') ) {
		// If ACF is active, the acf/save_post hook handles the email. Let's not duplicate.
		return;
	}
	the_loft_send_submission_email_on_save( $post_id );
}
add_action( 'save_post', 'the_loft_send_submission_email_native_save', 20, 3 );

/**
 * Notify submission author when their loft submission is published (first time only).
 */
function the_loft_notify_author_on_first_publish( $new_status, $old_status, $post ) {
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	if ( $post->post_type !== 'loft-submission' ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post->ID ) ) {
		return;
	}

	if ( $new_status !== 'publish' ) {
		return;
	}

	if ( $old_status === 'publish' ) {
		return;
	}

	if ( get_post_meta( $post->ID, '_loft_review_published_email_sent', true ) ) {
		return;
	}

	$author_id = (int) $post->post_author;
	if ( $author_id < 1 ) {
		return;
	}

	$user = get_userdata( $author_id );
	if ( ! $user || ! is_email( $user->user_email ) ) {
		return;
	}

	$to      = $user->user_email;
	$subject = 'Your Music Savvy submission has been reviewed.';

	$body  = "I've listened to your playing submission and given you my impressions along with some tips and resources to help get you closer to how you wish to play. Feel free to comment in the submission since I make sure to review all comments.\n\n";

	$review_url = apply_filters( 'the_loft_publish_notification_review_url', get_permalink( $post->ID ), $post );
	if ( is_string( $review_url ) && $review_url !== '' ) {
		$body .= "Read your full review on The Loft:\n" . $review_url . "\n\n";
	}

	$body .= 'Mike';

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

	$sent = wp_mail( $to, $subject, $body, $headers );

	if ( $sent ) {
		update_post_meta( $post->ID, '_loft_review_published_email_sent', '1' );
	}
}
add_action( 'transition_post_status', 'the_loft_notify_author_on_first_publish', 10, 3 );
