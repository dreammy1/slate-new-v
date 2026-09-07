-- Minimum studio data the render suite needs to exercise its parent-facing
-- views. Without these rows four views (class, portal, register, recital) are
-- skipped, so the suite's count silently tracks whatever the database holds.
INSERT INTO customers (id, tenant_id, email, password_hash, name, status, email_verified)
VALUES (900001, 1, 'render-parent@example.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC4WvO75mGxU2Rz1nO', 'Render Parent', 'active', 1)
  ON DUPLICATE KEY UPDATE status=VALUES(status);

INSERT INTO studio_families (id, tenant_id, primary_parent_id)
VALUES (900001, 1, 900001)
  ON DUPLICATE KEY UPDATE primary_parent_id=VALUES(primary_parent_id);

INSERT INTO studio_class_series
  (id, tenant_id, name, style, instructor_id, day_of_week, start_time, end_time,
   session_start, session_end, price_cents, is_active)
VALUES (900001, 1, 'Render Ballet', 'ballet', 1, 2, '16:00', '17:00',
        '2026-01-06', '2026-06-30', 12000, 1)
  ON DUPLICATE KEY UPDATE is_active=VALUES(is_active), name=VALUES(name);

INSERT INTO studio_recitals (id, tenant_id, name)
VALUES (900001, 1, 'Render Recital')
  ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Applied by tests/bin/provision-test-db.sh and by CI's fixture step. Ids sit in
-- the 900000 range so tests/isolation.php's sweep recognises them as synthetic.
