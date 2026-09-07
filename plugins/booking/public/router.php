<?php
/**
 * Booking — public-facing widget.
 *
 * URL: /book   (and /book/<service-slug> for fast-booking a service)
 * Multi-step flow, server-side state via query params:
 *   step 1: pick service        step 2: pick provider
 *   step 3: pick date + slot     step 4: contact + extras + confirm
 *   step 5: success
 *
 * ?embed=1 strips the outer canvas + footer (for iframes).
 * Supports group size, add-ons, custom fields (incl. file upload) and
 * weekly recurring bookings.
 */

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__, 3) . '/config.php';
}
slate_public_entry('booking');
require_once dirname(__DIR__) . '/BookingAPI.php';
BookingAPI::ensureSchema();

// The booking widget is fully dynamic (per-booking summaries, payment forms,
// CSRF tokens). It must never be cached by the browser or the CDN/proxy in
// front of the site — a cached payment page would mount a stale Stripe form
// bound to an old PaymentIntent. Force no-store on every widget response.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$embed     = !empty($_GET['embed']);
$serviceId = (int)($_GET['service']  ?? 0);
$providerId= (int)($_GET['provider'] ?? 0);
$date      = (string)($_GET['date']  ?? '');
$slot      = (string)($_GET['slot']  ?? '');
$party     = max(1, (int)($_GET['party'] ?? 1));

// Self-service: /book/manage?token=… (cancel / reschedule own booking).
$routePath = trim((string)($_GET['_route_path'] ?? ''), '/');
if ($routePath === 'manage') {
    bookpub_manage($embed);
    return;
}

// Stripe return: /book/pay/done?token=…&session_id=… (post-checkout).
if ($routePath === 'pay/done' || $routePath === 'pay') {
    bookpub_pay_done($embed);
    return;
}

// Fast-book: /book/<service-slug> preselects the service.
if ($routePath !== '' && $serviceId === 0) {
    $bySlug = BookingAPI::getService($routePath);
    if ($bySlug) $serviceId = (int)$bySlug['id'];
}

// ── POST: confirm submission ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        bookpub_render_error('Security check failed. Please try again.', $embed);
        return;
    }
    // One-time submission token: a refresh, back-button re-POST, or fast
    // double-click would otherwise create a second appointment.
    if (!submit_token_consume()) {
        bookpub_render_error('This booking was already submitted. Check your email for the confirmation, or start a new booking.', $embed);
        return;
    }
    $postService  = (int)($_POST['service']  ?? 0);
    $postProvider = (int)($_POST['provider'] ?? 0);
    $postDate     = (string)($_POST['date'] ?? '');
    $postSlot     = (string)($_POST['slot'] ?? '');
    $partyPost    = max(1, (int)($_POST['party'] ?? 1));
    $svc          = BookingAPI::getService($postService);

    // Multi-slot booking: a JSON array of "Y-m-d H:i" strings the customer
    // built on step 3. Re-validated here regardless of what the client
    // sent — count, format, and the admin's configured max are all
    // enforced server-side (see bookpub_parse_multi_selections()).
    $multiStarts = [];
    if (bookpub_book_setting('multislot_enabled', false) && !empty($_POST['multi_slots'])) {
        $multiStarts = bookpub_parse_multi_selections((string)$_POST['multi_slots'], bookpub_multislot_max());
    }
    $isMultiBatch = count($multiStarts) >= 2;
    // Exactly one multi-select pick behaves like an ordinary single booking
    // — fold it into postDate/postSlot so the rest of this handler doesn't
    // need a third code path for it.
    if (count($multiStarts) === 1 && $postDate === '' && $postSlot === '') {
        [$postDate, $postSlot] = array_pad(explode(' ', $multiStarts[0]), 2, '');
    }

    // Collect custom-field values (+ store any uploads).
    [$custom, $customErr] = $svc ? bookpub_collect_custom((int)$svc['id']) : [[], null];

    $addonIds = array_map('intval', (array)($_POST['addons'] ?? []));

    $baseArgs = [
        'service_id'    => $postService,
        'provider_id'   => $postProvider,
        'customer_name' => trim((string)($_POST['customer_name']  ?? '')),
        'customer_email'=> trim((string)($_POST['customer_email'] ?? '')),
        'customer_phone'=> trim((string)($_POST['customer_phone'] ?? '')),
        'notes'         => trim((string)($_POST['notes'] ?? '')),
        'customer_id'   => Auth::customerId(),
        'party_size'    => $partyPost,
        'addons'        => $addonIds,
        'custom'        => $custom,
        // Promo / gift fields are honoured only when their booking-setting
        // toggle is on, so a disabled field can't be re-enabled by hand.
        'coupon_code'   => bookpub_book_setting('show_coupon')   ? trim((string)($_POST['coupon_code'] ?? ''))    : '',
        'gift_card_code'=> bookpub_book_setting('show_gift_card') ? trim((string)($_POST['gift_card_code'] ?? '')) : '',
        'source'        => 'online',
    ];

    if ($customErr !== null) {
        bookpub_render_step4_with_error($baseArgs + ['starts_at' => $postDate . ' ' . $postSlot], $customErr, $embed, $isMultiBatch ? $multiStarts : []);
        return;
    }

    if ($isMultiBatch) {
        // Multi-slot batch: one appointment per selected date/time, sharing
        // a group id — the same "recurrence_group" column weekly-repeat
        // already uses for exactly this ("a batch of appointments created
        // together in one submission"), just with arbitrary, not weekly,
        // spacing.
        $group = bin2hex(random_bytes(6));
        $firstResult = null; $booked = 0; $failed = 0; $failedTimes = [];
        foreach ($multiStarts as $startStr) {
            $args = $baseArgs;
            $args['starts_at']        = $startStr;
            $args['recurrence_group'] = $group;
            $res = BookingAPI::createAppointment($args);
            // Unlike weekly-repeat (whose "first" occurrence is today, so a
            // race there is rare), multi-select's picks are independent
            // times a customer chose across possibly several page views —
            // the specific one that happened to be raced away by another
            // customer is not necessarily first in the list. Drive the
            // success/payment/redirect path off the first one that actually
            // succeeded, not just the first attempted, so one unlucky pick
            // doesn't report the whole batch as failed when others booked.
            if (!empty($res['ok'])) {
                $booked++;
                if ($firstResult === null) $firstResult = $res;
            } else {
                $failed++; $failedTimes[] = $startStr;
            }
        }
        $recurCount = count($multiStarts);
    } else {
        // Recurrence — optional weekly repeat (only when the toggle is on).
        $recur      = bookpub_book_setting('show_repeat') && ($_POST['recur'] ?? 'none') === 'weekly';
        $recurCount = $recur ? max(1, min(12, (int)($_POST['recur_count'] ?? 1))) : 1;
        $group      = $recurCount > 1 ? bin2hex(random_bytes(6)) : null;

        $firstResult = null;
        $booked = 0; $failed = 0; $failedTimes = [];
        for ($i = 0; $i < $recurCount; $i++) {
            $startTs = strtotime($postDate . ' ' . $postSlot . ' +' . ($i * 7) . ' days');
            if ($startTs === false) { $failed++; continue; }
            $args = $baseArgs;
            $args['starts_at']        = date('Y-m-d H:i', $startTs);
            $args['recurrence_group'] = $group;
            $res = BookingAPI::createAppointment($args);
            if ($i === 0) $firstResult = $res;
            if (!empty($res['ok'])) $booked++; else $failed++;
        }
    }

    if ($firstResult && !empty($firstResult['ok'])) {
        // Paid services with a balance owing go to Stripe to settle it.
        // (Multi-slot batches: only the first selected time is carried
        // through checkout here — see the notice shown on step 4. The
        // others were each independently evaluated by createAppointment()
        // and, if they also need payment, sit pending with their own
        // manage-link the customer can pay from — same characteristic the
        // weekly-repeat option already has.)
        if (!empty($firstResult['needs_payment']) && !empty($firstResult['id'])) {
            $appt = Database::row("SELECT * FROM booking_appointments WHERE id = ?", [(int)$firstResult['id']]);
            if ($appt) {
                $stripeReady = class_exists('StripePaymentAPI') && StripePaymentAPI::isConfigured();
                $embeddedOn  = (Database::setting('stripe-payment.booking_embedded') ?? '1') !== '0';

                // Preferred path: collect payment inline on a summary step
                // inside the widget — the customer never leaves the page.
                if ($stripeReady && $embeddedOn) {
                    bookpub_render_payment($appt, $embed);
                    return;
                }
                // Fallback: hosted Stripe Checkout (redirect off-site).
                $pay = BookingAPI::startPayment($appt);
                if (!empty($pay['ok'])) { header('Location: ' . $pay['url']); exit; }
            }
        }
        bookpub_render_success($firstResult['ref'], $baseArgs, $embed, $firstResult['status'] ?? 'confirmed', $booked, $recurCount, $isMultiBatch, $failedTimes);
        return;
    }
    // Extension point: a gate (e.g. Booking+'s HSR-prereq gate) can attach
    // a redirect URL when the natural next step is to send the client
    // somewhere else (e.g. to book a required prior service).
    if (!empty($firstResult['redirect_to']) && is_string($firstResult['redirect_to'])) {
        header('Location: ' . $firstResult['redirect_to']);
        exit;
    }
    $err = $firstResult['error'] ?? 'Sorry, that booking could not be completed.';
    bookpub_render_step4_with_error($baseArgs + ['starts_at' => $postDate . ' ' . $postSlot], $err, $embed, $isMultiBatch ? $multiStarts : []);
    return;
}

// ── GET routing by what's in the query ───────────────────────
if ($serviceId === 0) {
    bookpub_render_step1($embed);
    return;
}
$service = BookingAPI::getService($serviceId);
if (!$service || empty($service['is_active'])) {
    bookpub_render_error('That service is no longer available.', $embed);
    return;
}

if ($providerId === 0) {
    bookpub_render_step2($service, $embed);
    return;
}
$provider = Database::row("SELECT * FROM booking_providers WHERE id = ? AND tenant_id = ? AND is_active = 1",
    [$providerId, current_tenant_id()]);
if (!$provider) {
    bookpub_render_error('That provider isn\'t available.', $embed);
    return;
}

