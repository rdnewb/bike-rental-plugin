<?php
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;
?>
<section class="brp-booking" data-api="<?php echo esc_url( rest_url( PublicBooking::API . '/' ) ); ?>" aria-label="Bike rental selection">
<form class="brp-form">
<fieldset><legend>Book your bike rental</legend>
<section aria-labelledby="<?php echo esc_attr( $uid ); ?>-choose">
<h2 id="<?php echo esc_attr( $uid ); ?>-choose">1. Choose Your Rental</h2>
<p>Choose a rental below, then select your date, start time, and number of bikes.</p>
<div class="brp-grid">
<?php foreach ( $cards as $card ) :
	$product = wc_get_product( $card['product_id'] );
	if ( ! $product ) { continue; }
	$heading = $uid . '-package-' . $card['product_id'];
	$image = wp_get_attachment_image( $product->get_image_id(), 'woocommerce_thumbnail', false, array( 'loading' => 'lazy', 'decoding' => 'async' ) );
	$description = wp_kses( wpautop( strip_shortcodes( $product->get_short_description() ) ), array( 'p' => array(), 'br' => array(), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'ul' => array(), 'ol' => array(), 'li' => array() ) );
	$amount = (int) $card['duration_amount'];
	$unit = 'hours' === $card['duration_type'] ? _n( 'Hour', 'Hours', $amount, 'bike-rental-plugin' ) : _n( 'Day', 'Days', $amount, 'bike-rental-plugin' );
?>
<article class="brp-card" data-package-id="<?php echo esc_attr( $card['product_id'] ); ?>" aria-labelledby="<?php echo esc_attr( $heading ); ?>">
<div class="brp-card-image"><?php if ( $image ) { echo wp_kses( $image, array( 'img' => array_fill_keys( array( 'src', 'srcset', 'sizes', 'width', 'height', 'alt', 'class', 'loading', 'decoding', 'fetchpriority' ), true ) ) ); } else { ?><span class="brp-image-fallback" role="img" aria-label="No rental photo available">Rental photo coming soon</span><?php } ?></div>
<div class="brp-card-content">
<?php if ( $card['promotional_label'] ) : ?><p class="brp-promo"><?php echo esc_html( $card['promotional_label'] ); ?></p><?php endif; ?>
<h3 id="<?php echo esc_attr( $heading ); ?>"><?php echo esc_html( $card['name'] ); ?></h3>
<p class="brp-duration"><?php echo esc_html( $amount . ' ' . $unit ); ?></p>
<?php if ( trim( wp_strip_all_tags( $description ) ) ) : ?><div class="brp-description" tabindex="0" role="region" aria-label="<?php echo esc_attr( $card['name'] . ' short description' ); ?>"><?php echo $description; // Restricted formatting allowlist above. ?></div><?php endif; ?>
<p class="brp-card-price"><?php echo wp_kses_post( $product->get_price_html() ); ?> <span class="brp-price-unit">per bike</span></p>
<button class="brp-select" type="button" aria-pressed="false" aria-label="<?php echo esc_attr( 'Select Rental: ' . $card['name'] ); ?>" data-package-name="<?php echo esc_attr( $card['name'] ); ?>" disabled>Select Rental</button>
</div>
</article>
<?php endforeach; ?>
</div>
<p class="brp-empty"<?php if ( $cards ) { echo ' hidden'; } ?>>No rental packages are currently available. Please check back soon or contact us.</p>
</section>
<input name="package_id" type="hidden" value="">
<div class="brp-details" hidden>
<h2>2. Choose Date / Time</h2>
<label for="<?php echo esc_attr( $uid ); ?>-date">Start date</label>
<input id="<?php echo esc_attr( $uid ); ?>-date" name="date" type="date" required disabled>
<label for="<?php echo esc_attr( $uid ); ?>-time">Start time</label>
<select id="<?php echo esc_attr( $uid ); ?>-time" name="time" required disabled><option value="">Choose a date first</option></select>
<h2>3. Choose Quantity</h2>
<label for="<?php echo esc_attr( $uid ); ?>-quantity">Number of bikes</label>
<input id="<?php echo esc_attr( $uid ); ?>-quantity" name="quantity" type="number" inputmode="numeric" min="1" step="1" value="1" required disabled>
<h2>4. Review</h2>
<div class="brp-summary" aria-live="polite"></div>
<button class="brp-submit" type="submit" disabled>Reserve bikes</button>
<p>Your selection is checked again before a temporary hold is created. Then continue to checkout.</p>
</div>
</fieldset>
</form>
<div class="brp-status" role="status" aria-live="polite" aria-atomic="true">Loading booking options…</div>
<section class="brp-result" hidden tabindex="-1" aria-label="Temporary reservation">
<h2>5. Checkout</h2><h3>Temporary reservation</h3><div class="brp-receipt"></div>
<p class="brp-expiry" role="status" aria-live="polite"></p>
<button class="brp-checkout" type="button" hidden>Continue to checkout</button>
<button class="brp-restart" type="button" hidden>Start over</button>
</section>
<noscript>Please enable JavaScript to check rental times and reserve bikes.</noscript>
</section>
