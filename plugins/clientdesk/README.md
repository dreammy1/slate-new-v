# Client Desk

A client portal for freelance web designers / developers, built as a
Slate plugin. It centralises onboarding, project tracking, quotes,
billing, file delivery, team coordination and support for both direct
and Fiverr clients.

## What's new in 2.0

- **Analytics overview** — KPI tiles, a 6-month revenue chart, and a
  project-phase breakdown, plus a "needs attention" rail.
- **Quotes / proposals** — send a quote the client approves or declines
  online; approved quotes convert to an invoice in one click.
- **Online payments** — when the Stripe Payment plugin is configured,
  clients pay invoices by card from their portal; the webhook marks the
  invoice paid automatically.
- **Files & deliverables** — upload assets and final files per project,
  toggle client visibility, and let clients upload their own assets.
- **Project comments** — a two-way thread separate from the activity feed.
- **Project templates** — apply a preset milestone set (ships with a
  "Standard website build") to a project in one click.
- **Automations** — a daily job flags overdue invoices, expires stale
  quotes, and sends deadline reminders via in-app notifications.
- **Tabbed project page**, phase stepper, progress rings, charts, and a
  search box + tags on the client directory.

## Features → where they live

| Capability | Location |
|---|---|
| Onboarding questionnaire / input guide | Project page → *Overview*; clients fill it from their portal |
| Progress dashboard | Customer portal: progress ring, phase stepper, milestones, activity, deliverables |
| Quotes the client approves online | Admin *Quotes*; client approves in portal |
| Invoices + agreement + brief attached | Admin *Invoices*; brief auto-built from the questionnaire |
| Card payments | Portal *Pay now* (needs Stripe Payment plugin) |
| File delivery + client uploads | Project page → *Files*; portal upload + downloads |
| Team assignment | Project page → *Team* |
| Live support tickets | Portal *Support*; staff reply in admin *Support* |
| Client directory (direct vs Fiverr) | Admin *Clients*, with source tag, tags + search |
| Custom per-client login link | `/portal/<token>` per client |
| Role-based permissions | 7 permissions in Slate's Roles editor |

## Permissions

- `clientdesk.view` — directory + projects
- `clientdesk.manage_clients` — clients + access links
- `clientdesk.manage_projects` — projects, progress, files, comments
- `clientdesk.manage_quotes` — quotes / proposals
- `clientdesk.manage_invoices` — invoices + payments
- `clientdesk.manage_team` — assign team members
- `clientdesk.handle_support` — answer tickets

The Manager role gets all seven by default on install.

## Upgrade notes (1.x → 2.0)

Uploading the 2.0 ZIP over a 1.x install keeps your data. On first boot
the plugin runs its migrations: it creates the new tables (quotes,
files, comments, templates) and adds the new columns (`clients.tags`,
`invoices.payment_ref`, `invoices.payment_method`) idempotently. No
manual SQL required.

## Payments

Online payments are optional. With the **stripe-payment** plugin active
and configured, the portal shows a *Pay now* button on unpaid invoices,
opens Stripe Checkout, and the shared webhook marks the invoice paid
(method + reference recorded). Without Stripe, invoices still work as
manual/marked-paid.

## Data

All tables are prefixed `clientdesk_` and are dropped on uninstall.
Uploaded files live under `/uploads/clientdesk/<project>/`. Portal
customer accounts live in the core `customers` table and are **not**
deleted on uninstall.