// Multi-slot booking: the customer built a list of several date/time picks
// on step 3 (only reachable when the admin has turned this on — see
// bookpub_book_setting('multislot_enabled')) and hit "Continue". "sel" is
// carried as a JSON array of "Y-m-d H:i" strings; re-validated here rather
// than trusted from the query string.
if (!empty($_GET['multi']) && !empty($_GET['sel'])) {
    $multiMax = bookpub_multislot_max();
    $selections = bookpub_parse_multi_selections((string)$_GET['sel'], $multiMax);
    if (count($selections) === 1) {
        // Exactly one pick behaves like an ordinary single booking — same
        // fold the POST handler does — so the confirm page shown here
        // matches what actually gets submitted rather than showing a
        // one-item "batch" summary for something that isn't one.
        [$soloDate, $soloSlot] = array_pad(explode(' ', $selections[0]), 2, '');
        bookpub_render_step4($service, $provider, $soloDate, $soloSlot, $party, [], '', $embed);
        return;
    }
    if (count($selections) >= 2) {
        bookpub_render_step4($service, $provider, '', '', $party, [], '', $embed, $selections);
        return;
    }
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $slot === '') {
    bookpub_render_step3($service, $provider, $date, $party, $embed);
    return;
}

// service + provider + date + slot — show confirm form.
bookpub_render_step4($service, $provider, $date, $slot, $party, [], '', $embed);


// ─────────────────────────────────────────────────────────────
//                      Helpers
// ─────────────────────────────────────────────────────────────

/** Build a querystring suffix, dropping empty values. */
function bookpub_qs(array $params, bool $embed): string {
    if ($embed) $params['embed'] = 1;
    $parts = [];
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) continue;
        $parts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    return '?' . implode('&', $parts);
}

/**
 * Absolute URL back to the booking widget start.
 *
 * The "Book something else" / "Start over" links used a bare `?` (the
 * relative result of bookpub_qs([])). From a sub-route like
 * /book/pay/done that resolves to /book/pay/done? — re-entering the
 * pay-done handler with no token ("Invalid payment link"), or 500ing.
 * Anchoring to the absolute /book root sends the customer back to step 1.
 */
function bookpub_home(bool $embed): string {
    return SLATE_URL . '/book' . ($embed ? '?embed=1' : '');
}

/**
 * Read a boolean booking setting (default ON). A stored '0' disables;
 * anything else (including unset) is treated as enabled.
 */
function bookpub_book_setting(string $key, bool $default = true): bool {
    $v = Database::setting('booking.' . $key);
    if ($v === null || $v === '') return $default;
    return $v !== '0';
}

/** Admin-configured cap on how many times one multi-slot booking can hold. */
function bookpub_multislot_max(): int {
    return max(2, min(20, (int)(Database::setting('booking.multislot_max') ?? 4)));
}

/**
 * Parse + validate a multi-slot selection list (JSON array of "Y-m-d H:i"
 * strings) from user input. De-dupes, drops malformed entries, sorts
 * chronologically, and caps at $max — never trust the count or shape the
 * client sent, whether it came from the GET "sel" carry-forward or the
 * POST "multi_slots" hidden field.
 */
function bookpub_parse_multi_selections(string $raw, int $max): array {
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $v) {
        // The client keeps each pick as {value, label} (label is a display
        // convenience, rebuilt from the visible slot text, not trusted for
        // anything) so a chip can show its own date+time after the day on
        // screen has moved on — but the "sel" hidden field is round-tripped
        // through both a GET carry-forward and the final POST, so this must
        // accept that shape as well as a bare string.
        if (is_array($v)) {
            $v = isset($v['value']) ? (string)$v['value'] : '';
        } else {
            $v = (string)$v;
        }
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) $out[$v] = true;
    }
    $out = array_keys($out);
    sort($out);
    return array_slice($out, 0, max(1, $max));
}

/**
 * Collect custom-field values for a service from the request, storing any
 * file uploads. Returns [values, error|null].
 */
function bookpub_collect_custom(int $serviceId): array {
    $values = [];
    foreach (BookingAPI::getCustomFields($serviceId) as $f) {
        $name = $f['name'];
        if ($f['type'] === 'file') {
            $stored = bookpub_store_upload('custom_file_' . $name);
            if ($stored !== null) $values[$name] = $stored;
            elseif ((int)$f['is_required'] === 1) return [$values, 'Please attach: ' . ($f['label'] ?? $name)];
        } elseif ($f['type'] === 'checkbox') {
            $values[$name] = !empty($_POST['custom'][$name]) ? 'Yes' : '';
        } else {
            $v = $_POST['custom'][$name] ?? '';
            $values[$name] = is_array($v) ? '' : trim((string)$v);
        }
    }
    return [$values, null];
}

/** Securely store an uploaded file under uploads/booking/. Returns rel path or null. */
function bookpub_store_upload(string $inputName): ?string {
    if (empty($_FILES[$inputName]) || ($_FILES[$inputName]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $f = $_FILES[$inputName];
    if ($f['size'] <= 0 || $f['size'] > 8 * 1024 * 1024) return null; // 8 MB cap
    $allowed = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','txt'];
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return null;

    // Validate the real content type, not just the client-supplied
    // extension, so an attacker can't smuggle an executable disguised
    // with an allowed extension.
    $allowedMimes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',  // .docx is a zip container; some libmagic builds report this
        'application/octet-stream', // some servers report .doc/.docx generically
    ];
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($f['tmp_name']);
        if ($mime !== '' && !in_array($mime, $allowedMimes, true)) return null;
    }

    $dir = SLATE_ROOT . '/uploads/booking';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;

    // Defence in depth: disable PHP execution in the upload dir (matches
    // the branding/shop upload dirs). Cheap idempotent write.
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess,
            "<IfModule mod_php.c>\n" .
            "    php_flag engine off\n" .
            "</IfModule>\n" .
            "<IfModule mod_php7.c>\n" .
            "    php_flag engine off\n" .
            "</IfModule>\n" .
            "AddHandler default-handler .php .phtml .php3 .php4 .php5 .php7 .phps\n");
    }

    $rel = 'uploads/booking/' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], SLATE_ROOT . '/' . $rel)) return null;
    return $rel;
}

// ─────────────────────────────────────────────────────────────
//                      Renderers
// ─────────────────────────────────────────────────────────────

function bookpub_layout_start(string $title, bool $embed): void {
    $siteName = Database::setting('site_name') ?: 'Slate';
    slate_ui_emit_css();
    // Without this the tenant's brand colour never overrides core's default
    // --accent, so every `var(--accent)` in the widget rendered #2563EB blue
    // instead of the brand colour. Must follow slate_ui_emit_css().
    slate_brand_accent_emit();
    ?>
    <!DOCTYPE html><html lang="<?= e(I18n::currentLocale()) ?>"><head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title><?= e($title) ?> — <?= e($siteName) ?></title>
        <meta name="csrf" content="<?= e(csrf_token()) ?>">
        <?php
        // Inline the widget stylesheet instead of linking it. The booking
        // widget is dynamic (per-date GET/POST, embedded in third-party
        // iframes) and was being served behind a CDN/proxy that cached the
        // external public.css by path and ignored the ?v= query string —
        // so styling for newer components (calendar, promo, etc.) never
        // updated. Inlining ties the CSS to the (uncacheable) HTML so it is
        // always in sync. Falls back to a <link> if the file can't be read.
        $bookCss = @file_get_contents(plugin_dir('booking', 'assets/css/public.css'));
        if ($bookCss !== false && $bookCss !== '') {
            echo "<style>\n" . $bookCss . "\n</style>";
        } else {
            $bookCssVer = @filemtime(plugin_dir('booking', 'assets/css/public.css')) ?: time();
            echo '<link rel="stylesheet" href="' . e(plugin_url('booking', 'assets/css/public.css'))
               . '?v=' . $bookCssVer . '">';
        }
        ?>
    </head><body class="book-public<?= $embed ? ' book-public-embed' : '' ?>">
    <main class="book-shell">
        <article class="book-card">
    <?php
}
function bookpub_layout_end(bool $embed): void {
    echo '</article></main>';
    if (!$embed) {
        echo '<footer class="book-footer text-sm text-muted text-center">Powered by '
           . e(Database::setting('site_name') ?: 'Slate') . '</footer>';
    } else {
        // Embedded in a page (Content Builder "Booking" block): report our
        // height to the parent so the host iframe auto-sizes with no scroll.
        echo '<script>(function(){'
           . 'function h(){var b=document.body,d=document.documentElement;'
           . 'var ht=Math.max(b.scrollHeight,d.scrollHeight,b.offsetHeight,d.offsetHeight);'
           . 'try{parent.postMessage({type:"cb-booking-height",height:ht},"*");}catch(e){}}'
           . 'window.addEventListener("load",h);window.addEventListener("resize",h);'
           . 'if(window.ResizeObserver){try{new ResizeObserver(h).observe(document.body);}catch(e){}}'
           . 'setTimeout(h,60);setTimeout(h,400);})();</script>';
    }
    echo '</body></html>';
}

function bookpub_stepper(int $current, bool $embed): void {
    $labels = ['Service','Provider','When','Confirm'];
    echo '<ol class="book-stepper">';
    foreach ($labels as $i => $label) {
        $n = $i + 1;
        $cls = 'book-step';
        if ($n <  $current) $cls .= ' is-done';
        if ($n === $current) $cls .= ' is-current';
        echo '<li class="' . $cls . '"><span class="book-step-num">' . $n . '</span>'
           . '<span class="book-step-label">' . e($label) . '</span></li>';
    }
    echo '</ol>';
}

function bookpub_render_step1(bool $embed): void {
    bookpub_layout_start('Book an appointment', $embed);
    bookpub_stepper(1, $embed);
    echo '<h1>Pick a service</h1>';
    // One implementation of the cards, shared with the booking BLOCK — see
    // BookingAPI::renderServiceCatalogue(). The href keeps this page's embed and
    // query state, which is the only thing that differs between the two callers.
    echo BookingAPI::renderServiceCatalogue(
        static fn (int $id): string => bookpub_qs(['service' => $id], $embed)
    );
    bookpub_layout_end($embed);
}

