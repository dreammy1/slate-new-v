# Site Timeclock

Construction-site employee time tracking for Slate.

## What it does

**Employee clock app** — public kiosk page at `/timeclock`:
- Pick yourself from an avatar grid (coloured initials, with a *Clocked In* / *Off* badge for today).
- Clock in: choose a site, set the clock-in time, a live `HH:MM:SS` timer starts.
- Clock out: drag tasks onto 10 hourly slots (1 slot = 1 hour), click a filled slot to clear it, or *Clear All*. A running summary shows assigned hours. **At least one task hour is required before clocking out** (the button is disabled until then). On clock-out a permanent row lands in `timeclock_entries`.
- *Forgot to log yesterday?* opens a pre-filled `mailto:` to the configured owner email.

**Admin** (sidebar → Timeclock):
- **Time Entries** — view/filter (by employee and/or date), add manual/back-dated entries, edit, delete, and export CSV.
- **Employees / Sites / Tasks** — full CRUD. Employees and tasks carry a colour; Sites tab also holds the **owner email** used by the “forgot to log” feature.
- **Reports** — weekly (Mon–Sun) or monthly, computed from a reference date. Stat cards (total hours, sessions, active sites, task hours), per-employee breakdown with colour-coded task chips, daily breakdown with a task bar, and a one-click CSV export for the period.

## CSV format

`Date, Employee, Site, Clock In, Clock Out, Total Hours`, then one column per task type (hours spent). Only completed entries are exported. Filter via `?employee_id=`, `?from=`, `?to=` query params on `admin/export.php`.

## Authentication note

The spec mentioned a password-protected admin area (`admin1234`). Slate already gates admin pages with its own login + role permissions, so this plugin uses the native `Auth::requirePerm()` model instead of a separate hard-coded password — that is the correct, secure approach on this platform (a parallel plugin password would be a downgrade). Access is controlled by two permissions in the Roles editor:

- `timeclock.view` — see entries and reports
- `timeclock.manage` — add/edit/delete entries, employees, sites, tasks

Both are granted to the Manager role by default; Super Admin always has them.

## Tables

`timeclock_employees`, `timeclock_sites`, `timeclock_tasks`, `timeclock_active` (open shifts), `timeclock_entries` (completed). All prefixed and tenant-scoped.
