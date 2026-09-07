<?php
/**
 * Shop — Reports admin page.
 * Revenue, top products, order status breakdown, daily trend.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('shop.view_reports');

$pageTitle  = __('shop_reports', 'Reports');
$currentNav = 'shop-reports';

$tid      = current_tenant_id();
$currency = Database::setting('shop.currency') ?: 'USD';

// Date range picker
$period = $_GET['period'] ?? '30d';
switch ($period) {
    case '7d':  $from = date('Y-m-d', strtotime('-6 days'));  $to = date('Y-m-d'); break;
    case '90d': $from = date('Y-m-d', strtotime('-89 days')); $to = date('Y-m-d'); break;
    case 'mtd': $from = date('Y-m-01'); $to = date('Y-m-d'); break;
    case 'ytd': $from = date('Y-01-01'); $to = date('Y-m-d'); break;
    default:    $from = date('Y-m-d', strtotime('-29 days')); $to = date('Y-m-d'); break;
}
if (isset($_GET['from'], $_GET['to'])) {
    // Accept only strict ISO YYYY-MM-DD. Bad input falls back to the
    // current period default so the page doesn't 500 when someone fat-fingers
    // the URL or pastes a malformed date.
    $rawFrom = (string)$_GET['from'];
    $rawTo   = (string)$_GET['to'];
    $valid = function(string $s): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    };
    if ($valid($rawFrom) && $valid($rawTo)) {
        $from   = $rawFrom;
        $to     = $rawTo;
        $period = 'custom';
    }
    // Otherwise: leave $from / $to as the period-derived defaults set above.
}

// Summary
$summary = ShopAPI::revenueSummary($from, $to);
$topProducts = ShopAPI::topProducts(10, $from, $to);

// Status breakdown
$statusBreakdown = Database::rows(
    "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total),0) AS revenue
       FROM shop_orders WHERE tenant_id = ? AND DATE(created_at) BETWEEN ? AND ?
       GROUP BY status ORDER BY cnt DESC",
    [$tid, $from, $to]
);

// Daily revenue for chart (last N days)
$dailyRevenue = Database::rows(
    "SELECT DATE(created_at) AS day, COALESCE(SUM(total),0) AS revenue, COUNT(*) AS orders
       FROM shop_orders
      WHERE tenant_id = ? AND status IN ('processing','completed')
        AND DATE(created_at) BETWEEN ? AND ?
      GROUP BY DATE(created_at) ORDER BY day ASC",
    [$tid, $from, $to]
);

// Fill missing days with 0
$dailyMap = [];
foreach ($dailyRevenue as $row) { $dailyMap[$row['day']] = $row; }
$filledDays = [];
$d = new DateTime($from);
$end = new DateTime($to);
while ($d <= $end) {
    $key = $d->format('Y-m-d');
    $filledDays[] = [
        'day'     => $key,
        'revenue' => isset($dailyMap[$key]) ? (float)$dailyMap[$key]['revenue'] : 0,
        'orders'  => isset($dailyMap[$key]) ? (int)$dailyMap[$key]['orders'] : 0,
    ];
    $d->modify('+1 day');
}

require $root . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Shop', 'href' => plugin_url('shop', 'admin/index.php')],
    ['label' => 'Reports'],
]); ?>

<div class="page-header">
    <h1>Reports</h1>
    <form method="get" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
        <?php $periods = ['7d'=>'Last 7 days','30d'=>'Last 30 days','90d'=>'Last 90 days','mtd'=>'Month to date','ytd'=>'Year to date']; ?>
        <select name="period" onchange="this.form.submit()">
            <?php foreach ($periods as $k => $label): ?>
                <option value="<?= $k ?>" <?= $period === $k ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= e($from) ?>">
        <span>to</span>
        <input type="date" name="to" value="<?= e($to) ?>">
        <button type="submit" class="btn">Apply</button>
    </form>
</div>
<p class="text-muted text-sm" style="margin-top:-0.5rem; margin-bottom:1rem;">
    <?= e($from) ?> — <?= e($to) ?>
</p>

<!-- KPI row -->
<div class="flex gap-2" style="flex-wrap:wrap; margin-bottom:1.5rem;">
    <?php
    $kpis = [
        ['label' => 'Revenue',    'value' => ShopAPI::formatMoney((float)($summary['revenue'] ?? 0), $currency)],
        ['label' => 'Orders',     'value' => number_format((float)($summary['order_count'] ?? 0))],
        ['label' => 'Avg. Order', 'value' => ShopAPI::formatMoney((float)($summary['avg_order'] ?? 0), $currency)],
        ['label' => 'Tax',        'value' => ShopAPI::formatMoney((float)($summary['taxes'] ?? 0), $currency)],
        ['label' => 'Shipping',   'value' => ShopAPI::formatMoney((float)($summary['shipping'] ?? 0), $currency)],
        ['label' => 'Discounts',  'value' => ShopAPI::formatMoney((float)($summary['discounts'] ?? 0), $currency)],
    ];
    foreach ($kpis as $kpi): ?>
    <div class="card" style="flex:1; min-width:130px;">
        <div class="text-muted text-sm"><?= e($kpi['label']) ?></div>
        <div style="font-size:1.4rem; font-weight:700; margin-top:0.2rem;"><?= e($kpi['value']) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Revenue chart (plain HTML/CSS bar chart — no CDN dependencies) -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header"><h2>Daily Revenue</h2></div>
    <?php if (empty($filledDays) || max(array_column($filledDays, 'revenue')) == 0): ?>
        <p class="text-muted text-sm">No revenue data for this period.</p>
    <?php else:
        $maxRev = max(array_column($filledDays, 'revenue'));
        $maxRev = $maxRev > 0 ? $maxRev : 1;
        $showLabel = count($filledDays) <= 31;
    ?>
    <div style="display:flex; align-items:flex-end; gap:2px; height:140px; padding:0 0.5rem; overflow-x:auto;">
        <?php foreach ($filledDays as $day): ?>
        <?php $h = max(2, round(($day['revenue'] / $maxRev) * 120)); ?>
        <div style="flex:1; min-width:6px; display:flex; flex-direction:column; align-items:center;"
             title="<?= e($day['day']) ?>: <?= ShopAPI::formatMoney($day['revenue'], $currency) ?> (<?= $day['orders'] ?> orders)">
            <div style="width:100%; height:<?= $h ?>px; background:var(--accent); border-radius:2px 2px 0 0;
                        transition:opacity 0.2s;" onmouseover="this.style.opacity=0.7" onmouseout="this.style.opacity=1"></div>
            <?php if ($showLabel): ?>
            <div class="text-muted" style="font-size:9px; margin-top:2px; white-space:nowrap;">
                <?= date('d', strtotime($day['day'])) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="flex" style="justify-content:space-between; padding:0 0.5rem;">
        <span class="text-muted text-sm"><?= e($from) ?></span>
        <span class="text-muted text-sm"><?= e($to) ?></span>
    </div>
    <?php endif; ?>
</div>

<div class="flex gap-2" style="flex-wrap:wrap; align-items:flex-start;">

    <!-- Top products -->
    <div style="flex:2; min-width:280px;">
        <div class="card">
            <div class="card-header"><h2>Top Products</h2></div>
            <?php if (empty($topProducts)): ?>
                <p class="text-muted text-sm">No sales data for this period.</p>
            <?php else: ?>
                <?php
                $maxQty = max(array_column($topProducts, 'qty_sold'));
                $maxQty = $maxQty > 0 ? $maxQty : 1;
                ?>
                <?php foreach ($topProducts as $i => $p): ?>
                <div style="padding:0.4rem 0; border-bottom:1px solid var(--border);">
                    <div class="flex items-center gap-2">
                        <span class="text-muted" style="width:1.4rem;"><?= $i+1 ?>.</span>
                        <div style="flex:1;">
                            <div><?= e($p['product_name']) ?></div>
                            <div style="height:6px; background:var(--border); border-radius:3px; margin-top:4px;">
                                <div style="height:6px; background:var(--accent); border-radius:3px;
                                            width:<?= round(($p['qty_sold']/$maxQty)*100) ?>%;"></div>
                            </div>
                        </div>
                        <div style="text-align:right; white-space:nowrap;">
                            <div style="font-weight:600;"><?= e(ShopAPI::formatMoney((float)$p['revenue'], $currency)) ?></div>
                            <div class="text-muted text-sm"><?= (int)$p['qty_sold'] ?> sold</div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status breakdown -->
    <div style="flex:1; min-width:220px;">
        <div class="card">
            <div class="card-header"><h2>Orders by Status</h2></div>
            <?php if (empty($statusBreakdown)): ?>
                <p class="text-muted text-sm">No orders in this period.</p>
            <?php else: ?>
                <?php foreach ($statusBreakdown as $row): ?>
                <div class="flex items-center gap-2" style="padding:0.4rem 0; border-bottom:1px solid var(--border);">
                    <span class="badge <?= ShopAPI::statusBadgeClass($row['status']) ?>">
                        <?= e($row['status']) ?>
                    </span>
                    <div style="flex:1;"></div>
                    <div style="text-align:right;">
                        <div style="font-weight:600;"><?= (int)$row['cnt'] ?> orders</div>
                        <div class="text-muted text-sm"><?= e(ShopAPI::formatMoney((float)$row['revenue'], $currency)) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require $root . '/admin/partials/footer.php'; ?>
