<?php
/**
 * Client Desk public API.
 *
 * Other plugins (and the plugin's own pages) call these instead of
 * touching clientdesk_* tables directly. All methods scope to the
 * current tenant. Use as \ClientDeskAPI::method(...).
 */

class ClientDeskAPI {

    /* ---- uploads ---- */

    /**
     * Allow-list of options for client/staff file uploads. Uploads::handle
     * only enforces type checks when these arrays are present, so passing
     * this is what prevents a portal user from storing executable/markup
     * files (.php/.phtml/.html/.svg) that could yield RCE or stored XSS.
     * The extension allow-list is the hard gate (a .php is rejected even if
     * finfo reports application/octet-stream); covers the document, image,
     * and archive types a project portal needs.
     */
    public static function uploadOpts(int $maxBytes = 20971520): array {
        return [
            'max_bytes'     => $maxBytes,
            'allowed_exts'  => [
                'pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf',
                'jpg','jpeg','png','gif','webp','heic',
                'zip','psd','ai','sketch','fig','indd','eps',
                'mp4','mov','mp3','wav',
            ],
            'allowed_mimes' => [
                'application/pdf','application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain','text/csv','application/rtf','application/x-rtf',
                'image/jpeg','image/png','image/gif','image/webp','image/heic',
                'application/zip','application/x-zip-compressed',
                'image/vnd.adobe.photoshop','application/postscript','application/octet-stream',
                'video/mp4','video/quicktime','audio/mpeg','audio/wav','audio/x-wav',
            ],
        ];
    }

    /* ---- phases ---- */

    public static function phases(): array {
        return [
            'onboarding'  => 'Onboarding',
            'design'      => 'Design',
            'development' => 'Development',
            'review'      => 'Review',
            'revisions'   => 'Revisions',
            'launch'      => 'Launch',
            'complete'    => 'Complete',
        ];
    }

    public static function phaseLabel(string $phase): string {
        $m = self::phases();
        return $m[$phase] ?? ucfirst($phase);
    }

    /* ---- clients ---- */

    public static function clients(array $filters = []): array {
        $sql    = "SELECT * FROM clientdesk_clients WHERE tenant_id = ?";
        $params = [current_tenant_id()];
        if (!empty($filters['source'])) { $sql .= " AND source = ?"; $params[] = $filters['source']; }
        if (!empty($filters['status'])) { $sql .= " AND status = ?"; $params[] = $filters['status']; }
        $sql .= " ORDER BY created_at DESC";
        return Database::rows($sql, $params);
    }

    public static function client(int $id): ?array {
        return Database::row(
            "SELECT * FROM clientdesk_clients WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        );
    }

    public static function clientByToken(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) return null;
        return Database::row(
            "SELECT * FROM clientdesk_clients WHERE access_token = ? AND tenant_id = ? AND status != 'archived'",
            [$token, current_tenant_id()]
        );
    }

    public static function newAccessToken(): string {
        return bin2hex(random_bytes(20)); // 40 hex chars
    }

    /**
     * Resolve the client row for a signed-in customer, auto-linking when
     * safe. Order of precedence:
     *   1. A client already linked to this customer_id.
     *   2. An UNLINKED client whose email matches the customer's email —
     *      bind it to this customer and return it (self-service linking).
     * Returns null if neither applies.
     */
    public static function clientForCustomer(int $customerId, ?string $customerEmail = null): ?array {
        $t = current_tenant_id();
        $linked = Database::row(
            "SELECT * FROM clientdesk_clients WHERE customer_id = ? AND tenant_id = ?",
            [$customerId, $t]);
        if ($linked) return $linked;

        if ($customerEmail !== null && $customerEmail !== '') {
            $match = Database::row(
                "SELECT * FROM clientdesk_clients
                  WHERE customer_id IS NULL AND tenant_id = ? AND email IS NOT NULL
                    AND LOWER(email) = LOWER(?) LIMIT 1",
                [$t, $customerEmail]);
            if ($match) {
                Database::update('clientdesk_clients', ['customer_id' => $customerId],
                    'id = ? AND tenant_id = ?', [$match['id'], $t]);
                $match['customer_id'] = $customerId;
                return $match;
            }
        }
        return null;
    }

