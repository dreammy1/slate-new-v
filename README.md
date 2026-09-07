# Slate — Modular PHP Application Platform

A lean, multi-tenant PHP application shell with a WordPress-style
plugin system. The core ships an admin shell, auth + roles, settings,
a customer portal, an audit log, a hook system, and a public router.
Everything else — e-commerce, bookings, forms, payments — is a plugin
you upload as a ZIP through the admin.

- **Core version:** 1.0.0
- **Tested on:** PHP 8.4, MySQL/MariaDB
- **No build step.** Vanilla PHP, vanilla CSS/JS. PHPMailer is the
  only Composer dependency (vendored).

---

## What's in this package

### Core (v1.0.0)

- ✅ Two-step `install.php` wizard (DB creds → admin account)
- ✅ Premium admin shell — dark sidebar with section labels, light
  canvas, blue accent, mobile bottom tab bar
- ✅ Admin login + dashboard
- ✅ **Card-row data lists** instead of HTML tables everywhere
- ✅ **Full plugin manager** — ZIP upload, activate, deactivate,
  uninstall, with cross-filesystem safe staging; `bin/package-plugin.php`
  CLI packager
- ✅ **Users CRUD** + password reset, with self-protection guards
  (can't demote/suspend/delete yourself or the last Super Admin)
- ✅ **Roles editor** — grouped permission cards; permissions are the
  union of the core list and active-plugin manifests; Super Admin
  (id=1) is read-only
- ✅ **Settings** (tabbed) — General, Branding (accent + logo upload),
  Business details, Email/SMTP (encrypted password + test send),
  Security (session timeout, login throttling, force-HTTPS), System
  (maintenance mode, timezone, version info)
- ✅ **Customer portal** — register, email verification, login,
  logout, forgot/reset password, resend verification, dashboard
  (`customer/`)
- ✅ **Public router** (`PublicRouter`) — plugins register URL
  prefixes (e.g. `/forms/…`, `/shop/…`, `/book`) served through
  `public.php`
- ✅ **Cron entry point** (`cron.php`, secret-protected) for plugin
  scheduled tasks (reminder emails, webhook retries, etc.)
- ✅ Hook system, AuditLog, Mailer (PHPMailer/SMTP), I18n with DB
  language overrides, Uploads helper, encrypted secrets
- ✅ Legacy **core Contact Forms** (`admin/contact_forms.php`) — a
  basic builder kept from the original Stage 1. Superseded by the
  Forms plugin below; see *Known issues*.

### Bundled plugins (`plugins/`)

| Plugin | Slug | Version | Status | What it does |
|---|---|---|---|---|
| Shop | `shop` | 1.2.7 | Stable | Full e-commerce: products w/ variants, gallery, dimensions, categories; public storefront w/ cart + checkout; orders, coupons, customers, reports; CSV import/export |
| Stripe Payment Gateway | `stripe-payment` | 1.2.0 | Stable | Generic Stripe checkout API for any plugin (hosted + embedded Payment Element); webhook handler; Charges admin w/ refunds; test/live toggle |
| Shop email notifications | `shop-emails` | 1.0.0 | Stable | Order confirmation, payment-completed, and admin new-order emails (overridable templates). Needs Shop + SMTP |
| Media library | `media-library` | 1.0.0 | Stable | Browse/upload/reuse images; picker modal for the product editor |
| Flat Rate Shipping (USPS boxes) | `shipping-flat-rate` | 1.0.1 | Stable | Per-box pricing w/ greedy bin-packing; USPS Flat Rate defaults |
| Flat-rate shipping by weight | `flat-rate-shipping` | 1.0.0 | Stable | Cart-weight-tier shipping; integrates w/ Shop free-shipping threshold |
| Forms | `forms` | 0.1.0 | Early | Public forms (`/forms/<slug>` or iframe embed); admin inbox; per-submit webhooks; admin + submitter email |
| Booking | `booking` | 0.5.1 | Beta | Full appointments — services w/ categories, add-ons, custom fields, per-staff pricing, deposits/tax & group price tiers; providers w/ weekly hours, breaks & date overrides; capacity (group), recurring & walk-in bookings; locations + rooms/resources w/ conflict prevention; buffer-before/after, slot interval & advance-window controls; admin month **calendar**; public `/book` widget w/ fast-book, custom fields, file upload & self-service cancel/reschedule; configurable email + SMS/WhatsApp (Twilio) reminders & follow-ups; payments via Stripe (full/deposit), coupons, gift cards, refunds & invoices. See `plugins/booking/README.md`. |
| Hello World | `hello-world` | 1.0.0 | Example | Reference plugin demonstrating every contract point |

> **Two shipping plugins ship in the box** (`shipping-flat-rate`,
> per-box, and `flat-rate-shipping`, per-weight). Activate at most one
> at a time — both register a shipping-rate filter.

---

## Design language

Slate uses a dark-sidebar / light-canvas theme. Off-white canvas
(`#F5F4F1`), white cards on 1px hairline borders (no halo shadow),
near-black sidebar (`#0E1117`), blue accent (`#2563EB`) for primary
actions and active states. Typography is DM Sans (body), Syne
(display), DM Mono (code), from Google Fonts. 12px card radius, 44px
minimum tap targets on mobile, backdrop-blurred topbar.

Sidebar nav items are organised into uppercase section labels
(OVERVIEW, CONTENT, SYSTEM by default) via a `group` field; plugins
register their own groups (e.g. SHOP).

**Data lists use the card-row pattern, not HTML tables.** Each row is
a card (avatar + title + meta + status badge); tapping expands a
labeled key/value grid with optional actions. One row open at a time.
No horizontal scrollbars. Plugins get this free via `slate_data_row()`.

Detail pages use the right-rail layout — `slate_page_layout('with-aside')`
opens a CSS grid with a 320px aside hosting `.aside-card` blocks
(`.kv-list`, `.audit-trail`). All tokens and component classes are
documented in `docs/PLUGIN-API.md` §15 — reuse them rather than adding
parallel CSS.

## Responsive layout

- **Desktop (≥768px):** sidebar left, topbar across, main content, no
  tab bar.
- **Mobile (<768px):** topbar across, full-width content, bottom tab
  bar pinned to the viewport. First 4 nav items in the bar; the rest
  in a slide-up "More" sheet.

Vanilla CSS Grid + a single `@media (min-width: 768px)` breakpoint.

---

## File structure

```
slate/
├── install.php            Two-step install wizard
├── config.php             Bootstrap (loads .env, core includes, boots plugins)
├── public.php / route.php Public-router entry points
├── cron.php               Secret-protected cron runner
├── .env.example           Copy to .env (install.php does this)
├── .htaccess              Apache rewrites + hardening
│
├── admin/                 Admin interface (dashboard, login, plugins,
│                          users, roles, settings, audit, contact_forms…)
│   └── partials/          header.php (shell) + footer.php
├── customer/              Customer portal (register/verify/login/reset)
├── includes/              Core classes: Auth, Database, Hook, AuditLog,
│                          Mailer, I18n, Uploads, Plugin, PluginLoader,
│                          PublicRouter, ui_components, helpers…
├── db/
│   ├── schema.sql         12 core tables
│   └── migrations/        phase1.sql
├── docs/                  PLUGIN-API.md, BUILDING-PLUGINS.md,
│                          CLIENT-ONBOARDING.md  ← read before plugin work
├── lang/en.php            Base translations (DB can override)
├── templates/             Email/page templates
├── uploads/               File uploads (branding, shop, plugin staging)
├── bin/                   package-plugin.php, seed-demo.php, clean-demo.php
├── vendor/                PHPMailer (Composer)
└── plugins/               Bundled plugins (see table above)
    └── _dist/             Pre-built upload-ready plugin ZIPs
```

Core tables (`db/schema.sql`): `tenants`, `roles`, `role_permissions`,
`users`, `customers`, `customer_auth_tokens`, `settings`, `plugins`,
`audit_log`, `contact_forms`, `contact_form_submissions`,
`lang_overrides`. Each plugin creates its own tables on activation
(and self-heals via an `ensureSchema()` pattern).

---

## Deploying

1. Upload the contents to your web root.
2. Visit `/install.php`. Step 1: DB credentials + app URL. Step 2:
   admin name, email, password (8+ chars).
3. Log in. Go to **Plugins** to upload/activate plugins.
4. Round-trip test: download the example plugin from the Plugins page,
   re-upload it, activate it, and watch "Hello World" appear in the
   sidebar.

See `INSTALL.md` for the full walkthrough and `docs/CLIENT-ONBOARDING.md`
for handing a finished site to a client.

## Packaging a plugin

```bash
php bin/package-plugin.php plugins/my-plugin           # → plugins/my-plugin-vX.Y.Z.zip
php bin/package-plugin.php plugins/my-plugin --dist     # → write into plugins/_dist/
```

The packager validates the manifest + SQL before zipping, so a ZIP
that passes is guaranteed to pass installation. Start from
`docs/BUILDING-PLUGINS.md`.

## Requirements

- PHP **8.1+** (developed/tested on **8.4**)
- MySQL/MariaDB 5.7+ (the Forms schema self-heal uses
  `information_schema`; MariaDB 10.2+ / MySQL 5.7+)
- PHP extensions: `pdo_mysql`, `mbstring`, `zip`, `openssl`, `fileinfo`,
  `curl` (Stripe/webhooks), `gd` (image dimensions)
- Apache with `mod_rewrite`, `mod_headers`, `mod_deflate` (the public
  router relies on rewrites); nginx needs equivalent config.

## Browser support

Modern CSS (Grid, custom properties, backdrop-filter). Current Chrome,
Safari, Firefox, Edge. iOS Safari 15+ and Android Chrome 90+ are
first-class. IE11 is not supported.

---

## Known issues & remaining work

**Security hardening pass (latest):**
- **Login throttling is now enforced.** The `max_login_attempts` /
  `lockout_minutes` Security settings were previously stored but never
  read; `Auth` now counts failed attempts per client IP and locks out
  both admin and customer logins (defaults: 10 attempts / 15 min). The
  no-such-user path also runs a dummy `password_verify` to flatten the
  account-enumeration timing oracle.
- **Force-HTTPS and session idle-timeout are now enforced** (they were
  inert settings). `config.php` 301-redirects to HTTPS (and sets HSTS)
  when `force_https` is on; idle sessions past `session_timeout_minutes`
  are dropped. The non-functional "Require 2FA" toggle was removed.
- **Stripe secret + webhook keys are encrypted at rest** (AES-256-GCM)
  via `slate_encrypt_secret()`; legacy plaintext keys are read
  transparently and re-encrypted on next save. (Publishable key stays
  plaintext — it's public.)
- **Stripe completion paths hardened:** the embedded checkout and the
  bank-redirect `return.php` now reconcile the captured amount against
  the rebuilt order total (parking mismatches on-hold), `return.php` is
  bound to the buyer's `shop_sid`, and the charges ledger has unique
  keys on `(tenant_id, payment_intent_id)` / `(tenant_id, session_id)`
  so concurrent webhooks can't double-insert.
- **Booking payment confirmation** (`/book/done`) now verifies the
  Stripe session's `metadata.booking_appt_id` matches the appointment
  before marking it paid (was spoofable with any paid session id).
- **Forms webhooks gained an SSRF guard** — non-http(s) schemes and
  private/loopback/link-local/reserved IPs are refused, and curl is
  pinned to the vetted IP. Public form submissions are now rate-limited
  per IP (≤5/min).
- **Tenant isolation:** shop variant create/edit/delete now verify the
  parent product belongs to the tenant; the `shipping-flat-rate` plugin
  gained a `tenant_id` column + per-tenant scoping on all queries.
- **Open-redirect fixed** on both login pages (`next=//evil.com` is now
  rejected via `slate_safe_redirect_target()`).
- **Booking uploads** (`uploads/booking/`) now get a PHP-off `.htaccess`
  and a real MIME check. `data/`, `db/`, `docs/`, `includes/` each got a
  `Require all denied` `.htaccess` (the root rules don't match under a
  `/subdir/` deployment). SVG logo upload disabled (script-carrying).
- **Concurrency:** booking gift-card debit is now an atomic conditional
  UPDATE and coupon redemption respects `max_uses` atomically.
- **CSV exports** (shop products, form submissions) neutralise formula
  injection; the `migrate-images` CLI tool blocks SSRF to private IPs.
- **Booking schema self-heal:** `gift_applied_cents` and the other 0.5.1
  payment columns are now reconciled even when the version was stamped
  early (`schemaIsCurrent()` checks them; a one-time pass adds them).

**Still open (deliberately deferred):**
- **Customer email verification is not a login gate.** An unverified
  customer can still log in (the dashboard shows a soft banner). Left as
  intentional UX to avoid locking out existing accounts — don't treat
  `email_verified` as an authorization signal in plugins.
- **Storefront checkout has no coupon field.** `ShopAPI::createOrder()`
  supports coupons but the public checkout never collects one, and cart
  vs. order totals are computed by two paths — unify before relying on
  storefront discounts.
- Logout is a GET with no CSRF token (low-impact session-only).

**Recently fixed (prior audit):**
- Flat-rate-shipping triggered a PHP 8.4 implicit-nullable deprecation
  on every request — now `?array $context`.
- Shop coupons editor crashed when creating a new coupon
  (`$editing['expires_at']` on null) — now null-safe.
- Forms threw "Undefined array key 'status'" because
  `CREATE TABLE IF NOT EXISTS` never adds columns to a pre-existing
  table — `FormsAPI::ensureSchema()` now reconciles missing columns.
- **Forms public submissions fatal-crashed** (`Unknown column
  'data_json'`): `ensureSchema()` only reconciled `forms_definitions`,
  not `forms_submissions`. Both tables now reconcile, plus a one-time
  legacy `data` → `data_json` backfill so old submissions still render.
- **Stripe webhook now rejects future-dated timestamps** via
  `abs(time() - $t) > tolerance` (was only rejecting too-old).
- **Shop/Stripe paid amount is now reconciled** against the rebuilt
  order total at webhook/return time. A mismatch (cart drifted between
  intent creation and completion) parks the order on `on-hold` for
  review — logged + audited — instead of silently fulfilling a wrong
  total. Matching charges advance to `processing` as before.
- **Shop order numbers** now derive from the row's auto-increment id
  (collision-safe, never reused after a delete) instead of `COUNT(*)+1`.
- **Booking** gained an admin month **calendar** (`admin/calendar.php`)
  and its READMEs were rewritten to the actual 0.5.1 feature set.

**Open / known:**
- **Two contact-form systems coexist:** the legacy core Contact Forms
  (`admin/contact_forms.php` + `contact_forms` tables) and the newer
  Forms plugin. Pick one; retire the other. *Deferred — removing the
  legacy system drops its tables (data loss) and there's no VCS here,
  so it needs an explicit go-ahead.*
- **Forms** (0.1.0) is early — the field editor is line-syntax, not the
  planned drag-and-drop builder. *Deferred (large feature).*
- **Booking** (0.5.1) is feature-complete for its manifest; remaining
  nice-to-haves: calendar drag-to-reschedule, Google/iCal sync,
  multi-timezone slot computation, waitlist, and membership plans.
- **`.env` contains live credentials** — keep it out of any
  distributable ZIP. (Other stray build/backup/log artifacts have been
  removed from the working tree.)

## License

(To be confirmed.)
