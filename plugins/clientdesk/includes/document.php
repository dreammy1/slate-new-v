<?php
/**
 * Client Desk — branded printable document (invoice / quote).
 *
 * cd_document() renders a self-contained, professional document with the
 * business branding pulled from Slate settings. It ships its own print
 * stylesheet that hides the admin shell, so "Print / PDF" outputs only
 * the document — no sidebar, topbar, breadcrumbs or buttons.
 *
 * Usage:
 *   cd_document([
 *     'kind'       => 'INVOICE' | 'QUOTE',
 *     'number'     => 'INV-2026-0001',
 *     'status'     => 'sent',
 *     'currency'   => 'EUR',
 *     'items'      => [['desc'=>'…','amount_cents'=>8600], …],
 *     'total_cents'=> 26400,
 *     'client'     => $clientRow,
 *     'meta'       => [['Issued','1 Jun 2026'], ['Due','2 Jan 2027'], …],
 *     'intro'      => 'optional scope/notes text',
 *     'brief'      => 'optional requirements brief',
 *     'agreement'  => 'optional agreement copy',
 *     'pay_url'    => 'optional https://… online-pay link',
 *   ]);
 */

if (!function_exists('cd_document')) {

    function cd_doc_brand(): array {
        $accent = (string) Database::setting('brand_accent_color');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent = '#2563EB';
        return [
            'site'    => (string) (Database::setting('site_name') ?: 'Slate'),
            'name'    => (string) (Database::setting('business_name') ?: (Database::setting('site_name') ?: '')),
            'address' => (string) Database::setting('business_address'),
            'email'   => (string) (Database::setting('business_email') ?: Database::setting('smtp_from_email')),
            'phone'   => (string) Database::setting('business_phone'),
            'logo'    => (string) Database::setting('brand_logo_path'),
            'accent'  => $accent,
        ];
    }

    function cd_document(array $d): void {
        $b        = cd_doc_brand();
        $kind     = strtoupper($d['kind'] ?? 'INVOICE');
        $currency = $d['currency'] ?? 'USD';
        $items    = $d['items'] ?? [];
        $client   = $d['client'] ?? [];
        $fmt = fn($c) => $currency . ' ' . number_format(((int)$c) / 100, 2);

        $statusColors = [
            'paid'=>'#16A34A','sent'=>'#D97706','overdue'=>'#DC2626','draft'=>'#6B7280',
            'approved'=>'#16A34A','declined'=>'#DC2626','expired'=>'#6B7280',
        ];
        $sc = $statusColors[strtolower($d['status'] ?? '')] ?? '#6B7280';
        ?>
        <style>
        /* Print: hide the entire admin shell, show only the document. */
        @media print {
            body * { visibility: hidden !important; }
            #cd-doc, #cd-doc * { visibility: visible !important; }
            #cd-doc {
                position: absolute !important; inset: 0 !important;
                margin: 0 !important; width: 100% !important;
                box-shadow: none !important; border: 0 !important;
            }
            .cd-doc-actions { display: none !important; }
            @page { margin: 18mm 16mm; }
        }
        #cd-doc {
            --doc-accent: <?= $b['accent'] ?>;
            background: #fff; color: #1A1D21;
            max-width: 820px; margin: 0 auto;
            border: 1px solid var(--border); border-radius: var(--radius-lg);
            padding: 40px 44px; font-family: var(--font-sans);
        }
        #cd-doc .cd-doc-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; flex-wrap: wrap; }
        #cd-doc .cd-doc-brand { display: flex; align-items: center; gap: 14px; }
        #cd-doc .cd-doc-logo { width: 52px; height: 52px; border-radius: 12px; object-fit: cover; display: block; }
        #cd-doc .cd-doc-logo-fallback { width: 52px; height: 52px; border-radius: 12px; background: var(--doc-accent); color: #fff; display: flex; align-items: center; justify-content: center; font: 700 24px var(--font-display, sans-serif); }
        #cd-doc .cd-doc-biz-name { font: 700 20px/1.1 var(--font-display, sans-serif); color: #1A1D21; }
        #cd-doc .cd-doc-biz-sub { font-size: 12.5px; color: #6B7280; margin-top: 3px; white-space: pre-line; }
        #cd-doc .cd-doc-head-right { text-align: right; }
        #cd-doc .cd-doc-kind { font: 700 26px var(--font-display, sans-serif); letter-spacing: 0.04em; color: var(--doc-accent); }
        #cd-doc .cd-doc-number { font: 600 14px var(--font-mono, monospace); color: #1A1D21; margin-top: 2px; }
        #cd-doc .cd-doc-status { display: inline-block; margin-top: 8px; font: 600 11px var(--font-sans); letter-spacing: .05em; text-transform: uppercase; color: #fff; background: <?= $sc ?>; padding: 3px 10px; border-radius: 999px; }
        #cd-doc .cd-doc-rule { height: 3px; background: var(--doc-accent); border-radius: 2px; margin: 24px 0; }
        #cd-doc .cd-doc-parties { display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; margin-bottom: 28px; }
        #cd-doc .cd-doc-block-label { font: 600 10.5px var(--font-sans); letter-spacing: .07em; text-transform: uppercase; color: #9AA0A6; margin-bottom: 6px; }
        #cd-doc .cd-doc-block-body { font-size: 13.5px; line-height: 1.5; color: #2A2D31; }
        #cd-doc .cd-doc-block-body strong { font-weight: 600; color: #1A1D21; }
        #cd-doc .cd-doc-meta { text-align: right; font-size: 13px; }
        #cd-doc .cd-doc-meta-row { display: flex; justify-content: flex-end; gap: 16px; padding: 2px 0; }
        #cd-doc .cd-doc-meta-row span:first-child { color: #9AA0A6; }
        #cd-doc table.cd-doc-items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        #cd-doc table.cd-doc-items th { text-align: left; font: 600 10.5px var(--font-sans); letter-spacing: .06em; text-transform: uppercase; color: #9AA0A6; padding: 0 0 10px; border-bottom: 2px solid #ECECEC; }
        #cd-doc table.cd-doc-items th.amt, #cd-doc table.cd-doc-items td.amt { text-align: right; white-space: nowrap; }
        #cd-doc table.cd-doc-items td { padding: 12px 0; border-bottom: 1px solid #F0F0F0; font-size: 13.5px; color: #2A2D31; vertical-align: top; }
        #cd-doc table.cd-doc-items td.desc { padding-right: 24px; line-height: 1.5; }
        #cd-doc .cd-doc-totals { margin-top: 18px; margin-left: auto; width: 280px; }
        #cd-doc .cd-doc-total-row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; color: #2A2D31; }
        #cd-doc .cd-doc-total-grand { border-top: 2px solid #1A1D21; margin-top: 6px; padding-top: 12px; font: 700 17px var(--font-display, sans-serif); color: #1A1D21; }
        #cd-doc .cd-doc-section { margin-top: 30px; }
        #cd-doc .cd-doc-section h3 { font: 600 12px var(--font-sans); letter-spacing: .05em; text-transform: uppercase; color: #6B7280; margin: 0 0 8px; }
        #cd-doc .cd-doc-section .cd-doc-prose { font-size: 13px; line-height: 1.6; color: #3A3D41; white-space: pre-wrap; }
        #cd-doc .cd-doc-pay { display: inline-block; margin-top: 8px; background: var(--doc-accent); color: #fff; text-decoration: none; padding: 11px 20px; border-radius: 8px; font: 600 14px var(--font-sans); }
        #cd-doc .cd-doc-foot { margin-top: 40px; padding-top: 16px; border-top: 1px solid #ECECEC; font-size: 11.5px; color: #9AA0A6; text-align: center; }
        /* Phones: trim the heavy 44px side padding and let the 280px totals
           block go full-width so the document fits a 360px viewport. */
        @media (max-width: 480px) {
            #cd-doc { padding: 24px 18px; }
            #cd-doc .cd-doc-totals { width: 100%; margin-left: 0; }
        }
        </style>

        <div id="cd-doc" class="cd-fade">
            <div class="cd-doc-top">
                <div class="cd-doc-brand">
                    <?php if ($b['logo']): ?>
                        <img class="cd-doc-logo" src="<?= e(SLATE_URL . $b['logo']) ?>" alt="">
                    <?php else: ?>
                        <div class="cd-doc-logo-fallback"><?= e(mb_strtoupper(mb_substr($b['name'] ?: $b['site'], 0, 1))) ?></div>
                    <?php endif; ?>
                    <div>
                        <div class="cd-doc-biz-name"><?= e($b['name'] ?: $b['site']) ?></div>
                        <?php if ($b['address'] || $b['email'] || $b['phone']): ?>
                            <div class="cd-doc-biz-sub"><?php
                                $bits = array_filter([$b['address'], $b['email'], $b['phone']]);
                                echo e(implode("\n", $bits));
                            ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="cd-doc-head-right">
                    <div class="cd-doc-kind"><?= e($kind) ?></div>
                    <div class="cd-doc-number"><?= e($d['number'] ?? '') ?></div>
                    <?php if (!empty($d['status'])): ?>
                        <div class="cd-doc-status"><?= e(ucfirst($d['status'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="cd-doc-rule"></div>

            <div class="cd-doc-parties">
                <div>
                    <div class="cd-doc-block-label"><?= $kind === 'QUOTE' ? 'Prepared for' : 'Billed to' ?></div>
                    <div class="cd-doc-block-body">
                        <strong><?= e($client['name'] ?? '—') ?></strong><br>
                        <?php if (!empty($client['company'])): ?><?= e($client['company']) ?><br><?php endif; ?>
                        <?php if (!empty($client['email'])): ?><?= e($client['email']) ?><br><?php endif; ?>
                        <?php if (!empty($client['phone'])): ?><?= e($client['phone']) ?><?php endif; ?>
                    </div>
                </div>
                <div class="cd-doc-meta">
                    <?php foreach (($d['meta'] ?? []) as $row): ?>
                        <div class="cd-doc-meta-row"><span><?= e($row[0]) ?></span><span><?= e($row[1]) ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!empty($d['intro'])): ?>
                <div class="cd-doc-section" style="margin-top:0">
                    <div class="cd-doc-prose"><?= e($d['intro']) ?></div>
                </div>
            <?php endif; ?>

            <table class="cd-doc-items">
                <thead>
                    <tr><th>Description</th><th class="amt">Amount</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $li): ?>
                        <tr><td class="desc"><?= e($li['desc'] ?? '') ?></td><td class="amt"><?= e($fmt($li['amount_cents'] ?? 0)) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="cd-doc-totals">
                <div class="cd-doc-total-row cd-doc-total-grand"><span>Total</span><span><?= e($fmt($d['total_cents'] ?? 0)) ?></span></div>
            </div>

            <?php if (!empty($d['pay_url'])): ?>
                <div class="cd-doc-section cd-doc-actions">
                    <a class="cd-doc-pay" href="<?= e($d['pay_url']) ?>">Pay this invoice online →</a>
                </div>
            <?php endif; ?>

            <?php if (!empty($d['brief'])): ?>
                <div class="cd-doc-section">
                    <h3>Requirements brief</h3>
                    <div class="cd-doc-prose"><?= e($d['brief']) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($d['agreement'])): ?>
                <div class="cd-doc-section">
                    <h3>Agreement</h3>
                    <div class="cd-doc-prose"><?= e($d['agreement']) ?></div>
                </div>
            <?php endif; ?>

            <div class="cd-doc-foot">
                <?= e($b['name'] ?: $b['site']) ?> · <?= e($d['number'] ?? '') ?>
                <?php if ($b['email']): ?> · <?= e($b['email']) ?><?php endif; ?>
            </div>
        </div>
        <?php
    }
}
