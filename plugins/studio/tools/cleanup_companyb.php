<?php
/**
 * Remove all Company B demo data (meta.demo="companyb") and its dependents.
 *
 * DRY RUN BY DEFAULT — this changed. The tool used to delete the moment it was
 * invoked, with no preview, which is how a single run took the entire class
 * catalog (all 15 series carried the demo tag) when only the families were
 * meant to go.
 *
 *   php plugins/studio/tools/cleanup_companyb.php            # preview
 *   php plugins/studio/tools/cleanup_companyb.php --apply    # delete
 *
 * Two passes, and the second is the important one:
 *
 *  1. TAG sweep — collect ids where meta.demo='companyb' and delete their
 *     dependents. This is what the tool always did.
 *
 *  2. ORPHAN sweep — delete studio rows whose contact/series/family no longer
 *     exists. Pass 1 alone works exactly once: it reaches studio rows only
 *     THROUGH the tagged ids, so if the contacts go first — by hand, by a
 *     partial run, by a re-seed — every studio row that referenced them is
 *     stranded and this tool can never see them again. Company B's tenant
 *     reached 22 of 29 active enrolments pointing at students with no contacts
 *     row, and the fee generator then proposed charges against them.
 *
 * Pass 2 keys off referential reality rather than a tag, so cleanup is
 * self-healing and safe to re-run. purge_studio_orphans.php does the same
 * sweep standalone, for when the demo tag was never involved.
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

$APPLY = in_array('--apply', $argv, true);
$tid   = current_tenant_id();

$ids = static fn (string $t) => array_map('intval', array_column(
    Database::rows("SELECT id FROM {$t} WHERE tenant_id = ? AND JSON_EXTRACT(meta,'$.demo')='companyb'", [$tid]), 'id'));

$contactIds = $ids('contacts');
$seriesIds  = $ids('studio_class_series');
$familyIds  = $ids('studio_families');
$enrollIds  = $ids('studio_enrollments');
$recitalIds = $ids('studio_recitals');

$in = static fn (array $a) => $a ? '(' . implode(',', $a) . ')' : '(0)';

$pieceIds = $recitalIds
    ? array_map('intval', array_column(Database::rows(
        "SELECT id FROM studio_recital_pieces WHERE tenant_id = ? AND recital_id IN (" . implode(',', $recitalIds) . ")",
        [$tid]), 'id'))
    : [];

echo $APPLY ? "APPLYING — tenant {$tid}\n\n" : "DRY RUN (nothing deleted) — tenant {$tid}\n\n";

printf("tagged: contacts=%d series=%d families=%d enrolments=%d recitals=%d\n\n",
    count($contactIds), count($seriesIds), count($familyIds), count($enrollIds), count($recitalIds));

if ($seriesIds) {
    echo 'NOTE: ' . count($seriesIds) . " class series carry the demo tag and WILL be removed,\n"
       . "      along with their occurrences, enrolments and attendance. Re-seed with\n"
       . "      seed_companyb.php, then re-run apply_companyb_policies.php to restore\n"
       . "      real pricing.\n\n";
}

/**
 * Pass 1 — by tag. Children before parents.
 * studio_fees is included: it did not exist when this tool was written, so a
 * cleanup used to leave demo fee rows behind pointing at deleted families.
 */
$pass1 = [
    'studio_costumes'        => "tenant_id={$tid} AND (piece_id IN " . $in($pieceIds) . " OR student_id IN " . $in($contactIds) . ")",
    'studio_fees'            => "tenant_id={$tid} AND (family_id IN " . $in($familyIds) . " OR student_id IN " . $in($contactIds) . " OR series_id IN " . $in($seriesIds) . " OR recital_id IN " . $in($recitalIds) . ")",
    'studio_recital_pieces'  => "tenant_id={$tid} AND (recital_id IN " . $in($recitalIds) . " OR series_id IN " . $in($seriesIds) . ")",
    'studio_recital_tickets' => "tenant_id={$tid} AND recital_id IN " . $in($recitalIds),
    'studio_recitals'        => "tenant_id={$tid} AND id IN " . $in($recitalIds),
    'studio_attendance'      => "tenant_id={$tid} AND student_id IN " . $in($contactIds),
    'studio_class_occurrences' => "tenant_id={$tid} AND series_id IN " . $in($seriesIds),
    'studio_enrollments'     => "tenant_id={$tid} AND (series_id IN " . $in($seriesIds) . " OR student_id IN " . $in($contactIds) . " OR id IN " . $in($enrollIds) . ")",
    'studio_class_series'    => "tenant_id={$tid} AND id IN " . $in($seriesIds),
    'studio_family_members'  => "tenant_id={$tid} AND (family_id IN " . $in($familyIds) . " OR contact_id IN " . $in($contactIds) . ")",
    'studio_families'        => "tenant_id={$tid} AND id IN " . $in($familyIds),
    'studio_contact_roles'   => "tenant_id={$tid} AND contact_id IN " . $in($contactIds),
    'contact_emails'         => "tenant_id={$tid} AND contact_id IN " . $in($contactIds),
    'contact_phones'         => "tenant_id={$tid} AND contact_id IN " . $in($contactIds),
    'contacts'               => "tenant_id={$tid} AND id IN " . $in($contactIds),
];

