# shop-emails (Slate plugin)

Sends automated emails for shop orders. Four templates, three triggers,
zero queue.

## Requirements

- Slate core ≥ 1.0.0
- Shop plugin (fires the order hooks we listen on)
- SMTP configured in Slate → Settings → Email (otherwise emails go via
  PHP's `mail()` and most hosts silently drop them or land them in spam)

## What it does

| Trigger | Customer email | Admin email |
|---|---|---|
| Order placed (`shop_order_created`) | "Your order \{N\}" — order details + view-order link | "[Store] New order: \{N\} — \{total\}" — order summary + admin link |
| Order moved to `processing` (Stripe paid OR admin set manually) | "Payment confirmed" | — |
| Order moved to `completed` (admin set manually) | "Your order is complete" | — |

The "payment confirmed" trigger uses `shop_order_status_changed`, so it
fires automatically when Stripe's webhook bumps an order to `processing`
after a successful charge. No extra wiring needed.

## Setup

1. Install via Slate plugin uploader (Admin → Plugins → Upload ZIP).
2. Activate.
3. Grant `shop_emails.manage` to the role that should edit templates
   (or just rely on super-admin access).
4. Visit Admin → Email templates.
5. Set the admin notification recipient (your inbox), then **send a test
   email for each template** from that page. Verify they arrive, look
   right, and aren't in spam. **Do this before going live.**

That's it — once the plugin is active, real emails start firing on
real orders.

## Templates

Four templates, each with editable subject and body:

- `customer_created` — customer's "we got your order" email
- `customer_paid` — customer's "payment received" email
- `customer_completed` — customer's "order complete" email
- `admin_created` — admin's "new order" notification

Each ships with a built-in HTML default. The admin page shows the
default in the editor; saving an empty value resets to default.

### Placeholders

These can appear anywhere in subject or body:

```
{site_name}        {customer_name}        {subtotal}
{shop_url}         {customer_email}       {discount}
{order_number}     {status}               {shipping}
{order_url}        {currency}             {tax}
{admin_order_url}  {coupon_code}          {total}
{created_at}       {items_html}           {shipping_addr_html}
```

`{items_html}` and `{shipping_addr_html}` are pre-rendered HTML
fragments — don't wrap them in `<table>` or anything else, just drop
them in.

Unknown placeholders are left as-is rather than blanked, so if you
typo `{cusotmer_name}` you'll see the literal text in the test email
and can fix it.

## What this plugin deliberately does NOT do

- **No queue / no retry.** If SMTP fails, the email is lost. The order
  itself is fine — there's no transactional dependency. For ~20 orders
  a day this is acceptable; the audit log records every send so you
  can spot the rare failure. At higher volume you'd want a queued
  replacement.
- **No template editor with WYSIWYG.** It's a `<textarea>` of raw
  HTML. Sufficient for shop staff who can copy/paste; not sufficient
  for "non-technical marketing team."
- **No per-customer language detection.** Templates render in whatever
  the admin typed.
- **No tracking pixels, no unsubscribe links.** This is transactional
  email, not marketing — those things have legal implications you don't
  want here. Don't reuse this plugin for newsletter sends.
- **No PDF receipt attachment.** Out of scope.

## Operational notes

- Both customer-received-order and admin-new-order emails fire from the
  SAME hook (`shop_order_created`), within milliseconds of each other.
  Failure of one does not block the other.
- The audit log under `mail.send` records every attempt with the SMTP
  error message. If a customer reports no email, check there first
  before assuming it's a code bug.
- `data/slate.log` gets a line per failed send (`shop-emails: ... send
  failed for order #N to ...`). Tail it during testing.
- The webhook path fires the order-created email AND the payment-paid
  email in quick succession. That's intentional — they say different
  things ("we got your order, pending payment" vs "payment cleared,
  we're processing"). Customers expect this from major e-commerce.

## Uninstalling

Removes the four templates plus the admin recipient setting (via a
`DELETE FROM settings WHERE setting_key LIKE 'shop_email.%'` in
`uninstall.sql`). Your overridden templates are gone — keep a backup
of any you've spent time customising.
