# SiteHub — PortKit fleet control plane (Slate plugin)

Manage many PortKit-equipped WordPress sites from one Slate install.

## What it does
- **Registry** of connected sites (URL + encrypted PortKit token).
- **Health** — ping each site, track online status + PortKit version.
- **Cross-site Site Doctor** — pull each site's scan summary (hardcoded colors, empty links, images missing alt, pages without H1, legacy-section pages) and roll it up into a fleet dashboard.
- **Fleet fixes** — replace a color or normalise corner radius across all online sites at once (each takes a rollback snapshot on the site).
- **Push** a bundle .zip to selected sites; **pull** a site as a backup bundle (stored locally, downloadable).
- Nightly `daily_cron` refresh so the dashboard stays current.

## Requirements
- Each target WordPress site runs **PortKit ≥ 3.3.0** with **Settings → Remote API** enabled and a token.
- Slate core ≥ 1.0.0, PHP cURL extension, HTTPS on the target sites.

## Install
1. `php bin/package-plugin.php plugins/sitehub --dist`
2. Slate admin → Plugins → Upload → activate.
3. Open **SiteHub** (System group), add a site with its PortKit URL + token.

## Security
- Tokens are encrypted at rest with `slate_encrypt_secret()` (AES-256-GCM).
- All site I/O is HTTPS with bearer auth and SSL verification on.
- Every mutating action is permission-gated (`sitehub.manage`) and audit-logged.

## How it talks to sites
SiteHub never touches a remote database. It calls PortKit's REST API:
`/wp-json/portkit/v1/` — `ping`, `pull`, `receive`, and `doctor/scan`,
`doctor/replace-color`, `doctor/set-radius`, `doctor/reclaim-color`.

By Rakib Hasan · rakibhasaan.com