$del = 0;
echo "pass 1 — by demo tag:\n";
foreach ($pass1 as $table => $where) {
    $n = (int) Database::value("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
    if ($n > 0) { printf("  %-26s %d\n", $table, $n); }
    $del += $n;
    if ($APPLY && $n > 0) { Database::query("DELETE FROM `{$table}` WHERE {$where}"); }
}
printf("  %-26s %d\n\n", 'subtotal', $del);

/**
 * Pass 2 — by referential reality. Anything whose person no longer exists,
 * however it got that way. Runs AFTER pass 1 so it also mops up rows that
 * pass 1 has just orphaned by deleting their contacts.
 */
$pass2 = [
    'studio_costumes'       => "co.student_id IS NOT NULL AND c.id IS NULL|studio_costumes co LEFT JOIN contacts c ON c.id=co.student_id|co",
    'studio_fees'           => "f.student_id IS NOT NULL AND c.id IS NULL|studio_fees f LEFT JOIN contacts c ON c.id=f.student_id|f",
    'studio_attendance'     => "c.id IS NULL|studio_attendance a LEFT JOIN contacts c ON c.id=a.student_id|a",
    'studio_enrollments'    => "c.id IS NULL|studio_enrollments e LEFT JOIN contacts c ON c.id=e.student_id|e",
    'studio_family_members' => "c.id IS NULL|studio_family_members fm LEFT JOIN contacts c ON c.id=fm.contact_id|fm",
    'studio_contact_roles'  => "c.id IS NULL|studio_contact_roles r LEFT JOIN contacts c ON c.id=r.contact_id|r",
    'studio_families'       => "c.id IS NULL|studio_families fa LEFT JOIN contacts c ON c.id=fa.primary_parent_id|fa",
];

$orphans = 0;
echo "pass 2 — orphaned rows (contact no longer exists):\n";
foreach ($pass2 as $table => $spec) {
    [$cond, $from, $alias] = explode('|', $spec);
    $sel = "SELECT {$alias}.id FROM {$from} WHERE {$alias}.tenant_id = {$tid} AND {$cond}";
    $rows = array_map('intval', array_column(Database::rows($sel), 'id'));
    if ($rows) { printf("  %-26s %d\n", $table, count($rows)); }
    $orphans += count($rows);
    if ($APPLY && $rows) {
        Database::query("DELETE FROM `{$table}` WHERE tenant_id = {$tid} AND id IN (" . implode(',', $rows) . ")");
    }
}
printf("  %-26s %d\n\n", 'subtotal', $orphans);

if ($APPLY) {
    // Re-probe: the tenant must come out referentially clean, or pass 2 missed
    // something and the next fee run would bill a ghost.
    $left = 0;
    foreach ($pass2 as $spec) {
        [$cond, $from, $alias] = explode('|', $spec);
        $left += (int) Database::value("SELECT COUNT(*) FROM {$from} WHERE {$alias}.tenant_id = {$tid} AND {$cond}");
    }
    printf("Removed %d rows (%d by tag, %d orphaned).\n", $del + $orphans, $del, $orphans);
    echo $left === 0 ? "✓ no orphaned studio rows remain\n"
                     : "!! {$left} orphaned rows still present — investigate\n";
} else {
    printf("Would remove %d rows (%d by tag, %d orphaned).\n", $del + $orphans, $del, $orphans);
    echo "\nDry run only. Re-run with --apply to delete.\n";
}
