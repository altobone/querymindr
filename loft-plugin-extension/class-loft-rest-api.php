<?php
/**
 * Loft REST API — Mobile Submission Endpoint
 *
 * Drop this file into your Loft plugin's includes/ folder and add one line
 * to the-loft.php (see MOBILE_SETUP.md for exact instructions).
 *
 * Endpoints registered:
 *   POST /wp-json/the-loft/v1/auth        — public, login with WP credentials → JWT
 *   POST /wp-json/the-loft/v1/register    — public, create account → JWT
 *   GET  /wp-json/the-loft/v1/instruments — public, returns instrument list
 *   POST /wp-json/the-loft/v1/presign     — auth required, returns S3 pre-signed upload URL
 *   POST /wp-json/the-loft/v1/submit      — auth required, creates the submission
 *
 * Authentication: Custom JWT (Bearer token) issued by /auth or /register.
 * Application Passwords (Basic Auth) continue to work for backward compatibility.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Loft_REST_API {

    const REST_NAMESPACE = 'the-loft/v1';

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_filter( 'determine_current_user', array( $this, 'authenticate_jwt_user' ), 20 );
    }

    public function register_routes() {

        register_rest_route(
            self::REST_NAMESPACE,
            '/auth',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_auth' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'username' => array( 'required' => true, 'sanitize_callback' => 'sanitize_user' ),
                    'password' => array( 'required' => true ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/register',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_register' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'username' => array( 'required' => true, 'sanitize_callback' => 'sanitize_user' ),
                    'email'    => array( 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
                    'password' => array( 'required' => true ),
                ),
            )
        );

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

    // -------------------------------------------------------------------------
    // JWT helpers
    // -------------------------------------------------------------------------

    private function get_jwt_secret() {
        $secret = get_option( 'loft_jwt_secret' );
        if ( ! $secret ) {
            $secret = bin2hex( random_bytes( 32 ) );
            update_option( 'loft_jwt_secret', $secret );
        }
        return $secret;
    }

    private function base64url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    private function base64url_decode( $data ) {
        $pad = strlen( $data ) % 4;
        if ( $pad ) {
            $data .= str_repeat( '=', 4 - $pad );
        }
        return base64_decode( strtr( $data, '-_', '+/' ) );
    }

    private function generate_jwt( $user_id, $username ) {
        $header  = $this->base64url_encode( json_encode( array( 'typ' => 'JWT', 'alg' => 'HS256' ) ) );
        $payload = $this->base64url_encode( json_encode( array(
            'sub'      => (int) $user_id,
            'username' => $username,
            'iat'      => time(),
            'exp'      => time() + ( 90 * DAY_IN_SECONDS ),
        ) ) );
        $sig = $this->base64url_encode( hash_hmac( 'sha256', "$header.$payload", $this->get_jwt_secret(), true ) );
        return "$header.$payload.$sig";
    }

    private function verify_jwt( $token ) {
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 3 ) {
            return false;
        }
        list( $header, $payload, $sig ) = $parts;
        $expected = $this->base64url_encode( hash_hmac( 'sha256', "$header.$payload", $this->get_jwt_secret(), true ) );
        if ( ! hash_equals( $expected, $sig ) ) {
            return false;
        }
        $data = json_decode( $this->base64url_decode( $payload ), true );
        if ( ! $data || ! isset( $data['exp'] ) || $data['exp'] < time() ) {
            return false;
        }
        return $data;
    }

    /**
     * Hook into determine_current_user so our JWT tokens log the user in
     * for the entire REST request (makes get_current_user_id() work normally).
     */
    public function authenticate_jwt_user( $user_id ) {
        if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
            return $user_id;
        }
        if ( $user_id ) {
            return $user_id;
        }

        $auth_header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if ( ! $auth_header && function_exists( 'getallheaders' ) ) {
            $all     = getallheaders();
            $auth_header = isset( $all['Authorization'] ) ? $all['Authorization']
                         : ( isset( $all['authorization'] ) ? $all['authorization'] : '' );
        }

        if ( strncmp( $auth_header, 'Bearer ', 7 ) !== 0 ) {
            return $user_id;
        }

        $token = substr( $auth_header, 7 );
        $data  = $this->verify_jwt( $token );
        if ( ! $data ) {
            return $user_id;
        }

        return (int) $data['sub'];
    }

    // -------------------------------------------------------------------------
    // Permission callback
    // -------------------------------------------------------------------------

    public function require_auth( WP_REST_Request $request ) {
        return is_user_logged_in();
    }

    // -------------------------------------------------------------------------
    // POST /auth  — login
    // -------------------------------------------------------------------------

    public function handle_auth( WP_REST_Request $request ) {
        $username = sanitize_user( (string) $request->get_param( 'username' ) );
        $password = (string) $request->get_param( 'password' );

        if ( empty( $username ) || empty( $password ) ) {
            return new WP_Error( 'missing_credentials', 'Username and password are required.', array( 'status' => 400 ) );
        }

        $user = wp_authenticate( $username, $password );
        if ( is_wp_error( $user ) ) {
            return new WP_Error( 'invalid_credentials', 'Invalid username or password.', array( 'status' => 401 ) );
        }

        $token = $this->generate_jwt( $user->ID, $user->user_login );

        return rest_ensure_response( array(
            'token'    => $token,
            'username' => $user->user_login,
        ) );
    }

    // -------------------------------------------------------------------------
    // POST /register  — create account
    // -------------------------------------------------------------------------

    public function handle_register( WP_REST_Request $request ) {
        if ( ! get_option( 'users_can_register' ) ) {
            return new WP_Error( 'registration_disabled', 'User registration is currently disabled on this site.', array( 'status' => 403 ) );
        }

        $username = sanitize_user( (string) $request->get_param( 'username' ) );
        $email    = sanitize_email( (string) $request->get_param( 'email' ) );
        $password = (string) $request->get_param( 'password' );

        if ( empty( $username ) || empty( $email ) || empty( $password ) ) {
            return new WP_Error( 'missing_fields', 'Username, email, and password are all required.', array( 'status' => 400 ) );
        }

        if ( ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', 'Please enter a valid email address.', array( 'status' => 400 ) );
        }

        if ( strlen( $password ) < 8 ) {
            return new WP_Error( 'password_too_short', 'Password must be at least 8 characters.', array( 'status' => 400 ) );
        }

        if ( username_exists( $username ) ) {
            return new WP_Error( 'username_exists', 'That username is already taken.', array( 'status' => 409 ) );
        }

        if ( email_exists( $email ) ) {
            return new WP_Error( 'email_exists', 'An account with that email address already exists.', array( 'status' => 409 ) );
        }

        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            return new WP_Error( 'registration_failed', $user_id->get_error_message(), array( 'status' => 500 ) );
        }

        $token = $this->generate_jwt( $user_id, $username );

        return rest_ensure_response( array(
            'token'    => $token,
            'username' => $username,
        ) );
    }

    // -------------------------------------------------------------------------
    // GET /instruments
    // -------------------------------------------------------------------------

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

        if ( class_exists( 'Loft_S3_Integration' ) && method_exists( 'Loft_S3_Integration', 'get_presigned_upload_url' ) ) {
            $result = Loft_S3_Integration::get_presigned_upload_url( $object_key, $content_type );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response(
                array(
                    'upload_url' => $result['url'],
                    'object_key' => $object_key,
                )
            );
        }

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

            $presigned  = $s3->createPresignedRequest( $cmd, '+15 minutes' );
            $upload_url = (string) $presigned->getUri();

            return rest_ensure_response(
                array(
                    'upload_url' => $upload_url,
                    'object_key' => $object_key,
                )
            );

        } catch ( \Exception $e ) {
            error_log( '[Loft REST API] S3 presign error: ' . $e->getMessage() );
            return new WP_Error(
                'loft_s3_presign_error',
                'Could not generate upload URL. Please try again or contact support.',
                array( 'status' => 500 )
            );
        }
    }

    // -------------------------------------------------------------------------
    // POST /submit
    // -------------------------------------------------------------------------

    public function create_submission( WP_REST_Request $request ) {
        $user_id = get_current_user_id();

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

        if ( ! empty( $request->get_param( 'loft_hp_field' ) ) ) {
            return rest_ensure_response( array( 'success' => true, 'message' => 'Form captured.' ) );
        }

        $s3_object_key      = sanitize_text_field( (string) $request->get_param( 's3_object_key' ) );
        $video_url          = esc_url_raw( (string) $request->get_param( 'submission_video_url' ) );
        $issue_text         = sanitize_textarea_field( (string) $request->get_param( 'what_doesnt_feel_right' ) );
        $improve_text       = sanitize_textarea_field( (string) $request->get_param( 'what_would_you_like_to_improve' ) );
        $instrument_id      = intval( $request->get_param( 'instrument' ) );
        $video_sample_start = sanitize_text_field( (string) $request->get_param( 'submission_video_sample_start' ) );
        $video_sample_start = trim( substr( $video_sample_start, 0, 40 ) );

        if ( empty( $issue_text ) || empty( $improve_text ) || empty( $instrument_id ) ) {
            return new WP_Error(
                'loft_missing_fields',
                'what_doesnt_feel_right, what_would_you_like_to_improve, and instrument are all required.',
                array( 'status' => 400 )
            );
        }

        $has_s3    = ( $s3_object_key !== '' );
        $has_video = ( $video_url !== '' );

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

        if ( ! $has_s3 && ! $has_video ) {
            return new WP_Error(
                'loft_no_media',
                'Please upload an audio file or paste a link to your video.',
                array( 'status' => 400 )
            );
        }

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

        wp_set_object_terms( $post_id, $instrument_id, 'loft-instrument' );

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

    private function get_s3_bucket() {
        if ( defined( 'LOFT_S3_BUCKET' ) && LOFT_S3_BUCKET ) {
            return LOFT_S3_BUCKET;
        }
        return (string) get_option( 'loft_s3_bucket', '' );
    }

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
