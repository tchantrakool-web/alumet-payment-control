<?php
define('DB_PATH', __DIR__ . '/../db/payment_control.sqlite');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        initializeDB($pdo);
    }
    return $pdo;
}

function initializeDB(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            display_name TEXT NOT NULL,
            description TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            full_name TEXT NOT NULL,
            email TEXT,
            role_id INTEGER REFERENCES roles(id),
            is_active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS vendors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            vendor_code TEXT UNIQUE,
            vendor_name TEXT NOT NULL,
            tax_id TEXT,
            bank_name TEXT,
            bank_account TEXT,
            bank_branch TEXT,
            contact_name TEXT,
            phone TEXT,
            email TEXT,
            is_active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS sap_import_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_no TEXT UNIQUE NOT NULL,
            filename TEXT NOT NULL,
            total_records INTEGER DEFAULT 0,
            imported_records INTEGER DEFAULT 0,
            error_records INTEGER DEFAULT 0,
            status TEXT DEFAULT 'completed',
            imported_by INTEGER REFERENCES users(id),
            imported_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS sap_ap_invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            import_batch_id INTEGER REFERENCES sap_import_batches(id),
            vendor_code TEXT,
            vendor_name TEXT,
            po_doc_num TEXT,
            grpo_doc_num TEXT,
            grpo_date TEXT,
            grpo_total REAL DEFAULT 0,
            ap_invoice_doc_num TEXT UNIQUE,
            ap_invoice_date TEXT,
            ap_invoice_total REAL DEFAULT 0,
            ap_paid_amount REAL DEFAULT 0,
            ap_balance REAL DEFAULT 0,
            payment_doc_num TEXT,
            payment_total REAL DEFAULT 0,
            payment_status TEXT DEFAULT 'Imported',
            is_deleted INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS finance_import_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_no TEXT UNIQUE NOT NULL,
            filename TEXT NOT NULL,
            period TEXT,
            total_records INTEGER DEFAULT 0,
            imported_records INTEGER DEFAULT 0,
            error_records INTEGER DEFAULT 0,
            status TEXT DEFAULT 'completed',
            imported_by INTEGER REFERENCES users(id),
            imported_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS finance_ap_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            import_batch_id INTEGER REFERENCES finance_import_batches(id),
            vendor_name TEXT,
            invoice_no TEXT,
            invoice_date TEXT,
            due_date TEXT,
            amount REAL DEFAULT 0,
            wht_amount REAL DEFAULT 0,
            net_payable REAL DEFAULT 0,
            cheque_date TEXT,
            cheque_no TEXT,
            payment_status TEXT DEFAULT 'Imported',
            remark TEXT,
            sap_invoice_id INTEGER REFERENCES sap_ap_invoices(id),
            is_deleted INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS payment_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_no TEXT UNIQUE NOT NULL,
            vendor_id INTEGER REFERENCES vendors(id),
            vendor_code TEXT,
            vendor_name TEXT,
            total_amount REAL DEFAULT 0,
            gross_amount REAL DEFAULT 0,
            wht_applicable INTEGER DEFAULT 0,
            wht_rate REAL DEFAULT 0,
            wht_base_amount REAL DEFAULT 0,
            wht_amount REAL DEFAULT 0,
            net_payable REAL DEFAULT 0,
            due_date TEXT,
            payment_method TEXT DEFAULT 'cheque',
            status TEXT DEFAULT 'draft',
            priority TEXT DEFAULT 'normal',
            note TEXT,
            tax_invoice_required INTEGER DEFAULT 1,
            has_po INTEGER DEFAULT 0,
            has_grn INTEGER DEFAULT 0,
            has_invoice INTEGER DEFAULT 0,
            has_tax_invoice INTEGER DEFAULT 0,
            return_to TEXT,
            return_reason TEXT,
            payment_date TEXT,
            payment_reference TEXT,
            payment_bank TEXT,
            payer_name TEXT,
            created_by INTEGER REFERENCES users(id),
            checked_by INTEGER REFERENCES users(id),
            checked_at TEXT,
            submitted_at TEXT,
            is_deleted INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS payment_request_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            payment_request_id INTEGER REFERENCES payment_requests(id),
            sap_invoice_id INTEGER REFERENCES sap_ap_invoices(id),
            ap_invoice_no TEXT,
            invoice_date TEXT,
            invoice_amount REAL DEFAULT 0,
            wht_amount REAL DEFAULT 0,
            net_amount REAL DEFAULT 0,
            due_date TEXT,
            remark TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS approval_matrix (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            min_amount REAL DEFAULT 0,
            max_amount REAL,
            approver_role TEXT NOT NULL,
            approver_user_id INTEGER REFERENCES users(id),
            sequence INTEGER DEFAULT 1,
            is_active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS approval_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            payment_request_id INTEGER REFERENCES payment_requests(id),
            approver_id INTEGER REFERENCES users(id),
            sequence INTEGER DEFAULT 1,
            status TEXT DEFAULT 'pending',
            action TEXT,
            comment TEXT,
            actioned_at TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS approval_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            payment_request_id INTEGER REFERENCES payment_requests(id),
            user_id INTEGER REFERENCES users(id),
            action TEXT NOT NULL,
            comment TEXT,
            old_status TEXT,
            new_status TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS payment_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_no TEXT UNIQUE NOT NULL,
            batch_date TEXT,
            payment_type TEXT DEFAULT 'cheque',
            total_amount REAL DEFAULT 0,
            status TEXT DEFAULT 'draft',
            is_locked INTEGER DEFAULT 0,
            note TEXT,
            created_by INTEGER REFERENCES users(id),
            locked_by INTEGER REFERENCES users(id),
            locked_at TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS payment_batch_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            payment_batch_id INTEGER REFERENCES payment_batches(id),
            payment_request_id INTEGER REFERENCES payment_requests(id),
            amount REAL DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS cheques (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cheque_no TEXT,
            cheque_date TEXT,
            bank TEXT,
            amount REAL DEFAULT 0,
            payee_name TEXT,
            payment_batch_id INTEGER REFERENCES payment_batches(id),
            payment_request_id INTEGER REFERENCES payment_requests(id),
            vendor_id INTEGER REFERENCES vendors(id),
            status TEXT DEFAULT 'prepared',
            receiver_name TEXT,
            received_date TEXT,
            void_reason TEXT,
            created_by INTEGER REFERENCES users(id),
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            related_type TEXT NOT NULL,
            related_id INTEGER NOT NULL,
            filename TEXT NOT NULL,
            original_filename TEXT NOT NULL,
            file_type TEXT,
            file_size INTEGER,
            document_type TEXT,
            uploaded_by INTEGER REFERENCES users(id),
            is_deleted INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER REFERENCES users(id),
            username TEXT,
            action TEXT NOT NULL,
            module TEXT NOT NULL,
            record_id INTEGER,
            old_value TEXT,
            new_value TEXT,
            ip_address TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS system_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key TEXT UNIQUE NOT NULL,
            setting_value TEXT,
            description TEXT,
            updated_by INTEGER REFERENCES users(id),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS import_error_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            import_batch_id INTEGER,
            import_type TEXT,
            row_number INTEGER,
            field_name TEXT,
            error_message TEXT,
            raw_data TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS notification_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            related_type TEXT,
            related_id INTEGER,
            recipient_id INTEGER,
            recipient_email TEXT,
            subject TEXT,
            body TEXT,
            status TEXT DEFAULT 'pending',
            sent_at TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );
    ");

    runMigrations($pdo);
    seedDefaultData($pdo);
}

function runMigrations(PDO $pdo): void {
    ensureColumn($pdo, 'payment_requests', 'gross_amount', "REAL DEFAULT 0");
    ensureColumn($pdo, 'payment_requests', 'wht_applicable', "INTEGER DEFAULT 0");
    ensureColumn($pdo, 'payment_requests', 'wht_rate', "REAL DEFAULT 0");
    ensureColumn($pdo, 'payment_requests', 'wht_base_amount', "REAL DEFAULT 0");
    ensureColumn($pdo, 'payment_requests', 'tax_invoice_required', "INTEGER DEFAULT 1");
    ensureColumn($pdo, 'payment_requests', 'has_po', "INTEGER DEFAULT 0");
    ensureColumn($pdo, 'payment_requests', 'has_grn', "INTEGER DEFAULT 0");
    ensureColumn($pdo, 'payment_requests', 'has_invoice', "INTEGER DEFAULT 0");
    ensureColumn($pdo, 'payment_requests', 'has_tax_invoice', "INTEGER DEFAULT 0");
    ensureColumn($pdo, 'payment_requests', 'return_to', "TEXT");
    ensureColumn($pdo, 'payment_requests', 'return_reason', "TEXT");
    ensureColumn($pdo, 'payment_requests', 'payment_date', "TEXT");
    ensureColumn($pdo, 'payment_requests', 'payment_reference', "TEXT");
    ensureColumn($pdo, 'payment_requests', 'payment_bank', "TEXT");
    ensureColumn($pdo, 'payment_requests', 'payer_name', "TEXT");
    ensureColumn($pdo, 'payment_requests', 'checker_po_accepted', "INTEGER DEFAULT 0");
    ensureColumn($pdo, 'payment_requests', 'checker_po_comment', "TEXT");
    ensureColumn($pdo, 'payment_requests', 'checker_invoice_accepted', "INTEGER DEFAULT 0");
    ensureColumn($pdo, 'payment_requests', 'checker_invoice_comment', "TEXT");
    ensureColumn($pdo, 'payment_requests', 'checker_gr_accepted', "INTEGER DEFAULT 0");
    ensureColumn($pdo, 'payment_requests', 'checker_gr_comment', "TEXT");
    ensureColumn($pdo, 'finance_ap_records', 'tax_invoice_no', "TEXT");
    ensureColumn($pdo, 'finance_ap_records', 'cheque_bank', "TEXT");
    ensureColumn($pdo, 'finance_ap_records', 'paid_date', "TEXT");
    ensureColumn($pdo, 'finance_ap_records', 'source_row', "INTEGER");
    ensureColumn($pdo, 'cheques', 'source_type', "TEXT");
    ensureColumn($pdo, 'cheques', 'source_id', "INTEGER");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cheques_source ON cheques(source_type, source_id)");

    // The original seeded tiers used whole-baht starts (100001, 500001,
    // 2000001), leaving cent-valued payments between tiers with no approver.
    // Limit this correction to the exact original seeded boundaries so custom
    // approval matrices are not rewritten.
    $pdo->exec("UPDATE approval_matrix SET min_amount = 100000.01 WHERE min_amount = 100001 AND max_amount = 500000");
    $pdo->exec("UPDATE approval_matrix SET min_amount = 500000.01 WHERE min_amount = 500001 AND max_amount = 2000000");
    $pdo->exec("UPDATE approval_matrix SET min_amount = 2000000.01 WHERE min_amount = 2000001 AND max_amount IS NULL");

    $pdo->exec("
        UPDATE payment_requests
        SET gross_amount = CASE
                WHEN COALESCE(gross_amount, 0) = 0 THEN COALESCE(total_amount, 0)
                ELSE gross_amount
            END,
            wht_base_amount = CASE
                WHEN COALESCE(wht_base_amount, 0) = 0 THEN COALESCE(total_amount, 0)
                ELSE wht_base_amount
            END,
            net_payable = CASE
                WHEN COALESCE(net_payable, 0) = 0 THEN COALESCE(total_amount, 0) - COALESCE(wht_amount, 0)
                ELSE net_payable
            END
    ");
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->query("PRAGMA table_info({$table})");
    $columns = array_column($stmt->fetchAll(), 'name');
    if (!in_array($column, $columns, true)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function seedDefaultData(PDO $pdo): void {
    $count = $pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
    if ($count > 0) return;

    $roles = [
        ['admin',           'Administrator',    'Full system access'],
        ['maker',           'Maker',            'Create payment requests'],
        ['checker',         'Checker',          'Verify payment requests'],
        ['approver',        'Approver',         'Approve payment requests'],
        ['finance_manager', 'Finance Manager',  'Manage payment batches and cash forecast'],
        ['executive',       'MD / Executive',   'View dashboards and approve high-value items'],
    ];
    $stmtR = $pdo->prepare("INSERT INTO roles (name, display_name, description) VALUES (?, ?, ?)");
    foreach ($roles as $r) $stmtR->execute($r);

    $adminRoleId = $pdo->lastInsertId();
    // admin role id = 1
    $stmtU = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role_id) VALUES (?, ?, ?, ?, ?)");
    $stmtU->execute(['admin', password_hash('admin1234', PASSWORD_DEFAULT), 'System Admin', 'admin@alumet.co.th', 1]);
    $stmtU->execute(['maker1', password_hash('maker1234', PASSWORD_DEFAULT), 'Finance Maker', 'maker@alumet.co.th', 2]);
    $stmtU->execute(['checker1', password_hash('checker1234', PASSWORD_DEFAULT), 'Finance Checker', 'checker@alumet.co.th', 3]);
    $stmtU->execute(['approver1', password_hash('approver1234', PASSWORD_DEFAULT), 'Finance Approver', 'approver@alumet.co.th', 4]);
    $stmtU->execute(['finance1', password_hash('finance1234', PASSWORD_DEFAULT), 'Finance Manager', 'fm@alumet.co.th', 5]);
    $stmtU->execute(['md1', password_hash('md1234', PASSWORD_DEFAULT), 'Managing Director', 'md@alumet.co.th', 6]);

    $pdo->exec("INSERT INTO approval_matrix (min_amount, max_amount, approver_role, sequence) VALUES
        (0, 100000, 'finance_manager', 1),
        (100000.01, 500000, 'finance_manager', 1),
        (100000.01, 500000, 'approver', 2),
        (500000.01, 2000000, 'approver', 1),
        (500000.01, 2000000, 'executive', 2),
        (2000000.01, NULL, 'executive', 1)
    ");

    $settings = [
        ['company_name',    'Alumet Co., Ltd.',  'Company name'],
        ['currency',        'THB',               'Default currency'],
        ['date_format',     'd/m/Y',             'Display date format'],
    ];
    $stmtS = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
    foreach ($settings as $s) $stmtS->execute($s);
}
