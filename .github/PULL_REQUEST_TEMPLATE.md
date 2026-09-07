## What changed

<!-- One or two sentences. What does this PR do, and why? -->

## Related

<!-- Issue number, ADR, or docs section. e.g. Closes #12 / docs/14-ADR/00xx-*.md -->

## How it was verified

- [ ] `make test` passes locally (or the failing test is called out below)
- [ ] Checked the affected page(s) in the browser
- [ ] Migrations run clean both ways (`php bin/migrate migrate` / `rollback`)

## Risk checklist

- [ ] No credentials, tokens, or `.env` values are in the diff
- [ ] Every new query is tenant-scoped (`tenant_id`)
- [ ] Money stays in `Money` — no floats
- [ ] New admin routes check `Auth::can()`; customer routes self-gate
- [ ] Plugin assets are cache-busted (`?v=` by mtime)

## Notes for the reviewer

<!-- Anything surprising, deliberately deferred, or worth a closer look. -->
