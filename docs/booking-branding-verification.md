# Booking form branding — 0.7.1

## Settings and defaults

Open **Bike Rentals > Settings > Booking Form Branding**. These fields use the existing `brp_settings` option (nested `branding` values), existing Settings API form/nonce and the same capability checks. No new settings store, table, schema migration, fonts or page builder. Legacy installations receive display defaults without being rewritten.

| Setting | Default / behavior |
| --- | --- |
| Booking Section Heading | `Choose Your Rental`; retains the existing `1.` step prefix. Plain text, 240-byte maximum. Empty removes the heading and supplies an accessible section label instead. |
| Intro Text | Existing instruction: “Choose a rental below, then select your date, start time, and number of bikes.” Optional; empty omits it. Up to 8000 bytes; supports paragraphs, strong/emphasis, links and line breaks only. |
| Select Rental Button Text | `Select Rental`; blank restores this default. |
| Selected Button Text | `Selected`, with the existing checkmark. Blank restores this default. |
| Change Rental Button Text | `Change Rental`; blank restores this default. |
| Primary Accent Color | Blank retains the existing focus/selection colors. Configured color supplies the focus outline and fallback selected highlight. |
| Primary Button Background Color | Blank retains current button backgrounds: system foreground on card-selection buttons, transparent on other booking actions. |
| Primary Button Text Color | Blank retains system canvas text on selection buttons and inherited text on other booking actions. |
| Selected Package Border / Highlight Color | Blank follows the accent, then the original system foreground fallback. |
| Card Background Color | Blank retains system `Canvas`. |
| Card Border Color | Blank retains system `GrayText`. |
| Border Radius | `Current defaults` retains .75rem cards and .25rem buttons. Square = 0, Slightly Rounded = .25rem, Rounded = .75rem, Very Rounded = 1.5rem, applied to cards/buttons only. |
| Booking Logo / Image | None (attachment ID 0). Choose/remove using the native Media Library. |
| Advanced Custom CSS | Empty; administrator-only restricted CSS rules. |

Labels are plain text, sanitized on save and escaped in HTML/attributes; JavaScript uses textContent for changed labels. Button labels have a 240-byte limit and never become blank. Intro markup uses an explicit WordPress allowlist with safe URL protocols; scripts/event attributes and embedded content are removed. Script/style/iframe/object blocks are removed with their content.

All six colors use native WordPress color pickers. Only blank or 3/6-digit hex is accepted; colors normalize to lowercase six-digit hex. No arbitrary expressions, named colors or 8-digit alpha colors in these settings. Invalid submitted fields retain the previous complete settings, with an admin error. Invalid persisted presentation fields fall back individually on read, without making booking unavailable.

Use **Restore booking branding defaults when saving** to reset only branding, including the logo. Administrators also reset custom CSS; shop managers cannot change or erase administrator CSS, including through forged form submissions or reset. Other settings submitted in the same form still save normally. Scheduling/payment settings are never reset by this checkbox. Submissions omitting the branding section retain existing branding.

## Theme, CSS variables and images

The wrapper receives only validated overrides:

`--brp-accent`, `--brp-button-bg`, `--brp-button-text`, `--brp-selected-border`, `--brp-card-bg`, `--brp-card-border`, `--brp-radius`.

`booking.css` consumes these properties under `.brp-booking`. Blank settings emit no overrides; explicit fallback chains preserve the prior appearance. Font families inherit the current WordPress/Divi theme, including theme heading families and inherited form fonts. Existing relative size/weight hierarchy remains; this release adds no typography scale, family selector, font loading or external font service. Unrelated WooCommerce/Divi buttons, product cards and WordPress admin styles are not changed.

The optional logo renders above the selection heading/intro via `wp_get_attachment_image(..., 'medium')`. Its stored value is a Media Library attachment ID, never an external URL. Existing alt text, width/height and responsive srcset/sizes are retained through an image-attribute allowlist. `.brp-logo` is constrained to its container and at most 300px wide, with proportional height. Deleted/invalid attachments render nothing. An image whose file is missing at the storage provider while attachment metadata remains valid cannot be verified without fetching it; repair that Media Library asset normally.

Deep links continue to show the configured heading/intro/logo above the selected package. Selected colors and labels apply both on initial server rendering and after live package changes. Change Rental and the booking/session/hold/checkout requests are unchanged apart from displayed labels. Multiple rendered instances have independent IDs and CSS scopes, although the supported production booking flow still uses one shortcode on its dedicated page.

