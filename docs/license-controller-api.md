# NT License Controller API v1

Base URL: `https://newbytechnologies.com/wp-json/nt-license/v1`.

| Method/path | Action |
| --- | --- |
| POST `/activate` | Create/reuse the installation if product/status/term/limit/site permit |
| POST `/validate` | Check existing active installation and current entitlement |
| POST `/deactivate` | Mark this installation inactive and free a slot, retaining history |

HTTPS and `Content-Type: application/json` required. License credentials go in the body, never a query string. Requests are limited to 8KB. No cookies or WordPress nonce are required for these remote endpoints; the license key plus installation/site identity authorizes licensing actions, not administration.

Request example (replace placeholders; not a working credential):

```json
{
  "license_key": "<one-time generated NTL1 key>",
  "product_slug": "bike-rental-plugin",
  "installation_id": "<64 lowercase random hex characters>",
  "site_url": "https://rentals.example.com",
  "plugin_version": "0.9.0",
  "wordpress_version": "<installed version>",
  "php_version": "<installed version>"
}
```

Product max 100 characters, versions max 40 characters from letters, numbers, dot, underscore, plus, space and hyphen. Key format and URL normalization are documented in [architecture](licensing-architecture.md). Unknown extra properties are not stored or returned. No rental records are accepted.

HTTP 200 entitlement response:

```json
{
  "valid": true,
  "status": "active",
  "plan_type": "annual",
  "expires_at": "2027-09-21T00:00:00Z",
  "activation_limit": 1,
  "activations_used": 1,
  "controller_timestamp": "2026-09-21T12:00:00Z",
  "product_slug": "bike-rental-plugin",
  "installation_id": "<same installation ID>",
  "site_url": "https://rentals.example.com",
  "code": "license_valid",
  "update_entitlement": true
}
```

All timestamps are UTC ISO 8601 with seconds. Lifetime expiry is null. Unlimited activation limit is 0. Unknown-key/wrong-product replies omit entitlement metadata through null plan/expiry/limit and zero count. They still return the requested normalized identity. No customer name/email, notes, database primary keys, hashes or billing identifiers are exposed.

| Stable code | Meaning |
| --- | --- |
| `license_valid` | Eligible active installation |
| `license_not_found` | Well-formed credential not found |
| `product_mismatch` | Credential does not license the requested product |
| `license_expired` | Term expired or explicitly Expired |
| `license_suspended` / `license_revoked` | Administrator disabled entitlement |
| `activation_limit_reached` | Another installation cannot be activated |
| `installation_not_activated` | Validate requires activation first |
| `site_mismatch` | Active installation belongs to a different normalized URL |
| `installation_deactivated` | Deactivated, including idempotent repeated deactivation |

These codes use HTTP 200 with `valid:false` except `license_valid`. A deactivated response has `status:inactive`; unknown/wrong-product has `status:invalid`. Other denials retain the authoritative license status. Do not infer entitlement from `status:active` alone: `valid` also checks installation/site/limits.

Transport/input failures:

| HTTP | Code | Client treatment |
| --- | --- | --- |
| 400 | `invalid_request` (or WordPress `rest_invalid_json`) | Fix payload; no entitlement granted |
| 403 | `https_required` | Correct HTTPS/proxy setup |
| 429 | `rate_limited` | Retry later; `Retry-After: 3600` |
| 503 | `controller_unavailable` | Temporary storage/lock/server error |

Controller callback errors use `{ "valid": false, "status": "error", "code": "..." }`. WordPress-level parsing/permission errors use WordPress's standard error envelope. Entitlement responses have `Cache-Control: no-store, private`; exclude these POST routes from CDN caching and request-body logging. The client treats any non-200 or malformed response as unavailable, with bounded previously valid grace only.

Default rate limits: 120/IP/hour and 60/key/hour, fixed UTC hour buckets. Duplicate activation with identical installation/site is safe. Deactivation remains available for expired/revoked/suspended licenses. To move an active installation to another URL, first deactivate at the old site or through controller admin. No arbitrary status or database mutation REST endpoint is exposed.
