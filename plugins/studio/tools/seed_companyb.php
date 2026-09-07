<?php
/**
 * Seed realistic demo data for the Studio plugin, modelled on Company B
 * Performing Arts Studio (Seaford, NY). Inserts into the live tenant so it shows
 * in the admin UI. Every created row is tagged meta.demo="companyb" for clean
 * removal (see cleanup_companyb.php). Idempotent: aborts if already seeded.
 *
 * Run:  php plugins/studio/tools/seed_companyb.php
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

use Slate\Services\Identity\ContactRepository;
use Slate\Tenancy\TenantContext;

$TAG = json_encode(['demo' => 'companyb']);

// Idempotency guard.
$already = Database::value(
    "SELECT COUNT(*) FROM contacts WHERE tenant_id = ? AND JSON_EXTRACT(meta, '$.demo') = 'companyb'",
    [current_tenant_id()]
);
if ((int) $already > 0) {
    echo "Demo data already present ({$already} tagged contacts). Nothing to do.\n";
    echo "Run cleanup_companyb.php first if you want to reseed.\n";
    exit(0);
}

$contacts = new ContactRepository(new TenantContext());
$tag = static function (string $table, int $id) use ($TAG): void {
    Database::update($table, ['meta' => $TAG], 'id = ?', [$id]);
};

// ── Instructors ──────────────────────────────────────────────
$instructors = [
    'Lori Marshall'  => 'lori@companybstudio.com',      // Founder & Director — vocal
    'Ryann Marshall' => 'ryann@companybstudio.com',     // Dance
    'Bianca Alvarez' => 'bianca@companybstudio.com',
    'Marcus Lee'     => 'marcus@companybstudio.com',
    'Priya Nair'     => 'priya@companybstudio.com',
];
$instructorId = [];
foreach ($instructors as $name => $email) {
    $c = $contacts->resolveOrCreate(['display_name' => $name, 'email' => $email]);
    $tag('contacts', $c->id);
    StudioAPI::assignContactRole($c->id, 'instructor');
    $instructorId[$name] = $c->id;
}
echo "instructors: " . count($instructorId) . "\n";

// ── Classes (Fall 2026 term) ─────────────────────────────────
$TERM_START = '2026-09-08';   // Monday
$TERM_END   = '2026-12-14';
$D = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

// [name, style, level|null, instructor, dayKey, start, end, capacity, price$, ageMin, ageMax]
$classes = [
    ['Beginner Ballet',            'ballet',          'beginner',     'Ryann Marshall', 'Mon', '16:00', '17:00', 15,  95, 6, 10],
    ['Intermediate Jazz',          'jazz',            'intermediate', 'Ryann Marshall', 'Tue', '17:00', '18:00', 15, 105, 9, 14],
    ['Advanced Tap',               'tap',             'advanced',     'Bianca Alvarez', 'Wed', '18:00', '19:15', 4,  115, 11, 17],
    ['Hip-Hop Level 1',            'hiphop',          'beginner',     'Marcus Lee',     'Mon', '17:00', '18:00', 18,  95, 7, 12],
    ['Hip-Hop Level 2',            'hiphop',          'intermediate', 'Marcus Lee',     'Wed', '17:00', '18:00', 18, 105, 10, 15],
    ['Contemporary',               'contemporary',    'intermediate', 'Bianca Alvarez', 'Thu', '17:00', '18:15', 14, 115, 11, 17],
    ['Musical Theatre',            'musical theatre', null,           'Lori Marshall',  'Fri', '16:30', '18:00', 16, 125, 8, 16],
    ['Vocal Training',             'vocal',           null,           'Lori Marshall',  'Tue', '18:00', '19:00', 10, 120, 9, 18],
    ['Acting',                     'acting',          null,           'Priya Nair',     'Fri', '18:00', '19:00', 14, 110, 9, 17],
    ['Broadway Styles',            'broadway',        'advanced',     'Priya Nair',     'Wed', '19:15', '20:30', 16, 130, 12, 18],
    ['Beginner Tap & Jazz (4-6)',  'tap',             'beginner',     'Ryann Marshall', 'Sat', '09:30', '10:30', 12,  85, 4, 6],
    ['NFL Cheer Dance',            'cheer',           null,           'Bianca Alvarez', 'Sat', '10:30', '11:30', 20,  90, 6, 14],
    ['Beginner Musical Theatre',   'musical theatre', 'beginner',     'Lori Marshall',  'Sat', '11:30', '12:30', 16,  95, 6, 12],
    ['Adult Hip-Hop',              'hiphop',          null,           'Marcus Lee',     'Thu', '21:00', '22:00', 20,  80, 18, 99],
    ['Adult Pilates',              'pilates',         null,           'Priya Nair',     'Thu', '20:30', '21:30', 15,  75, 18, 99],
];
$seriesId = [];
$totalOcc = 0;
foreach ($classes as [$name, $style, $level, $instr, $dayKey, $start, $end, $cap, $price, $ageMin, $ageMax]) {
    $sid = StudioAPI::createClassSeries([
        'name' => $name, 'style' => $style, 'level' => $level,
        'instructor_id' => $instructorId[$instr], 'capacity' => $cap,
        'day_of_week' => $D[$dayKey], 'start_time' => $start, 'end_time' => $end,
        'session_start' => $TERM_START, 'session_end' => $TERM_END,
        'price_cents' => $price * 100, 'currency' => 'USD',
        'age_min' => $ageMin, 'age_max' => $ageMax,
    ]);
    $tag('studio_class_series', $sid);
    $seriesId[$name] = $sid;
    $totalOcc += StudioAPI::generateOccurrences($sid);
}
echo "classes: " . count($seriesId) . " (occurrences generated: {$totalOcc})\n";

// ── Families + students ──────────────────────────────────────
$families = [
    ['Alex Morgan',        'alex.morgan@example.com',    ['Sam Morgan', 'Riley Morgan']],
    ['Jennifer Russo',     'jen.russo@example.com',      ['Sophia Russo']],
    ['Michael Delgado',    'm.delgado@example.com',      ['Mia Delgado', 'Lucas Delgado']],
    ['Danielle Kim',       'danielle.kim@example.com',   ['Ava Kim']],
    ['Christopher Nolan',  'chris.nolan@example.com',    ['Grace Nolan', 'Ethan Nolan']],
    ['Amanda Pierce',      'amanda.pierce@example.com',  ['Chloe Pierce']],
    ['Robert Callahan',    'rob.callahan@example.com',   ['Isabella Callahan']],
    ['Nicole Ferraro',     'nicole.ferraro@example.com', ['Emma Ferraro', 'Jack Ferraro']],
];
$studentId = [];
foreach ($families as [$parentName, $parentEmail, $students]) {
    $parent = $contacts->resolveOrCreate(['display_name' => $parentName, 'email' => $parentEmail]);
    $tag('contacts', $parent->id);
    $kidIds = [];
    foreach ($students as $s) {
        $kid = $contacts->create(['display_name' => $s]);
        $tag('contacts', $kid->id);
        $studentId[$s] = $kid->id;
        $kidIds[] = $kid->id;
    }
    $fid = StudioAPI::createFamily($parent->id, $kidIds);
    $tag('studio_families', $fid);
}
echo "families: " . count($families) . " (students: " . count($studentId) . ")\n";

// ── Enrollments (mix of multi-class, siblings, and a waitlist) ──
$enrollPlan = [
    'Sam Morgan'        => ['Beginner Ballet', 'Hip-Hop Level 1'],
    'Riley Morgan'      => ['Beginner Tap & Jazz (4-6)'],
    'Sophia Russo'      => ['Intermediate Jazz', 'Contemporary', 'Musical Theatre'],
    'Mia Delgado'       => ['Advanced Tap', 'Contemporary'],
    'Lucas Delgado'     => ['Hip-Hop Level 2'],
    'Ava Kim'           => ['Vocal Training', 'Musical Theatre'],
    'Grace Nolan'       => ['Advanced Tap', 'Broadway Styles'],
    'Ethan Nolan'       => ['Advanced Tap', 'Acting'],
    'Chloe Pierce'      => ['Beginner Ballet'],
    'Isabella Callahan' => ['Advanced Tap', 'Contemporary'],
    'Emma Ferraro'      => ['Advanced Tap', 'Intermediate Jazz'],
    'Jack Ferraro'      => ['Hip-Hop Level 1'],
];
$enrolled = 0; $waitlisted = 0;
foreach ($enrollPlan as $student => $classNames) {
    foreach ($classNames as $cn) {
        $res = StudioAPI::enrollStudent($studentId[$student], $seriesId[$cn]);
        if (!empty($res['ok']) && !empty($res['id'])) {
            $tag('studio_enrollments', (int) $res['id']);
            $res['status'] === 'waitlist' ? $waitlisted++ : $enrolled++;
        }
    }
}
echo "enrollments: active/trial={$enrolled}, waitlisted={$waitlisted}\n";
echo "\nDONE — Company B demo data seeded into tenant " . current_tenant_id() . ".\n";