## Advanced Custom CSS

Only a user with `manage_options` can change this field. Other branding controls retain the existing `manage_options` / `manage_woocommerce` access policy. The Settings API checks its normal nonce before the sanitizer; the sanitizer separately protects CSS regardless of submitted form contents.

Example:

```css
.brp-booking .brp-promo { color: #334455; padding: .5rem; }
.brp-booking .brp-card:hover { border-color: #334455; }
```

Every comma-separated selector must begin with `.brp-booking`. Supported selectors are descendants/children using tag names/classes and optional hover, focus, focus-visible, active, disabled, first-child or last-child pseudo-classes. The server replaces the root with `#generated-instance-id.brp-booking`. No rule is emitted globally, and unrelated elements sharing inner class names remain unaffected.

Supported properties are color/background-color; border/color/width/style/radius; padding/margin (including individual sides); gap/row-gap/column-gap; width/height/min/max dimensions; font-size/weight/style; line-height/letter-spacing; text-align/decoration/transform; box-shadow; outline/color/width/offset; and opacity. Values can contain basic keywords, units, hex colors and rgb/rgba/hsl/hsla color functions. Comments are accepted. This is intentionally **not a full CSS parser or arbitrary stylesheet editor**: invalid property values may be ignored by the browser.

No at-rules (including media queries/imports), nesting, selector escapes, sibling selectors outside the wrapper, arbitrary pseudo-functions, attribute selectors, URLs, var()/calc(), custom property declarations, position/display rules, HTML, JavaScript or PHP. Unsupported structures/properties/functions are rejected; the full previous settings remain. Maximum 8000 bytes. CSS is revalidated when read and emitted only as a CSS style block targeting its unique wrapper. It is never evaluated as PHP/JavaScript. Larger design changes belong in the site's theme/Divi tooling.

Advanced overrides can still change sizing/spacing, overflow and accessibility inside the booking form. Keep changes small and test them; restore defaults to remove them. No live-preview system is included.

## Accessibility

- Choose button/text colors with sufficient contrast for accessibility. The settings page displays this guidance. Colors are never silently altered and contrast is not automatically guaranteed.
- Visible keyboard focus, checkmarked selected text and aria-pressed remain. Selection is not conveyed by color alone.
- Empty headings do not produce empty elements or dangling aria-labelledby references. No H1 is added by the shortcode.
- Buttons retain 48px minimum targets and wrap long labels. Images retain their Media Library alt text; set useful alt text for informative logos, or leave it empty for decorative images.
- Verify selected/focus states against the configured card/background colors at all widths, including disabled controls.

## Automated verification

`tests/booking-branding.php` uses real WordPress/WooCommerce in the guarded disposable database: defaults, optional text, safe/unsafe intro HTML, labels, hex normalization and rejection, variables, radius presets, responsive attachment rendering/deletion, CSS grammar/scoping/injection rejection, administrator/shop-manager/guest permissions, restore behavior, existing option/nonce structure, native admin dependencies and unchanged scheduling values.

`tests/booking-branding-browser.cjs` uses real PHP-rendered markup and shipped CSS/JS with mocked booking transport: defaults/custom colors, labels after selection changes, theme font inheritance, unrelated-element isolation, custom CSS, logo sizing/alt text, deep links, keyboard operation and 1280/800/375/320px layouts. It also checks the unchanged hold-to-checkout handoff; no real charge occurs.

Use `BRP_BRANDING_FIXTURE_DIR` to export the local PHP fixtures for this browser suite. Screenshots/test logs are ignored Git artifacts and never included in the release ZIP. Native Media Library/color picker controls are enqueued using the existing WordPress APIs; dedicated-site interaction with those controls is still part of manual acceptance.

## Manual test-site acceptance — pending deployment

### Local release results — September 20, 2026

Passed **2,186 automated check executions**, representing **1,928 distinct checks**. This includes all 2,064 regression executions from 0.7.0, rerun against 0.7.1, plus **80 new branding integration checks** and **42 new branding browser checks**. The 258 order-storage-sensitive checks are run in both CPT and HPOS modes; setup-only repeats are excluded. All **50 PHP files** pass syntax validation, both changed JavaScript files pass syntax checks, and desktop/mobile branding screenshots were inspected.

