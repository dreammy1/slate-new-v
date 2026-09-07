<?php
/**
 * Apply Company B Performing Arts Studio's real published pricing and policy to
 * the live tenant, replacing the invented demo figures from seed_companyb.php.
 *
 * The demo seed priced 15 classes at made-up rates between $75 and $130 and
 * costumes at $45. Company B's actual price list is duration-and-type driven:
 *
 *   $85   60 minute class
 *   $128  1.5 hour Ballet
 *   $140  1.5 hour Musical Theatre (dance and vocal training)
 *   $100  1 hour monthly vocal class
 *   $65   1 hour private vocal
 *   $45   half hour private vocal
 *
 * Classes that no line of that list covers are DEACTIVATED rather than
 * repriced or deleted: deactivating hides them from the catalog while keeping
 * their enrolments, occurrences and attendance history intact, which a delete
 * would destroy. Reactivating is one UPDATE if the studio does run them.
 *
 * Also writes the fee schedule (registration free, recital $100 + $50 per
 * additional child on May 1, costumes $95 per class in two instalments, tights
 * $10) into settings, where FeeSchedule reads it.
 *
 * DRY RUN BY DEFAULT — prints what it would change and touches nothing.
 *
 *   php plugins/studio/tools/apply_companyb_policies.php           # preview
 *   php plugins/studio/tools/apply_companyb_policies.php --apply   # write
 *
 * Idempotent: safe to re-run, and a second run reports no changes.
 */
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

$ROOT = dirname(__DIR__, 3);
foreach (file($ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v);
    if (str_starts_with($k, 'DB_')) define($k, $v);
    if ($k === 'TENANT_ID') define('TENANT_ID', (int) $v);
}
require $ROOT . '/src/autoload.php';
class_alias(\Slate\Data\Database::class, 'Database');
require $ROOT . '/includes/helpers.php';
require $ROOT . '/plugins/studio/StudioAPI.php';

$APPLY = in_array('--apply', $argv, true);
$TID   = defined('TENANT_ID') ? TENANT_ID : 1;

echo $APPLY ? "APPLYING to tenant {$TID}\n\n" : "DRY RUN (pass --apply to write) — tenant {$TID}\n\n";

/**
 * Price one class from the published list.
 *
 * Returns the price in cents, or null when no line covers it — which is the
 * signal to deactivate rather than guess. Guessing is how a studio ends up
 * quietly undercharging for a term.
 */
function companyb_price(string $name, string $style, int $minutes): ?int
{
    $style = strtolower($style);

    // 1.5-hour classes are priced by discipline, not by the generic rate.
    if ($minutes >= 85 && $minutes <= 95) {
        if (str_contains($style, 'ballet'))          { return 12800; }
        if (str_contains($style, 'musical theatre')) { return 14000; }
        return null;                                  // no other 1.5hr line exists
    }

    if ($minutes === 60) {
        // A group vocal class is the "1 hour monthly vocal class" line, not the
        // generic 60-minute rate — vocal is priced separately throughout.
        if (str_contains($style, 'vocal')) { return 10000; }

        // Disciplines Company B's list doesn't run. 60 minutes alone isn't
        // enough to price these; the studio decided they're out of catalog.
        foreach (['acting', 'cheer', 'pilates'] as $out) {
            if (str_contains($style, $out)) { return null; }
        }
        if (str_starts_with(strtolower($name), 'adult')) { return null; }

        return 8500;                                  // $85 — 60 minute class
    }

    // 75 minutes, 45 minutes, anything else: not on the list.
    return null;
}

$rows = Database::rows(
    "SELECT id, name, style, start_time, end_time, price_cents, is_active
       FROM studio_class_series WHERE tenant_id = ? ORDER BY name",
    [$TID]
);

$reprice = [];
$deactivate = [];

foreach ($rows as $r) {
    $mins  = (int) round((strtotime($r['end_time']) - strtotime($r['start_time'])) / 60);
    $price = companyb_price((string) $r['name'], (string) $r['style'], $mins);

    if ($price === null) {
        if ((int) $r['is_active'] === 1) { $deactivate[] = $r + ['mins' => $mins]; }
        continue;
    }
    if ((int) $r['price_cents'] !== $price) {
        $reprice[] = $r + ['mins' => $mins, 'new' => $price];
    }
}

// ── Report ────────────────────────────────────────────────────────────

printf("REPRICE (%d)\n", count($reprice));
foreach ($reprice as $r) {
    printf("  #%-3s %-30s %-16s %3dmin   $%-7s -> $%s\n",
        $r['id'], $r['name'], $r['style'], $r['mins'],
        number_format($r['price_cents'] / 100, 2), number_format($r['new'] / 100, 2));
}

printf("\nDEACTIVATE (%d) — not covered by the price list; enrolments kept\n", count($deactivate));
foreach ($deactivate as $r) {
    printf("  #%-3s %-30s %-16s %3dmin   $%s\n",
        $r['id'], $r['name'], $r['style'], $r['mins'],
        number_format($r['price_cents'] / 100, 2));
}

$costumeStale = (int) Database::value(
    "SELECT COUNT(*) FROM studio_costumes WHERE tenant_id = ? AND cost_cents <> 9500", [$TID]
);
printf("\nCOSTUMES: %d row(s) not at \$95.00\n", $costumeStale);

$fees = [
    'registration_cents'       => '0',
    'recital_first_cents'      => '10000',
    'recital_additional_cents' => '5000',
    'recital_due'              => '05-01',
    'costume_cents'            => '9500',
    'tights_cents'             => '1000',
    'costume_instalments'      => json_encode([
        ['month_day' => '11-01', 'percent' => 50],
        ['month_day' => '02-01', 'percent' => 50],
    ]),
];
echo "\nFEE SETTINGS\n";
foreach ($fees as $k => $v) {
    $cur = (string) Database::setting('studio.fee_' . $k);
    printf("  %-26s %-46s %s\n", $k, $v, $cur === $v ? '(unchanged)' : "(was: " . ($cur === '' ? '—' : $cur) . ")");
}

if (!$APPLY) {
    echo "\nNothing written. Re-run with --apply.\n";
    exit(0);
}

// ── Apply ─────────────────────────────────────────────────────────────

$now = date('Y-m-d H:i:s');

foreach ($reprice as $r) {
    Database::query(
        "UPDATE studio_class_series SET price_cents = ?, updated_at = ? WHERE tenant_id = ? AND id = ?",
        [$r['new'], $now, $TID, (int) $r['id']]
    );
}

foreach ($deactivate as $r) {
    Database::query(
        "UPDATE studio_class_series SET is_active = 0, updated_at = ? WHERE tenant_id = ? AND id = ?",
        [$now, $TID, (int) $r['id']]
    );
}

Database::query(
    "UPDATE studio_costumes SET cost_cents = 9500, updated_at = ? WHERE tenant_id = ? AND cost_cents <> 9500",
    [$now, $TID]
);

foreach ($fees as $k => $v) {
    Database::setSetting('studio.fee_' . $k, $v);
}

printf("\nDone: %d repriced, %d deactivated, %d costume row(s) set to \$95, %d fee settings written.\n",
    count($reprice), count($deactivate), $costumeStale, count($fees));
