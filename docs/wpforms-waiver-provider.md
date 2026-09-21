# WPForms provider setup - 0.8.1

The adapter uses WPForms paid entry storage and Signature field display/validation capabilities. WPForms Lite is insufficient. The intended installation is WPForms Elite with the Signature Addon. Actual installed-version signing compatibility must be validated on the dedicated test site before enabling live bookings; local automated tests use explicit WPForms doubles.

## Configure the dedicated form

1. Install/activate licensed WPForms Elite and Signature Addon. Keep **Payment Mode = Full Payment**; no deposit work is needed.
2. Create a dedicated waiver form with entry storage enabled. Use **Message** confirmations, including any conditional confirmations; no redirect confirmations. Disable unrelated notifications/integrations or address them only to authorized test recipients during testing. Do not use a payment field in this form.
3. Add the 15 distinct fields below. Non-hidden fields must be required. Do not use conditional logic on mapped fields. Use a Single Line Text field for signer name, not the composite Name field. Disable Email Confirmation on the signer Email field. Use a single unchecked acceptance choice. Field IDs (including zero) and form ID are configurable, never hard-coded in runtime.
4. In **Bike Rentals > Settings > Waivers**, select WPForms and save to reveal mapping controls. Enter the form ID and corresponding field IDs. Add the business-approved plain-text waiver and a version identifier, such as `2026-09-20-v1`.
5. Publish a normal WordPress page containing **`[bike_rental_waiver]`**, then select it under **Waiver Signing Page**. Do not publish the raw WPForms shortcode. A missing, unpublished, password-protected or shortcode-less page prevents configured/ready status. Configure the Adult and Guardian Invitation Email groups using the listed placeholders.
6. Keep waivers disabled until mappings validate. Select **Require Rider Waivers = Yes** and save once diagnostics show available/configured. A missing/misconfigured provider can be saved for setup, but checkout for waiver-required reservations fails closed with an admin warning.

| Mapping | WPForms field type | Preserved content |
|---|---|---|
| Reservation reference | Hidden | Reservation reference |
| Rider ID | Hidden | Intended rider ID |
| Rider legal name | Hidden | Intended rider name |
| Rider age | Hidden | Age |
| Adult/minor | Hidden | `adult` or `minor` |
| Guardian name | Hidden | Guardian name, blank for adults |
| Guardian relationship | Hidden | Relationship, blank for adults |
| Package reference | Hidden | Product ID and agreed package name |
| Waiver version | Hidden | Frozen legal version |
| Waiver text hash | Hidden | SHA-256 of displayed frozen text |
| Waiver request reference | Hidden | Non-secret waiver record ID |
| Signer full legal name | Single Line Text | Adult's own name or named guardian |
| Signer email | Email | Adult email or guardian email |
| Signature | Signature | Provider-stored signature reference |
| Self/guardian acceptance | Checkboxes | Explicit acceptance of displayed waiver and named signer role |

Suggested checkbox label: “I am the named adult rider signing for myself, or the named parent/guardian signing for this minor. I accept the waiver shown above.” Supply approved business wording; the plugin does not create legal advice or a legal waiver.

The generic signing page displays the exact frozen waiver text, version, intended rider and signer role above the form. Do not put a different legal agreement in the live form. Hidden context and signer fields are filled server-side and compared on submission. The adapter now inspects the actual generated HTML for all 13 expected mapped values and the separate bearer before displaying the form; incompatible/missing population fails closed. Adult guardian fields are explicitly blank; minors retain their own rider identity while signer fields identify the guardian. Editing those values to represent another rider is rejected. One guardian signs each minor separately. Publishing the WPForms shortcode independently is insufficient: the dedicated form requires a current invitation.

## Completion verification

