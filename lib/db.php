<?php

// Database configuration
define('DB_PATH', __DIR__ . '/../db/issues.sqlite');

// Global database connection
$db = null;

/**
 * Initialize database connection and create tables if needed
 */
function db_init() {
    global $db;

    // Create db directory if it doesn't exist
    $db_dir = dirname(DB_PATH);
    if (!is_dir($db_dir)) {
        mkdir($db_dir, 0755, true);
    }

    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Enable WAL mode for better concurrency
        $db->exec('PRAGMA journal_mode = WAL');

        // Create tables if they don't exist
        create_tables();

    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

/**
 * Create database tables
 */
function create_tables() {
    global $db;

    // Settings table
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY,
            key TEXT UNIQUE NOT NULL,
            value TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Repos table
    $db->exec("
        CREATE TABLE IF NOT EXISTS repos (
            id INTEGER PRIMARY KEY,
            source TEXT NOT NULL CHECK(source IN ('github', 'linear')),
            source_id TEXT NOT NULL,
            name TEXT NOT NULL,
            local_path TEXT,
            default_branch TEXT DEFAULT 'main',
            auto_create_pr INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Issues table
    $db->exec("
        CREATE TABLE IF NOT EXISTS issues (
            id INTEGER PRIMARY KEY,
            repo_id INTEGER REFERENCES repos(id),
            source TEXT NOT NULL CHECK(source IN ('github', 'linear')),
            source_id TEXT NOT NULL,
            source_url TEXT,
            title TEXT NOT NULL,
            description TEXT,
            labels TEXT,
            priority TEXT,
            status TEXT,
            summary TEXT,
            assessment TEXT CHECK(assessment IN ('pending', 'too_complex', 'agentic_pr_capable')),
            pr_status TEXT DEFAULT 'none' CHECK(pr_status IN ('none', 'in_progress', 'branch_pushed', 'pr_created', 'needs_review', 'failed')),
            pr_url TEXT,
            pr_branch TEXT,
            analysis_model TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            analyzed_at TEXT,
            UNIQUE(source, source_id)
        )
    ");

    // Callbacks table
    $db->exec("
        CREATE TABLE IF NOT EXISTS callbacks (
            id INTEGER PRIMARY KEY,
            issue_id INTEGER REFERENCES issues(id),
            callback_id TEXT UNIQUE NOT NULL,
            status TEXT DEFAULT 'pending',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT
        )
    ");

    // Create indexes for better performance
    $db->exec("CREATE INDEX IF NOT EXISTS idx_issues_repo_id ON issues(repo_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_issues_assessment ON issues(assessment)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_callbacks_issue_id ON callbacks(issue_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_callbacks_callback_id ON callbacks(callback_id)");
}

/**
 * Execute a database query with optional parameters
 * @param string $query SQL query
 * @param array $params Parameters to bind
 * @return PDOStatement|false
 */
function db_query($query, $params = []) {
    global $db;

    if (!$db) {
        db_init();
    }

    try {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Database query failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get a single row from the database
 * @param string $query SQL query
 * @param array $params Parameters to bind
 * @return array|false
 */
function db_get_one($query, $params = []) {
    $stmt = db_query($query, $params);
    if (!$stmt) {
        return false;
    }
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get all rows from the database
 * @param string $query SQL query
 * @param array $params Parameters to bind
 * @return array
 */
function db_get_all($query, $params = []) {
    $stmt = db_query($query, $params);
    if (!$stmt) {
        return [];
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Save a setting with selective encryption
 * @param string $key Setting key
 * @param string $value Setting value
 * @return bool
 */
function save_setting($key, $value) {
    // Include crypto functions
    require_once __DIR__ . '/crypto.php';

    // Check if this key needs encryption
    if (needs_encryption($key)) {
        $value = encrypt_value($value);
    }

    $existing = db_get_one("SELECT id FROM settings WHERE key = ?", [$key]);

    if ($existing) {
        return db_query(
            "UPDATE settings SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE key = ?",
            [$value, $key]
        ) !== false;
    } else {
        return db_query(
            "INSERT INTO settings (key, value) VALUES (?, ?)",
            [$key, $value]
        ) !== false;
    }
}

/**
 * Get a setting with selective decryption
 * @param string $key Setting key
 * @return string
 */
function get_setting($key) {
    // Include crypto functions
    require_once __DIR__ . '/crypto.php';

    $row = db_get_one("SELECT value FROM settings WHERE key = ?", [$key]);

    if (!$row || empty($row['value'])) {
        return '';
    }

    $value = $row['value'];

    // Check if value is encrypted and decrypt if necessary
    if (is_encrypted($value)) {
        $value = decrypt_value($value);
    }

    return $value;
}

// Initialize database on first include
db_init();