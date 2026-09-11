<?php
/**
 * Admin interface.
 */
class Housemajik_Admin {

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_menu_page(
			'Housemagik Settings',
			'Housemagik',
			'manage_options',
			'housemajik',
			array( $this, 'render_settings_page' ),
			'dashicons-admin-home',
			30
		);
		
		add_submenu_page(
			'housemajik',
			'Settings',
			'Settings',
			'manage_options',
			'housemajik',
			array( $this, 'render_settings_page' )
		);
		
		add_submenu_page(
			'housemajik',
			'Alerts',
			'Alerts',
			'manage_options',
			'housemajik-alerts',
			array( $this, 'render_alerts_page' )
		);
		
		add_submenu_page(
			'housemajik',
			'Cron Status',
			'Cron Status',
			'manage_options',
			'housemajik-cron',
			array( $this, 'render_cron_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// AI Settings
		register_setting( 'housemajik_ai', 'housemajik_ai_model' );
		register_setting( 'housemajik_ai', 'housemajik_ai_daily_cap' );
		
		// Data Source
		register_setting( 'housemajik_general', 'housemajik_data_source' );
		
		// ARMLS Settings
		register_setting( 'housemajik_armls', 'housemajik_armls_endpoint' );
		register_setting( 'housemajik_armls', 'housemajik_armls_username' );
		register_setting( 'housemajik_armls', 'housemajik_armls_password' );
		
		// Broker Settings
		register_setting(
			'housemajik_broker',
			'housemajik_agent_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			)
		);
		register_setting(
			'housemajik_broker',
			'housemajik_broker_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( 'Housemajik_Security', 'sanitize_email_list' ),
			)
		);
		register_setting( 'housemajik_broker', 'housemajik_broker_name' );
		register_setting( 'housemajik_broker', 'housemajik_brokerage_name' );
		
		// Email Settings
		register_setting( 'housemajik_email', 'housemajik_sender_email' );
		register_setting( 'housemajik_email', 'housemajik_buyer_sender_email' );
		register_setting( 'housemajik_email', 'housemajik_sender_name' );
		register_setting( 'housemajik_email', 'housemajik_reply_to_email' );
		
		// Alert Settings
		register_setting( 'housemajik_alerts', 'housemajik_alert_frequency' );
		
		// Other Settings
		register_setting( 'housemajik_general', 'housemajik_idx_disclaimer' );
		register_setting(
			'housemajik_general',
			'housemajik_google_maps_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		register_setting( 'housemajik_general', 'housemajik_rate_limit_searches' );
		register_setting( 'housemajik_general', 'housemajik_rate_limit_registers' );
		register_setting( 'housemajik_general', 'housemajik_rate_limit_window' );
	}

	/**
	 * Enqueue admin styles.
	 */
	public function enqueue_styles( $hook ) {
		if ( strpos( $hook, 'housemajik' ) === false ) {
			return;
		}
		
		wp_enqueue_style(
			'housemajik-admin',
			HOUSEMAJIK_PLUGIN_URL . 'admin/css/housemagik-admin.css',
			array(),
			HOUSEMAJIK_VERSION
		);
	}

	/**
	 * Enqueue admin scripts.
	 */
	public function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'housemajik' ) === false ) {
			return;
		}
		
		wp_enqueue_script(
			'housemajik-admin',
			HOUSEMAJIK_PLUGIN_URL . 'admin/js/housemagik-admin.js',
			array( 'jquery' ),
			HOUSEMAJIK_VERSION,
			true
		);
		
		wp_localize_script( 'housemajik-admin', 'housemajikAdmin', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'housemajik_admin' ),
		) );
	}

	/**
	 * Show admin notices.
	 */
	public function show_admin_notices() {
		$screen = get_current_screen();
		if ( strpos( $screen->id, 'housemajik' ) === false ) {
			return;
		}
		
		// Check if Anthropic API key is configured
		if ( ! defined( 'ANTHROPIC_API_KEY' ) || ! ANTHROPIC_API_KEY ) {
			echo '<div class="notice notice-warning"><p>';
			echo '<strong>Housemagik:</strong> Anthropic API key not configured. ';
			echo 'Add <code>define( \'ANTHROPIC_API_KEY\', \'your-key-here\' );</code> to wp-config.php for AI features.';
			echo '</p></div>';
		}

		$last_mail = get_option( 'housemajik_last_mail' );
		if ( is_array( $last_mail ) && isset( $last_mail['ok'] ) && ! $last_mail['ok'] ) {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>Housemagik email:</strong> The last send failed. ';
			echo esc_html( ! empty( $last_mail['error'] ) ? $last_mail['error'] : 'wp_mail returned false' );
			if ( ! empty( $last_mail['time'] ) ) {
				echo ' <em>(' . esc_html( $last_mail['time'] ) . ')</em>';
			}
			echo '</p></div>';
		}

		$last_error = get_option( 'housemajik_ai_last_error' );
		if ( is_array( $last_error ) && ! empty( $last_error['message'] ) ) {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>Housemagik AI:</strong> The last Claude request failed, so why-lines used a local fallback. ';
			echo esc_html( $last_error['message'] );
			if ( ! empty( $last_error['time'] ) ) {
				echo ' <em>(' . esc_html( $last_error['time'] ) . ')</em>';
			}
			echo '</p></div>';
		}
		
		// Check data source
		$data_source = get_option( 'housemajik_data_source', 'sample' );
		if ( $data_source === 'sample' ) {
			echo '<div class="notice notice-info"><p>';
			echo '<strong>Housemagik:</strong> Currently using sample data. Configure ARMLS credentials below to use live listings.';
			echo '</p></div>';
		}
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		
		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error( 'housemajik_messages', 'housemajik_message', 'Settings saved.', 'updated' );
		}

		if ( isset( $_POST['housemajik_test_mail'] ) && check_admin_referer( 'housemajik_test_mail' ) ) {
			$to = Housemajik_Security::sanitize_email( wp_unslash( $_POST['housemajik_test_to'] ?? '' ) );
			$audience = ( $_POST['housemajik_test_mail'] === 'broker' ) ? 'broker' : 'buyer';
			if ( ! is_email( $to ) ) {
				add_settings_error( 'housemajik_messages', 'housemajik_test_mail', 'Enter a valid test address.', 'error' );
			} elseif ( Housemajik_Email::send_test( $to, $audience ) ) {
				add_settings_error( 'housemajik_messages', 'housemajik_test_mail', 'Test email sent to ' . $to . '.', 'updated' );
			} else {
				$status = Housemajik_Email::last_status();
				$detail = ! empty( $status['error'] ) ? $status['error'] : 'wp_mail returned false';
				add_settings_error( 'housemajik_messages', 'housemajik_test_mail', 'Test email failed: ' . $detail, 'error' );
			}
		}
		
		settings_errors( 'housemajik_messages' );
		
		$active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'general';
		?>
		
		<div class="wrap housemajik-admin">
			<h1>Housemagik Settings</h1>
			
			<h2 class="nav-tab-wrapper">
				<a href="?page=housemajik&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">General</a>
				<a href="?page=housemajik&tab=ai" class="nav-tab <?php echo $active_tab === 'ai' ? 'nav-tab-active' : ''; ?>">AI</a>
				<a href="?page=housemajik&tab=armls" class="nav-tab <?php echo $active_tab === 'armls' ? 'nav-tab-active' : ''; ?>">ARMLS</a>
				<a href="?page=housemajik&tab=broker" class="nav-tab <?php echo $active_tab === 'broker' ? 'nav-tab-active' : ''; ?>">Broker</a>
				<a href="?page=housemajik&tab=email" class="nav-tab <?php echo $active_tab === 'email' ? 'nav-tab-active' : ''; ?>">Email</a>
				<a href="?page=housemajik&tab=alerts" class="nav-tab <?php echo $active_tab === 'alerts' ? 'nav-tab-active' : ''; ?>">Alerts</a>
			</h2>
			
			<form method="post" action="options.php">
				<?php
				if ( $active_tab === 'general' ) {
					settings_fields( 'housemajik_general' );
					$this->render_general_settings();
				} elseif ( $active_tab === 'ai' ) {
					settings_fields( 'housemajik_ai' );
					$this->render_ai_settings();
				} elseif ( $active_tab === 'armls' ) {
					settings_fields( 'housemajik_armls' );
					$this->render_armls_settings();
				} elseif ( $active_tab === 'broker' ) {
					settings_fields( 'housemajik_broker' );
					$this->render_broker_settings();
				} elseif ( $active_tab === 'email' ) {
					settings_fields( 'housemajik_email' );
					$this->render_email_settings();
				} elseif ( $active_tab === 'alerts' ) {
					settings_fields( 'housemajik_alerts' );
					$this->render_alert_settings();
				}
				
				submit_button();
				?>
			</form>
			<?php if ( $active_tab === 'email' ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=housemajik&tab=email' ) ); ?>" style="margin-top: 24px;">
				<?php wp_nonce_field( 'housemajik_test_mail' ); ?>
				<h2>Send a test</h2>
				<p>Sends a short message so you can see whether Brevo accepts mail from this site.</p>
				<p>
					<label for="housemajik_test_to">Send test to</label><br>
					<input type="email" id="housemajik_test_to" name="housemajik_test_to" class="regular-text" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
				</p>
				<p>
					<button type="submit" name="housemajik_test_mail" value="buyer" class="button button-secondary">Send test email</button>
				</p>
			</form>
			<?php endif; ?>
		</div>
		
		<?php
	}

	private function render_general_settings() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">Data Source</th>
				<td>
					<select name="housemajik_data_source">
						<option value="sample" <?php selected( get_option( 'housemajik_data_source', 'sample' ), 'sample' ); ?>>Sample Data</option>
						<option value="armls" <?php selected( get_option( 'housemajik_data_source' ), 'armls' ); ?>>ARMLS Feed</option>
					</select>
					<p class="description">Use sample data for testing, or ARMLS when credentials are configured.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">IDX Disclaimer</th>
				<td>
					<textarea name="housemajik_idx_disclaimer" rows="4" class="large-text"><?php echo esc_textarea( get_option( 'housemajik_idx_disclaimer' ) ); ?></textarea>
					<p class="description">Use [DATE] placeholder for last updated date.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Google Maps API Key</th>
				<td>
					<input type="text" name="housemajik_google_maps_key" value="<?php echo esc_attr( get_option( 'housemajik_google_maps_key', '' ) ); ?>" class="regular-text">
					<p class="description">Optional. Listing maps already work with OpenStreetMap. Add a Maps JavaScript API key only if you want Google tiles instead.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Rate Limit</th>
				<td>
					<input type="number" name="housemajik_rate_limit_searches" value="<?php echo esc_attr( get_option( 'housemajik_rate_limit_searches', 100 ) ); ?>" style="width: 80px;" min="1">
					searches per
					<input type="number" name="housemajik_rate_limit_window" value="<?php echo esc_attr( get_option( 'housemajik_rate_limit_window', 3600 ) ); ?>" style="width: 100px;" min="1">
					seconds
					<br><br>
					<input type="number" name="housemajik_rate_limit_registers" value="<?php echo esc_attr( get_option( 'housemajik_rate_limit_registers', 5 ) ); ?>" style="width: 80px;" min="1">
					registrations (and alert saves) in that same window
					<p class="description">Search can be frequent. Register sends mail, so keep it low. 3600 seconds = 1 hour. The same email gets at most one confirmation per day, and the site sends at most 50 registration letters per day. Logged-in administrators are not limited.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	private function render_ai_settings() {
		$api_key_configured = defined( 'ANTHROPIC_API_KEY' ) && ANTHROPIC_API_KEY;
		$usage_today = (int) get_option( 'housemajik_ai_usage_today', 0 );
		$current_model = Housemajik_AI::get_model();
		?>
		<table class="form-table">
			<tr>
				<th scope="row">Anthropic API Key</th>
				<td>
					<?php if ( $api_key_configured ) : ?>
						<span style="color: #46b450;">✓ Configured in wp-config.php</span>
					<?php else : ?>
						<span style="color: #dc3232;">✗ Not configured</span>
						<p class="description">Add to wp-config.php: <code>define( 'ANTHROPIC_API_KEY', 'your-key-here' );</code></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">AI Model</th>
				<td>
					<select name="housemajik_ai_model">
						<option value="claude-haiku-4-5" <?php selected( $current_model, 'claude-haiku-4-5' ); ?>>Claude Haiku 4.5 (Recommended)</option>
						<option value="claude-sonnet-5" <?php selected( $current_model, 'claude-sonnet-5' ); ?>>Claude Sonnet 5</option>
						<option value="claude-opus-5" <?php selected( $current_model, 'claude-opus-5' ); ?>>Claude Opus 5</option>
					</select>
					<p class="description">Claude 3 Haiku / Sonnet / Opus IDs are retired and now return API errors.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Daily Usage Cap</th>
				<td>
					<input type="number" name="housemajik_ai_daily_cap" value="<?php echo esc_attr( get_option( 'housemajik_ai_daily_cap', 200 ) ); ?>" class="small-text">
					API calls per day
					<p class="description">Today's usage: <strong><?php echo (int) $usage_today; ?></strong>. Each search uses one Claude call. After the cap, listings still show with the local why-line. One visitor IP is limited to 30 Claude searches per day.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	private function render_armls_settings() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">ARMLS Endpoint</th>
				<td>
					<input type="url" name="housemajik_armls_endpoint" value="<?php echo esc_attr( get_option( 'housemajik_armls_endpoint', '' ) ); ?>" class="large-text" placeholder="https://api.armls.com/...">
					<p class="description">Spark Web API endpoint URL.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Username</th>
				<td>
					<input type="text" name="housemajik_armls_username" value="<?php echo esc_attr( get_option( 'housemajik_armls_username', '' ) ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">Password</th>
				<td>
					<input type="password" name="housemajik_armls_password" value="<?php echo esc_attr( get_option( 'housemajik_armls_password', '' ) ); ?>" class="regular-text">
				</td>
			</tr>
		</table>
		<?php
	}

	private function render_broker_settings() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">Agent ID</th>
				<td>
					<input type="text" name="housemajik_agent_id" value="<?php echo esc_attr( Housemajik_Agent::id() ); ?>" class="regular-text">
					<p class="description">Stable id for this agent's buyers, saves, comments, and alerts. Do not change it after buyers exist. Next agent gets a different WordPress site or a different id.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Broker Name</th>
				<td>
					<input type="text" name="housemajik_broker_name" value="<?php echo esc_attr( get_option( 'housemajik_broker_name', 'Suzanne Gonzalez' ) ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">Brokerage Name</th>
				<td>
					<input type="text" name="housemajik_brokerage_name" value="<?php echo esc_attr( get_option( 'housemajik_brokerage_name', 'Keys of Dreams Brokery' ) ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">Broker Email</th>
				<td>
					<input type="text" name="housemajik_broker_email" value="<?php echo esc_attr( get_option( 'housemajik_broker_email', 'mlake@redlake.tv' ) ); ?>" class="regular-text" style="max-width: 36rem; width: 100%;">
					<p class="description">One or more inboxes, separated by commas. Each gets New Lead, Search updated, Friday recap, and alert copies. Do not include leads@ — that address is the sender, and Brevo will not deliver into it.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	private function render_email_settings() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">Sender Name</th>
				<td>
					<input type="text" name="housemajik_sender_name" value="<?php echo esc_attr( get_option( 'housemajik_sender_name', get_bloginfo( 'name' ) ) ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">Broker From address</th>
				<td>
					<input type="email" name="housemajik_sender_email" value="<?php echo esc_attr( get_option( 'housemajik_sender_email', 'leads@housemagik.ai' ) ); ?>" class="regular-text">
					<p class="description">From address on mail to the broker (New Lead and alert copies). Always leads@.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Buyer From address</th>
				<td>
					<input type="email" name="housemajik_buyer_sender_email" value="<?php echo esc_attr( get_option( 'housemajik_buyer_sender_email', 'homealerts@housemagik.ai' ) ); ?>" class="regular-text">
					<p class="description">From address on all mail to the buyer. Always homealerts@. Never leads@.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Reply-To Email</th>
				<td>
					<input type="email" name="housemajik_reply_to_email" value="<?php echo esc_attr( get_option( 'housemajik_reply_to_email', get_option( 'admin_email' ) ) ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">Last send</th>
				<td>
					<?php
					$status = Housemajik_Email::last_status();
					if ( empty( $status ) ) {
						echo '<p class="description">No plugin mail has been attempted yet.</p>';
					} else {
						$ok = ! empty( $status['ok'] );
						echo '<p><strong>' . ( $ok ? 'Sent' : 'Failed' ) . '</strong>';
						if ( ! empty( $status['time'] ) ) {
							echo ' at ' . esc_html( $status['time'] );
						}
						echo '</p>';
						if ( ! empty( $status['to'] ) ) {
							echo '<p>To: ' . esc_html( $status['to'] ) . '</p>';
						}
						if ( ! empty( $status['from'] ) ) {
							echo '<p>From: ' . esc_html( $status['from'] ) . '</p>';
						}
						if ( ! empty( $status['subject'] ) ) {
							echo '<p>Subject: ' . esc_html( $status['subject'] ) . '</p>';
						}
						if ( ! $ok && ! empty( $status['error'] ) ) {
							echo '<p style="color:#b32d2e;">' . esc_html( $status['error'] ) . '</p>';
						}
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row">SMTP</th>
				<td>
					<p class="description">
						WP Mail SMTP must be allowed to send as both From addresses. Turn off “Force from email” if it is pinning every message to leads@.
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	private function render_alert_settings() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">Alert Frequency</th>
				<td>
					<select name="housemajik_alert_frequency">
						<option value="daily" <?php selected( get_option( 'housemajik_alert_frequency', 'daily' ), 'daily' ); ?>>Daily</option>
					</select>
					<p class="description">How often to check for new matches and send alerts.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render alerts page.
	 */
	public function render_alerts_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		
		global $wpdb;
		$table = $wpdb->prefix . 'housemajik_alerts';
		$alerts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE agent_id = %s ORDER BY created_at DESC",
				Housemajik_Agent::id()
			),
			ARRAY_A
		);
		
		?>
		<div class="wrap">
			<h1>Saved Search Alerts</h1>
			
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Name</th>
						<th>Email</th>
						<th>Location</th>
						<th>Criteria</th>
						<th>Status</th>
						<th>Created</th>
						<th>Last Sent</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $alerts ) ) : ?>
						<tr>
							<td colspan="7">No alerts saved yet.</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $alerts as $alert ) :
							$params = json_decode( $alert['search_params'], true );
						?>
							<tr>
								<td><?php echo esc_html( $alert['name'] ); ?></td>
								<td><?php echo esc_html( $alert['email'] ); ?></td>
								<td><?php echo esc_html( Housemajik_Locations::display( $params['location'] ?? '' ) ); ?></td>
								<td>
									<?php echo esc_html( $params['beds'] ); ?> bed, 
									<?php echo esc_html( $params['baths'] ); ?> bath, 
									$<?php echo number_format( $params['max_price'] ); ?>
								</td>
								<td>
									<?php if ( $alert['active'] ) : ?>
										<span style="color: #46b450;">● Active</span>
									<?php else : ?>
										<span style="color: #999;">○ Inactive</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $alert['created_at'] ); ?></td>
								<td><?php echo $alert['last_sent_at'] ? esc_html( $alert['last_sent_at'] ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render cron status page.
	 */
	public function render_cron_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		
		// Handle manual trigger
		if ( isset( $_POST['trigger_cron'] ) && check_admin_referer( 'housemajik_trigger_cron' ) ) {
			Housemajik_Alerts::trigger_manual();
			echo '<div class="notice notice-success"><p>Alert cron triggered manually.</p></div>';
		}
		if ( isset( $_POST['trigger_weekly'] ) && check_admin_referer( 'housemajik_trigger_weekly' ) ) {
			Housemajik_Buyer_Activity::run_weekly_report();
			echo '<div class="notice notice-success"><p>Weekly activity report sent.</p></div>';
		}
		
		$status = Housemajik_Alerts::get_cron_status();
		
		global $wpdb;
		$log_table = $wpdb->prefix . 'housemajik_cron_log';
		$logs = $wpdb->get_results( "SELECT * FROM $log_table ORDER BY run_at DESC LIMIT 20", ARRAY_A );
		
		?>
		<div class="wrap">
			<h1>Cron Status</h1>
			
			<div class="card" style="max-width: 800px;">
				<h2>Current Status</h2>
				<table class="form-table">
					<tr>
						<th>Scheduled:</th>
						<td><?php echo $status['is_scheduled'] ? '<span style="color: #46b450;">✓ Yes</span>' : '<span style="color: #dc3232;">✗ No</span>'; ?></td>
					</tr>
					<tr>
						<th>Next Run:</th>
						<td><?php echo esc_html( $status['next_run'] ); ?></td>
					</tr>
					<tr>
						<th>Weekly report:</th>
						<td><?php
						$weekly = wp_next_scheduled( Housemajik_Buyer_Activity::WEEKLY_HOOK );
						if ( $weekly ) {
							$phoenix = new DateTime( '@' . $weekly );
							$phoenix->setTimezone( new DateTimeZone( 'America/Phoenix' ) );
							echo esc_html( $phoenix->format( 'l, F j, Y g:i a T' ) );
						} else {
							echo 'Not scheduled';
						}
						?></td>
					</tr>
					<?php if ( $status['last_run'] ) : ?>
						<tr>
							<th>Last Run:</th>
							<td><?php echo esc_html( $status['last_run']['run_at'] ); ?></td>
						</tr>
						<tr>
							<th>Last Status:</th>
							<td><?php echo esc_html( ucfirst( $status['last_run']['status'] ) ); ?></td>
						</tr>
						<tr>
							<th>Alerts Processed:</th>
							<td><?php echo esc_html( $status['last_run']['alerts_processed'] ); ?></td>
						</tr>
						<tr>
							<th>Matches Sent:</th>
							<td><?php echo esc_html( $status['last_run']['matches_sent'] ); ?></td>
						</tr>
					<?php endif; ?>
				</table>
				
				<form method="post">
					<?php wp_nonce_field( 'housemajik_trigger_cron' ); ?>
					<button type="submit" name="trigger_cron" class="button button-primary">Trigger Manual Run</button>
				</form>
				<form method="post" style="margin-top: 0.75rem;">
					<?php wp_nonce_field( 'housemajik_trigger_weekly' ); ?>
					<button type="submit" name="trigger_weekly" class="button">Send weekly activity report now</button>
				</form>
			</div>
			
			<h2>Recent Runs</h2>
			<table class="wp-list-table widefat fixed striped" style="max-width: 1200px;">
				<thead>
					<tr>
						<th>Run Time</th>
						<th>Alerts Processed</th>
						<th>Matches Sent</th>
						<th>Status</th>
						<th>Message</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr>
							<td colspan="5">No cron runs recorded yet.</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log['run_at'] ); ?></td>
								<td><?php echo esc_html( $log['alerts_processed'] ); ?></td>
								<td><?php echo esc_html( $log['matches_sent'] ); ?></td>
								<td><?php echo esc_html( ucfirst( $log['status'] ) ); ?></td>
								<td><?php echo esc_html( $log['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			
			<div class="card" style="max-width: 800px; margin-top: 20px;">
				<h3>WP-Cron Setup</h3>
				<p>
					By default, WordPress WP-Cron triggers on page visits. For production, set up a system cron job for reliability:
				</p>
				<pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;">wget -q -O - <?php echo site_url( 'wp-cron.php?doing_wp_cron' ); ?></pre>
				<p>
					Add to crontab to run every hour, then disable default WP-Cron by adding to wp-config.php:
				</p>
				<pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;">define( 'DISABLE_WP_CRON', true );</pre>
			</div>
		</div>
		<?php
	}
}