The adapter uses [field properties](https://wpforms.com/developers/wpforms_field_properties/) to prefill context and [process filtering](https://wpforms.com/developers/wpforms_process_filter/) to reject incorrect context before entry storage. The [submit-before hook](https://wpforms.com/developers/wpforms_display_submit_before/) adds the raw bearer token as a separate form control, outside the stored mapped fields. The public request reference is not authorization.

After [process completion](https://wpforms.com/developers/wpforms_process_complete/), the adapter requires a positive entry ID and rereads that entry using the paid entry-storage API. It verifies form ID, every preserved context field, signer identity, legal-text hash, required acceptance and a stored Signature URL reference. It rejects missing entries, mismatches, spam/trash status and absent signatures. Browser `completed=1` flags have no effect. Core completion then consumes the token and checks every rider plus verified payment under the shared database lock.

Message confirmations report whether the rental waiver was actually recorded. Provider evidence stays in WPForms; custom tables contain submission references and frozen legal/context data, never signature images. Back up both systems together. If WPForms saves an entry but the rental update fails, the waiver remains incomplete; retry from the still-valid link or have authorized staff review the saved evidence and record an explicit exemption with a reason. No automatic confirmation is inferred from a provider thank-you page.

## Delivery and privacy

- WordPress `wp_mail` sends each adult their own invitation and each guardian their minor's invitation. Successful mail acceptance is not proof of inbox delivery. Configure and test the site's mail transport.
- Links expire after seven days. Admin **Resend Waiver Email** rotates the secret and enforces a 15-minute interval between attempts, including failed attempts. Completed/exempt requests cannot be resent.
- Exclude `?brp_waiver=...` and `?brp_riders=...` pages from page/CDN caching and analytics/session-recording tools. The pages also send no-cache, no-referrer and no-index headers. Use HTTPS. Avoid logging invitation query strings or request bodies.
- Do not expose WPForms entries/signature storage publicly. WPForms controls its asset storage/access policy; verify it on the actual installation.
- The purchaser uses an authenticated order account or the private Woo order key. Signers need only their individual link and see only their own rider context. Treat invitation links and order keys as secrets.
- Configure retention/access policy with the business. Uninstall and cancellation retain evidence; completed evidence erasure/export and full document-history management remain outside scope. Unlinked unpaid abandoned rider data is cleaned after 30 terminal days; see the architecture guide.

## Required real-site acceptance

Record WordPress, WooCommerce, Square, WPForms and Signature Addon versions. Verify mappings and actual signature value/storage on those versions, including AJAX and ordinary form submissions. Complete all scenarios A-H in [waiver verification](waiver-verification.md), using sandbox payments and authorized test inboxes. No real signature or live charge is claimed by the local doubles.

## Runtime inspection and guarded diagnostics

0.8.1 inspected the official WPForms frontend, Text, Email and processing sources: the general field-properties filter runs after type-specific filters; both Text and Email render `inputs.primary.attr.value`. The adapter populates that property only within its valid invitation rendering context. It verifies the actual provider HTML rather than treating hook execution as proof. Entry verification explicitly uses the provider's internal `cap => false` read after bearer authorization, matching WPForms' own frontend entry-read pattern; no public arbitrary-entry endpoint is added.

The provider processing source stores `page_url`/`url_referer` metadata separately from form fields. The adapter removes `brp_waiver` from those metadata URLs before storage. The before-processing filter captures the separate bearer in request memory and removes it from the raw provider entry and POST form data before storage and downstream entry hooks. The mapped request ID is non-secret. Keep analytics/session recording and server access-body/query logs off this page; no-cache headers cannot override an incorrectly configured upstream cache. Exclude the complete signing-page path, every `brp_waiver` query and purchaser `brp_riders` URL at all cache layers.

For controlled test-site verification, temporarily define `BRP_WAIVER_DIAGNOSTICS` as `true` in the site's configuration. Settings > Waivers shows the latest stages only to authorized rental managers: properties filter execution, per-field rendered-value booleans, separate bearer presence, submitted-context validation, stored-entry reread/signature/acceptance checks, and intended-rider completion. Records contain UTC times and booleans, no token, signature URL, names, emails or legal text. They expire after 24 hours; remove the constant after testing. These observations are verification aids, not a permanent audit log or proof of inbox delivery.

**Signed-in test-site inspection (September 21, 2026):** WordPress 7.1.1, WooCommerce 11.1.1, Square 5.5.0, WPForms 2.0.2 and WPForms Signatures 1.14.0 are installed and active. Bike Rental Plugin is still 0.8.0 with Full Payment selected. Its configured form is **Bike Rental Rider Waiver, ID 58**, with mapped fields 1-11 for context, 12 for signer text, 13 for signer email, 14 for Signature and 15 for acceptance. The builder shows required signer/signature/acceptance fields, Email Confirmation off, AJAX on, entry storage enabled and automatic entry purging off. No settings were changed. The form currently has zero entries.

**Actual 0.8.1 installed-version population, signature storage and completion remain pending deployment.** Read-only form inspection, official source inspection and simulated provider tests are not substitutes for the A-H deployed acceptance matrix. Publish/select the generic signing page after uploading 0.8.1, then compare every mapped rendered and stored field for one adult and one minor in a guest browser, including AJAX and non-AJAX paths. The new runtime HTML check fails closed if the real provider does not render the expected values; use guarded diagnostics to identify the failing field without exposing secrets.