function bookpub_render_step2(array $service, bool $embed): void {
    $providers = BookingAPI::getProvidersForService((int)$service['id']);
    bookpub_layout_start($service['name'], $embed);
    bookpub_stepper(2, $embed);
    echo '<a class="book-back" href="' . e(bookpub_qs([], $embed)) . '">&larr; back</a>';
    echo '<h1>Choose a provider</h1><p class="book-sub">For <strong>' . e($service['name']) . '</strong>.</p>';
    if (!$providers) {
        echo '<div class="alert alert-warning">No providers are available for this service yet.</div>';
    } else {
        echo '<div class="book-grid">';
        foreach ($providers as $p) {
            $price = BookingAPI::serviceBasePriceCents($service, (int)$p['id']);
            echo '<a class="book-card-link" href="'
               . e(bookpub_qs(['service' => (int)$service['id'], 'provider' => (int)$p['id']], $embed)) . '">';
            echo '<div class="book-card-title">' . e($p['name']) . '</div>';
            echo '<div class="book-card-meta">' . ($price > 0 ? e($service['currency']) . ' ' . number_format($price / 100, 2) : 'Free') . '</div>';
            if (!empty($p['bio'])) {
                echo '<div class="book-card-desc">' . e(mb_strimwidth($p['bio'], 0, 140, '…')) . '</div>';
            }
            echo '</a>';
        }
        echo '</div>';
    }
    bookpub_layout_end($embed);
}

function bookpub_calendar_script(): void {
    ?>
    <script>
    (function () {
        var MONTHS = ['January','February','March','April','May','June','July',
                      'August','September','October','November','December'];
        function pad(n){ return (n < 10 ? '0' : '') + n; }
        function iso(y, m, d){ return y + '-' + pad(m + 1) + '-' + pad(d); }
        function parse(s){ var p = (s || '').split('-'); return p.length === 3
            ? { y: +p[0], m: +p[1] - 1, d: +p[2] } : null; }

        function init(cal){
            var form     = cal.closest('form');
            var dateIn   = form ? form.querySelector('input[name="date"]') : null;
            var titleEl  = cal.querySelector('[data-cal-title]');
            var daysEl   = cal.querySelector('[data-cal-days]');
            var prevBtn  = cal.querySelector('[data-cal-prev]');
            var nextBtn  = cal.querySelector('[data-cal-next]');
            var selected = cal.getAttribute('data-selected') || '';
            var todayStr = cal.getAttribute('data-today') || '';
            var minStr   = cal.getAttribute('data-min') || todayStr;
            var sel = parse(selected), minObj = parse(minStr);
            var view = sel ? { y: sel.y, m: sel.m }
                     : (minObj ? { y: minObj.y, m: minObj.m } : null);
            if (!view){ var n = new Date(); view = { y: n.getFullYear(), m: n.getMonth() }; }

            function render(){
                titleEl.textContent = MONTHS[view.m] + ' ' + view.y;
                daysEl.innerHTML = '';
                var first = new Date(view.y, view.m, 1).getDay();
                var dim   = new Date(view.y, view.m + 1, 0).getDate();
                for (var i = 0; i < first; i++){
                    var sp = document.createElement('span');
                    sp.className = 'book-cal-day is-empty';
                    daysEl.appendChild(sp);
                }
                for (var d = 1; d <= dim; d++){
                    var cur  = iso(view.y, view.m, d);
                    var cell = document.createElement('button');
                    cell.type = 'button';
                    cell.className = 'book-cal-day';
                    cell.textContent = d;
                    if (todayStr && cur === todayStr) cell.classList.add('is-today');
                    if (selected && cur === selected) cell.classList.add('is-selected');
                    if (minStr && cur < minStr){
                        cell.disabled = true;
                        cell.setAttribute('aria-disabled', 'true');
                    } else {
                        cell.setAttribute('aria-label', cur);
                        (function (c){
                            cell.addEventListener('click', function (){
                                if (dateIn) dateIn.value = c;
                                if (form){ form.requestSubmit ? form.requestSubmit() : form.submit(); }
                            });
                        })(cur);
                    }
                    daysEl.appendChild(cell);
                }
                if (prevBtn){
                    prevBtn.disabled = minObj
                        ? (view.y < minObj.y || (view.y === minObj.y && view.m <= minObj.m))
                        : false;
                }
            }
            if (prevBtn) prevBtn.addEventListener('click', function (){
                if (--view.m < 0){ view.m = 11; view.y--; } render();
            });
            if (nextBtn) nextBtn.addEventListener('click', function (){
                if (++view.m > 11){ view.m = 0; view.y++; } render();
            });
            render();
        }
        var cals = document.querySelectorAll('.book-cal');
        for (var i = 0; i < cals.length; i++) init(cals[i]);
    })();
    </script>
    <?php
}

function bookpub_render_step3(array $service, array $provider, string $date, int $party, bool $embed): void {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    $capacity = max(1, (int)($service['capacity'] ?? 1));
    $party    = max(1, min($capacity, $party));
    $slotMap  = BookingAPI::computeSlotCapacityMap((int)$service['id'], (int)$provider['id'], $date, $party);

    $showCapacity = $capacity > 1 && bookpub_book_setting('show_capacity');
    $multiEnabled = bookpub_book_setting('multislot_enabled', false);
    $multiMax     = bookpub_multislot_max();
    $selections   = $multiEnabled ? bookpub_parse_multi_selections((string)($_GET['sel'] ?? ''), $multiMax) : [];
    $dateFormId   = 'book-date-form';

    bookpub_layout_start($service['name'] . ' — ' . $provider['name'], $embed);
    bookpub_stepper(3, $embed);
    echo '<a class="book-back" href="' . e(bookpub_qs(['service' => (int)$service['id']], $embed)) . '">&larr; back</a>';
    echo '<h1>Pick a time</h1>';
    echo '<p class="book-sub"><strong>' . e($service['name']) . '</strong> with '
       . e($provider['name']) . ' · ' . BookingAPI::effectiveDuration($service, (int)$provider['id']) . ' min</p>';
    if ($multiEnabled) {
        echo '<p class="book-sub text-sm text-muted">Select up to ' . (int)$multiMax
           . ' time slots from any dates, then continue to book them all at once.</p>';
    }
    ?>
    <div class="book-when">
    <form method="get" class="book-date-form" id="<?= e($dateFormId) ?>">
        <input type="hidden" name="service"  value="<?= (int)$service['id'] ?>">
        <input type="hidden" name="provider" value="<?= (int)$provider['id'] ?>">
        <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>

        <span class="field-label">Date</span>
        <!-- JS-updated value; submitting the form (e.g. party change) keeps the date. -->
        <input type="hidden" name="date" value="<?= e($date) ?>">
        <?php if ($multiEnabled): ?>
            <!-- Carries the customer's picks-so-far across a date change
                 (the calendar reloads this form via GET) and into the final
                 "Continue" submit. Re-validated server-side either way —
                 see bookpub_parse_multi_selections(). -->
            <input type="hidden" name="sel" id="book-multiselect-field" value="<?= e(json_encode(array_map(
                static fn(string $v) => ['value' => $v], $selections
            ))) ?>">
        <?php endif; ?>
        <div class="book-cal" data-selected="<?= e($date) ?>" data-today="<?= e(date('Y-m-d')) ?>" data-min="<?= e(date('Y-m-d')) ?>">
            <div class="book-cal-head">
                <button type="button" class="book-cal-nav" data-cal-prev aria-label="Previous month">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <div class="book-cal-title" data-cal-title aria-live="polite"></div>
                <button type="button" class="book-cal-nav" data-cal-next aria-label="Next month">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
            </div>
            <div class="book-cal-dow" aria-hidden="true">
                <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
            </div>
            <div class="book-cal-days" data-cal-days role="grid"></div>
        </div>
        <noscript>
            <input type="date" name="date" value="<?= e($date) ?>" min="<?= e(date('Y-m-d')) ?>" onchange="this.form.submit()">
        </noscript>

        <?php if ($capacity > 1): ?>
            <label class="field-label" for="party" style="margin-top:8px;">People</label>
            <select id="party" name="party" onchange="this.form.submit()">
                <?php for ($n = 1; $n <= $capacity; $n++): ?>
                    <option value="<?= $n ?>" <?= $n === $party ? 'selected' : '' ?>><?= $n ?></option>
                <?php endfor; ?>
            </select>
        <?php endif; ?>
    </form>
    <?php bookpub_calendar_script(); ?>
    <div class="book-when-slots" data-day-label="<?= e(date('D j M', strtotime($date))) ?>">
    <?php
    if (empty($slotMap)) {
        echo '<div class="alert alert-info">No slots available on this day. Pick another date.</div>';
    } else {
        echo '<div class="book-slots" data-slot-list>';
        foreach ($slotMap as $time => $cap) {
            $key = $date . ' ' . $time; // machine "Y-m-d H:i" — the multi-select value
            $isSelected = $multiEnabled && in_array($key, $selections, true);
            $capBadge = '';
            if ($showCapacity) {
                $left = (int)$cap['remaining'];
                $capBadge = '<span class="book-slot-cap">' . (int)$left . ($left === 1 ? ' spot left' : ' spots left') . '</span>';
            }
            // The href's "slot"/"date" values and the array key above must
            // stay the raw 24h "H:i" machine format ($time) — they round-
            // trip through the URL and are matched back against
            // computeAvailableSlots()'s own H:i keys on submit. Only the
            // visible label honors the site's Time Format setting.
            //
            // When multi-select is on, JS intercepts this link's click
            // (data-slot-btn) and adds/removes it from the running list
            // instead of navigating — data-value carries the raw machine
            // key it needs for that. With JS disabled the link still just
            // navigates straight to step 4 for that one time, same as the
            // single-select path.
            echo '<a class="book-slot' . ($isSelected ? ' is-selected' : '') . '" href="'
               . e(bookpub_qs([
                    'service'  => (int)$service['id'],
                    'provider' => (int)$provider['id'],
                    'date'     => $date,
                    'slot'     => $time,
                    'party'    => $party,
                 ], $embed)) . '"'
               . ($multiEnabled ? ' data-slot-btn data-value="' . e($key) . '"' : '') . '>'
               . '<span class="book-slot-time">' . e(slate_format_time($date . ' ' . $time)) . '</span>'
               . $capBadge . '</a>';
        }
        echo '</div>';
    }
    ?>
    </div><!-- /book-when-slots -->

    <?php if ($multiEnabled): ?>
    <div class="book-multiselect-bar" id="book-multiselect-bar" hidden>
        <div class="book-multiselect-chips" id="book-multiselect-chips"></div>
        <div class="book-multiselect-actions">
            <div class="book-multiselect-progress">
                <span class="book-multiselect-ring" id="book-multiselect-ring" aria-hidden="true">
                    <span class="book-multiselect-ring-label" id="book-multiselect-ring-label"></span>
                </span>
                <span class="book-multiselect-progress-label">selected</span>
                <span class="book-sr-only" id="book-multiselect-count" aria-live="polite"></span>
            </div>
            <button type="submit" form="<?= e($dateFormId) ?>" name="multi" value="1"
                    class="btn btn-primary" id="book-multiselect-continue" disabled>Continue</button>
        </div>
    </div>
    <script>
    (function () {
        var field    = document.getElementById('book-multiselect-field');
        var bar      = document.getElementById('book-multiselect-bar');
        var chipsEl  = document.getElementById('book-multiselect-chips');
        var countEl  = document.getElementById('book-multiselect-count');
        var ring     = document.getElementById('book-multiselect-ring');
        var ringLbl  = document.getElementById('book-multiselect-ring-label');
        var goBtn    = document.getElementById('book-multiselect-continue');
        var list     = document.querySelector('[data-slot-list]');
        var dayLabel = (document.querySelector('.book-when-slots') || {}).getAttribute
            ? document.querySelector('.book-when-slots').getAttribute('data-day-label') : '';
        var max      = <?= (int)$multiMax ?>;
        if (!field || !bar || !list) return;

        var picks = [];
        try {
            var raw = JSON.parse(field.value || '[]');
            if (Array.isArray(raw)) {
                picks = raw.map(function (p) {
                    return (p && typeof p === 'object') ? { value: String(p.value || ''), label: String(p.label || p.value || '') }
                                                         : { value: String(p), label: String(p) };
                }).filter(function (p) { return /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(p.value); });
            }
        } catch (e) { picks = []; }

        function persist() {
            field.value = JSON.stringify(picks);
        }
        function render() {
            chipsEl.innerHTML = '';
            picks.forEach(function (p, i) {
                var chip = document.createElement('span');
                chip.className = 'book-chip';
                var txt = document.createElement('span');
                txt.textContent = p.label;
                chip.appendChild(txt);
                var rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'book-chip-remove';
                rm.setAttribute('aria-label', 'Remove ' + p.label);
                rm.textContent = '×';
                rm.addEventListener('click', function () {
                    picks.splice(i, 1);
                    persist(); render(); syncSlotButtons();
                });
                chip.appendChild(rm);
                chipsEl.appendChild(chip);
            });
            bar.hidden = picks.length === 0;
            goBtn.disabled = picks.length === 0;
            countEl.textContent = picks.length + ' of ' + max + ' selected';
            var pct = max > 0 ? Math.min(100, Math.round((picks.length / max) * 100)) : 0;
            ring.style.setProperty('--pct', pct + '%');
            ringLbl.textContent = picks.length + '/' + max;
        }
        function syncSlotButtons() {
            var atMax = picks.length >= max;
            var btns = document.querySelectorAll('[data-slot-btn]');
            for (var i = 0; i < btns.length; i++) {
                var v = btns[i].getAttribute('data-value');
                var idx = picks.findIndex(function (p) { return p.value === v; });
                btns[i].classList.toggle('is-selected', idx !== -1);
                btns[i].classList.toggle('is-full', atMax && idx === -1);
            }
        }

        list.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-slot-btn]');
            if (!btn) return;
            e.preventDefault();
            var v = btn.getAttribute('data-value');
            var idx = picks.findIndex(function (p) { return p.value === v; });
            if (idx !== -1) {
                picks.splice(idx, 1);
            } else {
                if (picks.length >= max) return; // silently ignore past the cap
                var timeEl = btn.querySelector('.book-slot-time');
                var timeText = (timeEl ? timeEl.textContent : btn.textContent).replace(/\s+/g, ' ').trim();
                picks.push({ value: v, label: (dayLabel ? dayLabel + ', ' : '') + timeText });
            }
            persist(); render(); syncSlotButtons();
        });

        render();
        syncSlotButtons();
    })();
    </script>
    <?php endif; ?>

    </div><!-- /book-when -->
    <?php
    bookpub_layout_end($embed);
}