    /**
     * Link a client to a customer account by matching email, if the client
     * is currently unlinked. Used on customer_registered. Returns linked id.
     */
    public static function autoLinkByEmail(int $customerId): ?int {
        $cust = Database::row("SELECT email FROM customers WHERE id = ? AND tenant_id = ?", [$customerId, current_tenant_id()]);
        if (!$cust || empty($cust['email'])) return null;
        $client = self::clientForCustomer($customerId, $cust['email']);
        return $client ? (int)$client['id'] : null;
    }

    /* ---- projects ---- */

    public static function projectsForClient(int $clientId): array {
        return Database::rows(
            "SELECT * FROM clientdesk_projects WHERE client_id = ? AND tenant_id = ? ORDER BY created_at DESC",
            [$clientId, current_tenant_id()]
        );
    }

    public static function project(int $id): ?array {
        return Database::row(
            "SELECT * FROM clientdesk_projects WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        );
    }

    public static function milestones(int $projectId): array {
        return Database::rows(
            "SELECT * FROM clientdesk_milestones WHERE project_id = ? AND tenant_id = ? ORDER BY sort_order, id",
            [$projectId, current_tenant_id()]
        );
    }

    public static function activity(int $projectId, int $limit = 20): array {
        return Database::rows(
            "SELECT * FROM clientdesk_activity WHERE project_id = ? AND tenant_id = ?
              ORDER BY created_at DESC LIMIT " . max(1, min(100, $limit)),
            [$projectId, current_tenant_id()]
        );
    }

    public static function logActivity(int $projectId, string $body, ?string $author = null): int {
        return Database::insert('clientdesk_activity', [
            'tenant_id'  => current_tenant_id(),
            'project_id' => $projectId,
            'body'       => mb_substr($body, 0, 500),
            'author'     => $author !== null ? mb_substr($author, 0, 120) : null,
        ]);
    }

    /* ---- intake / questionnaire ---- */

    public static function intake(int $projectId): ?array {
        $row = Database::row(
            "SELECT * FROM clientdesk_intake WHERE project_id = ? AND tenant_id = ?",
            [$projectId, current_tenant_id()]
        );
        if ($row && $row['answers'] !== null) {
            $row['answers_decoded'] = json_decode($row['answers'], true) ?: [];
        } else if ($row) {
            $row['answers_decoded'] = [];
        }
        return $row;
    }

    public static function saveIntake(int $projectId, array $answers, bool $submitted = false): void {
        $existing = Database::row(
            "SELECT id FROM clientdesk_intake WHERE project_id = ? AND tenant_id = ?",
            [$projectId, current_tenant_id()]
        );
        $payload = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($existing) {
            $data = ['answers' => $payload, 'updated_at' => slate_db_now()];
            if ($submitted) $data['submitted_at'] = slate_db_now();
            Database::update('clientdesk_intake', $data, 'id = ?', [$existing['id']]);
        } else {
            Database::insert('clientdesk_intake', [
                'tenant_id'    => current_tenant_id(),
                'project_id'   => $projectId,
                'answers'      => $payload,
                'submitted_at' => $submitted ? slate_db_now() : null,
            ]);
        }
    }

    /** The questionnaire field definitions. Drives both form + brief. */
    public static function intakeFields(): array {
        return [
            'goals'       => ['label' => 'Project goals & needs', 'type' => 'textarea'],
            'site_type'   => ['label' => 'Type of website',       'type' => 'text'],
            'references'  => ['label' => 'Reference / inspiration links', 'type' => 'textarea'],
            'logo'        => ['label' => 'Logo (have one / need design?)', 'type' => 'text'],
            'domain'      => ['label' => 'Domain (owned? registrar? needs buying?)', 'type' => 'text'],
            'hosting'     => ['label' => 'Hosting (provider? needs setup?)', 'type' => 'text'],
            'gmb'         => ['label' => 'Google My Business (existing? needs creating?)', 'type' => 'text'],
            'seo'         => ['label' => 'SEO knowledge / expectations', 'type' => 'text'],
            'layout'      => ['label' => 'Layout preferences', 'type' => 'textarea'],
            'palette'     => ['label' => 'Color palette (hex / description)', 'type' => 'text'],
            'pages'       => ['label' => 'Required pages / features', 'type' => 'textarea'],
            'budget'      => ['label' => 'Budget range', 'type' => 'text'],
            'timeline'    => ['label' => 'Desired timeline / launch date', 'type' => 'text'],
            'content'     => ['label' => 'Is your content (text, images) ready, or do you need help?', 'type' => 'textarea'],
            'languages'   => ['label' => 'Languages the site should support', 'type' => 'text'],
            'integrations'=> ['label' => 'Must-have features / integrations (booking, payments, chat…)', 'type' => 'textarea'],
            'avoid'       => ['label' => 'Sites or styles you dislike / want to avoid', 'type' => 'textarea'],
            'contact'     => ['label' => 'Best way & time to reach you', 'type' => 'text'],
        ];
    }

    /* ---- assignments ---- */

    public static function assignments(int $projectId): array {
        return Database::rows(
            "SELECT a.*, u.name AS user_name, u.email AS user_email
               FROM clientdesk_assignments a
               JOIN users u ON u.id = a.user_id
              WHERE a.project_id = ? AND a.tenant_id = ?
              ORDER BY u.name",
            [$projectId, current_tenant_id()]
        );
    }

    public static function projectsForUser(int $userId): array {
        return Database::rows(
            "SELECT p.* FROM clientdesk_projects p
               JOIN clientdesk_assignments a ON a.project_id = p.id
              WHERE a.user_id = ? AND p.tenant_id = ?
              ORDER BY p.created_at DESC",
            [$userId, current_tenant_id()]
        );
    }

    /* ---- invoices ---- */

    public static function invoicesForClient(int $clientId): array {
        return Database::rows(
            "SELECT * FROM clientdesk_invoices WHERE client_id = ? AND tenant_id = ? ORDER BY created_at DESC",
            [$clientId, current_tenant_id()]
        );
    }

    public static function invoice(int $id): ?array {
        $row = Database::row(
            "SELECT * FROM clientdesk_invoices WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        );
        if ($row) {
            $row['line_items_decoded'] = $row['line_items'] !== null
                ? (json_decode($row['line_items'], true) ?: []) : [];
        }
        return $row;
    }

    public static function nextInvoiceNumber(): string {
        $n = (int) Database::value(
            "SELECT COUNT(*) FROM clientdesk_invoices WHERE tenant_id = ?",
            [current_tenant_id()]
        );
        return 'INV-' . date('Y') . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
    }

    public static function formatMoney(int $cents, string $currency = 'USD'): string {
        return $currency . ' ' . number_format($cents / 100, 2);
    }

    /* ---- tickets ---- */

    public static function ticketsForClient(int $clientId): array {
        return Database::rows(
            "SELECT * FROM clientdesk_tickets WHERE client_id = ? AND tenant_id = ? ORDER BY updated_at DESC",
            [$clientId, current_tenant_id()]
        );
    }

    public static function ticket(int $id): ?array {
        return Database::row(
            "SELECT * FROM clientdesk_tickets WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        );
    }

    public static function ticketMessages(int $ticketId): array {
        return Database::rows(
            "SELECT * FROM clientdesk_ticket_messages WHERE ticket_id = ? AND tenant_id = ? ORDER BY created_at",
            [$ticketId, current_tenant_id()]
        );
    }

    public static function addTicketMessage(int $ticketId, string $authorType, ?string $authorName, string $body): int {
        $id = Database::insert('clientdesk_ticket_messages', [
            'tenant_id'   => current_tenant_id(),
            'ticket_id'   => $ticketId,
            'author_type' => $authorType === 'staff' ? 'staff' : 'client',
            'author_name' => $authorName !== null ? mb_substr($authorName, 0, 120) : null,
            'body'        => $body,
        ]);
        Database::update('clientdesk_tickets',
            ['updated_at' => slate_db_now()], 'id = ?', [$ticketId]);
        return $id;
    }

    /* ---- quotes / proposals ---- */

    public static function quotesForClient(int $clientId): array {
        return Database::rows(
            "SELECT * FROM clientdesk_quotes WHERE client_id = ? AND tenant_id = ? ORDER BY created_at DESC",
            [$clientId, current_tenant_id()]);
    }

    public static function quote(int $id): ?array {
        $row = Database::row("SELECT * FROM clientdesk_quotes WHERE id = ? AND tenant_id = ?", [$id, current_tenant_id()]);
        if ($row) $row['line_items_decoded'] = $row['line_items'] !== null ? (json_decode($row['line_items'], true) ?: []) : [];
        return $row;
    }

    public static function nextQuoteNumber(): string {
        $n = (int) Database::value("SELECT COUNT(*) FROM clientdesk_quotes WHERE tenant_id = ?", [current_tenant_id()]);
        return 'QT-' . date('Y') . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
    }

    /* ---- files / deliverables ---- */

    public static function files(int $projectId, ?bool $clientVisibleOnly = null): array {
        $sql = "SELECT * FROM clientdesk_files WHERE project_id = ? AND tenant_id = ?";
        $params = [$projectId, current_tenant_id()];
        if ($clientVisibleOnly === true) $sql .= " AND visible_to_client = 1";
        $sql .= " ORDER BY created_at DESC";
        return Database::rows($sql, $params);
    }

    public static function file(int $id): ?array {
        return Database::row("SELECT * FROM clientdesk_files WHERE id = ? AND tenant_id = ?", [$id, current_tenant_id()]);
    }

    public static function isImage(?string $mime): bool {
        return $mime !== null && strpos($mime, 'image/') === 0;
    }

    /* ---- comments ---- */

    public static function comments(int $projectId): array {
        return Database::rows(
            "SELECT * FROM clientdesk_comments WHERE project_id = ? AND tenant_id = ? ORDER BY created_at",
            [$projectId, current_tenant_id()]);
    }

    public static function addComment(int $projectId, string $authorType, ?string $name, string $body): int {
        return Database::insert('clientdesk_comments', [
            'tenant_id'   => current_tenant_id(),
            'project_id'  => $projectId,
            'author_type' => $authorType === 'client' ? 'client' : 'staff',
            'author_name' => $name !== null ? mb_substr($name, 0, 120) : null,
            'body'        => $body,
        ]);
    }

    /* ---- templates ---- */

    public static function templates(): array {
        return Database::rows("SELECT * FROM clientdesk_templates WHERE tenant_id = ? ORDER BY name", [current_tenant_id()]);
    }

    public static function applyTemplate(int $projectId, int $templateId): int {
        $tpl = Database::row("SELECT * FROM clientdesk_templates WHERE id = ? AND tenant_id = ?", [$templateId, current_tenant_id()]);
        if (!$tpl || $tpl['milestones'] === null) return 0;
        $labels = json_decode($tpl['milestones'], true) ?: [];
        $order = (int) Database::value("SELECT COALESCE(MAX(sort_order),0) FROM clientdesk_milestones WHERE project_id=? AND tenant_id=?", [$projectId, current_tenant_id()]);
        $count = 0;
        foreach ($labels as $label) {
            $label = trim((string)$label);
            if ($label === '') continue;
            Database::insert('clientdesk_milestones', [
                'tenant_id' => current_tenant_id(), 'project_id' => $projectId,
                'label' => mb_substr($label, 0, 190), 'sort_order' => ++$order,
            ]);
            $count++;
        }
        return $count;
    }

    /* ---- analytics ---- */

    public static function revenueByMonth(int $months = 6): array {
        $rows = Database::rows(
            "SELECT DATE_FORMAT(paid_at, '%Y-%m') ym, SUM(total_cents) cents
               FROM clientdesk_invoices
              WHERE tenant_id = ? AND status = 'paid' AND paid_at IS NOT NULL
                AND paid_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
              GROUP BY ym ORDER BY ym", [current_tenant_id(), $months]);
        $map = [];
        foreach ($rows as $r) $map[$r['ym']] = (int)$r['cents'];
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("-$i months"));
            $out[] = ['label' => date('M', strtotime($ym . '-01')), 'value' => ($map[$ym] ?? 0) / 100];
        }
        return $out;
    }

    public static function phaseBreakdown(): array {
        $rows = Database::rows(
            "SELECT phase, COUNT(*) n FROM clientdesk_projects WHERE tenant_id = ? GROUP BY phase",
            [current_tenant_id()]);
        $map = [];
        foreach ($rows as $r) $map[$r['phase']] = (int)$r['n'];
        $colors = [
            'onboarding'=>'var(--muted)','design'=>'var(--info)','development'=>'var(--accent)',
            'review'=>'var(--warning)','revisions'=>'var(--warning)','launch'=>'var(--accent-deep)','complete'=>'var(--success)',
        ];
        $out = [];
        foreach (self::phases() as $k => $label) {
            if (!empty($map[$k])) $out[] = ['label'=>$label,'value'=>$map[$k],'color'=>$colors[$k] ?? 'var(--accent)'];
        }
        return $out;
    }

    public static function kpis(): array {
        $t = current_tenant_id();
        return [
            'clients_active'  => (int) Database::value("SELECT COUNT(*) FROM clientdesk_clients WHERE tenant_id=? AND status='active'", [$t]),
            'projects_active' => (int) Database::value("SELECT COUNT(*) FROM clientdesk_projects WHERE tenant_id=? AND phase!='complete'", [$t]),
            'paid_total'      => (int) Database::value("SELECT COALESCE(SUM(total_cents),0) FROM clientdesk_invoices WHERE tenant_id=? AND status='paid'", [$t]),
            'outstanding'     => (int) Database::value("SELECT COALESCE(SUM(total_cents),0) FROM clientdesk_invoices WHERE tenant_id=? AND status IN ('sent','overdue')", [$t]),
            'open_tickets'    => (int) Database::value("SELECT COUNT(*) FROM clientdesk_tickets WHERE tenant_id=? AND status IN ('open','in_progress')", [$t]),
            'pending_quotes'  => (int) Database::value("SELECT COUNT(*) FROM clientdesk_quotes WHERE tenant_id=? AND status='sent'", [$t]),
        ];
    }

    /* ---- stripe ---- */

    public static function stripeReady(): bool {
        return class_exists('StripePaymentAPI') && \StripePaymentAPI::isConfigured();
    }

    /** Build a hosted-checkout URL for an invoice, or null if unavailable. */
    public static function invoiceCheckoutUrl(array $invoice, array $client, string $returnBase): ?string {
        if (!self::stripeReady()) return null;
        try {
            $session = \StripePaymentAPI::createCheckout(
                [['name' => 'Invoice ' . $invoice['number'], 'amount_cents' => (int)$invoice['total_cents'], 'quantity' => 1]],
                [
                    'currency'       => strtolower($invoice['currency'] ?: 'usd'),
                    'customer_email' => $client['email'] ?? null,
                    'success_url'    => $returnBase . '?paid=1',
                    'cancel_url'     => $returnBase . '?cancelled=1',
                    'metadata'       => ['source_plugin' => 'clientdesk', 'invoice_id' => (string)$invoice['id']],
                ]);
            return $session['url'] ?? null;
        } catch (\Throwable $e) {
            slate_log('ClientDesk checkout failed: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /* ---- access requests (portal landing page) ---- */

    public static function createAccessRequest(string $name, string $email, string $message = ''): int {
        return Database::insert('clientdesk_access_requests', [
            'tenant_id' => current_tenant_id(),
            'name'      => mb_substr($name, 0, 160),
            'email'     => mb_substr($email, 0, 190),
            'message'   => $message !== '' ? mb_substr($message, 0, 2000) : null,
        ]);
    }

    public static function accessRequests(?string $status = null): array {
        $sql = "SELECT * FROM clientdesk_access_requests WHERE tenant_id = ?";
        $params = [current_tenant_id()];
        if ($status !== null) { $sql .= " AND status = ?"; $params[] = $status; }
        $sql .= " ORDER BY created_at DESC";
        return Database::rows($sql, $params);
    }

    public static function pendingAccessRequestCount(): int {
        return (int) Database::value(
            "SELECT COUNT(*) FROM clientdesk_access_requests WHERE tenant_id = ? AND status = 'new'",
            [current_tenant_id()]);
    }
}
