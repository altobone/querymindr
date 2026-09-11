<?php
/**
 * One-time registration prompt. Shown after the first property note.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$broker_name    = get_option( 'housemajik_broker_name', 'Suzanne Gonzalez' );
$brokerage_name = get_option( 'housemajik_brokerage_name', 'Keys of Dreams Brokery' );
?>
<div class="housemajik-modal" id="housemajik-register-modal" style="display: none;">
	<div class="housemajik-modal-overlay"></div>
	<div class="housemajik-modal-content">
		<button class="housemajik-modal-close" id="housemajik-modal-close" aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6L18 18M18 6L6 18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square"/></svg></button>

		<h3>Stay up to date</h3>
		<p>Let's save your comments so that we can best match your searches to your taste. Leave your name and email and we'll save your notes and send matching homes as they become available.</p>

		<form id="housemajik-register-form" class="housemajik-modal-form">
			<input type="hidden" name="website" value="" class="housemajik-honeypot">
			<input type="hidden" name="listing_id" id="reg_listing_id" value="">

			<p class="housemajik-required-note"><span aria-hidden="true">*</span> Required fields</p>

			<div class="housemajik-row">
				<div class="housemajik-field housemajik-col-half">
					<label for="reg_first_name">First name <span class="housemajik-required" aria-hidden="true">*</span></label>
					<input type="text" id="reg_first_name" name="first_name" autocomplete="given-name" required>
				</div>
				<div class="housemajik-field housemajik-col-half">
					<label for="reg_last_name">Last name (optional)</label>
					<input type="text" id="reg_last_name" name="last_name" autocomplete="family-name">
				</div>
			</div>

			<div class="housemajik-field">
				<label for="reg_email">Email <span class="housemajik-required" aria-hidden="true">*</span></label>
				<input type="email" id="reg_email" name="email" required>
			</div>

			<div class="housemajik-field">
				<label for="reg_phone">Phone (optional)</label>
				<input type="tel" id="reg_phone" name="phone">
			</div>

			<div class="housemajik-field">
				<label class="housemajik-checkbox">
					<input type="checkbox" name="alert" id="reg_alert" checked>
					<span>Email me when new listings meet my criteria</span>
				</label>
			</div>

			<p class="housemajik-consent">
				By registering, you agree to receive search results and property alerts.
				Your information will be shared with <?php echo esc_html( $broker_name ); ?>
				at <?php echo esc_html( $brokerage_name ); ?>.
			</p>

			<div class="housemajik-modal-actions">
				<button type="button" class="housemajik-btn housemajik-btn-secondary" id="housemajik-skip-register">Skip for now</button>
				<button type="submit" class="housemajik-btn housemajik-btn-primary" id="housemajik-register-submit">Register</button>
			</div>
		</form>
	</div>
</div>