function bookpub_render_step4(array $service, array $provider, string $date, string $slot, int $party = 1, array $errors = [], string $generalError = '', bool $embed = false, array $multiSelections = []): void {
    $cust     = Auth::customer();
    $capacity = max(1, (int)($service['capacity'] ?? 1));
    $party    = max(1, min($capacity, $party));
    $addons   = BookingAPI::getServiceAddons((int)$service['id']);
    $fields   = BookingAPI::getCustomFields((int)$service['id']);
    $basePrice= BookingAPI::serviceBasePriceCents($service, (int)$provider['id']);
    $mode     = (string)($service['payment_mode'] ?? 'free');
    $isMulti  = count($multiSelections) >= 2;

    bookpub_layout_start('Confirm — ' . $service['name'], $embed);
    bookpub_stepper(4, $embed);
    if ($isMulti) {
        echo '<a class="book-back" href="'
           . e(bookpub_qs(['service' => (int)$service['id'], 'provider' => (int)$provider['id'], 'party' => $party,
                            'sel' => json_encode($multiSelections)], $embed))
           . '">&larr; back</a>';
    } else {
        echo '<a class="book-back" href="'
           . e(bookpub_qs(['service' => (int)$service['id'], 'provider' => (int)$provider['id'], 'date' => $date, 'party' => $party], $embed))
           . '">&larr; back</a>';
    }
    echo '<h1>Confirm your booking</h1>';
    echo '<div class="book-summary">';
    echo   '<div><span class="kv-label">Service</span><span class="kv-value">' . e($service['name']) . '</span></div>';
    echo   '<div><span class="kv-label">With</span><span class="kv-value">' . e($provider['name']) . '</span></div>';
    if ($isMulti) {
        echo '<div><span class="kv-label">When</span><span class="kv-value">' . count($multiSelections) . ' times selected</span></div>';
    } else {
        echo '<div><span class="kv-label">When</span><span class="kv-value">'
           . e(date('l, j F Y', strtotime($date))) . ' · ' . e(slate_format_time($date . ' ' . $slot)) . '</span></div>';
    }
    if ($party > 1) echo '<div><span class="kv-label">People</span><span class="kv-value">' . (int)$party . '</span></div>';
    if ($basePrice > 0) {
        echo '<div><span class="kv-label">Price</span><span class="kv-value">'
           . e($service['currency']) . ' ' . number_format($basePrice / 100, 2)
           . ($party > 1 ? ' × ' . (int)$party : '')
           . ($isMulti ? ' per time' : '') . '</span></div>';
    }
    echo '</div>';

    if ($isMulti) {
        echo '<div class="book-multiselect-summary"><div class="book-multiselect-summary-title">Booking these ' . count($multiSelections) . ' times</div><ul class="book-multiselect-summary-list">';
        foreach ($multiSelections as $dt) {
            $ts = strtotime($dt) ?: slate_db_time();
            echo '<li class="book-multiselect-summary-item">'
               .   '<span class="book-multiselect-summary-date">'
               .     '<span class="book-multiselect-summary-dow">' . e(date('D', $ts)) . '</span>'
               .     '<span class="book-multiselect-summary-dom">' . e(date('j', $ts)) . '</span>'
               .   '</span>'
               .   '<span class="book-multiselect-summary-detail">'
               .     '<span class="book-multiselect-summary-time">' . e(slate_format_time($dt)) . '</span>'
               .     '<span class="book-multiselect-summary-month">' . e(date('M Y', $ts)) . '</span>'
               .   '</span>'
               . '</li>';
        }
        echo '</ul></div>';
        if ($basePrice > 0) {
            echo '<div class="alert alert-info">Payment (if required) covers your first selected time. The other times are booked the same way any booking on this site confirms, and each has its own confirmation you can pay from if it also needs payment.</div>';
        }
    }

    if ($generalError !== '') echo '<div class="alert alert-error">' . e($generalError) . '</div>';
    ?>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><?= submit_token_field() ?>
        <input type="hidden" name="service"  value="<?= (int)$service['id'] ?>">
        <input type="hidden" name="provider" value="<?= (int)$provider['id'] ?>">
        <?php if ($isMulti): ?>
            <input type="hidden" name="multi_slots" value="<?= e(json_encode($multiSelections)) ?>">
        <?php else: ?>
            <input type="hidden" name="date" value="<?= e($date) ?>">
            <input type="hidden" name="slot" value="<?= e($slot) ?>">
        <?php endif; ?>
        <input type="hidden" name="party"    value="<?= (int)$party ?>">
        <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>

        <?php if ($addons): ?>
            <fieldset class="book-fieldset">
                <legend>Add-ons</legend>
                <?php foreach ($addons as $a): ?>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:4px 0;">
                        <input type="checkbox" name="addons[]" value="<?= (int)$a['id'] ?>">
                        <span><?= e($a['name']) ?>
                            <?php if ((int)$a['price_cents'] > 0): ?>· <?= e($service['currency']) ?> <?= number_format($a['price_cents'] / 100, 2) ?><?php endif; ?>
                            <?php if ((int)$a['duration_min'] > 0): ?>· +<?= (int)$a['duration_min'] ?> min<?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="customer_name">Your name <span class="field-required">*</span></label>
                <input type="text" id="customer_name" name="customer_name" required maxlength="160" value="<?= e($cust['name'] ?? '') ?>">
            </div>
            <div class="field">
                <label class="field-label" for="customer_email">Email <span class="field-required">*</span></label>
                <input type="email" id="customer_email" name="customer_email" required maxlength="200" value="<?= e($cust['email'] ?? '') ?>">
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="customer_phone">Phone <span class="text-muted text-xs">(optional)</span></label>
            <input type="tel" id="customer_phone" name="customer_phone" maxlength="40">
        </div>

        <?php foreach ($fields as $f) bookpub_render_custom_field($f); ?>

        <div class="field">
            <label class="field-label" for="notes">Notes <span class="text-muted text-xs">(optional)</span></label>
            <textarea id="notes" name="notes" maxlength="2000"></textarea>
        </div>

        <?php
        $showCoupon = bookpub_book_setting('show_coupon');
        $showGift   = bookpub_book_setting('show_gift_card');
        // Repeat (weekly) and multi-select both create several appointments
        // from one submission — offering both at once would mean either
        // combining them (a customer picks N arbitrary times AND repeats
        // each one weekly) or the two silently overriding each other. Multi-
        // select already lets a customer pick as many arbitrary times as
        // they want in one go, so Repeat is simply out of scope once they've
        // used it — hide it rather than build that combination.
        $showRepeat = bookpub_book_setting('show_repeat') && !$isMulti;
        ?>
        <?php if ($basePrice > 0 && ($showCoupon || $showGift)): ?>
            <section class="book-section">
                <div class="book-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                    Promo / gift code
                </div>
                <div class="field-row field-row-2">
                    <?php if ($showCoupon): ?>
                    <div class="field">
                        <label class="field-label" for="coupon_code">Coupon code</label>
                        <div class="book-input-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                            <input type="text" id="coupon_code" name="coupon_code" maxlength="60" autocomplete="off" placeholder="e.g. SAVE10">
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($showGift): ?>
                    <div class="field">
                        <label class="field-label" for="gift_card_code">Gift card</label>
                        <div class="book-input-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                            <input type="text" id="gift_card_code" name="gift_card_code" maxlength="60" autocomplete="off" placeholder="Gift card code">
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($showRepeat): ?>
        <section class="book-section">
            <div class="book-section-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                Repeat
            </div>
            <div class="book-options">
                <label class="book-option">
                    <input type="radio" name="recur" value="none" checked>
                    <span class="book-option-body">
                        <span class="book-option-title">One-time</span>
                        <span class="book-option-sub">A single appointment</span>
                    </span>
                </label>
                <label class="book-option">
                    <input type="radio" name="recur" value="weekly">
                    <span class="book-option-body">
                        <span class="book-option-title">Weekly for
                            <input type="number" name="recur_count" min="2" max="12" value="4" class="book-weeks-input"
                                   onfocus="this.closest('label').querySelector('input[type=radio]').checked=true">
                            weeks</span>
                        <span class="book-option-sub">Repeats on the same day &amp; time each week</span>
                    </span>
                </label>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($mode === 'full' || $mode === 'deposit'): ?>
            <div class="alert alert-info">This service requires <?= $mode === 'deposit' ? 'a deposit' : 'payment' ?>. You'll be taken to secure checkout to confirm your slot.</div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-lg btn-block"><?= ($mode === 'full' || $mode === 'deposit') ? 'Continue to payment' : 'Confirm booking' ?></button>
    </form>
    <?php
    bookpub_layout_end($embed);
}