| Coverage | Executions |
| --- | ---: |
| Foundation/packages | 208 |
| Reservation storage/editing, availability, Active rules | 407 |
| Calendar-day duration and weekday matrix | 667 |
| Public booking | 74 |
| Concurrent inventory/payment/cart cleanup | 70 |
| Full Payment checkout, CPT + HPOS | 192 |
| Rental / ordinary Store API checkout, CPT + HPOS | 50 |
| Cart cleanup, CPT + HPOS | 94 |
| Product grid PHP / browser | 68 |
| Missing WooCommerce / persistence | 13 |
| Deep links/calendar PHP, CPT + HPOS | 180 |
| Deep links/calendar browser | 41 |
| New branding PHP / browser | 122 |
| **Total** | **2,186** |

Environment: PHP 8.3.33, WordPress 7.0, WooCommerce 11.1.0, MariaDB 11.4.8 and local Chrome. Browser booking transport and Square evidence are simulated. The existing public-booking fixture emits its known duplicate Woo block-registration notices from repeated initialization; assertions pass. The new branding integration suite has no PHP warnings. Archived Acowebs preflight remains excluded because deposit-provider compatibility is not approved; its guard is unchanged.

Files changed (18):

- Runtime updates: `bike-rental-plugin.php`, `readme.txt`, `src/Plugin.php`, `src/Settings.php`, `src/settings-page.php`, `src/PublicBooking.php` (shortcode presentation only), `src/booking-form.php`, `assets/css/booking.css`, `assets/js/booking.js` (labels only).
- New runtime files: `src/Branding.php`, `src/branding-fields.php`, `assets/js/branding-admin.js`.
- Tests: `tests/foundation.php`; new `tests/booking-branding.php` and `tests/booking-branding-browser.cjs`.
- Documentation: root `README.md`, `CHANGELOG.md`, and this report.

The final diff excludes changes to payment, deposit, scheduling, reservation, availability, database and calendar implementation files. Git work is directly on `main`; no push or deployment is performed. The release ZIP includes only the full inner runtime plugin directory, excluding tests, documentation, screenshots and local dependencies.

Local package: `.release/milestone7a/bike-rental-plugin-0.7.1.zip`. All **39 runtime files** were compared with the ZIP entries. SHA-256: `8958D6849BE3CCAD56C619F1441FCD9C486EB7728C24D84188E9FDEDB49AE091`.

### Manual steps

1. Upload the entire 0.7.1 plugin folder. Without changing branding, verify the ordinary and deep-linked booking pages retain their previous colors/layout and remain usable. Purge page/asset caches if needed; preserve query-aware caching for rental links.
2. In Booking Form Branding, set a custom heading/intro, three button labels, all six colors and a rounded preset. Save and refresh. Check the chosen values, selected highlight, focus and review/checkout buttons. Clear heading/intro and confirm no empty elements.
3. Use Choose image to select a Media Library image with alt text. Verify its responsive public display, then Remove image and save. Delete a selected test attachment and confirm graceful omission.
4. Open `/reserve/?rental=3-day-rental` with a real active slug. Check one branded selected card, custom Change Rental label and normal switching to hourly/7-day packages. Complete the usual date/time/quantity and checkout tests.
5. At desktop/tablet/mobile widths, verify 3/2/1 columns, no logo overflow, readable button contrast, visible keyboard focus and usable selected text. Check with the actual Divi fonts and modules.
6. As an administrator, save the harmless scoped CSS example above. Confirm it applies only inside the shortcode. Try an unsupported global selector/import and confirm a clear error with previous settings retained. Verify shop managers cannot edit/reset administrator CSS.
7. Restore branding defaults, save and refresh. Confirm old appearance is restored, and operational settings, reservations, inventory, payment mode and calendar remain unchanged.

Known limits: no font controls, arbitrary stylesheet support, live preview or automatic contrast correction. Source-page/Divi custom styles may override plugin rules; examine normal CSS precedence when troubleshooting. Actual Divi/admin browser acceptance and the existing Square sandbox acceptance remain pending. No site deployment, external configuration change or real payment is claimed.

Runtime **0.7.1**, schema **1**. Booking/payment/deposit/reservation/availability logic and calendar behavior are unchanged. Waiver integration has not started. Upload only the inner `bike-rental-plugin/` folder to `wp-content/plugins/bike-rental-plugin/`.
