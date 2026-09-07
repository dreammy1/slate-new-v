# React Site Bridge

## Guided site-package import

Version 0.3 introduces the fastest setup path: upload a single **React Site Package** ZIP from **React Sites**. The importer creates the tenant-scoped site, imports editable route documents, streams validated files into the shared Slate Media Library, creates logical media mappings, and publishes the initial manifest when the administrator leaves the publish option selected.

The ZIP must contain this top-level structure:

```text
site-package.json
media/
  kaimana-logo.png
  project-photo.jpg
frontend/
  README.md
```

`site-package.json` uses `package_type: "react-site-bridge-package"` and schema version `1`. It contains a `site` object, a `documents` list, and a `media` list. Each media item names a `key`, a safe `media/<filename>` path, accessible alt text, and optional focal coordinates. Package imports are tenant-scoped, never extract archive paths to disk, enforce an allow-list of Slate Media types, limit individual files to 10 MB and package media to 45 MB, and remain unpublished if the import fails.

If a site with the package key already exists, administrators can either open that site to manage it, delete it, or explicitly select **Replace an existing site with the same key** during import. Replacement retains the opaque public identifier but deliberately removes bridge route documents, media mappings, and manifest revisions before importing the new package. Media Library files remain available for deliberate cleanup. The site list now includes **Open / manage**, **View manifest**, and **Preview frontend** controls. A rendered preview becomes available after a deployed React preview or published URL is saved in the site settings; before then, the manifest view verifies published content.

> The package includes frontend handoff material for the deployment team, but it does not execute JavaScript or PHP from the archive. The React build continues to be deployed as static files; Slate supplies its editable manifest and media URLs.

React Site Bridge connects a static React frontend to Slate-managed, tenant-scoped content and media. It is deliberately a **headless content bridge**, not a React compiler or a second form, booking, membership, or customer-authentication system.

## Install

Package this plugin from the Slate root with `php bin/package-plugin.php plugins/react-site-bridge`, then upload and activate the resulting ZIP using **Admin → Plugins**. The plugin adds a **React Sites** item to the Content navigation group.

Create a site record, add one JSON document for each public React route, map logical media keys to existing Media Library assets, and publish a manifest revision. The public endpoint is:

```text
GET /site-data/<public-id>/manifest
```

Only a published immutable revision is exposed from this endpoint. CORS is allowed only for the optional published and preview origins configured in the site record.

## React usage

Set two public build variables in the React project:

```text
VITE_SITE_BRIDGE_URL=https://admin.example.com/slate
VITE_SITE_BRIDGE_ID=<public-id shown in Slate admin>
```

Then use a minimal client such as:

```ts
export async function getSiteManifest() {
  const base = import.meta.env.VITE_SITE_BRIDGE_URL.replace(/\/$/, '');
  const id = import.meta.env.VITE_SITE_BRIDGE_ID;
  const response = await fetch(`${base}/site-data/${id}/manifest`);
  if (!response.ok) throw new Error('Site content is unavailable.');
  return response.json();
}
```

The React application should keep presentation, routing, animations, and component behavior in source control. It should use the returned `documents` and `media` maps for editable data. A regular admin can then update copy, project selections, images, links, and SEO data without rebuilding React; a developer rebuilds only for a visual or functional release.

## Existing Slate integrations

Media mappings use the core `Media` service, so the same tenant-scoped Media Library remains authoritative. Forms, Booking, Membership, the customer portal, and Content Builder should be referenced by their existing public IDs/slugs in the route document, not reimplemented here. Other plugins can subscribe to `react_site_bridge_published` to clear caches, trigger deployment webhooks, or log release activity.

## Content editor

Version 0.5.4 adds a **Content editor** link on every React Site management screen. It presents the existing text fields of each route document as labeled inputs, keeps protected component structure intact, and offers **Save draft** and **Save & publish** actions. The original JSON document editor remains available for developers who need to manage document schema or non-text values.

## Live visual editor

Version 0.5.7 adds a **Visual editor** link for direct Slate-hosted React releases. It provides a same-origin live canvas, desktop/tablet/mobile modes, click-to-select outlines, and a focused inspector for marked text, color, typography, border, and spacing properties. It stores only constrained visual overrides in a tenant-scoped React Site Bridge document and retains the existing CSRF, audit-log, draft, permission, immutable-revision, and publish model.

The visual editor is not an arbitrary CSS or source-code editor. The connected React site must mark elements as editable, and the editor does not allow scripts, raw HTML, URL changes, navigation edits, or unapproved component changes.

## Initial limitations

This release includes native site/document/media management, visual editing of existing text fields, immutable publishing, JSON manifest delivery, origin-controlled CORS, guided ZIP imports with recovery controls, seed export, and audit events. Future releases can add revision compare/restore, signed private preview manifests, webhook deployment, and a maintained npm SDK.