/** Render a single custom field input. */
function bookpub_render_custom_field(array $f): void {
    $name = $f['name'];
    $req  = (int)($f['is_required'] ?? 0) === 1;
    $reqMark = $req ? ' <span class="field-required">*</span>' : '';
    echo '<div class="field">';
    echo '<label class="field-label" for="cf_' . e($name) . '">' . e($f['label']) . $reqMark . '</label>';
    switch ($f['type']) {
        case 'textarea':
            echo '<textarea id="cf_' . e($name) . '" name="custom[' . e($name) . ']" maxlength="2000"' . ($req ? ' required' : '') . '></textarea>';
            break;
        case 'select':
            $opts = json_decode((string)($f['options_json'] ?? '[]'), true) ?: [];
            echo '<select id="cf_' . e($name) . '" name="custom[' . e($name) . ']"' . ($req ? ' required' : '') . '>';
            echo '<option value="">— choose —</option>';
            foreach ($opts as $o) echo '<option value="' . e($o) . '">' . e($o) . '</option>';
            echo '</select>';
            break;
        case 'checkbox':
            echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">'
               . '<input type="checkbox" name="custom[' . e($name) . ']" value="1"' . ($req ? ' required' : '') . '><span>Yes</span></label>';
            break;
        case 'file':
            echo '<input type="file" id="cf_' . e($name) . '" name="custom_file_' . e($name) . '"' . ($req ? ' required' : '') . '>';
            break;
        default: // text, tel, url etc. — treat as text
            echo '<input type="text" id="cf_' . e($name) . '" name="custom[' . e($name) . ']" maxlength="500"' . ($req ? ' required' : '') . '>';
    }
    echo '</div>';
}

function bookpub_render_step4_with_error(array $args, string $error, bool $embed, array $multiSelections = []): void {
    $service  = BookingAPI::getService((int)$args['service_id']);
    $provider = Database::row("SELECT * FROM booking_providers WHERE id = ?", [(int)$args['provider_id']]);
    if (!$service || !$provider) {
        bookpub_render_error($error, $embed);
        return;
    }
    [$date, $slot] = array_pad(explode(' ', (string)($args['starts_at'] ?? '')), 2, '');
    $slot = substr($slot, 0, 5);
    bookpub_render_step4($service, $provider, $date, $slot, (int)($args['party_size'] ?? 1), [], $error, $embed, $multiSelections);
}

function bookpub_render_success(string $ref, array $args, bool $embed, string $status = 'confirmed', int $booked = 1, int $requested = 1, bool $isMultiBatch = false, array $failedTimes = []): void {
    bookpub_layout_start('Booking confirmed', $embed);
    ?>
    <div class="book-success">
        <div class="book-success-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h1><?= $status === 'pending' ? 'Slot reserved' : ($status === 'awaiting_approval' ? 'Request received' : "You're booked") ?></h1>
        <?php if ($status === 'pending'): ?>
            <p>Your slot is reserved. We'll follow up to collect payment.</p>
        <?php elseif ($status === 'awaiting_approval'): ?>
            <p>Your time is held while we review the request — we'll email <strong><?= e($args['customer_email']) ?></strong> as soon as it's approved.</p>
        <?php else: ?>
            <p>We've sent a confirmation to <strong><?= e($args['customer_email']) ?></strong>.</p>
        <?php endif; ?>
        <?php if ($requested > 1 && !$isMultiBatch): ?>
            <p class="text-sm text-muted"><?= (int)$booked ?> of <?= (int)$requested ?> weekly appointments booked.</p>
        <?php elseif ($isMultiBatch): ?>
            <p class="text-sm text-muted"><?= (int)$booked ?> of <?= (int)$requested ?> selected times booked.</p>
            <?php if ($failedTimes): ?>
                <div class="alert alert-warning" style="text-align:left;">
                    <div>These times couldn't be booked — someone else may have taken them. Please pick a different time for each:</div>
                    <ul class="book-multiselect-summary-list">
                        <?php foreach ($failedTimes as $dt): ?>
                            <li><?= e(slate_format_datetime($dt, 'D j M Y')) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <p class="text-sm text-muted">Reference: <code><?= e($ref) ?></code></p>
        <p class="mt-3"><a href="<?= e(bookpub_home($embed)) ?>" class="btn btn-ghost">Book something else</a></p>
    </div>
    <?php
    bookpub_layout_end($embed);
}

/**
 * Inline payment step. Rendered after a paid booking is created when the
 * Stripe "Inline embedded card payment" option is on. Shows the booking
 * summary plus an embedded Stripe Payment Element so the customer pays
 * the deposit/total without leaving the widget.
 *
 * The appointment already exists (status pending); on a successful
 * confirmation the browser lands on /book/pay/done?token=…&payment_intent=…
 * which verifies with Stripe and marks the booking paid.
 */
