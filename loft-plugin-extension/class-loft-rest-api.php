<?php
/**
 * Loft REST API — Mobile Submission Endpoint
 *
 * Drop this file into your Loft plugin's includes/ folder and add one line
 * to the-loft.php (see MOBILE_SETUP.md for exact instructions).
 *
 * Endpoints registered:
 *   GET  /wp-json/the-loft/v1/instruments   — public, returns instrument list
 *   POST /wp-json/the-loft/v1/presign       — auth required, returns S3 pre-signed upload URL
 *   POST /wp-json/the-loft/v1/submit        — auth required, creates the submission
 *
 * Authentication: WordPress Application Passwords (built into WP 5.6+).
 * No extra plugins needed. Users supply their WP username + Application Password
 * via HTTP Basic Auth with every request.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Loft_REST_API {

    const REST_NAMESPACE = 'the-loft/v1';

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {

        register_rest_route(
            self::REST_NAMESPACE,
            '/instruments',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_instruments' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/presign',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'get_presigned_url' ),
                'permission_callback' => array( $this, 'require_auth' ),
                'args'                => array(
                    'filename' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_file_name',
                    ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/submit',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_submission' ),
                'permission_callback' => array( $this, 'require_auth' ),
            )
        );
    }

    /**
     * Permission callback — requires the user to be authenticated.
     * Works with WordPress Application Passwords (HTTP Basic Auth) as well as
     * normal cookie-based sessions.
     */
    public function require_auth( WP_REST_Request $request ) {
        return is_user_logged_in();
    }

    // -------------------------------------------------------------------------
    // GET /instruments
    // -------------------------------------------------------------------------

    /**
     * Return all loft-instrument taxonomy terms for the mobile picker.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_instruments( WP_REST_Request $request ) {
        $terms = get_terms(
            array(
                'taxonomy'   => 'loft-instrument',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );

        if ( is_wp_error( $terms ) ) {
            return new WP_Error(
                'loft_terms_error',
                'Could not retrieve instrument list.',
                array( 'status' => 500 )
            );
        }

        $data = array();
        foreach ( $terms as $term ) {
            $data[] = array(
                'id'   => $term->term_id,
                'name' => $term->name,
            );
        }

        return rest_ensure_response( $data );
    }

    // -------------------------------------------------------------------------
    // POST /presign
    // -------------------------------------------------------------------------

    /**
     * Generate a pre-signed S3 URL so the mobile app can upload audio directly
     * to S3 without routing the file through WordPress.
     *
     * Request body (JSON or form-data):
     *   filename  string  e.g. "my-recording.m4a"
     *
     * Response:
     *   upload_url  string  Pre-signed PUT URL (valid for 15 minutes)
     *   object_key  string  The S3 key to send in the /submit call
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_presigned_url( WP_REST_Request $request ) {
        $user_id  = get_current_user_id();
        $filename = sanitize_file_name( $request->get_param( 'filename' ) );

        if ( empty( $filename ) ) {
            return new WP_Error(
                'loft_missing_filename',
                'filename is required.',
                array( 'status' => 400 )
            );
        }

        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, array( 'mp3', 'm4a' ), true ) ) {
            return new WP_Error(
                'loft_invalid_type',
                'Only MP3 and M4A audio files are accepted.',
                array( 'status' => 400 )
            );
        }

        $object_key   = 'loft-submissions/' . $user_id . '/' . time() . '-' . $filename;
        $content_type = ( $ext === 'mp3' ) ? 'audio/mpeg' : 'audio/mp4';

        // --- Try existing Loft_S3_Integration class first ---
        if ( class_exists( 'Loft_S3_Integration' ) && method_exists( 'Loft_S3_Integration', 'get_presigned_upload_url' ) ) {
            $result = Loft_S3_Integration::get_presigned_upload_url( $object_key, $content_type );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response(
                array(
                    'upload_url'  => $result['url'],
                    'object_key'  => $object_key,
                )
            );
        }

        // --- Fallback: build pre-signed URL directly via AWS SDK ---
        $s3 = $this->build_s3_client();
        if ( is_wp_error( $s3 ) ) {
            return $s3;
        }

        try {
            $bucket = $this->get_s3_bucket();
            if ( empty( $bucket ) ) {
                return new WP_Error(
                    'loft_s3_no_bucket',
                    'S3 bucket is not configured. Set LOFT_S3_BUCKET in wp-config.php.',
                    array( 'status' => 500 )
                );
            }

            $cmd = $s3->getCommand(
                'PutObject',
                array(
                    'Bucket'      => $bucket,
                    'Key'         => $object_key,
                    'ContentType' => $content_type,
                )
            );

            $presigned   = $s3->createPresignedRequest( $cmd, '+15 minutes' );
            $upload_url  = (string) $presigned->getUri();

            return rest_ensure_response(
                array(
                    'upload_url' => $upload_url,
                    'object_key' => $object_key,
                )
            );

        } catch ( \Exception $e ) {
            return new WP_Error(
                'loft_s3_presign_error',
                'Could not generate upload URL: ' . $e->getMessage(),
                array( 'status' => 500 )
            );
        }
    }

    // -------------------------------------------------------------------------
    // POST /submit
    // -------------------------------------------------------------------------

    /**
     * Create a Loft submission. Mirrors create_submission() in
     * Loft_Submission_Form exactly, adapted for the REST API.
     *
     * Request body (JSON or form-data):
     *   s3_object_key              string  The key returned by /presign (if audio was uploaded)
     *   submission_video_url       string  YouTube / Vimeo URL (alternative to audio)
     *   submission_video_sample_start string  e.g. "4:15" (optional, only with video)
     *   what_doesnt_feel_right     string  Required
     *   what_would_you_like_to_improve string Required
     *   instrument                 int     Term ID from /instruments. Required.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function create_submission( WP_REST_Request $request ) {
        $user_id = get_current_user_id();

        // Membership check — mirrors AJAX handler
        if ( ! the_loft_public_submissions_enabled() ) {
            $has_membership = false;
            if ( current_user_can( 'manage_options' ) ) {
                $has_membership = true;
            } elseif ( function_exists( 'wc_memberships_is_user_active_member' ) ) {
                if ( wc_memberships_is_user_active_member( $user_id, 'essentials' ) ||
                     wc_memberships_is_user_active_member( $user_id, 'mastery' ) ) {
                    $has_membership = true;
                }
            }

            if ( ! $has_membership ) {
                return new WP_Error(
                    'loft_no_membership',
                    'Active Essentials or Mastery membership required.',
                    array( 'status' => 403 )
                );
            }
        }

        // Sanitize inputs
        $s3_object_key      = sanitize_text_field( (string) $request->get_param( 's3_object_key' ) );
        $video_url          = esc_url_raw( (string) $request->get_param( 'submission_video_url' ) );
        $issue_text         = sanitize_textarea_field( (string) $request->get_param( 'what_doesnt_feel_right' ) );
        $improve_text       = sanitize_textarea_field( (string) $request->get_param( 'what_would_you_like_to_improve' ) );
        $instrument_id      = intval( $request->get_param( 'instrument' ) );
        $video_sample_start = sanitize_text_field( (string) $request->get_param( 'submission_video_sample_start' ) );
        $video_sample_start = trim( substr( $video_sample_start, 0, 40 ) );

        // Required field validation
        if ( empty( $issue_text ) || empty( $improve_text ) || empty( $instrument_id ) ) {
            return new WP_Error(
                'loft_missing_fields',
                'what_doesnt_feel_right, what_would_you_like_to_improve, and instrument are all required.',
                array( 'status' => 400 )
            );
        }

        $has_s3    = ( $s3_object_key !== '' );
        $has_video = ( $video_url !== '' );

        // Video URL validation
        if ( $has_video ) {
            $valid_url = function_exists( 'wp_http_validate_url' )
                ? (bool) wp_http_validate_url( $video_url )
                : (bool) preg_match( '#^https?://#i', $video_url );

            if ( ! $valid_url ) {
                return new WP_Error(
                    'loft_invalid_video_url',
                    'Please enter a valid video URL (https://…).',
                    array( 'status' => 400 )
                );
            }
        }

        // Must have at least one media source
        if ( ! $has_s3 && ! $has_video ) {
            return new WP_Error(
                'loft_no_media',
                'Please upload an audio file or paste a link to your video.',
                array( 'status' => 400 )
            );
        }

        // Validate S3 key format — same regex as AJAX handler
        if ( $has_s3 && ! preg_match( '#^loft-submissions/' . $user_id . '/\d+-.*#', $s3_object_key ) ) {
            return new WP_Error(
                'loft_invalid_s3_key',
                'Invalid upload signature payload mapping.',
                array( 'status' => 400 )
            );
        }

        if ( ! $has_s3 ) {
            $s3_object_key = '';
        }
        if ( ! $has_video ) {
            $video_sample_start = '';
        }

        // Build display name — same logic as AJAX handler
        $author     = get_userdata( $user_id );
        $first_name = get_user_meta( $user_id, 'first_name', true );
        $last_name  = get_user_meta( $user_id, 'last_name', true );

        if ( ! empty( $first_name ) && ! empty( $last_name ) ) {
            $display_name = $first_name . ' ' . mb_substr( $last_name, 0, 1 ) . '.';
        } else {
            $raw_name = $author ? $author->display_name : 'Unknown';
            $parts    = explode( ' ', $raw_name );
            if ( count( $parts ) > 1 ) {
                $last         = array_pop( $parts );
                $display_name = implode( ' ', $parts ) . ' ' . mb_substr( $last, 0, 1 ) . '.';
            } else {
                $display_name = $raw_name;
            }
        }

        // Create the submission post
        $post_data = array(
            'post_title'  => 'Submission by ' . $display_name . ' (' . date( 'Y-m-d' ) . ')',
            'post_type'   => 'loft-submission',
            'post_status' => 'draft',
            'post_author' => $user_id,
        );

        $post_id = wp_insert_post( $post_data );
        if ( is_wp_error( $post_id ) ) {
            return new WP_Error(
                'loft_insert_failed',
                'Could not create submission database entry.',
                array( 'status' => 500 )
            );
        }

        // Taxonomy
        wp_set_object_terms( $post_id, $instrument_id, 'loft-instrument' );

        // ACF fields (with post_meta fallback)
        if ( function_exists( 'update_field' ) ) {
            update_field( 'what_doesnt_feel_natural', $issue_text, $post_id );
            update_field( 'what_were_you_trying', $improve_text, $post_id );
            update_field( 's3_object_key', $s3_object_key, $post_id );
            update_field( 'submission_video_url', $has_video ? $video_url : '', $post_id );
            update_field( 'submission_video_sample_start', $has_video ? $video_sample_start : '', $post_id );
            update_field( 'submission_status', 'Pending', $post_id );
        } else {
            update_post_meta( $post_id, 'what_doesnt_feel_natural', $issue_text );
            update_post_meta( $post_id, 'what_were_you_trying', $improve_text );
            update_post_meta( $post_id, 's3_object_key', $s3_object_key );
            update_post_meta( $post_id, 'submission_video_url', $has_video ? $video_url : '' );
            update_post_meta( $post_id, 'submission_video_sample_start', $has_video ? $video_sample_start : '' );
            update_post_meta( $post_id, 'submission_status', 'Pending' );
        }

        // Admin notification email — same content as AJAX handler
        $subject  = 'You have a new Loft submission.';
        $body     = 'Submitter: ' . $display_name . "\n";
        $body    .= 'Date: ' . date( 'Y-m-d H:i:s' ) . "\n\n";
        $body    .= "What doesn't feel right to you?\n" . $issue_text . "\n\n";
        $body    .= "What would you like to improve?\n" . $improve_text . "\n\n";
        if ( $has_video ) {
            $body .= "Submitted video link:\n" . $video_url . "\n";
            if ( $video_sample_start !== '' ) {
                $body .= 'Sample starts at (in video): ' . $video_sample_start . "\n";
            }
            $body .= "\n";
        }
        $body .= "Direct WP admin edit link:\n" . get_edit_post_link( $post_id, 'raw' );

        wp_mail( 'mlake@redlake.tv', $subject, $body );

        return rest_ensure_response(
            array(
                'success' => true,
                'message' => 'Submission correctly recorded.',
                'post_id' => $post_id,
            )
        );
    }

    // -------------------------------------------------------------------------
    // S3 helpers
    // -------------------------------------------------------------------------

    /**
     * Return the configured S3 bucket name.
     * Checks wp-config.php constant first, then a WP option as fallback.
     *
     * @return string
     */
    private function get_s3_bucket() {
        if ( defined( 'LOFT_S3_BUCKET' ) && LOFT_S3_BUCKET ) {
            return LOFT_S3_BUCKET;
        }
        return (string) get_option( 'loft_s3_bucket', '' );
    }

    /**
     * Build an AWS S3 client using credentials from wp-config.php constants
     * or WP options (same convention Loft_S3_Integration uses).
     *
     * Expected constants in wp-config.php:
     *   LOFT_S3_KEY      — AWS access key ID
     *   LOFT_S3_SECRET   — AWS secret access key
     *   LOFT_S3_BUCKET   — S3 bucket name
     *   LOFT_S3_REGION   — AWS region (e.g. us-east-1)  [optional, defaults to us-east-1]
     *
     * @return \Aws\S3\S3Client|WP_Error
     */
    private function build_s3_client() {
        if ( ! class_exists( 'Aws\S3\S3Client' ) ) {
            return new WP_Error(
                'loft_s3_sdk_missing',
                'AWS SDK not found. Ensure the Loft plugin Composer dependencies are installed.',
                array( 'status' => 500 )
            );
        }

        $key    = defined( 'LOFT_S3_KEY' )    ? LOFT_S3_KEY    : get_option( 'loft_s3_key', '' );
        $secret = defined( 'LOFT_S3_SECRET' ) ? LOFT_S3_SECRET : get_option( 'loft_s3_secret', '' );
        $region = defined( 'LOFT_S3_REGION' ) ? LOFT_S3_REGION : get_option( 'loft_s3_region', 'us-east-1' );

        if ( empty( $key ) || empty( $secret ) ) {
            return new WP_Error(
                'loft_s3_no_credentials',
                'AWS credentials are not configured. Set LOFT_S3_KEY and LOFT_S3_SECRET in wp-config.php.',
                array( 'status' => 500 )
            );
        }

        return new \Aws\S3\S3Client(
            array(
                'version'     => 'latest',
                'region'      => $region,
                'credentials' => array(
                    'key'    => $key,
                    'secret' => $secret,
                ),
            )
        );
    }
}

new Loft_REST_API();
