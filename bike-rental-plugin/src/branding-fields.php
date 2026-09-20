<?php
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;
$branding = Branding::get();
?>
<section class="brp-branding-settings" aria-labelledby="brp-branding-title">
<h2 id="brp-branding-title">Booking Form Branding</h2>
<p>Save changes and refresh the booking page to preview. Typography inherits your active WordPress/Divi theme; no fonts are loaded.</p>
<h3>Content</h3>
<table class="form-table" role="presentation">
<?php foreach ( Branding::texts() as $key => $label ) : ?>
<tr><th scope="row"><label for="brp-brand-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
<td><input class="regular-text" id="brp-brand-<?php echo esc_attr( $key ); ?>" name="brp_settings[branding][<?php echo esc_attr( $key ); ?>]" type="text" maxlength="240" value="<?php echo esc_attr( $branding[ $key ] ); ?>"><p class="description"><?php echo 'heading' === $key ? 'Plain text. Leave blank to hide the selection heading.' : 'Plain text. Leave blank to restore this label’s default.'; ?></p></td></tr>
<?php endforeach; ?>
<tr><th scope="row"><label for="brp-brand-intro">Intro Text</label></th><td><textarea class="large-text" rows="4" id="brp-brand-intro" name="brp_settings[branding][intro]" maxlength="8000"><?php echo esc_textarea( $branding['intro'] ); ?></textarea><p class="description">Optional. Supports paragraphs, strong/emphasis, links and line breaks. Other HTML is removed.</p></td></tr>
<tr><th scope="row">Booking Logo / Image</th><td>
<input type="hidden" id="brp-brand-logo" name="brp_settings[branding][logo_id]" value="<?php echo (int) $branding['logo_id']; ?>">
<div class="brp-logo-preview"><?php echo Branding::logo( $branding['logo_id'] ); // Image attributes allowlisted by the renderer. ?></div>
<button class="button brp-choose-logo" type="button">Choose image</button> <button class="button brp-remove-logo" type="button">Remove image</button>
<p class="description">Select a Media Library image. Its existing alternative text is used. To remove a missing image, click Remove image and save.</p>
</td></tr>
</table>
<h3>Colors</h3><p>Choose button/text colors with sufficient contrast for accessibility. Colors are not automatically adjusted. Leave blank to use the current defaults; a blank selected border follows the accent color.</p>
<table class="form-table" role="presentation">
<?php foreach ( Branding::colors() as $key => $label ) : ?>
<tr><th scope="row"><label for="brp-brand-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input class="brp-color" type="text" id="brp-brand-<?php echo esc_attr( $key ); ?>" name="brp_settings[branding][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $branding[ $key ] ); ?>" placeholder="#336699" maxlength="7"></td></tr>
<?php endforeach; ?></table>
<h3>Style</h3><table class="form-table" role="presentation">
<tr><th scope="row"><label for="brp-brand-radius">Border Radius</label></th><td><select id="brp-brand-radius" name="brp_settings[branding][radius]">
<?php foreach ( Branding::radii() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $branding['radius'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
</select><p class="description">Current defaults retain existing card and button rounding. Other presets apply to both.</p></td></tr>
<tr><th scope="row"><label for="brp-brand-css">Advanced Custom CSS</label></th><td>
<?php if ( current_user_can( 'manage_options' ) ) : ?>
<textarea class="large-text code" rows="6" id="brp-brand-css" name="brp_settings[branding][custom_css]" maxlength="8000" spellcheck="false"><?php echo esc_textarea( $branding['custom_css'] ); ?></textarea>
<p class="description">Administrators only. Use flat rules starting with <code>.brp-booking</code>, for example <code>.brp-booking .brp-promo { color: #333333; }</code>. Rules are confined to each booking instance. Supports basic colors, borders, spacing, dimensions and text styling; no at-rules, nesting, external URLs, scripts, positioning or global selectors. Invalid CSS is rejected. Advanced overrides can affect layout and accessibility.</p>
<?php else : ?><p id="brp-brand-css">Only administrators can change Advanced Custom CSS. Existing CSS is retained when you save.</p><?php endif; ?>
</td></tr></table>
<p><label><input type="checkbox" name="brp_settings[branding][restore_defaults]" value="1"> Restore booking branding defaults when saving</label></p>
<p class="description">Resets only branding, including logo and (for administrators) Custom CSS. Rental scheduling, payment mode, reservations and inventory are not reset.</p>
</section>