function bookpub_render_payment(array $appt, bool $embed): void {
    $service  = BookingAPI::getService((int)$appt['service_id']);
    $provider = Database::row("SELECT name FROM booking_providers WHERE id = ?", [(int)$appt['provider_id']]);
    $currency = strtoupper((string)($service['currency'] ?? 'USD'));
    $token    = (string)$appt['manage_token'];

    $due = (int)$appt['deposit_cents'] > 0
         ? (int)$appt['deposit_cents']
         : ((int)$appt['price_cents'] + (int)$appt['tax_cents']);
    $due -= (int)($appt['paid_cents'] ?? 0);
    $isDeposit = (int)$appt['deposit_cents'] > 0;

    $pubKey      = StripePaymentAPI::publishableKey();
    $intentUrl   = SLATE_URL . '/plugins/booking/public/pay-intent.php';
    $doneUrl     = SLATE_URL . '/book/pay/done?token=' . rawurlencode($token) . ($embed ? '&embed=1' : '');
    $applePayOn  = (Database::setting('stripe-payment.wallet_apple_pay')  ?? '1') !== '0';
    $googlePayOn = (Database::setting('stripe-payment.wallet_google_pay') ?? '1') !== '0';

    // "Card-only" = no Link and none of the extra (redirect/voucher) methods
    // are enabled. In that mode we strip the Payment Element down to just the
    // card fields — no billing name/email/phone, no Country selector, and no
    // Link "save my info" signup — since none of those are needed for a card
    // and we already know the customer from the booking. (When other methods
    // are enabled they may require those fields, so we leave them on.)
    $sset = static fn(string $k, string $d = '0'): bool =>
        (Database::setting('stripe-payment.' . $k) ?? $d) !== '0';
    $cardOnly = !$sset('wallet_link', '1')
        && !$sset('method_us_bank_account')
        && !$sset('method_klarna')
        && !$sset('method_cashapp')
        && !$sset('method_amazon_pay')
        // Bancontact uses the Payment Element only for EUR bookings. Keeping
        // this condition currency-aware preserves the compact card fields for
        // all other currencies when Bancontact is enabled globally.
        && !(strtolower((string)($appt['currency'] ?? 'usd')) === 'eur'
            && $sset('method_bancontact'));

    bookpub_layout_start('Payment — ' . (string)($service['name'] ?? 'Booking'), $embed);
    echo '<h1>Secure payment</h1>';

    echo '<div class="book-summary">';
    echo   '<div><span class="kv-label">Service</span><span class="kv-value">' . e((string)($service['name'] ?? '')) . '</span></div>';
    if ($provider) {
        echo '<div><span class="kv-label">With</span><span class="kv-value">' . e((string)$provider['name']) . '</span></div>';
    }
    echo   '<div><span class="kv-label">When</span><span class="kv-value">'
         . e(slate_format_datetime((string)$appt['starts_at'], 'l, j F Y')) . '</span></div>';
    echo   '<div><span class="kv-label">Reference</span><span class="kv-value"><code>' . e((string)$appt['ref']) . '</code></span></div>';
    echo   '<div><span class="kv-label">' . ($isDeposit ? 'Deposit due' : 'Amount due') . '</span>'
         . '<span class="kv-value"><strong>' . e($currency) . ' ' . number_format($due / 100, 2) . '</strong></span></div>';
    echo '</div>';
    ?>
    <style>
        .book-pay-wrap { margin-top: 18px; }
        #book-pay-element { padding: 6px 0; min-height: 60px; }
        .book-pay-loading { display: flex; align-items: center; gap: 10px; padding: 16px 2px; color: var(--muted, #6b7280); font-size: 14px; }
        .book-pay-spinner { width: 16px; height: 16px; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: book-pay-spin .7s linear infinite; flex: none; }
        @keyframes book-pay-spin { to { transform: rotate(360deg); } }
        #book-pay-card-fields .field-label { display:block; font-size: 13px; font-weight: 600; margin: 0 0 8px; color: var(--text, #111217); }
        /* Compact single-row card input: number | expiry | cvc in one box,
           matched to the widget's field styling (border, radius, focus ring). */
        .book-pay-card-inline { display:flex; align-items:stretch; border:1px solid var(--border-strong, rgba(0,0,0,0.13)); border-radius: var(--radius, 8px); background: var(--surface, #fff); overflow:hidden; transition: border-color .12s, box-shadow .12s; }
        .book-pay-card-inline > div { padding:13px 14px; display:flex; align-items:center; }
        .bpc-num { flex:1 1 auto; min-width:0; }
        .bpc-exp { flex:0 0 auto; width:108px; border-left:1px solid var(--border, rgba(0,0,0,0.08)); }
        .bpc-cvc { flex:0 0 auto; width:92px;  border-left:1px solid var(--border, rgba(0,0,0,0.08)); }
        .book-pay-card-inline > div > .__PrivateStripeElement,
        .book-pay-card-inline > div > div { width:100%; }
        .book-pay-card-inline:focus-within { border-color: var(--accent, #2563EB); box-shadow: 0 0 0 3px var(--ring, rgba(37,99,235,0.18)); }
        /* Spacing + actions */
        #book-pay-btn { margin-top: 24px; }
        .book-pay-cancel { display:block; text-align:center; margin-top: 14px; font-size: 14px; color: var(--muted, #6B7280); text-decoration: none; }
        .book-pay-cancel:hover { color: var(--text, #111217); text-decoration: underline; }
        @media (max-width: 460px) {
            .bpc-num { flex: 1 1 100%; }
            .book-pay-card-inline { flex-wrap: wrap; }
            .bpc-exp, .bpc-cvc { flex: 1 1 50%; width:auto; border-left:0; border-top:1px solid var(--border, rgba(0,0,0,0.08)); }
            .bpc-cvc { border-left:1px solid var(--border, rgba(0,0,0,0.08)); }
            .book-pay-card-inline > div { padding:12px 12px; }
        }
    </style>
    <form id="book-pay-form">
        <div class="book-pay-wrap">
            <div id="book-pay-loading" class="book-pay-loading">
                <span class="book-pay-spinner" aria-hidden="true"></span>
                <span>Loading secure payment form…</span>
            </div>
            <?php if ($cardOnly): ?>
            <div id="book-pay-card-fields" hidden>
                <label class="field-label">Card details</label>
                <div class="book-pay-card-inline">
                    <div id="book-pay-card-number" class="bpc-num"></div>
                    <div id="book-pay-card-expiry" class="bpc-exp"></div>
                    <div id="book-pay-card-cvc"    class="bpc-cvc"></div>
                </div>
            </div>
            <?php else: ?>
            <div id="book-pay-element" hidden></div>
            <?php endif; ?>
            <div id="book-pay-errors" class="alert alert-error" role="alert" style="display:none;margin-top:14px;"></div>
            <noscript>
                <div class="alert alert-error">JavaScript is required to pay inline. Please enable it and reload.</div>
            </noscript>
        </div>
        <button type="submit" id="book-pay-btn" class="btn btn-primary btn-lg btn-block" disabled>
            Pay <?= e($currency) ?> <?= number_format($due / 100, 2) ?>
        </button>
        <a href="<?= e(bookpub_home($embed)) ?>" class="book-pay-cancel">Cancel</a>
    </form>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
    (function () {
        var publishableKey = <?= json_encode($pubKey) ?>;
        var intentUrl      = <?= json_encode($intentUrl) ?>;
        var doneUrl        = <?= json_encode($doneUrl) ?>;
        var token          = <?= json_encode($token) ?>;
        var walletOpts     = {
            applePay:  <?= $applePayOn ? "'auto'" : "'never'" ?>,
            googlePay: <?= $googlePayOn ? "'auto'" : "'never'" ?>
        };
        var cardOnly      = <?= $cardOnly ? 'true' : 'false' ?>;
        var custName      = <?= json_encode((string)($appt['customer_name']  ?? '')) ?>;
        var custEmail     = <?= json_encode((string)($appt['customer_email'] ?? '')) ?>;

        var state = 'idle';
        var stripe = null, elements = null, paymentElement = null, cardElement = null;
        var clientSecret = null;
        var form = document.getElementById('book-pay-form');
        var btn  = document.getElementById('book-pay-btn');

        function showError(msg) {
            var box = document.getElementById('book-pay-errors');
            // Use inline display rather than the [hidden] attribute: the
            // widget's .alert rule sets display:block, which would override
            // [hidden] and leave an empty red box on screen.
            if (box) { box.textContent = msg; box.style.display = 'block'; }
        }
        function hideError() {
            var box = document.getElementById('book-pay-errors');
            if (box) { box.textContent = ''; box.style.display = 'none'; }
        }
        function setLoading(v) {
            var l = document.getElementById('book-pay-loading');
            // Whichever mount container this mode uses (split card fields or
            // the Payment Element wrapper).
            var m = document.getElementById('book-pay-card-fields')
                 || document.getElementById('book-pay-element');
            // The loader uses display:flex, which beats the [hidden] attribute
            // — so toggle inline display or it never disappears.
            if (l) l.style.display = v ? 'flex' : 'none';
            if (m) m.hidden = v;
        }

        function whenStripeReady(cb) {
            if (window.Stripe) return cb();
            var n = 0, iv = setInterval(function () {
                if (window.Stripe) { clearInterval(iv); cb(); }
                else if (++n > 100) { clearInterval(iv); showError('Could not load Stripe. Refresh and try again.'); }
            }, 100);
        }

        async function init() {
            if (state !== 'idle') return;
            state = 'initializing';
            hideError();
            setLoading(true);
            var resp, data = null;
            try {
                // Cache-buster + no-store so neither the browser nor a CDN can
                // hand back a stale client_secret from an earlier intent.
                resp = await fetch(intentUrl + '?_=' + (new Date()).getTime(), {
                    method: 'POST',
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: token })
                });
                data = await resp.json();
            } catch (e) {
                state = 'idle'; setLoading(false);
                showError('Network error contacting payment server.');
                return;
            }
            if (!resp.ok || !data || !data.client_secret) {
                state = 'idle'; setLoading(false);
                showError((data && data.error) ? data.error : 'Could not initialise payment.');
                return;
            }
            clientSecret = data.client_secret;
            function markReady() {
                if (state === 'ready') return;
                state = 'ready';
                setLoading(false);
                if (btn) btn.disabled = false;
            }
            try {
                stripe = window.Stripe(publishableKey);
                if (cardOnly) {
                    // Split Card Elements: separate Card number / Expiry / CVC
                    // boxes. The multi-method Payment Element surfaces
                    // account-enabled methods (Bank/Klarna/Link) even when the
                    // PaymentIntent is card-only, so for card-only we bypass it.
                    elements = stripe.elements();
                    var elStyle = { base: { fontSize: '16px', color: '#111827', '::placeholder': { color: '#9ca3af' } },
                                    invalid: { color: '#dc2626' } };
                    var cardNumber = elements.create('cardNumber', { style: elStyle, showIcon: true });
                    var cardExpiry = elements.create('cardExpiry', { style: elStyle });
                    var cardCvc    = elements.create('cardCvc',    { style: elStyle });
                    cardElement = cardNumber; // confirmCardPayment links the trio via this instance
                    cardNumber.on('ready', markReady);
                    cardNumber.on('change', function (ev) { if (ev && ev.complete) hideError(); });
                    state = 'mounting';
                    cardNumber.mount('#book-pay-card-number');
                    cardExpiry.mount('#book-pay-card-expiry');
                    cardCvc.mount('#book-pay-card-cvc');
                } else {
                    elements = stripe.elements({ clientSecret: clientSecret, appearance: { theme: 'stripe' } });
                    paymentElement = elements.create('payment', { layout: 'tabs', wallets: walletOpts });
                    paymentElement.on('ready', markReady);
                    paymentElement.on('loaderror', function (ev) {
                        state = 'idle'; setLoading(false);
                        showError((ev && ev.error && ev.error.message) || 'Could not load payment form.');
                    });
                    state = 'mounting';
                    paymentElement.mount('#book-pay-element');
                }
            } catch (e) {
                state = 'idle'; setLoading(false);
                showError('Stripe.js error: ' + (e.message || e));
                return;
            }
            // Safety net: if Stripe's 'ready' event is delayed or missed (seen
            // on some browsers/iframes), the element is still mounted and
            // usable — so clear the spinner and enable Pay after a short grace
            // period rather than spinning forever.
            setTimeout(function () {
                if (state === 'mounting') markReady();
            }, 4000);
        }

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (state !== 'ready' || !stripe || !elements) {
                showError('Payment form is still loading — please wait a moment.');
                return;
            }
            hideError();
            state = 'submitting';
            btn.disabled = true;
            var orig = btn.textContent;
            btn.textContent = 'Processing…';

            var billing = { name: custName || undefined, email: custEmail || undefined };
            var result;
            try {
                if (cardOnly) {
                    // Card Element flow: confirm the card against the intent's
                    // client_secret. 3D Secure (if any) is handled as a popup.
                    result = await stripe.confirmCardPayment(clientSecret, {
                        payment_method: { card: cardElement, billing_details: billing }
                    });
                } else {
                    result = await stripe.confirmPayment({
                        elements: elements,
                        confirmParams: {
                            return_url: doneUrl,
                            payment_method_data: { billing_details: billing }
                        },
                        redirect: 'if_required'
                    });
                }
            } catch (e2) {
                state = 'ready'; btn.disabled = false; btn.textContent = orig;
                showError('Unexpected error: ' + (e2.message || e2));
                return;
            }
            if (result.error) {
                state = 'ready'; btn.disabled = false; btn.textContent = orig;
                showError(result.error.message || 'Payment failed.');
                return;
            }
            var pi = result.paymentIntent;
            if (!pi || (pi.status !== 'succeeded' && pi.status !== 'requires_capture')) {
                state = 'ready'; btn.disabled = false; btn.textContent = orig;
                showError('Payment did not complete (status: ' + (pi ? pi.status : 'unknown') + ').');
                return;
            }
            // Paid inline — hand off to the done page to verify + confirm.
            window.location = doneUrl + '&payment_intent=' + encodeURIComponent(pi.id);
        });

        whenStripeReady(init);
    })();
    </script>
    <?php
    bookpub_layout_end($embed);
}

function bookpub_render_error(string $msg, bool $embed): void {
    bookpub_layout_start('Booking', $embed);
    echo '<h1>Something went wrong</h1>';
    echo '<div class="alert alert-error">' . e($msg) . '</div>';
    echo '<a href="' . e(bookpub_home($embed)) . '" class="btn">Start over</a>';
    bookpub_layout_end($embed);
}

// ─────────────────────────────────────────────────────────────
//             Self-service manage (cancel / reschedule)
// ─────────────────────────────────────────────────────────────

function bookpub_manage(bool $embed): void {
    $token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        bookpub_render_error('Invalid or missing management link.', $embed);
        return;
    }
    $appt = BookingAPI::findByManageToken($token);
    if (!$appt) { bookpub_render_error('Booking not found.', $embed); return; }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) { bookpub_render_error('Security check failed.', $embed); return; }
        $action = $_POST['_action'] ?? '';
        if ($action === 'cancel') {
            // Policy check FIRST — the view hides the button once a booking
            // is terminal or outside the notice window, but that is cosmetic
            // only; this is the actual enforcement. Admin-initiated cancels
            // go through BookingAPI::cancelAppointment() directly and never
            // hit this gate.
            $gate = BookingAPI::canSelfCancel($appt);
            if (empty($gate['ok'])) {
                bookpub_manage_view($appt, $embed, $gate['error'] ?? 'This booking can\'t be cancelled online.');
                return;
            }
            BookingAPI::cancelAppointment((int)$appt['id'], 'Cancelled by customer');
            if (class_exists('Notifications')) {
                Notifications::add('Booking cancelled by customer · ' . $appt['ref'], [
                    'body' => ($appt['customer_name'] ?: 'A customer') . ' cancelled ' . $appt['service_name']
                            . (!empty($appt['starts_at']) ? ' · was ' . date('D j M, g:ia', strtotime($appt['starts_at'])) : ''),
                    'url'  => plugin_url('booking', 'admin/appointment.php') . '?id=' . (int)$appt['id'],
                    'icon' => 'x-circle',
                ]);
            }
            bookpub_layout_start('Cancelled', $embed);
            echo '<div class="book-success"><h1>Booking cancelled</h1>'
               . '<p>Your appointment for <strong>' . e($appt['service_name']) . '</strong> has been cancelled.</p></div>';
            bookpub_layout_end($embed);
            return;
        }
        if ($action === 'reschedule') {
            $gate = BookingAPI::canSelfReschedule($appt);
            if (empty($gate['ok'])) {
                bookpub_manage_view($appt, $embed, $gate['error'] ?? 'This booking can\'t be rescheduled online.');
                return;
            }
            $newStart = (string)($_POST['date'] ?? '') . ' ' . (string)($_POST['slot'] ?? '');
            $res = BookingAPI::rescheduleAppointment((int)$appt['id'], $newStart);
            if (!empty($res['ok'])) {
                if (class_exists('Notifications')) {
                    Notifications::add('Booking rescheduled by customer · ' . $appt['ref'], [
                        'body' => ($appt['customer_name'] ?: 'A customer') . ' moved ' . $appt['service_name']
                                . ' to ' . date('D j M, g:ia', strtotime($newStart)),
                        'url'  => plugin_url('booking', 'admin/appointment.php') . '?id=' . (int)$appt['id'],
                        'icon' => 'calendar',
                    ]);
                }
                bookpub_layout_start('Rescheduled', $embed);
                echo '<div class="book-success"><h1>All set</h1>'
                   . '<p>Your appointment was moved to <strong>' . e(slate_format_datetime($newStart, 'l, j F Y')) . '</strong>.</p></div>';
                bookpub_layout_end($embed);
                return;
            }
            bookpub_manage_view($appt, $embed, $res['error'] ?? 'Could not reschedule.');
            return;
        }
    }
    bookpub_manage_view($appt, $embed, '');
}

function bookpub_manage_view(array $appt, bool $embed, string $error): void {
    $token = (string)$appt['manage_token'];
    $cancelled = in_array($appt['status'], ['cancelled','completed','no_show'], true);
    $date = (string)($_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d', max(time(), strtotime($appt['starts_at'])));
    $party = max(1, (int)($appt['party_size'] ?? 1));

    bookpub_layout_start('Manage booking', $embed);
    echo '<h1 style="margin-bottom:6px;">Your booking</h1>';
    echo '<p class="book-sub" style="margin-bottom:16px;">View details, reschedule your time, or cancel your appointment.</p>';

    echo '<div class="book-summary">';
    echo '<div><span class="kv-label">Service</span><span class="kv-value"><strong>' . e($appt['service_name']) . '</strong></span></div>';
    echo '<div><span class="kv-label">With</span><span class="kv-value">' . e($appt['provider_name']) . '</span></div>';
    echo '<div><span class="kv-label">When</span><span class="kv-value"><strong>' . e(slate_format_datetime($appt['starts_at'], 'l, j F Y')) . '</strong></span></div>';

    $statusColor = $appt['status'] === 'confirmed' ? '#15803D;background:#DCFCE7' : ($cancelled ? '#B91C1C;background:#FEE2E2' : '#B45309;background:#FEF3C7');
    $statusLabel = ['pending' => 'Awaiting payment', 'awaiting_approval' => 'Awaiting confirmation'][$appt['status']] ?? ucfirst((string)$appt['status']);
    echo '<div><span class="kv-label">Status</span><span class="kv-value"><span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;color:' . $statusColor . ';">' . e($statusLabel) . '</span></span></div>';
    echo '<div><span class="kv-label">Reference</span><span class="kv-value"><code style="font-weight:700;letter-spacing:0.04em;">' . e($appt['ref']) . '</code></span></div>';
    echo '</div>';

    if ($error !== '') echo '<div class="alert alert-error">' . e($error) . '</div>';

    if ($cancelled) {
        echo '<div class="alert alert-info">This booking can no longer be changed.</div>';
        bookpub_layout_end($embed);
        return;
    }

    // Customer self-service policy (Booking → Settings). Checked here purely
    // for what to SHOW — bookpub_manage()'s POST handler is what actually
    // enforces it, so this can never be bypassed by hiding/showing markup.
    $rescheduleGate = BookingAPI::canSelfReschedule($appt);
    $cancelGate     = BookingAPI::canSelfCancel($appt);

    if (empty($rescheduleGate['ok'])) {
        echo '<div class="alert alert-info" style="margin-top:20px;">' . e($rescheduleGate['error'] ?? 'Rescheduling isn\'t available for this booking.') . '</div>';
    } else {

    // Modern Reschedule Card
    echo '<div class="book-reschedule-wrap" style="margin-top:24px;border:1px solid var(--border, rgba(0,0,0,0.08));border-radius:14px;background:var(--surface, #fff);box-shadow:0 2px 8px rgba(0,0,0,0.03);overflow:hidden;">';

    echo '<div style="padding:16px 20px;border-bottom:1px solid var(--border, rgba(0,0,0,0.06));background:var(--surface-2, #FAFAF7);display:flex;align-items:center;justify-content:space-between;gap:12px;">';
    echo '  <div style="display:flex;align-items:center;gap:10px;">';
    echo '    <span style="display:grid;place-items:center;width:32px;height:32px;border-radius:8px;background:var(--accent-soft, #EFF6FF);color:var(--accent, #2563EB);">';
    echo '      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
    echo '    </span>';
    echo '    <div>';
    echo '      <h2 style="margin:0;font-size:16px;font-weight:700;color:var(--text, #111217);">Reschedule appointment</h2>';
    echo '      <p style="margin:2px 0 0;font-size:12.5px;color:var(--muted, #6B7280);">Select a new date and choose from available slots.</p>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    echo '<div style="padding:20px;">';
    echo '<div class="book-when" style="margin:0;">';
    echo '<form method="get" class="book-date-form">';
    echo '<input type="hidden" name="_route_path" value="manage">';
    echo '<input type="hidden" name="token" value="' . e($token) . '">';
    if ($embed) echo '<input type="hidden" name="embed" value="1">';
    echo '<input type="hidden" name="date" value="' . e($date) . '">';

    echo '<span class="field-label" style="display:block;margin-bottom:8px;font-weight:600;font-size:13px;color:var(--text, #111217);">Choose new date</span>';
    echo '<div class="book-cal" data-selected="' . e($date) . '" data-today="' . e(date('Y-m-d')) . '" data-min="' . e(date('Y-m-d')) . '">';
    echo '  <div class="book-cal-head">';
    echo '    <button type="button" class="book-cal-nav" data-cal-prev aria-label="Previous month">';
    echo '      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>';
    echo '    </button>';
    echo '    <div class="book-cal-title" data-cal-title aria-live="polite"></div>';
    echo '    <button type="button" class="book-cal-nav" data-cal-next aria-label="Next month">';
    echo '      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>';
    echo '    </button>';
    echo '  </div>';
    echo '  <div class="book-cal-dow" aria-hidden="true">';
    echo '    <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>';
    echo '  </div>';
    echo '  <div class="book-cal-days" data-cal-days role="grid"></div>';
    echo '</div>';
    echo '<noscript>';
    echo '  <label class="field-label" for="date">New date</label>';
    echo '  <input type="date" id="date" name="date" value="' . e($date) . '" min="' . e(date('Y-m-d')) . '" onchange="this.form.submit()">';
    echo '</noscript>';
    echo '</form>';

    echo '<div class="book-when-slots">';
    echo '  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">';
    echo '    <span style="font-weight:600;font-size:13.5px;color:var(--text, #111217);">';
    echo '      Available times &middot; <span style="color:var(--accent, #2563EB);">' . e(date('D, j M Y', strtotime($date))) . '</span>';
    echo '    </span>';

    $slots = BookingAPI::computeAvailableSlots((int)$appt['service_id'], (int)$appt['provider_id'], $date, $party);
    if ($slots) {
        echo '<span style="font-size:12px;color:var(--muted, #6B7280);font-weight:500;">' . count($slots) . ' slots</span>';
    }
    echo '  </div>';

    if (!$slots) {
        echo '<div class="alert alert-info" style="margin-top:6px;">No available slots on this day. Please select another date on the calendar.</div>';
    } else {
        echo '<div class="book-slots">';
        foreach ($slots as $time) {
            echo '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'Confirm rescheduling your appointment to ' . e(date('l, j F Y', strtotime($date))) . ' at ' . e($time) . '?\')">'
               . csrf_field()
               . '<input type="hidden" name="token" value="' . e($token) . '">'
               . '<input type="hidden" name="_action" value="reschedule">'
               . '<input type="hidden" name="date" value="' . e($date) . '">'
               . '<button type="submit" class="book-slot" name="slot" value="' . e($time) . '" style="cursor:pointer;width:100%;font-size:13.5px;font-weight:600;">'
               . e($time)
               . '</button>'
               . '</form>';
        }
        echo '</div>';
    }
    echo '</div>'; // /book-when-slots
    echo '</div>'; // /book-when
    echo '</div>'; // /padding:20px
    echo '</div>'; // /book-reschedule-wrap

    bookpub_calendar_script();
    } // end rescheduleGate

    // Cancel Section at the bottom of the card
    echo '<div style="margin-top:28px;padding-top:16px;border-top:1px solid var(--border, rgba(0,0,0,0.07));display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">';
    if (empty($cancelGate['ok'])) {
        echo '  <span style="font-size:13px;color:var(--muted, #6B7280);">' . e($cancelGate['error'] ?? 'Cancellation isn\'t available for this booking.') . '</span>';
    } else {
        echo '  <span style="font-size:13px;color:var(--muted, #6B7280);">Need to cancel this appointment entirely?</span>';
        echo '  <form method="post" onsubmit="return confirm(\'Are you sure you want to cancel this booking? This action cannot be undone.\')" style="margin:0;">'
           . csrf_field()
           . '<input type="hidden" name="token" value="' . e($token) . '">'
           . '<input type="hidden" name="_action" value="cancel">'
           . '<button type="submit" class="btn btn-sm btn-ghost" style="color:var(--danger, #DC2626);border-color:color-mix(in srgb, var(--danger, #DC2626) 30%, transparent);font-size:13px;cursor:pointer;">Cancel booking</button>'
           . '</form>';
    }
    echo '</div>';

    bookpub_layout_end($embed);
}

// ─────────────────────────────────────────────────────────────
//                  Stripe checkout return
// ─────────────────────────────────────────────────────────────

function bookpub_pay_done(bool $embed): void {
    $token     = (string)($_GET['token'] ?? '');
    $sessionId = (string)($_GET['session_id'] ?? '');
    $piId      = (string)($_GET['payment_intent'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        bookpub_render_error('Invalid payment link.', $embed);
        return;
    }
    $appt = BookingAPI::findByManageToken($token, false);
    if (!$appt) { bookpub_render_error('Booking not found.', $embed); return; }

    // Fallback in case the webhook hasn't arrived yet: verify the session is
    // paid with Stripe, then record + mark paid (all idempotent).
    if (($appt['payment_status'] ?? '') !== 'paid' && $sessionId !== '' && class_exists('StripePaymentAPI')) {
        $sess = StripePaymentAPI::getSession($sessionId);
        // Bind the Stripe session to THIS appointment via the metadata
        // stamped at session creation. Without this, a customer holding
        // their own manage_token could pass any paid session_id from the
        // same Stripe account and have their pending booking marked paid
        // against an unrelated payment. (The webhook path keys off the
        // same metadata.booking_appt_id.)
        $sessMeta   = ($sess && is_array($sess['metadata'] ?? null)) ? $sess['metadata'] : [];
        $sessApptId = (int)($sessMeta['booking_appt_id'] ?? 0);
        if ($sess && ($sess['payment_status'] ?? '') === 'paid' && $sessApptId === (int)$appt['id']) {
            $chargeId = StripePaymentAPI::recordCharge([
                'source_plugin'            => 'booking',
                'source_id'                => (string)$appt['id'],
                'stripe_session_id'        => $sessionId,
                'stripe_payment_intent_id' => (string)($sess['payment_intent'] ?? ''),
                'customer_email'           => (string)$appt['customer_email'],
                'amount_cents'             => (int)($sess['amount_total'] ?? 0),
                'currency'                 => strtoupper((string)($sess['currency'] ?? 'USD')),
                'status'                   => 'succeeded',
            ]);
            if ($chargeId) {
                Database::update('booking_appointments',
                    ['charge_id' => $chargeId, 'stripe_session_id' => $sessionId], 'id = ?', [(int)$appt['id']]);
            }
            BookingAPI::markPaid((int)$appt['id'], (int)($sess['amount_total'] ?? 0), (string)($sess['payment_intent'] ?? ''));
            $appt['payment_status'] = 'paid';
        } elseif ($sess && ($sess['payment_status'] ?? '') === 'paid' && $sessApptId !== (int)$appt['id']) {
            slate_log('Booking pay_done: session ' . $sessionId
                . ' metadata appt ' . $sessApptId . ' != ' . (int)$appt['id'], 'warning');
        }
    }

    // Embedded Payment Element fallback: Stripe (or our inline JS) returns
    // here with ?payment_intent=pi_…. Verify it with Stripe and bind it to
    // THIS appointment via metadata.booking_appt_id before marking paid.
    // Idempotent: recordCharge de-dupes on the PI id and markPaid no-ops
    // once already paid, so the webhook and this path can both run.
    if (($appt['payment_status'] ?? '') !== 'paid' && $piId !== ''
        && preg_match('/^pi_[A-Za-z0-9_]+$/', $piId) && class_exists('StripePaymentAPI')) {
        $pi = StripePaymentAPI::getPaymentIntent($piId);
        $piMeta   = ($pi && is_array($pi['metadata'] ?? null)) ? $pi['metadata'] : [];
        $piApptId = (int)($piMeta['booking_appt_id'] ?? 0);
        $piStatus = (string)($pi['status'] ?? '');
        if ($pi && in_array($piStatus, ['succeeded', 'requires_capture'], true)
            && $piApptId === (int)$appt['id']) {
            $paid = (int)($pi['amount_received'] ?? $pi['amount'] ?? 0);
            $chargeId = StripePaymentAPI::recordCharge([
                'source_plugin'            => 'booking',
                'source_id'                => (string)$appt['id'],
                'stripe_payment_intent_id' => $piId,
                'customer_email'           => (string)$appt['customer_email'],
                'amount_cents'             => $paid,
                'currency'                 => strtoupper((string)($pi['currency'] ?? 'USD')),
                'status'                   => 'succeeded',
            ]);
            if ($chargeId) {
                Database::update('booking_appointments',
                    ['charge_id' => $chargeId], 'id = ?', [(int)$appt['id']]);
            }
            BookingAPI::markPaid((int)$appt['id'], $paid, $piId);
            $appt['payment_status'] = 'paid';
        } elseif ($pi && $piApptId !== (int)$appt['id']) {
            slate_log('Booking pay_done: payment_intent ' . $piId
                . ' metadata appt ' . $piApptId . ' != ' . (int)$appt['id'], 'warning');
        }
    }

    // If we still don't see the payment as settled, it may be processing
    // (async method) or the customer landed here without completing. Show a
    // gentle holding message with a resume link rather than a false "Thank
    // you", and let the webhook flip it to paid in the background.
    if (($appt['payment_status'] ?? '') !== 'paid') {
        bookpub_layout_start('Payment processing', $embed);
        echo '<div class="book-success">';
        echo '<h1>Almost there</h1>';
        echo '<p>We\'re confirming your payment for <strong>' . e($appt['service_name']) . '</strong>. '
           . 'This can take a moment — you\'ll get an email once it\'s confirmed.</p>';
        echo '<p class="text-sm text-muted">Reference: <code>' . e($appt['ref']) . '</code></p>';
        echo '<p class="mt-3"><a href="' . e(SLATE_URL . '/book/manage?token=' . rawurlencode((string)$appt['manage_token']) . ($embed ? '&embed=1' : '')) . '" class="btn btn-ghost">View / retry payment</a></p>';
        echo '</div>';
        bookpub_layout_end($embed);
        return;
    }

    bookpub_layout_start('Payment received', $embed);
    echo '<div class="book-success">';
    echo '<div class="book-success-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>';
    echo '<h1>Thank you</h1>';
    echo '<p>Your booking for <strong>' . e($appt['service_name']) . '</strong> is confirmed.</p>';
    echo '<p class="text-sm text-muted">Reference: <code>' . e($appt['ref']) . '</code></p>';
    echo '<p class="mt-3"><a href="' . e(bookpub_home($embed)) . '" class="btn btn-ghost">Book something else</a></p>';
    echo '</div>';
    bookpub_layout_end($embed);
}
