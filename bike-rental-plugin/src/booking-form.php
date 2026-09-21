<?php
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;
?>
<section class="brp-booking" id="<?php echo esc_attr( $uid ); ?>" style="<?php echo esc_attr( Branding::variables( $branding ) ); ?>" data-select-text="<?php echo esc_attr( $branding['select_text'] ); ?>" data-selected-text="<?php echo esc_attr( $branding['selected_text'] ); ?>" data-filtered="<?php echo $preselected ? 'true' : 'false'; ?>" data-preselected="<?php echo esc_attr( $preselected ); ?>" data-selection-source="<?php echo $preselected ? 'url' : 'none'; ?>" data-api="<?php echo esc_url( rest_url( PublicBooking::API . '/' ) ); ?>" aria-label="Bike rental selection">
<?php if ( $branding_css ) : ?><style><?php echo $branding_css; // Validated flat CSS, scoped to this generated ID; HTML/escapes are forbidden. ?></style><?php endif; ?>
<form class="brp-form">
<fieldset><legend>Book your bike rental</legend>
<section <?php echo $branding['heading'] ? 'aria-labelledby="' . esc_attr( $uid ) . '-choose"' : 'aria-label="Rental packages"'; ?>>
<?php echo Branding::logo( $branding['logo_id'] ); // Image attributes allowlisted by the renderer. ?>
<?php if ( $branding['heading'] ) : ?><h2 id="<?php echo esc_attr( $uid ); ?>-choose">1. <?php echo esc_html( $branding['heading'] ); ?></h2><?php endif; ?>
<?php if ( $branding['intro'] ) : ?><div class="brp-intro"><?php echo Branding::intro( $branding['intro'] ); // Restricted safe HTML only. ?></div><?php endif; ?>
<button class="brp-change-rental" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-grid"<?php if ( ! $preselected ) { echo ' hidden'; } ?> disabled><?php echo esc_html( $branding['change_text'] ); ?></button>
<div class="brp-grid" id="<?php echo esc_attr( $uid ); ?>-grid">
<?php foreach ( $cards as $card ) :
	$product = wc_get_product( $card['product_id'] );
	if ( ! $product ) { continue; }
	$heading = $uid . '-package-' . $card['product_id'];
	$selected = $preselected === (int) $card['product_id'];
	$image = wp_get_attachment_image( $product->get_image_id(), 'woocommerce_thumbnail', false, array( 'loading' => 'lazy', 'decoding' => 'async' ) );
	$description = preg_replace( '~<(script|style|iframe|object)\b[^>]*>.*?</\1\s*>~is', '', $product->get_short_description() );
	$description = wp_kses( wpautop( strip_shortcodes( $description ) ), array( 'p' => array(), 'br' => array(), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'ul' => array(), 'ol' => array(), 'li' => array() ) );
?>
<article class="brp-card<?php if ( $selected ) { echo ' brp-selected'; } ?>" data-package-id="<?php echo esc_attr( $card['product_id'] ); ?>" data-package-slug="<?php echo esc_attr( $product->get_slug() ); ?>" aria-labelledby="<?php echo esc_attr( $heading ); ?>"<?php if ( $preselected && ! $selected ) { echo ' hidden'; } ?>>
<div class="brp-card-image"><?php if ( $image ) { echo wp_kses( $image, array( 'img' => array_fill_keys( array( 'src', 'srcset', 'sizes', 'width', 'height', 'alt', 'class', 'loading', 'decoding', 'fetchpriority' ), true ) ) ); } else { ?><span class="brp-image-fallback" role="img" aria-label="No rental photo available">Rental photo coming soon</span><?php } ?></div>
<div class="brp-card-content">
<?php if ( $card['promotional_label'] ) : ?><p class="brp-promo"><?php echo esc_html( $card['promotional_label'] ); ?></p><?php endif; ?>
<h3 id="<?php echo esc_attr( $heading ); ?>"><?php echo esc_html( $card['name'] ); ?></h3>
<?php if ( trim( wp_strip_all_tags( $description ) ) ) : ?><div class="brp-description"><?php echo $description; // Server-rendered short description; restricted formatting allowlist above. ?></div><?php endif; ?>
<p class="brp-card-price"><?php echo wp_kses_post( $product->get_price_html() ); ?> <span class="brp-price-unit">per bike</span></p>
<button class="brp-select" type="button" aria-pressed="<?php echo $selected ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( ( $selected ? $branding['selected_text'] : $branding['select_text'] ) . ': ' . $card['name'] ); ?>" data-package-name="<?php echo esc_attr( $card['name'] ); ?>" disabled><?php echo esc_html( $selected ? '✓ ' . $branding['selected_text'] : $branding['select_text'] ); ?></button>
</div>
</article>
<?php endforeach; ?>
</div>
<p class="brp-empty"<?php if ( $cards ) { echo ' hidden'; } ?>>No rental packages are currently available. Please check back soon or contact us.</p>
</section>
<input name="package_id" type="hidden" value="<?php echo esc_attr( $preselected ?: '' ); ?>">
<div class="brp-details"<?php if ( ! $preselected ) { echo ' hidden'; } ?>>
<h2>2. Choose Date / Time</h2>
<label for="<?php echo esc_attr( $uid ); ?>-date">Start date</label>
<input id="<?php echo esc_attr( $uid ); ?>-date" name="date" type="date" required disabled>
<label for="<?php echo esc_attr( $uid ); ?>-time">Start time</label>
<select id="<?php echo esc_attr( $uid ); ?>-time" name="time" required disabled><option value="">Choose a date first</option></select>
<h2>3. Choose Quantity</h2>
<label for="<?php echo esc_attr( $uid ); ?>-quantity">Number of bikes</label>
<input id="<?php echo esc_attr( $uid ); ?>-quantity" name="quantity" type="number" inputmode="numeric" min="1" step="1" value="1" required disabled>
<h2>4. Rider Information</h2>
<p>Enter one rider per bike. Adults sign their own waiver; a parent or guardian signs for each minor. Waiver invitations are sent after successful payment.</p>
<div class="brp-riders"></div>
<h2>5. Review / Temporary Reservation</h2>
<div class="brp-summary" aria-live="polite"></div>
<button class="brp-submit" type="submit" disabled>Reserve bikes</button>
<p>Your selection is checked again before a temporary hold is created. Then continue to checkout.</p>
</div>
</fieldset>
</form>
<div class="brp-status" role="status" aria-live="polite" aria-atomic="true">Loading booking options…</div>
<section class="brp-result" hidden tabindex="-1" aria-label="Temporary reservation">
<h2>6. Checkout</h2><h3>Temporary reservation</h3><div class="brp-receipt"></div>
<p class="brp-expiry" role="status" aria-live="polite"></p>
<button class="brp-checkout" type="button" hidden>Continue to checkout</button>
<button class="brp-restart" type="button" hidden>Start over</button>
</section>
<noscript>Please enable JavaScript to check rental times and reserve bikes.</noscript>
</section>
