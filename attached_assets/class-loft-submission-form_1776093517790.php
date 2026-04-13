<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Loft_Submission_Form {

    public function __construct() {
        add_shortcode( 'loft_submission_form', array( $this, 'render_shortcode' ) );
        add_action( 'wp_ajax_loft_create_submission', array( $this, 'create_submission' ) );
    }

    public function render_shortcode( $atts ) {
        if ( the_loft_public_submissions_enabled() ) {
            if ( ! is_user_logged_in() ) {
                wp_enqueue_style( 'loft-submission-css', plugin_dir_url( __FILE__ ) . '../assets/css/loft-submission.css' );
                ob_start();
                ?>
                <div class="loft-submission-wrapper loft-submission-account-gate">
                    <p class="loft-account-gate-message"><?php echo esc_html__( 'Register or log in to your Music Savvy account to submit your playing.', 'the-loft' ); ?></p>
                    <a href="<?php echo esc_url( 'https://musicsavvy.com/my-account' ); ?>" class="loft-account-gate-btn"><?php echo esc_html__( 'Log In or Register', 'the-loft' ); ?></a>
                </div>
                <?php
                return ob_get_clean();
            }
        } else {
            if ( ! is_user_logged_in() ) {
                return '<p>You must be logged in to your MusicSavvy.com account to submit.</p>';
            }

            $user_id = get_current_user_id();

            // Membership check
            $has_membership = false;
            if ( current_user_can('manage_options') ) {
                $has_membership = true;
            } elseif ( function_exists('wc_memberships_is_user_active_member') ) {
                if ( wc_memberships_is_user_active_member( $user_id, 'essentials' ) || wc_memberships_is_user_active_member( $user_id, 'mastery' ) ) {
                    $has_membership = true;
                }
            }

            if ( ! $has_membership ) {
                return '<p>Submitting to The Loft is available to Jazz Circle Essentials and Mastery members only.</p>
                    <br>
                    <a href="https://musicsavvy.com/step/jazz-circle-landing/" target="_blank" class="loft-join-btn">Join Jazz Circle</a>';
            }
        }

        // 24 hour lock check (Disabled for Testing)
        // $transient_key = 'loft_submission_24hr_limit_' . $user_id;
        // if ( get_transient( $transient_key ) ) {
        //     return '<p>You\'ve already submitted today. Please wait 24 hours before submitting again.</p>';
        // }

        // Enqueue Assets
        $loft_asset_base = plugin_dir_path( __FILE__ ) . '../assets/';
        $loft_js_ver     = file_exists( $loft_asset_base . 'js/loft-submission.js' ) ? filemtime( $loft_asset_base . 'js/loft-submission.js' ) : false;
        $loft_css_ver    = file_exists( $loft_asset_base . 'css/loft-submission.css' ) ? filemtime( $loft_asset_base . 'css/loft-submission.css' ) : false;
        wp_enqueue_style( 'loft-submission-css', plugin_dir_url( __FILE__ ) . '../assets/css/loft-submission.css', array(), $loft_css_ver );
        wp_enqueue_script( 'loft-submission-js', plugin_dir_url( __FILE__ ) . '../assets/js/loft-submission.js', array(), $loft_js_ver, true );

        wp_localize_script( 'loft-submission-js', 'loftData', array(
            'ajax_url'           => admin_url( 'admin-ajax.php' ),
            'upload_nonce'       => wp_create_nonce( 'loft_secure_upload' ),
            'submit_nonce'       => wp_create_nonce( 'loft_submission_nonce' ),
            'i18n_selected_file' => __( 'Selected file:', 'the-loft' ),
        ));

        // Get taxonomy terms
        $terms = get_terms( array(
            'taxonomy'   => 'loft-instrument',
            'hide_empty' => false,
        ));

        $options = '';
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            foreach ( $terms as $term ) {
                $options .= '<option value="' . esc_attr( $term->term_id ) . '">' . esc_html( $term->name ) . '</option>';
            }
        }

        ob_start();
        ?>
        <div class="loft-submission-wrapper">
            <div class="loft-submission-card">
                <div id="loft-submission-message-container" class="loft-submission-message-slot"></div>
                <div class="loft-submission-card-header" role="banner">
                    <h2 class="loft-submission-card-title"><?php echo esc_html__( 'Playing Submission Form', 'the-loft' ); ?></h2>
                    <p class="loft-submission-card-subtitle"><?php echo esc_html__( 'Upload an MP3 or M4A sample, or paste a link to a video of your playing (YouTube, Vimeo, etc.). Describe how you feel about it and what you would like to improve, then select your instrument. You need either an audio file or a video link.', 'the-loft' ); ?></p>
                </div>
                <form id="loft-submission-form" class="loft-submission-card-form" enctype="multipart/form-data" novalidate>
                    <div class="loft-submission-card-body">
                        <div class="loft-form-group">
                            <div id="loft-audio-file-label" class="loft-field-label"><?php echo esc_html__( 'Audio file', 'the-loft' ); ?></div>
                            <div class="loft-file-upload">
                                <div class="loft-file-picker" aria-hidden="true">
                                    <span class="loft-file-picker-icon">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="7 10 12 15 17 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="15" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                    </span>
                                    <span class="loft-file-picker-copy">
                                        <span class="loft-file-picker-strong"><?php echo esc_html__( 'Choose a file', 'the-loft' ); ?></span><span class="loft-file-picker-meta"><?php echo esc_html( ' · MP3 or M4A, max 15MB' ); ?></span>
                                    </span>
                                </div>
                                <input type="file" id="loft_audio_file" class="loft-file-input-overlay" aria-labelledby="loft-audio-file-label" accept=".mp3,.m4a,audio/mpeg,audio/mp4">
                            </div>
                            <p id="loft-file-selected-name" class="loft-file-selected-name" hidden aria-live="polite"></p>
                        </div>

                        <div class="loft-form-group">
                            <label class="loft-field-label" for="loft_submission_video_url"><?php echo esc_html__( 'Or link to a video', 'the-loft' ); ?></label>
                            <input type="text" id="loft_submission_video_url" class="loft-field-input-url" name="loft_submission_video_url" inputmode="url" autocomplete="off" placeholder="<?php echo esc_attr__( 'https://… (YouTube, Vimeo, or other)', 'the-loft' ); ?>">
                            <p class="loft-field-hint"><?php echo esc_html__( 'Paste a full URL to your playing. Required only if you are not uploading an audio file above.', 'the-loft' ); ?></p>
                        </div>

                        <div class="loft-form-group">
                            <label class="loft-field-label" for="loft_submission_video_sample_start"><?php echo esc_html__( 'Sample starts at (in the video)', 'the-loft' ); ?></label>
                            <input type="text" id="loft_submission_video_sample_start" class="loft-field-input-text" name="loft_submission_video_sample_start" maxlength="40" autocomplete="off" placeholder="<?php echo esc_attr__( 'e.g. 4:15 or 1:02:30', 'the-loft' ); ?>">
                            <p class="loft-field-hint"><?php echo esc_html__( 'Optional. If your video is long, enter the time where the playing you want reviewed begins (from the video’s timer). Shown on the review page for Mike and other viewers.', 'the-loft' ); ?></p>
                        </div>

                        <div class="loft-form-group">
                            <label class="loft-field-label" for="loft_what_doesnt_feel_right"><?php echo esc_html__( 'What doesn\'t feel right to you?', 'the-loft' ); ?> <span class="loft-label-required" aria-hidden="true">*</span></label>
                            <textarea id="loft_what_doesnt_feel_right" class="loft-field-textarea" placeholder="<?php echo esc_attr__( 'Describe what feels off in your playing...', 'the-loft' ); ?>" required></textarea>
                        </div>

                        <div class="loft-form-group">
                            <label class="loft-field-label" for="loft_what_would_you_like_to_improve"><?php echo esc_html__( 'What would you like to improve?', 'the-loft' ); ?> <span class="loft-label-required" aria-hidden="true">*</span></label>
                            <textarea id="loft_what_would_you_like_to_improve" class="loft-field-textarea" placeholder="<?php echo esc_attr__( 'What specific aspect are you working on...', 'the-loft' ); ?>" required></textarea>
                        </div>

                        <div class="loft-form-group">
                            <label class="loft-field-label" for="loft_instrument"><?php echo esc_html__( 'Instrument', 'the-loft' ); ?> <span class="loft-label-required" aria-hidden="true">*</span></label>
                            <select id="loft_instrument" class="loft-field-select" required>
                                <option value=""><?php echo esc_html__( 'Select an instrument...', 'the-loft' ); ?></option>
                                <?php echo $options; ?>
                            </select>
                        </div>

                        <div class="loft-hp-wrapper">
                            <input type="text" id="loft_hp_field" name="loft_hp_field" tabindex="-1" autocomplete="off">
                        </div>

                        <div id="loft-form-errors" class="loft-form-errors"></div>

                        <hr class="loft-submit-divider" aria-hidden="true">

                        <p class="loft-submit-note"><?php echo esc_html__( 'Once you submit, your playing goes directly into review. You\'ll be notified by email when your full review and recommendations are ready.', 'the-loft' ); ?></p>

                        <div class="loft-form-group loft-form-group-submit">
                            <button type="submit" id="loft-submit-btn" class="loft-submit-btn"><?php echo esc_html__( 'Submit to The Loft', 'the-loft' ); ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function create_submission() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'loft_submission_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token.' ), 403 );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to submit.' ), 401 );
        }

        $user_id = get_current_user_id();

        if ( ! the_loft_public_submissions_enabled() ) {
            // Server-side Check membership
            $has_membership = false;
            if ( current_user_can('manage_options') ) {
                $has_membership = true;
            } elseif ( function_exists('wc_memberships_is_user_active_member') ) {
                if ( wc_memberships_is_user_active_member( $user_id, 'essentials' ) || wc_memberships_is_user_active_member( $user_id, 'mastery' ) ) {
                    $has_membership = true;
                }
            }

            if ( ! $has_membership ) {
                wp_send_json_error( array( 'message' => 'Active Essentials or Mastery membership required.' ), 403 );
            }
        }

        // 24 Hour rate limit (Disabled for Testing)
        // $transient_key = 'loft_submission_24hr_limit_' . $user_id;
        // if ( get_transient( $transient_key ) ) {
        //     wp_send_json_error( array( 'message' => 'You have already submitted within the last 24 hours.' ), 429 );
        // }

        // Honeypot check
        if ( ! empty( $_POST['loft_hp_field'] ) ) {
            // Silently succeed
            wp_send_json_success( array( 'message' => 'Form captured.' ) );
        }

        // Text & Data Sanitation
        $s3_object_key  = isset( $_POST['s3_object_key'] ) ? sanitize_text_field( wp_unslash( $_POST['s3_object_key'] ) ) : '';
        $video_url      = isset( $_POST['submission_video_url'] ) ? esc_url_raw( wp_unslash( $_POST['submission_video_url'] ) ) : '';
        $issue_text     = isset( $_POST['what_doesnt_feel_right'] ) ? sanitize_textarea_field( wp_unslash( $_POST['what_doesnt_feel_right'] ) ) : '';
        $improve_text   = isset( $_POST['what_would_you_like_to_improve'] ) ? sanitize_textarea_field( wp_unslash( $_POST['what_would_you_like_to_improve'] ) ) : '';
        $instrument_id  = isset( $_POST['instrument'] ) ? intval( $_POST['instrument'] ) : 0;

        if ( empty( $issue_text ) || empty( $improve_text ) || empty( $instrument_id ) ) {
            wp_send_json_error( array( 'message' => 'All fields are strictly required.' ), 400 );
        }

        $has_s3    = ( $s3_object_key !== '' );
        $has_video = ( $video_url !== '' );

        if ( $has_video && function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $video_url ) ) {
            wp_send_json_error( array( 'message' => 'Please enter a valid video URL (https://…), or leave it blank if you uploaded audio.' ), 400 );
        }
        if ( $has_video && ! function_exists( 'wp_http_validate_url' ) && ! preg_match( '#^https?://#i', $video_url ) ) {
            wp_send_json_error( array( 'message' => 'Please enter a valid video URL (https://…), or leave it blank if you uploaded audio.' ), 400 );
        }

        if ( ! $has_s3 && ! $has_video ) {
            wp_send_json_error( array( 'message' => 'Please upload an audio file or paste a link to your video.' ), 400 );
        }

        if ( $has_s3 && ! preg_match( '#^loft-submissions/' . $user_id . '/\d+-.*#', $s3_object_key ) ) {
            wp_send_json_error( array( 'message' => 'Invalid upload signature payload mapping.' ), 400 );
        }

        if ( ! $has_s3 ) {
            $s3_object_key = '';
        }

        $video_sample_start = isset( $_POST['submission_video_sample_start'] ) ? sanitize_text_field( wp_unslash( $_POST['submission_video_sample_start'] ) ) : '';
        $video_sample_start = trim( substr( $video_sample_start, 0, 40 ) );
        if ( ! $has_video ) {
            $video_sample_start = '';
        }

        $author = get_userdata($user_id);
        
        $first_name = get_user_meta( $user_id, 'first_name', true );
        $last_name = get_user_meta( $user_id, 'last_name', true );
        
        if ( ! empty( $first_name ) && ! empty( $last_name ) ) {
            $display_name = $first_name . ' ' . mb_substr( $last_name, 0, 1 ) . '.';
        } else {
            // Fallback if no explicit first/last name found
            $raw_name = $author ? $author->display_name : 'Unknown';
            $parts = explode(' ', $raw_name);
            if ( count($parts) > 1 ) {
                $last = array_pop($parts);
                $display_name = implode(' ', $parts) . ' ' . mb_substr($last, 0, 1) . '.';
            } else {
                $display_name = $raw_name;
            }
        }

        // 1. Core Post Hook
        $post_data = array(
            'post_title'   => 'Submission by ' . $display_name . ' (' . date('Y-m-d') . ')',
            'post_type'    => 'loft-submission',
            'post_status'  => 'draft',
            'post_author'  => $user_id,
        );

        $post_id = wp_insert_post( $post_data );
        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array( 'message' => 'Could not create submission database entry.' ), 500 );
        }

        // 2. Taxonomy Binding
        wp_set_object_terms( $post_id, $instrument_id, 'loft-instrument' );

        // 3. Mapping ACF Custom Fields
        if ( function_exists('update_field') ) {
            update_field( 'what_doesnt_feel_natural', $issue_text, $post_id );
            update_field( 'what_were_you_trying', $improve_text, $post_id );
            update_field( 's3_object_key', $s3_object_key, $post_id );
            update_field( 'submission_video_url', $has_video ? $video_url : '', $post_id );
            update_field( 'submission_video_sample_start', $has_video ? $video_sample_start : '', $post_id );
            update_field( 'submission_status', 'Pending', $post_id ); // Explicit status map defined
        } else {
            update_post_meta( $post_id, 'what_doesnt_feel_natural', $issue_text );
            update_post_meta( $post_id, 'what_were_you_trying', $improve_text );
            update_post_meta( $post_id, 's3_object_key', $s3_object_key );
            update_post_meta( $post_id, 'submission_video_url', $has_video ? $video_url : '' );
            update_post_meta( $post_id, 'submission_video_sample_start', $has_video ? $video_sample_start : '' );
            update_post_meta( $post_id, 'submission_status', 'Pending' );
        }

        // 4. Explicit Email Rule Execution
        $subject = "You have a new Loft submission.";
        $body  = "Submitter: " . $display_name . "\n";
        $body .= "Date: " . date('Y-m-d H:i:s') . "\n\n";
        $body .= "What doesn't feel right to you?\n" . $issue_text . "\n\n";
        $body .= "What would you like to improve?\n" . $improve_text . "\n\n";
        if ( $has_video ) {
            $body .= "Submitted video link:\n" . $video_url . "\n";
            if ( $video_sample_start !== '' ) {
                $body .= 'Sample starts at (in video): ' . $video_sample_start . "\n";
            }
            $body .= "\n";
        }
        $body .= "Direct WP admin edit link:\n" . get_edit_post_link( $post_id, 'raw' );

        wp_mail( 'mlake@redlake.tv', $subject, $body );

        wp_send_json_success( array( 'message' => 'Submission correctly recorded.' ) );
    }
}

new Loft_Submission_Form();
