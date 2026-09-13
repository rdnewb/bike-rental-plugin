<?php
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;
?>
<section class="brp-booking" data-api="<?php echo esc_url( rest_url( PublicBooking::API . '/' ) ); ?>" aria-label="Bike rental selection">
<form class="brp-form">
<fieldset><legend>Choose your bike rental</legend>
<label for="<?php echo esc_attr( $uid ); ?>-package">Rental package</label>
<select id="<?php echo esc_attr( $uid ); ?>-package" name="package_id" required disabled><option value="">Loading packages…</option></select>
<div class="brp-package" aria-live="polite"></div>
<label for="<?php echo esc_attr( $uid ); ?>-date">Start date</label>
<input id="<?php echo esc_attr( $uid ); ?>-date" name="date" type="date" required disabled>
<label for="<?php echo esc_attr( $uid ); ?>-time">Start time</label>
<select id="<?php echo esc_attr( $uid ); ?>-time" name="time" required disabled><option value="">Choose a date first</option></select>
<label for="<?php echo esc_attr( $uid ); ?>-quantity">Number of bikes</label>
<input id="<?php echo esc_attr( $uid ); ?>-quantity" name="quantity" type="number" inputmode="numeric" min="1" step="1" value="1" required disabled>
</fieldset>
<div class="brp-summary" aria-live="polite"></div>
<button class="brp-submit" type="submit" disabled>Reserve bikes</button>
</form>
<div class="brp-status" role="status" aria-live="polite" aria-atomic="true">Loading rental packages…</div>
<section class="brp-result" hidden tabindex="-1" aria-label="Temporary reservation">
<h3>Temporary reservation</h3><div class="brp-receipt"></div>
<p class="brp-expiry" role="status" aria-live="polite"></p>
<button class="brp-restart" type="button" hidden>Start over</button>
</section>
<noscript>Please enable JavaScript to check rental times and reserve bikes.</noscript>
</section>
