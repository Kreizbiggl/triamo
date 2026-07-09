<?php
declare(strict_types=1);

/*
 * TRIAMO – responsive Ein-Datei-SPA für PHP 8.1+ und MySQL/MariaDB.
 * Installation:
 * 1. Leere MySQL-Datenbank im Hosting anlegen.
 * 2. Nur die Werte im Konfigurationsblock anpassen.
 * 3. Diese Datei auf den Webspace laden und über HTTPS aufrufen.
 * 4. Beim ersten Aufruf das Administratorkonto anlegen.
 */

// ======================== KONFIGURATION ========================
const DB_HOST = '';
const DB_NAME = '';
const DB_USER = '';
const DB_PASS = '';
const DB_TABLE_PREFIX = 'triamo_prod_'; // optional, z. B. 'triamo_prod_' oder 'triamo_test_'

const APP_NAME = 'TRIAMO';
const APP_TIMEZONE = 'Europe/Berlin';
const MAIL_FROM = ''; // leer = automatisch bibliothek@deine-domain
const MAIL_FROM_NAME = 'TRIAMO';
const GOOGLE_BOOKS_API_KEY = ''; // optional; erweitert Metadaten und Online-Suche
const CRON_TOKEN = ''; // HIER_EINEN_ZUFÄLLIGEN_WERT_EINTRAGEN
const DEFAULT_LOAN_DAYS = 28;
const REMINDER_DAYS_BEFORE = 2;
const MAX_JOB_ATTEMPTS = 4;
const ALLOW_SELF_REGISTRATION = true;
const DEFAULT_PUBLIC_SHARE_DAYS = 14;
const MAX_COVER_UPLOAD_BYTES = 12 * 1024 * 1024;
const MAX_BOOK_FILE_BYTES = 50 * 1024 * 1024;
const PRIVACY_NOTICE_VERSION = ''; // Stand der bei Registrierung angezeigten Datenschutzerklärung
// ===============================================================



/*
 * WARTUNGSÜBERSICHT
 * -----------------
 * Diese Ein-Datei-App ist absichtlich in klar erkennbare Bereiche gegliedert:
 * 1. Konfiguration und Sicherheitsheader
 * 2. Datenbankzugriff, Tabellenpräfixe und Migrationen
 * 3. Anmeldung, Haushalte, Freigaben und Rechteprüfung
 * 4. Normalisierung, ISBN-, Standort- und Barcode-Helfer
 * 5. Metadaten-, Cover-, Job- und E-Mail-Verarbeitung
 * 6. API-Router
 * 7. HTML, CSS und JavaScript der Oberfläche
 *
 * Jede benannte Funktion in PHP und JavaScript besitzt darunter einen kurzen
 * Wartungskopf mit Zweck, typischen Aufrufern und wichtigen internen Abhängigkeiten. Die Angaben sind
 * als Orientierung für manuelle Änderungen gedacht und ersetzen keine Tests.
 */

date_default_timezone_set(APP_TIMEZONE);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('hausbibliothek_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');

/**
 * Wartung: Validiert das konfigurierte Tabellenpräfix und gibt es für SQL-Umschreibungen zurück.
 * Aufgerufen von: create_backup_payload(), db().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function db_table_prefix(): string
{
    $prefix = (string)DB_TABLE_PREFIX;
    if ($prefix === '') {
        return '';
    }
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/', $prefix)) {
        throw new RuntimeException('DB_TABLE_PREFIX darf nur Buchstaben, Zahlen und Unterstriche enthalten, muss mit einem Buchstaben beginnen und höchstens 32 Zeichen lang sein. Beispiel: triamo_prod_');
    }
    return $prefix;
}

/**
 * Wartung: Listet alle logischen Tabellen, die vom Präfix-Wrapper erkannt werden.
 * Aufgerufen von: db().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function logical_table_names(): array
{
    return [
        'users', 'books', 'copies', 'loans', 'reservations', 'jobs', 'email_queue', 'audit_log',
        'inventory_checks', 'locations', 'location_code_aliases', 'book_covers', 'book_metadata',
        'book_history', 'library_reminder_log', 'households', 'household_members',
        'household_book_settings', 'public_share_links', 'public_share_access_log',
        'household_access_keys', 'household_access_grants', 'schema_migrations', 'book_files',
        'password_reset_tokens', 'email_verification_tokens'
    ];
}


// ======================== DATENBANK-PRÄFIX UND PDO-WRAPPER ========================
class TriamoPDO extends PDO
{
    private string $tablePrefix = '';
    private array $logicalTables = [];

    /**
     * Wartung: Initialisiert den PDO-Wrapper mit Präfix und erlaubten Tabellennamen.
     * Aufgerufen von: db().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    public function setTriamoPrefix(string $prefix, array $tables): void
    {
        $this->tablePrefix = $prefix;
        $this->logicalTables = $tables;
    }

    /**
     * Wartung: Erzeugt aus einem logischen Tabellennamen den realen Namen mit Präfix.
     * Aufgerufen von: derzeit kein direkter interner Aufrufer.
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    public function triamoTableName(string $logicalName): string
    {
        if (!in_array($logicalName, $this->logicalTables, true)) {
            throw new InvalidArgumentException('Unbekannte Tabelle: ' . $logicalName);
        }
        return $this->tablePrefix . $logicalName;
    }

    /**
     * Wartung: Schreibt SQL-Anweisungen automatisch auf die konfigurierten Präfix-Tabellen um.
     * Aufgerufen von: exec(), prepare(), query().
     * Abhängigkeiten: triamoConstraintName().
     */
    public function triamoPrefixSql(string $sql): string
    {
        if ($this->tablePrefix === '') {
            return $sql;
        }
        foreach ($this->logicalTables as $table) {
            $quoted = preg_quote($table, '/');
            $prefix = preg_quote($this->tablePrefix, '/');
            $sql = preg_replace(
                '/\b(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?|ALTER\s+TABLE\s+|DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?|TRUNCATE\s+TABLE\s+|SHOW\s+COLUMNS\s+FROM\s+|SHOW\s+INDEX\s+FROM\s+|DESCRIBE\s+|FROM\s+|JOIN\s+|INTO\s+|UPDATE\s+|REFERENCES\s+|DELETE\s+FROM\s+)(`?)' . $quoted . '(`?)(\b)/i',
                '$1$2' . $this->tablePrefix . $table . '$3$4',
                $sql
            );
            // In seltenen Fällen stehen Backticks direkt ohne SQL-Schlüsselwort, z. B. in dynamischen Hilfsabfragen.
            $sql = preg_replace('/`' . $quoted . '`/i', '`' . $this->tablePrefix . $table . '`', $sql);
            // Schutz gegen versehentliches Doppelpräfixieren.
            $sql = preg_replace('/\b' . $prefix . $prefix . $quoted . '\b/i', $this->tablePrefix . $table, $sql);
        }
        $self = $this;
        $sql = preg_replace_callback('/\bCONSTRAINT\s+(`?)([a-zA-Z0-9_]+)(`?)\b/', static function (array $m) use ($self): string {
            $name = $self->triamoConstraintName($m[2]);
            return 'CONSTRAINT ' . $m[1] . $name . $m[3];
        }, $sql);
        return $sql;
    }

    /**
     * Wartung: Erzeugt sichere Fremdschlüssel- und Constraintnamen mit Präfix.
     * Aufgerufen von: triamoPrefixSql().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    public function triamoConstraintName(string $name): string
    {
        if ($this->tablePrefix === '' || str_starts_with($name, $this->tablePrefix)) {
            return $name;
        }
        $candidate = $this->tablePrefix . $name;
        if (strlen($candidate) <= 64) {
            return $candidate;
        }
        return substr($this->tablePrefix, 0, 24) . substr(hash('sha256', $candidate), 0, 12) . '_' . substr($name, -24);
    }

    /**
     * Wartung: Leitet PDO::exec mit vorheriger Tabellenpräfix-Umschreibung weiter.
     * Aufgerufen von: apply_schema_migrations(), apply_schema_migrations_v6(), apply_schema_migrations_v61(), apply_schema_migrations_v62(), cleanup_duplicate_done_metadata_jobs(), cleanup_finished_metadata_jobs(), drop_index_if_exists(), +4 weitere.
     * Abhängigkeiten: triamoPrefixSql().
     */
    public function exec(string $statement): int|false
    {
        return parent::exec($this->triamoPrefixSql($statement));
    }

    /**
     * Wartung: Leitet PDO::prepare mit vorheriger Tabellenpräfix-Umschreibung weiter.
     * Aufgerufen von: Globaler Ablauf/API/Events, active_reservation_for_book(), apply_schema_migrations_v68(), audit(), backup_rows(), book_event(), cancel_metadata_job(), +34 weitere.
     * Abhängigkeiten: triamoPrefixSql().
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->triamoPrefixSql($query), $options);
    }

    /**
     * Wartung: Leitet PDO::query mit vorheriger Tabellenpräfix-Umschreibung weiter.
     * Aufgerufen von: Globaler Ablauf/API/Events, backup_rows(), cleanup_finished_metadata_jobs(), cleanup_location_code_collisions(), enqueue_due_reminders(), enqueue_library_reminders(), metadata_queue_snapshot(), +5 weitere.
     * Abhängigkeiten: triamoPrefixSql().
     */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $query = $this->triamoPrefixSql($query);
        if ($fetchMode === null) {
            return parent::query($query);
        }
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

class MetadataRetryLaterException extends RuntimeException
{
    public int $retryAfterSeconds;

    /**
     * Wartung: Initialisiert eine Ausnahme oder Klasse mit den notwendigen Startwerten.
     * Aufgerufen von: derzeit kein direkter interner Aufrufer.
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    public function __construct(string $message, int $retryAfterSeconds = 21600)
    {
        parent::__construct($message);
        $this->retryAfterSeconds = max(300, $retryAfterSeconds);
    }
}

/**
 * Wartung: Ermittelt und erstellt das lokale Datenverzeichnis für Caches und Markerdateien.
 * Aufgerufen von: source_backoff_path().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function triamo_data_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'bookvault_data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Wartung: Erzeugt den Dateipfad für die temporäre Sperre einer externen Quelle.
 * Aufgerufen von: clear_source_backoff(), set_source_backoff(), source_backoff_until().
 * Abhängigkeiten: triamo_data_dir().
 */
function source_backoff_path(string $sourceKey): string
{
    $safe = preg_replace('/[^a-z0-9_\-]+/i', '_', $sourceKey) ?: 'source';
    return triamo_data_dir() . DIRECTORY_SEPARATOR . 'backoff_' . $safe . '.txt';
}

/**
 * Wartung: Liest, bis wann eine externe Quelle wegen Fehlern pausiert ist.
 * Aufgerufen von: Globaler Ablauf/API/Events, fetch_and_store_metadata(), metadata_google_result(), metadata_queue_snapshot().
 * Abhängigkeiten: source_backoff_path().
 */
function source_backoff_until(string $sourceKey): int
{
    $path = source_backoff_path($sourceKey);
    if (!is_file($path)) {
        return 0;
    }
    return max(0, (int)trim((string)@file_get_contents($path)));
}

/**
 * Wartung: Setzt eine temporäre Pause für eine externe Metadatenquelle.
 * Aufgerufen von: Globaler Ablauf/API/Events, metadata_google_result().
 * Abhängigkeiten: source_backoff_path().
 */
function set_source_backoff(string $sourceKey, int $seconds): int
{
    $until = time() + max(300, $seconds);
    $path = source_backoff_path($sourceKey);
    @file_put_contents($path, (string)$until, LOCK_EX);
    return $until;
}

/**
 * Wartung: Entfernt eine gesetzte Quellenpause nach erfolgreichem Abruf.
 * Aufgerufen von: metadata_google_result().
 * Abhängigkeiten: source_backoff_path().
 */
function clear_source_backoff(string $sourceKey): void
{
    $path = source_backoff_path($sourceKey);
    if (is_file($path)) {
        @unlink($path);
    }
}



// ======================== DATENBANK-BOOTSTRAP UND MIGRATIONEN ========================
/**
 * Wartung: Öffnet die Datenbankverbindung und führt Schema-Initialisierung und Migrationen aus.
 * Aufgerufen von: Globaler Ablauf/API/Events, active_reservation_for_book(), audit(), book_event(), cancel_metadata_job(), choose_active_cover(), consolidate_metadata_sources(), +25 weitere.
 * Abhängigkeiten: apply_schema_migrations(), apply_schema_migrations_v6(), apply_schema_migrations_v61(), apply_schema_migrations_v62(), apply_schema_migrations_v68(), db_table_prefix(), init_schema(), +2 weitere.
 */
function db(): PDO
{
    static $pdo = null;
    static $initialized = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new TriamoPDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);
    $pdo->setTriamoPrefix(db_table_prefix(), logical_table_names());

    if (!$initialized) {
        // Schema und Migrationen werden bewusst bei jedem ersten DB-Zugriff des Requests geprüft.
        // Dadurch wird eine leergeräumte Datenbank auch dann wieder korrekt aufgebaut, wenn
        // alte Markerdateien im Dateisystem noch vorhanden sind.
        init_schema($pdo);
        apply_schema_migrations($pdo);
        apply_schema_migrations_v6($pdo);
        apply_schema_migrations_v61($pdo);
        apply_schema_migrations_v62($pdo);
        apply_schema_migrations_v68($pdo);
        apply_schema_migrations_v70($pdo);
        apply_schema_migrations_v71($pdo);
        $dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'bookvault_data';
        if ((is_dir($dataDir) || @mkdir($dataDir, 0775, true)) && is_writable($dataDir)) {
            if (!is_file($dataDir . DIRECTORY_SEPARATOR . 'index.html')) {
                @file_put_contents($dataDir . DIRECTORY_SEPARATOR . 'index.html', '');
            }
        }
        $initialized = true;
    }

    return $pdo;
}

/**
 * Wartung: Erstellt alle Basistabellen, die für frische Installationen benötigt werden.
 * Aufgerufen von: db().
 * Abhängigkeiten: exec().
 */
function init_schema(PDO $pdo): void
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(191) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'member',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_email (email),
            KEY idx_users_active (active),
            KEY idx_users_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS books (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            isbn13 VARCHAR(13) NULL,
            isbn10 VARCHAR(10) NULL,
            title VARCHAR(500) NOT NULL,
            subtitle VARCHAR(500) NULL,
            authors VARCHAR(1000) NULL,
            publisher VARCHAR(255) NULL,
            published_date VARCHAR(30) NULL,
            description MEDIUMTEXT NULL,
            page_count INT UNSIGNED NULL,
            categories TEXT NULL,
            language VARCHAR(20) NULL,
            cover_path VARCHAR(500) NULL,
            cover_url VARCHAR(1000) NULL,
            metadata_source VARCHAR(50) NULL,
            metadata_status VARCHAR(20) NOT NULL DEFAULT 'queued',
            metadata_error TEXT NULL,
            last_seen_at DATETIME NULL,
            seen_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_books_isbn13 (isbn13),
            KEY idx_books_isbn10 (isbn10),
            KEY idx_books_status (metadata_status),
            KEY idx_books_title (title(191)),
            CONSTRAINT fk_books_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS copies (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            book_id BIGINT UNSIGNED NOT NULL,
            inventory_no VARCHAR(50) NOT NULL,
            scan_token VARCHAR(64) NULL,
            location VARCHAR(255) NULL,
            shelf VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'available',
            notes TEXT NULL,
            last_seen_at DATETIME NULL,
            seen_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_copies_inventory (inventory_no),
            UNIQUE KEY uq_copies_scan_token (scan_token),
            KEY idx_copies_book (book_id),
            KEY idx_copies_status (status),
            CONSTRAINT fk_copies_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS loans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            copy_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            loaned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            due_at DATETIME NOT NULL,
            returned_at DATETIME NULL,
            return_note TEXT NULL,
            last_reminder_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_loans_copy (copy_id),
            KEY idx_loans_user (user_id),
            KEY idx_loans_due (due_at),
            KEY idx_loans_returned (returned_at),
            CONSTRAINT fk_loans_copy FOREIGN KEY (copy_id) REFERENCES copies(id) ON DELETE RESTRICT,
            CONSTRAINT fk_loans_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT fk_loans_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS reservations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            book_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notified_at DATETIME NULL,
            fulfilled_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_res_book_status (book_id, status),
            KEY idx_res_user_status (user_id, status),
            CONSTRAINT fk_res_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
            CONSTRAINT fk_res_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_type VARCHAR(50) NOT NULL,
            payload LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            locked_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_jobs_pick (status, available_at),
            KEY idx_jobs_type (job_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS email_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recipient VARCHAR(191) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            send_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_email_pick (status, send_after)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(50) NULL,
            entity_id BIGINT UNSIGNED NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_audit_user (user_id),
            KEY idx_audit_created (created_at),
            CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS inventory_checks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            book_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            isbn13 VARCHAR(13) NOT NULL,
            scan_token VARCHAR(64) NULL,
            location VARCHAR(255) NULL,
            shelf VARCHAR(255) NULL,
            checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_inventory_checks_token (scan_token),
            KEY idx_inventory_checks_book (book_id, checked_at),
            KEY idx_inventory_checks_isbn (isbn13, checked_at),
            CONSTRAINT fk_inventory_checks_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
            CONSTRAINT fk_inventory_checks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];


    $queries[] = "CREATE TABLE IF NOT EXISTS schema_migrations (
            migration_key VARCHAR(120) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            details TEXT NULL,
            PRIMARY KEY (migration_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    foreach ($queries as $query) {
        $pdo->exec($query);
    }
}

/**
 * Wartung: Prüft robust, ob eine Spalte in einer Tabelle existiert.
 * Aufgerufen von: apply_schema_migrations(), apply_schema_migrations_v6(), apply_schema_migrations_v61(), apply_schema_migrations_v62(), apply_schema_migrations_v68(), cleanup_location_code_collisions().
 * Abhängigkeiten: query().
 */
function table_column_exists(PDO $pdo, string $table, string $column): bool
{
    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) {
        throw new InvalidArgumentException('Ungültiger Tabellen- oder Spaltenname.');
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
        return (bool)$stmt->fetch();
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '42S02') {
            return false;
        }
        throw $e;
    }
}


/**
 * Wartung: Liest die SHOW-COLUMNS-Informationen einer Spalte für gezielte Migrationen.
 * Aufgerufen von: apply_schema_migrations_v6().
 * Abhängigkeiten: query().
 */
function table_column_info(PDO $pdo, string $table, string $column): ?array
{
    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) {
        throw new InvalidArgumentException('Ungültiger Tabellen- oder Spaltenname.');
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '42S02') {
            return null;
        }
        throw $e;
    }
}

/**
 * Wartung: Prüft, ob eine einmalige Datenmigration bereits als erledigt markiert wurde.
 * Aufgerufen von: apply_schema_migrations_v68().
 * Abhängigkeiten: prepare().
 */
function schema_migration_done(PDO $pdo, string $migrationKey): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = ? LIMIT 1');
    $stmt->execute([$migrationKey]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Wartung: Speichert den erfolgreichen Abschluss einer einmaligen Datenmigration.
 * Aufgerufen von: apply_schema_migrations_v68().
 * Abhängigkeiten: prepare().
 */
function mark_schema_migration_done(PDO $pdo, string $migrationKey, ?string $details = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO schema_migrations (migration_key, applied_at, details) VALUES (?, NOW(), ?) '
        . 'ON DUPLICATE KEY UPDATE applied_at = VALUES(applied_at), details = VALUES(details)'
    );
    $stmt->execute([$migrationKey, $details]);
}

/**
 * Wartung: Entfernt einen Migrationsmarker, damit eine Bereinigung erneut laufen kann.
 * Aufgerufen von: restore_full_backup(), restore_household_backup().
 * Abhängigkeiten: prepare().
 */
function unmark_schema_migration(PDO $pdo, string $migrationKey): void
{
    $stmt = $pdo->prepare('DELETE FROM schema_migrations WHERE migration_key = ?');
    $stmt->execute([$migrationKey]);
}

/**
 * Wartung: Erzeugt eine freie fünfstellige Standortgruppen-ID.
 * Aufgerufen von: Globaler Ablauf/API/Events, cleanup_location_code_collisions(), ensure_household_loose_location().
 * Abhängigkeiten: prepare().
 */
function new_location_group_code(PDO $pdo): string
{
    for ($attempt = 0; $attempt < 200; $attempt++) {
        $code = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE group_code = ? OR code LIKE ?");
        $stmt->execute([$code, 'TRIAMO-' . $code . '-%']);
        if ((int)$stmt->fetchColumn() === 0) {
            return $code;
        }
    }
    throw new RuntimeException('Es konnte keine freie fünfstellige Standort-ID erzeugt werden.');
}

/**
 * Wartung: Bereinigt historische Standortduplikate innerhalb eines Haushalts.
 * Aufgerufen von: apply_schema_migrations_v62().
 * Abhängigkeiten: new_location_group_code(), prepare(), query(), table_column_exists().
 */
function cleanup_location_code_collisions(PDO $pdo): void
{
    if (!table_column_exists($pdo, 'locations', 'household_id')) {
        return;
    }

    // Genau ein aktiver loser Standort pro Haushalt. Historische Duplikate werden nicht
    // gelöscht, sondern deaktiviert und vorhandene Buchzuordnungen auf den ersten aktiven
    // losen Standort des Haushalts umgehängt.
    $looseGroups = $pdo->query(
        "SELECT household_id, GROUP_CONCAT(id ORDER BY id) AS ids
         FROM locations
         WHERE household_id IS NOT NULL AND is_loose = 1
         GROUP BY household_id
         HAVING COUNT(*) > 1"
    )->fetchAll();
    foreach ($looseGroups as $group) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)$group['ids']))));
        if (count($ids) < 2) {
            continue;
        }
        $keepId = array_shift($ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$keepId], $ids);
        $pdo->prepare("UPDATE copies SET location_id = ? WHERE location_id IN ($placeholders)")->execute($params);
        $pdo->prepare("UPDATE copies SET home_location_id = ? WHERE home_location_id IN ($placeholders)")->execute($params);
        foreach ($ids as $id) {
            $groupCode = new_location_group_code($pdo);
            $newCode = 'TRIAMO-' . $groupCode . '-0';
            $pdo->prepare("UPDATE locations SET code = ?, group_code = ?, active = 0 WHERE id = ?")
                ->execute([$newCode, $groupCode, $id]);
        }
    }

    // Falls aus alten Zwischenständen trotzdem doppelte Codes in einem Haushalt vorhanden
    // sind, bekommt jeder doppelte Datensatz einen neuen freien Code. Buchzuordnungen
    // bleiben dabei unverändert.
    $duplicates = $pdo->query(
        "SELECT household_id, code, GROUP_CONCAT(id ORDER BY id) AS ids
         FROM locations
         WHERE household_id IS NOT NULL AND code IS NOT NULL AND code <> ''
         GROUP BY household_id, code
         HAVING COUNT(*) > 1"
    )->fetchAll();
    foreach ($duplicates as $duplicate) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)$duplicate['ids']))));
        array_shift($ids);
        foreach ($ids as $duplicateId) {
            $stmt = $pdo->prepare("SELECT compartment_no, code FROM locations WHERE id = ?");
            $stmt->execute([$duplicateId]);
            $row = $stmt->fetch();
            if (!$row) {
                continue;
            }
            $groupCode = new_location_group_code($pdo);
            $compartmentNo = max(0, (int)($row['compartment_no'] ?? 0));
            $newCode = 'TRIAMO-' . $groupCode . '-' . $compartmentNo;
            $oldCode = strtoupper(trim((string)($row['code'] ?? '')));
            if ($oldCode !== '') {
                $pdo->prepare("INSERT IGNORE INTO location_code_aliases (alias_code, location_id) VALUES (?, ?)")
                    ->execute([$oldCode, $duplicateId]);
            }
            $pdo->prepare("UPDATE locations SET code = ?, group_code = ? WHERE id = ?")
                ->execute([$newCode, $groupCode, $duplicateId]);
        }
    }
}

/**
 * Wartung: Ergänzt Schemaänderungen aus frühen Versionen und erstellt Metadatentabellen.
 * Aufgerufen von: db().
 * Abhängigkeiten: ensure_household_loose_location(), exec(), table_column_exists().
 */
function apply_schema_migrations(PDO $pdo): void
{
    $columns = [
        ['books', 'last_seen_at', "ALTER TABLE books ADD COLUMN last_seen_at DATETIME NULL AFTER metadata_error"],
        ['books', 'seen_count', "ALTER TABLE books ADD COLUMN seen_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_seen_at"],
        ['books', 'metadata_field_sources', "ALTER TABLE books ADD COLUMN metadata_field_sources LONGTEXT NULL AFTER metadata_source"],
        ['books', 'deleted_at', "ALTER TABLE books ADD COLUMN deleted_at DATETIME NULL AFTER seen_count"],
        ['books', 'deleted_by', "ALTER TABLE books ADD COLUMN deleted_by BIGINT UNSIGNED NULL AFTER deleted_at"],
        ['books', 'selected_cover_id', "ALTER TABLE books ADD COLUMN selected_cover_id BIGINT UNSIGNED NULL AFTER cover_url"],
        ['copies', 'last_seen_at', "ALTER TABLE copies ADD COLUMN last_seen_at DATETIME NULL AFTER notes"],
        ['copies', 'location_id', "ALTER TABLE copies ADD COLUMN location_id BIGINT UNSIGNED NULL AFTER shelf"],
        ['copies', 'home_location_id', "ALTER TABLE copies ADD COLUMN home_location_id BIGINT UNSIGNED NULL AFTER location_id"],
        ['copies', 'seen_count', "ALTER TABLE copies ADD COLUMN seen_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_seen_at"],
        ['copies', 'ownership', "ALTER TABLE copies ADD COLUMN ownership VARCHAR(20) NOT NULL DEFAULT 'owned' AFTER status"],
        ['copies', 'library_name', "ALTER TABLE copies ADD COLUMN library_name VARCHAR(255) NULL AFTER ownership"],
        ['copies', 'library_due_at', "ALTER TABLE copies ADD COLUMN library_due_at DATETIME NULL AFTER library_name"],
        ['copies', 'library_returned_at', "ALTER TABLE copies ADD COLUMN library_returned_at DATETIME NULL AFTER library_due_at"],
        ['copies', 'deleted_at', "ALTER TABLE copies ADD COLUMN deleted_at DATETIME NULL AFTER seen_count"],
        ['copies', 'deleted_by', "ALTER TABLE copies ADD COLUMN deleted_by BIGINT UNSIGNED NULL AFTER deleted_at"],
        ['inventory_checks', 'location_id', "ALTER TABLE inventory_checks ADD COLUMN location_id BIGINT UNSIGNED NULL AFTER shelf"],
    ];
    foreach ($columns as [$table, $column, $sql]) {
        if (!table_column_exists($pdo, $table, $column)) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS locations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(40) NOT NULL,
            building VARCHAR(160) NULL,
            room VARCHAR(160) NULL,
            shelf VARCHAR(160) NULL,
            compartment VARCHAR(160) NULL,
            notes TEXT NULL,
            is_loose TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_locations_code (code),
            KEY idx_locations_active (active),
            KEY idx_locations_path (building, room, shelf, compartment)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!table_column_exists($pdo, 'locations', 'group_code')) {
        $pdo->exec("ALTER TABLE locations ADD COLUMN group_code VARCHAR(5) NULL AFTER code");
    }
    if (!table_column_exists($pdo, 'locations', 'compartment_no')) {
        $pdo->exec("ALTER TABLE locations ADD COLUMN compartment_no INT UNSIGNED NULL AFTER compartment");
    }
    if (!table_column_exists($pdo, 'locations', 'group_size')) {
        $pdo->exec("ALTER TABLE locations ADD COLUMN group_size INT UNSIGNED NULL AFTER compartment_no");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS location_code_aliases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            alias_code VARCHAR(40) NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_location_alias_code (alias_code),
            KEY idx_location_alias_location (location_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS book_covers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            book_id BIGINT UNSIGNED NOT NULL,
            source_key VARCHAR(50) NOT NULL,
            source_name VARCHAR(120) NOT NULL,
            remote_url VARCHAR(1000) NOT NULL,
            local_path VARCHAR(500) NULL,
            mime_type VARCHAR(80) NULL,
            width INT UNSIGNED NULL,
            height INT UNSIGNED NULL,
            file_size INT UNSIGNED NULL,
            fetch_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            error_message TEXT NULL,
            fetched_at DATETIME NULL,
            selected_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_book_covers_source (book_id, source_key),
            KEY idx_book_covers_book (book_id),
            KEY idx_book_covers_status (fetch_status),
            CONSTRAINT fk_book_covers_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Version 6.4: keine automatische Freitext-Standortmigration.
    // Frische Installationen und leere Datenbanken starten ausschließlich mit
    // haushaltsspezifischen TRIAMO-Standorten. Der lose Standort wird nach dem
    // Anlegen des Haushalts über ensure_household_loose_location() erzeugt.

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS book_metadata (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            book_id BIGINT UNSIGNED NOT NULL,
            isbn13 VARCHAR(13) NULL,
            source_key VARCHAR(50) NOT NULL,
            source_name VARCHAR(120) NOT NULL,
            fetch_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            title VARCHAR(500) NULL,
            subtitle VARCHAR(500) NULL,
            authors VARCHAR(1000) NULL,
            publisher VARCHAR(255) NULL,
            published_date VARCHAR(30) NULL,
            description MEDIUMTEXT NULL,
            page_count INT UNSIGNED NULL,
            categories TEXT NULL,
            language VARCHAR(20) NULL,
            isbn10 VARCHAR(10) NULL,
            cover_url VARCHAR(1000) NULL,
            external_url VARCHAR(1000) NULL,
            raw_payload LONGTEXT NULL,
            error_message TEXT NULL,
            http_status INT NULL,
            fetched_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_book_metadata_source (book_id, source_key),
            KEY idx_book_metadata_isbn (isbn13),
            KEY idx_book_metadata_status (fetch_status),
            CONSTRAINT fk_book_metadata_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS book_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            book_id BIGINT UNSIGNED NOT NULL,
            copy_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(50) NOT NULL,
            summary VARCHAR(500) NOT NULL,
            details LONGTEXT NULL,
            source_key VARCHAR(120) NULL,
            occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_book_history_source (source_key),
            KEY idx_book_history_book (book_id, occurred_at),
            KEY idx_book_history_copy (copy_id),
            CONSTRAINT fk_book_history_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
            CONSTRAINT fk_book_history_copy FOREIGN KEY (copy_id) REFERENCES copies(id) ON DELETE SET NULL,
            CONSTRAINT fk_book_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS library_reminder_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            copy_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            reminder_kind VARCHAR(30) NOT NULL,
            reminder_date DATE NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_library_reminder (copy_id, user_id, reminder_kind),
            KEY idx_library_reminder_date (reminder_date),
            CONSTRAINT fk_library_reminder_copy FOREIGN KEY (copy_id) REFERENCES copies(id) ON DELETE CASCADE,
            CONSTRAINT fk_library_reminder_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Vorhandene Daten werden einmalig in die neue, buchbezogene Historie übernommen.
    $pdo->exec(
        "INSERT IGNORE INTO book_history
            (book_id, user_id, event_type, summary, details, source_key, occurred_at)
         SELECT book_id, user_id, 'inventory_scan', 'Bestand per ISBN-Scan bestätigt',
                CONCAT('{\"isbn\":\"', isbn13, '\"}'), CONCAT('inventory_check:', id), checked_at
         FROM inventory_checks"
    );
    $pdo->exec(
        "INSERT IGNORE INTO book_history
            (book_id, copy_id, user_id, event_type, summary, source_key, occurred_at)
         SELECT c.book_id, c.id, l.created_by, 'loan_created', 'Verleihung eingetragen',
                CONCAT('loan_created:', l.id), l.loaned_at
         FROM loans l JOIN copies c ON c.id = l.copy_id"
    );
    $pdo->exec(
        "INSERT IGNORE INTO book_history
            (book_id, copy_id, user_id, event_type, summary, details, source_key, occurred_at)
         SELECT c.book_id, c.id, l.created_by, 'loan_returned', 'Rückgabe eingetragen',
                NULL,
                CONCAT('loan_returned:', l.id), l.returned_at
         FROM loans l JOIN copies c ON c.id = l.copy_id
         WHERE l.returned_at IS NOT NULL"
    );

    // Keine automatische Masseneinplanung alter Bücher mehr.
    // In früheren Entwicklungsständen wurden hier bei jedem Request erneut
    // Metadatenjobs für Bücher ohne Cover oder ohne Quellenzeile erzeugt.
    // Seit die Schema-Prüfung bewusst bei jedem Request läuft, führte das zu
    // tausenden historischer erledigter Jobs. Neue Jobs entstehen jetzt nur noch
    // beim Scan, beim manuellen Neuabruf oder beim expliziten Reparaturlauf.
}


/**
 * Wartung: Prüft robust, ob ein Index in einer Tabelle vorhanden ist.
 * Aufgerufen von: apply_schema_migrations_v6(), apply_schema_migrations_v62(), drop_index_if_exists().
 * Abhängigkeiten: query().
 */
function table_index_exists(PDO $pdo, string $table, string $index): bool
{
    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $index)) {
        throw new InvalidArgumentException('Ungültiger Tabellen- oder Indexname.');
    }
    try {
        $stmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $pdo->quote($index));
        return (bool)$stmt->fetch();
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '42S02') {
            return false;
        }
        throw $e;
    }
}


/**
 * Wartung: Entfernt einen Index nur dann, wenn er tatsächlich existiert.
 * Aufgerufen von: apply_schema_migrations_v62().
 * Abhängigkeiten: exec(), table_index_exists().
 */
function drop_index_if_exists(PDO $pdo, string $table, string $index): void
{
    if (table_index_exists($pdo, $table, $index)) {
        $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    }
}

/**
 * Wartung: Stellt sicher, dass ein Haushalt einen losen Standardstandort besitzt.
 * Aufgerufen von: apply_schema_migrations(), create_household_for_user(), loose_location(), restore_household_backup().
 * Abhängigkeiten: new_location_group_code(), prepare().
 */
function ensure_household_loose_location(PDO $pdo, int $householdId): int
{
    $stmt = $pdo->prepare("SELECT id FROM locations WHERE household_id = ? AND is_loose = 1 ORDER BY id LIMIT 1");
    $stmt->execute([$householdId]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    $groupCode = new_location_group_code($pdo);
    $code = 'TRIAMO-' . $groupCode . '-0';
    $stmt = $pdo->prepare(
        "INSERT INTO locations
            (household_id, code, group_code, building, compartment_no, group_size, is_loose, active)
         VALUES (?, ?, ?, 'Kein Standort / lose', 0, 1, 1, 1)"
    );
    $stmt->execute([$householdId, $code, $groupCode]);
    return (int)$pdo->lastInsertId();
}

/**
 * Wartung: Ergänzt Haushalts-, Freigabe- und Buchsichtbarkeitsstrukturen.
 * Aufgerufen von: db().
 * Abhängigkeiten: exec(), table_column_exists(), table_column_info(), table_index_exists().
 */
function apply_schema_migrations_v6(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS households (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            owner_user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_households_owner (owner_user_id),
            KEY idx_households_active (active),
            CONSTRAINT fk_households_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS household_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            household_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            member_role VARCHAR(20) NOT NULL DEFAULT 'owner',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_household_member (household_id, user_id),
            KEY idx_household_members_user (user_id, active),
            CONSTRAINT fk_household_members_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
            CONSTRAINT fk_household_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        ['copies', 'household_id', "ALTER TABLE copies ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER id"],
        ['locations', 'household_id', "ALTER TABLE locations ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER id"],
        ['inventory_checks', 'household_id', "ALTER TABLE inventory_checks ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER id"],
        ['book_history', 'household_id', "ALTER TABLE book_history ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER id"],
        ['reservations', 'household_id', "ALTER TABLE reservations ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER id"],
        ['book_metadata', 'household_id', "ALTER TABLE book_metadata ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER book_id"],
        ['book_metadata', 'source_scope', "ALTER TABLE book_metadata ADD COLUMN source_scope VARCHAR(20) NOT NULL DEFAULT 'external' AFTER source_name"],
    ];
    foreach ($columns as [$table, $column, $sql]) {
        if (!table_column_exists($pdo, $table, $column)) {
            $pdo->exec($sql);
        }
    }
    $sourceKeyColumn = table_column_info($pdo, 'book_metadata', 'source_key');
    $sourceKeyType = strtolower((string)($sourceKeyColumn['Type'] ?? ''));
    $sourceKeyNull = strtoupper((string)($sourceKeyColumn['Null'] ?? ''));
    if ($sourceKeyType !== 'varchar(100)' || $sourceKeyNull !== 'NO') {
        $pdo->exec("ALTER TABLE book_metadata MODIFY source_key VARCHAR(100) NOT NULL");
    }

    $indexes = [
        ['copies', 'idx_copies_household_book', "ALTER TABLE copies ADD KEY idx_copies_household_book (household_id, book_id, deleted_at)"],
        ['locations', 'idx_locations_household', "ALTER TABLE locations ADD KEY idx_locations_household (household_id, active, is_loose)"],
        ['inventory_checks', 'idx_inventory_household', "ALTER TABLE inventory_checks ADD KEY idx_inventory_household (household_id, checked_at)"],
        ['book_history', 'idx_history_household', "ALTER TABLE book_history ADD KEY idx_history_household (household_id, occurred_at)"],
        ['reservations', 'idx_res_household', "ALTER TABLE reservations ADD KEY idx_res_household (household_id, status, book_id)"],
        ['book_metadata', 'idx_metadata_household', "ALTER TABLE book_metadata ADD KEY idx_metadata_household (household_id, source_scope)"],
    ];
    foreach ($indexes as [$table, $index, $sql]) {
        if (!table_index_exists($pdo, $table, $index)) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS household_book_settings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            household_id BIGINT UNSIGNED NOT NULL,
            book_id BIGINT UNSIGNED NOT NULL,
            title_override VARCHAR(500) NULL,
            subtitle_override VARCHAR(500) NULL,
            authors_override VARCHAR(1000) NULL,
            publisher_override VARCHAR(255) NULL,
            published_date_override VARCHAR(30) NULL,
            description_override MEDIUMTEXT NULL,
            page_count_override INT UNSIGNED NULL,
            categories_override TEXT NULL,
            language_override VARCHAR(20) NULL,
            visibility VARCHAR(20) NOT NULL DEFAULT 'lendable',
            selected_cover_id BIGINT UNSIGNED NULL,
            adopted_metadata_id BIGINT UNSIGNED NULL,
            archived_at DATETIME NULL,
            archived_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_household_book (household_id, book_id),
            KEY idx_household_book_visibility (household_id, visibility, archived_at),
            KEY idx_household_book_book (book_id),
            CONSTRAINT fk_hbs_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
            CONSTRAINT fk_hbs_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
            CONSTRAINT fk_hbs_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS public_share_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            household_id BIGINT UNSIGNED NOT NULL,
            description VARCHAR(500) NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            access_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_accessed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_share_household (household_id, revoked_at, expires_at),
            CONSTRAINT fk_share_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
            CONSTRAINT fk_share_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS public_share_access_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            share_link_id BIGINT UNSIGNED NOT NULL,
            accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_hash CHAR(64) NULL,
            user_agent VARCHAR(500) NULL,
            referrer VARCHAR(1000) NULL,
            PRIMARY KEY (id),
            KEY idx_share_access (share_link_id, accessed_at),
            CONSTRAINT fk_share_access_link FOREIGN KEY (share_link_id) REFERENCES public_share_links(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS household_access_keys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            household_id BIGINT UNSIGNED NOT NULL,
            key_hash CHAR(64) NOT NULL,
            key_hint VARCHAR(20) NOT NULL,
            key_ciphertext TEXT NULL,
            note VARCHAR(500) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            redeemed_at DATETIME NULL,
            redeemed_by BIGINT UNSIGNED NULL,
            revoked_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_household_access_hash (key_hash),
            KEY idx_household_access_active (household_id, active),
            CONSTRAINT fk_access_key_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
            CONSTRAINT fk_access_key_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS household_access_grants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            owner_household_id BIGINT UNSIGNED NOT NULL,
            viewer_user_id BIGINT UNSIGNED NOT NULL,
            access_key_id BIGINT UNSIGNED NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paused_at DATETIME NULL,
            paused_by BIGINT UNSIGNED NULL,
            revoked_at DATETIME NULL,
            revoked_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_household_viewer (owner_household_id, viewer_user_id),
            KEY idx_access_grants_viewer (viewer_user_id, active),
            CONSTRAINT fk_access_grant_household FOREIGN KEY (owner_household_id) REFERENCES households(id) ON DELETE CASCADE,
            CONSTRAINT fk_access_grant_viewer FOREIGN KEY (viewer_user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_access_grant_key FOREIGN KEY (access_key_id) REFERENCES household_access_keys(id) ON DELETE SET NULL,
            CONSTRAINT fk_access_grant_revoked_by FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Aktuelle Tabellen sind vollständig definiert. Keine historischen Zwischenstände werden hier migriert.

    // Haushalte und lose Standorte entstehen beim Setup, bei der Registrierung oder beim ersten Login.
}


// ======================== REQUEST-, JSON- UND BACKUP-HILFSFUNKTIONEN ========================
/**
 * Wartung: Sendet eine JSON-Antwort und beendet den Request.
 * Aufgerufen von: Globaler Ablauf/API/Events, cancel_metadata_job(), create_backup_payload(), json_input(), require_admin(), require_household_access(), require_login(), +4 weitere.
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Wartung: Liest JSON- oder Formulardaten aus dem aktuellen Request.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: json_response().
 */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['ok' => false, 'error' => 'Ungültige JSON-Daten.'], 400);
    }
    return $data;
}

/**
 * Wartung: Erzwingt eine HTTP-Methode für schreibende Endpunkte.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: json_response().
 */
function require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
        json_response(['ok' => false, 'error' => 'Methode nicht erlaubt.'], 405);
    }
}

/**
 * Wartung: Ergänzt Zugriffsschlüssel- und Pausenfelder aus Version 6.1.
 * Aufgerufen von: db().
 * Abhängigkeiten: exec(), table_column_exists().
 */
function apply_schema_migrations_v61(PDO $pdo): void
{
    $columns = [
        ['household_access_keys', 'key_ciphertext', "ALTER TABLE household_access_keys ADD COLUMN key_ciphertext TEXT NULL AFTER key_hint"],
        ['household_access_keys', 'note', "ALTER TABLE household_access_keys ADD COLUMN note VARCHAR(500) NULL AFTER key_ciphertext"],
        ['household_access_keys', 'redeemed_at', "ALTER TABLE household_access_keys ADD COLUMN redeemed_at DATETIME NULL AFTER created_at"],
        ['household_access_keys', 'redeemed_by', "ALTER TABLE household_access_keys ADD COLUMN redeemed_by BIGINT UNSIGNED NULL AFTER redeemed_at"],
        ['household_access_grants', 'paused_at', "ALTER TABLE household_access_grants ADD COLUMN paused_at DATETIME NULL AFTER created_at"],
        ['household_access_grants', 'paused_by', "ALTER TABLE household_access_grants ADD COLUMN paused_by BIGINT UNSIGNED NULL AFTER paused_at"],
    ];
    foreach ($columns as [$table, $column, $sql]) {
        if (!table_column_exists($pdo, $table, $column)) {
            $pdo->exec($sql);
        }
    }
    $pdo->exec(
        "UPDATE household_access_keys k
         JOIN (
            SELECT access_key_id, MIN(created_at) AS redeemed_at, MIN(viewer_user_id) AS redeemed_by
            FROM household_access_grants WHERE access_key_id IS NOT NULL GROUP BY access_key_id
         ) g ON g.access_key_id=k.id
         SET k.redeemed_at=COALESCE(k.redeemed_at,g.redeemed_at),
             k.redeemed_by=COALESCE(k.redeemed_by,g.redeemed_by), k.active=0
         WHERE k.redeemed_at IS NULL"
    );
}


/**
 * Wartung: Stellt haushaltsspezifische Standortcodes und Alias-Indizes her.
 * Aufgerufen von: db().
 * Abhängigkeiten: cleanup_location_code_collisions(), drop_index_if_exists(), exec(), table_column_exists(), table_index_exists().
 */
function apply_schema_migrations_v62(PDO $pdo): void
{
    cleanup_location_code_collisions($pdo);

    // Standort-Barcodes sind haushaltsspezifisch. Daher darf derselbe Code in
    // unterschiedlichen Haushalten vorkommen, innerhalb eines Haushalts aber nicht.
    if (!table_index_exists($pdo, 'locations', 'uq_locations_household_code')) {
        drop_index_if_exists($pdo, 'locations', 'uq_locations_code');
        $pdo->exec("ALTER TABLE locations ADD UNIQUE KEY uq_locations_household_code (household_id, code)");
    }

    if (!table_column_exists($pdo, 'location_code_aliases', 'household_id')) {
        $pdo->exec("ALTER TABLE location_code_aliases ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER id");
        $pdo->exec(
            "UPDATE location_code_aliases a JOIN locations l ON l.id = a.location_id
             SET a.household_id = l.household_id WHERE a.household_id IS NULL"
        );
    }
    if (!table_index_exists($pdo, 'location_code_aliases', 'uq_location_alias_household_code')) {
        drop_index_if_exists($pdo, 'location_code_aliases', 'uq_location_alias_code');
        $pdo->exec("ALTER TABLE location_code_aliases ADD UNIQUE KEY uq_location_alias_household_code (household_id, alias_code)");
    }
    if (!table_index_exists($pdo, 'location_code_aliases', 'idx_location_alias_household')) {
        $pdo->exec("ALTER TABLE location_code_aliases ADD KEY idx_location_alias_household (household_id, location_id)");
    }
}

/**
 * Wartung: Bereinigt alte DNB-Steuerzeichen einmalig und markerbasiert.
 * Aufgerufen von: db().
 * Abhängigkeiten: mark_schema_migration_done(), prepare(), schema_migration_done(), table_column_exists().
 */
function apply_schema_migrations_v68(PDO $pdo): void
{
    $migrationKey = 'v68_dnb_control_chars_cleaned';
    if (schema_migration_done($pdo, $migrationKey)) {
        return;
    }

    // Alte DNB/MARC-Steuerzeichen aus bereits gespeicherten Titeln und Metadaten entfernen.
    // Die teuren UPDATE-Läufe werden nur ausgeführt, wenn die Zeichen wirklich gefunden wurden.
    $tables = [
        'books' => ['title', 'subtitle', 'authors', 'publisher', 'published_date', 'description', 'categories', 'language'],
        'book_metadata' => ['title', 'subtitle', 'authors', 'publisher', 'published_date', 'description', 'categories', 'language'],
        'household_book_settings' => ['title_override', 'subtitle_override', 'authors_override', 'publisher_override', 'published_date_override', 'description_override', 'categories_override', 'language_override'],
        'book_history' => ['summary', 'details'],
    ];
    $badChars = ["\xC2\x98", "\xC2\x9C"];
    $changedColumns = 0;

    foreach ($tables as $table => $columns) {
        foreach ($columns as $column) {
            if (!table_column_exists($pdo, $table, $column)) {
                continue;
            }
            foreach ($badChars as $badChar) {
                $check = $pdo->prepare("SELECT 1 FROM `$table` WHERE `$column` LIKE ? LIMIT 1");
                $check->execute(['%' . $badChar . '%']);
                if (!$check->fetchColumn()) {
                    continue;
                }
                $stmt = $pdo->prepare("UPDATE `$table` SET `$column` = REPLACE(`$column`, ?, '') WHERE `$column` LIKE ?");
                $stmt->execute([$badChar, '%' . $badChar . '%']);
                $changedColumns++;
            }
        }
    }

    mark_schema_migration_done($pdo, $migrationKey, 'Bereinigte Spaltenläufe: ' . $changedColumns);
}


/**
 * Wartung: Ergänzt die Tabellen für mehrere Buchdateien und deren Freigabestatus.
 * Aufgerufen von: db().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function apply_schema_migrations_v70(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS book_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            household_id BIGINT UNSIGNED NOT NULL,
            book_id BIGINT UNSIGNED NOT NULL,
            sequence_no INT UNSIGNED NOT NULL,
            original_name VARCHAR(500) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            local_path VARCHAR(700) NOT NULL,
            mime_type VARCHAR(160) NULL,
            file_extension VARCHAR(20) NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sha256 CHAR(64) NOT NULL,
            comment TEXT NULL,
            share_allowed TINYINT(1) NOT NULL DEFAULT 0,
            uploaded_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            deleted_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_book_files_sequence (household_id, book_id, sequence_no),
            KEY idx_book_files_book (household_id, book_id, deleted_at),
            KEY idx_book_files_share (household_id, book_id, share_allowed, deleted_at),
            CONSTRAINT fk_book_files_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
            CONSTRAINT fk_book_files_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
            CONSTRAINT fk_book_files_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_book_files_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}


/**
 * Wartung: Ergänzt Sicherheits- und Kontoverwaltungsfelder sowie Einmallink-Tabellen.
 * Aufgerufen von: db().
 * Abhängigkeiten: exec(), table_column_exists().
 */
function apply_schema_migrations_v71(PDO $pdo): void
{
    $columns = [
        ['users', 'last_login_at', "ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER active"],
        ['users', 'email_verified_at', "ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER last_login_at"],
        ['users', 'session_version', "ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER email_verified_at"],
        ['users', 'anonymized_at', "ALTER TABLE users ADD COLUMN anonymized_at DATETIME NULL AFTER session_version"],
    ];
    foreach ($columns as [$table, $column, $sql]) {
        if (!table_column_exists($pdo, $table, $column)) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_password_reset_token (token_hash),
            KEY idx_password_reset_user (user_id, used_at, expires_at),
            CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_password_reset_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_verification_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_email_verification_token (token_hash),
            KEY idx_email_verification_user (user_id, used_at, expires_at),
            CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_email_verification_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * Wartung: Definiert die Export- und Importreihenfolge der Backup-Tabellen.
 * Aufgerufen von: create_backup_payload(), restore_full_backup().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function backup_table_order(): array
{
    return [
        'users', 'books', 'households', 'household_members', 'locations', 'location_code_aliases',
        'household_book_settings', 'book_metadata', 'book_covers', 'book_files', 'copies', 'loans', 'reservations',
        'jobs', 'email_queue', 'audit_log', 'inventory_checks', 'book_history', 'library_reminder_log',
        'public_share_links', 'public_share_access_log', 'household_access_keys', 'household_access_grants'
    ];
}

/**
 * Wartung: Liest Tabellenzeilen für den Backup-Export.
 * Aufgerufen von: create_backup_payload().
 * Abhängigkeiten: prepare(), query().
 */
function backup_rows(PDO $pdo, string $table, string $where = '', array $params = []): array
{
    $sql = 'SELECT * FROM ' . $table . ($where !== '' ? ' WHERE ' . $where : '');
    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) {
        $stmt->execute($params);
    }
    return $stmt->fetchAll();
}

/**
 * Wartung: Sammelt referenzierte IDs aus bereits exportierten Backup-Zeilen.
 * Aufgerufen von: create_backup_payload().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function backup_referenced_ids(array $rows, string $field): array
{
    $ids = [];
    foreach ($rows as $row) {
        $value = (int)($row[$field] ?? 0);
        if ($value > 0) {
            $ids[$value] = true;
        }
    }
    return array_keys($ids);
}

/**
 * Wartung: Lädt Tabellenzeilen anhand einer ID-Liste für haushaltsbezogene Backups.
 * Aufgerufen von: create_backup_payload().
 * Abhängigkeiten: prepare().
 */
function select_rows_by_ids(PDO $pdo, string $table, string $field, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE ' . $field . ' IN (' . $placeholders . ')');
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

/**
 * Wartung: Erstellt den vollständigen oder haushaltsbezogenen Backup-Datensatz.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: backup_referenced_ids(), backup_rows(), backup_table_order(), db(), db_table_prefix(), json_response(), select_rows_by_ids().
 */
function create_backup_payload(string $scope, array $user): array
{
    $pdo = db();
    $scope = $scope === 'all' ? 'all' : 'household';
    if ($scope === 'all' && ($user['role'] ?? '') !== 'admin') {
        json_response(['ok' => false, 'error' => 'Nur Administratoren dürfen ein vollständiges Systembackup erstellen.'], 403);
    }
    $payload = [
        'format' => 'triamo-backup-v1',
        'app' => APP_NAME,
        'scope' => $scope,
        'exported_at' => date(DATE_ATOM),
        'table_prefix' => db_table_prefix(),
        'household_id' => $scope === 'household' ? (int)$user['active_household_id'] : null,
        'household_name' => $scope === 'household' ? (string)$user['active_household_name'] : null,
        'tables' => [],
    ];

    if ($scope === 'all') {
        foreach (backup_table_order() as $table) {
            $payload['tables'][$table] = backup_rows($pdo, $table);
        }
        return $payload;
    }

    $householdId = (int)$user['active_household_id'];
    $payload['tables']['households'] = backup_rows($pdo, 'households', 'id = ?', [$householdId]);
    $payload['tables']['household_members'] = backup_rows($pdo, 'household_members', 'household_id = ?', [$householdId]);
    $payload['tables']['locations'] = backup_rows($pdo, 'locations', 'household_id = ?', [$householdId]);
    $payload['tables']['location_code_aliases'] = backup_rows($pdo, 'location_code_aliases', 'household_id = ?', [$householdId]);
    $payload['tables']['household_book_settings'] = backup_rows($pdo, 'household_book_settings', 'household_id = ?', [$householdId]);
    $payload['tables']['copies'] = backup_rows($pdo, 'copies', 'household_id = ?', [$householdId]);
    $payload['tables']['inventory_checks'] = backup_rows($pdo, 'inventory_checks', 'household_id = ?', [$householdId]);
    $payload['tables']['book_history'] = backup_rows($pdo, 'book_history', 'household_id = ?', [$householdId]);
    $payload['tables']['reservations'] = backup_rows($pdo, 'reservations', 'household_id = ?', [$householdId]);
    $payload['tables']['public_share_links'] = backup_rows($pdo, 'public_share_links', 'household_id = ?', [$householdId]);
    $payload['tables']['household_access_keys'] = backup_rows($pdo, 'household_access_keys', 'household_id = ?', [$householdId]);
    $payload['tables']['household_access_grants'] = backup_rows($pdo, 'household_access_grants', 'owner_household_id = ?', [$householdId]);

    $shareIds = backup_referenced_ids($payload['tables']['public_share_links'], 'id');
    $payload['tables']['public_share_access_log'] = select_rows_by_ids($pdo, 'public_share_access_log', 'share_link_id', $shareIds);
    $copyIds = backup_referenced_ids($payload['tables']['copies'], 'id');
    $payload['tables']['loans'] = select_rows_by_ids($pdo, 'loans', 'copy_id', $copyIds);
    $bookIds = array_unique(array_merge(
        backup_referenced_ids($payload['tables']['household_book_settings'], 'book_id'),
        backup_referenced_ids($payload['tables']['copies'], 'book_id'),
        backup_referenced_ids($payload['tables']['reservations'], 'book_id'),
        backup_referenced_ids($payload['tables']['book_history'], 'book_id'),
        backup_referenced_ids($payload['tables']['inventory_checks'], 'book_id')
    ));
    $payload['tables']['books'] = select_rows_by_ids($pdo, 'books', 'id', $bookIds);
    $payload['tables']['book_metadata'] = select_rows_by_ids($pdo, 'book_metadata', 'book_id', $bookIds);
    $payload['tables']['book_covers'] = select_rows_by_ids($pdo, 'book_covers', 'book_id', $bookIds);
    $payload['tables']['book_files'] = backup_rows($pdo, 'book_files', 'household_id = ?', [$householdId]);

    $userIds = [];
    foreach (['household_members' => 'user_id', 'loans' => 'user_id', 'reservations' => 'user_id', 'book_history' => 'user_id', 'inventory_checks' => 'user_id'] as $table => $field) {
        $userIds = array_merge($userIds, backup_referenced_ids($payload['tables'][$table] ?? [], $field));
    }
    $userIds[] = (int)$user['id'];
    $payload['tables']['users'] = select_rows_by_ids($pdo, 'users', 'id', $userIds);
    return $payload;
}

/**
 * Wartung: Fügt eine Backup-Zeile sicher mit dynamischen Spalten ein.
 * Aufgerufen von: map_inserted_book(), restore_full_backup(), restore_household_backup().
 * Abhängigkeiten: prepare().
 */
function insert_backup_row(PDO $pdo, string $table, array $row, ?array $onlyColumns = null): void
{
    if (!$row) {
        return;
    }
    if ($onlyColumns !== null) {
        $row = array_intersect_key($row, array_flip($onlyColumns));
    }
    if (!$row) {
        return;
    }
    $columns = array_keys($row);
    foreach ($columns as $column) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new RuntimeException('Ungültiger Spaltenname im Backup: ' . $column);
        }
    }
    $sql = 'INSERT INTO ' . $table . ' (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($row));
}

/**
 * Wartung: Stellt ein vollständiges Systembackup wieder her.
 * Aufgerufen von: restore_backup_payload().
 * Abhängigkeiten: backup_table_order(), db(), exec(), insert_backup_row(), json_response(), unmark_schema_migration().
 */
function restore_full_backup(array $backup, array $user): void
{
    if (($user['role'] ?? '') !== 'admin') {
        json_response(['ok' => false, 'error' => 'Nur Administratoren dürfen ein vollständiges Systembackup wiederherstellen.'], 403);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_reverse(backup_table_order()) as $table) {
            if (array_key_exists($table, $backup['tables'] ?? [])) {
                $pdo->exec('DELETE FROM ' . $table);
                $pdo->exec('ALTER TABLE ' . $table . ' AUTO_INCREMENT=1');
            }
        }
        foreach (backup_table_order() as $table) {
            foreach (($backup['tables'][$table] ?? []) as $row) {
                insert_backup_row($pdo, $table, $row);
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        unmark_schema_migration($pdo, 'v68_dnb_control_chars_cleaned');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
        throw $e;
    }
}

/**
 * Wartung: Liefert die erste Zeile einer Backup-Tabelle oder null.
 * Aufgerufen von: restore_household_backup().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function first_backup_row(array $backup, string $table): ?array
{
    $rows = $backup['tables'][$table] ?? [];
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

/**
 * Wartung: Ordnet alte Buch-IDs beim Restore bestehenden oder neu eingefügten Büchern zu.
 * Aufgerufen von: restore_household_backup().
 * Abhängigkeiten: insert_backup_row(), prepare().
 */
function map_inserted_book(PDO $pdo, array $row, array &$bookMap): int
{
    $oldId = (int)($row['id'] ?? 0);
    $isbn13 = trim((string)($row['isbn13'] ?? ''));
    $isbn10 = trim((string)($row['isbn10'] ?? ''));
    if ($isbn13 !== '') {
        $stmt = $pdo->prepare('SELECT id FROM books WHERE isbn13 = ? LIMIT 1');
        $stmt->execute([$isbn13]);
        $existing = (int)($stmt->fetchColumn() ?: 0);
        if ($existing > 0) { $bookMap[$oldId] = $existing; return $existing; }
    }
    if ($isbn10 !== '') {
        $stmt = $pdo->prepare('SELECT id FROM books WHERE isbn10 = ? LIMIT 1');
        $stmt->execute([$isbn10]);
        $existing = (int)($stmt->fetchColumn() ?: 0);
        if ($existing > 0) { $bookMap[$oldId] = $existing; return $existing; }
    }
    unset($row['id']);
    insert_backup_row($pdo, 'books', $row);
    $newId = (int)$pdo->lastInsertId();
    $bookMap[$oldId] = $newId;
    return $newId;
}

/**
 * Wartung: Stellt ein Haushaltsbackup als neuen Haushalt wieder her.
 * Aufgerufen von: restore_backup_payload().
 * Abhängigkeiten: audit(), db(), ensure_household_loose_location(), first_backup_row(), insert_backup_row(), map_inserted_book(), prepare(), +2 weitere.
 */
function restore_household_backup(array $backup, array $user): int
{
    require_household_manager();
    $pdo = db();
    $sourceHousehold = first_backup_row($backup, 'households') ?: [];
    $name = trim((string)($sourceHousehold['name'] ?? $backup['household_name'] ?? 'Wiederhergestellter Haushalt'));
    $name = mb_substr(($name !== '' ? $name : 'Wiederhergestellter Haushalt') . ' Restore ' . date('Y-m-d H:i'), 0, 160);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO households (owner_user_id, name, active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())');
        $stmt->execute([(int)$user['id'], $name]);
        $newHouseholdId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO household_members (household_id, user_id, member_role, active) VALUES (?, ?, 'owner', 1)")
            ->execute([$newHouseholdId, (int)$user['id']]);

        $userMap = [(int)$user['id'] => (int)$user['id']];
        foreach (($backup['tables']['users'] ?? []) as $u) {
            $oldId = (int)($u['id'] ?? 0);
            if ($oldId < 1 || isset($userMap[$oldId])) { continue; }
            $email = trim((string)($u['email'] ?? ''));
            $existing = 0;
            if ($email !== '') {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $existing = (int)($stmt->fetchColumn() ?: 0);
            }
            if ($existing > 0) { $userMap[$oldId] = $existing; continue; }
            $placeholderEmail = 'restore-' . $oldId . '-' . bin2hex(random_bytes(3)) . '@local.invalid';
            $stmt = $pdo->prepare('INSERT INTO users (email,password_hash,display_name,role,active) VALUES (?,?,?,?,0)');
            $stmt->execute([$email !== '' ? $email : $placeholderEmail, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), mb_substr((string)($u['display_name'] ?? 'Wiederhergestellter Benutzer'),0,120), 'member']);
            $userMap[$oldId] = (int)$pdo->lastInsertId();
        }

        $bookMap = [];
        foreach (($backup['tables']['books'] ?? []) as $book) {
            map_inserted_book($pdo, $book, $bookMap);
        }
        foreach (($backup['tables']['book_metadata'] ?? []) as $meta) {
            $oldBookId = (int)($meta['book_id'] ?? 0);
            if (!isset($bookMap[$oldBookId])) { continue; }
            unset($meta['id']);
            $meta['book_id'] = $bookMap[$oldBookId];
            $meta['household_id'] = isset($meta['household_id']) && $meta['household_id'] !== null ? $newHouseholdId : null;
            try { insert_backup_row($pdo, 'book_metadata', $meta); } catch (Throwable $ignored) {}
        }
        foreach (($backup['tables']['book_covers'] ?? []) as $cover) {
            $oldBookId = (int)($cover['book_id'] ?? 0);
            if (!isset($bookMap[$oldBookId])) { continue; }
            unset($cover['id']);
            $cover['book_id'] = $bookMap[$oldBookId];
            try { insert_backup_row($pdo, 'book_covers', $cover); } catch (Throwable $ignored) {}
        }

        // Buchdateien werden bei einer Wiederherstellung innerhalb derselben Installation
        // physisch in den neuen Haushaltsordner kopiert. Fehlt die Quelldatei, wird kein
        // verwaister Datenbankeintrag erzeugt.
        foreach (($backup['tables']['book_files'] ?? []) as $fileRow) {
            $oldBookId = (int)($fileRow['book_id'] ?? 0);
            if (!isset($bookMap[$oldBookId]) || !empty($fileRow['deleted_at'])) { continue; }
            $sourceRelative = (string)($fileRow['local_path'] ?? '');
            $sourceAbsolute = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourceRelative));
            $filesRoot = realpath(triamo_data_dir() . DIRECTORY_SEPARATOR . 'files');
            if (!$filesRoot || !$sourceAbsolute || !str_starts_with($sourceAbsolute, $filesRoot . DIRECTORY_SEPARATOR) || !is_file($sourceAbsolute)) {
                continue;
            }
            $newBookId = $bookMap[$oldBookId];
            $sequence = max(1, (int)($fileRow['sequence_no'] ?? 1));
            $extension = strtolower((string)($fileRow['file_extension'] ?? pathinfo((string)($fileRow['stored_name'] ?? ''), PATHINFO_EXTENSION)));
            if (!in_array($extension, allowed_book_file_extensions(), true)) { continue; }
            $bookStmt = $pdo->prepare('SELECT isbn13, isbn10 FROM books WHERE id = ?');
            $bookStmt->execute([$newBookId]);
            $bookData = $bookStmt->fetch() ?: [];
            $isbnPart = preg_replace('/[^0-9X]+/i', '', (string)($bookData['isbn13'] ?: ($bookData['isbn10'] ?? '')));
            $baseName = $isbnPart !== '' ? $isbnPart : ('B' . str_pad((string)$newBookId, 8, '0', STR_PAD_LEFT));
            $storedName = $baseName . '-' . str_pad((string)$sequence, 3, '0', STR_PAD_LEFT) . '.' . $extension;
            $targetDir = ensure_book_files_dir($newHouseholdId, $newBookId);
            $targetAbsolute = $targetDir . DIRECTORY_SEPARATOR . $storedName;
            if (!@copy($sourceAbsolute, $targetAbsolute)) { continue; }
            @chmod($targetAbsolute, 0640);
            unset($fileRow['id']);
            $fileRow['household_id'] = $newHouseholdId;
            $fileRow['book_id'] = $newBookId;
            $fileRow['sequence_no'] = $sequence;
            $fileRow['stored_name'] = $storedName;
            $fileRow['local_path'] = 'bookvault_data/files/h' . $newHouseholdId . '/b' . $newBookId . '/' . $storedName;
            $fileRow['file_size'] = filesize($targetAbsolute);
            $fileRow['sha256'] = hash_file('sha256', $targetAbsolute);
            $fileRow['uploaded_by'] = isset($userMap[(int)($fileRow['uploaded_by'] ?? 0)]) ? $userMap[(int)$fileRow['uploaded_by']] : (int)$user['id'];
            $fileRow['deleted_by'] = null;
            $fileRow['deleted_at'] = null;
            try {
                insert_backup_row($pdo, 'book_files', $fileRow);
            } catch (Throwable $ignored) {
                @unlink($targetAbsolute);
            }
        }

        $locationMap = [];
        foreach (($backup['tables']['locations'] ?? []) as $loc) {
            $oldId = (int)($loc['id'] ?? 0);
            unset($loc['id']);
            $loc['household_id'] = $newHouseholdId;
            insert_backup_row($pdo, 'locations', $loc);
            $locationMap[$oldId] = (int)$pdo->lastInsertId();
        }
        if (!$locationMap) {
            ensure_household_loose_location($pdo, $newHouseholdId);
        }
        foreach (($backup['tables']['location_code_aliases'] ?? []) as $alias) {
            $oldLocation = (int)($alias['location_id'] ?? 0);
            if (!isset($locationMap[$oldLocation])) { continue; }
            unset($alias['id']);
            $alias['location_id'] = $locationMap[$oldLocation];
            $alias['household_id'] = $newHouseholdId;
            try { insert_backup_row($pdo, 'location_code_aliases', $alias); } catch (Throwable $ignored) {}
        }

        foreach (($backup['tables']['household_book_settings'] ?? []) as $hbs) {
            $oldBookId = (int)($hbs['book_id'] ?? 0);
            if (!isset($bookMap[$oldBookId])) { continue; }
            unset($hbs['id']);
            $hbs['household_id'] = $newHouseholdId;
            $hbs['book_id'] = $bookMap[$oldBookId];
            $hbs['archived_by'] = isset($userMap[(int)($hbs['archived_by'] ?? 0)]) ? $userMap[(int)$hbs['archived_by']] : null;
            $hbs['selected_cover_id'] = null;
            $hbs['adopted_metadata_id'] = null;
            try { insert_backup_row($pdo, 'household_book_settings', $hbs); } catch (Throwable $ignored) {}
        }

        $copyMap = [];
        foreach (($backup['tables']['copies'] ?? []) as $copy) {
            $oldId = (int)($copy['id'] ?? 0);
            $oldBookId = (int)($copy['book_id'] ?? 0);
            if (!isset($bookMap[$oldBookId])) { continue; }
            unset($copy['id']);
            $copy['household_id'] = $newHouseholdId;
            $copy['book_id'] = $bookMap[$oldBookId];
            foreach (['location_id', 'home_location_id'] as $field) {
                $copy[$field] = isset($locationMap[(int)($copy[$field] ?? 0)]) ? $locationMap[(int)$copy[$field]] : null;
            }
            if (!empty($copy['inventory_no'])) {
                $copy['inventory_no'] = 'REST-' . $newHouseholdId . '-' . $copy['inventory_no'];
            }
            try {
                insert_backup_row($pdo, 'copies', $copy);
                $copyMap[$oldId] = (int)$pdo->lastInsertId();
            } catch (Throwable $ignored) {}
        }

        foreach (['loans','reservations','inventory_checks','book_history'] as $table) {
            foreach (($backup['tables'][$table] ?? []) as $row) {
                unset($row['id']);
                if (isset($row['household_id'])) { $row['household_id'] = $newHouseholdId; }
                if (isset($row['copy_id'])) {
                    $oldCopy = (int)$row['copy_id'];
                    if (!isset($copyMap[$oldCopy])) { continue; }
                    $row['copy_id'] = $copyMap[$oldCopy];
                }
                if (isset($row['book_id'])) {
                    $oldBook = (int)$row['book_id'];
                    if (!isset($bookMap[$oldBook])) { continue; }
                    $row['book_id'] = $bookMap[$oldBook];
                }
                foreach (['user_id','created_by','deleted_by','returned_by'] as $field) {
                    if (array_key_exists($field, $row)) {
                        $row[$field] = isset($userMap[(int)($row[$field] ?? 0)]) ? $userMap[(int)$row[$field]] : null;
                    }
                }
                try { insert_backup_row($pdo, $table, $row); } catch (Throwable $ignored) {}
            }
        }

        audit((int)$user['id'], 'backup_household_restored', 'household', $newHouseholdId, ['source' => $backup['household_name'] ?? null]);
        unmark_schema_migration($pdo, 'v68_dnb_control_chars_cleaned');
        $pdo->commit();
        return $newHouseholdId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * Wartung: Validiert ein Backup und verteilt auf Voll- oder Haushaltsrestore.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: json_response(), restore_full_backup(), restore_household_backup().
 */
function restore_backup_payload(array $backup, array $user): array
{
    if (($backup['format'] ?? '') !== 'triamo-backup-v1' || !is_array($backup['tables'] ?? null)) {
        json_response(['ok' => false, 'error' => 'Die Datei ist kein gültiges Triamo-Backup.'], 422);
    }
    if (($backup['scope'] ?? '') === 'all') {
        restore_full_backup($backup, $user);
        return ['mode' => 'all'];
    }
    $newHouseholdId = restore_household_backup($backup, $user);
    return ['mode' => 'household', 'household_id' => $newHouseholdId];
}


// ======================== DATENSCHUTZ-EXPORT ========================
/**
 * Wartung: Führt eine vorbereitete SELECT-Abfrage für den Datenschutz-Export aus.
 * Aufgerufen von: create_privacy_export_payload().
 * Abhängigkeiten: prepare().
 */
function privacy_export_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Wartung: Erstellt eine personenbezogene Datenkopie für den angemeldeten Benutzer.
 * Der Export ist bewusst kontobezogen und gibt keine Passwort-Hashes,
 * Zugriffsschlüssel oder personenbezogenen Daten anderer Benutzer aus.
 * Aufgerufen von: API-Endpunkt privacy_export.
 * Abhängigkeiten: db(), privacy_export_rows().
 */
function create_privacy_export_payload(array $user): array
{
    $pdo = db();
    $userId = (int)$user['id'];
    $email = mb_strtolower(trim((string)$user['email']));

    $accountRows = privacy_export_rows(
        $pdo,
        "SELECT id, email, display_name, role, active, created_at, updated_at, last_login_at, email_verified_at, anonymized_at
         FROM users WHERE id = ? LIMIT 1",
        [$userId]
    );
    $account = $accountRows[0] ?? [];
    $account['passwortspeicherung'] = [
        'klartextpasswort_gespeichert' => false,
        'hinweis' => 'TRIAMO speichert ausschließlich einen Passwort-Hash. Der Hash selbst wird aus Sicherheitsgründen nicht ausgegeben.',
    ];

    $memberships = privacy_export_rows(
        $pdo,
        "SELECT h.id AS household_id, h.name AS household_name, h.active AS household_active,
                h.created_at AS household_created_at, h.updated_at AS household_updated_at,
                hm.member_role, hm.active AS membership_active,
                hm.created_at AS membership_created_at, hm.updated_at AS membership_updated_at,
                CASE WHEN h.owner_user_id = ? THEN 1 ELSE 0 END AS is_owner
         FROM household_members hm
         JOIN households h ON h.id = hm.household_id
         WHERE hm.user_id = ?
         ORDER BY h.id",
        [$userId, $userId]
    );

    $accessGrants = privacy_export_rows(
        $pdo,
        "SELECT g.id, g.owner_household_id AS household_id, h.name AS household_name,
                g.active, g.created_at, g.paused_at, g.revoked_at
         FROM household_access_grants g
         JOIN households h ON h.id = g.owner_household_id
         WHERE g.viewer_user_id = ?
         ORDER BY g.created_at, g.id",
        [$userId]
    );

    $accessKeyActions = privacy_export_rows(
        $pdo,
        "SELECT k.id, k.household_id, h.name AS household_name, k.note, k.active,
                k.created_at, k.redeemed_at, k.revoked_at,
                CASE WHEN k.created_by = ? THEN 1 ELSE 0 END AS created_by_subject,
                CASE WHEN k.redeemed_by = ? THEN 1 ELSE 0 END AS redeemed_by_subject
         FROM household_access_keys k
         JOIN households h ON h.id = k.household_id
         WHERE k.created_by = ? OR k.redeemed_by = ?
         ORDER BY k.created_at, k.id",
        [$userId, $userId, $userId, $userId]
    );

    $ownLoans = privacy_export_rows(
        $pdo,
        "SELECT l.id, l.copy_id, c.household_id, c.inventory_no,
                COALESCE(hbs.title_override, b.title) AS book_title,
                b.isbn13, b.isbn10, l.loaned_at, l.due_at, l.returned_at,
                l.return_note, l.last_reminder_at, l.created_at, l.updated_at,
                CASE WHEN l.created_by = ? THEN 1 ELSE 0 END AS created_by_subject
         FROM loans l
         JOIN copies c ON c.id = l.copy_id
         JOIN books b ON b.id = c.book_id
         LEFT JOIN household_book_settings hbs
                ON hbs.household_id = c.household_id AND hbs.book_id = b.id
         WHERE l.user_id = ?
         ORDER BY l.created_at, l.id",
        [$userId, $userId]
    );

    $managedLoans = privacy_export_rows(
        $pdo,
        "SELECT l.id, l.copy_id, c.household_id, c.inventory_no,
                COALESCE(hbs.title_override, b.title) AS book_title,
                b.isbn13, b.isbn10, l.loaned_at, l.due_at, l.returned_at,
                l.last_reminder_at, l.created_at, l.updated_at
         FROM loans l
         JOIN copies c ON c.id = l.copy_id
         JOIN books b ON b.id = c.book_id
         LEFT JOIN household_book_settings hbs
                ON hbs.household_id = c.household_id AND hbs.book_id = b.id
         WHERE l.created_by = ? AND l.user_id <> ?
         ORDER BY l.created_at, l.id",
        [$userId, $userId]
    );
    foreach ($managedLoans as &$managedLoan) {
        $managedLoan['borrower_identity'] = 'Nicht ausgegeben, da es sich um Daten einer anderen Person handelt.';
    }
    unset($managedLoan);

    $reservations = privacy_export_rows(
        $pdo,
        "SELECT r.id, r.household_id, r.book_id,
                COALESCE(hbs.title_override, b.title) AS book_title,
                b.isbn13, b.isbn10, r.status, r.created_at,
                r.notified_at, r.fulfilled_at, r.cancelled_at
         FROM reservations r
         JOIN books b ON b.id = r.book_id
         LEFT JOIN household_book_settings hbs
                ON hbs.household_id = r.household_id AND hbs.book_id = b.id
         WHERE r.user_id = ?
         ORDER BY r.created_at, r.id",
        [$userId]
    );

    $inventoryChecks = privacy_export_rows(
        $pdo,
        "SELECT i.id, i.household_id, i.book_id,
                COALESCE(hbs.title_override, b.title) AS book_title,
                i.isbn13, i.location, i.shelf, i.location_id, i.checked_at
         FROM inventory_checks i
         JOIN books b ON b.id = i.book_id
         LEFT JOIN household_book_settings hbs
                ON hbs.household_id = i.household_id AND hbs.book_id = b.id
         WHERE i.user_id = ?
         ORDER BY i.checked_at, i.id",
        [$userId]
    );

    $bookHistory = privacy_export_rows(
        $pdo,
        "SELECT bh.id, bh.household_id, bh.book_id, bh.copy_id,
                COALESCE(hbs.title_override, b.title) AS book_title,
                bh.event_type, bh.summary, bh.source_key, bh.occurred_at, bh.created_at
         FROM book_history bh
         JOIN books b ON b.id = bh.book_id
         LEFT JOIN household_book_settings hbs
                ON hbs.household_id = bh.household_id AND hbs.book_id = b.id
         WHERE bh.user_id = ?
         ORDER BY bh.occurred_at, bh.id",
        [$userId]
    );

    $reminders = privacy_export_rows(
        $pdo,
        "SELECT lr.id, lr.copy_id, c.household_id, c.inventory_no,
                COALESCE(hbs.title_override, b.title) AS book_title,
                lr.reminder_kind, lr.reminder_date, lr.created_at
         FROM library_reminder_log lr
         JOIN copies c ON c.id = lr.copy_id
         JOIN books b ON b.id = c.book_id
         LEFT JOIN household_book_settings hbs
                ON hbs.household_id = c.household_id AND hbs.book_id = b.id
         WHERE lr.user_id = ?
         ORDER BY lr.created_at, lr.id",
        [$userId]
    );

    $publicShares = privacy_export_rows(
        $pdo,
        "SELECT p.id, p.household_id, h.name AS household_name, p.description,
                p.expires_at, p.revoked_at, p.access_count, p.last_accessed_at,
                p.created_at, p.updated_at
         FROM public_share_links p
         JOIN households h ON h.id = p.household_id
         WHERE p.created_by = ?
         ORDER BY p.created_at, p.id",
        [$userId]
    );

    $emails = privacy_export_rows(
        $pdo,
        "SELECT id, recipient, subject, body, status, attempts, send_after,
                sent_at, last_error, created_at, updated_at
         FROM email_queue
         WHERE LOWER(recipient) = ?
         ORDER BY created_at, id",
        [$email]
    );

    $auditEntries = privacy_export_rows(
        $pdo,
        "SELECT id, action, entity_type, entity_id, created_at
         FROM audit_log
         WHERE user_id = ?
         ORDER BY created_at, id",
        [$userId]
    );

    $bookActions = privacy_export_rows(
        $pdo,
        "SELECT id, isbn13, isbn10, title, authors, metadata_status,
                created_at, updated_at, deleted_at,
                CASE WHEN created_by = ? THEN 1 ELSE 0 END AS created_by_subject,
                CASE WHEN deleted_by = ? THEN 1 ELSE 0 END AS deleted_by_subject
         FROM books
         WHERE created_by = ? OR deleted_by = ?
         ORDER BY created_at, id",
        [$userId, $userId, $userId, $userId]
    );

    $copyActions = privacy_export_rows(
        $pdo,
        "SELECT c.id, c.household_id, c.book_id, c.inventory_no,
                COALESCE(hbs.title_override, b.title) AS book_title,
                c.status, c.ownership, c.created_at, c.updated_at, c.deleted_at
         FROM copies c
         JOIN books b ON b.id = c.book_id
         LEFT JOIN household_book_settings hbs
                ON hbs.household_id = c.household_id AND hbs.book_id = b.id
         WHERE c.deleted_by = ?
         ORDER BY c.deleted_at, c.id",
        [$userId]
    );

    $archivedBookSettings = privacy_export_rows(
        $pdo,
        "SELECT hbs.id, hbs.household_id, hbs.book_id,
                COALESCE(hbs.title_override, b.title) AS book_title,
                hbs.visibility, hbs.archived_at, hbs.created_at, hbs.updated_at
         FROM household_book_settings hbs
         JOIN books b ON b.id = hbs.book_id
         WHERE hbs.archived_by = ?
         ORDER BY hbs.archived_at, hbs.id",
        [$userId]
    );

    $fileActions = privacy_export_rows(
        $pdo,
        "SELECT bf.id, bf.household_id, bf.book_id,
                COALESCE(hbs.title_override, b.title) AS book_title,
                bf.sequence_no, bf.original_name, bf.mime_type, bf.file_extension,
                bf.file_size, bf.sha256, bf.comment, bf.share_allowed,
                bf.created_at, bf.updated_at, bf.deleted_at,
                CASE WHEN bf.uploaded_by = ? THEN 1 ELSE 0 END AS uploaded_by_subject,
                CASE WHEN bf.deleted_by = ? THEN 1 ELSE 0 END AS deleted_by_subject
         FROM book_files bf
         JOIN books b ON b.id = bf.book_id
         LEFT JOIN household_book_settings hbs
                ON hbs.household_id = bf.household_id AND hbs.book_id = b.id
         WHERE bf.uploaded_by = ? OR bf.deleted_by = ?
         ORDER BY bf.created_at, bf.id",
        [$userId, $userId, $userId, $userId]
    );

    return [
        'format' => 'triamo-datenauskunft-v1',
        'anwendung' => APP_NAME,
        'erzeugt_am' => date(DATE_ATOM),
        'betroffene_person' => [
            'interne_benutzer_id' => $userId,
            'email' => (string)($account['email'] ?? $user['email']),
            'name' => (string)($account['display_name'] ?? $user['display_name']),
        ],
        'identitaetspruefung' => [
            'verfahren' => 'Angemeldete Sitzung und erneute Prüfung des aktuellen Passworts',
            'zeitpunkt' => date(DATE_ATOM),
        ],
        'hinweise_zum_export' => [
            'Dieser Export enthält die Daten, die TRIAMO automatisiert und eindeutig dem angemeldeten Benutzerkonto zuordnen kann.',
            'Passwort-Hashes, Zugriffsschlüssel und andere geheime Authentifizierungswerte werden nicht ausgegeben.',
            'Personenbezogene Daten anderer Personen werden nicht ausgegeben oder werden in gemischten Datensätzen reduziert.',
            'Technische Server-Logdateien des Hostinganbieters sind nicht Bestandteil dieses automatischen Exports, weil TRIAMO sie nicht zuverlässig einem Benutzerkonto zuordnet.',
            'Freitextfelder in gemischten Audit- und Historieneinträgen werden nicht automatisiert ausgegeben. Für eine vollständige formelle Prüfung kann eine Anfrage an datenschutz@triamo.tschugg.eu gestellt werden.',
        ],
        'informationen_zur_verarbeitung' => [
            'zwecke' => [
                'Bereitstellung und Absicherung des Benutzerkontos',
                'Verwaltung von Haushalten, Büchern, Standorten, Verleihungen und Vormerkungen',
                'Bereitstellung von Freigaben, Erinnerungen, Sicherungen und Buchdateien',
                'Fehleranalyse, Missbrauchsabwehr und Nachvollziehbarkeit von Änderungen',
            ],
            'datenkategorien' => [
                'Stammdaten und Kontaktdaten',
                'Konto-, Rollen- und Haushaltsdaten',
                'Verleih-, Vormerkungs- und Erinnerungsdaten',
                'Freigabe-, Aktivitäts- und Protokolldaten',
                'Von der betroffenen Person veranlasste Buch-, Datei- und Bestandsänderungen',
                'E-Mail-Versanddaten',
            ],
            'empfaenger_oder_empfaengergruppen' => [
                'Berechtigte Benutzer des jeweiligen Haushalts',
                'ALL-INKL.COM – Neue Medien Münnich als Hostinganbieter',
                'Je nach verwendeter Funktion konfigurierte Buch- und Metadatendienste',
                'E-Mail-Infrastrukturanbieter im Rahmen des Nachrichtenversands',
            ],
            'speicherdauer' => 'Die Speicherdauer richtet sich nach dem jeweiligen Zweck, der Kontolaufzeit, der Nachvollziehbarkeit von Vorgängen sowie gesetzlichen Aufbewahrungs- und Nachweispflichten.',
            'datenherkunft' => 'Die Daten stammen überwiegend von der betroffenen Person selbst sowie aus ihrer Nutzung der Anwendung. Einzelne Buchmetadaten können aus externen Katalogdiensten stammen.',
            'automatisierte_entscheidungsfindung' => 'Es findet keine ausschließlich automatisierte Entscheidungsfindung einschließlich Profiling im Sinne von Art. 22 DSGVO statt.',
            'rechte' => [
                'Berichtigung',
                'Löschung',
                'Einschränkung der Verarbeitung',
                'Datenübertragbarkeit, soweit anwendbar',
                'Widerspruch, soweit anwendbar',
                'Beschwerde bei einer Datenschutzaufsichtsbehörde',
            ],
        ],
        'daten' => [
            'benutzerkonto' => $account,
            'haushaltsmitgliedschaften' => $memberships,
            'erhaltene_haushaltsfreigaben' => $accessGrants,
            'aktionen_mit_zugriffsschluesseln' => $accessKeyActions,
            'eigene_verleihungen' => $ownLoans,
            'von_der_person_verwaltete_verleihungen_an_dritte' => $managedLoans,
            'vormerkungen' => $reservations,
            'bestandspruefungen' => $inventoryChecks,
            'buchhistorie_mit_eigener_beteiligung' => $bookHistory,
            'erinnerungen' => $reminders,
            'erstellte_oeffentliche_freigaben' => $publicShares,
            'email_versanddaten' => $emails,
            'audit_aktivitaeten' => $auditEntries,
            'buchaktionen' => $bookActions,
            'geloeschte_exemplare' => $copyActions,
            'archivierte_haushaltsbuecher' => $archivedBookSettings,
            'aktionen_mit_buchdateien' => $fileActions,
        ],
    ];
}



// ======================== KONTO-SICHERHEIT UND BENACHRICHTIGUNGEN ========================
/**
 * Wartung: Erzeugt einen kryptografisch zufälligen Einmallink und speichert nur dessen Hash.
 * Aufgerufen von: queue_password_reset_link(), queue_email_verification_link().
 * Abhängigkeiten: db(), prepare().
 */
function create_account_token(string $table, int $userId, ?int $createdBy, int $validHours): string
{
    if (!in_array($table, ['password_reset_tokens', 'email_verification_tokens'], true)) {
        throw new InvalidArgumentException('Unzulässige Tokentabelle.');
    }
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $validHours = max(1, min(168, $validHours));
    $pdo = db();
    $pdo->prepare("UPDATE `$table` SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
        ->execute([$userId]);
    $stmt = $pdo->prepare(
        "INSERT INTO `$table` (user_id, token_hash, expires_at, created_by) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL $validHours HOUR), ?)"
    );
    $stmt->execute([$userId, $hash, $createdBy]);
    return $token;
}

/**
 * Wartung: Legt eine E-Mail zum Setzen oder Zurücksetzen eines Passworts in die Versandwarteschlange.
 * Aufgerufen von: API password_reset_request, user_create, user_password_reset_link.
 * Abhängigkeiten: base_url(), create_account_token(), queue_email().
 */
function queue_password_reset_link(array $target, ?int $createdBy = null): string
{
    $token = create_account_token('password_reset_tokens', (int)$target['id'], $createdBy, 2);
    $url = base_url() . '?reset_token=' . rawurlencode($token);
    $body = "Guten Tag " . (string)$target['display_name'] . ",\n\n"
        . "über den folgenden Einmallink kannst du ein neues Passwort für dein TRIAMO-Konto festlegen:\n\n"
        . $url . "\n\n"
        . "Der Link ist zwei Stunden gültig und kann nur einmal verwendet werden. Wenn du diese Änderung nicht veranlasst hast, ignoriere diese Nachricht und informiere datenschutz@triamo.tschugg.eu.\n\n"
        . "Freundliche Grüße\nTRIAMO";
    queue_email((string)$target['email'], 'TRIAMO: Passwort festlegen oder zurücksetzen', $body);
    return $url;
}

/**
 * Wartung: Legt einen Bestätigungslink für die aktuelle E-Mail-Adresse in die Versandwarteschlange.
 * Aufgerufen von: Registrierung, Profiländerung, API email_verification_request.
 * Abhängigkeiten: base_url(), create_account_token(), queue_email().
 */
function queue_email_verification_link(array $target, ?int $createdBy = null): string
{
    $token = create_account_token('email_verification_tokens', (int)$target['id'], $createdBy, 24);
    $url = base_url() . '?verify_email_token=' . rawurlencode($token);
    $body = "Guten Tag " . (string)$target['display_name'] . ",\n\n"
        . "bitte bestätige deine E-Mail-Adresse für TRIAMO über diesen Einmallink:\n\n"
        . $url . "\n\n"
        . "Der Link ist 24 Stunden gültig. Wenn du kein TRIAMO-Konto angelegt oder keine Änderung veranlasst hast, ignoriere diese Nachricht.\n\n"
        . "Freundliche Grüße\nTRIAMO";
    queue_email((string)$target['email'], 'TRIAMO: E-Mail-Adresse bestätigen', $body);
    return $url;
}

/**
 * Wartung: Versendet eine sachliche Sicherheitsbenachrichtigung zu einer Kontoänderung.
 * Aufgerufen von: Admin- und Profilaktionen.
 * Abhängigkeiten: queue_email(), valid_email().
 */
function queue_account_notice(string $recipient, string $subject, string $message): void
{
    if (!valid_email($recipient)) {
        return;
    }
    $body = "Guten Tag,\n\n" . trim($message)
        . "\n\nWenn du diese Änderung nicht veranlasst hast, wende dich bitte an datenschutz@triamo.tschugg.eu.\n\n"
        . "Freundliche Grüße\nTRIAMO";
    queue_email($recipient, 'TRIAMO: ' . $subject, $body);
}

/**
 * Wartung: Prüft bei sensiblen Admin-Aktionen erneut das Passwort des handelnden Administrators.
 * Aufgerufen von: Benutzeranlage, Benutzeränderung, Session-Widerruf, Löschablauf.
 * Abhängigkeiten: db(), json_response(), prepare().
 */
function require_admin_password(array $admin, string $password): void
{
    if ($password === '') {
        json_response(['ok' => false, 'error' => 'Bitte das eigene Administratorpasswort eingeben.'], 422);
    }
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$admin['id']]);
    $hash = (string)$stmt->fetchColumn();
    if ($hash === '' || !password_verify($password, $hash)) {
        usleep(200000);
        json_response(['ok' => false, 'error' => 'Das Administratorpasswort ist falsch.'], 422);
    }
}

/**
 * Wartung: Liefert eine kompakte Haushalts- und Freigabeübersicht für das Admin-Panel.
 * Aufgerufen von: API users, user_deletion_preview.
 * Abhängigkeiten: db(), prepare().
 */
function admin_user_household_overview(int $userId): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT h.id, h.name, h.active, h.owner_user_id, hm.member_role, hm.active AS membership_active,
                CASE WHEN h.owner_user_id = ? THEN 1 ELSE 0 END AS is_owner
         FROM household_members hm
         JOIN households h ON h.id = hm.household_id
         WHERE hm.user_id = ? ORDER BY is_owner DESC, h.name"
    );
    $stmt->execute([$userId, $userId]);
    $memberships = $stmt->fetchAll();
    foreach ($memberships as &$row) {
        $row['id'] = (int)$row['id'];
        $row['active'] = (bool)$row['active'];
        $row['membership_active'] = (bool)$row['membership_active'];
        $row['is_owner'] = (bool)$row['is_owner'];
    }
    unset($row);

    $stmt = $pdo->prepare(
        "SELECT g.id, h.id AS household_id, h.name AS household_name, g.active, g.paused_at, g.revoked_at, g.created_at
         FROM household_access_grants g
         JOIN households h ON h.id = g.owner_household_id
         WHERE g.viewer_user_id = ? ORDER BY g.created_at DESC"
    );
    $stmt->execute([$userId]);
    $grants = $stmt->fetchAll();
    foreach ($grants as &$row) {
        $row['id'] = (int)$row['id'];
        $row['household_id'] = (int)$row['household_id'];
        $row['active'] = (bool)$row['active'];
    }
    unset($row);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM public_share_links WHERE created_by = ? AND revoked_at IS NULL AND expires_at > NOW()"
    );
    $stmt->execute([$userId]);

    return [
        'memberships' => $memberships,
        'access_grants' => $grants,
        'active_public_share_links' => (int)$stmt->fetchColumn(),
    ];
}

// ======================== SITZUNG, BENUTZER UND RECHTE ========================
/**
 * Wartung: Erzeugt oder liest das CSRF-Token der aktuellen Sitzung.
 * Aufgerufen von: Globaler Ablauf/API/Events, verify_csrf().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

/**
 * Wartung: Prüft das CSRF-Token bei schreibenden Requests.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: csrf_token(), json_response().
 */
function verify_csrf(): void
{
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($provided === '' || !hash_equals(csrf_token(), $provided)) {
        json_response(['ok' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen. Bitte die Seite neu laden.'], 419);
    }
}

/**
 * Wartung: Lädt alle Haushalte und Freigaben des angemeldeten Benutzers.
 * Aufgerufen von: Globaler Ablauf/API/Events, current_user().
 * Abhängigkeiten: db(), prepare().
 */
function user_households(int $userId): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT h.id, h.name, h.owner_user_id, hm.member_role AS access_role, 1 AS can_manage
         FROM household_members hm
         JOIN households h ON h.id = hm.household_id
         WHERE hm.user_id = ? AND hm.active = 1 AND h.active = 1
         ORDER BY (hm.member_role = 'owner') DESC, h.name"
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    $byId = [];
    foreach ($rows as $row) {
        $row['id'] = (int)$row['id'];
        $row['owner_user_id'] = (int)$row['owner_user_id'];
        $row['can_manage'] = true;
        $byId[$row['id']] = $row;
    }

    $stmt = $pdo->prepare(
        "SELECT h.id, h.name, h.owner_user_id, 'viewer' AS access_role, 0 AS can_manage
         FROM household_access_grants g
         JOIN households h ON h.id = g.owner_household_id
         WHERE g.viewer_user_id = ? AND g.active = 1 AND h.active = 1
         ORDER BY h.name"
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $row['id'] = (int)$row['id'];
        $row['owner_user_id'] = (int)$row['owner_user_id'];
        $row['can_manage'] = false;
        $byId[$row['id']] ??= $row;
    }
    return array_values($byId);
}

/**
 * Wartung: Erstellt den ersten Haushalt samt Mitgliedschaft für einen Benutzer.
 * Aufgerufen von: Globaler Ablauf/API/Events, current_user().
 * Abhängigkeiten: ensure_household_loose_location(), prepare().
 */
function create_household_for_user(PDO $pdo, int $userId, string $name): int
{
    $name = trim($name) !== '' ? mb_substr(trim($name), 0, 160) : 'Mein Haushalt';
    $stmt = $pdo->prepare("INSERT INTO households (owner_user_id, name) VALUES (?, ?)");
    $stmt->execute([$userId, $name]);
    $householdId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO household_members (household_id, user_id, member_role, active) VALUES (?, ?, 'owner', 1)")
        ->execute([$householdId, $userId]);
    ensure_household_loose_location($pdo, $householdId);
    return $householdId;
}

/**
 * Wartung: Ermittelt den eingeloggten Benutzer samt aktivem Haushalt und Rechten.
 * Aufgerufen von: Globaler Ablauf/API/Events, get_book(), require_login().
 * Abhängigkeiten: create_household_for_user(), db(), prepare(), user_households().
 */
function current_user(): ?array
{
    static $loaded = false;
    static $user = null;

    if ($loaded) {
        return $user;
    }
    $loaded = true;

    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id < 1) {
        return null;
    }

    $stmt = db()->prepare("SELECT id, email, display_name, role, active, created_at, last_login_at, email_verified_at, session_version, anonymized_at FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || !(int)$row['active'] || !empty($row['anonymized_at'])) {
        unset($_SESSION['user_id'], $_SESSION['household_id'], $_SESSION['session_version']);
        return null;
    }

    $dbSessionVersion = max(1, (int)($row['session_version'] ?? 1));
    if (!isset($_SESSION['session_version'])) {
        $_SESSION['session_version'] = $dbSessionVersion;
    } elseif ((int)$_SESSION['session_version'] !== $dbSessionVersion) {
        unset($_SESSION['user_id'], $_SESSION['household_id'], $_SESSION['session_version']);
        return null;
    }

    $row['id'] = (int)$row['id'];
    $row['active'] = (bool)$row['active'];
    $row['session_version'] = $dbSessionVersion;
    $households = user_households($row['id']);
    if (!$households) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $householdId = create_household_for_user($pdo, $row['id'], $row['display_name'] . 's Haushalt');
            $pdo->commit();
            $households = user_households($row['id']);
            $_SESSION['household_id'] = $householdId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $requestedHouseholdId = (int)($_SESSION['household_id'] ?? 0);
    $activeHousehold = null;
    foreach ($households as $household) {
        if ((int)$household['id'] === $requestedHouseholdId) {
            $activeHousehold = $household;
            break;
        }
    }
    if (!$activeHousehold) {
        foreach ($households as $household) {
            if (!empty($household['can_manage'])) {
                $activeHousehold = $household;
                break;
            }
        }
        $activeHousehold ??= $households[0];
        $_SESSION['household_id'] = (int)$activeHousehold['id'];
    }

    $row['households'] = $households;
    $row['active_household'] = $activeHousehold;
    $row['active_household_id'] = (int)$activeHousehold['id'];
    $row['active_household_name'] = (string)$activeHousehold['name'];
    $row['can_manage_household'] = (bool)$activeHousehold['can_manage'];
    $row['own_household_id'] = 0;
    foreach ($households as $household) {
        if (!empty($household['can_manage']) && ($household['access_role'] ?? '') === 'owner') {
            $row['own_household_id'] = (int)$household['id'];
            break;
        }
    }
    $user = $row;
    return $user;
}

/**
 * Wartung: Bricht ab, wenn kein Benutzer angemeldet ist.
 * Aufgerufen von: Globaler Ablauf/API/Events, current_household_id(), require_admin(), require_household_access().
 * Abhängigkeiten: current_user(), json_response().
 */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        json_response(['ok' => false, 'error' => 'Bitte anmelden.', 'auth_required' => true], 401);
    }
    return $user;
}

/**
 * Wartung: Gibt die aktive Haushalts-ID des aktuellen Benutzers zurück.
 * Aufgerufen von: active_reservation_for_book(), get_location_by_code(), get_location_by_id(), loose_location(), resolve_location_selection(), store_manual_metadata().
 * Abhängigkeiten: require_login().
 */
function current_household_id(): int
{
    $user = require_login();
    return (int)$user['active_household_id'];
}

/**
 * Wartung: Erzwingt Zugriff auf den aktiven Haushalt.
 * Aufgerufen von: Globaler Ablauf/API/Events, require_household_manager().
 * Abhängigkeiten: json_response(), require_login().
 */
function require_household_access(bool $write = false): array
{
    $user = require_login();
    if ($write && empty($user['can_manage_household'])) {
        json_response(['ok' => false, 'error' => 'Dieser fremde Haushalt ist nur zur Ansicht freigegeben.'], 403);
    }
    return $user;
}

/**
 * Wartung: Erzwingt Verwaltungsrechte im aktiven Haushalt.
 * Aufgerufen von: Globaler Ablauf/API/Events, restore_household_backup().
 * Abhängigkeiten: require_household_access().
 */
function require_household_manager(): array
{
    return require_household_access(true);
}

/**
 * Wartung: Erzwingt die globale Administratorrolle.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: json_response(), require_login().
 */
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        json_response(['ok' => false, 'error' => 'Diese Funktion ist nur für Systemadministratoren verfügbar.'], 403);
    }
    return $user;
}

/**
 * Wartung: Prüft, ob die Installation bereits Benutzer enthält.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: db(), query().
 */
function users_exist(): bool
{
    return (int)db()->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0;
}

/**
 * Wartung: Kodiert Binärdaten URL-sicher ohne Padding.
 * Aufgerufen von: encrypt_access_key(), public_share_token().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * Wartung: Dekodiert URL-sichere Base64-Daten.
 * Aufgerufen von: resolve_public_share().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function base64url_decode(string $value): string|false
{
    $padding = (4 - strlen($value) % 4) % 4;
    return base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
}

/**
 * Wartung: Erzeugt einen signierten öffentlichen Freigabe-Token.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: base64url_encode().
 */
function public_share_token(int $linkId, string $createdAt): string
{
    $payload = $linkId . '.' . strtotime($createdAt);
    $signature = hash_hmac('sha256', $payload, CRON_TOKEN, true);
    return base64url_encode($payload . '.' . base64url_encode($signature));
}

/**
 * Wartung: Prüft und lädt einen öffentlichen Freigabelink.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: base64url_decode(), db(), nullable_text(), prepare().
 */
function resolve_public_share(string $token, bool $logAccess = false): ?array
{
    $decoded = base64url_decode($token);
    if ($decoded === false || !preg_match('/^(\d+)\.(\d+)\.([A-Za-z0-9_-]+)$/', $decoded, $m)) {
        return null;
    }
    $id = (int)$m[1];
    $timestamp = (int)$m[2];
    $providedSignature = base64url_decode($m[3]);
    if ($id < 1 || $providedSignature === false) {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT sl.*, h.name AS household_name
         FROM public_share_links sl JOIN households h ON h.id = sl.household_id
         WHERE sl.id = ? AND sl.revoked_at IS NULL AND sl.expires_at > NOW() AND h.active = 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || strtotime((string)$row['created_at']) !== $timestamp) {
        return null;
    }
    $payload = $id . '.' . $timestamp;
    $expected = hash_hmac('sha256', $payload, CRON_TOKEN, true);
    if (!hash_equals($expected, $providedSignature)) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['household_id'] = (int)$row['household_id'];
    $row['access_count'] = (int)$row['access_count'];

    if ($logAccess) {
        // Der Bootstrap wird genau einmal pro Seitenaufruf aufgerufen. Dadurch entspricht
        // jede Protokollzeile einem tatsächlichen Öffnen des Freigabelinks, auch wenn
        // derselbe Browser den Link später erneut aufruft.
        $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        $ipHash = $ip !== '' ? hash_hmac('sha256', $ip, CRON_TOKEN) : null;
        $userAgent = nullable_text($_SERVER['HTTP_USER_AGENT'] ?? null, 500);
        $referrer = nullable_text($_SERVER['HTTP_REFERER'] ?? null, 1000);
        db()->prepare(
            "INSERT INTO public_share_access_log (share_link_id, ip_hash, user_agent, referrer) VALUES (?, ?, ?, ?)"
        )->execute([$row['id'], $ipHash, $userAgent, $referrer]);
        db()->prepare(
            "UPDATE public_share_links SET access_count = access_count + 1, last_accessed_at = NOW() WHERE id = ?"
        )->execute([$row['id']]);
        $row['access_count']++;
        $row['last_accessed_at'] = date('Y-m-d H:i:s');
    }
    return $row;
}

/**
 * Wartung: Erzeugt einen menschenlesbaren Zugriffsschlüssel.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function generate_access_key(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $parts = [];
    for ($group = 0; $group < 3; $group++) {
        $part = '';
        for ($i = 0; $i < 4; $i++) {
            $part .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $parts[] = $part;
    }
    return 'TRI-' . implode('-', $parts);
}


/**
 * Wartung: Normalisiert einen eingegebenen Zugriffsschlüssel für Vergleiche.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function normalize_access_key(string $value): string
{
    $compact = preg_replace('/[^A-Z0-9]/', '', strtoupper($value)) ?? '';
    if (!preg_match('/^TRI([A-Z0-9]{12})$/', $compact, $match)) {
        return '';
    }
    return 'TRI-' . implode('-', str_split($match[1], 4));
}

/**
 * Wartung: Verschlüsselt einen Zugriffsschlüssel für spätere Anzeigehinweise.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: base64url_encode().
 */
function encrypt_access_key(string $plain): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('Die PHP-Erweiterung OpenSSL wird für dauerhaft sichtbare Zugriffsschlüssel benötigt.');
    }
    $key = hash('sha256', CRON_TOKEN, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'triamo-access-key-v1');
    if ($cipher === false) {
        throw new RuntimeException('Der Zugriffsschlüssel konnte nicht verschlüsselt gespeichert werden.');
    }
    return 'v1.' . base64url_encode($iv . $tag . $cipher);
}

/**
 * Wartung: Entschlüsselt einen gespeicherten Zugriffsschlüssel, wenn möglich.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function decrypt_access_key(?string $payload): ?string
{
    if (!$payload || !str_starts_with($payload, 'v1.') || !function_exists('openssl_decrypt')) {
        return null;
    }
    $encoded = substr($payload, 3);
    $padding = strlen($encoded) % 4;
    if ($padding) {
        $encoded .= str_repeat('=', 4 - $padding);
    }
    $binary = base64_decode(strtr($encoded, '-_', '+/'), true);
    if ($binary === false || strlen($binary) < 29) {
        return null;
    }
    $iv = substr($binary, 0, 12);
    $tag = substr($binary, 12, 16);
    $cipher = substr($binary, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', CRON_TOKEN, true), OPENSSL_RAW_DATA, $iv, $tag, 'triamo-access-key-v1');
    return is_string($plain) && $plain !== '' ? $plain : null;
}

/**
 * Wartung: Berechnet den Status einer Haushaltsfreigabe.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function access_grant_status(array $row): string
{
    if (!empty($row['revoked_at'])) {
        return 'revoked';
    }
    if (!empty($row['paused_at']) || empty($row['active'])) {
        return 'paused';
    }
    return 'active';
}



// ======================== TEXT-, ISBN-, STANDORT- UND BARCODE-HELFER ========================
/**
 * Wartung: Entfernt störende Katalog-Steuerzeichen aus externen Texten.
 * Aufgerufen von: clean_text().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function strip_catalog_control_chars(string $text): string
{
    // DNB/MARC21 verwendet teilweise Steuerzeichen für nicht zu sortierende Artikel
    // (U+0098 und U+009C). In UTF-8 erscheinen sie sichtbar als „“ und „“.
    // Diese Zeichen gehören nicht zum Titel und werden für Anzeige und Speicherung entfernt.
    $text = str_replace(["\xC2\x98", "\xC2\x9C", "\x98", "\x9C"], '', $text);
    $cleaned = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', '', $text);
    if (is_string($cleaned)) {
        $text = $cleaned;
    }
    return $text;
}

/**
 * Wartung: Bereinigt Freitext aus Formularen und externen Quellen.
 * Aufgerufen von: Globaler Ablauf/API/Events, clean_marc_value(), metadata_google_result(), metadata_openlibrary_result(), nullable_text(), openlibrary_search_data(), parse_dnb_marc(), +3 weitere.
 * Abhängigkeiten: strip_catalog_control_chars().
 */
function clean_text(mixed $value, int $max = 1000): string
{
    $text = strip_catalog_control_chars(trim((string)$value));
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return mb_substr(trim($text), 0, $max);
}

/**
 * Wartung: Wandelt leere bereinigte Texte in null um.
 * Aufgerufen von: Globaler Ablauf/API/Events, metadata_google_result(), metadata_openlibrary_result(), openlibrary_search_data(), parse_dnb_marc(), resolve_public_share(), save_household_book_settings(), +1 weitere.
 * Abhängigkeiten: clean_text().
 */
function nullable_text(mixed $value, int $max = 1000): ?string
{
    $text = clean_text($value, $max);
    return $text === '' ? null : $text;
}

/**
 * Wartung: Validiert eine E-Mail-Adresse.
 * Aufgerufen von: Globaler Ablauf/API/Events, mail_from_address(), queue_email().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 191;
}

/**
 * Wartung: Schreibt einen Eintrag ins Audit-Protokoll.
 * Aufgerufen von: Globaler Ablauf/API/Events, restore_household_backup().
 * Abhängigkeiten: db(), prepare().
 */
function audit(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?array $details = null): void
{
    $stmt = db()->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $userId,
        mb_substr($action, 0, 80),
        $entityType,
        $entityId,
        $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null
    ]);
}

/**
 * Wartung: Schreibt einen fachlichen Ereigniseintrag zur Buchhistorie.
 * Aufgerufen von: Globaler Ablauf/API/Events, cancel_metadata_job(), fetch_and_store_metadata().
 * Abhängigkeiten: db(), prepare().
 */
function book_event(
    int $bookId,
    string $eventType,
    string $summary,
    ?int $userId = null,
    ?int $copyId = null,
    ?array $details = null,
    ?string $sourceKey = null,
    ?string $occurredAt = null,
    ?int $householdId = null
): void {
    if ($householdId === null && $copyId) {
        $stmt = db()->prepare("SELECT household_id FROM copies WHERE id = ?");
        $stmt->execute([$copyId]);
        $householdId = (int)($stmt->fetchColumn() ?: 0) ?: null;
    }
    $stmt = db()->prepare(
        "INSERT INTO book_history
            (household_id, book_id, copy_id, user_id, event_type, summary, details, source_key, occurred_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    try {
        $stmt->execute([
            $householdId,
            $bookId,
            $copyId,
            $userId,
            mb_substr($eventType, 0, 50),
            mb_substr($summary, 0, 500),
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            $sourceKey ? mb_substr($sourceKey, 0, 120) : null,
            $occurredAt ?: date('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') {
            throw $e;
        }
    }
}

/**
 * Wartung: Parst ein Datum und erlaubt nur heutige oder künftige Werte.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: clean_text().
 */
function parse_future_date(mixed $value, string $label = 'Datum'): ?string
{
    $text = clean_text($value, 30);
    if ($text === '') {
        return null;
    }
    try {
        $date = new DateTimeImmutable($text . (strlen($text) <= 10 ? ' 23:59:59' : ''));
    } catch (Throwable) {
        throw new InvalidArgumentException($label . ' ist ungültig.');
    }
    return $date->format('Y-m-d H:i:s');
}

/**
 * Wartung: Validiert eine ISBN-10 inklusive Prüfziffer.
 * Aufgerufen von: metadata_openlibrary_result(), normalize_isbn(), openlibrary_search_data(), parse_dnb_marc().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function isbn10_valid(string $isbn): bool
{
    if (!preg_match('/^\d{9}[\dX]$/', $isbn)) {
        return false;
    }
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $digit = ($i === 9 && $isbn[$i] === 'X') ? 10 : (int)$isbn[$i];
        $sum += (10 - $i) * $digit;
    }
    return $sum % 11 === 0;
}

/**
 * Wartung: Validiert eine ISBN-13 inklusive Prüfziffer.
 * Aufgerufen von: isbn13_to_10(), normalize_isbn().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function isbn13_valid(string $isbn): bool
{
    if (!preg_match('/^\d{13}$/', $isbn)) {
        return false;
    }
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int)$isbn[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    $check = (10 - ($sum % 10)) % 10;
    return $check === (int)$isbn[12];
}

/**
 * Wartung: Wandelt eine ISBN-10 in eine ISBN-13 um.
 * Aufgerufen von: normalize_isbn().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function isbn10_to_13(string $isbn10): string
{
    $base = '978' . substr($isbn10, 0, 9);
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int)$base[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return $base . ((10 - ($sum % 10)) % 10);
}

/**
 * Wartung: Wandelt eine passende ISBN-13 in eine ISBN-10 um.
 * Aufgerufen von: metadata_dnb_result(), metadata_google_result(), metadata_openlibrary_result(), normalize_isbn().
 * Abhängigkeiten: isbn13_valid().
 */
function isbn13_to_10(string $isbn13): ?string
{
    if (!isbn13_valid($isbn13) || !str_starts_with($isbn13, '978')) {
        return null;
    }
    $base = substr($isbn13, 3, 9);
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (10 - $i) * (int)$base[$i];
    }
    $check = (11 - ($sum % 11)) % 11;
    return $base . ($check === 10 ? 'X' : (string)$check);
}

/**
 * Wartung: Normalisiert und validiert ISBN-Eingaben aus Scans oder Formularen.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: isbn10_to_13(), isbn10_valid(), isbn13_to_10(), isbn13_valid().
 */
function normalize_isbn(string $raw): array
{
    $upper = strtoupper($raw);
    if (preg_match('/(?:97[89][\d\-\s]{10,20}\d)/', $upper, $m)) {
        $candidate = preg_replace('/\D/', '', $m[0]) ?? '';
        if (strlen($candidate) >= 13) {
            $candidate = substr($candidate, 0, 13);
            if (isbn13_valid($candidate)) {
                return ['isbn13' => $candidate, 'isbn10' => isbn13_to_10($candidate)];
            }
        }
    }

    $clean = preg_replace('/[^0-9X]/', '', $upper) ?? '';
    if (strlen($clean) === 13 && isbn13_valid($clean)) {
        return ['isbn13' => $clean, 'isbn10' => isbn13_to_10($clean)];
    }
    if (strlen($clean) === 10 && isbn10_valid($clean)) {
        return ['isbn13' => isbn10_to_13($clean), 'isbn10' => $clean];
    }

    throw new InvalidArgumentException('Die ISBN ist ungültig. Erwartet wird eine gültige ISBN-10 oder ISBN-13.');
}

/**
 * Wartung: Erzeugt die interne Inventarnummer für ein Exemplar.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function inventory_number(int $bookId): string
{
    return 'HB-' . str_pad((string)$bookId, 6, '0', STR_PAD_LEFT) . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Wartung: Normalisiert einen Standortcode für Suche und Vergleich.
 * Aufgerufen von: Globaler Ablauf/API/Events, get_location_by_code(), normalize_location_group_code_input().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function normalize_location_code(string $value): string
{
    $value = str_replace(['ß', 'ẞ', '§', '–', '—', '−', '_'], '-', trim($value));
    $value = mb_strtoupper($value, 'UTF-8');
    $value = preg_replace('/\s+/u', '', $value) ?? '';
    return trim($value, '*');
}

/**
 * Wartung: Normalisiert die fünfstellige Standortgruppen-ID aus Eingaben.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: normalize_location_code().
 */
function normalize_location_group_code_input(string $value): string
{
    $value = normalize_location_code($value);
    if (preg_match('/^TRIAMO-(\d{5})(?:-\d{1,3})?$/', $value, $m)) {
        return $m[1];
    }
    if (preg_match('/^\d{5}$/', $value)) {
        return $value;
    }
    return '';
}

/**
 * Wartung: Erzeugt den vollständigen Barcode-Code für ein Standortfach.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function location_barcode_code(string $groupCode, int $compartmentNo): string
{
    $groupCode = strtoupper(trim($groupCode));
    if (!preg_match('/^\d{5}$/', $groupCode) || $compartmentNo < 1 || $compartmentNo > 999) {
        throw new InvalidArgumentException('Ungültige Standort-ID oder Fachnummer.');
    }
    return 'TRIAMO-' . $groupCode . '-' . $compartmentNo;
}

/**
 * Wartung: Formatiert einen Standort als lesbaren Pfad.
 * Aufgerufen von: Globaler Ablauf/API/Events, cast_location_row().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function location_path(array $location): string
{
    if (!empty($location['is_loose'])) {
        return 'Kein Standort / lose';
    }
    $parts = [];
    foreach (['building', 'room', 'shelf'] as $field) {
        $value = trim((string)($location[$field] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }
    $compartmentNo = (int)($location['compartment_no'] ?? 0);
    $compartment = trim((string)($location['compartment'] ?? ''));
    if ($compartmentNo > 0) {
        $parts[] = 'Fach ' . $compartmentNo;
    } elseif ($compartment !== '') {
        $parts[] = str_starts_with(mb_strtolower($compartment), 'fach') ? $compartment : 'Fach ' . $compartment;
    }
    return $parts ? implode(' → ', $parts) : 'Unbenannter Standort';
}

/**
 * Wartung: Formatiert den gemeinsamen Pfad einer Standortgruppe.
 * Aufgerufen von: cast_location_row().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function location_group_path(array $location): string
{
    if (!empty($location['is_loose'])) {
        return 'Kein Standort / lose';
    }
    $parts = [];
    foreach (['building', 'room', 'shelf'] as $field) {
        $value = trim((string)($location[$field] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }
    return $parts ? implode(' → ', $parts) : 'Unbenannter Standort';
}

/**
 * Wartung: Typisiert Standortdaten aus der Datenbank für JSON-Antworten.
 * Aufgerufen von: Globaler Ablauf/API/Events, get_location_by_code(), get_location_by_id(), loose_location().
 * Abhängigkeiten: location_group_path(), location_path().
 */
function cast_location_row(array $row): array
{
    $row['id'] = (int)$row['id'];
    if (array_key_exists('household_id', $row)) { $row['household_id'] = (int)$row['household_id']; }
    $row['compartment_no'] = $row['compartment_no'] !== null ? (int)$row['compartment_no'] : null;
    $row['group_size'] = $row['group_size'] !== null ? (int)$row['group_size'] : null;
    $row['is_loose'] = (bool)$row['is_loose'];
    $row['active'] = (bool)$row['active'];
    $row['path'] = location_path($row);
    $row['group_path'] = location_group_path($row);
    return $row;
}

/**
 * Wartung: Lädt oder erstellt den losen Standort des aktuellen Haushalts.
 * Aufgerufen von: Globaler Ablauf/API/Events, resolve_location_selection().
 * Abhängigkeiten: cast_location_row(), current_household_id(), db(), ensure_household_loose_location(), prepare().
 */
function loose_location(?int $householdId = null): array
{
    $householdId ??= current_household_id();
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM locations WHERE household_id = ? AND is_loose = 1 ORDER BY id LIMIT 1");
    $stmt->execute([$householdId]);
    $row = $stmt->fetch();
    if (!$row) {
        $id = ensure_household_loose_location($pdo, $householdId);
        $stmt = $pdo->prepare("SELECT * FROM locations WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    }
    if (!$row) {
        throw new RuntimeException('Der Sonderstandort konnte nicht geladen werden.');
    }
    return cast_location_row($row);
}

/**
 * Wartung: Lädt einen aktiven Standort anhand seiner ID.
 * Aufgerufen von: Globaler Ablauf/API/Events, resolve_location_selection().
 * Abhängigkeiten: cast_location_row(), current_household_id(), db(), prepare().
 */
function get_location_by_id(int $id, bool $allowInactive = false, ?int $householdId = null): ?array
{
    if ($id <= 0) {
        return null;
    }
    $householdId ??= current_household_id();
    $sql = "SELECT * FROM locations WHERE id = ? AND household_id = ?" . ($allowInactive ? '' : ' AND active = 1') . " LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute([$id, $householdId]);
    $row = $stmt->fetch();
    return $row ? cast_location_row($row) : null;
}

/**
 * Wartung: Löst einen Standortcode oder Alias in einen Standort auf.
 * Aufgerufen von: Globaler Ablauf/API/Events, resolve_location_selection().
 * Abhängigkeiten: cast_location_row(), current_household_id(), db(), normalize_location_code(), prepare().
 */
function get_location_by_code(string $code, bool $allowInactive = false, ?int $householdId = null): ?array
{
    $code = normalize_location_code($code);
    if ($code === '') {
        return null;
    }
    $householdId ??= current_household_id();
    $pdo = db();
    $sql = "SELECT * FROM locations WHERE code = ? AND household_id = ?" . ($allowInactive ? '' : ' AND active = 1') . " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$code, $householdId]);
    $row = $stmt->fetch();
    if (!$row) {
        $sql = "SELECT l.* FROM location_code_aliases a JOIN locations l ON l.id = a.location_id WHERE a.alias_code = ? AND l.household_id = ? AND (a.household_id = ? OR a.household_id IS NULL)"
            . ($allowInactive ? '' : ' AND l.active = 1') . " LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$code, $householdId, $householdId]);
        $row = $stmt->fetch();
    }
    return $row ? cast_location_row($row) : null;
}

/**
 * Wartung: Ermittelt den Zielstandort aus Formularfeldern oder Scanwerten.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: current_household_id(), get_location_by_code(), get_location_by_id(), loose_location().
 */
function resolve_location_selection(array $data): array
{
    $householdId = current_household_id();
    $location = null;
    if (!empty($data['location_id'])) {
        $location = get_location_by_id((int)$data['location_id'], false, $householdId);
    } elseif (!empty($data['location_code'])) {
        $location = get_location_by_code((string)$data['location_code'], false, $householdId);
    }
    $location ??= loose_location($householdId);
    if (!$location) {
        throw new InvalidArgumentException('Bitte einen gültigen Standort auswählen.');
    }
    return $location;
}

/**
 * Wartung: Bereitet Standortformularwerte für Speicherung und Anzeige auf.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function location_text_values(array $location): array
{
    if (!empty($location['is_loose'])) {
        return [null, null];
    }
    $first = trim(implode(' → ', array_values(array_filter([
        (string)($location['building'] ?? ''),
        (string)($location['room'] ?? ''),
    ], static fn(string $v): bool => trim($v) !== ''))));
    $second = trim(implode(' → ', array_values(array_filter([
        (string)($location['shelf'] ?? ''),
        (string)($location['compartment'] ?? ''),
    ], static fn(string $v): bool => trim($v) !== ''))));
    return [$first !== '' ? $first : null, $second !== '' ? $second : null];
}

/**
 * Wartung: Erzeugt ein Code-39-Barcode-SVG für Standortetiketten.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function code39_svg(string $value, int $height = 80, bool $showText = true): string
{
    $value = strtoupper(trim($value));
    if (!preg_match('/^[0-9A-Z. \-\$\/\+%]+$/', $value)) {
        throw new InvalidArgumentException('Der Barcode enthält nicht unterstützte Zeichen.');
    }
    $patterns = [
        '0'=>'nnnwwnwnn','1'=>'wnnwnnnnw','2'=>'nnwwnnnnw','3'=>'wnwwnnnnn','4'=>'nnnwwnnnw',
        '5'=>'wnnwwnnnn','6'=>'nnwwwnnnn','7'=>'nnnwnnwnw','8'=>'wnnwnnwnn','9'=>'nnwwnnwnn',
        'A'=>'wnnnnwnnw','B'=>'nnwnnwnnw','C'=>'wnwnnwnnn','D'=>'nnnnwwnnw','E'=>'wnnnwwnnn',
        'F'=>'nnwnwwnnn','G'=>'nnnnnwwnw','H'=>'wnnnnwwnn','I'=>'nnwnnwwnn','J'=>'nnnnwwwnn',
        'K'=>'wnnnnnnww','L'=>'nnwnnnnww','M'=>'wnwnnnnwn','N'=>'nnnnwnnww','O'=>'wnnnwnnwn',
        'P'=>'nnwnwnnwn','Q'=>'nnnnnnwww','R'=>'wnnnnnwwn','S'=>'nnwnnnwwn','T'=>'nnnnwnwwn',
        'U'=>'wwnnnnnnw','V'=>'nwwnnnnnw','W'=>'wwwnnnnnn','X'=>'nwnnwnnnw','Y'=>'wwnnwnnnn',
        'Z'=>'nwwnwnnnn','-'=>'nwnnnnwnw','.'=>'wwnnnnwnn',' '=>'nwwnnnwnn','$'=>'nwnwnwnnn',
        '/'=>'nwnwnnnwn','+'=>'nwnnnwnwn','%'=>'nnnwnwnwn','*'=>'nwnnwnwnn',
    ];
    $text = '*' . $value . '*';
    $narrow = 2;
    $wide = 5;
    $quiet = 18;
    $x = $quiet;
    $bars = [];
    foreach (str_split($text) as $char) {
        $pattern = $patterns[$char] ?? null;
        if (!$pattern) {
            throw new InvalidArgumentException('Der Barcode kann nicht erzeugt werden.');
        }
        foreach (str_split($pattern) as $index => $kind) {
            $width = $kind === 'w' ? $wide : $narrow;
            if ($index % 2 === 0) {
                $bars[] = '<rect x="' . $x . '" y="4" width="' . $width . '" height="' . $height . '" fill="#000"/>';
            }
            $x += $width;
        }
        $x += $narrow;
    }
    $total = $x + $quiet;
    $safe = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $svgHeight = $height + ($showText ? 30 : 8);
    $text = $showText
        ? '<text x="' . ($total / 2) . '" y="' . ($height + 24) . '" text-anchor="middle" font-family="monospace" font-size="14">' . $safe . '</text>'
        : '';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $total . '" height="' . $svgHeight . '" viewBox="0 0 ' . $total . ' ' . $svgHeight . '">' .
        '<rect width="100%" height="100%" fill="#fff"/>' . implode('', $bars) . $text . '</svg>';
}


/**
 * Wartung: Erstellt das Verzeichnis für dauerhaft zwischengespeicherte Barcode-SVGs.
 * Aufgerufen von: cached_code39_svg().
 * Abhängigkeiten: triamo_data_dir().
 */
function ensure_barcode_cache_dir(): string
{
    $dir = triamo_data_dir() . DIRECTORY_SEPARATOR . 'barcodes';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Das Barcode-Cache-Verzeichnis konnte nicht angelegt werden.');
    }
    if (!is_file($dir . DIRECTORY_SEPARATOR . 'index.html')) {
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.html', '');
    }
    return $dir;
}

/**
 * Wartung: Liefert ein geprüftes Barcode-SVG aus dem Dateicache oder erzeugt es neu.
 * Aufgerufen von: direkte Barcode-Ausgabe.
 * Abhängigkeiten: code39_svg(), ensure_barcode_cache_dir().
 */
function cached_code39_svg(string $code, int $height = 80, bool $showText = true): array
{
    $code = strtoupper(trim($code));
    $generatorVersion = 2;
    $descriptor = [
        'code' => $code,
        'height' => $height,
        'show_text' => $showText,
        'generator_version' => $generatorVersion,
    ];
    $key = hash('sha256', json_encode($descriptor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $dir = ensure_barcode_cache_dir();
    $svgPath = $dir . DIRECTORY_SEPARATOR . $key . '.svg';
    $metaPath = $dir . DIRECTORY_SEPARATOR . $key . '.json';

    $valid = false;
    $meta = null;
    if (is_file($svgPath) && is_file($metaPath)) {
        $decoded = json_decode((string)@file_get_contents($metaPath), true);
        if (is_array($decoded)
            && ($decoded['code'] ?? null) === $code
            && (int)($decoded['height'] ?? 0) === $height
            && (bool)($decoded['show_text'] ?? false) === $showText
            && (int)($decoded['generator_version'] ?? 0) === $generatorVersion
            && is_string($decoded['sha256'] ?? null)
            && hash_equals((string)$decoded['sha256'], (string)@hash_file('sha256', $svgPath))) {
            $valid = true;
            $meta = $decoded;
        }
    }

    if (!$valid) {
        $svg = code39_svg($code, $height, $showText);
        $tmpSvg = $svgPath . '.tmp-' . bin2hex(random_bytes(3));
        $tmpMeta = $metaPath . '.tmp-' . bin2hex(random_bytes(3));
        if (@file_put_contents($tmpSvg, $svg, LOCK_EX) === false) {
            throw new RuntimeException('Der Barcode konnte nicht im Cache gespeichert werden.');
        }
        $meta = $descriptor + [
            'sha256' => hash('sha256', $svg),
            'created_at' => date(DATE_ATOM),
        ];
        if (@file_put_contents($tmpMeta, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false
            || !@rename($tmpSvg, $svgPath)
            || !@rename($tmpMeta, $metaPath)) {
            @unlink($tmpSvg);
            @unlink($tmpMeta);
            throw new RuntimeException('Der Barcode-Cache konnte nicht aktualisiert werden.');
        }
    }

    return [
        'path' => $svgPath,
        'etag' => '"' . (string)$meta['sha256'] . '"',
        'mtime' => (int)(@filemtime($svgPath) ?: time()),
        'code' => $code,
    ];
}

/**
 * Wartung: Prüft, ob eine IP-Adresse öffentlich erreichbar ist.
 * Aufgerufen von: assert_public_download_url().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function is_public_ip_address(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * Wartung: Verhindert bei benutzerdefinierten Download-URLs Zugriffe auf lokale Netze.
 * Aufgerufen von: http_get_public_resource().
 * Abhängigkeiten: is_public_ip_address().
 */
function assert_public_download_url(string $url): string
{
    $url = trim($url);
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Bitte eine gültige HTTP- oder HTTPS-Adresse eingeben.');
    }
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $port = isset($parts['port']) ? (int)$parts['port'] : null;
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
        throw new InvalidArgumentException('Die Bildadresse muss öffentlich über HTTP oder HTTPS erreichbar sein.');
    }
    if (isset($parts['user']) || isset($parts['pass']) || ($port !== null && !in_array($port, [80, 443], true))) {
        throw new InvalidArgumentException('Anmeldedaten oder ungewöhnliche Netzwerkports sind in Bildadressen nicht erlaubt.');
    }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $records = function_exists('dns_get_record') ? @dns_get_record($host, DNS_A | DNS_AAAA) : [];
        foreach (is_array($records) ? $records : [] as $record) {
            if (!empty($record['ip'])) $ips[] = (string)$record['ip'];
            if (!empty($record['ipv6'])) $ips[] = (string)$record['ipv6'];
        }
        if (!$ips) {
            $fallback = @gethostbynamel($host);
            if (is_array($fallback)) $ips = array_merge($ips, $fallback);
        }
    }
    if (!$ips) {
        throw new RuntimeException('Der Servername der Bildadresse konnte nicht aufgelöst werden.');
    }
    foreach (array_unique($ips) as $ip) {
        if (!is_public_ip_address($ip)) {
            throw new InvalidArgumentException('Bildadressen in lokalen oder reservierten Netzen sind nicht erlaubt.');
        }
    }
    return $url;
}

/**
 * Wartung: Löst relative HTTP-Weiterleitungen gegen die vorherige Adresse auf.
 * Aufgerufen von: http_get_public_resource().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function resolve_http_redirect_url(string $baseUrl, string $location): string
{
    $location = trim($location);
    if (preg_match('~^https?://~i', $location)) {
        return $location;
    }
    $base = parse_url($baseUrl);
    $scheme = (string)($base['scheme'] ?? 'https');
    $host = (string)($base['host'] ?? '');
    $origin = $scheme . '://' . $host;
    if (!empty($base['port'])) $origin .= ':' . (int)$base['port'];
    if (str_starts_with($location, '//')) {
        return $scheme . ':' . $location;
    }
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }
    $basePath = (string)($base['path'] ?? '/');
    $directory = str_replace('\\', '/', dirname($basePath));
    if ($directory === '.' || $directory === DIRECTORY_SEPARATOR) $directory = '';
    return $origin . ($directory !== '' ? '/' . trim($directory, '/') : '') . '/' . ltrim($location, '/');
}

/**
 * Wartung: Lädt eine öffentliche Ressource mit Größenlimit und geprüften Weiterleitungen.
 * Aufgerufen von: manuelle Cover-URL.
 * Abhängigkeiten: assert_public_download_url().
 */
function http_get_public_resource(string $url, int $maxBytes, int $timeout = 20, int $redirects = 4): array
{
    $current = assert_public_download_url($url);
    for ($step = 0; $step <= $redirects; $step++) {
        if (function_exists('curl_init')) {
            $body = '';
            $responseHeaders = [];
            $tooLarge = false;
            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'Triamo/7.0 (' . mail_from_address() . ')',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/jpeg,image/*;q=0.9,*/*;q=0.2'],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $length = strlen($line);
                    $line = trim($line);
                    if ($line !== '' && str_contains($line, ':')) {
                        [$name, $value] = array_map('trim', explode(':', $line, 2));
                        $responseHeaders[strtolower($name)] = $value;
                    }
                    return $length;
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($tooLarge) {
                throw new RuntimeException('Die Bilddatei ist größer als ' . round($maxBytes / 1024 / 1024) . ' MB.');
            }
            if ($status >= 300 && $status < 400 && !empty($responseHeaders['location'])) {
                $location = resolve_http_redirect_url($current, (string)$responseHeaders['location']);
                $current = assert_public_download_url($location);
                continue;
            }
            return [
                'ok' => $ok !== false && $status >= 200 && $status < 300,
                'status' => $status,
                'body' => $body,
                'content_type' => $contentType,
                'error' => $error,
                'final_url' => $current,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'header' => "User-Agent: Triamo/7.0\r\nAccept: image/*,*/*;q=0.2\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($current, false, $context, 0, $maxBytes + 1);
        $headers = $http_response_header ?? [];
        $status = 0;
        $contentType = '';
        $location = '';
        foreach ($headers as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', (string)$line, $m)) $status = (int)$m[1];
            elseif (stripos((string)$line, 'Content-Type:') === 0) $contentType = trim(substr((string)$line, 13));
            elseif (stripos((string)$line, 'Location:') === 0) $location = trim(substr((string)$line, 9));
        }
        if (is_string($body) && strlen($body) > $maxBytes) {
            throw new RuntimeException('Die Bilddatei ist größer als ' . round($maxBytes / 1024 / 1024) . ' MB.');
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            $location = resolve_http_redirect_url($current, $location);
            $current = assert_public_download_url($location);
            continue;
        }
        return [
            'ok' => is_string($body) && ($status === 0 || ($status >= 200 && $status < 300)),
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'content_type' => $contentType,
            'error' => is_string($body) ? '' : 'HTTP-Abruf fehlgeschlagen.',
            'final_url' => $current,
        ];
    }
    throw new RuntimeException('Die Bildadresse leitet zu oft weiter.');
}

/**
 * Wartung: Erkennt erlaubte Coverformate und liest Abmessungen aus.
 * Aufgerufen von: store_manual_cover_bytes().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function inspect_cover_bytes(string $body, string $contentType = ''): array
{
    if ($body === '' || strlen($body) > MAX_COVER_UPLOAD_BYTES) {
        throw new InvalidArgumentException('Das Cover ist leer oder größer als 12 MB.');
    }
    $type = strtolower($contentType);
    $extension = null;
    $mime = null;
    if (str_contains($type, 'image/jpeg') || str_starts_with($body, "\xFF\xD8\xFF")) {
        $extension = 'jpg'; $mime = 'image/jpeg';
    } elseif (str_contains($type, 'image/png') || str_starts_with($body, "\x89PNG")) {
        $extension = 'png'; $mime = 'image/png';
    } elseif (str_contains($type, 'image/webp') || (strlen($body) >= 12 && substr($body, 8, 4) === 'WEBP')) {
        $extension = 'webp'; $mime = 'image/webp';
    }
    if (!$extension) {
        throw new InvalidArgumentException('Erlaubt sind JPEG-, PNG- und WebP-Bilder.');
    }
    $size = @getimagesizefromstring($body);
    $width = is_array($size) ? (int)($size[0] ?? 0) : 0;
    $height = is_array($size) ? (int)($size[1] ?? 0) : 0;
    if ($width < 40 || $height < 40 || $width > 12000 || $height > 12000) {
        throw new InvalidArgumentException('Das Bild ist ungültig, zu klein oder ungewöhnlich groß.');
    }
    return [
        'extension' => $extension,
        'mime_type' => $mime,
        'width' => $width,
        'height' => $height,
        'file_size' => strlen($body),
    ];
}

/**
 * Wartung: Speichert ein manuell geliefertes Cover und wählt es im aktiven Haushalt aus.
 * Aufgerufen von: API cover_upload und cover_url_add.
 * Abhängigkeiten: book_event(), db(), ensure_cover_dir(), inspect_cover_bytes().
 */
function store_manual_cover_bytes(int $bookId, int $householdId, int $userId, string $body, string $contentType, string $sourceName, string $remoteUrl = ''): array
{
    $info = inspect_cover_bytes($body, $contentType);
    $stmt = db()->prepare('SELECT isbn13, isbn10 FROM books WHERE id = ?');
    $stmt->execute([$bookId]);
    $book = $stmt->fetch();
    if (!$book) {
        throw new RuntimeException('Buch nicht gefunden.');
    }

    $sourceKey = 'manual_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $isbnPart = preg_replace('/[^0-9X]+/i', '', (string)($book['isbn13'] ?: ($book['isbn10'] ?? '')));
    $baseName = $isbnPart !== '' ? $isbnPart : ('ohne-isbn-' . str_pad((string)$bookId, 8, '0', STR_PAD_LEFT));
    $filename = $baseName . '-' . $sourceKey . '.' . $info['extension'];
    $dir = ensure_cover_dir();
    $target = $dir . DIRECTORY_SEPARATOR . $filename;
    $tmp = $target . '.tmp-' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, $body, LOCK_EX) === false || !@rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('Das Cover konnte nicht gespeichert werden.');
    }
    $localPath = 'bookvault_data/covers/' . $filename;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            "INSERT INTO book_covers
                (book_id, source_key, source_name, remote_url, local_path, mime_type, width, height, file_size,
                 fetch_status, error_message, fetched_at, selected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'success', NULL, NOW(), NOW())"
        );
        $insert->execute([
            $bookId, $sourceKey, mb_substr($sourceName, 0, 120), $remoteUrl, $localPath,
            $info['mime_type'], $info['width'], $info['height'], $info['file_size'],
        ]);
        $coverId = (int)$pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO household_book_settings (household_id, book_id, selected_cover_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE selected_cover_id = VALUES(selected_cover_id)"
        )->execute([$householdId, $bookId, $coverId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @unlink($target);
        throw $e;
    }
    book_event($bookId, 'cover_uploaded', 'Eigenes Cover hinzugefügt', $userId, null, [
        'cover_id' => $coverId,
        'source_name' => $sourceName,
        'width' => $info['width'],
        'height' => $info['height'],
    ], null, null, $householdId);
    return [
        'id' => $coverId,
        'local_path' => $localPath,
        'cover' => $localPath,
        'width' => $info['width'],
        'height' => $info['height'],
        'file_size' => $info['file_size'],
    ];
}

/**
 * Wartung: Erstellt den geschützten Ablageordner für Buchdateien.
 * Aufgerufen von: store_book_file_upload().
 * Abhängigkeiten: triamo_data_dir().
 */
function ensure_book_files_dir(int $householdId, int $bookId): string
{
    $root = triamo_data_dir() . DIRECTORY_SEPARATOR . 'files';
    $dir = $root . DIRECTORY_SEPARATOR . 'h' . $householdId . DIRECTORY_SEPARATOR . 'b' . $bookId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Das Verzeichnis für Buchdateien konnte nicht angelegt werden.');
    }
    if (!is_file($root . DIRECTORY_SEPARATOR . 'index.html')) {
        @file_put_contents($root . DIRECTORY_SEPARATOR . 'index.html', '');
    }
    if (!is_file($root . DIRECTORY_SEPARATOR . '.htaccess')) {
        @file_put_contents(
            $root . DIRECTORY_SEPARATOR . '.htaccess',
            "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
        );
    }
    return $dir;
}

/**
 * Wartung: Gibt die erlaubten Dateiendungen für Buchanhänge zurück.
 * Aufgerufen von: store_book_file_upload().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function allowed_book_file_extensions(): array
{
    return [
        'pdf', 'epub', 'mobi', 'azw', 'azw3', 'djvu',
        'txt', 'md', 'rtf', 'csv',
        'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp',
        'zip', 'cbz', 'cbr',
        'jpg', 'jpeg', 'png', 'webp'
    ];
}

/**
 * Wartung: Speichert einen hochgeladenen Buchanhang mit ISBN und laufender Nummer.
 * Aufgerufen von: API book_file_upload.
 * Abhängigkeiten: allowed_book_file_extensions(), book_event(), db(), ensure_book_files_dir().
 */
function store_book_file_upload(int $bookId, int $householdId, int $userId, array $upload, ?string $comment, bool $shareAllowed): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Die Datei konnte nicht vollständig hochgeladen werden.');
    }
    $size = (int)($upload['size'] ?? 0);
    if ($size < 1 || $size > MAX_BOOK_FILE_BYTES) {
        throw new InvalidArgumentException('Die Datei ist leer oder größer als 50 MB.');
    }
    $tmpName = (string)($upload['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new InvalidArgumentException('Die Upload-Datei ist ungültig.');
    }
    $originalName = trim((string)($upload['name'] ?? 'Datei'));
    $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === 'jpeg') $extension = 'jpg';
    if (!in_array($extension, allowed_book_file_extensions(), true)) {
        throw new InvalidArgumentException('Dieser Dateityp ist nicht erlaubt.');
    }
    $mime = (string)($upload['type'] ?? 'application/octet-stream');
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedMime = @finfo_file($finfo, $tmpName);
            if (is_string($detectedMime) && $detectedMime !== '') $mime = $detectedMime;
            @finfo_close($finfo);
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    $target = null;
    try {
        $bookStmt = $pdo->prepare('SELECT isbn13, isbn10 FROM books WHERE id = ?');
        $bookStmt->execute([$bookId]);
        $book = $bookStmt->fetch();
        if (!$book) throw new RuntimeException('Buch nicht gefunden.');

        $seqStmt = $pdo->prepare(
            "SELECT sequence_no FROM book_files
             WHERE household_id = ? AND book_id = ?
             ORDER BY sequence_no DESC LIMIT 1 FOR UPDATE"
        );
        $seqStmt->execute([$householdId, $bookId]);
        $sequence = (int)($seqStmt->fetchColumn() ?: 0) + 1;
        $isbnPart = preg_replace('/[^0-9X]+/i', '', (string)($book['isbn13'] ?: ($book['isbn10'] ?? '')));
        $baseName = $isbnPart !== '' ? $isbnPart : ('B' . str_pad((string)$bookId, 8, '0', STR_PAD_LEFT));
        $storedName = $baseName . '-' . str_pad((string)$sequence, 3, '0', STR_PAD_LEFT) . '.' . $extension;
        $dir = ensure_book_files_dir($householdId, $bookId);
        $target = $dir . DIRECTORY_SEPARATOR . $storedName;
        if (!@move_uploaded_file($tmpName, $target)) {
            throw new RuntimeException('Die Datei konnte nicht in den Buchspeicher übernommen werden.');
        }
        @chmod($target, 0640);
        $relativePath = 'bookvault_data/files/h' . $householdId . '/b' . $bookId . '/' . $storedName;
        $sha256 = (string)hash_file('sha256', $target);
        $insert = $pdo->prepare(
            "INSERT INTO book_files
                (household_id, book_id, sequence_no, original_name, stored_name, local_path, mime_type,
                 file_extension, file_size, sha256, comment, share_allowed, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insert->execute([
            $householdId, $bookId, $sequence, mb_substr($originalName, 0, 500), $storedName,
            $relativePath, mb_substr($mime, 0, 160), $extension, $size, $sha256,
            $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 10000) : null,
            $shareAllowed ? 1 : 0, $userId,
        ]);
        $fileId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($target && is_file($target)) @unlink($target);
        throw $e;
    }
    book_event($bookId, 'book_file_uploaded', 'Datei zum Buch hinzugefügt', $userId, null, [
        'file_id' => $fileId,
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'share_allowed' => $shareAllowed,
    ], null, null, $householdId);
    return [
        'id' => $fileId,
        'sequence_no' => $sequence,
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'mime_type' => $mime,
        'file_extension' => $extension,
        'file_size' => $size,
        'comment' => $comment,
        'share_allowed' => $shareAllowed,
        'created_at' => date('Y-m-d H:i:s'),
    ];
}

/**
 * Wartung: Lädt die sichtbaren Dateien eines Buchs für Detailansichten.
 * Aufgerufen von: API book und public_share_book.
 * Abhängigkeiten: db().
 */
function book_files_for_view(int $bookId, int $householdId, bool $canManage): array
{
    $sql = "SELECT bf.id, bf.sequence_no, bf.original_name, bf.stored_name, bf.mime_type,
                   bf.file_extension, bf.file_size, bf.sha256, bf.comment, bf.share_allowed,
                   bf.uploaded_by, bf.created_at, bf.updated_at, u.display_name AS uploaded_by_name
            FROM book_files bf
            LEFT JOIN users u ON u.id = bf.uploaded_by
            WHERE bf.household_id = ? AND bf.book_id = ? AND bf.deleted_at IS NULL";
    if (!$canManage) $sql .= ' AND bf.share_allowed = 1';
    $sql .= ' ORDER BY bf.sequence_no, bf.id';
    $stmt = db()->prepare($sql);
    $stmt->execute([$householdId, $bookId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['sequence_no'] = (int)$row['sequence_no'];
        $row['file_size'] = (int)$row['file_size'];
        $row['share_allowed'] = (bool)$row['share_allowed'];
    }
    unset($row);
    return $rows;
}



// ======================== JOBS, METADATEN, COVER UND E-MAILS ========================
/**
 * Wartung: Legt einen Hintergrundjob in der Warteschlange an.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: db(), prepare().
 */
function queue_job(string $type, array $payload): void
{
    $pdo = db();
    if ($type === 'fetch_metadata' && !empty($payload['book_id'])) {
        $needle = '%"book_id":' . (int)$payload['book_id'] . '%';
        $stmt = $pdo->prepare(
            "SELECT id FROM jobs
             WHERE job_type = 'fetch_metadata' AND status IN ('pending', 'retry', 'processing')
               AND payload LIKE ? LIMIT 1"
        );
        $stmt->execute([$needle]);
        if ($stmt->fetchColumn()) {
            return;
        }
    }
    $stmt = $pdo->prepare("INSERT INTO jobs (job_type, payload, status, available_at) VALUES (?, ?, 'pending', NOW())");
    $stmt->execute([$type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

/**
 * Wartung: Legt eine E-Mail in der Versandwarteschlange ab.
 * Aufgerufen von: Globaler Ablauf/API/Events, enqueue_due_reminders(), enqueue_library_reminders().
 * Abhängigkeiten: db(), prepare(), valid_email().
 */
function queue_email(string $recipient, string $subject, string $body, ?string $sendAfter = null): void
{
    if (!valid_email($recipient)) {
        return;
    }
    $stmt = db()->prepare("INSERT INTO email_queue (recipient, subject, body, status, send_after) VALUES (?, ?, ?, 'pending', ?)");
    $stmt->execute([$recipient, mb_substr($subject, 0, 255), $body, $sendAfter ?: date('Y-m-d H:i:s')]);
}

/**
 * Wartung: Ruft externe HTTP-Ressourcen mit Timeout und Fehlerbehandlung ab.
 * Aufgerufen von: Globaler Ablauf/API/Events, download_cover_variant(), json_get(), metadata_dnb_result(), metadata_google_result(), metadata_openlibrary_result().
 * Abhängigkeiten: mail_from_address().
 */
function http_get(string $url, int $timeout = 8, array $extraHeaders = []): array
{
    $userAgent = 'Triamo/7.0 (' . mail_from_address() . ')';
    if (!function_exists('curl_init')) {
        $headerLines = array_merge([
            'User-Agent: ' . $userAgent,
            'Accept: application/json,application/xml,text/xml,image/*;q=0.9,*/*;q=0.5',
        ], $extraHeaders);
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => implode("\r\n", $headerLines) . "\r\n",
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $status = 0;
        $contentType = '';
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', (string)$header, $match)) {
                $status = (int)$match[1];
            } elseif (stripos((string)$header, 'Content-Type:') === 0) {
                $contentType = trim(substr((string)$header, strlen('Content-Type:')));
            }
        }
        $ok = $body !== false && ($status === 0 || ($status >= 200 && $status < 300));
        return [
            'ok' => $ok,
            'status' => $status ?: ($body !== false ? 200 : 0),
            'body' => $body ?: '',
            'content_type' => $contentType,
            'error' => $ok ? '' : (function_exists('error_get_last') ? (string)((error_get_last()['message'] ?? 'HTTP-Abruf fehlgeschlagen.')) : 'HTTP-Abruf fehlgeschlagen.'),
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json,application/xml,text/xml,image/*;q=0.9,*/*;q=0.5'], $extraHeaders),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => $body !== false && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $body === false ? '' : $body,
        'content_type' => $contentType,
        'error' => $error,
    ];
}

/**
 * Wartung: Ruft eine JSON-Ressource ab und dekodiert sie.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: http_get().
 */
function json_get(string $url, int $timeout = 8): ?array
{
    $response = http_get($url, $timeout);
    if (!$response['ok']) {
        return null;
    }
    $data = json_decode($response['body'], true);
    return is_array($data) ? $data : null;
}

/**
 * Wartung: Erzeugt die Standardstruktur für ein Metadatenergebnis.
 * Aufgerufen von: metadata_dnb_result(), metadata_google_result(), metadata_openlibrary_result().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function metadata_result(
    string $sourceKey,
    string $sourceName,
    string $status,
    ?array $data = null,
    ?string $raw = null,
    ?string $error = null,
    ?int $httpStatus = null
): array {
    return [
        'source_key' => $sourceKey,
        'source_name' => $sourceName,
        'status' => $status,
        'data' => $data,
        'raw' => $raw,
        'error' => $error,
        'http_status' => $httpStatus,
    ];
}

/**
 * Wartung: Normalisiert und prüft Cover-URLs.
 * Aufgerufen von: google_cover_candidates(), parse_dnb_marc(), resolve_cover_url(), unique_cover_urls().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function normalize_cover_url(?string $url): ?string
{
    $url = trim((string)$url);
    if ($url === '') {
        return null;
    }
    $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    $url = preg_replace('/^http:/i', 'https:', $url) ?? $url;
    return preg_match('#^https://#i', $url) ? $url : null;
}

/**
 * Wartung: Löst relative oder protokollrelative Cover-URLs auf.
 * Aufgerufen von: html_cover_candidates().
 * Abhängigkeiten: normalize_cover_url().
 */
function resolve_cover_url(?string $url, string $baseUrl = ''): ?string
{
    $url = html_entity_decode(trim((string)$url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'javascript:')) {
        return null;
    }
    if (str_starts_with($url, '//')) {
        return normalize_cover_url('https:' . $url);
    }
    if (preg_match('#^https?://#i', $url)) {
        return normalize_cover_url($url);
    }
    if ($baseUrl === '') {
        return null;
    }
    $base = parse_url($baseUrl);
    if (empty($base['host'])) {
        return null;
    }
    $origin = ($base['scheme'] ?? 'https') . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
    if (str_starts_with($url, '/')) {
        return normalize_cover_url($origin . $url);
    }
    $path = (string)($base['path'] ?? '/');
    $directory = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
    $combined = $directory . $url;
    $segments = [];
    foreach (explode('/', $combined) as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') { array_pop($segments); continue; }
        $segments[] = $segment;
    }
    return normalize_cover_url($origin . '/' . implode('/', $segments));
}

/**
 * Wartung: Entfernt doppelte Cover-Kandidaten bei erhaltener Reihenfolge.
 * Aufgerufen von: dnb_cover_candidates_from_isbn(), download_cover_variant(), google_cover_candidates(), html_cover_candidates(), metadata_dnb_result(), metadata_google_result(), metadata_openlibrary_result(), +3 weitere.
 * Abhängigkeiten: normalize_cover_url().
 */
function unique_cover_urls(array $urls): array
{
    $result = [];
    foreach ($urls as $url) {
        $normalized = normalize_cover_url(is_string($url) ? $url : null);
        if ($normalized && !isset($result[$normalized])) {
            $result[$normalized] = $normalized;
        }
    }
    return array_values($result);
}

/**
 * Wartung: Erzeugt mögliche Google-Books-Covervarianten.
 * Aufgerufen von: metadata_google_result().
 * Abhängigkeiten: normalize_cover_url(), unique_cover_urls().
 */
function google_cover_candidates(array $imageLinks): array
{
    $urls = [];
    foreach (['extraLarge', 'large', 'medium', 'small', 'thumbnail', 'smallThumbnail'] as $key) {
        if (!empty($imageLinks[$key])) {
            $urls[] = (string)$imageLinks[$key];
        }
    }
    // Google liefert nicht bei jedem Datensatz alle Größen. Aus vorhandenen
    // books.google-Links werden deshalb zusätzliche Zoomstufen als Fallback probiert.
    foreach (array_values($urls) as $url) {
        $normalized = normalize_cover_url($url);
        if (!$normalized || !str_contains($normalized, 'books.google.')) {
            continue;
        }
        $normalized = preg_replace('/([?&])edge=curl(?:&|$)/', '$1', $normalized) ?? $normalized;
        $normalized = rtrim($normalized, '?&');
        foreach ([4, 3, 2, 1] as $zoom) {
            if (preg_match('/([?&])zoom=\d+/', $normalized)) {
                $urls[] = preg_replace('/([?&])zoom=\d+/', '$1zoom=' . $zoom, $normalized) ?? $normalized;
            } else {
                $urls[] = $normalized . (str_contains($normalized, '?') ? '&' : '?') . 'zoom=' . $zoom;
            }
        }
    }
    return unique_cover_urls($urls);
}

/**
 * Wartung: Extrahiert Coverbilder aus HTML-Seiten.
 * Aufgerufen von: metadata_dnb_result().
 * Abhängigkeiten: resolve_cover_url(), unique_cover_urls().
 */
function html_cover_candidates(string $html, string $baseUrl): array
{
    $urls = [];
    $patterns = [
        "~<meta[^>]+(?:property|name)=[\"'](?:og:image|og:image:secure_url|twitter:image|twitter:image:src)[\"'][^>]+content=[\"']([^\"']+)[\"']~i",
        "~<meta[^>]+content=[\"']([^\"']+)[\"'][^>]+(?:property|name)=[\"'](?:og:image|og:image:secure_url|twitter:image|twitter:image:src)[\"']~i",
        "~<link[^>]+rel=[\"'](?:image_src|preload)[\"'][^>]+href=[\"']([^\"']+)[\"']~i",
        "~[\"']image[\"']\\s*:\\s*[\"']([^\"']+)[\"']~i",
        // Viele Bibliothekskataloge haben kein Open-Graph-Bild, aber ein klassisches Cover-IMG.
        "~<img[^>]+(?:alt|title)=[\"'][^\"']*(?:cover|buchumschlag|titelbild)[^\"']*[\"'][^>]+src=[\"']([^\"']+)[\"']~i",
        "~<img[^>]+src=[\"']([^\"']*(?:cover|buchumschlag|titelbild|thumbnail)[^\"']*)[\"'][^>]*>~i",
        "~<img[^>]+srcset=[\"']([^\"']+)[\"'][^>]*(?:alt|title)=[\"'][^\"']*(?:cover|buchumschlag|titelbild)[^\"']*[\"']~i",
    ];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $html, $matches)) {
            foreach ($matches[1] as $candidate) {
                // Bei srcset wird bevorzugt der letzte, normalerweise größte Kandidat genommen.
                if (str_contains((string)$candidate, ',')) {
                    $parts = array_map('trim', explode(',', (string)$candidate));
                    $candidate = preg_split('/\\s+/', (string)end($parts))[0] ?? $candidate;
                }
                $urls[] = (string)$candidate;
            }
        }
    }
    $resolved = [];
    foreach ($urls as $url) {
        $normalized = resolve_cover_url((string)$url, $baseUrl);
        if (!$normalized) continue;
        $lower = strtolower($normalized);
        if (str_contains($lower, 'logo') || str_contains($lower, 'favicon') || str_contains($lower, 'sprite') || str_contains($lower, '/icon')) {
            continue;
        }
        $resolved[] = $normalized;
    }
    return unique_cover_urls($resolved);
}

/**
 * Wartung: Lädt und normalisiert Metadaten aus Google Books.
 * Aufgerufen von: fetch_and_store_metadata().
 * Abhängigkeiten: clean_text(), clear_source_backoff(), google_cover_candidates(), http_get(), isbn13_to_10(), metadata_result(), nullable_text(), +3 weitere.
 */
function metadata_google_result(string $isbn): array
{
    $backoffUntil = source_backoff_until('google_books');
    if ($backoffUntil > time()) {
        return metadata_result(
            'google_books',
            'Google Books',
            'deferred',
            null,
            null,
            'Google Books wird wegen HTTP 429 bis ' . date('d.m.Y H:i', $backoffUntil) . ' pausiert.',
            429
        );
    }

    if (GOOGLE_BOOKS_API_KEY === '') {
        return metadata_result(
            'google_books',
            'Google Books',
            'skipped',
            null,
            null,
            'Kein Google-Books-API-Schlüssel eingetragen.'
        );
    }

    $candidates = array_values(array_unique(array_filter([$isbn, isbn13_to_10($isbn)])));
    $responses = [];
    $transportErrors = [];
    $allItems = [];
    $lastStatus = null;
    foreach ($candidates as $candidate) {
        $url = 'https://www.googleapis.com/books/v1/volumes?q=' . rawurlencode('isbn:' . $candidate)
            . '&maxResults=5&projection=full&key=' . rawurlencode(GOOGLE_BOOKS_API_KEY);
        $response = http_get($url, 12);
        $lastStatus = (int)$response['status'];
        $responses[$candidate] = $response['body'];
        if (!$response['ok']) {
            if ((int)$response['status'] === 429) {
                $until = set_source_backoff('google_books', 4 * 3600);
                $raw = json_encode($responses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                return metadata_result(
                    'google_books',
                    'Google Books',
                    'deferred',
                    null,
                    $raw,
                    'HTTP 429: Google Books wird bis ' . date('d.m.Y H:i', $until) . ' pausiert.',
                    429
                );
            }
            $transportErrors[] = $response['error'] ?: ('HTTP ' . (int)$response['status']);
            continue;
        }
        $payload = json_decode($response['body'], true);
        if (!is_array($payload)) {
            $transportErrors[] = 'Ungültige JSON-Antwort für ISBN ' . $candidate . '.';
            continue;
        }
        foreach (($payload['items'] ?? []) as $item) {
            $allItems[] = $item;
        }
        if ($allItems) {
            break;
        }
    }

    $raw = json_encode($responses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!$allItems) {
        if ($transportErrors) {
            return metadata_result('google_books', 'Google Books', 'error', null, $raw, implode('; ', array_unique($transportErrors)), $lastStatus);
        }
        return metadata_result('google_books', 'Google Books', 'not_found', null, $raw, 'Kein Treffer für ISBN-13 oder ISBN-10.', $lastStatus);
    }

    $selected = null;
    foreach ($allItems as $item) {
        $v = $item['volumeInfo'] ?? [];
        foreach (($v['industryIdentifiers'] ?? []) as $identifier) {
            $identifierValue = strtoupper(preg_replace('/[^0-9X]/i', '', (string)($identifier['identifier'] ?? '')) ?? '');
            if (in_array($identifierValue, $candidates, true)) {
                $selected = $item;
                break 2;
            }
        }
    }
    $selected ??= $allItems[0];
    $v = $selected['volumeInfo'] ?? [];
    if (empty($v['title'])) {
        return metadata_result('google_books', 'Google Books', 'not_found', null, $raw, 'Treffer ohne verwertbaren Titel.', $lastStatus);
    }

    $ids = [];
    foreach (($v['industryIdentifiers'] ?? []) as $identifier) {
        if (!empty($identifier['type']) && !empty($identifier['identifier'])) {
            $ids[(string)$identifier['type']] = strtoupper(preg_replace('/[^0-9X]/i', '', (string)$identifier['identifier']) ?? '');
        }
    }
    $coverCandidates = google_cover_candidates((array)($v['imageLinks'] ?? []));
    $volumeId = trim((string)($selected['id'] ?? ''));
    if ($volumeId !== '') {
        $directCandidates = [];
        foreach ([4, 3, 2, 1] as $zoom) {
            $direct = 'https://books.google.com/books/content?id=' . rawurlencode($volumeId)
                . '&printsec=frontcover&img=1&zoom=' . $zoom . '&source=gbs_api';
            $directCandidates[] = $direct;
            $directCandidates[] = $direct . '&edge=curl';
            if (GOOGLE_BOOKS_API_KEY !== '') {
                $directCandidates[] = $direct . '&key=' . rawurlencode(GOOGLE_BOOKS_API_KEY);
            }
        }
        $coverCandidates = unique_cover_urls(array_merge($coverCandidates, $directCandidates));
    }
    $cover = $coverCandidates[0] ?? null;

    $data = [
        'title' => clean_text($v['title'] ?? '', 500),
        'subtitle' => nullable_text($v['subtitle'] ?? null, 500),
        'authors' => nullable_text(implode(', ', array_map('strval', $v['authors'] ?? [])), 1000),
        'publisher' => nullable_text($v['publisher'] ?? null, 255),
        'published_date' => nullable_text($v['publishedDate'] ?? null, 30),
        'description' => nullable_text($v['description'] ?? null, 60000),
        'page_count' => isset($v['pageCount']) ? max(0, (int)$v['pageCount']) : null,
        'categories' => nullable_text(implode(', ', array_map('strval', $v['categories'] ?? [])), 4000),
        'language' => nullable_text($v['language'] ?? null, 20),
        'isbn10' => !empty($ids['ISBN_10']) ? $ids['ISBN_10'] : isbn13_to_10($isbn),
        'cover_url' => $cover,
        'cover_candidates' => $coverCandidates,
        'external_url' => nullable_text($v['infoLink'] ?? null, 1000),
    ];
    clear_source_backoff('google_books');
    return metadata_result('google_books', 'Google Books', 'success', $data, $raw, null, $lastStatus);
}

/**
 * Wartung: Sucht Open-Library-Daten über ISBN oder Titel.
 * Aufgerufen von: metadata_openlibrary_result().
 * Abhängigkeiten: clean_text(), isbn10_valid(), nullable_text(), unique_cover_urls().
 */
function openlibrary_search_data(array $doc, string $isbn): ?array
{
    if (empty($doc['title'])) {
        return null;
    }
    $isbn10 = null;
    foreach (($doc['isbn'] ?? []) as $candidate) {
        $candidate = strtoupper(preg_replace('/[^0-9X]/i', '', (string)$candidate) ?? '');
        if (strlen($candidate) === 10 && isbn10_valid($candidate)) {
            $isbn10 = $candidate;
            break;
        }
    }
    $coverCandidates = [];
    if (!empty($doc['cover_i'])) {
        foreach (['L', 'M', 'S'] as $size) {
            $coverCandidates[] = 'https://covers.openlibrary.org/b/id/' . (int)$doc['cover_i'] . '-' . $size . '.jpg?default=false';
        }
    }
    foreach (array_values(array_unique(array_filter([$isbn, $isbn10]))) as $identifier) {
        foreach (['L', 'M'] as $size) {
            $coverCandidates[] = 'https://covers.openlibrary.org/b/isbn/' . rawurlencode($identifier) . '-' . $size . '.jpg?default=false';
        }
    }
    $coverCandidates = unique_cover_urls($coverCandidates);
    $cover = $coverCandidates[0] ?? null;
    $published = $doc['publish_date'][0] ?? ($doc['first_publish_year'] ?? null);
    $key = (string)($doc['key'] ?? '');
    return [
        'title' => clean_text($doc['title'] ?? '', 500),
        'subtitle' => nullable_text($doc['subtitle'] ?? null, 500),
        'authors' => nullable_text(implode(', ', array_slice(array_map('strval', $doc['author_name'] ?? []), 0, 12)), 1000),
        'publisher' => nullable_text((string)($doc['publisher'][0] ?? ''), 255),
        'published_date' => nullable_text((string)$published, 30),
        'description' => null,
        'page_count' => isset($doc['number_of_pages_median']) ? max(0, (int)$doc['number_of_pages_median']) : null,
        'categories' => nullable_text(implode(', ', array_slice(array_map('strval', $doc['subject'] ?? []), 0, 20)), 4000),
        'language' => nullable_text((string)($doc['language'][0] ?? ''), 20),
        'isbn10' => $isbn10,
        'cover_url' => $cover,
        'cover_candidates' => $coverCandidates,
        'external_url' => $key !== '' ? 'https://openlibrary.org' . $key : null,
    ];
}

/**
 * Wartung: Lädt und normalisiert Metadaten aus Open Library.
 * Aufgerufen von: fetch_and_store_metadata().
 * Abhängigkeiten: clean_text(), http_get(), isbn10_valid(), isbn13_to_10(), metadata_result(), nullable_text(), openlibrary_search_data(), +1 weitere.
 */
function metadata_openlibrary_result(string $isbn): array
{
    $identifiers = array_values(array_unique(array_filter([$isbn, isbn13_to_10($isbn)])));
    $bibKeys = array_map(static fn(string $value): string => 'ISBN:' . $value, $identifiers);
    $url = 'https://openlibrary.org/api/books?bibkeys=' . rawurlencode(implode(',', $bibKeys)) . '&jscmd=data&format=json';
    $response = http_get($url, 12);
    $rawParts = ['books_api' => $response['body']];
    $hadTransportError = !$response['ok'];
    $transportMessages = [];
    if (!$response['ok']) {
        $transportMessages[] = $response['error'] ?: ('HTTP ' . (int)$response['status']);
    }

    if ($response['ok']) {
        $payload = json_decode($response['body'], true);
        $entry = null;
        if (is_array($payload)) {
            foreach ($bibKeys as $bibKey) {
                if (isset($payload[$bibKey]) && is_array($payload[$bibKey])) {
                    $entry = $payload[$bibKey];
                    break;
                }
            }
            $entry ??= array_values($payload)[0] ?? null;
        }
        if (is_array($entry) && !empty($entry['title'])) {
            $authors = array_values(array_filter(array_map(static fn($row) => (string)($row['name'] ?? ''), $entry['authors'] ?? [])));
            $publishers = array_values(array_filter(array_map(static fn($row) => (string)($row['name'] ?? ''), $entry['publishers'] ?? [])));
            $subjects = array_values(array_filter(array_map(static fn($row) => (string)($row['name'] ?? ''), $entry['subjects'] ?? [])));
            $ids = $entry['identifiers'] ?? [];
            $isbn10 = null;
            foreach (($ids['isbn_10'] ?? []) as $candidate) {
                $candidate = strtoupper(preg_replace('/[^0-9X]/i', '', (string)$candidate) ?? '');
                if (isbn10_valid($candidate)) {
                    $isbn10 = $candidate;
                    break;
                }
            }
            $data = [
                'title' => clean_text($entry['title'] ?? '', 500),
                'subtitle' => nullable_text($entry['subtitle'] ?? null, 500),
                'authors' => nullable_text(implode(', ', array_slice($authors, 0, 12)), 1000),
                'publisher' => nullable_text(implode(', ', array_slice($publishers, 0, 4)), 255),
                'published_date' => nullable_text($entry['publish_date'] ?? null, 30),
                'description' => null,
                'page_count' => isset($entry['number_of_pages']) ? max(0, (int)$entry['number_of_pages']) : null,
                'categories' => nullable_text(implode(', ', array_slice($subjects, 0, 20)), 4000),
                'language' => null,
                'isbn10' => $isbn10 ?: isbn13_to_10($isbn),
                'cover_url' => null,
                'cover_candidates' => [],
                'external_url' => nullable_text($entry['url'] ?? null, 1000),
            ];
            $openLibraryCandidates = [];
            foreach (['large', 'medium', 'small'] as $coverSize) {
                if (!empty($entry['cover'][$coverSize])) {
                    $openLibraryCandidates[] = (string)$entry['cover'][$coverSize];
                }
            }
            foreach ($identifiers as $identifier) {
                foreach (['L', 'M'] as $coverSize) {
                    $openLibraryCandidates[] = 'https://covers.openlibrary.org/b/isbn/' . rawurlencode($identifier) . '-' . $coverSize . '.jpg?default=false';
                }
            }
            $data['cover_candidates'] = unique_cover_urls($openLibraryCandidates);
            $data['cover_url'] = $data['cover_candidates'][0] ?? null;
            return metadata_result('open_library', 'Open Library', 'success', $data, json_encode($rawParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null, (int)$response['status']);
        }
    }

    // Die genaue Books-API ist erste Wahl; die Search-API dient als zweiter Versuch.
    $fields = 'key,title,subtitle,author_name,publisher,first_publish_year,publish_date,isbn,number_of_pages_median,subject,language,cover_i';
    $lastStatus = (int)$response['status'];
    foreach ($identifiers as $identifier) {
        $searchUrl = 'https://openlibrary.org/search.json?isbn=' . rawurlencode($identifier)
            . '&limit=3&fields=' . rawurlencode($fields);
        $search = http_get($searchUrl, 12);
        $lastStatus = (int)$search['status'];
        $rawParts['search_api_' . $identifier] = $search['body'];
        if ($search['ok']) {
            $payload = json_decode($search['body'], true);
            foreach (($payload['docs'] ?? []) as $doc) {
                $data = openlibrary_search_data((array)$doc, $isbn);
                if ($data) {
                    $data['isbn10'] ??= isbn13_to_10($isbn);
                    return metadata_result('open_library', 'Open Library', 'success', $data, json_encode($rawParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null, (int)$search['status']);
                }
            }
        } else {
            $hadTransportError = true;
            $transportMessages[] = $search['error'] ?: ('HTTP ' . (int)$search['status']);
        }
    }

    if ($hadTransportError) {
        $message = trim(implode('; ', array_unique(array_filter($transportMessages))));
        $message = $message !== '' ? $message : 'Open Library war nicht erreichbar.';
        return metadata_result('open_library', 'Open Library', 'error', null, json_encode($rawParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $message, $lastStatus);
    }
    return metadata_result('open_library', 'Open Library', 'not_found', null, json_encode($rawParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'Kein Treffer für ISBN-13 oder ISBN-10.', $lastStatus);
}

/**
 * Wartung: Liest Text aus einem XML-Fragment.
 * Aufgerufen von: marc_xml_control_first(), marc_xml_values().
 * Abhängigkeiten: clean_text().
 */
function xml_fragment_text(string $value): string
{
    $value = preg_replace('/<!\[CDATA\[(.*?)\]\]>/su', '$1', $value) ?? $value;
    $value = strip_tags($value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return clean_text($value, 4000);
}

/**
 * Wartung: Extrahiert MARC-XML-Datenfelder nach Tag und Unterfeld.
 * Aufgerufen von: marc_xml_first(), parse_dnb_marc().
 * Abhängigkeiten: xml_fragment_text().
 */
function marc_xml_values(string $xml, string $tag, string $code): array
{
    $tag = preg_quote($tag, '~');
    $code = preg_quote($code, '~');
    $fieldPattern = '~<(?:[a-z0-9_-]+:)?datafield\b(?=[^>]*\btag=["\']' . $tag . '["\'])[^>]*>(.*?)</(?:[a-z0-9_-]+:)?datafield>~si';
    if (!preg_match_all($fieldPattern, $xml, $fields)) {
        return [];
    }
    $values = [];
    $subfieldPattern = '~<(?:[a-z0-9_-]+:)?subfield\b(?=[^>]*\bcode=["\']' . $code . '["\'])[^>]*>(.*?)</(?:[a-z0-9_-]+:)?subfield>~si';
    foreach ($fields[1] as $field) {
        if (preg_match_all($subfieldPattern, (string)$field, $matches)) {
            foreach ($matches[1] as $match) {
                $value = xml_fragment_text((string)$match);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }
    }
    return $values;
}

/**
 * Wartung: Liefert den ersten passenden MARC-XML-Wert.
 * Aufgerufen von: parse_dnb_marc().
 * Abhängigkeiten: marc_xml_values().
 */
function marc_xml_first(string $xml, string $tag, string $code): ?string
{
    return marc_xml_values($xml, $tag, $code)[0] ?? null;
}

/**
 * Wartung: Liefert den ersten passenden MARC-Kontrollfeldwert.
 * Aufgerufen von: parse_dnb_marc().
 * Abhängigkeiten: xml_fragment_text().
 */
function marc_xml_control_first(string $xml, string $tag): ?string
{
    $tag = preg_quote($tag, '~');
    $pattern = '~<(?:[a-z0-9_-]+:)?controlfield\b(?=[^>]*\btag=["\']' . $tag . '["\'])[^>]*>(.*?)</(?:[a-z0-9_-]+:)?controlfield>~si';
    if (!preg_match($pattern, $xml, $match)) {
        return null;
    }
    $value = xml_fragment_text((string)$match[1]);
    return $value === '' ? null : $value;
}

/**
 * Wartung: Bereinigt MARC-Werte von Steuerzeichen und Interpunktion.
 * Aufgerufen von: parse_dnb_marc().
 * Abhängigkeiten: clean_text().
 */
function clean_marc_value(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = clean_text($value, 4000);
    $value = preg_replace('/\s*[\/:;,]\s*$/u', '', $value) ?? $value;
    $value = trim($value);
    return $value === '' ? null : $value;
}

/**
 * Wartung: Erzeugt Suchbegriffe für Cover-Recherchen.
 * Aufgerufen von: dnb_cover_candidates_from_isbn().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function isbn_cover_query_candidates(string $isbn): array
{
    $clean = strtoupper(preg_replace('/[^0-9X]/i', '', $isbn) ?? '');
    $candidates = [];
    if ($clean !== '') {
        $candidates[] = $clean;
    }
    // Für deutschsprachige ISBNs probieren wir mehrere gültige optische Bindestrichvarianten.
    // Die DNB-Coveradresse akzeptiert je nach Datensatz oft genau die gedruckte ISBN-Schreibweise.
    if (strlen($clean) === 13 && preg_match('/^(978|979)3(\d{8})(\d)$/', $clean, $m)) {
        $body = $m[2];
        $check = $m[3];
        for ($publisherLen = 1; $publisherLen <= 7; $publisherLen++) {
            $publisher = substr($body, 0, $publisherLen);
            $title = substr($body, $publisherLen);
            if ($publisher !== '' && $title !== '') {
                $candidates[] = $m[1] . '-3-' . $publisher . '-' . $title . '-' . $check;
            }
        }
    }
    if (strlen($clean) === 10 && preg_match('/^3(\d{8})([0-9X])$/', $clean, $m)) {
        $body = $m[1];
        $check = $m[2];
        for ($publisherLen = 1; $publisherLen <= 7; $publisherLen++) {
            $publisher = substr($body, 0, $publisherLen);
            $title = substr($body, $publisherLen);
            if ($publisher !== '' && $title !== '') {
                $candidates[] = '3-' . $publisher . '-' . $title . '-' . $check;
            }
        }
    }
    return array_values(array_unique(array_filter($candidates)));
}

/**
 * Wartung: Findet mögliche Coverlinks über DNB-nahe Suchseiten.
 * Aufgerufen von: parse_dnb_marc().
 * Abhängigkeiten: isbn_cover_query_candidates(), unique_cover_urls().
 */
function dnb_cover_candidates_from_isbn(string $isbn): array
{
    $urls = [];
    foreach (isbn_cover_query_candidates($isbn) as $candidate) {
        $urls[] = 'https://portal.dnb.de/opac/mvb/cover?isbn=' . rawurlencode($candidate);
    }
    return unique_cover_urls($urls);
}


/**
 * Wartung: Wandelt DNB-MARC-XML in ein Metadatenergebnis um.
 * Aufgerufen von: metadata_dnb_result().
 * Abhängigkeiten: clean_marc_value(), clean_text(), dnb_cover_candidates_from_isbn(), isbn10_valid(), marc_xml_control_first(), marc_xml_first(), marc_xml_values(), +3 weitere.
 */
function parse_dnb_marc(string $xmlBody, string $isbn): ?array
{
    $marcXml = $xmlBody;
    if (!preg_match('/<(?:[a-z0-9_-]+:)?datafield\b/i', $marcXml) && str_contains($marcXml, '&lt;')) {
        $marcXml = html_entity_decode($marcXml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
    $title = clean_marc_value(marc_xml_first($marcXml, '245', 'a'))
        ?? clean_marc_value(marc_xml_first($marcXml, '246', 'a'));
    if (!$title) {
        return null;
    }
    $authors = array_merge(
        marc_xml_values($marcXml, '100', 'a'),
        marc_xml_values($marcXml, '110', 'a'),
        marc_xml_values($marcXml, '700', 'a')
    );
    $authors = array_values(array_unique(array_filter(array_map('clean_marc_value', $authors))));
    $publisher = clean_marc_value(marc_xml_first($marcXml, '264', 'b'))
        ?? clean_marc_value(marc_xml_first($marcXml, '260', 'b'));
    $published = clean_marc_value(marc_xml_first($marcXml, '264', 'c'))
        ?? clean_marc_value(marc_xml_first($marcXml, '260', 'c'));
    $published = $published ? trim($published, "[]()., ") : null;
    $extent = clean_marc_value(marc_xml_first($marcXml, '300', 'a'));
    $pageCount = null;
    if ($extent && preg_match('/(\d{1,5})/u', $extent, $match)) {
        $pageCount = (int)$match[1];
    }
    $subjects = array_merge(marc_xml_values($marcXml, '650', 'a'), marc_xml_values($marcXml, '653', 'a'));
    $subjects = array_values(array_unique(array_filter(array_map('clean_marc_value', $subjects))));
    $language = clean_marc_value(marc_xml_first($marcXml, '041', 'a'));
    if ($language) {
        $language = mb_substr($language, 0, 3);
    }
    $isbn10 = null;
    foreach (marc_xml_values($marcXml, '020', 'a') as $candidate) {
        $parts = preg_split('/\s/u', $candidate, 2);
        $candidate = strtoupper(preg_replace('/[^0-9X]/i', '', (string)($parts[0] ?? '')) ?? '');
        if (strlen($candidate) === 10 && isbn10_valid($candidate)) {
            $isbn10 = $candidate;
            break;
        }
    }
    $identifier = marc_xml_control_first($marcXml, '001') ?? '';
    $coverCandidates = dnb_cover_candidates_from_isbn($isbn);
    foreach (marc_xml_values($marcXml, '856', 'u') as $candidateUrl) {
        $candidateUrl = normalize_cover_url($candidateUrl);
        if (!$candidateUrl) continue;
        $lower = strtolower($candidateUrl);
        if (preg_match('/\.(?:jpe?g|png|webp)(?:\?|$)/i', $candidateUrl)
            || str_contains($lower, 'cover') || str_contains($lower, 'thumbnail') || str_contains($lower, 'image')) {
            $coverCandidates[] = $candidateUrl;
        }
    }
    $coverCandidates = unique_cover_urls($coverCandidates);
    return [
        'title' => clean_text($title, 500),
        'subtitle' => nullable_text(clean_marc_value(marc_xml_first($marcXml, '245', 'b')), 500),
        'authors' => nullable_text(implode(', ', array_slice($authors, 0, 12)), 1000),
        'publisher' => nullable_text($publisher, 255),
        'published_date' => nullable_text($published, 30),
        'description' => nullable_text(clean_marc_value(marc_xml_first($marcXml, '520', 'a')), 60000),
        'page_count' => $pageCount,
        'categories' => nullable_text(implode(', ', array_slice($subjects, 0, 20)), 4000),
        'language' => nullable_text($language, 20),
        'isbn10' => $isbn10,
        'cover_url' => $coverCandidates[0] ?? null,
        'cover_candidates' => $coverCandidates,
        'external_url' => $identifier !== '' ? 'https://d-nb.info/' . rawurlencode($identifier) : null,
    ];
}

/**
 * Wartung: Lädt und normalisiert Metadaten aus der Deutschen Nationalbibliothek.
 * Aufgerufen von: fetch_and_store_metadata(), metadata_dnb().
 * Abhängigkeiten: html_cover_candidates(), http_get(), isbn13_to_10(), metadata_result(), parse_dnb_marc(), unique_cover_urls().
 */
function metadata_dnb_result(string $isbn): array
{
    $responses = [];
    $transportErrors = [];
    $lastStatus = null;
    $identifiers = array_values(array_unique(array_filter([$isbn, isbn13_to_10($isbn)])));
    $queries = [];
    foreach ($identifiers as $identifier) {
        $queries[] = 'num=' . $identifier;
        $queries[] = 'num="' . $identifier . '"';
    }
    foreach ($queries as $query) {
        $url = 'https://services.dnb.de/sru/dnb?version=1.1&operation=searchRetrieve&query=' . rawurlencode($query)
            . '&recordSchema=MARC21-xml&recordPacking=xml&maximumRecords=3';
        $response = http_get($url, 14);
        $lastStatus = (int)$response['status'];
        $responses[$query] = $response['body'];
        if (!$response['ok']) {
            $transportErrors[] = $response['error'] ?: ('HTTP ' . (int)$response['status']);
            continue;
        }
        $candidate = $response['body'];
        if (preg_match('/<(?:[a-z0-9_-]+:)?diagnostic\b/i', $candidate)
            || preg_match('/<(?:[a-z0-9_-]+:)?numberOfRecords>\s*0\s*</i', $candidate)) {
            continue;
        }
        $data = parse_dnb_marc($candidate, $isbn);
        if ($data) {
            // Manche DNB-Datensätze verweisen erst auf der Datensatzseite auf ein Cover.
            // Diese Seite wird nur ergänzend ausgewertet; bibliografische Daten bleiben aus SRU/MARC.
            if (!empty($data['external_url'])) {
                $page = http_get((string)$data['external_url'], 12, ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.7']);
                $responses['record_page'] = $page['body'];
                if ($page['ok'] && $page['body'] !== '') {
                    $pageCandidates = html_cover_candidates($page['body'], (string)$data['external_url']);
                    $data['cover_candidates'] = unique_cover_urls(array_merge((array)($data['cover_candidates'] ?? []), $pageCandidates));
                    $data['cover_url'] = $data['cover_candidates'][0] ?? null;
                }
            }
            return metadata_result('dnb', 'Deutsche Nationalbibliothek', 'success', $data, json_encode($responses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null, (int)$response['status']);
        }
    }
    if ($transportErrors) {
        return metadata_result('dnb', 'Deutsche Nationalbibliothek', 'error', null, json_encode($responses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), implode('; ', array_unique($transportErrors)), $lastStatus);
    }
    return metadata_result('dnb', 'Deutsche Nationalbibliothek', 'not_found', null, json_encode($responses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'Kein Treffer für ISBN-13 oder ISBN-10.', $lastStatus);
}

// Kompatibilitätshelfer für die erweiterte Online-Suche.
/**
 * Wartung: Kompatibilitätswrapper für den DNB-Metadatenabruf.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: metadata_dnb_result().
 */
function metadata_dnb(string $isbn): ?array
{
    $result = metadata_dnb_result($isbn);
    return $result['status'] === 'success' ? ($result['data'] + ['source' => $result['source_name']]) : null;
}

/**
 * Wartung: Speichert ein Metadatenergebnis quellenbezogen zum Buch.
 * Aufgerufen von: fetch_and_store_metadata().
 * Abhängigkeiten: db(), prepare().
 */
function store_book_metadata(int $bookId, string $isbn, array $result): void
{
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $raw = $result['raw'] ?? null;
    if (is_string($raw) && strlen($raw) > 1500000) {
        $raw = substr($raw, 0, 1500000) . "\n[gekürzt]";
    }
    $stmt = db()->prepare(
        "INSERT INTO book_metadata
            (book_id, household_id, isbn13, source_key, source_name, source_scope, fetch_status, title, subtitle, authors,
             publisher, published_date, description, page_count, categories, language, isbn10,
             cover_url, external_url, raw_payload, error_message, http_status, fetched_at)
         VALUES (?, NULL, ?, ?, ?, 'external', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            household_id = NULL, isbn13 = VALUES(isbn13), source_name = VALUES(source_name), source_scope = 'external',
            fetch_status = VALUES(fetch_status), title = VALUES(title), subtitle = VALUES(subtitle), authors = VALUES(authors),
            publisher = VALUES(publisher), published_date = VALUES(published_date), description = VALUES(description),
            page_count = VALUES(page_count), categories = VALUES(categories), language = VALUES(language),
            isbn10 = VALUES(isbn10), cover_url = VALUES(cover_url), external_url = VALUES(external_url),
            raw_payload = VALUES(raw_payload), error_message = VALUES(error_message), http_status = VALUES(http_status),
            fetched_at = NOW()"
    );
    $stmt->execute([
        $bookId, $isbn, $result['source_key'], $result['source_name'], $result['status'],
        $data['title'] ?? null, $data['subtitle'] ?? null, $data['authors'] ?? null,
        $data['publisher'] ?? null, $data['published_date'] ?? null, $data['description'] ?? null,
        $data['page_count'] ?? null, $data['categories'] ?? null, $data['language'] ?? null,
        $data['isbn10'] ?? null, $data['cover_url'] ?? null, $data['external_url'] ?? null,
        $raw, $result['error'] ?? null, $result['http_status'] ?? null,
    ]);
}

/**
 * Wartung: Erzeugt den Quellschlüssel für manuell geteilte Haushaltsmetadaten.
 * Aufgerufen von: store_manual_metadata().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function community_source_key(int $householdId): string
{
    return 'community-' . substr(hash_hmac('sha256', (string)$householdId, CRON_TOKEN), 0, 20);
}

/**
 * Wartung: Speichert manuelle Metadaten als haushaltsbezogene Quelle.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: community_source_key(), current_household_id(), db(), nullable_text(), prepare().
 */
function store_manual_metadata(int $bookId, ?string $isbn, array $data, ?int $householdId = null): void
{
    $householdId ??= current_household_id();
    $sourceKey = community_source_key($householdId);
    $payload = [
        'title' => nullable_text($data['title'] ?? null, 500),
        'subtitle' => nullable_text($data['subtitle'] ?? null, 500),
        'authors' => nullable_text($data['authors'] ?? null, 1000),
        'publisher' => nullable_text($data['publisher'] ?? null, 255),
        'published_date' => nullable_text($data['published_date'] ?? null, 30),
        'description' => nullable_text($data['description'] ?? null, 60000),
        'page_count' => isset($data['page_count']) && $data['page_count'] !== '' ? max(0, (int)$data['page_count']) : null,
        'categories' => nullable_text($data['categories'] ?? null, 4000),
        'language' => nullable_text($data['language'] ?? null, 20),
        'isbn10' => null,
        'cover_url' => null,
        'external_url' => null,
    ];
    $stmt = db()->prepare(
        "INSERT INTO book_metadata
            (book_id, household_id, isbn13, source_key, source_name, source_scope, fetch_status, title, subtitle, authors,
             publisher, published_date, description, page_count, categories, language, isbn10, cover_url, external_url,
             raw_payload, error_message, http_status, fetched_at)
         VALUES (?, ?, ?, ?, 'Benutzereingabe', 'community', 'success', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NOW())
         ON DUPLICATE KEY UPDATE
            household_id = VALUES(household_id), isbn13 = VALUES(isbn13), source_name = 'Benutzereingabe',
            source_scope = 'community', fetch_status = 'success', title = VALUES(title), subtitle = VALUES(subtitle),
            authors = VALUES(authors), publisher = VALUES(publisher), published_date = VALUES(published_date),
            description = VALUES(description), page_count = VALUES(page_count), categories = VALUES(categories),
            language = VALUES(language), isbn10 = VALUES(isbn10), cover_url = VALUES(cover_url),
            external_url = VALUES(external_url), raw_payload = VALUES(raw_payload), error_message = NULL,
            http_status = NULL, fetched_at = NOW()"
    );
    $stmt->execute([
        $bookId, $householdId, $isbn, $sourceKey,
        $payload['title'], $payload['subtitle'], $payload['authors'], $payload['publisher'],
        $payload['published_date'], $payload['description'], $payload['page_count'], $payload['categories'],
        $payload['language'], $payload['isbn10'], $payload['cover_url'], $payload['external_url'],
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
    ]);
}

/**
 * Wartung: Speichert lokale Buchüberschreibungen und Sichtbarkeit je Haushalt.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: clean_text(), db(), nullable_text(), prepare().
 */
function save_household_book_settings(int $householdId, int $bookId, array $data, ?string $visibility = null): void
{
    $visibility = $visibility ?? clean_text($data['visibility'] ?? 'lendable', 20);
    if (!in_array($visibility, ['internal', 'visible', 'lendable'], true)) {
        $visibility = 'lendable';
    }
    $values = [
        nullable_text($data['title'] ?? null, 500),
        nullable_text($data['subtitle'] ?? null, 500),
        nullable_text($data['authors'] ?? null, 1000),
        nullable_text($data['publisher'] ?? null, 255),
        nullable_text($data['published_date'] ?? null, 30),
        nullable_text($data['description'] ?? null, 60000),
        isset($data['page_count']) && $data['page_count'] !== '' ? max(0, (int)$data['page_count']) : null,
        nullable_text($data['categories'] ?? null, 4000),
        nullable_text($data['language'] ?? null, 20),
    ];
    $stmt = db()->prepare(
        "INSERT INTO household_book_settings
            (household_id, book_id, title_override, subtitle_override, authors_override, publisher_override,
             published_date_override, description_override, page_count_override, categories_override,
             language_override, visibility, archived_at, archived_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)
         ON DUPLICATE KEY UPDATE
            title_override = VALUES(title_override), subtitle_override = VALUES(subtitle_override),
            authors_override = VALUES(authors_override), publisher_override = VALUES(publisher_override),
            published_date_override = VALUES(published_date_override), description_override = VALUES(description_override),
            page_count_override = VALUES(page_count_override), categories_override = VALUES(categories_override),
            language_override = VALUES(language_override), visibility = VALUES(visibility),
            archived_at = NULL, archived_by = NULL"
    );
    $stmt->execute([$householdId, $bookId, ...$values, $visibility]);
}

/**
 * Wartung: Übernimmt die beste verfügbare Metadatenquelle in den Buchstamm.
 * Aufgerufen von: fetch_and_store_metadata(), finalize_book_metadata_from_stored_sources().
 * Abhängigkeiten: db(), prepare().
 */
function consolidate_metadata_sources(int $bookId): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM book_metadata
         WHERE book_id = ? AND fetch_status = 'success' AND source_scope = 'external'"
    );
    $stmt->execute([$bookId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[(string)$row['source_key']] = $row;
    }
    if (!$rows) {
        return null;
    }

    $fieldPriority = [
        'title' => ['dnb', 'google_books', 'open_library'],
        'subtitle' => ['dnb', 'google_books', 'open_library'],
        'authors' => ['dnb', 'google_books', 'open_library'],
        'publisher' => ['dnb', 'google_books', 'open_library'],
        'published_date' => ['dnb', 'google_books', 'open_library'],
        'description' => ['google_books', 'dnb', 'open_library'],
        'page_count' => ['google_books', 'open_library', 'dnb'],
        'categories' => ['google_books', 'dnb', 'open_library'],
        'language' => ['google_books', 'dnb', 'open_library'],
        'isbn10' => ['dnb', 'google_books', 'open_library'],
        'cover_url' => ['google_books', 'open_library', 'dnb'],
    ];
    $meta = [];
    $fieldSources = [];
    $usedNames = [];
    foreach ($fieldPriority as $field => $priority) {
        $meta[$field] = null;
        foreach ($priority as $sourceKey) {
            $value = $rows[$sourceKey][$field] ?? null;
            if ($value !== null && $value !== '') {
                $meta[$field] = $field === 'page_count' ? (int)$value : $value;
                $fieldSources[$field] = [
                    'key' => $sourceKey,
                    'name' => (string)$rows[$sourceKey]['source_name'],
                ];
                $usedNames[(string)$rows[$sourceKey]['source_name']] = true;
                break;
            }
        }
    }
    if (empty($meta['title'])) {
        return null;
    }
    return [
        'meta' => $meta,
        'field_sources' => $fieldSources,
        'source_summary' => implode(' + ', array_keys($usedNames)),
    ];
}


/**
 * Wartung: Erstellt das lokale Cover-Verzeichnis.
 * Aufgerufen von: download_cover_variant().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function ensure_cover_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'bookvault_data' . DIRECTORY_SEPARATOR . 'covers';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Das Cover-Verzeichnis konnte nicht angelegt werden.');
    }
    $index = dirname($dir) . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '');
    }
    $coverIndex = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($coverIndex)) {
        @file_put_contents($coverIndex, '');
    }
    return $dir;
}

/**
 * Wartung: Lädt ein Coverbild herunter, prüft es und speichert es lokal.
 * Aufgerufen von: store_cover_candidate().
 * Abhängigkeiten: db(), ensure_cover_dir(), http_get(), prepare(), unique_cover_urls().
 */
function download_cover_variant(string|array|null $urls, int $bookId, string $sourceKey): array
{
    $candidates = unique_cover_urls(is_array($urls) ? $urls : [$urls]);
    if (!$candidates) {
        return ['ok' => false, 'error' => 'Keine Bildadresse vorhanden.', 'attempted_urls' => []];
    }

    $best = null;
    $errors = [];
    $attempted = [];
    foreach (array_slice($candidates, 0, 16) as $url) {
        $attempted[] = $url;
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $referer = str_contains($host, 'google') ? 'https://books.google.com/'
            : (str_contains($host, 'openlibrary') ? 'https://openlibrary.org/'
                : (str_contains($host, 'dnb') ? 'https://d-nb.info/' : null));
        $headers = ['Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8'];
        if ($referer) {
            $headers[] = 'Referer: ' . $referer;
        }
        $response = http_get($url, 18, $headers);
        if (!$response['ok'] || $response['body'] === '') {
            $errors[] = $url . ': ' . ($response['error'] ?: ('HTTP ' . (int)$response['status']));
            continue;
        }
        if (strlen($response['body']) > 12 * 1024 * 1024) {
            $errors[] = $url . ': Bild größer als 12 MB';
            continue;
        }

        $type = strtolower((string)$response['content_type']);
        $extension = null;
        $mime = null;
        if (str_contains($type, 'image/jpeg') || str_starts_with($response['body'], "\xFF\xD8\xFF")) {
            $extension = 'jpg'; $mime = 'image/jpeg';
        } elseif (str_contains($type, 'image/png') || str_starts_with($response['body'], "\x89PNG")) {
            $extension = 'png'; $mime = 'image/png';
        } elseif (str_contains($type, 'image/webp') || substr($response['body'], 8, 4) === 'WEBP') {
            $extension = 'webp'; $mime = 'image/webp';
        }
        if (!$extension) {
            $preview = trim(strip_tags(substr($response['body'], 0, 180)));
            $errors[] = $url . ': kein unterstütztes Bildformat' . ($preview !== '' ? ' (' . mb_substr($preview, 0, 80) . ')' : '');
            continue;
        }

        $size = @getimagesizefromstring($response['body']);
        $width = is_array($size) ? (int)($size[0] ?? 0) : 0;
        $height = is_array($size) ? (int)($size[1] ?? 0) : 0;
        if ($width < 40 || $height < 40) {
            $errors[] = $url . ': Bild zu klein oder ungültig (' . $width . ' × ' . $height . ')';
            continue;
        }
        $candidate = [
            'body' => $response['body'], 'remote_url' => $url, 'extension' => $extension,
            'mime_type' => $mime, 'width' => $width, 'height' => $height,
            'file_size' => strlen($response['body']),
        ];
        $candidateScore = $width * $height;
        $bestScore = $best ? ((int)$best['width'] * (int)$best['height']) : -1;
        if (!$best || $candidateScore > $bestScore
            || ($candidateScore === $bestScore && $candidate['file_size'] > $best['file_size'])) {
            $best = $candidate;
        }
    }

    if (!$best) {
        return [
            'ok' => false,
            'error' => $errors ? implode(' | ', array_slice($errors, 0, 6)) : 'Keine Bildquelle lieferte ein verwertbares Bild.',
            'attempted_urls' => $attempted,
        ];
    }

    $dir = ensure_cover_dir();
    $suffix = preg_replace('/[^a-z0-9]+/i', '-', strtolower($sourceKey)) ?: 'quelle';
    $bookStmt = db()->prepare('SELECT isbn13, isbn10 FROM books WHERE id = ?');
    $bookStmt->execute([$bookId]);
    $bookRow = $bookStmt->fetch() ?: [];
    $isbnPart = preg_replace('/[^0-9X]+/i', '', (string)($bookRow['isbn13'] ?: ($bookRow['isbn10'] ?? '')));
    $baseName = $isbnPart !== '' ? $isbnPart : ('ohne-isbn-' . str_pad((string)$bookId, 8, '0', STR_PAD_LEFT));
    $filename = $baseName . '-' . trim($suffix, '-') . '.' . $best['extension'];
    $target = $dir . DIRECTORY_SEPARATOR . $filename;
    $tmp = $target . '.tmp-' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, $best['body'], LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'Die Bilddatei konnte nicht auf dem Server gespeichert werden.', 'attempted_urls' => $attempted];
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'Die Bilddatei konnte nicht übernommen werden.', 'attempted_urls' => $attempted];
    }

    return [
        'ok' => true,
        'remote_url' => $best['remote_url'],
        'local_path' => 'bookvault_data/covers/' . $filename,
        'mime_type' => $best['mime_type'],
        'width' => $best['width'],
        'height' => $best['height'],
        'file_size' => $best['file_size'],
        'attempted_urls' => $attempted,
        'warnings' => $errors,
    ];
}

/**
 * Wartung: Speichert einen Cover-Kandidaten und startet bei Bedarf den Download.
 * Aufgerufen von: fetch_and_store_metadata().
 * Abhängigkeiten: db(), download_cover_variant(), prepare(), unique_cover_urls().
 */
function store_cover_candidate(int $bookId, array $result): array
{
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $coverCandidates = is_array($data['cover_candidates'] ?? null) ? $data['cover_candidates'] : [];
    if (!empty($data['cover_url'])) $coverCandidates[] = (string)$data['cover_url'];
    $coverCandidates = unique_cover_urls($coverCandidates);
    $url = $coverCandidates[0] ?? null;
    if (($result['status'] ?? '') !== 'success' || !$url) {
        return ['attempted' => false, 'ok' => true, 'source_name' => $result['source_name'] ?? 'Quelle'];
    }
    $download = download_cover_variant($coverCandidates, $bookId, (string)$result['source_key']);
    if (empty($download['ok'])) {
        $old = db()->prepare("SELECT local_path, mime_type, width, height, file_size FROM book_covers WHERE book_id = ? AND source_key = ? LIMIT 1");
        $old->execute([$bookId, $result['source_key']]);
        $existing = $old->fetch();
        if ($existing && !empty($existing['local_path']) && is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$existing['local_path']))) {
            $download = [
                'ok' => true,
                'local_path' => $existing['local_path'],
                'mime_type' => $existing['mime_type'],
                'width' => $existing['width'],
                'height' => $existing['height'],
                'file_size' => $existing['file_size'],
                'warning' => $download['error'] ?? 'Die Quelle war beim letzten Aktualisieren nicht erreichbar.',
            ];
        }
    }
    $stmt = db()->prepare(
        "INSERT INTO book_covers
            (book_id, source_key, source_name, remote_url, local_path, mime_type, width, height, file_size,
             fetch_status, error_message, fetched_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            source_name = VALUES(source_name), remote_url = VALUES(remote_url), local_path = VALUES(local_path),
            mime_type = VALUES(mime_type), width = VALUES(width), height = VALUES(height), file_size = VALUES(file_size),
            fetch_status = VALUES(fetch_status), error_message = VALUES(error_message), fetched_at = NOW()"
    );
    $stmt->execute([
        $bookId, $result['source_key'], $result['source_name'], $download['remote_url'] ?? $url,
        $download['local_path'] ?? null, $download['mime_type'] ?? null,
        $download['width'] ?? null, $download['height'] ?? null, $download['file_size'] ?? null,
        !empty($download['ok']) ? 'success' : 'error', $download['warning'] ?? ($download['error'] ?? null),
    ]);
    return [
        'attempted' => true,
        'ok' => !empty($download['ok']) && empty($download['warning']),
        'preserved' => !empty($download['warning']),
        'source_name' => $result['source_name'],
        'error' => $download['warning'] ?? ($download['error'] ?? null),
    ];
}

/**
 * Wartung: Wählt das aktive Cover für Buch und Haushalt.
 * Aufgerufen von: fetch_and_store_metadata(), finalize_book_metadata_from_stored_sources().
 * Abhängigkeiten: db(), prepare().
 */
function choose_active_cover(int $bookId): ?array
{
    $stmt = db()->prepare("SELECT selected_cover_id FROM books WHERE id = ?");
    $stmt->execute([$bookId]);
    $selectedId = $stmt->fetchColumn();
    $cover = null;
    if ($selectedId) {
        $stmt = db()->prepare("SELECT * FROM book_covers WHERE id = ? AND book_id = ? AND fetch_status = 'success' AND local_path IS NOT NULL");
        $stmt->execute([(int)$selectedId, $bookId]);
        $cover = $stmt->fetch() ?: null;
    }
    if (!$cover) {
        $stmt = db()->prepare(
            "SELECT * FROM book_covers
             WHERE book_id = ? AND fetch_status = 'success' AND local_path IS NOT NULL
             ORDER BY (COALESCE(width,0) * COALESCE(height,0)) DESC, COALESCE(file_size,0) DESC, id ASC LIMIT 1"
        );
        $stmt->execute([$bookId]);
        $cover = $stmt->fetch() ?: null;
    }
    if ($cover) {
        db()->prepare("UPDATE books SET cover_path = ?, cover_url = ? WHERE id = ?")
            ->execute([$cover['local_path'], $cover['remote_url'], $bookId]);
        return $cover;
    }
    return null;
}


/**
 * Wartung: Führt den kompletten Metadatenabruf für ein Buch aus.
 * Aufgerufen von: process_jobs().
 * Abhängigkeiten: book_event(), choose_active_cover(), consolidate_metadata_sources(), db(), metadata_dnb_result(), metadata_google_result(), metadata_openlibrary_result(), +4 weitere.
 */
function fetch_and_store_metadata(int $bookId, string $isbn): void
{
    $pdo = db();
    $pdo->prepare("UPDATE books SET metadata_status = 'fetching', metadata_error = NULL WHERE id = ?")->execute([$bookId]);

    // Jede Quelle wird unabhängig abgefragt und separat gespeichert. Ein Fehler
    // bei einer Quelle verhindert deshalb nicht mehr die Auswertung der anderen.
    $results = [
        metadata_google_result($isbn),
        metadata_openlibrary_result($isbn),
        metadata_dnb_result($isbn),
    ];
    $networkErrors = [];
    $deferredSources = [];
    $successfulSources = [];
    foreach ($results as $result) {
        store_book_metadata($bookId, $isbn, $result);
        if ($result['status'] === 'success') {
            $successfulSources[] = $result['source_name'];
        } elseif ($result['status'] === 'deferred') {
            $deferredSources[] = $result['source_name'] . ': ' . ($result['error'] ?: 'später erneut versuchen');
        } elseif ($result['status'] === 'error') {
            $networkErrors[] = $result['source_name'] . ': ' . ($result['error'] ?: 'Abruffehler');
        }
    }

    $consolidated = consolidate_metadata_sources($bookId);
    if (!$consolidated) {
        if ($deferredSources) {
            $message = implode(' | ', $deferredSources);
            $retryAfter = max(3600, source_backoff_until('google_books') - time() + 300);
            $pdo->prepare("UPDATE books SET metadata_status = 'queued', metadata_error = ? WHERE id = ?")
                ->execute([mb_substr($message, 0, 4000), $bookId]);
            throw new MetadataRetryLaterException($message, $retryAfter);
        }
        if ($networkErrors) {
            $message = implode(' | ', $networkErrors);
            $pdo->prepare("UPDATE books SET metadata_status = 'queued', metadata_error = ? WHERE id = ?")
                ->execute([mb_substr($message, 0, 4000), $bookId]);
            throw new MetadataRetryLaterException($message, 6 * 3600);
        }
        $message = 'Keine der eingerichteten Quellen hat Metadaten zu dieser ISBN geliefert.';
        $pdo->prepare("UPDATE books SET metadata_status = 'error', metadata_error = ? WHERE id = ?")
            ->execute([$message, $bookId]);
        book_event($bookId, 'metadata_not_found', 'Keine Metadatenquelle lieferte einen Treffer', null, null, [
            'sources' => array_map(static fn(array $r): array => [
                'source' => $r['source_name'],
                'status' => $r['status'],
                'error' => $r['error'],
            ], $results),
        ]);
        return;
    }

    $meta = $consolidated['meta'];
    $coverErrors = [];
    foreach ($results as $result) {
        $coverResult = store_cover_candidate($bookId, $result);
        if (!empty($coverResult['attempted']) && empty($coverResult['ok'])) {
            $coverErrors[] = ($coverResult['source_name'] ?? 'Coverquelle') . ': ' . ($coverResult['error'] ?? 'Bild konnte nicht geladen werden.');
        }
    }
    $activeCover = choose_active_cover($bookId);
    $stmt = $pdo->prepare(
        "UPDATE books SET
            isbn10 = COALESCE(?, isbn10),
            title = ?, subtitle = ?, authors = ?, publisher = ?, published_date = ?,
            description = ?, page_count = ?, categories = ?, language = ?,
            cover_path = COALESCE(?, cover_path), cover_url = COALESCE(?, cover_url),
            metadata_source = ?, metadata_field_sources = ?,
            metadata_status = 'ready', metadata_error = NULL
         WHERE id = ?"
    );
    $stmt->execute([
        $meta['isbn10'] ?? null,
        $meta['title'],
        $meta['subtitle'] ?? null,
        $meta['authors'] ?? null,
        $meta['publisher'] ?? null,
        $meta['published_date'] ?? null,
        $meta['description'] ?? null,
        $meta['page_count'] ?? null,
        $meta['categories'] ?? null,
        $meta['language'] ?? null,
        $activeCover['local_path'] ?? null,
        $activeCover['remote_url'] ?? ($meta['cover_url'] ?? null),
        $consolidated['source_summary'],
        json_encode($consolidated['field_sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $bookId,
    ]);
    book_event($bookId, 'metadata_loaded', 'Metadatenquellen aktualisiert', null, null, [
        'successful_sources' => $successfulSources,
        'field_sources' => $consolidated['field_sources'],
        'source_errors' => $networkErrors,
        'cover_errors' => $coverErrors,
    ]);

    // Verwertbare Metadaten schließen den Auftrag ab. Cover- oder Teilquellenfehler
    // bleiben in den Quellenkarten sichtbar, blockieren aber nicht mehr die Warteschlange.
    // Dadurch steht ein Buch nicht dauerhaft auf „In Warteschlange“, obwohl der Datensatz
    // bereits brauchbar ist.
}



/**
 * Wartung: Finalisiert Buchdaten anhand gespeicherter Quellen.
 * Aufgerufen von: cleanup_finished_metadata_jobs().
 * Abhängigkeiten: choose_active_cover(), consolidate_metadata_sources(), db(), prepare().
 */
function finalize_book_metadata_from_stored_sources(int $bookId): bool
{
    $consolidated = consolidate_metadata_sources($bookId);
    if (!$consolidated) {
        return false;
    }
    $meta = $consolidated['meta'];
    $activeCover = choose_active_cover($bookId);
    db()->prepare(
        "UPDATE books SET
            isbn10 = COALESCE(?, isbn10),
            title = ?, subtitle = ?, authors = ?, publisher = ?, published_date = ?,
            description = ?, page_count = ?, categories = ?, language = ?,
            cover_path = COALESCE(?, cover_path), cover_url = COALESCE(?, cover_url),
            metadata_source = ?, metadata_field_sources = ?,
            metadata_status = 'ready', metadata_error = NULL
         WHERE id = ?"
    )->execute([
        $meta['isbn10'] ?? null,
        $meta['title'],
        $meta['subtitle'] ?? null,
        $meta['authors'] ?? null,
        $meta['publisher'] ?? null,
        $meta['published_date'] ?? null,
        $meta['description'] ?? null,
        $meta['page_count'] ?? null,
        $meta['categories'] ?? null,
        $meta['language'] ?? null,
        $activeCover['local_path'] ?? null,
        $activeCover['remote_url'] ?? ($meta['cover_url'] ?? null),
        $consolidated['source_summary'],
        json_encode($consolidated['field_sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $bookId,
    ]);
    return true;
}


/**
 * Wartung: Bereinigt doppelte erledigte Metadatenjobs.
 * Aufgerufen von: cleanup_finished_metadata_jobs().
 * Abhängigkeiten: exec().
 */
function cleanup_duplicate_done_metadata_jobs(PDO $pdo): void
{
    // Historische erledigte Dubletten zusammenräumen, die durch die alte
    // Auto-Einplanung entstanden sind. Pro Buch bleibt der neueste erledigte
    // Metadatenauftrag erhalten; Fehler und aktive Aufträge bleiben sichtbar.
    $pdo->exec(
        "DELETE j FROM jobs j
         JOIN jobs newer
           ON newer.job_type = 'fetch_metadata'
          AND newer.status = 'done'
          AND j.job_type = 'fetch_metadata'
          AND j.status = 'done'
          AND newer.id > j.id
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(newer.payload, '$.book_id')) AS UNSIGNED)
              = CAST(JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.book_id')) AS UNSIGNED)"
    );
}

/**
 * Wartung: Entfernt alte abgeschlossene oder gescheiterte Jobs.
 * Aufgerufen von: metadata_queue_snapshot(), process_jobs().
 * Abhängigkeiten: cleanup_duplicate_done_metadata_jobs(), exec(), finalize_book_metadata_from_stored_sources(), query().
 */
function cleanup_finished_metadata_jobs(PDO $pdo): void
{
    cleanup_duplicate_done_metadata_jobs($pdo);
    // Wenn eine Quelle bereits brauchbare Metadaten geliefert hat, wird das Buch aus
    // den gespeicherten Quellen finalisiert. Dadurch bleiben alte oder doppelte Jobs
    // nicht als „In Warteschlange“ stehen, obwohl DNB/Open Library bereits Erfolg hatten.
    $stmt = $pdo->query(
        "SELECT DISTINCT b.id
         FROM jobs j
         JOIN books b ON b.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.book_id')) AS UNSIGNED)
         JOIN book_metadata bm ON bm.book_id = b.id AND bm.source_scope = 'external' AND bm.fetch_status = 'success'
         WHERE j.job_type = 'fetch_metadata'
           AND j.status IN ('pending','retry','processing')
           AND b.metadata_status IN ('queued','fetching')
           AND JSON_EXTRACT(j.payload, '$.force') IS NULL
         LIMIT 120"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $bookId) {
        finalize_book_metadata_from_stored_sources((int)$bookId);
    }

    // Alte Jobs sollen die Oberfläche nicht dauerhaft als „in Warteschlange“ blockieren,
    // wenn das Buch inzwischen bereits fertig oder endgültig ohne Treffer ist.
    $pdo->exec(
        "UPDATE jobs j
         JOIN books b ON b.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.book_id')) AS UNSIGNED)
         SET j.status = 'done', j.last_error = COALESCE(j.last_error, 'Automatisch erledigt: Buchstatus ist bereits abgeschlossen')
         WHERE j.job_type = 'fetch_metadata'
           AND j.status IN ('pending','retry','processing')
           AND b.metadata_status IN ('ready','error')"
    );

    $pdo->exec(
        "UPDATE jobs
         SET status = 'failed', last_error = 'Auftrag abgebrochen: Das zugehörige Buch existiert nicht mehr'
         WHERE job_type = 'fetch_metadata'
           AND status IN ('pending','retry','processing')
           AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.book_id')) AS UNSIGNED) NOT IN (SELECT id FROM books)"
    );

    $pdo->exec(
        "UPDATE jobs
         SET status = 'failed', last_error = COALESCE(last_error, 'Maximale Anzahl von Metadatenversuchen erreicht')
         WHERE job_type = 'fetch_metadata'
           AND status IN ('pending','retry')
           AND attempts >= " . (int)MAX_JOB_ATTEMPTS
    );
}

/**
 * Wartung: Liefert Statistik und Einträge der Metadatenwarteschlange.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: cleanup_finished_metadata_jobs(), db(), prepare(), query(), source_backoff_until().
 */
function metadata_queue_snapshot(?int $householdId = null): array
{
    $pdo = db();
    cleanup_finished_metadata_jobs($pdo);

    $where = "j.job_type = 'fetch_metadata'";
    $params = [];
    if ($householdId !== null) {
        $where .= " AND EXISTS (SELECT 1 FROM household_book_settings hbs WHERE hbs.household_id = ? AND hbs.book_id = b.id)";
        $params[] = $householdId;
    }

    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(j.status IN ('pending','retry')) AS waiting,
            SUM(j.status = 'processing') AS processing,
            SUM(j.status = 'failed') AS failed,
            SUM(j.status = 'done') AS done,
            COALESCE(GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), MIN(CASE WHEN j.status IN ('pending','retry') THEN j.available_at END))), 0) AS next_delay_seconds
         FROM jobs j
         LEFT JOIN books b ON b.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.book_id')) AS UNSIGNED)
         WHERE $where"
    );
    $stmt->execute($params);
    $summary = $stmt->fetch() ?: [];

    $stmt = $pdo->prepare(
        "SELECT j.id, j.job_type, j.status, j.attempts, j.available_at, j.locked_at, j.last_error, j.created_at, j.updated_at,
                b.id AS book_id, b.isbn13, b.isbn10, b.title, b.metadata_status, b.metadata_error,
                GROUP_CONCAT(CONCAT(bm.source_name, ':', bm.fetch_status) ORDER BY bm.source_name SEPARATOR ' | ') AS source_summary
         FROM jobs j
         LEFT JOIN books b ON b.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.book_id')) AS UNSIGNED)
         LEFT JOIN book_metadata bm ON bm.book_id = b.id AND bm.source_scope = 'external'
         WHERE $where
         GROUP BY j.id, b.id
         ORDER BY FIELD(j.status,'processing','pending','retry','failed','done'), j.available_at ASC, j.id DESC
         LIMIT 80"
    );
    $stmt->execute($params);
    $jobs = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['id'] = (int)$row['id'];
        $row['attempts'] = (int)$row['attempts'];
        $row['book_id'] = $row['book_id'] !== null ? (int)$row['book_id'] : null;
        $row['next_delay_seconds'] = $row['available_at'] ? max(0, (int)$pdo->query("SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), " . $pdo->quote((string)$row['available_at']) . "))")->fetchColumn()) : 0;
        $jobs[] = $row;
    }

    return [
        'summary' => [
            'total' => (int)($summary['total'] ?? 0),
            'waiting' => (int)($summary['waiting'] ?? 0),
            'processing' => (int)($summary['processing'] ?? 0),
            'failed' => (int)($summary['failed'] ?? 0),
            'done' => (int)($summary['done'] ?? 0),
            'next_delay_seconds' => (int)($summary['next_delay_seconds'] ?? 0),
            'google_backoff_until' => source_backoff_until('google_books') > time() ? date('Y-m-d H:i:s', source_backoff_until('google_books')) : null,
        ],
        'jobs' => $jobs,
    ];
}

/**
 * Wartung: Bricht einen wartenden oder gesperrten Metadatenjob ab.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: book_event(), db(), json_response(), prepare().
 */
function cancel_metadata_job(int $jobId, int $householdId): void
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT j.id, j.status, b.id AS book_id
         FROM jobs j
         JOIN books b ON b.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.book_id')) AS UNSIGNED)
         JOIN household_book_settings hbs ON hbs.book_id = b.id AND hbs.household_id = ?
         WHERE j.id = ? AND j.job_type = 'fetch_metadata'"
    );
    $stmt->execute([$householdId, $jobId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'Metadatenauftrag nicht gefunden.'], 404);
    }
    if (!in_array((string)$row['status'], ['pending', 'retry', 'processing'], true)) {
        json_response(['ok' => false, 'error' => 'Dieser Auftrag ist bereits abgeschlossen.'], 409);
    }
    $pdo->prepare("UPDATE jobs SET status = 'failed', last_error = 'Manuell abgebrochen' WHERE id = ?")->execute([$jobId]);
    $pdo->prepare(
        "UPDATE books
         SET metadata_status = CASE WHEN metadata_status = 'ready' THEN 'ready' ELSE 'error' END,
             metadata_error = 'Metadatenabruf manuell abgebrochen'
         WHERE id = ?"
    )->execute([(int)$row['book_id']]);
    book_event((int)$row['book_id'], 'metadata_cancelled', 'Metadatenabruf manuell abgebrochen', null, null, ['job_id' => $jobId]);
}

/**
 * Wartung: Verarbeitet fällige Hintergrundjobs begrenzt pro Request.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: cleanup_finished_metadata_jobs(), db(), exec(), fetch_and_store_metadata(), prepare(), query().
 */
function process_jobs(int $limit = 3): array
{
    $pdo = db();
    cleanup_finished_metadata_jobs($pdo);
    $processed = 0;
    $failed = 0;

    $pdo->exec("UPDATE jobs SET status = 'retry', available_at = NOW(), last_error = 'Abgebrochener Auftrag wurde erneut freigegeben' WHERE status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");

    for ($i = 0; $i < $limit; $i++) {
        $pdo->beginTransaction();
        $stmt = $pdo->query(
            "SELECT * FROM jobs
             WHERE status IN ('pending', 'retry')
               AND available_at <= NOW()
               AND attempts < " . (int)MAX_JOB_ATTEMPTS . "
             ORDER BY (attempts = 0) DESC, available_at ASC, id ASC
             LIMIT 1
             FOR UPDATE"
        );
        $job = $stmt->fetch();
        if (!$job) {
            $pdo->commit();
            break;
        }

        $pdo->prepare("UPDATE jobs SET status = 'processing', locked_at = NOW(), attempts = attempts + 1 WHERE id = ?")
            ->execute([(int)$job['id']]);
        $pdo->commit();

        try {
            $payload = json_decode((string)$job['payload'], true, 32, JSON_THROW_ON_ERROR);
            if ($job['job_type'] === 'fetch_metadata') {
                fetch_and_store_metadata((int)$payload['book_id'], (string)$payload['isbn']);
            } else {
                throw new RuntimeException('Unbekannter Auftragstyp: ' . $job['job_type']);
            }

            $pdo->prepare("UPDATE jobs SET status = 'done', last_error = NULL WHERE id = ?")->execute([(int)$job['id']]);
            $processed++;
        } catch (Throwable $e) {
            $attempts = (int)$job['attempts'] + 1;
            $status = $attempts >= MAX_JOB_ATTEMPTS ? 'failed' : 'retry';
            if ($e instanceof MetadataRetryLaterException) {
                $delaySeconds = $e->retryAfterSeconds;
            } else {
                // Folgeversuche bewusst deutlich später planen: neue Erstabrufe sollen
                // nicht hinter Wiederholungen von bereits erfolglosen Büchern hängen bleiben.
                $delaySeconds = match (min($attempts, 3)) {
                    1 => 6 * 3600,
                    2 => 24 * 3600,
                    default => 72 * 3600,
                };
            }
            $availableAt = date('Y-m-d H:i:s', time() + $delaySeconds);
            $pdo->prepare(
                "UPDATE jobs SET status = ?, last_error = ?, available_at = ? WHERE id = ?"
            )->execute([$status, mb_substr($e->getMessage(), 0, 4000), $availableAt, (int)$job['id']]);

            $payload = json_decode((string)$job['payload'], true);
            if (!empty($payload['book_id'])) {
                $pdo->prepare(
                    "UPDATE books SET
                        metadata_status = CASE WHEN metadata_status = 'ready' THEN 'ready' ELSE ? END,
                        metadata_error = ?
                     WHERE id = ?"
                )->execute([$status === 'failed' ? 'error' : 'queued', mb_substr($e->getMessage(), 0, 4000), (int)$payload['book_id']]);
            }
            $failed++;
        }
    }

    $queue = $pdo->query(
        "SELECT COUNT(*) AS remaining,
                COALESCE(GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), MIN(available_at))), 0) AS next_delay_seconds
         FROM jobs
         WHERE status IN ('pending', 'retry')
           AND attempts < " . (int)MAX_JOB_ATTEMPTS
    )->fetch();

    return [
        'processed' => $processed,
        'failed' => $failed,
        'remaining' => (int)($queue['remaining'] ?? 0),
        'next_delay_seconds' => (int)($queue['next_delay_seconds'] ?? 0),
    ];
}

/**
 * Wartung: Ermittelt die Absenderadresse für System-E-Mails.
 * Aufgerufen von: http_get(), send_queued_emails().
 * Abhängigkeiten: valid_email().
 */
function mail_from_address(): string
{
    if (MAIL_FROM !== '' && valid_email(MAIL_FROM)) {
        return MAIL_FROM;
    }
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = preg_replace('/:\d+$/', '', $host) ?? 'localhost';
    $host = preg_replace('/^www\./', '', $host) ?? $host;
    $candidate = 'bibliothek@' . $host;
    return valid_email($candidate) ? $candidate : 'bibliothek@localhost';
}

/**
 * Wartung: Versendet fällige E-Mails aus der Warteschlange.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: db(), exec(), mail_from_address(), prepare().
 */
function send_queued_emails(int $limit = 20): array
{
    $pdo = db();
    $sent = 0;
    $failed = 0;

    $pdo->exec("UPDATE email_queue SET status = 'retry', last_error = 'Abgebrochener Versand wurde erneut freigegeben' WHERE status = 'sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");

    $stmt = $pdo->prepare(
        "SELECT * FROM email_queue
         WHERE status IN ('pending', 'retry') AND send_after <= NOW() AND attempts < 4
         ORDER BY id ASC LIMIT " . max(1, min(100, $limit))
    );
    $stmt->execute();
    $emails = $stmt->fetchAll();

    foreach ($emails as $email) {
        $pdo->prepare("UPDATE email_queue SET status = 'sending', attempts = attempts + 1 WHERE id = ?")
            ->execute([(int)$email['id']]);

        $fromName = '=?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode((string)$email['subject']) . '?=';
        $headers = [
            'From: ' . $fromName . ' <' . mail_from_address() . '>',
            'Reply-To: ' . mail_from_address(),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Hausbibliothek',
        ];

        $ok = @mail((string)$email['recipient'], $encodedSubject, (string)$email['body'], implode("\r\n", $headers));
        if ($ok) {
            $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?")
                ->execute([(int)$email['id']]);
            $sent++;
        } else {
            $attempts = (int)$email['attempts'] + 1;
            $status = $attempts >= 4 ? 'failed' : 'retry';
            $pdo->prepare("UPDATE email_queue SET status = ?, last_error = 'mail() meldete einen Fehler', send_after = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?")
                ->execute([$status, (int)$email['id']]);
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Wartung: Plant Erinnerungen für fällige Verleihungen.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: db(), prepare(), query(), queue_email().
 */
function enqueue_due_reminders(): int
{
    $pdo = db();
    $stmt = $pdo->query(
        "SELECT l.id, l.due_at, u.email, u.display_name,
                COALESCE(hbs.title_override,b.title) AS title, b.isbn13, c.inventory_no, h.name AS household_name
         FROM loans l
         JOIN users u ON u.id = l.user_id
         JOIN copies c ON c.id = l.copy_id
         JOIN books b ON b.id = c.book_id
         JOIN household_book_settings hbs ON hbs.household_id=c.household_id AND hbs.book_id=b.id
         JOIN households h ON h.id=c.household_id
         WHERE l.returned_at IS NULL
           AND l.due_at <= DATE_ADD(NOW(), INTERVAL " . (int)REMINDER_DAYS_BEFORE . " DAY)
           AND (l.last_reminder_at IS NULL OR l.last_reminder_at < DATE_SUB(NOW(), INTERVAL 23 HOUR))
           AND u.active = 1"
    );
    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        $due = new DateTimeImmutable((string)$row['due_at']);
        $today = new DateTimeImmutable('today');
        $overdue = $due < $today;
        $subject = $overdue ? 'Rückgabe überfällig: ' . $row['title'] : 'Rückgabe-Erinnerung: ' . $row['title'];
        $body = "Hallo " . $row['display_name'] . ",

";
        $body .= $overdue ? "die Rückgabe dieses Buches ist überfällig:

" : "bitte denke an die bevorstehende Rückgabe:

";
        $body .= $row['title'] . "
";
        if (!empty($row['isbn13'])) $body .= 'ISBN: ' . $row['isbn13'] . "
";
        $body .= 'Exemplar: ' . $row['inventory_no'] . "
";
        $body .= 'Fällig am: ' . $due->format('d.m.Y') . "
";
        $body .= 'Haushalt: ' . $row['household_name'] . "

Viele Grüße
" . MAIL_FROM_NAME;
        queue_email((string)$row['email'], $subject, $body);
        $pdo->prepare("UPDATE loans SET last_reminder_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
        $count++;
    }
    return $count;
}

/**
 * Wartung: Plant Rückgabeerinnerungen für Büchereibücher.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: db(), prepare(), query(), queue_email().
 */
function enqueue_library_reminders(): array
{
    $pdo = db();
    $owners = $pdo->query(
        "SELECT h.id AS household_id,h.name AS household_name,u.id,u.email,u.display_name
         FROM households h JOIN users u ON u.id=h.owner_user_id
         WHERE h.active=1 AND u.active=1 ORDER BY h.id"
    )->fetchAll();
    $emailsQueued = 0;
    $booksIncluded = 0;
    foreach ($owners as $owner) {
        $stmt = $pdo->prepare(
            "SELECT c.id AS copy_id,c.library_due_at,c.library_name,c.inventory_no,
                    COALESCE(hbs.title_override,b.title) AS title,b.isbn13
             FROM copies c
             JOIN books b ON b.id=c.book_id
             JOIN household_book_settings hbs ON hbs.household_id=c.household_id AND hbs.book_id=b.id
             WHERE c.household_id=? AND c.ownership='library' AND c.deleted_at IS NULL
               AND c.library_returned_at IS NULL AND c.library_due_at IS NOT NULL
               AND DATE(c.library_due_at) <= DATE_ADD(CURDATE(), INTERVAL " . (int)REMINDER_DAYS_BEFORE . " DAY)
               AND NOT EXISTS (
                    SELECT 1 FROM library_reminder_log lr
                    WHERE lr.copy_id=c.id AND lr.user_id=? AND lr.reminder_kind='due_soon'
               )
             ORDER BY c.library_due_at, title"
        );
        $stmt->execute([(int)$owner['household_id'], (int)$owner['id']]);
        $rows = $stmt->fetchAll();
        if (!$rows) continue;
        $body = "Hallo " . $owner['display_name'] . ",

";
        $body .= "für den Haushalt „" . $owner['household_name'] . "“ müssen folgende Büchereibücher in spätestens " . (int)REMINDER_DAYS_BEFORE . " Tagen zurückgegeben werden:

";
        foreach ($rows as $row) {
            $due = new DateTimeImmutable((string)$row['library_due_at']);
            $body .= "• " . $row['title'] . "
";
            if (!empty($row['isbn13'])) $body .= "  ISBN: " . $row['isbn13'] . "
";
            $body .= "  Rückgabe: " . $due->format('d.m.Y') . "
";
            if (!empty($row['library_name'])) $body .= "  Bücherei: " . $row['library_name'] . "
";
            $body .= "
";
        }
        $body .= "Viele Grüße
" . MAIL_FROM_NAME;
        queue_email((string)$owner['email'], 'Büchereibücher bald zurückgeben', $body);
        $log = $pdo->prepare("INSERT IGNORE INTO library_reminder_log (copy_id,user_id,reminder_kind,reminder_date) VALUES (?,?,'due_soon',CURDATE())");
        foreach ($rows as $row) $log->execute([(int)$row['copy_id'], (int)$owner['id']]);
        $emailsQueued++;
        $booksIncluded += count($rows);
    }
    return ['emails_queued'=>$emailsQueued,'books_included'=>$booksIncluded];
}

/**
 * Wartung: Ermittelt die Basis-URL der aktuellen Installation.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function base_url(): string
{
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
    return $scheme . '://' . $host . $path;
}

/**
 * Wartung: Schließt die Session frühzeitig, damit lange Jobs nicht blockieren.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function close_session_lock(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}


/**
 * Wartung: Typisiert und ergänzt Buchdaten aus SQL-Abfragen.
 * Aufgerufen von: get_book().
 * Abhängigkeiten: keine internen Hilfsfunktionen.
 */
function book_row_cast(array $row): array
{
    foreach ([
        'id', 'page_count', 'seen_count', 'copies_total', 'copies_all', 'copies_available',
        'copies_loaned', 'copies_reserved', 'copies_deleted', 'library_active', 'library_total',
        'library_returned', 'selected_cover_id', 'household_id', 'file_count'
    ] as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) {
            $row[$key] = (int)$row[$key];
        }
    }
    $row['is_deleted'] = !empty($row['deleted_at']);
    $row['library_history_only'] = ($row['copies_all'] ?? 0) > 0
        && ($row['library_total'] ?? 0) === ($row['copies_all'] ?? 0)
        && ($row['library_returned'] ?? 0) === ($row['copies_all'] ?? 0);
    $row['cover'] = $row['cover_path'] ?: $row['cover_url'];
    $sources = json_decode((string)($row['metadata_field_sources'] ?? ''), true);
    $row['metadata_field_sources'] = is_array($sources) ? $sources : [];
    $row['visibility'] = in_array((string)($row['visibility'] ?? ''), ['internal', 'visible', 'lendable'], true)
        ? (string)$row['visibility'] : 'lendable';
    $row['can_manage'] = !empty($row['can_manage']);
    $row['is_lendable'] = $row['visibility'] === 'lendable';
    return $row;
}

/**
 * Wartung: Lädt ein Buch mit Exemplaren, Metadaten und Historie.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: book_row_cast(), current_user(), db(), prepare().
 */
function get_book(int $id, ?int $householdId = null): ?array
{
    $user = current_user();
    if (!$user) {
        return null;
    }
    $householdId ??= (int)$user['active_household_id'];
    $canManage = (int)$user['active_household_id'] === $householdId && !empty($user['can_manage_household']);

    $stmt = db()->prepare(
        "SELECT
            b.id, b.isbn13, b.isbn10,
            COALESCE(hbs.title_override, b.title) AS title,
            COALESCE(hbs.subtitle_override, b.subtitle) AS subtitle,
            COALESCE(hbs.authors_override, b.authors) AS authors,
            COALESCE(hbs.publisher_override, b.publisher) AS publisher,
            COALESCE(hbs.published_date_override, b.published_date) AS published_date,
            COALESCE(hbs.description_override, b.description) AS description,
            COALESCE(hbs.page_count_override, b.page_count) AS page_count,
            COALESCE(hbs.categories_override, b.categories) AS categories,
            COALESCE(hbs.language_override, b.language) AS language,
            COALESCE(sc.local_path, b.cover_path) AS cover_path,
            b.cover_url, b.metadata_source, b.metadata_status, b.metadata_error, b.metadata_field_sources,
            COALESCE((SELECT MAX(ic.checked_at) FROM inventory_checks ic WHERE ic.household_id=hbs.household_id AND ic.book_id=b.id), MAX(c.last_seen_at), MIN(c.created_at)) AS last_seen_at,
            COALESCE((SELECT COUNT(*) FROM inventory_checks ic2 WHERE ic2.household_id=hbs.household_id AND ic2.book_id=b.id), SUM(COALESCE(c.seen_count,0)), 0) AS seen_count,
            b.created_by, b.created_at, b.updated_at,
            hbs.archived_at AS deleted_at, hbs.archived_by AS deleted_by,
            COALESCE(hbs.selected_cover_id, b.selected_cover_id) AS selected_cover_id,
            COALESCE(hbs.visibility, 'lendable') AS visibility,
            hbs.adopted_metadata_id,
            ? AS household_id,
            ? AS can_manage,
            COUNT(CASE WHEN c.deleted_at IS NULL THEN c.id END) AS copies_total,
            COUNT(c.id) AS copies_all,
            COALESCE(SUM(c.deleted_at IS NULL AND c.status = 'available'), 0) AS copies_available,
            COALESCE(SUM(c.deleted_at IS NULL AND c.status = 'loaned'), 0) AS copies_loaned,
            COALESCE(SUM(c.deleted_at IS NULL AND c.status = 'reserved'), 0) AS copies_reserved,
            COALESCE(SUM(c.deleted_at IS NOT NULL), 0) AS copies_deleted,
            COALESCE(SUM(c.deleted_at IS NULL AND c.ownership = 'library'), 0) AS library_active,
            COALESCE(SUM(c.ownership = 'library'), 0) AS library_total,
            COALESCE(SUM(c.ownership = 'library' AND c.library_returned_at IS NOT NULL), 0) AS library_returned,
            (SELECT COUNT(*) FROM book_files bf
             WHERE bf.household_id = hbs.household_id AND bf.book_id = b.id AND bf.deleted_at IS NULL
               AND (? = 1 OR bf.share_allowed = 1)) AS file_count,
            MIN(CASE WHEN c.deleted_at IS NULL AND c.ownership = 'library' THEN c.library_due_at END) AS nearest_library_due_at
         FROM books b
         JOIN household_book_settings hbs ON hbs.book_id = b.id AND hbs.household_id = ?
         LEFT JOIN book_covers sc ON sc.id = COALESCE(hbs.selected_cover_id, b.selected_cover_id) AND sc.book_id = b.id
         LEFT JOIN copies c ON c.book_id = b.id AND c.household_id = ?
         WHERE b.id = ?
           AND (? = 1 OR (hbs.visibility IN ('visible','lendable') AND EXISTS (
                SELECT 1 FROM copies cx WHERE cx.household_id = ? AND cx.book_id = b.id AND cx.deleted_at IS NULL
           )))
         GROUP BY b.id, hbs.id, sc.id"
    );
    $stmt->execute([$householdId, $canManage ? 1 : 0, $canManage ? 1 : 0, $householdId, $householdId, $id, $canManage ? 1 : 0, $householdId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row = book_row_cast($row);
    $fieldMap = [
        'title' => 'title_override', 'subtitle' => 'subtitle_override', 'authors' => 'authors_override',
        'publisher' => 'publisher_override', 'published_date' => 'published_date_override',
        'description' => 'description_override', 'page_count' => 'page_count_override',
        'categories' => 'categories_override', 'language' => 'language_override',
    ];
    $settingsStmt = db()->prepare("SELECT * FROM household_book_settings WHERE household_id = ? AND book_id = ?");
    $settingsStmt->execute([$householdId, $id]);
    $settings = $settingsStmt->fetch() ?: [];
    foreach ($fieldMap as $field => $override) {
        if (array_key_exists($override, $settings) && $settings[$override] !== null) {
            $row['metadata_field_sources'][$field] = 'Eigene Eingabe';
        }
    }
    return $row;
}

/**
 * Wartung: Findet die aktive Vormerkung eines Benutzers zu einem Buch.
 * Aufgerufen von: Globaler Ablauf/API/Events.
 * Abhängigkeiten: current_household_id(), db(), prepare().
 */
function active_reservation_for_book(int $bookId, ?int $householdId = null): ?array
{
    $householdId ??= current_household_id();
    $stmt = db()->prepare(
        "SELECT r.*, u.display_name, u.email
         FROM reservations r
         JOIN users u ON u.id = r.user_id
         WHERE r.household_id = ? AND r.book_id = ? AND r.status = 'active'
         ORDER BY r.created_at ASC, r.id ASC LIMIT 1"
    );
    $stmt->execute([$householdId, $bookId]);
    return $stmt->fetch() ?: null;
}


// ======================== DIREKTAUSGABEN: DATEIEN, BARCODE UND DRUCKANSICHT ========================
if (isset($_GET['book_file'])) {
    try {
        db();
        $fileId = max(0, (int)($_GET['book_file'] ?? 0));
        $stmt = db()->prepare(
            "SELECT bf.*, hbs.visibility, hbs.archived_at,
                    (SELECT COUNT(*) FROM copies cx
                     WHERE cx.household_id = bf.household_id AND cx.book_id = bf.book_id AND cx.deleted_at IS NULL) AS active_copies
             FROM book_files bf
             JOIN household_book_settings hbs ON hbs.household_id = bf.household_id AND hbs.book_id = bf.book_id
             WHERE bf.id = ? AND bf.deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        if (!$file) {
            http_response_code(404);
            exit('Datei nicht gefunden.');
        }

        $allowed = false;
        $publicToken = clean_text($_GET['share_file'] ?? '', 500);
        if ($publicToken !== '') {
            $share = resolve_public_share($publicToken, false);
            $allowed = $share
                && (int)$share['household_id'] === (int)$file['household_id']
                && in_array((string)$file['visibility'], ['visible', 'lendable'], true)
                && empty($file['archived_at'])
                && (int)$file['active_copies'] > 0
                && !empty($file['share_allowed']);
        } else {
            $downloadUser = current_user();
            if ($downloadUser) {
                foreach (user_households((int)$downloadUser['id']) as $household) {
                    if ((int)$household['id'] !== (int)$file['household_id']) continue;
                    $allowed = !empty($household['can_manage']) || (
                        !empty($file['share_allowed'])
                        && in_array((string)$file['visibility'], ['visible', 'lendable'], true)
                        && empty($file['archived_at'])
                        && (int)$file['active_copies'] > 0
                    );
                    break;
                }
            }
        }
        if (!$allowed) {
            http_response_code(403);
            exit('Keine Berechtigung.');
        }

        $root = realpath(triamo_data_dir() . DIRECTORY_SEPARATOR . 'files');
        $absolute = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$file['local_path']));
        if (!$root || !$absolute || !str_starts_with($absolute, $root . DIRECTORY_SEPARATOR) || !is_file($absolute)) {
            http_response_code(404);
            exit('Die gespeicherte Datei ist nicht vorhanden.');
        }

        close_session_lock();
        $mime = trim((string)($file['mime_type'] ?? '')) ?: 'application/octet-stream';
        $inlineExtensions = ['pdf', 'txt', 'jpg', 'jpeg', 'png', 'webp'];
        $disposition = in_array(strtolower((string)$file['file_extension']), $inlineExtensions, true) ? 'inline' : 'attachment';
        $originalName = str_replace(["\r", "\n", '"'], '', (string)$file['original_name']);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($absolute));
        header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($originalName) . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        readfile($absolute);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Die Datei konnte nicht ausgegeben werden.');
    }
}

if (isset($_GET['barcode'])) {
    try {
        db();
        $barcodeUser = current_user();
        if (!$barcodeUser || empty($barcodeUser['can_manage_household'])) {
            http_response_code(403);
            exit;
        }
        $requestedCode = clean_text($_GET['barcode'] ?? '', 40);
        $location = get_location_by_code($requestedCode, true);
        if (!$location) {
            http_response_code(404);
            exit;
        }
        $code = (string)$location['code'];
        $showText = !isset($_GET['label']) || (string)$_GET['label'] !== '0';
        $cached = cached_code39_svg($code, 80, $showText);
        $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: private, no-cache, max-age=0');
        header('ETag: ' . $cached['etag']);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)$cached['mtime']) . ' GMT');
        header('X-Barcode-Code: ' . $cached['code']);
        if ($ifNoneMatch !== '' && hash_equals($cached['etag'], $ifNoneMatch)) {
            http_response_code(304);
            exit;
        }
        readfile($cached['path']);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        exit;
    }
}

if (isset($_GET['print_locations'])) {
    try {
        db();
        $printUser = current_user();
        if (!$printUser || empty($printUser['can_manage_household'])) {
            http_response_code(403);
            echo 'Keine Berechtigung.';
            exit;
        }

        $ids = [];
        foreach (explode(',', (string)($_GET['ids'] ?? '')) as $candidate) {
            $id = (int)trim($candidate);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_slice(array_values($ids), 0, 500);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("SELECT * FROM locations WHERE household_id = ? AND active = 1 AND id IN ($placeholders) ORDER BY is_loose DESC, building, room, shelf, compartment_no, id");
            $stmt->execute([(int)$printUser['active_household_id'], ...$ids]);
            $printLocations = $stmt->fetchAll();
        } else {
            $stmt = db()->prepare("SELECT * FROM locations WHERE household_id = ? AND active = 1 ORDER BY is_loose DESC, building, room, shelf, compartment_no, id");
            $stmt->execute([(int)$printUser['active_household_id']]);
            $printLocations = $stmt->fetchAll();
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Standort-Barcodes – ' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . '</title>';
        echo <<<'HTML'
<style>
@page{size:A4;margin:8mm}*{box-sizing:border-box}body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;color:#111;background:#fff;--barcode-height:22mm;--columns:2}.toolbar{position:sticky;top:0;z-index:5;display:flex;align-items:end;flex-wrap:wrap;gap:10px;padding:10px;margin-bottom:12px;background:#f3f7f6;border:1px solid #cddbd8;border-radius:10px}.toolbar label{display:grid;gap:4px;font-size:12px;font-weight:700}.toolbar select,.toolbar input,.toolbar button{font:inherit;padding:8px 10px}.toolbar .hint{font-size:12px;color:#536966;flex:1 1 260px}.labels{display:grid;grid-template-columns:repeat(var(--columns),minmax(0,1fr));gap:5mm}.label{position:relative;border:1px dashed #777;padding:4mm;min-height:calc(var(--barcode-height) + 18mm);break-inside:avoid;background:#fff}.remove-label{position:absolute;top:2mm;right:2mm;border:0;border-radius:50%;width:7mm;height:7mm;line-height:1;background:#b5231c;color:#fff;font-weight:800;cursor:pointer;z-index:2}.barcode-wrap{min-width:0;display:flex;align-items:center;justify-content:center}.barcode-wrap img{display:block;width:auto;max-width:100%;height:var(--barcode-height);object-fit:fill}.caption{min-width:0}.path{font-weight:800;font-size:13px;line-height:1.25}.code{font:11px/1.25 ui-monospace,SFMono-Regular,Consolas,monospace;color:#4d5d5a;white-space:nowrap}body[data-layout="below"] .label{display:grid;grid-template-rows:auto auto;gap:2mm;align-content:center;text-align:center}body[data-layout="below"] .caption{display:grid;gap:1mm}body[data-layout="right"] .label{display:grid;grid-template-columns:minmax(0,2fr) minmax(32mm,1fr);gap:4mm;align-items:center}body[data-layout="right"] .caption{display:grid;gap:2mm;text-align:left}body[data-layout="inline"] .label{display:grid;grid-template-rows:auto auto;gap:1.5mm;align-content:center}body[data-layout="inline"] .caption{display:flex;align-items:baseline;justify-content:center;gap:3mm;white-space:nowrap}.empty{padding:30px;text-align:center;color:#667}@media(max-width:700px){.labels{grid-template-columns:1fr!important}.toolbar{position:static}body[data-layout="right"] .label{grid-template-columns:1fr}.remove-label{display:block}}@media print{body{background:#fff}.toolbar,.remove-label{display:none!important}.labels{gap:4mm}.label{border:.25mm solid #bbb}}
</style></head><body data-layout="below">
HTML;
        echo '<div class="toolbar">';
        echo '<button id="printBtn" type="button">Drucken</button><button type="button" onclick="window.close()">Schließen</button>';
        echo '<label>Layout<select id="layoutMode"><option value="below">Text unter dem Barcode</option><option value="right">Text rechts vom Barcode</option><option value="inline">Standort und Code in einer Zeile</option></select></label>';
        echo '<label>Barcode-Höhe <span id="heightValue">22 mm</span><input id="barcodeHeight" type="range" min="10" max="42" step="1" value="22"></label>';
        echo '<label>Spalten<select id="columns"><option value="1">1</option><option value="2" selected>2</option><option value="3">3</option></select></label>';
        echo '<div class="hint">Einzelne Etiketten kannst du mit × aus dieser Druckansicht entfernen. Die Höhe verändert nur den Barcode, nicht den Standorttext.</div></div>';
        echo '<main id="labels" class="labels">';
        foreach ($printLocations as $location) {
            $location = cast_location_row($location);
            $path = location_path($location);
            $code = (string)$location['code'];
            echo '<section class="label" data-label>';
            echo '<button class="remove-label" type="button" aria-label="Etikett entfernen">×</button>';
            echo '<div class="barcode-wrap"><img src="?barcode=' . rawurlencode($code) . '&amp;label=0" alt="Barcode ' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"></div>';
            echo '<div class="caption"><span class="path">' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '</span><span class="code">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span></div>';
            echo '</section>';
        }
        if (!$printLocations) {
            echo '<div class="empty">Keine Barcodes ausgewählt.</div>';
        }
        echo <<<'HTML'
</main><script>
(()=>{const body=document.body;const labels=document.getElementById('labels');const layout=document.getElementById('layoutMode');const height=document.getElementById('barcodeHeight');const heightValue=document.getElementById('heightValue');const columns=document.getElementById('columns');const apply=()=>{body.dataset.layout=layout.value;body.style.setProperty('--barcode-height',height.value+'mm');body.style.setProperty('--columns',columns.value);heightValue.textContent=height.value+' mm'};layout.addEventListener('change',apply);height.addEventListener('input',apply);columns.addEventListener('change',apply);labels.addEventListener('click',event=>{const remove=event.target.closest('.remove-label');if(remove)remove.closest('[data-label]')?.remove()});document.getElementById('printBtn').addEventListener('click',()=>window.print());apply()})();
</script></body></html>
HTML;
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Die Barcodeliste konnte nicht erstellt werden.';
        exit;
    }
}


// ======================== API-ROUTER ========================
$api = isset($_GET['api']) ? (string)$_GET['api'] : null;

if ($api !== null) {
    try {
        switch ($api) {
            case 'locations': {
                $locationUser = require_household_manager();
                $householdId = (int)$locationUser['active_household_id'];
                $includeInactive = !empty($_GET['include_inactive']) && !empty($locationUser['can_manage_household']);
                $sql = "SELECT l.*,
                            (SELECT COUNT(*) FROM copies c WHERE c.location_id = l.id AND c.deleted_at IS NULL) AS current_books,
                            (SELECT COUNT(*) FROM copies c WHERE c.home_location_id = l.id AND c.deleted_at IS NULL) AS home_books
                        FROM locations l WHERE l.household_id = " . (int)$householdId;
                if (!$includeInactive) {
                    $sql .= ' AND l.active = 1';
                }
                $sql .= ' ORDER BY l.is_loose DESC, l.building, l.room, l.shelf, l.compartment_no, l.id';
                $rows = db()->query($sql)->fetchAll();
                $groups = [];
                foreach ($rows as &$row) {
                    $row = cast_location_row($row);
                    $row['current_books'] = (int)$row['current_books'];
                    $row['home_books'] = (int)$row['home_books'];
                    $row['barcode_url'] = base_url() . '?barcode=' . rawurlencode((string)$row['code']);

                    $key = $row['is_loose'] ? 'LOOSE' : (string)($row['group_code'] ?: $row['code']);
                    if (!isset($groups[$key])) {
                        $groups[$key] = [
                            'group_code' => $key,
                            'building' => $row['building'],
                            'room' => $row['room'],
                            'shelf' => $row['shelf'],
                            'notes' => $row['notes'],
                            'is_loose' => $row['is_loose'],
                            'active' => false,
                            'path' => $row['group_path'],
                            'compartment_count' => (int)($row['group_size'] ?: 0),
                            'total_compartments' => 0,
                            'current_books' => 0,
                            'home_books' => 0,
                            'locations' => [],
                        ];
                    }
                    $groups[$key]['locations'][] = $row;
                    $groups[$key]['active'] = $groups[$key]['active'] || $row['active'];
                    $groups[$key]['total_compartments']++;
                    $groups[$key]['compartment_count'] = max($groups[$key]['compartment_count'], (int)($row['group_size'] ?: 0));
                    $groups[$key]['current_books'] += $row['current_books'];
                    $groups[$key]['home_books'] += $row['home_books'];
                }
                unset($row);
                json_response([
                    'ok' => true,
                    'locations' => $rows,
                    'groups' => array_values($groups),
                    'print_url' => base_url() . '?print_locations=1',
                ]);
            }

            case 'location_save': {
                require_method('POST');
                verify_csrf();
                $admin = require_household_manager();
                $householdId = (int)$admin['active_household_id'];
                $data = json_input();
                $originalGroupCode = normalize_location_group_code_input((string)($data['original_group_code'] ?? ''));
                $requestedGroupCode = normalize_location_group_code_input((string)($data['group_code'] ?? ''));
                if ($originalGroupCode === '' && !empty($data['id'])) {
                    $existingById = get_location_by_id((int)$data['id'], true, $householdId);
                    $originalGroupCode = strtoupper((string)($existingById['group_code'] ?? ''));
                }
                $groupCode = $requestedGroupCode;
                $lookupGroupCode = $originalGroupCode !== '' ? $originalGroupCode : $groupCode;
                $building = nullable_text($data['building'] ?? null, 160);
                $room = nullable_text($data['room'] ?? null, 160);
                $shelf = nullable_text($data['shelf'] ?? null, 160);
                $notes = nullable_text($data['notes'] ?? null, 4000);
                $active = !isset($data['active']) || !empty($data['active']);
                $compartmentCount = max(1, min(50, (int)($data['compartment_count'] ?? 1)));
                if (!$building) {
                    json_response(['ok' => false, 'error' => 'Gebäude beziehungsweise Hauptstandort ist erforderlich.'], 422);
                }
                if ($lookupGroupCode === 'LOOSE' || $groupCode === 'LOOSE') {
                    json_response(['ok' => false, 'error' => 'Der Sonderstandort „lose“ kann nicht bearbeitet werden.'], 409);
                }
                if (trim((string)($data['group_code'] ?? '')) !== '' && $groupCode === '') {
                    json_response(['ok' => false, 'error' => 'Die Standort-ID muss fünf Ziffern enthalten oder als TRIAMO-12345-1 eingegeben werden.'], 422);
                }
                if (trim((string)($data['original_group_code'] ?? '')) !== '' && $lookupGroupCode === '') {
                    json_response(['ok' => false, 'error' => 'Die bisherige Standort-ID ist ungültig.'], 422);
                }

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $isNew = $lookupGroupCode === '';
                    if ($isNew) {
                        if ($groupCode === '') {
                            $groupCode = new_location_group_code($pdo);
                        } else {
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE household_id = ? AND group_code = ?");
                            $stmt->execute([$householdId, $groupCode]);
                            if ((int)$stmt->fetchColumn() > 0) {
                                throw new RuntimeException('Diese fünfstellige Standort-ID wird in diesem Haushalt bereits verwendet.');
                            }
                        }
                        $existingRows = [];
                    } else {
                        $stmt = $pdo->prepare("SELECT * FROM locations WHERE household_id = ? AND group_code = ? AND is_loose = 0 ORDER BY compartment_no, id FOR UPDATE");
                        $stmt->execute([$householdId, $lookupGroupCode]);
                        $existingRows = $stmt->fetchAll();
                        if (!$existingRows) {
                            throw new RuntimeException('Standortgruppe nicht gefunden.');
                        }
                        if ($groupCode === '') {
                            $groupCode = $lookupGroupCode;
                        }
                        if ($groupCode !== $lookupGroupCode) {
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE household_id = ? AND group_code = ?");
                            $stmt->execute([$householdId, $groupCode]);
                            if ((int)$stmt->fetchColumn() > 0) {
                                throw new RuntimeException('Diese fünfstellige Standort-ID wird in diesem Haushalt bereits verwendet.');
                            }
                        }
                    }

                    // Beim Verkleinern werden überzählige Fächer nur deaktiviert. Ihre IDs,
                    // Barcodes und Buchzuordnungen bleiben erhalten und können später wieder aktiviert werden.
                    // Dadurch verweist kein vorhandenes Buch versehentlich auf ein anderes Fach.

                    $byNumber = [];
                    foreach ($existingRows as $row) {
                        $byNumber[(int)$row['compartment_no']] = $row;
                    }

                    for ($number = 1; $number <= $compartmentCount; $number++) {
                        $code = location_barcode_code($groupCode, $number);
                        if (isset($byNumber[$number])) {
                            $row = $byNumber[$number];
                            if ((string)$row['code'] !== $code) {
                                $pdo->prepare("INSERT IGNORE INTO location_code_aliases (household_id, alias_code, location_id) VALUES (?, ?, ?)")
                                    ->execute([$householdId, (string)$row['code'], (int)$row['id']]);
                            }
                            $pdo->prepare(
                                "UPDATE locations SET code = ?, building = ?, room = ?, shelf = ?, compartment = ?, compartment_no = ?, group_size = ?, notes = ?, active = ? WHERE id = ?"
                            )->execute([$code, $building, $room, $shelf, (string)$number, $number, $compartmentCount, $notes, $active ? 1 : 0, (int)$row['id']]);
                        } else {
                            $pdo->prepare(
                                "INSERT INTO locations (household_id, code, group_code, building, room, shelf, compartment, compartment_no, group_size, notes, active)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                            )->execute([$householdId, $code, $groupCode, $building, $room, $shelf, (string)$number, $number, $compartmentCount, $notes, $active ? 1 : 0]);
                        }
                    }
                    $pdo->prepare(
                        "UPDATE locations SET building = ?, room = ?, shelf = ?, group_size = ?, notes = ?, active = 0 WHERE household_id = ? AND group_code = ? AND compartment_no > ?"
                    )->execute([$building, $room, $shelf, $compartmentCount, $notes, $householdId, $groupCode, $compartmentCount]);

                    $warningStmt = $pdo->prepare(
                        "SELECT l.compartment_no,
                            (SELECT COUNT(*) FROM copies c WHERE c.deleted_at IS NULL AND (c.location_id = l.id OR c.home_location_id = l.id)) AS book_refs
                         FROM locations l
                         WHERE l.household_id = ? AND l.group_code = ? AND l.compartment_no > ? AND l.active = 0
                           AND (SELECT COUNT(*) FROM copies c2 WHERE c2.deleted_at IS NULL AND (c2.location_id = l.id OR c2.home_location_id = l.id)) > 0
                         ORDER BY l.compartment_no"
                    );
                    $warningStmt->execute([$householdId, $groupCode, $compartmentCount]);
                    $retiredWithBooks = $warningStmt->fetchAll();

                    $pdo->commit();
                    audit((int)$admin['id'], $isNew ? 'location_group_created' : 'location_group_updated', 'location_group', null, [
                        'group_code' => $groupCode,
                        'compartment_count' => $compartmentCount,
                        'retired_compartments_with_books' => array_map(static fn(array $row): int => (int)$row['compartment_no'], $retiredWithBooks),
                    ]);
                    $warning = '';
                    if ($retiredWithBooks) {
                        $numbers = implode(', ', array_map(static fn(array $row): string => (string)(int)$row['compartment_no'], $retiredWithBooks));
                        $warning = 'Die Fächer ' . $numbers . ' wurden deaktiviert, werden aber noch von Büchern verwendet. Die Zuordnungen bleiben erhalten und sind als „Fach nicht mehr vorhanden“ markiert.';
                    }
                    json_response(['ok' => true, 'group_code' => $groupCode, 'warning' => $warning]);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($e instanceof RuntimeException) {
                        json_response(['ok' => false, 'error' => $e->getMessage()], 409);
                    }
                    throw $e;
                }
            }

            case 'location_resolve': {
                require_household_manager();
                $code = normalize_location_code(clean_text($_GET['code'] ?? '', 40));
                $location = get_location_by_code($code, true);
                if (!$location) {
                    json_response(['ok' => false, 'error' => 'Standort-Barcode ist unbekannt.'], 404);
                }
                if (!$location['active']) {
                    json_response([
                        'ok' => false,
                        'error' => 'Dieses Fach ist nicht mehr aktiv. Die bisherigen Buchzuordnungen bleiben erhalten; für neue Scans bitte einen aktiven Standort wählen.',
                        'location' => $location,
                    ], 409);
                }
                json_response(['ok' => true, 'location' => $location]);
            }

            case 'bootstrap': {
                $pdo = db();
                $user = current_user();
                $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                json_response([
                    'ok' => true,
                    'app_name' => APP_NAME,
                    'needs_setup' => $userCount === 0,
                    'allow_registration' => ALLOW_SELF_REGISTRATION,
                    'user' => $user,
                    'csrf' => csrf_token(),
                    'defaults' => [
                        'loan_days' => DEFAULT_LOAN_DAYS,
                        'reminder_days' => REMINDER_DAYS_BEFORE,
                        'public_share_days' => DEFAULT_PUBLIC_SHARE_DAYS,
                    ],
                    'cron_url' => $user && $user['role'] === 'admin'
                        ? base_url() . '?api=cron&token=' . rawurlencode(CRON_TOKEN)
                        : null,
                ]);
            }

            case 'setup': {
                require_method('POST');
                verify_csrf();
                if (users_exist()) {
                    json_response(['ok' => false, 'error' => 'Die Ersteinrichtung wurde bereits abgeschlossen.'], 409);
                }
                $data = json_input();
                $name = clean_text($data['display_name'] ?? '', 120);
                $email = mb_strtolower(clean_text($data['email'] ?? '', 191));
                $password = (string)($data['password'] ?? '');
                $householdName = clean_text($data['household_name'] ?? ($name !== '' ? $name . 's Haushalt' : 'Mein Haushalt'), 160);
                $privacyNoticeAcknowledged = (string)($data['privacy_notice_acknowledged'] ?? '') === '1';

                if ($name === '' || !valid_email($email) || mb_strlen($password) < 10) {
                    json_response(['ok' => false, 'error' => 'Name, gültige E-Mail-Adresse und ein Passwort mit mindestens 10 Zeichen sind erforderlich.'], 422);
                }
                if (!$privacyNoticeAcknowledged) {
                    json_response(['ok' => false, 'error' => 'Bitte bestätige, dass du die Datenschutzerklärung zur Kenntnis genommen hast.'], 422);
                }

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        "INSERT INTO users (email, password_hash, display_name, role, active)
                         VALUES (?, ?, ?, 'admin', 1)"
                    );
                    $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
                    $id = (int)$pdo->lastInsertId();
                    $householdId = create_household_for_user($pdo, $id, $householdName);
                    $pdo->commit();
                    $_SESSION['user_id'] = $id;
                    $_SESSION['household_id'] = $householdId;
                    $_SESSION['session_version'] = 1;
                    session_regenerate_id(true);
                    audit($id, 'setup_completed', 'user', $id, [
                        'household_id' => $householdId,
                        'privacy_notice_acknowledged' => true,
                        'privacy_notice_version' => PRIVACY_NOTICE_VERSION,
                    ]);
                    queue_email_verification_link(['id' => $id, 'email' => $email, 'display_name' => $name], $id);
                    json_response(['ok' => true, 'message' => 'Administratorkonto und Haushalt angelegt.']);
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }

            case 'register': {
                require_method('POST');
                verify_csrf();
                if (!ALLOW_SELF_REGISTRATION) {
                    json_response(['ok' => false, 'error' => 'Die Selbstregistrierung ist deaktiviert.'], 403);
                }
                $data = json_input();
                $name = clean_text($data['display_name'] ?? '', 120);
                $email = mb_strtolower(clean_text($data['email'] ?? '', 191));
                $password = (string)($data['password'] ?? '');
                $householdName = clean_text($data['household_name'] ?? '', 160);
                $privacyNoticeAcknowledged = (string)($data['privacy_notice_acknowledged'] ?? '') === '1';
                if ($householdName === '' && $name !== '') {
                    $householdName = $name . 's Haushalt';
                }
                if ($name === '' || !valid_email($email) || mb_strlen($password) < 10 || $householdName === '') {
                    json_response(['ok' => false, 'error' => 'Name, Haushaltsname, gültige E-Mail-Adresse und ein Passwort mit mindestens 10 Zeichen sind erforderlich.'], 422);
                }
                if (!$privacyNoticeAcknowledged) {
                    json_response(['ok' => false, 'error' => 'Bitte bestätige, dass du die Datenschutzerklärung zur Kenntnis genommen hast.'], 422);
                }

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $check->execute([$email]);
                    if ($check->fetchColumn()) {
                        throw new RuntimeException('Diese E-Mail-Adresse ist bereits registriert.');
                    }
                    $stmt = $pdo->prepare(
                        "INSERT INTO users (email, password_hash, display_name, role, active)
                         VALUES (?, ?, ?, 'member', 1)"
                    );
                    $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
                    $id = (int)$pdo->lastInsertId();
                    $householdId = create_household_for_user($pdo, $id, $householdName);
                    $pdo->commit();
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $id;
                    $_SESSION['household_id'] = $householdId;
                    $_SESSION['session_version'] = 1;
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                    audit($id, 'self_registered', 'user', $id, [
                        'household_id' => $householdId,
                        'privacy_notice_acknowledged' => true,
                        'privacy_notice_version' => PRIVACY_NOTICE_VERSION,
                    ]);
                    queue_email_verification_link(['id' => $id, 'email' => $email, 'display_name' => $name], $id);
                    json_response(['ok' => true, 'csrf' => $_SESSION['csrf']]);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ($e instanceof RuntimeException) {
                        json_response(['ok' => false, 'error' => $e->getMessage()], 409);
                    }
                    throw $e;
                }
            }

            case 'login': {
                require_method('POST');
                verify_csrf();
                if (!users_exist()) {
                    json_response(['ok' => false, 'error' => 'Bitte zuerst die Ersteinrichtung durchführen.'], 409);
                }

                $data = json_input();
                $email = mb_strtolower(clean_text($data['email'] ?? '', 191));
                $password = (string)($data['password'] ?? '');

                $stmt = db()->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if (!$user || !(int)$user['active'] || !password_verify($password, (string)$user['password_hash'])) {
                    usleep(250000);
                    json_response(['ok' => false, 'error' => 'E-Mail-Adresse oder Passwort ist falsch.'], 401);
                }

                if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
                    db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                        ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
                }

                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['session_version'] = max(1, (int)($user['session_version'] ?? 1));
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$user['id']]);
                audit((int)$user['id'], 'login', 'user', (int)$user['id']);
                json_response(['ok' => true, 'csrf' => $_SESSION['csrf']]);
            }

            case 'password_reset_request': {
                require_method('POST');
                verify_csrf();
                $data = json_input();
                $email = mb_strtolower(clean_text($data['email'] ?? '', 191));
                if (valid_email($email)) {
                    $stmt = db()->prepare("SELECT id, email, display_name FROM users WHERE email = ? AND active = 1 AND anonymized_at IS NULL LIMIT 1");
                    $stmt->execute([$email]);
                    $target = $stmt->fetch();
                    if ($target) {
                        queue_password_reset_link($target, null);
                        audit((int)$target['id'], 'password_reset_requested', 'user', (int)$target['id']);
                    }
                }
                json_response(['ok' => true, 'message' => 'Falls ein aktives Konto zu dieser Adresse besteht, wurde ein Einmallink versendet.']);
            }

            case 'password_reset_complete': {
                require_method('POST');
                verify_csrf();
                $data = json_input();
                $token = strtolower(trim((string)($data['token'] ?? '')));
                $password = (string)($data['password'] ?? '');
                $passwordConfirm = (string)($data['password_confirm'] ?? '');
                if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
                    json_response(['ok' => false, 'error' => 'Der Einmallink ist ungültig.'], 422);
                }
                if (mb_strlen($password) < 10 || $password !== $passwordConfirm) {
                    json_response(['ok' => false, 'error' => 'Die Passwörter müssen übereinstimmen und mindestens 10 Zeichen lang sein.'], 422);
                }
                $hash = hash('sha256', $token);
                $pdo = db();
                $stmt = $pdo->prepare(
                    "SELECT t.id AS token_id, u.id, u.email, u.display_name
                     FROM password_reset_tokens t JOIN users u ON u.id = t.user_id
                     WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()
                       AND u.active = 1 AND u.anonymized_at IS NULL LIMIT 1"
                );
                $stmt->execute([$hash]);
                $target = $stmt->fetch();
                if (!$target) {
                    json_response(['ok' => false, 'error' => 'Der Einmallink ist abgelaufen oder wurde bereits verwendet.'], 410);
                }
                $pdo->beginTransaction();
                try {
                    $pdo->prepare(
                        "UPDATE users SET password_hash = ?, session_version = session_version + 1,
                         email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?"
                    )->execute([password_hash($password, PASSWORD_DEFAULT), (int)$target['id']]);
                    $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?")
                        ->execute([(int)$target['token_id']]);
                    $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                        ->execute([(int)$target['id']]);
                    $pdo->commit();
                    queue_account_notice((string)$target['email'], 'Passwort geändert', 'Das Passwort deines TRIAMO-Kontos wurde über einen Einmallink geändert. Alle bestehenden Sitzungen wurden beendet.');
                    audit((int)$target['id'], 'password_reset_completed', 'user', (int)$target['id']);
                    unset($_SESSION['user_id'], $_SESSION['household_id'], $_SESSION['session_version']);
                    json_response(['ok' => true, 'message' => 'Das Passwort wurde geändert. Du kannst dich jetzt anmelden.']);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }

            case 'email_verification_request': {
                require_method('POST');
                verify_csrf();
                $user = require_login();
                if (!empty($user['email_verified_at'])) {
                    json_response(['ok' => true, 'message' => 'Die E-Mail-Adresse ist bereits bestätigt.']);
                }
                $url = queue_email_verification_link($user, (int)$user['id']);
                audit((int)$user['id'], 'email_verification_requested', 'user', (int)$user['id']);
                json_response(['ok' => true, 'message' => 'Ein Bestätigungslink wurde versendet.']);
            }

            case 'email_verify': {
                require_method('POST');
                verify_csrf();
                $data = json_input();
                $token = strtolower(trim((string)($data['token'] ?? '')));
                if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
                    json_response(['ok' => false, 'error' => 'Der Bestätigungslink ist ungültig.'], 422);
                }
                $hash = hash('sha256', $token);
                $pdo = db();
                $stmt = $pdo->prepare(
                    "SELECT t.id AS token_id, u.id FROM email_verification_tokens t
                     JOIN users u ON u.id = t.user_id
                     WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()
                       AND u.active = 1 AND u.anonymized_at IS NULL LIMIT 1"
                );
                $stmt->execute([$hash]);
                $target = $stmt->fetch();
                if (!$target) {
                    json_response(['ok' => false, 'error' => 'Der Bestätigungslink ist abgelaufen oder wurde bereits verwendet.'], 410);
                }
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ?")
                        ->execute([(int)$target['id']]);
                    $pdo->prepare("UPDATE email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                        ->execute([(int)$target['id']]);
                    $pdo->commit();
                    audit((int)$target['id'], 'email_verified', 'user', (int)$target['id']);
                    json_response(['ok' => true, 'message' => 'Die E-Mail-Adresse wurde bestätigt.']);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }

            case 'household_switch': {
                require_method('POST');
                verify_csrf();
                $user = require_login();
                $data = json_input();
                $householdId = (int)($data['household_id'] ?? 0);
                $allowed = null;
                foreach (user_households((int)$user['id']) as $household) {
                    if ((int)$household['id'] === $householdId) {
                        $allowed = $household;
                        break;
                    }
                }
                if (!$allowed) {
                    json_response(['ok' => false, 'error' => 'Für diesen Haushalt besteht keine Freigabe.'], 403);
                }
                $_SESSION['household_id'] = $householdId;
                audit((int)$user['id'], 'household_switched', 'household', $householdId);
                json_response(['ok' => true, 'household' => $allowed]);
            }

            case 'logout': {
                require_method('POST');
                verify_csrf();
                $user = current_user();
                if ($user) {
                    audit((int)$user['id'], 'logout', 'user', (int)$user['id']);
                }
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
                }
                session_destroy();
                json_response(['ok' => true]);
            }

            case 'dashboard': {
                $user = require_household_access();
                $pdo = db();
                $householdId = (int)$user['active_household_id'];
                $canManage = !empty($user['can_manage_household']);
                $visibilitySql = $canManage ? '' : " AND hbs.visibility IN ('visible','lendable')";

                $stmt = $pdo->prepare(
                    "SELECT
                        COUNT(DISTINCT CASE WHEN hbs.archived_at IS NULL AND c.deleted_at IS NULL THEN b.id END) AS titles,
                        COUNT(DISTINCT CASE WHEN hbs.archived_at IS NOT NULL OR c.deleted_at IS NOT NULL THEN b.id END) AS archived,
                        COUNT(CASE WHEN c.deleted_at IS NULL THEN c.id END) AS copies,
                        COALESCE(SUM(c.deleted_at IS NULL AND c.status = 'available'), 0) AS available,
                        COALESCE(SUM(c.deleted_at IS NULL AND c.status = 'loaned'), 0) AS loaned,
                        COALESCE(SUM(c.deleted_at IS NULL AND c.ownership = 'library'), 0) AS library
                     FROM household_book_settings hbs
                     JOIN books b ON b.id = hbs.book_id
                     LEFT JOIN copies c ON c.book_id = b.id AND c.household_id = hbs.household_id
                     WHERE hbs.household_id = ? $visibilitySql"
                );
                $stmt->execute([$householdId]);
                $summary = $stmt->fetch() ?: [];
                $resStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE household_id = ? AND status = 'active'");
                $resStmt->execute([$householdId]);
                $metaStmt = $pdo->prepare(
                    "SELECT COUNT(DISTINCT b.id) FROM books b JOIN household_book_settings hbs ON hbs.book_id = b.id
                     WHERE hbs.household_id = ? AND b.metadata_status IN ('queued','fetching')"
                );
                $metaStmt->execute([$householdId]);
                $stats = [
                    'titles' => (int)($summary['titles'] ?? 0),
                    'archived' => (int)($summary['archived'] ?? 0),
                    'copies' => (int)($summary['copies'] ?? 0),
                    'available' => (int)($summary['available'] ?? 0),
                    'loaned' => (int)($summary['loaned'] ?? 0),
                    'reserved' => (int)$resStmt->fetchColumn(),
                    'library' => (int)($summary['library'] ?? 0),
                    'metadata_pending' => (int)$metaStmt->fetchColumn(),
                ];

                $params = [$householdId];
                $userFilter = '';
                if (!$canManage) {
                    $userFilter = ' AND l.user_id = ?';
                    $params[] = (int)$user['id'];
                }
                $stmt = $pdo->prepare(
                    "SELECT l.id, l.due_at, l.loaned_at, u.display_name, b.id AS book_id,
                            COALESCE(hbs.title_override, b.title) AS title,
                            COALESCE(hbs.authors_override, b.authors) AS authors,
                            COALESCE(sc.local_path, b.cover_path) AS cover_path, b.cover_url, c.inventory_no,
                            CASE WHEN l.due_at < NOW() THEN 1 ELSE 0 END AS overdue
                     FROM loans l
                     JOIN users u ON u.id = l.user_id
                     JOIN copies c ON c.id = l.copy_id AND c.household_id = ?
                     JOIN books b ON b.id = c.book_id
                     JOIN household_book_settings hbs ON hbs.household_id = c.household_id AND hbs.book_id = b.id
                     LEFT JOIN book_covers sc ON sc.id = COALESCE(hbs.selected_cover_id, b.selected_cover_id)
                     WHERE l.returned_at IS NULL $userFilter
                     ORDER BY overdue DESC, l.due_at ASC LIMIT 12"
                );
                $stmt->execute($params);
                $loans = $stmt->fetchAll();
                foreach ($loans as &$loan) {
                    $loan['id'] = (int)$loan['id'];
                    $loan['book_id'] = (int)$loan['book_id'];
                    $loan['overdue'] = (bool)$loan['overdue'];
                    $loan['cover'] = $loan['cover_path'] ?: $loan['cover_url'];
                }
                json_response(['ok' => true, 'stats' => $stats, 'loans' => $loans, 'can_manage' => $canManage]);
            }

            case 'scan': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();

                try {
                    $isbn = normalize_isbn((string)($data['isbn'] ?? ''));
                } catch (InvalidArgumentException $e) {
                    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
                }

                try {
                    $selectedLocation = resolve_location_selection($data);
                } catch (InvalidArgumentException $e) {
                    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
                }
                $locationId = (int)$selectedLocation['id'];
                $homeLocationId = !empty($selectedLocation['is_loose']) ? null : $locationId;
                [$location, $shelf] = location_text_values($selectedLocation);
                $scanToken = nullable_text($data['scan_token'] ?? null, 64);
                $isLibrary = !empty($data['is_library']);
                $ownership = $isLibrary ? 'library' : 'owned';
                $libraryName = $isLibrary ? nullable_text($data['library_name'] ?? null, 255) : null;
                try {
                    $libraryDueAt = $isLibrary ? parse_future_date($data['library_due_at'] ?? null, 'Die Rückgabefrist') : null;
                } catch (InvalidArgumentException $e) {
                    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
                }
                if ($isLibrary && !$libraryDueAt) {
                    json_response(['ok' => false, 'error' => 'Bei einem Büchereibuch ist die Rückgabefrist erforderlich.'], 422);
                }

                $pdo = db();
                if ($scanToken) {
                    $stmt = $pdo->prepare(
                        "SELECT ic.id AS check_id, b.id AS book_id
                         FROM inventory_checks ic JOIN books b ON b.id = ic.book_id
                         WHERE ic.scan_token = ? AND ic.household_id = ?"
                    );
                    $stmt->execute([$scanToken, $householdId]);
                    if ($existing = $stmt->fetch()) {
                        json_response([
                            'ok' => true,
                            'duplicate_request' => true,
                            'message' => 'Dieser Scan wurde bereits verarbeitet.',
                            'book' => get_book((int)$existing['book_id']),
                        ]);
                    }
                }

                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("SELECT * FROM books WHERE isbn13 = ? FOR UPDATE");
                    $stmt->execute([$isbn['isbn13']]);
                    $book = $stmt->fetch();
                    $newTitle = false;
                    $restoredBook = false;
                    $copyCreated = false;
                    $copyRestored = false;

                    if (!$book) {
                        $stmt = $pdo->prepare(
                            "INSERT INTO books (isbn13, isbn10, title, metadata_status, created_by, last_seen_at, seen_count)
                             VALUES (?, ?, ?, 'queued', ?, NOW(), 1)"
                        );
                        $stmt->execute([$isbn['isbn13'], $isbn['isbn10'], 'ISBN ' . $isbn['isbn13'], (int)$user['id']]);
                        $bookId = (int)$pdo->lastInsertId();
                        $newTitle = true;
                        queue_job('fetch_metadata', ['book_id' => $bookId, 'isbn' => $isbn['isbn13']]);
                    } else {
                        $bookId = (int)$book['id'];
                        $pdo->prepare("UPDATE books SET isbn10 = COALESCE(isbn10, ?), last_seen_at = NOW(), seen_count = seen_count + 1 WHERE id = ?")
                            ->execute([$isbn['isbn10'], $bookId]);

                        $stmt = $pdo->prepare(
                            "SELECT COUNT(*) AS source_count, MAX(fetched_at) AS last_fetch
                             FROM book_metadata WHERE book_id = ? AND source_scope = 'external'"
                        );
                        $stmt->execute([$bookId]);
                        $externalState = $stmt->fetch() ?: ['source_count' => 0, 'last_fetch' => null];
                        $hasExternalSources = (int)$externalState['source_count'] > 0;
                        $lastFetchTs = !empty($externalState['last_fetch']) ? strtotime((string)$externalState['last_fetch']) : false;
                        $errorIsStale = (string)$book['metadata_status'] === 'error'
                            && (!$lastFetchTs || $lastFetchTs < time() - 30 * 86400);
                        if (!$hasExternalSources || $errorIsStale) {
                            $pdo->prepare("UPDATE books SET metadata_status = 'queued', metadata_error = NULL WHERE id = ?")->execute([$bookId]);
                            queue_job('fetch_metadata', ['book_id' => $bookId, 'isbn' => $isbn['isbn13']]);
                        }
                    }

                    $stmt = $pdo->prepare("SELECT * FROM household_book_settings WHERE household_id = ? AND book_id = ? FOR UPDATE");
                    $stmt->execute([$householdId, $bookId]);
                    $householdBook = $stmt->fetch();
                    if ($householdBook) {
                        if (!empty($householdBook['archived_at'])) {
                            $restoredBook = true;
                        }
                        $pdo->prepare("UPDATE household_book_settings SET archived_at = NULL, archived_by = NULL WHERE household_id = ? AND book_id = ?")
                            ->execute([$householdId, $bookId]);
                    } else {
                        $pdo->prepare("INSERT INTO household_book_settings (household_id, book_id, visibility) VALUES (?, ?, 'lendable')")
                            ->execute([$householdId, $bookId]);
                    }

                    $stmt = $pdo->prepare(
                        "SELECT * FROM copies
                         WHERE household_id = ? AND book_id = ? AND ownership = ? AND deleted_at IS NULL
                         ORDER BY id ASC FOR UPDATE"
                    );
                    $stmt->execute([$householdId, $bookId, $ownership]);
                    $activeCopies = $stmt->fetchAll();
                    $copyId = null;
                    $inventoryNo = null;

                    if (!$activeCopies) {
                        if (!$isLibrary) {
                            $stmt = $pdo->prepare(
                                "SELECT * FROM copies
                                 WHERE household_id = ? AND book_id = ? AND ownership = 'owned' AND deleted_at IS NOT NULL
                                 ORDER BY deleted_at DESC, id DESC LIMIT 1 FOR UPDATE"
                            );
                            $stmt->execute([$householdId, $bookId]);
                            $oldCopy = $stmt->fetch();
                            if ($oldCopy) {
                                $copyId = (int)$oldCopy['id'];
                                $inventoryNo = (string)$oldCopy['inventory_no'];
                                $pdo->prepare(
                                    "UPDATE copies SET deleted_at = NULL, deleted_by = NULL, status = 'available',
                                        location_id = ?, home_location_id = CASE WHEN ? = 1 THEN home_location_id ELSE ? END,
                                        location = ?, shelf = ?, last_seen_at = NOW(), seen_count = seen_count + 1
                                     WHERE id = ?"
                                )->execute([$locationId, !empty($selectedLocation['is_loose']) ? 1 : 0, $homeLocationId, $location, $shelf, $copyId]);
                                $copyRestored = true;
                            }
                        }
                        if (!$copyId) {
                            $inventoryNo = inventory_number($bookId);
                            $stmt = $pdo->prepare(
                                "INSERT INTO copies
                                    (household_id, book_id, inventory_no, location, shelf, location_id, home_location_id, status, ownership, library_name,
                                     library_due_at, last_seen_at, seen_count)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, 'available', ?, ?, ?, NOW(), 1)"
                            );
                            $stmt->execute([$householdId, $bookId, $inventoryNo, $location, $shelf, $locationId, $homeLocationId, $ownership, $libraryName, $libraryDueAt]);
                            $copyId = (int)$pdo->lastInsertId();
                            $copyCreated = true;
                        }
                    } elseif (count($activeCopies) === 1) {
                        $copyId = (int)$activeCopies[0]['id'];
                        $inventoryNo = (string)$activeCopies[0]['inventory_no'];
                        $stmt = $pdo->prepare(
                            "UPDATE copies SET last_seen_at = NOW(), seen_count = seen_count + 1,
                                location_id = ?, home_location_id = CASE WHEN ? = 1 THEN home_location_id ELSE ? END,
                                location = ?, shelf = ?,
                                library_name = CASE WHEN ownership = 'library' THEN COALESCE(?, library_name) ELSE library_name END,
                                library_due_at = CASE WHEN ownership = 'library' THEN COALESCE(?, library_due_at) ELSE library_due_at END
                             WHERE id = ?"
                        );
                        $stmt->execute([$locationId, !empty($selectedLocation['is_loose']) ? 1 : 0, $homeLocationId, $location, $shelf, $libraryName, $libraryDueAt, $copyId]);
                    }

                    $stmt = $pdo->prepare(
                        "INSERT INTO inventory_checks (household_id, book_id, user_id, isbn13, scan_token, location, shelf, location_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([$householdId, $bookId, (int)$user['id'], $isbn['isbn13'], $scanToken, $location, $shelf, $locationId]);
                    $checkId = (int)$pdo->lastInsertId();

                    $summary = $isLibrary ? 'Büchereibuch per ISBN-Scan bestätigt' : 'Bestand per ISBN-Scan bestätigt';
                    if ($copyCreated) {
                        $summary = $isLibrary ? 'Büchereibuch erfasst' : 'Exemplar per ISBN-Scan erfasst';
                    } elseif ($copyRestored || $restoredBook) {
                        $summary = 'Archivierter Bestand per ISBN-Scan wiederhergestellt';
                    }
                    book_event($bookId, 'inventory_scan', $summary, (int)$user['id'], $copyId, [
                        'isbn' => $isbn['isbn13'],
                        'ownership' => $ownership,
                        'location_id' => $locationId,
                        'location_code' => $selectedLocation['code'],
                        'location_path' => $selectedLocation['path'],
                        'location' => $location,
                        'shelf' => $shelf,
                        'library_name' => $libraryName,
                        'library_due_at' => $libraryDueAt,
                    ], 'inventory_check:' . $checkId, null, $householdId);

                    $pdo->commit();
                    audit((int)$user['id'], $copyCreated ? 'copy_scanned' : 'book_seen', 'book', $bookId, ['copy_id' => $copyId]);

                    $message = 'Bereits vorhanden – Bestand bestätigt.';
                    if ($newTitle) {
                        $message = $isLibrary ? 'Büchereibuch erfasst; Metadaten werden geladen.' : 'Buch erfasst; Metadaten werden geladen.';
                    } elseif ($copyCreated) {
                        $message = $isLibrary ? 'Neues Büchereiexemplar erfasst.' : 'Fehlendes Exemplar wurde angelegt.';
                    } elseif ($copyRestored || $restoredBook) {
                        $message = 'Archivierter Bestand wurde wiederhergestellt.';
                    }
                    json_response([
                        'ok' => true,
                        'message' => $message,
                        'new_title' => $newTitle,
                        'copy_created' => $copyCreated,
                        'copy_restored' => $copyRestored,
                        'copy' => $copyId ? ['id' => $copyId, 'inventory_no' => $inventoryNo] : null,
                        'book' => get_book($bookId),
                    ], ($newTitle || $copyCreated || $copyRestored) ? 201 : 200);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }

            case 'book_create': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();

                $title = clean_text($data['title'] ?? '', 500);
                if ($title === '') {
                    json_response(['ok' => false, 'error' => 'Der Titel ist erforderlich.'], 422);
                }

                $isbn13 = null;
                $isbn10 = null;
                if (trim((string)($data['isbn'] ?? '')) !== '') {
                    try {
                        $isbn = normalize_isbn((string)$data['isbn']);
                        $isbn13 = $isbn['isbn13'];
                        $isbn10 = $isbn['isbn10'];
                    } catch (InvalidArgumentException $e) {
                        json_response(['ok' => false, 'error' => $e->getMessage()], 422);
                    }
                }

                $isLibrary = !empty($data['is_library']);
                $ownership = $isLibrary ? 'library' : 'owned';
                try {
                    $libraryDueAt = $isLibrary ? parse_future_date($data['library_due_at'] ?? null, 'Die Rückgabefrist') : null;
                } catch (InvalidArgumentException $e) {
                    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
                }
                if ($isLibrary && !$libraryDueAt) {
                    json_response(['ok' => false, 'error' => 'Bei einem Büchereibuch ist die Rückgabefrist erforderlich.'], 422);
                }
                try {
                    $selectedLocation = resolve_location_selection($data);
                } catch (InvalidArgumentException $e) {
                    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
                }
                $locationId = (int)$selectedLocation['id'];
                $homeLocationId = !empty($selectedLocation['is_loose']) ? null : $locationId;
                [$location, $shelf] = location_text_values($selectedLocation);

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $bookId = 0;
                    $newGlobalBook = false;
                    if ($isbn13) {
                        $stmt = $pdo->prepare("SELECT id FROM books WHERE isbn13 = ? FOR UPDATE");
                        $stmt->execute([$isbn13]);
                        $bookId = (int)($stmt->fetchColumn() ?: 0);
                    }
                    if ($bookId < 1) {
                        $baseTitle = $isbn13 ? 'ISBN ' . $isbn13 : $title;
                        $stmt = $pdo->prepare(
                            "INSERT INTO books
                                (isbn13, isbn10, title, metadata_status, metadata_source, created_by, last_seen_at, seen_count)
                             VALUES (?, ?, ?, ?, 'Benutzereingabe', ?, NOW(), 1)"
                        );
                        $stmt->execute([$isbn13, $isbn10, $baseTitle, $isbn13 ? 'queued' : 'ready', (int)$user['id']]);
                        $bookId = (int)$pdo->lastInsertId();
                        $newGlobalBook = true;
                    } else {
                        $pdo->prepare("UPDATE books SET isbn10 = COALESCE(isbn10, ?), last_seen_at = NOW(), seen_count = seen_count + 1 WHERE id = ?")
                            ->execute([$isbn10, $bookId]);
                    }

                    save_household_book_settings($householdId, $bookId, $data);
                    store_manual_metadata($bookId, $isbn13, $data, $householdId);

                    $inventoryNo = inventory_number($bookId);
                    $stmt = $pdo->prepare(
                        "INSERT INTO copies
                            (household_id, book_id, inventory_no, location, shelf, location_id, home_location_id,
                             status, ownership, library_name, library_due_at, notes)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'available', ?, ?, ?, ?)"
                    );
                    $stmt->execute([
                        $householdId, $bookId, $inventoryNo, $location, $shelf, $locationId, $homeLocationId,
                        $ownership, $isLibrary ? nullable_text($data['library_name'] ?? null, 255) : null,
                        $libraryDueAt, nullable_text($data['copy_notes'] ?? null, 4000),
                    ]);
                    $copyId = (int)$pdo->lastInsertId();
                    book_event($bookId, $isLibrary ? 'library_copy_added' : 'copy_added',
                        $isLibrary ? 'Büchereibuch manuell erfasst' : 'Buch und Exemplar manuell erfasst',
                        (int)$user['id'], $copyId, [
                            'inventory_no' => $inventoryNo,
                            'location_id' => $locationId,
                            'location_path' => $selectedLocation['path'],
                            'library_due_at' => $libraryDueAt,
                        ], null, null, $householdId);
                    if ($isbn13) {
                        $sourceCount = $pdo->prepare("SELECT COUNT(*) FROM book_metadata WHERE book_id = ? AND source_scope = 'external'");
                        $sourceCount->execute([$bookId]);
                        if ($newGlobalBook || (int)$sourceCount->fetchColumn() === 0) {
                            $pdo->prepare("UPDATE books SET metadata_status = 'queued', metadata_error = NULL WHERE id = ?")->execute([$bookId]);
                            queue_job('fetch_metadata', ['book_id' => $bookId, 'isbn' => $isbn13]);
                        }
                    }
                    $pdo->commit();
                    audit((int)$user['id'], 'book_created', 'book', $bookId, ['copy_id' => $copyId, 'household_id' => $householdId, 'ownership' => $ownership]);
                    json_response(['ok' => true, 'book' => get_book($bookId, $householdId)], 201);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }

            case 'books': {
                $user = require_login();
                $q = clean_text($_GET['q'] ?? '', 200);
                $availability = clean_text($_GET['availability'] ?? 'all', 20);
                $kind = clean_text($_GET['kind'] ?? 'all', 20);
                $locationFilter = clean_text($_GET['location'] ?? '', 100);
                $searchAllHouseholds = !empty($_GET['all_households']) && $locationFilter === '';
                $limitRaw = (string)($_GET['limit'] ?? '200');
                $limit = strtolower($limitRaw) === 'all' ? 100000 : max(10, min(500, (int)$limitRaw));
                $offset = max(0, (int)($_GET['offset'] ?? 0));
                if ($limit >= 100000) {
                    $offset = 0;
                }

                $accessible = user_households((int)$user['id']);
                if (!$searchAllHouseholds) {
                    $accessible = array_values(array_filter($accessible, static fn(array $row): bool => (int)$row['id'] === (int)$user['active_household_id']));
                }
                if (!$accessible) {
                    json_response(['ok' => true, 'books' => [], 'offset' => $offset, 'limit' => $limitRaw, 'all_households' => $searchAllHouseholds, 'stats' => ['total' => 0, 'found' => 0, 'shown' => 0, 'households' => 0, 'all_households' => $searchAllHouseholds], 'pagination' => ['offset' => $offset, 'limit' => $limitRaw, 'found' => 0, 'has_more' => false]]);
                }
                $householdIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['id'], $accessible)));
                $managerIds = array_values(array_unique(array_map(
                    static fn(array $row): int => !empty($row['can_manage']) ? (int)$row['id'] : 0,
                    $accessible
                )));
                $managerIds = array_values(array_filter($managerIds));
                $householdSql = implode(',', $householdIds);
                $managerSql = $managerIds ? implode(',', $managerIds) : '0';
                $canManageExpr = "CASE WHEN hbs.household_id IN ($managerSql) THEN 1 ELSE 0 END";

                $where = ["hbs.household_id IN ($householdSql)"];
                $params = [];
                // Eigene Haushalte dürfen vollständig durchsucht werden. Bei fremden Haushalten
                // gelten Sichtbarkeit und ein tatsächlich vorhandenes aktives Exemplar.
                $where[] = "(hbs.household_id IN ($managerSql) OR (
                    hbs.visibility IN ('visible','lendable') AND hbs.archived_at IS NULL
                    AND EXISTS (SELECT 1 FROM copies cv WHERE cv.household_id = hbs.household_id AND cv.book_id = b.id AND cv.deleted_at IS NULL)
                ))";
                $baseWhere = $where;
                $baseParams = $params;
                if ($q !== '') {
                    $where[] = "(COALESCE(hbs.title_override,b.title) LIKE ? OR COALESCE(hbs.subtitle_override,b.subtitle) LIKE ?
                        OR COALESCE(hbs.authors_override,b.authors) LIKE ? OR COALESCE(hbs.publisher_override,b.publisher) LIKE ?
                        OR COALESCE(hbs.published_date_override,b.published_date) LIKE ? OR b.isbn13 LIKE ? OR b.isbn10 LIKE ?
                        OR COALESCE(hbs.categories_override,b.categories) LIKE ?)";
                    $needle = '%' . $q . '%';
                    for ($i = 0; $i < 8; $i++) $params[] = $needle;
                }
                if ($availability === 'available') {
                    $where[] = "EXISTS (SELECT 1 FROM copies ca WHERE ca.household_id = hbs.household_id AND ca.book_id = b.id AND ca.deleted_at IS NULL AND ca.status = 'available')";
                } elseif ($availability === 'loaned') {
                    $where[] = "EXISTS (SELECT 1 FROM copies cl WHERE cl.household_id = hbs.household_id AND cl.book_id = b.id AND cl.deleted_at IS NULL AND cl.status = 'loaned')";
                } elseif ($availability === 'reserved') {
                    $where[] = "EXISTS (SELECT 1 FROM reservations rr WHERE rr.household_id = hbs.household_id AND rr.book_id = b.id AND rr.status = 'active')";
                }
                if ($kind === 'owned') {
                    $where[] = "EXISTS (SELECT 1 FROM copies co WHERE co.household_id = hbs.household_id AND co.book_id = b.id AND co.deleted_at IS NULL AND co.ownership = 'owned')";
                } elseif ($kind === 'library') {
                    $where[] = "EXISTS (SELECT 1 FROM copies cb WHERE cb.household_id = hbs.household_id AND cb.book_id = b.id AND cb.ownership = 'library')";
                } elseif ($kind === 'archived') {
                    $where[] = 'hbs.archived_at IS NOT NULL';
                } elseif ($kind === 'active') {
                    $where[] = 'hbs.archived_at IS NULL';
                }

                if ($locationFilter !== '') {
                    $activeHouseholdId = (int)$user['active_household_id'];
                    if (!in_array($activeHouseholdId, $householdIds, true)) {
                        json_response(['ok' => false, 'error' => 'Der gewählte Haushalt ist nicht zugänglich.'], 403);
                    }
                    // Ein Standortfilter bezieht sich bewusst auf den aktuellen Aufenthaltsort
                    // eines Exemplars. Der Stammplatz bleibt als eigene Information sichtbar,
                    // führt aber nicht dazu, dass ausgelagerte Bücher im Fach erscheinen.
                    if (str_starts_with($locationFilter, 'group:')) {
                        $groupCode = normalize_location_group_code_input(substr($locationFilter, 6));
                        if ($groupCode === '') {
                            json_response(['ok' => false, 'error' => 'Die Standortgruppe ist ungültig.'], 422);
                        }
                        $where[] = "EXISTS (
                            SELECT 1 FROM copies cf
                            JOIN locations lf ON lf.id = cf.location_id
                            WHERE cf.household_id = hbs.household_id AND cf.book_id = b.id
                              AND cf.deleted_at IS NULL AND lf.household_id = ? AND lf.group_code = ?
                        )";
                        $params[] = $activeHouseholdId;
                        $params[] = $groupCode;
                    } else {
                        $location = null;
                        if (str_starts_with($locationFilter, 'id:')) {
                            $location = get_location_by_id((int)substr($locationFilter, 3), true, $activeHouseholdId);
                        } else {
                            $rawCode = str_starts_with($locationFilter, 'code:') ? substr($locationFilter, 5) : $locationFilter;
                            $location = get_location_by_code($rawCode, true, $activeHouseholdId);
                        }
                        if (!$location) {
                            json_response(['ok' => false, 'error' => 'Der Standortbarcode wurde nicht gefunden.'], 404);
                        }
                        $where[] = "EXISTS (
                            SELECT 1 FROM copies cf
                            WHERE cf.household_id = hbs.household_id AND cf.book_id = b.id
                              AND cf.deleted_at IS NULL AND cf.location_id = ?
                        )";
                        $params[] = (int)$location['id'];
                    }
                }

                $countFrom = " FROM household_book_settings hbs
                        JOIN households h ON h.id = hbs.household_id AND h.active = 1
                        JOIN books b ON b.id = hbs.book_id";
                $baseCountStmt = db()->prepare("SELECT COUNT(*)" . $countFrom . " WHERE " . implode(' AND ', $baseWhere));
                $baseCountStmt->execute($baseParams);
                $filteredCountStmt = db()->prepare("SELECT COUNT(*)" . $countFrom . " WHERE " . implode(' AND ', $where));
                $filteredCountStmt->execute($params);
                $stats = [
                    'total' => (int)$baseCountStmt->fetchColumn(),
                    'found' => (int)$filteredCountStmt->fetchColumn(),
                    'shown' => 0,
                    'households' => count($householdIds),
                    'all_households' => $searchAllHouseholds,
                ];

                $order = $searchAllHouseholds
                    ? 'h.name ASC, (hbs.archived_at IS NOT NULL) ASC, COALESCE(hbs.title_override,b.title) ASC'
                    : '(hbs.archived_at IS NOT NULL) ASC, COALESCE(hbs.title_override,b.title) ASC';
                $sql = "SELECT
                            b.id, b.isbn13, b.isbn10,
                            COALESCE(hbs.title_override,b.title) AS title,
                            COALESCE(hbs.subtitle_override,b.subtitle) AS subtitle,
                            COALESCE(hbs.authors_override,b.authors) AS authors,
                            COALESCE(hbs.publisher_override,b.publisher) AS publisher,
                            COALESCE(hbs.published_date_override,b.published_date) AS published_date,
                            COALESCE(hbs.description_override,b.description) AS description,
                            COALESCE(hbs.page_count_override,b.page_count) AS page_count,
                            COALESCE(hbs.categories_override,b.categories) AS categories,
                            COALESCE(hbs.language_override,b.language) AS language,
                            COALESCE(sc.local_path,b.cover_path) AS cover_path, b.cover_url,
                            b.metadata_source,b.metadata_status,b.metadata_error,b.metadata_field_sources,
                            COALESCE((SELECT MAX(ic.checked_at) FROM inventory_checks ic WHERE ic.household_id=hbs.household_id AND ic.book_id=b.id), MAX(c.last_seen_at), MIN(c.created_at)) AS last_seen_at,
                            COALESCE((SELECT COUNT(*) FROM inventory_checks ic2 WHERE ic2.household_id=hbs.household_id AND ic2.book_id=b.id), SUM(COALESCE(c.seen_count,0)), 0) AS seen_count,
                            b.created_by,b.created_at,b.updated_at,
                            hbs.archived_at AS deleted_at,hbs.archived_by AS deleted_by,
                            COALESCE(hbs.selected_cover_id,b.selected_cover_id) AS selected_cover_id,
                            hbs.visibility, $canManageExpr AS can_manage, hbs.household_id, h.name AS household_name,
                            COUNT(CASE WHEN c.deleted_at IS NULL THEN c.id END) AS copies_total,
                            COUNT(c.id) AS copies_all,
                            COALESCE(SUM(c.deleted_at IS NULL AND c.status = 'available'),0) AS copies_available,
                            COALESCE(SUM(c.deleted_at IS NULL AND c.status = 'loaned'),0) AS copies_loaned,
                            COALESCE(SUM(c.deleted_at IS NULL AND c.status = 'reserved'),0) AS copies_reserved,
                            COALESCE(SUM(c.deleted_at IS NOT NULL),0) AS copies_deleted,
                            COALESCE(SUM(c.deleted_at IS NULL AND c.ownership = 'library'),0) AS library_active,
                            COALESCE(SUM(c.ownership = 'library'),0) AS library_total,
                            COALESCE(SUM(c.ownership = 'library' AND c.library_returned_at IS NOT NULL),0) AS library_returned,
                            (SELECT COUNT(*) FROM book_files bf
                             WHERE bf.household_id = hbs.household_id AND bf.book_id = b.id AND bf.deleted_at IS NULL
                               AND ($canManageExpr = 1 OR bf.share_allowed = 1)) AS file_count,
                            MIN(CASE WHEN c.deleted_at IS NULL AND c.ownership = 'library' THEN c.library_due_at END) AS nearest_library_due_at
                        FROM household_book_settings hbs
                        JOIN households h ON h.id = hbs.household_id AND h.active = 1
                        JOIN books b ON b.id = hbs.book_id
                        LEFT JOIN book_covers sc ON sc.id = COALESCE(hbs.selected_cover_id,b.selected_cover_id) AND sc.book_id = b.id
                        LEFT JOIN copies c ON c.book_id = b.id AND c.household_id = hbs.household_id
                        WHERE " . implode(' AND ', $where) . "
                        GROUP BY b.id,hbs.id,sc.id,h.id
                        ORDER BY $order
                        LIMIT $limit OFFSET $offset";
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
                $books = array_map('book_row_cast', $stmt->fetchAll());
                $stats['shown'] = count($books);
                json_response([
                    'ok' => true, 'books' => $books, 'offset' => $offset, 'limit' => $limitRaw,
                    'all_households' => $searchAllHouseholds, 'household_count' => count($householdIds),
                    'stats' => $stats,
                    'pagination' => [
                        'offset' => $offset,
                        'limit' => $limitRaw,
                        'limit_numeric' => $limit,
                        'found' => $stats['found'],
                        'has_more' => ($offset + count($books)) < $stats['found'],
                    ],
                ]);
            }

            case 'book': {
                $user = require_household_access();
                $householdId = (int)$user['active_household_id'];
                $canManage = !empty($user['can_manage_household']);
                $id = (int)($_GET['id'] ?? 0);
                $book = get_book($id, $householdId);
                if (!$book) {
                    json_response(['ok' => false, 'error' => 'Buch nicht gefunden oder nicht freigegeben.'], 404);
                }

                $copyWhere = $canManage ? '' : ' AND c.deleted_at IS NULL';
                $stmt = db()->prepare(
                    "SELECT c.*,
                        ln.id AS active_loan_id, ln.user_id AS borrower_id, ln.due_at,
                        u.display_name AS borrower_name,
                        loc.code AS location_code, loc.building AS location_building, loc.room AS location_room,
                        loc.shelf AS location_shelf, loc.compartment AS location_compartment, loc.compartment_no AS location_compartment_no,
                        loc.is_loose AS location_is_loose, loc.active AS location_active,
                        home.code AS home_location_code, home.building AS home_location_building, home.room AS home_location_room,
                        home.shelf AS home_location_shelf, home.compartment AS home_location_compartment,
                        home.compartment_no AS home_location_compartment_no, home.active AS home_location_active
                     FROM copies c
                     LEFT JOIN loans ln ON ln.copy_id = c.id AND ln.returned_at IS NULL
                     LEFT JOIN users u ON u.id = ln.user_id
                     LEFT JOIN locations loc ON loc.id = c.location_id
                     LEFT JOIN locations home ON home.id = c.home_location_id
                     WHERE c.household_id = ? AND c.book_id = ? $copyWhere
                     ORDER BY (c.deleted_at IS NOT NULL) ASC, c.created_at ASC, c.id ASC"
                );
                $stmt->execute([$householdId, $id]);
                $copies = $stmt->fetchAll();
                foreach ($copies as &$copy) {
                    $copy['id'] = (int)$copy['id'];
                    $copy['book_id'] = (int)$copy['book_id'];
                    $copy['household_id'] = (int)$copy['household_id'];
                    $copy['seen_count'] = (int)$copy['seen_count'];
                    $copy['active_loan_id'] = $copy['active_loan_id'] !== null ? (int)$copy['active_loan_id'] : null;
                    $copy['borrower_id'] = $copy['borrower_id'] !== null ? (int)$copy['borrower_id'] : null;
                    $copy['is_deleted'] = !empty($copy['deleted_at']);
                    $copy['is_library'] = $copy['ownership'] === 'library';
                    $copy['location_id'] = $copy['location_id'] !== null ? (int)$copy['location_id'] : null;
                    $copy['home_location_id'] = $copy['home_location_id'] !== null ? (int)$copy['home_location_id'] : null;
                    if ($canManage) {
                        $copy['location_path'] = location_path([
                            'is_loose' => (bool)($copy['location_is_loose'] ?? false),
                            'building' => $copy['location_building'] ?? null,
                            'room' => $copy['location_room'] ?? null,
                            'shelf' => $copy['location_shelf'] ?? null,
                            'compartment' => $copy['location_compartment'] ?? null,
                            'compartment_no' => $copy['location_compartment_no'] ?? null,
                        ]);
                        $copy['location_is_loose'] = (bool)($copy['location_is_loose'] ?? false);
                        $copy['location_active'] = $copy['location_id'] !== null && (bool)($copy['location_active'] ?? false);
                        $copy['location_retired'] = $copy['location_id'] !== null && !$copy['location_active'] && !$copy['location_is_loose'];
                        $copy['home_location_path'] = $copy['home_location_id'] ? location_path([
                            'is_loose' => false,
                            'building' => $copy['home_location_building'] ?? null,
                            'room' => $copy['home_location_room'] ?? null,
                            'shelf' => $copy['home_location_shelf'] ?? null,
                            'compartment' => $copy['home_location_compartment'] ?? null,
                            'compartment_no' => $copy['home_location_compartment_no'] ?? null,
                        ]) : null;
                        $copy['home_location_active'] = $copy['home_location_id'] !== null && (bool)($copy['home_location_active'] ?? false);
                        $copy['home_location_retired'] = $copy['home_location_id'] !== null && !$copy['home_location_active'];
                    } else {
                        $copy['inventory_no'] = 'Freigegebenes Exemplar';
                        $copy['notes'] = null;
                        $copy['location_id'] = null;
                        $copy['home_location_id'] = null;
                        $copy['location_path'] = null;
                        $copy['home_location_path'] = null;
                        $copy['borrower_name'] = null;
                        $copy['borrower_id'] = null;
                    }
                }

                $reservationSql = $canManage
                    ? "SELECT r.*, u.display_name, u.email FROM reservations r JOIN users u ON u.id = r.user_id
                       WHERE r.household_id = ? AND r.book_id = ? AND r.status = 'active' ORDER BY r.created_at ASC"
                    : "SELECT r.*, u.display_name, NULL AS email FROM reservations r JOIN users u ON u.id = r.user_id
                       WHERE r.household_id = ? AND r.book_id = ? AND r.status = 'active' AND r.user_id = ? ORDER BY r.created_at ASC";
                $stmt = db()->prepare($reservationSql);
                $stmt->execute($canManage ? [$householdId, $id] : [$householdId, $id, (int)$user['id']]);
                $reservations = $stmt->fetchAll();

                $stmt = db()->prepare(
                    "SELECT id, household_id, source_key, source_name, source_scope, fetch_status, title, subtitle, authors, publisher,
                            published_date, description, page_count, categories, language, isbn10,
                            cover_url, external_url, error_message, http_status, fetched_at, updated_at,
                            CHAR_LENGTH(raw_payload) AS raw_size
                     FROM book_metadata WHERE book_id = ?
                     ORDER BY (source_scope = 'external') DESC,
                              FIELD(source_key, 'dnb', 'google_books', 'open_library'), fetched_at DESC, id ASC"
                );
                $stmt->execute([$id]);
                $metadataSources = $stmt->fetchAll();
                $communityNumber = 0;
                foreach ($metadataSources as &$source) {
                    $source['id'] = (int)$source['id'];
                    $source['household_id'] = $source['household_id'] !== null ? (int)$source['household_id'] : null;
                    $source['page_count'] = $source['page_count'] !== null ? (int)$source['page_count'] : null;
                    $source['http_status'] = $source['http_status'] !== null ? (int)$source['http_status'] : null;
                    $source['raw_size'] = (int)$source['raw_size'];
                    $source['is_community'] = $source['source_scope'] === 'community';
                    $source['is_own_community'] = $source['is_community'] && (int)$source['household_id'] === $householdId;
                    $source['can_adopt'] = $canManage && $source['is_community'] && !$source['is_own_community'];
                    if ($source['is_community']) {
                        $communityNumber++;
                        $source['source_name'] = $source['is_own_community']
                            ? ($canManage ? 'Eigene Eingabe' : 'Eingabe dieses Haushalts')
                            : 'Anonyme Benutzereingabe ' . $communityNumber;
                        $source['source_key'] = 'community';
                        unset($source['household_id']);
                    }
                }

                $stmt = db()->prepare(
                    "SELECT id, source_key, source_name, remote_url, local_path, mime_type, width, height,
                            file_size, fetch_status, error_message, fetched_at,
                            CASE WHEN id = ? THEN 1 ELSE 0 END AS is_selected
                     FROM book_covers WHERE book_id = ?
                     ORDER BY (fetch_status = 'success') DESC, (COALESCE(width,0) * COALESCE(height,0)) DESC, id ASC"
                );
                $stmt->execute([(int)($book['selected_cover_id'] ?? 0), $id]);
                $covers = $stmt->fetchAll();
                foreach ($covers as &$cover) {
                    $cover['id'] = (int)$cover['id'];
                    $cover['width'] = $cover['width'] !== null ? (int)$cover['width'] : null;
                    $cover['height'] = $cover['height'] !== null ? (int)$cover['height'] : null;
                    $cover['file_size'] = $cover['file_size'] !== null ? (int)$cover['file_size'] : null;
                    $cover['is_selected'] = (bool)$cover['is_selected'];
                    $cover['cover'] = $cover['local_path'] ?: $cover['remote_url'];
                }

                $history = [];
                if ($canManage) {
                    $stmt = db()->prepare(
                        "SELECT h.*, u.display_name
                         FROM book_history h LEFT JOIN users u ON u.id = h.user_id
                         WHERE h.household_id = ? AND h.book_id = ?
                         ORDER BY h.occurred_at DESC, h.id DESC LIMIT 300"
                    );
                    $stmt->execute([$householdId, $id]);
                    $history = $stmt->fetchAll();
                    foreach ($history as &$entry) {
                        $entry['id'] = (int)$entry['id'];
                        $entry['copy_id'] = $entry['copy_id'] !== null ? (int)$entry['copy_id'] : null;
                        $details = json_decode((string)($entry['details'] ?? ''), true);
                        $entry['details'] = is_array($details) ? $details : null;
                    }
                }

                $bookFiles = book_files_for_view($id, $householdId, $canManage);
                json_response([
                    'ok' => true, 'book' => $book, 'copies' => $copies, 'reservations' => $reservations,
                    'metadata_sources' => $metadataSources, 'covers' => $covers, 'history' => $history,
                    'files' => $bookFiles, 'can_manage' => $canManage,
                ]);
            }

            case 'book_update': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                $id = (int)($data['id'] ?? 0);
                $book = get_book($id, $householdId);
                if (!$book) {
                    json_response(['ok' => false, 'error' => 'Buch nicht gefunden.'], 404);
                }
                $title = clean_text($data['title'] ?? '', 500);
                if ($title === '') {
                    json_response(['ok' => false, 'error' => 'Der Titel ist erforderlich.'], 422);
                }
                $data['title'] = $title;
                save_household_book_settings($householdId, $id, $data);
                store_manual_metadata($id, $book['isbn13'] ?? null, $data, $householdId);
                book_event($id, 'book_updated', 'Buchdaten für diesen Haushalt bearbeitet', (int)$user['id'], null, [
                    'changed_fields' => array_keys($data),
                    'visibility' => clean_text($data['visibility'] ?? 'lendable', 20),
                ], null, null, $householdId);
                audit((int)$user['id'], 'book_updated', 'book', $id, ['household_id' => $householdId]);
                json_response(['ok' => true, 'book' => get_book($id, $householdId)]);
            }

            case 'metadata_raw': {
                $user = require_household_access();
                $householdId = (int)$user['active_household_id'];
                $sourceId = (int)($_GET['id'] ?? 0);
                $stmt = db()->prepare("SELECT id, book_id, source_key, source_name, source_scope, raw_payload, fetched_at FROM book_metadata WHERE id = ? LIMIT 1");
                $stmt->execute([$sourceId]);
                $source = $stmt->fetch();
                if (!$source || !get_book((int)$source['book_id'], $householdId)) {
                    json_response(['ok' => false, 'error' => 'Metadatenquelle nicht gefunden oder nicht freigegeben.'], 404);
                }
                if ($source['raw_payload'] === null || $source['raw_payload'] === '') {
                    json_response(['ok' => false, 'error' => 'Für diese Quelle ist keine Rohantwort gespeichert.'], 404);
                }
                json_response([
                    'ok' => true,
                    'source' => [
                        'id' => (int)$source['id'],
                        'source_key' => (string)$source['source_key'],
                        'source_name' => (string)$source['source_name'],
                        'fetched_at' => $source['fetched_at'],
                        'raw' => (string)$source['raw_payload'],
                    ],
                ]);
            }

            case 'community_metadata_adopt': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                $sourceId = (int)($data['source_id'] ?? 0);
                $stmt = db()->prepare(
                    "SELECT * FROM book_metadata WHERE id = ? AND source_scope = 'community' AND household_id <> ? LIMIT 1"
                );
                $stmt->execute([$sourceId, $householdId]);
                $source = $stmt->fetch();
                if (!$source) {
                    json_response(['ok' => false, 'error' => 'Diese Benutzereingabe ist nicht verfügbar.'], 404);
                }
                $bookId = (int)$source['book_id'];
                $book = get_book($bookId, $householdId);
                if (!$book) {
                    json_response(['ok' => false, 'error' => 'Das Buch befindet sich nicht in deinem Haushalt.'], 404);
                }
                $payload = [
                    'title' => $source['title'], 'subtitle' => $source['subtitle'], 'authors' => $source['authors'],
                    'publisher' => $source['publisher'], 'published_date' => $source['published_date'],
                    'description' => $source['description'], 'page_count' => $source['page_count'],
                    'categories' => $source['categories'], 'language' => $source['language'],
                    'visibility' => $book['visibility'],
                ];
                save_household_book_settings($householdId, $bookId, $payload, (string)$book['visibility']);
                store_manual_metadata($bookId, $book['isbn13'] ?? null, $payload, $householdId);
                db()->prepare("UPDATE household_book_settings SET adopted_metadata_id = ? WHERE household_id = ? AND book_id = ?")
                    ->execute([$sourceId, $householdId, $bookId]);
                book_event($bookId, 'community_metadata_adopted', 'Anonyme Benutzereingabe übernommen', (int)$user['id'], null, [
                    'metadata_source_id' => $sourceId,
                ], null, null, $householdId);
                json_response(['ok' => true, 'book' => get_book($bookId, $householdId)]);
            }

            case 'cover_url_add': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                $bookId = (int)($data['book_id'] ?? 0);
                $url = trim((string)($data['url'] ?? ''));
                if (!get_book($bookId, $householdId)) {
                    json_response(['ok' => false, 'error' => 'Buch nicht gefunden.'], 404);
                }
                $response = http_get_public_resource($url, MAX_COVER_UPLOAD_BYTES, 22, 4);
                if (empty($response['ok']) || $response['body'] === '') {
                    json_response(['ok' => false, 'error' => 'Das Bild konnte nicht geladen werden: ' . ($response['error'] ?: ('HTTP ' . (int)$response['status']))], 422);
                }
                $cover = store_manual_cover_bytes(
                    $bookId, $householdId, (int)$user['id'], (string)$response['body'],
                    (string)$response['content_type'], 'Eigene Cover-URL', (string)($response['final_url'] ?? $url)
                );
                audit((int)$user['id'], 'cover_url_added', 'book', $bookId, ['household_id' => $householdId, 'cover_id' => $cover['id']]);
                json_response(['ok' => true, 'cover' => $cover]);
            }

            case 'cover_upload': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $bookId = (int)($_POST['book_id'] ?? 0);
                if (!get_book($bookId, $householdId)) {
                    json_response(['ok' => false, 'error' => 'Buch nicht gefunden.'], 404);
                }
                $upload = $_FILES['cover_file'] ?? null;
                if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    json_response(['ok' => false, 'error' => 'Bitte eine Bilddatei auswählen oder aufnehmen.'], 422);
                }
                $size = (int)($upload['size'] ?? 0);
                if ($size < 1 || $size > MAX_COVER_UPLOAD_BYTES) {
                    json_response(['ok' => false, 'error' => 'Das Cover ist leer oder größer als 12 MB.'], 422);
                }
                $tmpName = (string)($upload['tmp_name'] ?? '');
                if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                    json_response(['ok' => false, 'error' => 'Die Upload-Datei ist ungültig.'], 422);
                }
                $body = (string)@file_get_contents($tmpName);
                $sourceName = !empty($_POST['camera']) ? 'Eigenes Foto' : 'Eigene Bilddatei';
                $cover = store_manual_cover_bytes(
                    $bookId, $householdId, (int)$user['id'], $body,
                    (string)($upload['type'] ?? ''), $sourceName, ''
                );
                audit((int)$user['id'], 'cover_uploaded', 'book', $bookId, ['household_id' => $householdId, 'cover_id' => $cover['id']]);
                json_response(['ok' => true, 'cover' => $cover]);
            }

            case 'book_file_upload': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $bookId = (int)($_POST['book_id'] ?? 0);
                if (!get_book($bookId, $householdId)) {
                    json_response(['ok' => false, 'error' => 'Buch nicht gefunden.'], 404);
                }
                $comment = nullable_text($_POST['comment'] ?? null, 10000);
                $shareAllowed = !empty($_POST['share_allowed']);
                $raw = $_FILES['book_files'] ?? null;
                if (!is_array($raw)) {
                    json_response(['ok' => false, 'error' => 'Bitte mindestens eine Datei auswählen.'], 422);
                }
                $uploads = [];
                if (is_array($raw['name'] ?? null)) {
                    foreach ($raw['name'] as $index => $name) {
                        $uploads[] = [
                            'name' => $name,
                            'type' => $raw['type'][$index] ?? '',
                            'tmp_name' => $raw['tmp_name'][$index] ?? '',
                            'error' => $raw['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                            'size' => $raw['size'][$index] ?? 0,
                        ];
                    }
                } else {
                    $uploads[] = $raw;
                }
                $stored = [];
                foreach ($uploads as $upload) {
                    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                    $stored[] = store_book_file_upload($bookId, $householdId, (int)$user['id'], $upload, $comment, $shareAllowed);
                }
                if (!$stored) {
                    json_response(['ok' => false, 'error' => 'Es wurde keine Datei hochgeladen.'], 422);
                }
                audit((int)$user['id'], 'book_files_uploaded', 'book', $bookId, ['household_id' => $householdId, 'count' => count($stored)]);
                json_response(['ok' => true, 'files' => $stored], 201);
            }

            case 'book_file_update': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                $fileId = (int)($data['id'] ?? 0);
                $stmt = db()->prepare("SELECT * FROM book_files WHERE id = ? AND household_id = ? AND deleted_at IS NULL LIMIT 1");
                $stmt->execute([$fileId, $householdId]);
                $file = $stmt->fetch();
                if (!$file || !get_book((int)$file['book_id'], $householdId)) {
                    json_response(['ok' => false, 'error' => 'Datei nicht gefunden.'], 404);
                }
                $comment = nullable_text($data['comment'] ?? null, 10000);
                $shareAllowed = !empty($data['share_allowed']);
                db()->prepare("UPDATE book_files SET comment = ?, share_allowed = ? WHERE id = ?")
                    ->execute([$comment, $shareAllowed ? 1 : 0, $fileId]);
                book_event((int)$file['book_id'], 'book_file_updated', 'Dateiangaben geändert', (int)$user['id'], null, [
                    'file_id' => $fileId, 'share_allowed' => $shareAllowed,
                ], null, null, $householdId);
                json_response(['ok' => true]);
            }

            case 'book_file_delete': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                $fileId = (int)($data['id'] ?? 0);
                $stmt = db()->prepare("SELECT * FROM book_files WHERE id = ? AND household_id = ? AND deleted_at IS NULL LIMIT 1");
                $stmt->execute([$fileId, $householdId]);
                $file = $stmt->fetch();
                if (!$file || !get_book((int)$file['book_id'], $householdId)) {
                    json_response(['ok' => false, 'error' => 'Datei nicht gefunden.'], 404);
                }
                db()->prepare("UPDATE book_files SET deleted_at = NOW(), deleted_by = ? WHERE id = ?")
                    ->execute([(int)$user['id'], $fileId]);
                $root = realpath(triamo_data_dir() . DIRECTORY_SEPARATOR . 'files');
                $absolute = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$file['local_path']));
                if ($root && $absolute && str_starts_with($absolute, $root . DIRECTORY_SEPARATOR) && is_file($absolute)) {
                    @unlink($absolute);
                }
                book_event((int)$file['book_id'], 'book_file_deleted', 'Datei vom Buch entfernt', (int)$user['id'], null, [
                    'file_id' => $fileId, 'original_name' => $file['original_name'],
                ], null, null, $householdId);
                json_response(['ok' => true]);
            }

            case 'cover_select': {
                require_method('POST');
                verify_csrf();
                $admin = require_household_manager();
                $householdId = (int)$admin['active_household_id'];
                $data = json_input();
                $bookId = (int)($data['book_id'] ?? 0);
                $coverId = (int)($data['cover_id'] ?? 0);
                if (!get_book($bookId, $householdId)) {
                    json_response(['ok' => false, 'error' => 'Buch nicht gefunden.'], 404);
                }
                if ($coverId > 0) {
                    $stmt = db()->prepare("SELECT id FROM book_covers WHERE id = ? AND book_id = ? AND fetch_status = 'success' AND local_path IS NOT NULL");
                    $stmt->execute([$coverId, $bookId]);
                    if (!$stmt->fetchColumn()) {
                        json_response(['ok' => false, 'error' => 'Diese Coverquelle ist nicht verfügbar.'], 404);
                    }
                    db()->prepare("UPDATE household_book_settings SET selected_cover_id = ? WHERE household_id = ? AND book_id = ?")
                        ->execute([$coverId, $householdId, $bookId]);
                    db()->prepare("UPDATE book_covers SET selected_at = CASE WHEN id = ? THEN NOW() ELSE selected_at END WHERE book_id = ?")
                        ->execute([$coverId, $bookId]);
                } else {
                    db()->prepare("UPDATE household_book_settings SET selected_cover_id = NULL WHERE household_id = ? AND book_id = ?")
                        ->execute([$householdId, $bookId]);
                }
                book_event($bookId, 'cover_selected', $coverId > 0 ? 'Coverquelle für den Haushalt ausgewählt' : 'Automatische Coverauswahl aktiviert',
                    (int)$admin['id'], null, ['cover_id' => $coverId ?: null], null, null, $householdId);
                json_response(['ok' => true, 'book' => get_book($bookId, $householdId)]);
            }

            case 'book_retry_metadata': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                $id = (int)($data['id'] ?? 0);
                $book = get_book($id, $householdId);
                if (!$book || empty($book['isbn13'])) {
                    json_response(['ok' => false, 'error' => 'Für dieses Buch ist keine ISBN-13 hinterlegt.'], 422);
                }

                db()->prepare("UPDATE books SET metadata_status = 'queued', metadata_error = NULL WHERE id = ?")->execute([$id]);
                db()->prepare("UPDATE jobs SET status = 'failed', last_error = 'Durch manuellen Neuabruf ersetzt' WHERE job_type = 'fetch_metadata' AND status IN ('pending','retry','processing') AND payload LIKE ?")
                    ->execute(['%"book_id":' . $id . '%']);
                db()->prepare("INSERT INTO jobs (job_type, payload, status, attempts, available_at) VALUES ('fetch_metadata', ?, 'pending', 0, NOW())")
                    ->execute([json_encode(['book_id' => $id, 'isbn' => $book['isbn13'], 'force' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                book_event($id, 'metadata_retry', 'Metadatenabruf erneut gestartet', (int)$user['id'], null, null, null, null, $householdId);
                audit((int)$user['id'], 'metadata_retry', 'book', $id);
                json_response(['ok' => true, 'message' => 'Alle Metadatenquellen wurden erneut eingeplant.']);
            }

            case 'book_delete': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                $id = (int)($data['id'] ?? 0);
                $book = get_book($id, $householdId);
                if (!$book) {
                    json_response(['ok' => false, 'error' => 'Buch nicht gefunden.'], 404);
                }
                $stmt = db()->prepare(
                    "SELECT COUNT(*) FROM loans l JOIN copies c ON c.id = l.copy_id
                     WHERE c.household_id = ? AND c.book_id = ? AND l.returned_at IS NULL"
                );
                $stmt->execute([$householdId, $id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    json_response(['ok' => false, 'error' => 'Ein Buch mit aktiver Verleihung kann nicht archiviert werden.'], 409);
                }
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE household_book_settings SET archived_at = NOW(), archived_by = ? WHERE household_id = ? AND book_id = ?")
                        ->execute([(int)$user['id'], $householdId, $id]);
                    $pdo->prepare(
                        "UPDATE copies SET deleted_at = COALESCE(deleted_at, NOW()), deleted_by = ?,
                            status = CASE WHEN status = 'library_returned' THEN status ELSE 'deleted' END
                         WHERE household_id = ? AND book_id = ? AND deleted_at IS NULL"
                    )->execute([(int)$user['id'], $householdId, $id]);
                    $pdo->prepare(
                        "UPDATE reservations SET status = 'cancelled', cancelled_at = NOW()
                         WHERE household_id = ? AND book_id = ? AND status = 'active'"
                    )->execute([$householdId, $id]);
                    book_event($id, 'book_archived', 'Buch mit seinen aktiven Exemplaren archiviert', (int)$user['id'], null, null, null, null, $householdId);
                    $pdo->commit();
                    audit((int)$user['id'], 'book_archived', 'book', $id, ['household_id' => $householdId]);
                    json_response(['ok' => true]);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }

            case 'book_restore': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                $id = (int)($data['id'] ?? 0);
                $book = get_book($id, $householdId);
                if (!$book) {
                    // Archivierte Titel sind für get_book weiterhin sichtbar, sofern ein historisches Exemplar existiert.
                    json_response(['ok' => false, 'error' => 'Buch nicht gefunden.'], 404);
                }
                if (!empty($book['library_history_only'])) {
                    json_response(['ok' => false, 'error' => 'Ein zurückgegebenes Büchereibuch bleibt im Archiv. Bei erneuter Ausleihe bitte die ISBN neu scannen.'], 409);
                }
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE household_book_settings SET archived_at = NULL, archived_by = NULL WHERE household_id = ? AND book_id = ?")
                        ->execute([$householdId, $id]);
                    $pdo->prepare(
                        "UPDATE copies SET deleted_at = NULL, deleted_by = NULL, status = 'available'
                         WHERE household_id = ? AND book_id = ? AND ownership = 'owned' AND status = 'deleted'"
                    )->execute([$householdId, $id]);
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM copies WHERE household_id = ? AND book_id = ? AND deleted_at IS NULL");
                    $stmt->execute([$householdId, $id]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $inventoryNo = inventory_number($id);
                        $loose = loose_location($householdId);
                        $pdo->prepare(
                            "INSERT INTO copies (household_id, book_id, inventory_no, location_id, status, ownership)
                             VALUES (?, ?, ?, ?, 'available', 'owned')"
                        )->execute([$householdId, $id, $inventoryNo, (int)$loose['id']]);
                    }
                    book_event($id, 'book_restored', 'Buch aus dem Archiv wiederhergestellt', (int)$user['id'], null, null, null, null, $householdId);
                    $pdo->commit();
                    json_response(['ok' => true, 'book' => get_book($id, $householdId)]);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }

            case 'copy_add': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                $bookId = (int)($data['book_id'] ?? 0);
                if (!get_book($bookId, $householdId)) {
                    json_response(['ok' => false, 'error' => 'Buch nicht gefunden.'], 404);
                }
                $isLibrary = !empty($data['is_library']);
                try {
                    $libraryDueAt = $isLibrary ? parse_future_date($data['library_due_at'] ?? null, 'Die Rückgabefrist') : null;
                } catch (InvalidArgumentException $e) {
                    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
                }
                if ($isLibrary && !$libraryDueAt) {
                    json_response(['ok' => false, 'error' => 'Bei einem Büchereibuch ist die Rückgabefrist erforderlich.'], 422);
                }
                try {
                    $selectedLocation = resolve_location_selection($data);
                } catch (InvalidArgumentException $e) {
                    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
                }
                $locationId = (int)$selectedLocation['id'];
                $homeLocationId = !empty($selectedLocation['is_loose']) ? null : $locationId;
                [$location, $shelf] = location_text_values($selectedLocation);

                $inventoryNo = inventory_number($bookId);
                $stmt = db()->prepare(
                    "INSERT INTO copies
                        (household_id, book_id, inventory_no, location, shelf, location_id, home_location_id, status, ownership, library_name, library_due_at, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'available', ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $householdId, $bookId, $inventoryNo,
                    $location,
                    $shelf,
                    $locationId,
                    $homeLocationId,
                    $isLibrary ? 'library' : 'owned',
                    $isLibrary ? nullable_text($data['library_name'] ?? null, 255) : null,
                    $libraryDueAt,
                    nullable_text($data['notes'] ?? null, 4000),
                ]);
                $copyId = (int)db()->lastInsertId();
                db()->prepare("UPDATE household_book_settings SET archived_at = NULL, archived_by = NULL WHERE household_id = ? AND book_id = ?")->execute([$householdId, $bookId]);
                book_event($bookId, $isLibrary ? 'library_copy_added' : 'copy_added', $isLibrary ? 'Büchereiexemplar ergänzt' : 'Exemplar ergänzt', (int)$user['id'], $copyId, [
                    'inventory_no' => $inventoryNo,
                    'location_id' => $locationId,
                    'location_path' => $selectedLocation['path'],
                    'library_due_at' => $libraryDueAt,
                ], null, null, $householdId);
                audit((int)$user['id'], 'copy_added', 'copy', $copyId, ['book_id' => $bookId]);
                json_response(['ok' => true, 'copy_id' => $copyId, 'inventory_no' => $inventoryNo], 201);
            }

            case 'copy_update': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                $id = (int)($data['id'] ?? 0);

                $stmt = db()->prepare("SELECT * FROM copies WHERE id = ? AND household_id = ?");
                $stmt->execute([$id, $householdId]);
                $copy = $stmt->fetch();
                if (!$copy) {
                    json_response(['ok' => false, 'error' => 'Exemplar nicht gefunden.'], 404);
                }
                $bookId = (int)$copy['book_id'];
                $activeLoan = db()->prepare("SELECT COUNT(*) FROM loans WHERE copy_id = ? AND returned_at IS NULL");
                $activeLoan->execute([$id]);
                $isLoaned = (int)$activeLoan->fetchColumn() > 0;

                $status = $isLoaned ? 'loaned' : clean_text($data['status'] ?? $copy['status'], 20);
                if (!$isLoaned && !in_array($status, ['available', 'reserved', 'lost'], true)) {
                    json_response(['ok' => false, 'error' => 'Dieser Status kann nicht manuell gesetzt werden.'], 422);
                }
                $isLibrary = !empty($data['is_library']);
                if ($isLoaned && (($copy['ownership'] === 'library') !== $isLibrary)) {
                    json_response(['ok' => false, 'error' => 'Die Art eines aktiv ausgeliehenen Exemplars kann nicht geändert werden.'], 409);
                }
                try {
                    $libraryDueAt = $isLibrary ? parse_future_date($data['library_due_at'] ?? null, 'Die Rückgabefrist') : null;
                } catch (InvalidArgumentException $e) {
                    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
                }
                if ($isLibrary && !$libraryDueAt) {
                    json_response(['ok' => false, 'error' => 'Bei einem Büchereibuch ist die Rückgabefrist erforderlich.'], 422);
                }
                try {
                    $requestedLocationId = (int)($data['location_id'] ?? 0);
                    if ($requestedLocationId > 0 && $requestedLocationId === (int)($copy['location_id'] ?? 0)) {
                        // Ein bereits zugeordnetes, später deaktiviertes Fach darf beim Bearbeiten
                        // unverändert bleiben. Andere inaktive Standorte können nicht neu gewählt werden.
                        $selectedLocation = get_location_by_id($requestedLocationId, true, $householdId);
                        if (!$selectedLocation) {
                            throw new InvalidArgumentException('Der bisherige Standort ist nicht mehr vorhanden.');
                        }
                    } else {
                        $selectedLocation = resolve_location_selection($data);
                    }
                } catch (InvalidArgumentException $e) {
                    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
                }
                $locationId = (int)$selectedLocation['id'];
                $homeLocationId = !empty($selectedLocation['is_loose']) ? ($copy['home_location_id'] ?: null) : $locationId;
                [$location, $shelf] = location_text_values($selectedLocation);

                $stmt = db()->prepare(
                    "UPDATE copies SET location = ?, shelf = ?, location_id = ?, home_location_id = ?, notes = ?, status = ?, ownership = ?,
                        library_name = ?, library_due_at = ?, library_returned_at = NULL,
                        deleted_at = NULL, deleted_by = NULL
                     WHERE id = ?"
                );
                $stmt->execute([
                    $location,
                    $shelf,
                    $locationId,
                    $homeLocationId,
                    nullable_text($data['notes'] ?? null, 4000),
                    $status,
                    $isLibrary ? 'library' : 'owned',
                    $isLibrary ? nullable_text($data['library_name'] ?? null, 255) : null,
                    $libraryDueAt,
                    $id,
                ]);
                db()->prepare("UPDATE household_book_settings SET archived_at = NULL, archived_by = NULL WHERE household_id = ? AND book_id = ?")->execute([$householdId, $bookId]);
                book_event($bookId, 'copy_updated', 'Standort oder Exemplardaten bearbeitet', (int)$user['id'], $id, [
                    'before' => ['location_id' => $copy['location_id'], 'location' => $copy['location'], 'shelf' => $copy['shelf'], 'ownership' => $copy['ownership'], 'library_due_at' => $copy['library_due_at']],
                    'after' => ['location_id' => $locationId, 'location_path' => $selectedLocation['path'], 'ownership' => $isLibrary ? 'library' : 'owned', 'library_due_at' => $libraryDueAt],
                ], null, null, $householdId);
                audit((int)$user['id'], 'copy_updated', 'copy', $id);
                json_response(['ok' => true]);
            }

            case 'copy_delete': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                $id = (int)($data['id'] ?? 0);

                $stmt = db()->prepare("SELECT * FROM copies WHERE id = ? AND household_id = ?");
                $stmt->execute([$id, $householdId]);
                $copy = $stmt->fetch();
                if (!$copy) {
                    json_response(['ok' => false, 'error' => 'Exemplar nicht gefunden.'], 404);
                }
                $stmt = db()->prepare("SELECT COUNT(*) FROM loans WHERE copy_id = ? AND returned_at IS NULL");
                $stmt->execute([$id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    json_response(['ok' => false, 'error' => 'Ein aktiv ausgeliehenes Exemplar kann nicht archiviert werden.'], 409);
                }
                $bookId = (int)$copy['book_id'];
                db()->prepare("UPDATE copies SET deleted_at = NOW(), deleted_by = ?, status = 'deleted' WHERE id = ?")
                    ->execute([(int)$user['id'], $id]);
                $stmt = db()->prepare("SELECT COUNT(*) FROM copies WHERE household_id = ? AND book_id = ? AND deleted_at IS NULL");
                $stmt->execute([$householdId, $bookId]);
                if ((int)$stmt->fetchColumn() === 0) {
                    db()->prepare("UPDATE household_book_settings SET archived_at = NOW(), archived_by = ? WHERE household_id = ? AND book_id = ?")
                        ->execute([(int)$user['id'], $householdId, $bookId]);
                }
                book_event($bookId, 'copy_archived', 'Exemplar archiviert', (int)$user['id'], $id, ['inventory_no' => $copy['inventory_no']], null, null, $householdId);
                audit((int)$user['id'], 'copy_archived', 'copy', $id);
                json_response(['ok' => true]);
            }

            case 'copy_restore': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                $id = (int)($data['id'] ?? 0);
                $stmt = db()->prepare("SELECT * FROM copies WHERE id = ? AND household_id = ?");
                $stmt->execute([$id, $householdId]);
                $copy = $stmt->fetch();
                if (!$copy) {
                    json_response(['ok' => false, 'error' => 'Exemplar nicht gefunden.'], 404);
                }
                if ($copy['ownership'] === 'library' && !empty($copy['library_returned_at'])) {
                    json_response(['ok' => false, 'error' => 'Ein zurückgegebenes Büchereiexemplar wird nicht reaktiviert. Lege bei erneuter Ausleihe ein neues Büchereiexemplar an.'], 409);
                }
                db()->prepare("UPDATE copies SET deleted_at = NULL, deleted_by = NULL, status = 'available' WHERE id = ?")->execute([$id]);
                db()->prepare("UPDATE household_book_settings SET archived_at = NULL, archived_by = NULL WHERE household_id = ? AND book_id = ?")->execute([$householdId, (int)$copy['book_id']]);
                book_event((int)$copy['book_id'], 'copy_restored', 'Exemplar aus dem Archiv wiederhergestellt', (int)$user['id'], $id, null, null, null, $householdId);
                json_response(['ok' => true]);
            }

            case 'library_return_scan': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                try {
                    $isbn = normalize_isbn((string)($data['isbn'] ?? ''));
                } catch (InvalidArgumentException $e) {
                    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
                }
                $stmt = db()->prepare(
                    "SELECT c.*, b.title FROM copies c
                     JOIN books b ON b.id = c.book_id
                     WHERE c.household_id = ? AND b.isbn13 = ? AND c.ownership = 'library'
                       AND c.library_returned_at IS NULL AND c.deleted_at IS NULL
                     ORDER BY c.library_due_at ASC, c.id ASC LIMIT 1"
                );
                $stmt->execute([$householdId, $isbn['isbn13']]);
                $copy = $stmt->fetch();
                if (!$copy) {
                    $stmt = db()->prepare(
                        "SELECT COUNT(*) FROM copies c JOIN books b ON b.id = c.book_id
                         WHERE c.household_id = ? AND b.isbn13 = ? AND c.ownership = 'library' AND c.library_returned_at IS NOT NULL"
                    );
                    $stmt->execute([$householdId, $isbn['isbn13']]);
                    $message = (int)$stmt->fetchColumn() > 0
                        ? 'Dieses Büchereibuch wurde bereits zurückgegeben.'
                        : 'Zu dieser ISBN ist kein aktives Büchereibuch erfasst.';
                    json_response(['ok' => false, 'error' => $message], 404);
                }
                $stmt = db()->prepare("SELECT COUNT(*) FROM loans WHERE copy_id = ? AND returned_at IS NULL");
                $stmt->execute([(int)$copy['id']]);
                if ((int)$stmt->fetchColumn() > 0) {
                    json_response(['ok' => false, 'error' => 'Die interne Verleihung dieses Exemplars muss zuerst zurückgegeben werden.'], 409);
                }
                $bookId = (int)$copy['book_id'];
                db()->prepare(
                    "UPDATE copies SET library_returned_at = NOW(), deleted_at = NOW(), deleted_by = ?, status = 'library_returned'
                     WHERE id = ?"
                )->execute([(int)$user['id'], (int)$copy['id']]);
                $stmt = db()->prepare("SELECT COUNT(*) FROM copies WHERE household_id = ? AND book_id = ? AND deleted_at IS NULL");
                $stmt->execute([$householdId, $bookId]);
                if ((int)$stmt->fetchColumn() === 0) {
                    db()->prepare("UPDATE household_book_settings SET archived_at = NOW(), archived_by = ? WHERE household_id = ? AND book_id = ?")
                        ->execute([(int)$user['id'], $householdId, $bookId]);
                }
                book_event($bookId, 'library_returned_scan', 'Büchereibuch per ISBN-Scan zurückgegeben', (int)$user['id'], (int)$copy['id'], [
                    'isbn' => $isbn['isbn13'],
                    'library_name' => $copy['library_name'],
                    'due_at' => $copy['library_due_at'],
                ], null, null, $householdId);
                json_response([
                    'ok' => true,
                    'message' => '„' . $copy['title'] . '“ wurde als zurückgegeben markiert.',
                    'book_id' => $bookId,
                    'copy_id' => (int)$copy['id'],
                    'title' => $copy['title'],
                ]);
            }

            case 'library_return': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $data = json_input();
                $id = (int)($data['copy_id'] ?? 0);
                $stmt = db()->prepare("SELECT c.*, b.title FROM copies c JOIN books b ON b.id = c.book_id WHERE c.id = ? AND c.household_id = ?");
                $stmt->execute([$id, $householdId]);
                $copy = $stmt->fetch();
                if (!$copy || $copy['ownership'] !== 'library') {
                    json_response(['ok' => false, 'error' => 'Büchereiexemplar nicht gefunden.'], 404);
                }
                if (!empty($copy['library_returned_at'])) {
                    json_response(['ok' => false, 'error' => 'Dieses Büchereibuch wurde bereits als zurückgegeben markiert.'], 409);
                }
                $stmt = db()->prepare("SELECT COUNT(*) FROM loans WHERE copy_id = ? AND returned_at IS NULL");
                $stmt->execute([$id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    json_response(['ok' => false, 'error' => 'Eine interne Verleihung muss zuerst zurückgegeben werden.'], 409);
                }
                $bookId = (int)$copy['book_id'];
                db()->prepare(
                    "UPDATE copies SET library_returned_at = NOW(), deleted_at = NOW(), deleted_by = ?, status = 'library_returned'
                     WHERE id = ?"
                )->execute([(int)$user['id'], $id]);
                $stmt = db()->prepare("SELECT COUNT(*) FROM copies WHERE household_id = ? AND book_id = ? AND deleted_at IS NULL");
                $stmt->execute([$householdId, $bookId]);
                if ((int)$stmt->fetchColumn() === 0) {
                    db()->prepare("UPDATE household_book_settings SET archived_at = NOW(), archived_by = ? WHERE household_id = ? AND book_id = ?")
                        ->execute([(int)$user['id'], $householdId, $bookId]);
                }
                book_event($bookId, 'library_returned', 'Büchereibuch an die Bücherei zurückgegeben', (int)$user['id'], $id, [
                    'library_name' => $copy['library_name'],
                    'due_at' => $copy['library_due_at'],
                ], null, null, $householdId);
                json_response(['ok' => true]);
            }

            case 'library_books': {
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $scope = clean_text($_GET['scope'] ?? 'active', 20);
                $where = ["c.household_id = ?", "c.ownership = 'library'"];
                if ($scope === 'active') {
                    $where[] = 'c.deleted_at IS NULL AND c.library_returned_at IS NULL';
                } elseif ($scope === 'returned') {
                    $where[] = 'c.library_returned_at IS NOT NULL';
                }
                $stmt = db()->prepare(
                    "SELECT c.*, b.id AS book_id,
                            COALESCE(hbs.title_override,b.title) AS title,
                            COALESCE(hbs.authors_override,b.authors) AS authors,
                            COALESCE(hbs.publisher_override,b.publisher) AS publisher,
                            COALESCE(hbs.published_date_override,b.published_date) AS published_date,
                            b.isbn13, COALESCE(sc.local_path,b.cover_path) AS cover_path, b.cover_url,
                            loc.code AS location_code, loc.building AS location_building, loc.room AS location_room,
                            loc.shelf AS location_shelf, loc.compartment AS location_compartment, loc.compartment_no AS location_compartment_no,
                            loc.is_loose AS location_is_loose, loc.active AS location_active,
                            home.building AS home_location_building, home.room AS home_location_room,
                            home.shelf AS home_location_shelf, home.compartment AS home_location_compartment,
                            home.compartment_no AS home_location_compartment_no, home.active AS home_location_active,
                            CASE WHEN c.library_due_at IS NOT NULL AND c.library_due_at < NOW() AND c.library_returned_at IS NULL THEN 1 ELSE 0 END AS overdue
                     FROM copies c JOIN books b ON b.id = c.book_id
                     JOIN household_book_settings hbs ON hbs.household_id = c.household_id AND hbs.book_id = b.id
                     LEFT JOIN book_covers sc ON sc.id = COALESCE(hbs.selected_cover_id,b.selected_cover_id)
                     LEFT JOIN locations loc ON loc.id = c.location_id
                     LEFT JOIN locations home ON home.id = c.home_location_id
                     WHERE " . implode(' AND ', $where) . "
                     ORDER BY (c.library_returned_at IS NULL) DESC, c.library_due_at ASC, COALESCE(hbs.title_override,b.title) ASC"
                );
                $stmt->execute([$householdId]);
                $items = $stmt->fetchAll();
                foreach ($items as &$item) {
                    $item['id'] = (int)$item['id'];
                    $item['book_id'] = (int)$item['book_id'];
                    $item['overdue'] = (bool)$item['overdue'];
                    $item['cover'] = $item['cover_path'] ?: $item['cover_url'];
                    $item['location_path'] = location_path([
                        'is_loose' => (bool)($item['location_is_loose'] ?? false),
                        'building' => $item['location_building'] ?? null, 'room' => $item['location_room'] ?? null,
                        'shelf' => $item['location_shelf'] ?? null, 'compartment' => $item['location_compartment'] ?? null,
                        'compartment_no' => $item['location_compartment_no'] ?? null,
                    ]);
                    $item['location_is_loose'] = (bool)($item['location_is_loose'] ?? false);
                    $item['location_active'] = !empty($item['location_active']);
                    $item['location_retired'] = !empty($item['location_id']) && !$item['location_active'] && !$item['location_is_loose'];
                    $item['home_location_path'] = !empty($item['home_location_id']) ? location_path([
                        'is_loose' => false, 'building' => $item['home_location_building'] ?? null,
                        'room' => $item['home_location_room'] ?? null, 'shelf' => $item['home_location_shelf'] ?? null,
                        'compartment' => $item['home_location_compartment'] ?? null,
                        'compartment_no' => $item['home_location_compartment_no'] ?? null,
                    ]) : null;
                    $item['home_location_active'] = !empty($item['home_location_active']);
                    $item['home_location_retired'] = !empty($item['home_location_id']) && !$item['home_location_active'];
                }
                json_response(['ok' => true, 'books' => $items]);
            }

            case 'online_search': {
                require_login();
                $q = clean_text($_GET['q'] ?? '', 200);
                if (mb_strlen($q) < 2) {
                    json_response(['ok' => true, 'results' => []]);
                }
                close_session_lock();

                $results = [];
                $seen = [];

                $google = null;
                if (GOOGLE_BOOKS_API_KEY !== '' && source_backoff_until('google_books') <= time()) {
                    $googleUrl = 'https://www.googleapis.com/books/v1/volumes?q=' . rawurlencode($q) . '&maxResults=8&projection=lite&key=' . rawurlencode(GOOGLE_BOOKS_API_KEY);
                    $googleResponse = http_get($googleUrl, 8);
                    if ((int)$googleResponse['status'] === 429) {
                        set_source_backoff('google_books', 4 * 3600);
                    } elseif ($googleResponse['ok']) {
                        $decodedGoogle = json_decode($googleResponse['body'], true);
                        $google = is_array($decodedGoogle) ? $decodedGoogle : null;
                    }
                }
                foreach (($google['items'] ?? []) as $item) {
                    $v = $item['volumeInfo'] ?? [];
                    $isbn = '';
                    foreach (($v['industryIdentifiers'] ?? []) as $identifier) {
                        if (($identifier['type'] ?? '') === 'ISBN_13') {
                            $isbn = preg_replace('/\D/', '', (string)$identifier['identifier']) ?? '';
                            break;
                        }
                    }
                    $key = $isbn ?: mb_strtolower((string)($v['title'] ?? '') . '|' . implode(',', $v['authors'] ?? []));
                    if ($key === '' || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $results[] = [
                        'source' => 'Google Books',
                        'title' => clean_text($v['title'] ?? 'Ohne Titel', 500),
                        'authors' => clean_text(implode(', ', $v['authors'] ?? []), 1000),
                        'publisher' => clean_text($v['publisher'] ?? '', 255),
                        'published_date' => clean_text($v['publishedDate'] ?? '', 30),
                        'isbn' => $isbn,
                        'thumbnail' => preg_replace('/^http:/i', 'https:', (string)($v['imageLinks']['thumbnail'] ?? '')),
                        'url' => (string)($v['infoLink'] ?? ''),
                    ];
                }

                try {
                    $normalizedOnlineIsbn = normalize_isbn($q);
                    $dnbMeta = metadata_dnb($normalizedOnlineIsbn['isbn13']);
                    if ($dnbMeta && !isset($seen[$normalizedOnlineIsbn['isbn13']])) {
                        $seen[$normalizedOnlineIsbn['isbn13']] = true;
                        $results[] = [
                            'source' => 'Deutsche Nationalbibliothek',
                            'title' => clean_text($dnbMeta['title'] ?? 'Ohne Titel', 500),
                            'authors' => clean_text($dnbMeta['authors'] ?? '', 1000),
                            'publisher' => clean_text($dnbMeta['publisher'] ?? '', 255),
                            'published_date' => clean_text($dnbMeta['published_date'] ?? '', 30),
                            'isbn' => $normalizedOnlineIsbn['isbn13'],
                            'thumbnail' => (string)($dnbMeta['cover_url'] ?? ''),
                            'url' => (string)($dnbMeta['external_url'] ?? ''),
                        ];
                    }
                } catch (InvalidArgumentException) {
                    // Die DNB-Ergänzung wird nur bei einer vollständigen ISBN abgefragt.
                }

                $openUrl = 'https://openlibrary.org/search.json?q=' . rawurlencode($q)
                    . '&limit=8&fields=' . rawurlencode('key,title,author_name,publisher,first_publish_year,isbn,cover_i');
                $open = json_get($openUrl, 8);
                foreach (($open['docs'] ?? []) as $doc) {
                    $isbn = '';
                    foreach (($doc['isbn'] ?? []) as $candidate) {
                        $digits = preg_replace('/\D/', '', (string)$candidate) ?? '';
                        if (strlen($digits) === 13) {
                            $isbn = $digits;
                            break;
                        }
                    }
                    $key = $isbn ?: mb_strtolower((string)($doc['title'] ?? '') . '|' . implode(',', $doc['author_name'] ?? []));
                    if ($key === '' || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $results[] = [
                        'source' => 'Open Library',
                        'title' => clean_text($doc['title'] ?? 'Ohne Titel', 500),
                        'authors' => clean_text(implode(', ', array_slice($doc['author_name'] ?? [], 0, 8)), 1000),
                        'publisher' => clean_text((string)($doc['publisher'][0] ?? ''), 255),
                        'published_date' => clean_text((string)($doc['first_publish_year'] ?? ''), 30),
                        'isbn' => $isbn,
                        'thumbnail' => !empty($doc['cover_i'])
                            ? 'https://covers.openlibrary.org/b/id/' . (int)$doc['cover_i'] . '-M.jpg'
                            : '',
                        'url' => !empty($doc['key']) ? 'https://openlibrary.org' . $doc['key'] : '',
                    ];
                }

                json_response(['ok' => true, 'results' => array_slice($results, 0, 12)]);
            }

            case 'nerd_stats': {
                $user = require_household_access();
                $householdId = (int)$user['active_household_id'];
                $pdo = db();
                $titleCounts = $pdo->prepare(
                    "SELECT COUNT(*) AS titles,
                            COALESCE(SUM(COALESCE(hbs.page_count_override,b.page_count,0)),0) AS pages_by_titles
                     FROM household_book_settings hbs JOIN books b ON b.id = hbs.book_id
                     WHERE hbs.household_id = ? AND hbs.archived_at IS NULL"
                );
                $titleCounts->execute([$householdId]);
                $summary = $titleCounts->fetch() ?: [];
                $copyCounts = $pdo->prepare(
                    "SELECT COUNT(*) AS copies,
                            COALESCE(SUM(CASE WHEN c.deleted_at IS NULL THEN 1 ELSE 0 END),0) AS active_copies,
                            COALESCE(SUM(CASE WHEN c.deleted_at IS NULL THEN COALESCE(hbs.page_count_override,b.page_count,0) ELSE 0 END),0) AS pages_by_copies
                     FROM copies c
                     JOIN household_book_settings hbs ON hbs.household_id = c.household_id AND hbs.book_id = c.book_id
                     JOIN books b ON b.id = c.book_id
                     WHERE c.household_id = ? AND hbs.archived_at IS NULL"
                );
                $copyCounts->execute([$householdId]);
                $summary = array_merge($summary, $copyCounts->fetch() ?: []);

                $years = $pdo->prepare(
                    "SELECT SUBSTRING(COALESCE(hbs.published_date_override,b.published_date),1,4) AS year, COUNT(*) AS count
                     FROM household_book_settings hbs
                     JOIN books b ON b.id = hbs.book_id
                     WHERE hbs.household_id = ? AND hbs.archived_at IS NULL
                       AND COALESCE(hbs.published_date_override,b.published_date) REGEXP '^[0-9]{4}'
                     GROUP BY year ORDER BY year ASC"
                );
                $years->execute([$householdId]);
                $timeline = [];
                foreach ($years->fetchAll() as $row) {
                    $year = (int)$row['year'];
                    if ($year >= 1200 && $year <= (int)date('Y') + 1) {
                        $timeline[] = ['year' => $year, 'count' => (int)$row['count']];
                    }
                }

                $tagsStmt = $pdo->prepare(
                    "SELECT COALESCE(hbs.categories_override,b.categories) AS categories
                     FROM household_book_settings hbs JOIN books b ON b.id = hbs.book_id
                     WHERE hbs.household_id = ? AND hbs.archived_at IS NULL
                       AND COALESCE(hbs.categories_override,b.categories) IS NOT NULL
                       AND COALESCE(hbs.categories_override,b.categories) <> ''"
                );
                $tagsStmt->execute([$householdId]);
                $tags = [];
                foreach ($tagsStmt->fetchAll(PDO::FETCH_COLUMN) as $text) {
                    foreach (preg_split('/[,;|\/]+/u', (string)$text) as $tag) {
                        $tag = trim(preg_replace('/\s+/u', ' ', $tag) ?? '');
                        if (mb_strlen($tag) < 3 || mb_strlen($tag) > 45) continue;
                        $key = mb_strtolower($tag);
                        if (!isset($tags[$key])) $tags[$key] = ['label' => $tag, 'count' => 0];
                        $tags[$key]['count']++;
                    }
                }
                usort($tags, static fn($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));

                $topAuthors = $pdo->prepare(
                    "SELECT COALESCE(hbs.authors_override,b.authors) AS authors, COUNT(*) AS count
                     FROM household_book_settings hbs JOIN books b ON b.id = hbs.book_id
                     WHERE hbs.household_id = ? AND hbs.archived_at IS NULL
                       AND COALESCE(hbs.authors_override,b.authors) IS NOT NULL
                       AND COALESCE(hbs.authors_override,b.authors) <> ''
                     GROUP BY authors ORDER BY count DESC, authors ASC LIMIT 12"
                );
                $topAuthors->execute([$householdId]);

                json_response(['ok' => true, 'stats' => [
                    'titles' => (int)($summary['titles'] ?? 0),
                    'copies' => (int)($summary['copies'] ?? 0),
                    'active_copies' => (int)($summary['active_copies'] ?? 0),
                    'pages_by_copies' => (int)($summary['pages_by_copies'] ?? 0),
                    'pages_by_titles' => (int)($summary['pages_by_titles'] ?? 0),
                    'timeline' => $timeline,
                    'tags' => array_slice(array_values($tags), 0, 60),
                    'top_authors' => array_map(static fn($row) => ['authors' => (string)$row['authors'], 'count' => (int)$row['count']], $topAuthors->fetchAll()),
                ]]);
            }

            case 'metadata_queue': {
                $user = require_household_access();
                $householdId = (int)$user['active_household_id'];
                json_response(['ok' => true] + metadata_queue_snapshot($householdId));
            }

            case 'metadata_job_cancel': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $jobId = (int)(json_input()['job_id'] ?? 0);
                if ($jobId < 1) {
                    json_response(['ok' => false, 'error' => 'Ungültiger Auftrag.'], 400);
                }
                cancel_metadata_job($jobId, (int)$user['active_household_id']);
                json_response(['ok' => true] + metadata_queue_snapshot((int)$user['active_household_id']));
            }

            case 'process_jobs': {
                require_method('POST');
                verify_csrf();
                require_login();
                close_session_lock();
                ignore_user_abort(true);
                @set_time_limit(25);
                json_response(['ok' => true] + process_jobs(1));
            }

            case 'users': {
                require_admin();
                $stmt = db()->query(
                    "SELECT u.id, u.email, u.display_name, u.role, u.active, u.created_at, u.updated_at,
                            u.last_login_at, u.email_verified_at, u.anonymized_at,
                        (SELECT COUNT(*) FROM loans l WHERE l.user_id = u.id AND l.returned_at IS NULL) AS active_loans,
                        (SELECT COUNT(*) FROM reservations r WHERE r.user_id = u.id AND r.status = 'active') AS active_reservations
                     FROM users u ORDER BY u.display_name ASC"
                );
                $users = $stmt->fetchAll();
                foreach ($users as &$row) {
                    $row['id'] = (int)$row['id'];
                    $row['active'] = (bool)$row['active'];
                    $row['active_loans'] = (int)$row['active_loans'];
                    $row['active_reservations'] = (int)$row['active_reservations'];
                    $row['email_verified'] = !empty($row['email_verified_at']);
                    $row['anonymized'] = !empty($row['anonymized_at']);
                    $row['household_overview'] = admin_user_household_overview((int)$row['id']);
                }
                unset($row);
                json_response(['ok' => true, 'users' => $users]);
            }

            case 'user_create': {
                require_method('POST');
                verify_csrf();
                $admin = require_admin();
                $data = json_input();
                $name = clean_text($data['display_name'] ?? '', 120);
                $email = mb_strtolower(clean_text($data['email'] ?? '', 191));
                $password = (string)($data['password'] ?? '');
                $adminPassword = (string)($data['admin_password'] ?? '');
                require_admin_password($admin, $adminPassword);
                $role = ($data['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
                $householdName = clean_text($data['household_name'] ?? ($name !== '' ? $name . 's Haushalt' : 'Mein Haushalt'), 160);

                if ($name === '' || !valid_email($email) || $householdName === '') {
                    json_response(['ok' => false, 'error' => 'Name, Haushaltsname und eine gültige E-Mail-Adresse sind erforderlich.'], 422);
                }
                if ($password !== '' && mb_strlen($password) < 10) {
                    json_response(['ok' => false, 'error' => 'Ein angegebenes Startpasswort muss mindestens 10 Zeichen lang sein.'], 422);
                }

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        "INSERT INTO users (email, password_hash, display_name, role, active)
                         VALUES (?, ?, ?, ?, 1)"
                    );
                    $initialPassword = $password !== '' ? $password : bin2hex(random_bytes(32));
                    $stmt->execute([$email, password_hash($initialPassword, PASSWORD_DEFAULT), $name, $role]);
                    $id = (int)$pdo->lastInsertId();
                    $householdId = create_household_for_user($pdo, $id, $householdName);
                    $pdo->commit();
                    audit((int)$admin['id'], 'user_created', 'user', $id, ['role' => $role, 'household_id' => $householdId]);
                    $target = ['id' => $id, 'email' => $email, 'display_name' => $name];
                    $resetUrl = null;
                    if ($password === '') {
                        $resetUrl = queue_password_reset_link($target, (int)$admin['id']);
                    } else {
                        queue_email_verification_link($target, (int)$admin['id']);
                        queue_account_notice($email, 'Konto angelegt', 'Ein Administrator hat ein TRIAMO-Konto für dich angelegt. Bitte bestätige deine E-Mail-Adresse und ändere das Startpasswort nach der ersten Anmeldung.');
                    }
                    json_response(['ok' => true, 'id' => $id, 'household_id' => $householdId, 'reset_url' => $resetUrl], 201);
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ((string)$e->getCode() === '23000') {
                        json_response(['ok' => false, 'error' => 'Diese E-Mail-Adresse wird bereits verwendet.'], 409);
                    }
                    throw $e;
                }
            }

            case 'user_update': {
                require_method('POST');
                verify_csrf();
                $admin = require_admin();
                $data = json_input();
                $id = (int)($data['id'] ?? 0);
                require_admin_password($admin, (string)($data['admin_password'] ?? ''));

                $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) {
                    json_response(['ok' => false, 'error' => 'Benutzer nicht gefunden.'], 404);
                }

                $name = clean_text($data['display_name'] ?? $target['display_name'], 120);
                $email = mb_strtolower(clean_text($data['email'] ?? $target['email'], 191));
                $role = ($data['role'] ?? $target['role']) === 'admin' ? 'admin' : 'member';
                $active = !empty($data['active']) ? 1 : 0;
                $password = (string)($data['password'] ?? '');

                if ($name === '' || !valid_email($email)) {
                    json_response(['ok' => false, 'error' => 'Name und gültige E-Mail-Adresse sind erforderlich.'], 422);
                }
                if ($id === (int)$admin['id'] && (!$active || $role !== 'admin')) {
                    json_response(['ok' => false, 'error' => 'Das eigene Administratorkonto kann nicht deaktiviert oder herabgestuft werden.'], 409);
                }
                if (!$active && (int)$target['active'] === 1) {
                    $stmt = db()->prepare("SELECT (SELECT COUNT(*) FROM loans WHERE user_id = ? AND returned_at IS NULL) + (SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'active')");
                    $stmt->execute([$id, $id]);
                    if ((int)$stmt->fetchColumn() > 0) {
                        json_response(['ok' => false, 'error' => 'Das Konto hat aktive Verleihungen oder Vormerkungen und kann noch nicht deaktiviert werden.'], 409);
                    }
                }

                $adminCount = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
                if ($target['role'] === 'admin' && (int)$target['active'] === 1 && ($role !== 'admin' || !$active) && $adminCount <= 1) {
                    json_response(['ok' => false, 'error' => 'Mindestens ein aktiver Administrator muss bestehen bleiben.'], 409);
                }

                try {
                    $oldEmail = (string)$target['email'];
                    $emailChanged = !hash_equals(mb_strtolower($oldEmail), $email);
                    $passwordChanged = $password !== '';
                    if ($passwordChanged) {
                        if (mb_strlen($password) < 10) {
                            json_response(['ok' => false, 'error' => 'Das neue Passwort muss mindestens 10 Zeichen lang sein.'], 422);
                        }
                        $stmt = db()->prepare(
                            "UPDATE users SET email = ?, display_name = ?, role = ?, active = ?, password_hash = ?,
                             session_version = session_version + 1, email_verified_at = IF(? = 1, NULL, email_verified_at) WHERE id = ?"
                        );
                        $stmt->execute([$email, $name, $role, $active, password_hash($password, PASSWORD_DEFAULT), $emailChanged ? 1 : 0, $id]);
                    } else {
                        $stmt = db()->prepare(
                            "UPDATE users SET email = ?, display_name = ?, role = ?, active = ?,
                             email_verified_at = IF(? = 1, NULL, email_verified_at) WHERE id = ?"
                        );
                        $stmt->execute([$email, $name, $role, $active, $emailChanged ? 1 : 0, $id]);
                    }
                    if ($emailChanged) {
                        queue_account_notice($oldEmail, 'E-Mail-Adresse geändert', 'Ein Administrator hat die E-Mail-Adresse deines TRIAMO-Kontos geändert.');
                        queue_email_verification_link(['id' => $id, 'email' => $email, 'display_name' => $name], (int)$admin['id']);
                    }
                    if ($passwordChanged) {
                        if ($id === (int)$admin['id']) {
                            $versionStmt = db()->prepare('SELECT session_version FROM users WHERE id = ?');
                            $versionStmt->execute([$id]);
                            $_SESSION['session_version'] = (int)$versionStmt->fetchColumn();
                        }
                        queue_account_notice($email, 'Passwort geändert', 'Ein Administrator hat das Passwort deines TRIAMO-Kontos geändert. Alle bisherigen Sitzungen wurden beendet.');
                    }
                    if ((string)$target['role'] !== $role || (int)$target['active'] !== $active) {
                        queue_account_notice($email, 'Kontoberechtigung geändert', 'Ein Administrator hat Rolle oder Aktivstatus deines TRIAMO-Kontos geändert.');
                    }
                    audit((int)$admin['id'], 'user_updated', 'user', $id, ['role' => $role, 'active' => $active, 'email_changed' => $emailChanged, 'password_changed' => $passwordChanged]);
                    json_response(['ok' => true]);
                } catch (PDOException $e) {
                    if ((string)$e->getCode() === '23000') {
                        json_response(['ok' => false, 'error' => 'Diese E-Mail-Adresse wird bereits verwendet.'], 409);
                    }
                    throw $e;
                }
            }

            case 'user_password_reset_link': {
                require_method('POST');
                verify_csrf();
                $admin = require_admin();
                $data = json_input();
                require_admin_password($admin, (string)($data['admin_password'] ?? ''));
                $id = (int)($data['id'] ?? 0);
                $stmt = db()->prepare("SELECT id, email, display_name FROM users WHERE id = ? AND active = 1 AND anonymized_at IS NULL LIMIT 1");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) {
                    json_response(['ok' => false, 'error' => 'Aktives Benutzerkonto nicht gefunden.'], 404);
                }
                $url = queue_password_reset_link($target, (int)$admin['id']);
                audit((int)$admin['id'], 'password_reset_link_created', 'user', $id);
                json_response(['ok' => true, 'message' => 'Der Einmallink wurde versendet.', 'reset_url' => $url]);
            }

            case 'user_sessions_revoke': {
                require_method('POST');
                verify_csrf();
                $admin = require_admin();
                $data = json_input();
                require_admin_password($admin, (string)($data['admin_password'] ?? ''));
                $id = (int)($data['id'] ?? 0);
                $stmt = db()->prepare("SELECT id, email, display_name, active FROM users WHERE id = ? AND anonymized_at IS NULL LIMIT 1");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) {
                    json_response(['ok' => false, 'error' => 'Benutzerkonto nicht gefunden.'], 404);
                }
                db()->prepare("UPDATE users SET session_version = session_version + 1 WHERE id = ?")->execute([$id]);
                queue_account_notice((string)$target['email'], 'Sitzungen beendet', 'Ein Administrator hat alle aktiven Sitzungen deines TRIAMO-Kontos beendet.');
                audit((int)$admin['id'], 'user_sessions_revoked', 'user', $id);
                if ($id === (int)$admin['id']) {
                    unset($_SESSION['user_id'], $_SESSION['household_id'], $_SESSION['session_version']);
                    json_response(['ok' => true, 'message' => 'Alle Sitzungen wurden beendet. Du wirst abgemeldet.', 'self_logged_out' => true]);
                }
                json_response(['ok' => true, 'message' => 'Alle Sitzungen des Kontos wurden beendet.']);
            }

            case 'user_deletion_preview': {
                require_method('POST');
                verify_csrf();
                $admin = require_admin();
                $data = json_input();
                require_admin_password($admin, (string)($data['admin_password'] ?? ''));
                $id = (int)($data['id'] ?? 0);
                if ($id === (int)$admin['id']) {
                    json_response(['ok' => false, 'error' => 'Das eigene Administratorkonto kann über diesen Ablauf nicht anonymisiert werden.'], 409);
                }
                $stmt = db()->prepare("SELECT id, email, display_name, role, active, anonymized_at FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target || !empty($target['anonymized_at'])) {
                    json_response(['ok' => false, 'error' => 'Benutzerkonto nicht gefunden oder bereits anonymisiert.'], 404);
                }
                $counts = [];
                foreach ([
                    'active_loans' => "SELECT COUNT(*) FROM loans WHERE user_id = ? AND returned_at IS NULL",
                    'active_reservations' => "SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'active'",
                    'loan_history' => "SELECT COUNT(*) FROM loans WHERE user_id = ?",
                    'reservations' => "SELECT COUNT(*) FROM reservations WHERE user_id = ?",
                    'audit_entries' => "SELECT COUNT(*) FROM audit_log WHERE user_id = ?",
                ] as $key => $sql) {
                    $q = db()->prepare($sql);
                    $q->execute([$id]);
                    $counts[$key] = (int)$q->fetchColumn();
                }
                $overview = admin_user_household_overview($id);
                $owned = array_values(array_filter($overview['memberships'], static fn(array $row): bool => !empty($row['is_owner']) && !empty($row['active'])));
                $candidates = db()->prepare("SELECT id, display_name, email FROM users WHERE id <> ? AND active = 1 AND anonymized_at IS NULL ORDER BY display_name");
                $candidates->execute([$id]);
                json_response([
                    'ok' => true,
                    'target' => ['id' => $id, 'display_name' => (string)$target['display_name'], 'email' => (string)$target['email']],
                    'counts' => $counts,
                    'owned_households' => $owned,
                    'transfer_candidates' => $candidates->fetchAll(),
                    'confirmation_phrase' => 'KONTO ' . $id . ' ANONYMISIEREN',
                ]);
            }

            case 'user_anonymize': {
                require_method('POST');
                verify_csrf();
                $admin = require_admin();
                $data = json_input();
                require_admin_password($admin, (string)($data['admin_password'] ?? ''));
                $id = (int)($data['id'] ?? 0);
                $confirmation = trim((string)($data['confirmation'] ?? ''));
                $transferUserId = (int)($data['transfer_user_id'] ?? 0);
                if ($id < 1 || $id === (int)$admin['id']) {
                    json_response(['ok' => false, 'error' => 'Dieses Konto kann nicht über diesen Ablauf anonymisiert werden.'], 409);
                }
                if ($confirmation !== 'KONTO ' . $id . ' ANONYMISIEREN') {
                    json_response(['ok' => false, 'error' => 'Der Bestätigungstext stimmt nicht überein.'], 422);
                }
                $pdo = db();
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target || !empty($target['anonymized_at'])) {
                    json_response(['ok' => false, 'error' => 'Benutzerkonto nicht gefunden oder bereits anonymisiert.'], 404);
                }
                $stmt = $pdo->prepare("SELECT (SELECT COUNT(*) FROM loans WHERE user_id = ? AND returned_at IS NULL) + (SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'active')");
                $stmt->execute([$id, $id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    json_response(['ok' => false, 'error' => 'Aktive Verleihungen oder Vormerkungen müssen zuerst abgeschlossen werden.'], 409);
                }
                if ((string)$target['role'] === 'admin' && (int)$target['active'] === 1) {
                    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND anonymized_at IS NULL")->fetchColumn();
                    if ($adminCount <= 1) {
                        json_response(['ok' => false, 'error' => 'Mindestens ein aktiver Administrator muss bestehen bleiben.'], 409);
                    }
                }
                $ownedStmt = $pdo->prepare("SELECT id, name FROM households WHERE owner_user_id = ? AND active = 1");
                $ownedStmt->execute([$id]);
                $owned = $ownedStmt->fetchAll();
                if ($owned) {
                    if ($transferUserId < 1 || $transferUserId === $id) {
                        json_response(['ok' => false, 'error' => 'Für die eigenen Haushalte muss ein neuer Eigentümer gewählt werden.'], 422);
                    }
                    $transferStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND active = 1 AND anonymized_at IS NULL LIMIT 1");
                    $transferStmt->execute([$transferUserId]);
                    if (!$transferStmt->fetchColumn()) {
                        json_response(['ok' => false, 'error' => 'Der gewählte neue Eigentümer ist nicht verfügbar.'], 422);
                    }
                }
                $oldEmail = (string)$target['email'];
                $pdo->beginTransaction();
                try {
                    foreach ($owned as $household) {
                        $householdId = (int)$household['id'];
                        $pdo->prepare("UPDATE households SET owner_user_id = ? WHERE id = ?")
                            ->execute([$transferUserId, $householdId]);
                        $pdo->prepare(
                            "INSERT INTO household_members (household_id, user_id, member_role, active)
                             VALUES (?, ?, 'owner', 1)
                             ON DUPLICATE KEY UPDATE member_role = 'owner', active = 1"
                        )->execute([$householdId, $transferUserId]);
                    }
                    $pdo->prepare("UPDATE household_members SET active = 0 WHERE user_id = ?")->execute([$id]);
                    $pdo->prepare(
                        "UPDATE household_access_grants SET active = 0, revoked_at = COALESCE(revoked_at, NOW()), revoked_by = ?
                         WHERE viewer_user_id = ? AND active = 1"
                    )->execute([(int)$admin['id'], $id]);
                    $anonymousEmail = 'deleted+' . $id . '+' . substr(hash('sha256', random_bytes(16)), 0, 12) . '@invalid.local';
                    $anonymousName = 'Gelöschtes Konto #' . $id;
                    $pdo->prepare(
                        "UPDATE users SET email = ?, display_name = ?, role = 'member', active = 0,
                         password_hash = ?, email_verified_at = NULL, session_version = session_version + 1,
                         anonymized_at = NOW() WHERE id = ?"
                    )->execute([$anonymousEmail, $anonymousName, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $id]);
                    $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM email_verification_tokens WHERE user_id = ?")->execute([$id]);
                    $pdo->commit();
                    queue_account_notice($oldEmail, 'Konto anonymisiert', 'Dein TRIAMO-Konto wurde durch einen Administrator deaktiviert und personenbezogene Kontodaten wurden anonymisiert. Historische Vorgänge bleiben nur in anonymisierter Zuordnung erhalten.');
                    audit((int)$admin['id'], 'user_anonymized', 'user', $id, ['transferred_to' => $transferUserId ?: null, 'owned_households' => array_map(static fn(array $h): int => (int)$h['id'], $owned)]);
                    json_response(['ok' => true, 'message' => 'Das Konto wurde deaktiviert und anonymisiert.']);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }

            case 'privacy_export': {
                require_method('POST');
                verify_csrf();
                $user = require_login();
                $data = json_input();
                $currentPassword = (string)($data['current_password'] ?? '');

                if ($currentPassword === '') {
                    json_response(['ok' => false, 'error' => 'Bitte das aktuelle Passwort eingeben.'], 422);
                }

                $stmt = db()->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([(int)$user['id']]);
                $passwordHash = (string)$stmt->fetchColumn();

                if ($passwordHash === '' || !password_verify($currentPassword, $passwordHash)) {
                    json_response(['ok' => false, 'error' => 'Das aktuelle Passwort ist falsch.'], 422);
                }

                $payload = create_privacy_export_payload($user);
                $json = json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRETTY_PRINT
                    | JSON_INVALID_UTF8_SUBSTITUTE
                );

                if ($json === false) {
                    json_response(['ok' => false, 'error' => 'Die Datenkopie konnte nicht erstellt werden.'], 500);
                }

                audit((int)$user['id'], 'privacy_export_downloaded', 'user', (int)$user['id']);

                $filename = 'triamo-datenauskunft-' . date('Ymd-His') . '.json';
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Cache-Control: no-store, max-age=0');
                header('Pragma: no-cache');
                header('Content-Length: ' . strlen($json));
                echo $json;
                exit;
            }

            case 'profile_update': {
                require_method('POST');
                verify_csrf();
                $user = require_login();
                $data = json_input();
                $name = clean_text($data['display_name'] ?? '', 120);
                $email = mb_strtolower(clean_text($data['email'] ?? '', 191));
                $householdName = clean_text($data['household_name'] ?? '', 160);
                $currentPassword = (string)($data['current_password'] ?? '');
                $newPassword = (string)($data['new_password'] ?? '');

                if ($name === '' || !valid_email($email)) {
                    json_response(['ok' => false, 'error' => 'Name und gültige E-Mail-Adresse sind erforderlich.'], 422);
                }

                $stmt = db()->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->execute([(int)$user['id']]);
                $hash = (string)$stmt->fetchColumn();
                $oldEmail = (string)$user['email'];
                $emailChanged = !hash_equals(mb_strtolower($oldEmail), $email);

                if ($emailChanged && !password_verify($currentPassword, $hash)) {
                    json_response(['ok' => false, 'error' => 'Für die Änderung der E-Mail-Adresse ist das aktuelle Passwort erforderlich.'], 422);
                }
                if ($newPassword !== '') {
                    if (!password_verify($currentPassword, $hash)) {
                        json_response(['ok' => false, 'error' => 'Das aktuelle Passwort ist falsch.'], 422);
                    }
                    if (mb_strlen($newPassword) < 10) {
                        json_response(['ok' => false, 'error' => 'Das neue Passwort muss mindestens 10 Zeichen lang sein.'], 422);
                    }
                }
                if (!empty($user['can_manage_household']) && $householdName === '') {
                    json_response(['ok' => false, 'error' => 'Der Haushaltsname darf nicht leer sein.'], 422);
                }

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    if ($newPassword !== '') {
                        $pdo->prepare(
                            "UPDATE users SET display_name = ?, email = ?, password_hash = ?,
                             session_version = session_version + 1, email_verified_at = IF(? = 1, NULL, email_verified_at) WHERE id = ?"
                        )->execute([$name, $email, password_hash($newPassword, PASSWORD_DEFAULT), $emailChanged ? 1 : 0, (int)$user['id']]);
                    } else {
                        $pdo->prepare(
                            "UPDATE users SET display_name = ?, email = ?, email_verified_at = IF(? = 1, NULL, email_verified_at) WHERE id = ?"
                        )->execute([$name, $email, $emailChanged ? 1 : 0, (int)$user['id']]);
                    }
                    if (!empty($user['can_manage_household']) && $householdName !== '') {
                        $pdo->prepare("UPDATE households SET name = ? WHERE id = ? AND owner_user_id = ?")
                            ->execute([$householdName, (int)$user['active_household_id'], (int)$user['id']]);
                    }
                    $pdo->commit();
                    if ($newPassword !== '') {
                        $versionStmt = db()->prepare('SELECT session_version FROM users WHERE id = ?');
                        $versionStmt->execute([(int)$user['id']]);
                        $_SESSION['session_version'] = (int)$versionStmt->fetchColumn();
                        queue_account_notice($email, 'Passwort geändert', 'Das Passwort deines TRIAMO-Kontos wurde geändert. Andere bestehende Sitzungen wurden beendet.');
                    }
                    if ($emailChanged) {
                        queue_account_notice($oldEmail, 'E-Mail-Adresse geändert', 'Die E-Mail-Adresse deines TRIAMO-Kontos wurde geändert.');
                        queue_email_verification_link(['id' => (int)$user['id'], 'email' => $email, 'display_name' => $name], (int)$user['id']);
                    }
                    audit((int)$user['id'], 'profile_updated', 'user', (int)$user['id'], ['email_changed' => $emailChanged, 'password_changed' => $newPassword !== '']);
                    json_response(['ok' => true]);
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ((string)$e->getCode() === '23000') {
                        json_response(['ok' => false, 'error' => 'Diese E-Mail-Adresse wird bereits verwendet.'], 409);
                    }
                    throw $e;
                }
            }

            case 'loans': {
                $user = require_household_access();
                $householdId = (int)$user['active_household_id'];
                $canManage = !empty($user['can_manage_household']);
                $scope = clean_text($_GET['scope'] ?? 'active', 20);
                $where = ['c.household_id = ?'];
                $params = [$householdId];
                if (!$canManage) {
                    $where[] = 'l.user_id = ?';
                    $params[] = (int)$user['id'];
                }
                if ($scope === 'active') {
                    $where[] = 'l.returned_at IS NULL';
                } elseif ($scope === 'returned') {
                    $where[] = 'l.returned_at IS NOT NULL';
                }

                $sql = "SELECT l.*, u.display_name, u.email, c.inventory_no, c.book_id,
                            COALESCE(hbs.title_override,b.title) AS title,
                            COALESCE(hbs.authors_override,b.authors) AS authors,
                            b.isbn13, COALESCE(sc.local_path,b.cover_path) AS cover_path, b.cover_url,
                            CASE WHEN l.returned_at IS NULL AND l.due_at < NOW() THEN 1 ELSE 0 END AS overdue
                        FROM loans l
                        JOIN users u ON u.id = l.user_id
                        JOIN copies c ON c.id = l.copy_id
                        JOIN books b ON b.id = c.book_id
                        JOIN household_book_settings hbs ON hbs.household_id = c.household_id AND hbs.book_id = b.id
                        LEFT JOIN book_covers sc ON sc.id = COALESCE(hbs.selected_cover_id,b.selected_cover_id) AND sc.book_id = b.id
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY (l.returned_at IS NULL) DESC, l.due_at DESC LIMIT 300";

                $stmt = db()->prepare($sql);
                $stmt->execute($params);
                $loans = $stmt->fetchAll();
                foreach ($loans as &$loan) {
                    foreach (['id', 'copy_id', 'user_id', 'book_id'] as $key) $loan[$key] = (int)$loan[$key];
                    $loan['overdue'] = (bool)$loan['overdue'];
                    $loan['cover'] = $loan['cover_path'] ?: $loan['cover_url'];
                    if (!$canManage) {
                        $loan['email'] = null;
                    }
                }
                json_response(['ok' => true, 'loans' => $loans, 'can_manage' => $canManage]);
            }

            case 'borrowers': {
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $stmt = db()->prepare(
                    "SELECT DISTINCT u.id, u.display_name, u.email
                     FROM users u
                     LEFT JOIN household_members hm ON hm.user_id = u.id AND hm.household_id = ? AND hm.active = 1
                     LEFT JOIN household_access_grants g ON g.viewer_user_id = u.id AND g.owner_household_id = ? AND g.active = 1
                     WHERE u.active = 1 AND (hm.id IS NOT NULL OR g.id IS NOT NULL OR u.id = ?)
                     ORDER BY u.display_name, u.email"
                );
                $stmt->execute([$householdId, $householdId, (int)$user['id']]);
                $rows = $stmt->fetchAll();
                foreach ($rows as &$row) $row['id'] = (int)$row['id'];
                json_response(['ok' => true, 'users' => $rows]);
            }

            case 'loan_create': {
                require_method('POST');
                verify_csrf();
                $admin = require_household_manager();
                $householdId = (int)$admin['active_household_id'];
                $data = json_input();
                $bookId = (int)($data['book_id'] ?? 0);
                $userId = (int)($data['user_id'] ?? 0);
                $override = !empty($data['override_reservation']);

                if (!get_book($bookId, $householdId)) {
                    json_response(['ok' => false, 'error' => 'Buch nicht gefunden.'], 404);
                }
                $dueInput = clean_text($data['due_at'] ?? '', 30);
                try {
                    $due = $dueInput !== ''
                        ? new DateTimeImmutable($dueInput . (strlen($dueInput) <= 10 ? ' 23:59:59' : ''))
                        : (new DateTimeImmutable('now'))->modify('+' . DEFAULT_LOAN_DAYS . ' days')->setTime(23, 59, 59);
                } catch (Throwable) {
                    json_response(['ok' => false, 'error' => 'Das Rückgabedatum ist ungültig.'], 422);
                }
                if ($due <= new DateTimeImmutable('now')) {
                    json_response(['ok' => false, 'error' => 'Das Rückgabedatum muss in der Zukunft liegen.'], 422);
                }

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        "SELECT u.id, u.active, u.display_name
                         FROM users u
                         WHERE u.id = ? AND u.active = 1 AND (
                            u.id = ?
                            OR EXISTS (SELECT 1 FROM household_members hm WHERE hm.user_id = u.id AND hm.household_id = ? AND hm.active = 1)
                            OR EXISTS (SELECT 1 FROM household_access_grants g WHERE g.viewer_user_id = u.id AND g.owner_household_id = ? AND g.active = 1)
                         ) FOR UPDATE"
                    );
                    $stmt->execute([$userId, (int)$admin['id'], $householdId, $householdId]);
                    $borrower = $stmt->fetch();
                    if (!$borrower) throw new RuntimeException('Der ausgewählte Benutzer ist nicht für diesen Haushalt freigegeben oder nicht aktiv.');

                    $stmt = $pdo->prepare(
                        "SELECT r.* FROM reservations r
                         WHERE r.household_id = ? AND r.book_id = ? AND r.status = 'active'
                         ORDER BY r.created_at ASC, r.id ASC LIMIT 1 FOR UPDATE"
                    );
                    $stmt->execute([$householdId, $bookId]);
                    $firstReservation = $stmt->fetch();
                    if ($firstReservation && (int)$firstReservation['user_id'] !== $userId && !$override) {
                        $pdo->rollBack();
                        json_response(['ok' => false, 'error' => 'Das Buch ist zuerst für einen anderen Benutzer vorgemerkt. Eine Übersteuerung ist möglich.', 'reservation_conflict' => true], 409);
                    }

                    $stmt = $pdo->prepare(
                        "SELECT c.* FROM copies c
                         WHERE c.household_id = ? AND c.book_id = ? AND c.status = ? AND c.deleted_at IS NULL
                         ORDER BY c.id ASC LIMIT 1 FOR UPDATE"
                    );
                    $copy = null;
                    if ($firstReservation && (int)$firstReservation['user_id'] === $userId) {
                        $stmt->execute([$householdId, $bookId, 'reserved']);
                        $copy = $stmt->fetch();
                    }
                    if (!$copy) {
                        $stmt->execute([$householdId, $bookId, 'available']);
                        $copy = $stmt->fetch();
                    }
                    if (!$copy && $override) {
                        $stmt->execute([$householdId, $bookId, 'reserved']);
                        $copy = $stmt->fetch();
                    }
                    if (!$copy) throw new RuntimeException('Derzeit ist kein ausleihbares Exemplar verfügbar.');

                    $pdo->prepare("UPDATE copies SET status = 'loaned' WHERE id = ? AND household_id = ?")
                        ->execute([(int)$copy['id'], $householdId]);
                    $stmt = $pdo->prepare("INSERT INTO loans (copy_id, user_id, created_by, due_at) VALUES (?, ?, ?, ?)");
                    $stmt->execute([(int)$copy['id'], $userId, (int)$admin['id'], $due->format('Y-m-d H:i:s')]);
                    $loanId = (int)$pdo->lastInsertId();

                    if ($firstReservation && (int)$firstReservation['user_id'] === $userId) {
                        $pdo->prepare("UPDATE reservations SET status = 'fulfilled', fulfilled_at = NOW() WHERE id = ? AND household_id = ?")
                            ->execute([(int)$firstReservation['id'], $householdId]);
                    }
                    book_event($bookId, 'loan_created', 'Verleihung eingetragen', (int)$admin['id'], (int)$copy['id'], [
                        'loan_id' => $loanId, 'borrower_id' => $userId,
                        'borrower_name' => $borrower['display_name'] ?? null,
                        'due_at' => $due->format('Y-m-d H:i:s'),
                    ], 'loan_created:' . $loanId, null, $householdId);

                    $pdo->commit();
                    audit((int)$admin['id'], 'loan_created', 'loan', $loanId, ['book_id' => $bookId, 'user_id' => $userId, 'household_id' => $householdId]);
                    json_response(['ok' => true, 'loan_id' => $loanId], 201);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ($e instanceof RuntimeException) json_response(['ok' => false, 'error' => $e->getMessage()], 409);
                    throw $e;
                }
            }

            case 'loan_return': {
                require_method('POST');
                verify_csrf();
                $admin = require_household_manager();
                $householdId = (int)$admin['active_household_id'];
                $data = json_input();
                $loanId = (int)($data['loan_id'] ?? 0);
                $note = nullable_text($data['return_note'] ?? null, 6000);

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        "SELECT l.*, c.book_id, c.household_id, COALESCE(hbs.title_override,b.title) AS title
                         FROM loans l
                         JOIN copies c ON c.id = l.copy_id
                         JOIN books b ON b.id = c.book_id
                         JOIN household_book_settings hbs ON hbs.household_id=c.household_id AND hbs.book_id=b.id
                         WHERE l.id = ? AND c.household_id = ? FOR UPDATE"
                    );
                    $stmt->execute([$loanId, $householdId]);
                    $loan = $stmt->fetch();
                    if (!$loan) throw new RuntimeException('Verleihung nicht gefunden.');
                    if ($loan['returned_at'] !== null) throw new RuntimeException('Diese Verleihung wurde bereits zurückgegeben.');

                    $pdo->prepare("UPDATE loans SET returned_at = NOW(), return_note = ? WHERE id = ?")
                        ->execute([$note, $loanId]);
                    $stmt = $pdo->prepare(
                        "SELECT r.*, u.email, u.display_name FROM reservations r
                         JOIN users u ON u.id = r.user_id
                         WHERE r.household_id = ? AND r.book_id = ? AND r.status = 'active' AND u.active = 1
                         ORDER BY r.created_at ASC, r.id ASC LIMIT 1 FOR UPDATE"
                    );
                    $stmt->execute([$householdId, (int)$loan['book_id']]);
                    $reservation = $stmt->fetch();
                    if ($reservation) {
                        $pdo->prepare("UPDATE copies SET status = 'reserved' WHERE id = ? AND household_id = ?")
                            ->execute([(int)$loan['copy_id'], $householdId]);
                        $pdo->prepare("UPDATE reservations SET notified_at = NOW() WHERE id = ?")
                            ->execute([(int)$reservation['id']]);
                        $body = "Hallo " . $reservation['display_name'] . ",

";
                        $body .= "das vorgemerkte Buch ist jetzt verfügbar:

" . $loan['title'] . "

";
                        $body .= "Bitte melde dich zur Ausleihe.

Viele Grüße
" . MAIL_FROM_NAME;
                        queue_email((string)$reservation['email'], 'Vormerkung verfügbar: ' . $loan['title'], $body);
                    } else {
                        $pdo->prepare("UPDATE copies SET status = 'available' WHERE id = ? AND household_id = ?")
                            ->execute([(int)$loan['copy_id'], $householdId]);
                    }
                    book_event((int)$loan['book_id'], 'loan_returned', 'Rückgabe eingetragen', (int)$admin['id'], (int)$loan['copy_id'], [
                        'loan_id' => $loanId, 'return_note' => $note, 'reserved_for_next' => (bool)$reservation,
                    ], 'loan_returned:' . $loanId, null, $householdId);
                    $pdo->commit();
                    audit((int)$admin['id'], 'loan_returned', 'loan', $loanId, ['reservation_waiting' => (bool)$reservation, 'household_id' => $householdId]);
                    json_response(['ok' => true, 'reserved_for_next' => (bool)$reservation]);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ($e instanceof RuntimeException) json_response(['ok' => false, 'error' => $e->getMessage()], 409);
                    throw $e;
                }
            }

            case 'reservations': {
                $user = require_household_access();
                $householdId = (int)$user['active_household_id'];
                $canManage = !empty($user['can_manage_household']);
                $where = ['r.household_id = ?'];
                $params = [$householdId];
                if (!$canManage) {
                    $where[] = 'r.user_id = ?';
                    $params[] = (int)$user['id'];
                }
                $stmt = db()->prepare(
                    "SELECT r.*, u.display_name, u.email,
                        COALESCE(hbs.title_override,b.title) AS title,
                        COALESCE(hbs.authors_override,b.authors) AS authors,
                        b.isbn13, COALESCE(sc.local_path,b.cover_path) AS cover_path, b.cover_url,
                        (SELECT COUNT(*) FROM reservations x
                         WHERE x.household_id = r.household_id AND x.book_id = r.book_id AND x.status = 'active'
                           AND (x.created_at < r.created_at OR (x.created_at = r.created_at AND x.id <= r.id))) AS queue_position
                     FROM reservations r
                     JOIN users u ON u.id = r.user_id
                     JOIN books b ON b.id = r.book_id
                     JOIN household_book_settings hbs ON hbs.household_id=r.household_id AND hbs.book_id=b.id
                     LEFT JOIN book_covers sc ON sc.id=COALESCE(hbs.selected_cover_id,b.selected_cover_id) AND sc.book_id=b.id
                     WHERE " . implode(' AND ', $where) . "
                     ORDER BY (r.status = 'active') DESC, r.created_at DESC LIMIT 300"
                );
                $stmt->execute($params);
                $reservations = $stmt->fetchAll();
                foreach ($reservations as &$row) {
                    foreach (['id', 'book_id', 'user_id', 'queue_position', 'household_id'] as $key) $row[$key] = (int)$row[$key];
                    $row['cover'] = $row['cover_path'] ?: $row['cover_url'];
                    if (!$canManage) $row['email'] = null;
                }
                json_response(['ok' => true, 'reservations' => $reservations, 'can_manage' => $canManage]);
            }

            case 'reservation_create': {
                require_method('POST');
                verify_csrf();
                $user = require_household_access();
                $householdId = (int)$user['active_household_id'];
                $canManage = !empty($user['can_manage_household']);
                $data = json_input();
                $bookId = (int)($data['book_id'] ?? 0);
                $targetUserId = $canManage && !empty($data['user_id']) ? (int)$data['user_id'] : (int)$user['id'];
                $book = get_book($bookId, $householdId);
                if (!$book) json_response(['ok' => false, 'error' => 'Buch nicht gefunden.'], 404);
                if (!$canManage && ($book['visibility'] ?? '') !== 'lendable') {
                    json_response(['ok' => false, 'error' => 'Dieses Buch ist sichtbar, aber nicht zur Ausleihe freigegeben.'], 403);
                }
                $stmt = db()->prepare("SELECT active FROM users WHERE id = ?");
                $stmt->execute([$targetUserId]);
                if (!(int)$stmt->fetchColumn()) json_response(['ok' => false, 'error' => 'Benutzer nicht gefunden oder inaktiv.'], 404);
                if ($canManage && $targetUserId !== (int)$user['id']) {
                    $stmt = db()->prepare(
                        "SELECT 1 FROM household_access_grants WHERE owner_household_id=? AND viewer_user_id=? AND active=1
                         UNION SELECT 1 FROM household_members WHERE household_id=? AND user_id=? AND active=1 LIMIT 1"
                    );
                    $stmt->execute([$householdId, $targetUserId, $householdId, $targetUserId]);
                    if (!$stmt->fetchColumn()) json_response(['ok' => false, 'error' => 'Dieser Benutzer hat keinen Zugriff auf den Haushalt.'], 403);
                }
                $stmt = db()->prepare("SELECT id FROM reservations WHERE household_id=? AND book_id=? AND user_id=? AND status='active' LIMIT 1");
                $stmt->execute([$householdId, $bookId, $targetUserId]);
                if ($stmt->fetchColumn()) json_response(['ok' => false, 'error' => 'Für dieses Buch besteht bereits eine Vormerkung.'], 409);

                $stmt = db()->prepare("INSERT INTO reservations (household_id, book_id, user_id, status) VALUES (?, ?, ?, 'active')");
                $stmt->execute([$householdId, $bookId, $targetUserId]);
                $id = (int)db()->lastInsertId();
                book_event($bookId, 'reservation_created', 'Vormerkung eingetragen', (int)$user['id'], null, [
                    'reservation_id' => $id, 'user_id' => $targetUserId,
                ], null, null, $householdId);
                audit((int)$user['id'], 'reservation_created', 'reservation', $id, ['book_id' => $bookId, 'user_id' => $targetUserId, 'household_id' => $householdId]);
                json_response(['ok' => true, 'id' => $id], 201);
            }

            case 'reservation_cancel': {
                require_method('POST');
                verify_csrf();
                $user = require_household_access();
                $householdId = (int)$user['active_household_id'];
                $canManage = !empty($user['can_manage_household']);
                $data = json_input();
                $id = (int)($data['id'] ?? 0);

                $stmt = db()->prepare("SELECT * FROM reservations WHERE id = ? AND household_id = ?");
                $stmt->execute([$id, $householdId]);
                $reservation = $stmt->fetch();
                if (!$reservation) json_response(['ok' => false, 'error' => 'Vormerkung nicht gefunden.'], 404);
                if (!$canManage && (int)$reservation['user_id'] !== (int)$user['id']) json_response(['ok' => false, 'error' => 'Keine Berechtigung.'], 403);
                if ($reservation['status'] !== 'active') json_response(['ok' => false, 'error' => 'Nur aktive Vormerkungen können storniert werden.'], 409);

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE reservations SET status='cancelled', cancelled_at=NOW() WHERE id=? AND household_id=?")
                        ->execute([$id, $householdId]);
                    $stmt = $pdo->prepare("SELECT id FROM copies WHERE household_id=? AND book_id=? AND status='reserved' AND deleted_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE");
                    $stmt->execute([$householdId, (int)$reservation['book_id']]);
                    $reservedCopyId = $stmt->fetchColumn();
                    if ($reservedCopyId) {
                        $next = active_reservation_for_book((int)$reservation['book_id'], $householdId);
                        if ($next) {
                            $pdo->prepare("UPDATE reservations SET notified_at=NOW() WHERE id=?")->execute([(int)$next['id']]);
                            $book = get_book((int)$reservation['book_id'], $householdId);
                            $body = "Hallo " . $next['display_name'] . ",

";
                            $body .= "das vorgemerkte Buch ist jetzt verfügbar:

" . ($book['title'] ?? 'Buch') . "

";
                            $body .= "Bitte melde dich zur Ausleihe.

Viele Grüße
" . MAIL_FROM_NAME;
                            queue_email((string)$next['email'], 'Vormerkung verfügbar: ' . ($book['title'] ?? 'Buch'), $body);
                        } else {
                            $pdo->prepare("UPDATE copies SET status='available' WHERE id=? AND household_id=?")
                                ->execute([(int)$reservedCopyId, $householdId]);
                        }
                    }
                    book_event((int)$reservation['book_id'], 'reservation_cancelled', 'Vormerkung storniert', (int)$user['id'], null, [
                        'reservation_id' => $id, 'user_id' => (int)$reservation['user_id'],
                    ], null, null, $householdId);
                    $pdo->commit();
                    audit((int)$user['id'], 'reservation_cancelled', 'reservation', $id, ['household_id' => $householdId]);
                    json_response(['ok' => true]);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }

            case 'sharing': {
                $user = require_household_manager();
                $householdId = (int)$user['active_household_id'];
                $stmt = db()->prepare("SELECT owner_user_id FROM households WHERE id=?");
                $stmt->execute([$householdId]);
                $householdOwnerId = (int)$stmt->fetchColumn();

                $stmt = db()->prepare(
                    "SELECT sl.*,
                        (SELECT COUNT(*) FROM public_share_access_log al WHERE al.share_link_id=sl.id) AS log_count
                     FROM public_share_links sl WHERE sl.household_id=? ORDER BY sl.created_at DESC LIMIT 200"
                );
                $stmt->execute([$householdId]);
                $links = $stmt->fetchAll();
                foreach ($links as &$link) {
                    $link['id'] = (int)$link['id'];
                    $link['access_count'] = (int)$link['access_count'];
                    $link['log_count'] = (int)$link['log_count'];
                    $link['active'] = empty($link['revoked_at']) && strtotime((string)$link['expires_at']) > time();
                    $link['url'] = base_url() . '?share=' . rawurlencode(public_share_token((int)$link['id'], (string)$link['created_at']));
                }
                $stmt = db()->prepare(
                    "SELECT al.id, al.share_link_id, al.accessed_at, al.user_agent, al.referrer, sl.description
                     FROM public_share_access_log al JOIN public_share_links sl ON sl.id=al.share_link_id
                     WHERE sl.household_id=? ORDER BY al.accessed_at DESC LIMIT 300"
                );
                $stmt->execute([$householdId]);
                $logs = $stmt->fetchAll();
                foreach ($logs as &$log) { $log['id']=(int)$log['id']; $log['share_link_id']=(int)$log['share_link_id']; }

                $stmt = db()->prepare(
                    "SELECT k.id,k.key_hint,k.key_ciphertext,k.note,k.created_at,k.redeemed_at,k.redeemed_by,k.revoked_at,k.active,
                            u.display_name AS redeemed_by_name,u.email AS redeemed_by_email
                     FROM household_access_keys k LEFT JOIN users u ON u.id=k.redeemed_by
                     WHERE k.household_id=? ORDER BY k.created_at DESC LIMIT 200"
                );
                $stmt->execute([$householdId]);
                $keys = $stmt->fetchAll();
                foreach ($keys as &$key) {
                    $key['id'] = (int)$key['id'];
                    $key['active'] = (bool)$key['active'];
                    $key['key'] = decrypt_access_key($key['key_ciphertext'] ?? null);
                    unset($key['key_ciphertext']);
                    $key['status'] = !empty($key['redeemed_at']) ? 'redeemed'
                        : (!empty($key['revoked_at']) || !$key['active'] ? 'revoked' : 'unused');
                    $key['can_delete'] = $key['status'] === 'unused';
                }

                $stmt = db()->prepare(
                    "SELECT g.id,g.viewer_user_id,g.access_key_id,g.created_at,g.paused_at,g.revoked_at,g.active,
                            u.display_name,u.email,
                            EXISTS(
                                SELECT 1 FROM households rh
                                JOIN household_access_grants rg ON rg.owner_household_id=rh.id
                                WHERE rh.owner_user_id=g.viewer_user_id AND rg.viewer_user_id=? AND rg.revoked_at IS NULL
                            ) AS bilateral,
                            EXISTS(
                                SELECT 1 FROM households rh2
                                JOIN household_access_grants rg2 ON rg2.owner_household_id=rh2.id
                                WHERE rh2.owner_user_id=g.viewer_user_id AND rg2.viewer_user_id=?
                                  AND rg2.active=1 AND rg2.revoked_at IS NULL AND rg2.paused_at IS NULL
                            ) AS reverse_active
                     FROM household_access_grants g JOIN users u ON u.id=g.viewer_user_id
                     WHERE g.owner_household_id=? ORDER BY (g.revoked_at IS NULL) DESC,g.active DESC,u.display_name"
                );
                $stmt->execute([$householdOwnerId, $householdOwnerId, $householdId]);
                $grants = $stmt->fetchAll();
                foreach ($grants as &$grant) {
                    $grant['id']=(int)$grant['id'];
                    $grant['viewer_user_id']=(int)$grant['viewer_user_id'];
                    $grant['active']=(bool)$grant['active'];
                    $grant['bilateral']=(bool)$grant['bilateral'];
                    $grant['reverse_active']=(bool)$grant['reverse_active'];
                    $grant['status']=access_grant_status($grant);
                }

                // Freigaben, die der aktuelle Benutzer von anderen Haushalten erhalten hat.
                // Von hier kann der Gegen-Zugriff ohne zweiten Schlüssel eingerichtet werden.
                $stmt = db()->prepare(
                    "SELECT g.id,g.owner_household_id,g.created_at,g.paused_at,g.revoked_at,g.active,
                            h.name AS household_name,h.owner_user_id,u.display_name AS owner_name,u.email AS owner_email,
                            (SELECT rg.id FROM household_access_grants rg
                             WHERE rg.owner_household_id=? AND rg.viewer_user_id=h.owner_user_id AND rg.revoked_at IS NULL
                             ORDER BY rg.active DESC,rg.id DESC LIMIT 1) AS reverse_grant_id,
                            (SELECT rg.active FROM household_access_grants rg
                             WHERE rg.owner_household_id=? AND rg.viewer_user_id=h.owner_user_id AND rg.revoked_at IS NULL
                             ORDER BY rg.active DESC,rg.id DESC LIMIT 1) AS reverse_active
                     FROM household_access_grants g
                     JOIN households h ON h.id=g.owner_household_id
                     JOIN users u ON u.id=h.owner_user_id
                     WHERE g.viewer_user_id=? AND g.revoked_at IS NULL
                     ORDER BY g.active DESC,h.name"
                );
                $stmt->execute([$householdId,$householdId,(int)$user['id']]);
                $incoming = $stmt->fetchAll();
                foreach ($incoming as &$grant) {
                    $grant['id']=(int)$grant['id'];
                    $grant['owner_household_id']=(int)$grant['owner_household_id'];
                    $grant['active']=(bool)$grant['active'];
                    $grant['reverse_grant_id']=$grant['reverse_grant_id']!==null?(int)$grant['reverse_grant_id']:null;
                    $grant['reverse_active']=!empty($grant['reverse_active']);
                    $grant['status']=access_grant_status($grant);
                    $grant['bilateral']=$grant['reverse_grant_id']!==null;
                    $grant['can_reciprocate']=$grant['reverse_grant_id']===null;
                }
                json_response(['ok'=>true,'links'=>$links,'logs'=>$logs,'keys'=>$keys,'grants'=>$grants,'incoming_grants'=>$incoming]);
            }

            case 'share_link_create': {
                require_method('POST'); verify_csrf();
                $user=require_household_manager(); $householdId=(int)$user['active_household_id']; $data=json_input();
                $description=nullable_text($data['description']??null,500);
                $days=max(1,min(365,(int)($data['days']??DEFAULT_PUBLIC_SHARE_DAYS)));
                $expires=(new DateTimeImmutable('now'))->modify('+' . $days . ' days')->setTime(23,59,59);
                $stmt=db()->prepare("INSERT INTO public_share_links (household_id,description,expires_at,created_by) VALUES (?,?,?,?)");
                $stmt->execute([$householdId,$description,$expires->format('Y-m-d H:i:s'),(int)$user['id']]);
                $id=(int)db()->lastInsertId();
                $stmt=db()->prepare("SELECT created_at FROM public_share_links WHERE id=?"); $stmt->execute([$id]); $created=(string)$stmt->fetchColumn();
                $url=base_url().'?share='.rawurlencode(public_share_token($id,$created));
                audit((int)$user['id'],'public_share_created','public_share_link',$id,['household_id'=>$householdId,'expires_at'=>$expires->format('Y-m-d H:i:s')]);
                json_response(['ok'=>true,'id'=>$id,'url'=>$url,'expires_at'=>$expires->format('Y-m-d H:i:s')],201);
            }

            case 'share_link_revoke': {
                require_method('POST'); verify_csrf();
                $user=require_household_manager(); $householdId=(int)$user['active_household_id']; $data=json_input(); $id=(int)($data['id']??0);
                $stmt=db()->prepare("UPDATE public_share_links SET revoked_at=COALESCE(revoked_at,NOW()) WHERE id=? AND household_id=?");
                $stmt->execute([$id,$householdId]);
                if(!$stmt->rowCount()) json_response(['ok'=>false,'error'=>'Freigabelink nicht gefunden oder bereits widerrufen.'],404);
                audit((int)$user['id'],'public_share_revoked','public_share_link',$id,['household_id'=>$householdId]);
                json_response(['ok'=>true]);
            }

            case 'access_key_generate': {
                require_method('POST'); verify_csrf();
                $user=require_household_manager(); $householdId=(int)$user['active_household_id']; $data=json_input();
                $note=nullable_text($data['note']??null,500);
                $plain=generate_access_key(); $normalized=normalize_access_key($plain); $hash=hash('sha256',$normalized); $hint=substr($plain,-9);
                $ciphertext=encrypt_access_key($plain);
                $stmt=db()->prepare("INSERT INTO household_access_keys (household_id,key_hash,key_hint,key_ciphertext,note,created_by) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$householdId,$hash,$hint,$ciphertext,$note,(int)$user['id']]); $id=(int)db()->lastInsertId();
                audit((int)$user['id'],'access_key_created','household_access_key',$id,['household_id'=>$householdId,'note'=>$note]);
                json_response(['ok'=>true,'id'=>$id,'key'=>$plain],201);
            }

            case 'access_key_delete': {
                require_method('POST'); verify_csrf();
                $user=require_household_manager(); $householdId=(int)$user['active_household_id']; $data=json_input(); $id=(int)($data['id']??0);
                $stmt=db()->prepare("DELETE FROM household_access_keys WHERE id=? AND household_id=? AND redeemed_at IS NULL AND active=1 AND revoked_at IS NULL");
                $stmt->execute([$id,$householdId]);
                if(!$stmt->rowCount()) json_response(['ok'=>false,'error'=>'Nur noch nicht eingelöste Zugriffsschlüssel können gelöscht werden.'],409);
                audit((int)$user['id'],'access_key_deleted','household_access_key',$id,['household_id'=>$householdId]);
                json_response(['ok'=>true]);
            }

            // Kompatibilität mit der vorigen Oberfläche: unbenutzte Schlüssel werden ebenfalls gelöscht.
            case 'access_key_revoke': {
                require_method('POST'); verify_csrf();
                $user=require_household_manager(); $householdId=(int)$user['active_household_id']; $data=json_input(); $id=(int)($data['id']??0);
                $stmt=db()->prepare("DELETE FROM household_access_keys WHERE id=? AND household_id=? AND redeemed_at IS NULL");
                $stmt->execute([$id,$householdId]);
                if(!$stmt->rowCount()) json_response(['ok'=>false,'error'=>'Zugriffsschlüssel nicht gefunden oder bereits eingelöst.'],404);
                json_response(['ok'=>true]);
            }

            case 'access_key_redeem': {
                require_method('POST'); verify_csrf();
                $user=require_login(); $data=json_input();
                $plain=clean_text($data['key']??'',40); $normalized=normalize_access_key($plain);
                if ($normalized === '') json_response(['ok'=>false,'error'=>'Der Zugriffsschlüssel hat nicht das erwartete Format TRI-XXXX-XXXX-XXXX.'],422);
                $hash=hash('sha256',$normalized); $pdo=db(); $pdo->beginTransaction();
                try {
                    $stmt=$pdo->prepare(
                        "SELECT k.*,h.name,h.owner_user_id FROM household_access_keys k JOIN households h ON h.id=k.household_id
                         WHERE k.key_hash=? AND k.active=1 AND k.redeemed_at IS NULL AND k.revoked_at IS NULL AND h.active=1 LIMIT 1 FOR UPDATE"
                    );
                    $stmt->execute([$hash]); $key=$stmt->fetch();
                    if(!$key) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Der Zugriffsschlüssel ist ungültig, bereits eingelöst oder gelöscht.'],404); }
                    if((int)$key['owner_user_id']===(int)$user['id']) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Das ist der Schlüssel deines eigenen Haushalts.'],409); }
                    $pdo->prepare(
                        "INSERT INTO household_access_grants (owner_household_id,viewer_user_id,access_key_id,active,paused_at,paused_by,revoked_at,revoked_by)
                         VALUES (?,?,?,1,NULL,NULL,NULL,NULL)
                         ON DUPLICATE KEY UPDATE access_key_id=VALUES(access_key_id),active=1,paused_at=NULL,paused_by=NULL,revoked_at=NULL,revoked_by=NULL"
                    )->execute([(int)$key['household_id'],(int)$user['id'],(int)$key['id']]);
                    $pdo->prepare("UPDATE household_access_keys SET active=0,redeemed_at=NOW(),redeemed_by=? WHERE id=?")
                        ->execute([(int)$user['id'],(int)$key['id']]);
                    $pdo->commit();
                    audit((int)$user['id'],'access_key_redeemed','household',(int)$key['household_id'],['access_key_id'=>(int)$key['id']]);
                    json_response(['ok'=>true,'household_id'=>(int)$key['household_id'],'household_name'=>$key['name']]);
                } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
            }

            case 'access_grant_pause': {
                require_method('POST'); verify_csrf();
                $user=require_household_manager(); $householdId=(int)$user['active_household_id']; $data=json_input(); $id=(int)($data['id']??0);
                $stmt=db()->prepare("UPDATE household_access_grants SET active=0,paused_at=NOW(),paused_by=?,revoked_at=NULL,revoked_by=NULL WHERE id=? AND owner_household_id=? AND revoked_at IS NULL AND active=1");
                $stmt->execute([(int)$user['id'],$id,$householdId]);
                if(!$stmt->rowCount()) json_response(['ok'=>false,'error'=>'Aktive Freigabe nicht gefunden.'],404);
                audit((int)$user['id'],'access_grant_paused','household_access_grant',$id,['household_id'=>$householdId]); json_response(['ok'=>true]);
            }

            case 'access_grant_resume': {
                require_method('POST'); verify_csrf();
                $user=require_household_manager(); $householdId=(int)$user['active_household_id']; $data=json_input(); $id=(int)($data['id']??0);
                $stmt=db()->prepare("UPDATE household_access_grants SET active=1,paused_at=NULL,paused_by=NULL,revoked_at=NULL,revoked_by=NULL WHERE id=? AND owner_household_id=? AND revoked_at IS NULL AND active=0");
                $stmt->execute([$id,$householdId]);
                if(!$stmt->rowCount()) json_response(['ok'=>false,'error'=>'Pausierte Freigabe nicht gefunden.'],404);
                audit((int)$user['id'],'access_grant_resumed','household_access_grant',$id,['household_id'=>$householdId]); json_response(['ok'=>true]);
            }

            case 'access_grant_revoke': {
                require_method('POST'); verify_csrf();
                $user=require_household_manager(); $householdId=(int)$user['active_household_id']; $data=json_input(); $id=(int)($data['id']??0);
                $stmt=db()->prepare("UPDATE household_access_grants SET active=0,paused_at=NULL,paused_by=NULL,revoked_at=NOW(),revoked_by=? WHERE id=? AND owner_household_id=? AND revoked_at IS NULL");
                $stmt->execute([(int)$user['id'],$id,$householdId]);
                if(!$stmt->rowCount()) json_response(['ok'=>false,'error'=>'Freigabe nicht gefunden oder bereits entzogen.'],404);
                audit((int)$user['id'],'access_grant_revoked','household_access_grant',$id,['household_id'=>$householdId]); json_response(['ok'=>true]);
            }

            case 'access_grant_reciprocate': {
                require_method('POST'); verify_csrf();
                $user=require_household_manager(); $householdId=(int)$user['active_household_id']; $data=json_input(); $incomingId=(int)($data['id']??0);
                $stmt=db()->prepare(
                    "SELECT g.id,h.owner_user_id,h.name FROM household_access_grants g
                     JOIN households h ON h.id=g.owner_household_id
                     WHERE g.id=? AND g.viewer_user_id=? AND g.revoked_at IS NULL LIMIT 1"
                );
                $stmt->execute([$incomingId,(int)$user['id']]); $incoming=$stmt->fetch();
                if(!$incoming) json_response(['ok'=>false,'error'=>'Die empfangene Freigabe wurde nicht gefunden.'],404);
                $targetUserId=(int)$incoming['owner_user_id'];
                if($targetUserId===(int)$user['id']) json_response(['ok'=>false,'error'=>'Der Gegen-Zugriff ist für den eigenen Benutzer nicht erforderlich.'],409);
                db()->prepare(
                    "INSERT INTO household_access_grants (owner_household_id,viewer_user_id,access_key_id,active,paused_at,paused_by,revoked_at,revoked_by)
                     VALUES (?,?,NULL,1,NULL,NULL,NULL,NULL)
                     ON DUPLICATE KEY UPDATE active=1,paused_at=NULL,paused_by=NULL,revoked_at=NULL,revoked_by=NULL"
                )->execute([$householdId,$targetUserId]);
                audit((int)$user['id'],'access_grant_reciprocated','household_access_grant',$incomingId,['household_id'=>$householdId,'viewer_user_id'=>$targetUserId]);
                json_response(['ok'=>true,'granted_to'=>$incoming['name']]);
            }

            case 'public_share_bootstrap': {
                $token=clean_text($_GET['token']??'',500); $share=resolve_public_share($token,true);
                if(!$share) json_response(['ok'=>false,'error'=>'Dieser Freigabelink ist ungültig, abgelaufen oder wurde widerrufen.'],404);
                json_response(['ok'=>true,'share'=>['household_name'=>$share['household_name'],'description'=>$share['description'],'expires_at'=>$share['expires_at']]]);
            }

            case 'public_share_books': {
                $token=clean_text($_GET['token']??'',500); $share=resolve_public_share($token,false);
                if(!$share) json_response(['ok'=>false,'error'=>'Dieser Freigabelink ist ungültig, abgelaufen oder wurde widerrufen.'],404);
                $householdId=(int)$share['household_id']; $q=clean_text($_GET['q']??'',200); $params=[$householdId];
                $where=["hbs.household_id=?","hbs.archived_at IS NULL","hbs.visibility IN ('visible','lendable')","EXISTS(SELECT 1 FROM copies cx WHERE cx.household_id=hbs.household_id AND cx.book_id=b.id AND cx.deleted_at IS NULL)"];
                $baseWhere=$where; $baseParams=$params;
                if($q!==''){ $needle='%'.$q.'%'; $where[]="(COALESCE(hbs.title_override,b.title) LIKE ? OR COALESCE(hbs.authors_override,b.authors) LIKE ? OR COALESCE(hbs.publisher_override,b.publisher) LIKE ? OR COALESCE(hbs.published_date_override,b.published_date) LIKE ? OR b.isbn13 LIKE ?)"; for($i=0;$i<5;$i++)$params[]=$needle; }
                $countFrom=" FROM household_book_settings hbs JOIN books b ON b.id=hbs.book_id";
                $baseCountStmt=db()->prepare("SELECT COUNT(*)".$countFrom." WHERE ".implode(' AND ',$baseWhere)); $baseCountStmt->execute($baseParams);
                $filteredCountStmt=db()->prepare("SELECT COUNT(*)".$countFrom." WHERE ".implode(' AND ',$where)); $filteredCountStmt->execute($params);
                $publicStats=['total'=>(int)$baseCountStmt->fetchColumn(),'found'=>(int)$filteredCountStmt->fetchColumn(),'shown'=>0];
                $sql="SELECT b.id,b.isbn13,COALESCE(hbs.title_override,b.title) title,COALESCE(hbs.subtitle_override,b.subtitle) subtitle,
                    COALESCE(hbs.authors_override,b.authors) authors,COALESCE(hbs.publisher_override,b.publisher) publisher,
                    COALESCE(hbs.published_date_override,b.published_date) published_date,COALESCE(hbs.description_override,b.description) description,
                    COALESCE(sc.local_path,b.cover_path) cover_path,b.cover_url,hbs.visibility,
                    COUNT(CASE WHEN c.deleted_at IS NULL THEN c.id END) copies_total,
                    COALESCE(SUM(c.deleted_at IS NULL AND c.status='available'),0) copies_available
                    FROM household_book_settings hbs JOIN books b ON b.id=hbs.book_id
                    LEFT JOIN book_covers sc ON sc.id=COALESCE(hbs.selected_cover_id,b.selected_cover_id) AND sc.book_id=b.id
                    LEFT JOIN copies c ON c.book_id=b.id AND c.household_id=hbs.household_id
                    WHERE ".implode(' AND ',$where)." GROUP BY b.id,hbs.id,sc.id ORDER BY COALESCE(hbs.title_override,b.title) LIMIT 250";
                $stmt=db()->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
                foreach($rows as &$row){ $row['id']=(int)$row['id']; $row['copies_total']=(int)$row['copies_total']; $row['copies_available']=(int)$row['copies_available']; $row['cover']=$row['cover_path']?:$row['cover_url']; }
                $publicStats['shown']=count($rows);
                json_response(['ok'=>true,'books'=>$rows,'stats'=>$publicStats]);
            }

            case 'public_share_book': {
                $token=clean_text($_GET['token']??'',500); $share=resolve_public_share($token,false);
                if(!$share) json_response(['ok'=>false,'error'=>'Dieser Freigabelink ist ungültig, abgelaufen oder wurde widerrufen.'],404);
                $householdId=(int)$share['household_id']; $id=(int)($_GET['id']??0);
                $stmt=db()->prepare(
                    "SELECT b.id,b.isbn13,b.isbn10,COALESCE(hbs.title_override,b.title) title,COALESCE(hbs.subtitle_override,b.subtitle) subtitle,
                        COALESCE(hbs.authors_override,b.authors) authors,COALESCE(hbs.publisher_override,b.publisher) publisher,
                        COALESCE(hbs.published_date_override,b.published_date) published_date,COALESCE(hbs.description_override,b.description) description,
                        COALESCE(hbs.page_count_override,b.page_count) page_count,COALESCE(hbs.categories_override,b.categories) categories,
                        COALESCE(hbs.language_override,b.language) language,COALESCE(sc.local_path,b.cover_path) cover_path,b.cover_url,hbs.visibility,
                        COUNT(CASE WHEN c.deleted_at IS NULL THEN c.id END) copies_total,
                        COALESCE(SUM(c.deleted_at IS NULL AND c.status='available'),0) copies_available
                     FROM household_book_settings hbs JOIN books b ON b.id=hbs.book_id
                     LEFT JOIN book_covers sc ON sc.id=COALESCE(hbs.selected_cover_id,b.selected_cover_id) AND sc.book_id=b.id
                     LEFT JOIN copies c ON c.book_id=b.id AND c.household_id=hbs.household_id
                     WHERE hbs.household_id=? AND b.id=? AND hbs.archived_at IS NULL AND hbs.visibility IN ('visible','lendable')
                       AND EXISTS(SELECT 1 FROM copies cx WHERE cx.household_id=? AND cx.book_id=b.id AND cx.deleted_at IS NULL)
                     GROUP BY b.id,hbs.id,sc.id"
                );
                $stmt->execute([$householdId,$id,$householdId]); $book=$stmt->fetch();
                if(!$book) json_response(['ok'=>false,'error'=>'Buch nicht gefunden oder nicht freigegeben.'],404);
                $book['id']=(int)$book['id']; $book['page_count']=$book['page_count']!==null?(int)$book['page_count']:null; $book['copies_total']=(int)$book['copies_total']; $book['copies_available']=(int)$book['copies_available']; $book['cover']=$book['cover_path']?:$book['cover_url'];
                $files = book_files_for_view($id, $householdId, false);
                json_response(['ok'=>true,'book'=>$book,'files'=>$files]);
            }

            case 'backup_export': {
                $user = require_household_access(false);
                $scope = (string)($_GET['scope'] ?? 'household');
                $backup = create_backup_payload($scope, $user);
                $filename = 'triamo-' . ($backup['scope'] === 'all' ? 'system' : 'haushalt') . '-' . date('Ymd-His') . '.json';
                close_session_lock();
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Cache-Control: no-store');
                echo json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                exit;
            }

            case 'backup_restore': {
                require_method('POST');
                verify_csrf();
                $user = require_household_manager();
                $data = json_input();
                $raw = (string)($data['backup_json'] ?? '');
                if (strlen($raw) > 30 * 1024 * 1024) {
                    json_response(['ok' => false, 'error' => 'Die Backupdatei ist größer als 30 MB.'], 413);
                }
                $backup = json_decode($raw, true);
                if (!is_array($backup)) {
                    json_response(['ok' => false, 'error' => 'Die Backupdatei konnte nicht als JSON gelesen werden.'], 422);
                }
                $result = restore_backup_payload($backup, $user);
                json_response(['ok' => true, 'restore' => $result]);
            }

            case 'cron': {
                $token = (string)($_GET['token'] ?? '');
                if (CRON_TOKEN === '' || CRON_TOKEN === 'BITTE-EINEN-LANGEN-ZUFAELLIGEN-TOKEN-EINTRAGEN' || !hash_equals(CRON_TOKEN, $token)) {
                    json_response(['ok' => false, 'error' => 'Ungültiger Cron-Token.'], 403);
                }
                close_session_lock();
                ignore_user_abort(true);
                @set_time_limit(55);
                $reminders = enqueue_due_reminders();
                $libraryReminders = enqueue_library_reminders();
                $jobs = process_jobs(3);
                $emails = send_queued_emails(30);
                json_response([
                    'ok' => true,
                    'reminders_enqueued' => $reminders,
                    'library_reminders' => $libraryReminders,
                    'jobs' => $jobs,
                    'emails' => $emails,
                    'time' => date(DATE_ATOM),
                ]);
            }

            default:
                json_response(['ok' => false, 'error' => 'Unbekannte API-Funktion.'], 404);
        }
    } catch (PDOException $e) {
        error_log('Hausbibliothek PDO error: ' . $e->getMessage());
        $message = in_array((string)$e->getCode(), ['1044', '1045', '1049', '2002'], true)
            ? 'Die Datenbankverbindung ist fehlgeschlagen. Bitte die Zugangsdaten oben in der PHP-Datei prüfen.'
            : 'Datenbankfehler. Details stehen im Server-Fehlerprotokoll.';
        json_response(['ok' => false, 'error' => $message], 500);
    } catch (Throwable $e) {
        error_log('Hausbibliothek error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        json_response(['ok' => false, 'error' => 'Interner Fehler. Details stehen im Server-Fehlerprotokoll.'], 500);
    }
}

try {
    db();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $safe = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Datenbankfehler</title><style>body{font-family:system-ui;margin:3rem;max-width:800px;line-height:1.5}code{background:#eee;padding:.2rem .4rem;border-radius:.3rem}</style>';
    $headline = $e instanceof PDOException && in_array((string)$e->getCode(), ['1044', '1045', '1049', '2002'], true)
        ? 'Datenbankverbindung fehlgeschlagen'
        : 'Datenbankeinrichtung fehlgeschlagen';
    $hint = $headline === 'Datenbankverbindung fehlgeschlagen'
        ? 'Bitte passe die vier Datenbankwerte ganz oben in der PHP-Datei an und lege die Datenbank vorher im Hosting an.'
        : 'Die Verbindung steht, aber die automatische Tabellenerstellung oder Migration ist fehlgeschlagen.';
    echo '<h1>' . $headline . '</h1><p>' . $hint . '</p>';
    echo '<p><code>' . $safe . '</code></p></html>';
    exit;
}


// ======================== HTML-OBERFLÄCHE ========================
$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://books.google.com https://books.googleusercontent.com https://covers.openlibrary.org; connect-src 'self'; media-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f766e">
    <title><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
        :root{
            --bg:#f4f7f7;--surface:#ffffff;--surface-2:#eef5f4;--text:#162321;--muted:#60716e;
            --primary:#0f766e;--primary-dark:#0b5e58;--accent:#d97706;--danger:#b42318;--success:#067647;
            --border:#d8e3e1;--shadow:0 14px 34px rgba(17,43,39,.10);--radius:18px;--radius-sm:11px;
            --sidebar:250px;--topbar:68px;
        }
        *{box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        button,input,select,textarea{font:inherit}
        button{cursor:pointer}
        img{max-width:100%;display:block}
        a{color:var(--primary)}
        .hidden{display:none!important}
        .muted{color:var(--muted)}
        .small{font-size:.86rem}
        .nowrap{white-space:nowrap}
        .stack{display:grid;gap:14px}
        .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
        .grow{flex:1 1 auto}
        .spacer{flex:1}
        .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 4px 16px rgba(17,43,39,.04);padding:20px}
        .card h2,.card h3{margin-top:0}
        .btn{border:0;border-radius:11px;padding:10px 15px;background:var(--primary);color:#fff;font-weight:700;min-height:42px;display:inline-flex;align-items:center;justify-content:center;gap:8px}
        .btn:hover{background:var(--primary-dark)}
        .btn.secondary{background:var(--surface-2);color:var(--text);border:1px solid var(--border)}
        .btn.secondary:hover{background:#e4efed}
        .btn.danger{background:var(--danger)}
        .btn.warning{background:var(--accent)}
        .btn.ghost{background:transparent;color:var(--primary);padding:8px}
        .btn.smallbtn{padding:7px 10px;min-height:34px;font-size:.88rem}
        .btn:disabled{opacity:.55;cursor:not-allowed}
        label{font-weight:700;display:grid;gap:6px}
        input,select,textarea{width:100%;border:1px solid #c8d7d4;background:#fff;border-radius:11px;padding:11px 12px;color:var(--text);outline:none;min-height:43px}
        input:focus,select:focus,textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(15,118,110,.12)}
        textarea{min-height:110px;resize:vertical}
        .field-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .field-grid .full{grid-column:1/-1}
        .badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:4px 9px;font-size:.78rem;font-weight:800;background:var(--surface-2);color:var(--muted)}
        .badge.ok{background:#dff6e9;color:var(--success)}
        .badge.warn{background:#fff0d8;color:#9a5b00}
        .badge.bad{background:#fee4e2;color:var(--danger)}
        .badge.info{background:#dceff8;color:#175cd3}
        .empty{padding:34px 20px;text-align:center;color:var(--muted)}
        .iconbtn{border:1px solid var(--border);background:#fff;border-radius:10px;width:42px;height:42px;display:grid;place-items:center;color:var(--text)}
        .auth-shell{min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 10% 10%,#d7efec 0,transparent 34%),radial-gradient(circle at 90% 90%,#fae7c8 0,transparent 30%),var(--bg)}
        .auth-card{width:min(470px,100%);background:rgba(255,255,255,.94);backdrop-filter:blur(10px);padding:30px;border:1px solid var(--border);border-radius:24px;box-shadow:var(--shadow)}
        .brand{display:flex;gap:12px;align-items:center;font-weight:900;font-size:1.25rem}
        .brandmark{width:42px;height:42px;border-radius:13px;background:linear-gradient(145deg,var(--primary),#14b8a6);display:grid;place-items:center;color:#fff;font-size:1.3rem}
        .app-shell{min-height:100vh}
        .sidebar{position:fixed;inset:0 auto 0 0;width:var(--sidebar);background:#103934;color:#eafffb;padding:18px 14px;z-index:40;display:flex;flex-direction:column;gap:18px}
        .sidebar .brand{padding:4px 8px}
        .nav{display:grid;gap:5px}
        .nav button{border:0;background:transparent;color:#c7e5e1;padding:11px 12px;border-radius:11px;text-align:left;font-weight:700;display:flex;align-items:center;gap:10px}
        .nav button:hover,.nav button.active{background:rgba(255,255,255,.12);color:#fff}
        .nav .nav-icon{width:22px;text-align:center}
        .sidebar-footer{margin-top:auto;padding:12px 8px;border-top:1px solid rgba(255,255,255,.15)}
        .sidebar-legal{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;font-size:.82rem}
        .sidebar-legal a,.auth-legal a{color:#d6fffb;text-decoration:none;border-bottom:1px solid rgba(214,255,251,.45)}
        .sidebar-legal a:hover,.auth-legal a:hover{color:#fff;border-color:#fff}
        .auth-legal{margin-top:16px;text-align:center;font-size:.86rem;color:#d6fffb;display:flex;justify-content:center;gap:12px;flex-wrap:wrap}
        .legal-content{max-width:860px}.legal-content h1,.legal-content h2{margin-top:0}.legal-content h2{margin-top:24px}.legal-content ul{padding-left:1.2rem}
        .topbar{position:fixed;left:var(--sidebar);right:0;top:0;height:var(--topbar);background:rgba(255,255,255,.9);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);z-index:30;display:flex;align-items:center;padding:0 24px;gap:14px}
        .topbar h1{font-size:1.15rem;margin:0}
        .main{margin-left:var(--sidebar);padding:calc(var(--topbar) + 24px) 24px 40px;max-width:1600px}
        .view{display:none}
        .view.active{display:block}
        .view-head{display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:18px}
        .view-head h1{margin:0 0 4px;font-size:1.7rem}
        .stats{display:grid;grid-template-columns:repeat(6,minmax(135px,1fr));gap:14px;margin-bottom:18px}
        .stat{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:17px}
        .stat strong{display:block;font-size:1.75rem;line-height:1.1;margin-top:5px}
        .tag-cloud{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .tag-cloud button{border:1px solid var(--border);background:#f8fcfb;border-radius:999px;padding:7px 11px;cursor:pointer;font-weight:800;color:var(--accent)}
        .tag-cloud button:hover{background:#e6f5f2}
        .bar-list{display:grid;gap:9px}.bar-row{display:grid;grid-template-columns:74px 1fr 48px;gap:10px;align-items:center}.bar-track{height:18px;background:#edf5f3;border-radius:999px;overflow:hidden}.bar-fill{height:100%;background:var(--accent);border-radius:999px}.bar-label{font-weight:800}.mini-list{display:grid;gap:7px}.mini-list button{justify-content:flex-start}
        .scan-panel{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(280px,.8fr);gap:18px}
        .scan-input{font-size:1.45rem;font-weight:800;letter-spacing:.04em;padding:16px;min-height:62px}
        .scan-log{display:grid;gap:9px;max-height:480px;overflow:auto}
        .scan-entry{border:1px solid var(--border);border-radius:12px;padding:11px 13px;display:flex;gap:12px;align-items:center;background:#fff}
        .scan-entry.pending{border-style:dashed}
        .scan-entry.error{border-color:#f5aaa4;background:#fff7f6}
        .scan-dot{width:11px;height:11px;border-radius:999px;background:var(--accent);flex:0 0 auto}
        .scan-entry.ok .scan-dot{background:var(--success)}
        .scan-entry.error .scan-dot{background:var(--danger)}
        .toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
        .toolbar .search{min-width:240px;flex:1 1 320px}
        .table-wrap{overflow:auto;border:1px solid var(--border);border-radius:15px;background:#fff}
        table{width:100%;border-collapse:collapse;min-width:760px}
        th,td{text-align:left;padding:12px 13px;border-bottom:1px solid var(--border);vertical-align:top}
        th{font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);background:#f7faf9;position:sticky;top:0;z-index:1}
        tr:last-child td{border-bottom:0}
        .cover{width:46px;height:66px;object-fit:cover;border-radius:7px;background:#e9efee;border:1px solid var(--border)}
        .book-cell{display:flex;gap:12px;min-width:260px}
        .book-title{font-weight:800}
        .book-meta{font-size:.85rem;color:var(--muted)}
        .book-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(255px,1fr));gap:14px}
        .book-card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:14px;display:grid;grid-template-columns:72px 1fr;gap:13px}
        .book-card .cover{width:72px;height:104px}
        .online-section{margin-top:22px}
        .online-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
        .online-card{background:#fff;border:1px solid var(--border);border-radius:14px;padding:13px;display:flex;gap:12px}
        .online-card .cover{width:54px;height:78px}
        .statusline{display:flex;gap:7px;flex-wrap:wrap;margin-top:7px}
        .modal-backdrop{position:fixed;inset:0;background:rgba(8,22,20,.58);z-index:100;display:grid;place-items:center;padding:18px}
        .modal{width:min(1180px,calc(100vw - 28px));max-height:94vh;overflow:hidden;background:#fff;border-radius:20px;border:1px solid var(--border);box-shadow:var(--shadow);display:flex;flex-direction:column}
        .modal-head{display:flex;align-items:center;gap:12px;padding:18px 20px;border-bottom:1px solid var(--border);position:sticky;top:0;background:#fff;z-index:2}
        .modal-head h2{margin:0;font-size:1.25rem}
        .modal-body{padding:20px;overflow:auto;min-height:0}
        .modal-actions{display:flex;justify-content:flex-end;gap:10px;padding:16px 20px;border-top:1px solid var(--border);background:#fff;flex-wrap:wrap;flex:0 0 auto}
        .camera-box{position:relative;background:#111;border-radius:14px;overflow:hidden;aspect-ratio:4/3}
        .camera-box video{width:100%;height:100%;object-fit:cover}
        .camera-line{position:absolute;left:10%;right:10%;top:50%;height:2px;background:#22d3ee;box-shadow:0 0 12px #22d3ee}
        .toast-wrap{position:fixed;right:18px;bottom:18px;z-index:200;display:grid;gap:9px;width:min(390px,calc(100vw - 36px))}
        .toast{background:#153f3a;color:#fff;border-radius:12px;padding:12px 14px;box-shadow:var(--shadow);animation:toastin .2s ease-out}
        .toast.error{background:#7a271a}
        .toast.warn{background:#7a4e0b}
        @keyframes toastin{from{transform:translateY(8px);opacity:0}}
        .mobile-menu{display:none}
        .mobile-cards{display:none}
        .details-grid{display:grid;grid-template-columns:180px 1fr;gap:20px}
        .details-grid .big-cover{width:180px;max-height:270px;object-fit:cover;border-radius:12px;background:#edf2f1}
        .notice{border:1px solid #b9d9d5;background:#eefaf8;padding:12px 14px;border-radius:12px;color:#285e58}
        .notice.warning{border-color:#e0bd70;background:#fff8e8;color:#72500e}
        .danger-note{border-color:#f2b8b5;background:#fff4f3;color:#7a271a}
        .checkbox{display:flex;align-items:center;gap:9px;font-weight:700}
        .checkbox input{width:19px;height:19px;min-height:auto}
        .privacy-ack{display:grid;grid-template-columns:20px minmax(0,1fr);gap:10px;align-items:start;padding:11px 12px;border:1px solid var(--border);border-radius:11px;background:#f8fbfa}
        .privacy-ack input{width:19px;height:19px;min-height:auto;margin:2px 0 0}
        .privacy-ack label{display:block;font-weight:700;line-height:1.45}
        .privacy-ack a{display:inline-block;margin-top:3px;color:var(--primary);font-size:.9rem;font-weight:700}
        .archived-row,.book-card.archived{opacity:.58;filter:grayscale(.35);background:#f2f3f3}
        .archived-row:hover,.book-card.archived:hover{opacity:.78}
        .source-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px}
        .source-card{border:1px solid var(--border);border-radius:14px;padding:14px;background:#fafcfc}
        .source-card h4{margin:0 0 8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .source-fields{display:grid;grid-template-columns:minmax(85px,.45fr) 1fr;gap:5px 10px;font-size:.88rem}
        .source-fields dt{color:var(--muted)}.source-fields dd{margin:0;overflow-wrap:anywhere}
        .timeline{display:grid;gap:0;border-left:2px solid var(--border);margin-left:8px;padding-left:18px}
        .timeline-item{position:relative;padding:0 0 18px}
        .timeline-item:before{content:'';position:absolute;left:-24px;top:5px;width:10px;height:10px;border-radius:50%;background:var(--primary);border:2px solid #fff;box-shadow:0 0 0 1px var(--border)}
        .timeline-item:last-child{padding-bottom:0}
        .library-box{border:1px solid #f2c879;background:#fff8e8;border-radius:13px;padding:12px}
        .field-source{font-size:.72rem;color:var(--muted);font-weight:700;margin-left:5px}
        .table-actions{display:flex;align-items:center;gap:8px;flex-wrap:nowrap;white-space:nowrap}
        .meta-link,.title-link{border:0;background:transparent;padding:0;color:inherit;text-align:left;min-height:auto;font:inherit;cursor:pointer}
        .title-link{font-weight:800}.meta-link{color:var(--muted);text-decoration:underline;text-decoration-style:dotted;text-underline-offset:3px}
        .title-link:hover,.meta-link:hover{color:var(--primary)}
        .active-location{border:2px solid var(--primary);background:#eaf8f6;border-radius:14px;padding:13px 15px}
        .active-location strong{display:block;font-size:1.05rem}
        .location-code{font:600 .82rem/1.3 ui-monospace,SFMono-Regular,Consolas,monospace;color:var(--muted)}
        .cover-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
        .cover-option{border:1px solid var(--border);border-radius:14px;padding:10px;background:#fafcfc;display:grid;gap:8px;align-content:start}
        .cover-option.selected{border:2px solid var(--primary);background:#eefaf8}
        .cover-option img{width:100%;height:190px;object-fit:contain;background:#fff;border-radius:8px}
        .barcode-preview{max-width:300px;width:100%;background:#fff;border:1px solid var(--border);padding:8px;border-radius:10px}
        .location-select-all{margin-bottom:8px}.location-barcode-grid{display:flex;flex-direction:column;gap:8px}.location-barcode-choice{display:grid;grid-template-columns:auto minmax(120px,1fr) minmax(150px,230px);align-items:center;gap:10px;border:1px solid var(--border);border-radius:10px;padding:8px;background:#fff}.location-barcode-choice input{width:18px;height:18px}.location-barcode-choice img{width:100%;height:46px;object-fit:fill;background:#fff}.location-barcode-meta{display:grid;gap:2px;min-width:0}.location-barcode-meta .location-code{font-size:.7rem;overflow-wrap:anywhere}.location-counts{font-size:.75rem;color:var(--muted)}
        .upload-drop-zone{border:2px dashed var(--border);border-radius:14px;padding:18px;text-align:center;background:var(--surface-2);cursor:pointer;transition:.15s ease}.upload-drop-zone:hover,.upload-drop-zone.dragover{border-color:var(--accent);background:var(--accent-soft)}.asset-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}.asset-card{border:1px solid var(--border);border-radius:12px;padding:12px;background:#fff}.asset-card textarea{min-height:72px}.file-name{font-weight:700;overflow-wrap:anywhere}.file-meta{font-size:.78rem;color:var(--muted);margin-top:3px}
        .skeleton{background:linear-gradient(90deg,#edf2f1 25%,#f8faf9 45%,#edf2f1 65%);background-size:200% 100%;animation:shimmer 1.1s infinite;border-radius:9px;min-height:18px}
        .household-switch{min-width:210px;max-width:330px;background:#fff;font-weight:700}
        .public-shell{min-height:100vh;background:var(--bg);padding:24px}
        .public-shell-inner{max-width:1180px;margin:0 auto;display:grid;gap:18px}
        .public-hero{background:linear-gradient(135deg,#0b7168,#174a45);color:#fff;border-radius:24px;padding:26px;box-shadow:var(--shadow)}
        .public-hero h1{margin:0 0 7px;font-size:clamp(1.65rem,4vw,2.5rem)}
        .public-book-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}
        .public-book{display:grid;grid-template-columns:72px 1fr;gap:12px;align-items:start;border:1px solid var(--border);background:#fff;border-radius:16px;padding:13px;cursor:pointer;box-shadow:0 4px 16px rgba(12,57,53,.05)}
        .public-book:hover{border-color:var(--primary);transform:translateY(-1px)}
        .public-book .cover{width:72px;height:108px}
        .share-url{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center}
        .share-url code{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;background:#eef4f3;border-radius:8px;padding:9px}
        .access-key-box{font:800 1.15rem/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.04em;background:#153f3a;color:#fff;padding:16px;border-radius:12px;text-align:center;overflow-wrap:anywhere}
        .visibility-internal{background:#eceff1}.visibility-visible{background:#e8f2ff}.visibility-lendable{background:#ddf7e9}
        @keyframes shimmer{to{background-position:-200% 0}}
        @media(max-width:1180px){.stats{grid-template-columns:repeat(3,minmax(135px,1fr))}.scan-panel{grid-template-columns:1fr}}
        @media(max-width:820px){
            :root{--topbar:60px}
            .sidebar{transform:translateX(-105%);transition:transform .2s ease;width:min(285px,86vw);box-shadow:var(--shadow)}
            .sidebar.open{transform:translateX(0)}
            .topbar{left:0;padding:0 14px}.mobile-menu{display:grid}
            .main{margin-left:0;padding:calc(var(--topbar) + 16px) 14px 28px}
            .field-grid{grid-template-columns:1fr}
            .stats{grid-template-columns:repeat(2,minmax(120px,1fr))}
            .desktop-table{display:none}.mobile-cards{display:grid}
            .details-grid{grid-template-columns:1fr}.details-grid .big-cover{width:140px}.table-actions{flex-wrap:wrap;white-space:normal}
            .location-barcode-choice{grid-template-columns:auto 1fr}.location-barcode-choice img{grid-column:1/-1}
        }
        @media(max-width:480px){
            .stats{grid-template-columns:1fr 1fr;gap:9px}.stat{padding:13px}.stat strong{font-size:1.45rem}
            .card{padding:15px;border-radius:15px}.view-head h1{font-size:1.42rem}
            .btn{width:100%}.row .btn,.toolbar .btn,.modal-actions .btn{width:auto}
            .scan-input{font-size:1.12rem}.auth-card{padding:22px}
            .topbar .user-label{display:none}
        }
    </style>
</head>
<body>
<div id="toastWrap" class="toast-wrap" aria-live="polite"></div>

<div id="publicShareShell" class="public-shell hidden">
    <div class="public-shell-inner">
        <div id="publicShareHero" class="public-hero"></div>
        <div class="card">
            <div class="toolbar"><input id="publicBookSearch" class="search" type="search" placeholder="Freigegebenen Buchbestand durchsuchen"></div>
            <div id="publicBooksStats" class="stats" style="margin:10px 0 14px"></div>
            <div id="publicBooksResults"></div>
        </div>
    </div>
</div>

<div id="authShell" class="auth-shell">
    <div class="auth-card">
        <div class="brand"><span class="brandmark">▦</span><span><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></span></div>
        <p class="muted" id="authIntro">Privater Bücherbestand mit schneller ISBN-Erfassung.</p>

        <form id="setupForm" class="stack hidden">
            <div class="notice">Beim ersten Aufruf wird das Administratorkonto angelegt. Die Datenbanktabellen entstehen automatisch.</div>
            <label>Name<input name="display_name" autocomplete="name" required maxlength="120"></label>
            <label>Haushaltsname<input name="household_name" required maxlength="160" placeholder="z. B. Familie Tschugg"></label>
            <label>E-Mail-Adresse<input name="email" type="email" autocomplete="email" required maxlength="191"></label>
            <label>Passwort<input name="password" type="password" autocomplete="new-password" required minlength="10"></label>
            <div class="privacy-ack">
                <input id="setupPrivacyAcknowledged" name="privacy_notice_acknowledged" type="checkbox" value="1" required>
                <div>
                    <label for="setupPrivacyAcknowledged">Ich habe die Datenschutzerklärung zur Kenntnis genommen.</label>
                    <a href="datenschutz.php" data-legal-link>Datenschutzerklärung öffnen</a>
                </div>
            </div>
            <button class="btn" type="submit">Einrichtung abschließen</button>
        </form>

        <form id="loginForm" class="stack hidden">
            <label>E-Mail-Adresse<input name="email" type="email" autocomplete="username" required></label>
            <label>Passwort<input name="password" type="password" autocomplete="current-password" required></label>
            <button class="btn" type="submit">Anmelden</button>
            <button id="showForgotBtn" class="btn secondary" type="button">Passwort vergessen?</button>
            <button id="showRegisterBtn" class="btn secondary hidden" type="button">Neues Konto registrieren</button>
        </form>

        <form id="forgotForm" class="stack hidden">
            <div class="notice">Gib die E-Mail-Adresse deines Kontos ein. Falls ein aktives Konto besteht, wird ein zeitlich begrenzter Einmallink versendet.</div>
            <label>E-Mail-Adresse<input name="email" type="email" autocomplete="email" required maxlength="191"></label>
            <button class="btn" type="submit">Einmallink anfordern</button>
            <button id="forgotBackBtn" class="btn secondary" type="button">Zur Anmeldung</button>
        </form>

        <form id="resetForm" class="stack hidden">
            <div class="notice">Lege ein neues Passwort mit mindestens 10 Zeichen fest. Der Einmallink kann nur einmal verwendet werden.</div>
            <input name="token" type="hidden">
            <label>Neues Passwort<input name="password" type="password" autocomplete="new-password" required minlength="10"></label>
            <label>Passwort wiederholen<input name="password_confirm" type="password" autocomplete="new-password" required minlength="10"></label>
            <button class="btn" type="submit">Passwort speichern</button>
            <button id="resetBackBtn" class="btn secondary" type="button">Zur Anmeldung</button>
        </form>

        <form id="registerForm" class="stack hidden">
            <div class="notice">Jedes neue Konto erhält einen eigenen, getrennten Buchbestand. Gemeinsame ISBN-Metadaten werden wiederverwendet.</div>
            <label>Name<input name="display_name" autocomplete="name" required maxlength="120"></label>
            <label>Haushaltsname<input name="household_name" required maxlength="160" placeholder="z. B. Mein Haushalt"></label>
            <label>E-Mail-Adresse<input name="email" type="email" autocomplete="email" required maxlength="191"></label>
            <label>Passwort<input name="password" type="password" autocomplete="new-password" required minlength="10"></label>
            <div class="privacy-ack">
                <input id="registerPrivacyAcknowledged" name="privacy_notice_acknowledged" type="checkbox" value="1" required>
                <div>
                    <label for="registerPrivacyAcknowledged">Ich habe die Datenschutzerklärung zur Kenntnis genommen.</label>
                    <a href="datenschutz.php" data-legal-link>Datenschutzerklärung öffnen</a>
                </div>
            </div>
            <button class="btn" type="submit">Registrieren</button>
            <button id="showLoginBtn" class="btn secondary" type="button">Zur Anmeldung</button>
        </form>
    </div>
    <div class="auth-legal"><a href="impressum.php" data-legal-link>Impressum</a><a href="datenschutz.php" data-legal-link>Datenschutz</a></div>
</div>

<div id="appShell" class="app-shell hidden">
    <aside id="sidebar" class="sidebar">
        <div class="brand"><span class="brandmark">▦</span><span><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></span></div>
        <nav class="nav" id="nav">
            <button data-view="dashboard" class="active"><span class="nav-icon">⌂</span>Übersicht</button>
            <button data-view="scan" data-manager><span class="nav-icon">▣</span>Erfassen</button>
            <button data-view="locations" data-manager><span class="nav-icon">⌖</span>Standorte</button>
            <button data-view="books"><span class="nav-icon">▤</span>Bestand</button>
            <button data-view="library" data-manager><span class="nav-icon">⌛</span>Büchereibücher</button>
            <button data-view="loans"><span class="nav-icon">↔</span>Verleihungen</button>
            <button data-view="reservations"><span class="nav-icon">◷</span>Vormerkungen</button>
            <button data-view="sharing" data-manager><span class="nav-icon">⛓</span>Freigaben</button>
            <button data-view="backup" data-manager><span class="nav-icon">⇄</span>Backup</button>
            <button data-view="metadata" data-manager><span class="nav-icon">◌</span>Metadaten</button>
            <button data-view="nerdstats"><span class="nav-icon">▥</span>Statistiken</button>
            <button data-view="users" data-admin><span class="nav-icon">♙</span>Benutzer</button>
            <button data-view="profile"><span class="nav-icon">⚙</span>Profil</button>
        </nav>
        <div class="sidebar-footer">
            <div id="sidebarUser" style="font-weight:800"></div>
            <div id="sidebarRole" class="small" style="color:#a9d5cf"></div>
            <div class="sidebar-legal"><a href="impressum.php" data-legal-link>Impressum</a><a href="datenschutz.php" data-legal-link>Datenschutz</a></div>
        </div>
    </aside>

    <header class="topbar">
        <button id="menuBtn" class="iconbtn mobile-menu" aria-label="Menü öffnen">☰</button>
        <h1 id="topbarTitle">Übersicht</h1>
        <div class="spacer"></div>
        <select id="householdSwitcher" class="household-switch" aria-label="Aktiver Haushalt"></select>
        <span id="topbarUser" class="user-label muted"></span>
        <button id="logoutBtn" class="btn secondary smallbtn">Abmelden</button>
    </header>

    <main class="main">
        <section id="view-dashboard" class="view active">
            <div class="view-head">
                <div><h1>Übersicht</h1><div class="muted">Bestand, Verleihungen und offene Aufgaben auf einen Blick.</div></div>
                <div class="spacer"></div>
                <button class="btn" data-go="scan" data-manager>ISBN erfassen</button>
            </div>
            <div id="stats" class="stats"></div>
            <div class="card">
                <div class="row"><h2 style="margin:0">Aktive Verleihungen</h2><div class="spacer"></div><button class="btn secondary smallbtn" data-go="loans">Alle anzeigen</button></div>
                <div id="dashboardLoans" style="margin-top:14px"></div>
            </div>
        </section>

        <section id="view-scan" class="view">
            <div class="view-head">
                <div><h1>Bücher erfassen</h1><div class="muted">Scanner auf das ISBN-Feld richten und mit Enter abschließen. Der Metadatenabruf läuft im Hintergrund.</div></div>
                <div class="spacer"></div>
                <button id="cameraBtn" class="btn secondary">Kamera verwenden</button>
                <button id="manualBookBtn" class="btn secondary">Manuell erfassen</button>
            </div>
            <div class="scan-panel">
                <div class="card stack">
                    <label>ISBN-Scanner
                        <input id="scanInput" class="scan-input" inputmode="text" autocomplete="off" placeholder="Standort-Barcode oder ISBN scannen" aria-describedby="scanHelp">
                    </label>
                    <div id="scanHelp" class="muted">Zuerst kann ein Standort-Barcode gescannt werden. Er bleibt für alle folgenden ISBN-Scans aktiv, bis ein anderer Standort gewählt oder gescannt wird. Eine bekannte ISBN bestätigt nur den Bestand. Sendet ein Scanner wegen des Tastaturlayouts „ß“ statt „-“, wird das automatisch korrigiert.</div>
                    <div id="activeLocationBox" class="active-location">
                        <span class="small muted">Aktiver Standort für folgende Scans</span>
                        <strong id="activeLocationPath">Wird geladen …</strong>
                        <span id="activeLocationCode" class="location-code"></span>
                    </div>
                    <div class="row"><button id="scanSubmitBtn" class="btn smallbtn" type="button">Scan übernehmen</button></div>
                    <div class="field-grid">
                        <label class="full">Standort manuell wählen<select id="scanLocationSelect" required></select></label>
                        <label class="checkbox full"><input id="scanIsLibrary" type="checkbox">Als Büchereibuch erfassen</label>
                        <div id="scanLibraryFields" class="field-grid full hidden">
                            <label>Bücherei<input id="scanLibraryName" maxlength="255" placeholder="z. B. Stadtbibliothek"></label>
                            <label>Rückgabe bis<input id="scanLibraryDue" type="date"></label>
                        </div>
                    </div>
                    <div class="row">
                        <span id="scanPending" class="badge info">0 offen</span>
                        <span id="workerStatus" class="muted small">Metadatenwarteschlange bereit</span>
                    </div>
                </div>
                <div class="card">
                    <h2>Letzte Scans</h2>
                    <div id="scanLog" class="scan-log"><div class="empty">Noch keine Scans in dieser Sitzung.</div></div>
                </div>
            </div>
        </section>

        <section id="view-metadata" class="view">
            <div class="view-head">
                <div><h1>Metadaten-Warteschlange</h1><div class="muted">Nachvollziehen, welche ISBNs im Hintergrund abgefragt werden und hängende Aufträge abbrechen.</div></div>
                <div class="spacer"></div>
                <button id="refreshMetadataQueueBtn" class="btn secondary">Aktualisieren</button>
                <button id="runMetadataQueueBtn" class="btn">Nächsten Auftrag starten</button>
            </div>
            <div id="metadataQueueStats" class="stats"></div>
            <div class="card">
                <div id="metadataQueueResults"><div class="empty">Warteschlange wird geladen …</div></div>
            </div>
        </section>

        <section id="view-nerdstats" class="view">
            <div class="view-head">
                <div><h1>Schlagwörter und Statistiken</h1><div class="muted">Nerdige Auswertungen deines aktiven Haushalts. Schlagwörter sind anklickbar und übernehmen die Suche.</div></div>
                <div class="spacer"></div>
                <button id="refreshNerdStatsBtn" class="btn secondary">Aktualisieren</button>
            </div>
            <div id="nerdStatsCards" class="stats"></div>
            <div class="grid two">
                <div class="card"><h2>Schlagwörter-Wolke</h2><div id="tagCloud" class="tag-cloud"><div class="empty">Wird geladen …</div></div></div>
                <div class="card"><h2>Häufige Autoren</h2><div id="topAuthors"><div class="empty">Wird geladen …</div></div></div>
            </div>
            <div class="card" style="margin-top:16px"><h2>Zeitstrahl nach Erscheinungsjahr</h2><div id="yearTimeline"><div class="empty">Wird geladen …</div></div></div>
        </section>

        <section id="view-locations" class="view">
            <div class="view-head">
                <div><h1>Standorte</h1><div class="muted">Gebäude, Raum und Regal verwalten; die Fach-Barcodes werden automatisch erzeugt.</div></div>
                <div class="spacer"></div>
                <button id="selectAllLocationsBtn" class="btn secondary">Alle wählen</button>
                <button id="selectNoLocationsBtn" class="btn secondary">Keine wählen</button>
                <button id="printLocationsBtn" class="btn secondary">Auswahl drucken</button>
                <button id="addLocationBtn" class="btn">Standort anlegen</button>
            </div>
            <div class="card">
                <div class="notice">Jedes Regal erhält eine fünfstellige interne ID. Für jedes Fach wird automatisch ein Barcode im Format TRIAMO-XXXXX-N erzeugt. Gebäude, Raum oder Regalname können später geändert werden; die Barcodes bleiben gültig.</div>
                <div id="locationResults" style="margin-top:14px"></div>
            </div>
        </section>

        <section id="view-books" class="view">
            <div class="view-head">
                <div><h1>Bestand</h1><div class="muted">Lokale Bibliothek durchsuchen und bei Bedarf Online-Quellen einbeziehen.</div></div>
                <div class="spacer"></div>
                <button id="addBookBtn" class="btn" data-manager>Manuell erfassen</button>
            </div>
            <div class="card">
                <div class="toolbar">
                    <input id="bookSearch" class="search" type="search" placeholder="Titel, Autor, ISBN, Verlag oder Kategorie">
                    <select id="availabilityFilter" style="width:auto">
                        <option value="all">Alle Verfügbarkeiten</option>
                        <option value="available">Verfügbar</option>
                        <option value="loaned">Ausgeliehen</option>
                        <option value="reserved">Vorgemerkt</option>
                    </select>
                    <select id="bookKindFilter" style="width:auto">
                        <option value="all">Aktiv und archiviert</option>
                        <option value="active">Nur aktiver Bestand</option>
                        <option value="owned">Eigene Bücher</option>
                        <option value="library">Büchereibücher</option>
                        <option value="archived">Nur Archiv</option>
                    </select>
                    <select id="bookLocationFilter" style="width:auto;max-width:280px" title="Bestand nach Standort filtern">
                        <option value="">Alle Standorte</option>
                    </select>
                    <input id="bookLocationScan" style="max-width:280px" type="text" autocomplete="off" placeholder="Standortname oder Barcode eingeben/scannen">
                    <button id="bookLocationApplyBtn" class="btn secondary smallbtn" type="button">Standort anwenden</button>
                    <button id="bookLocationClearBtn" class="btn secondary smallbtn" type="button">Standort löschen</button>
                    <select id="bookPageSize" style="width:auto" title="Einträge pro Seite">
                        <option value="10">10 anzeigen</option>
                        <option value="50">50 anzeigen</option>
                        <option value="100">100 anzeigen</option>
                        <option value="200" selected>200 anzeigen</option>
                        <option value="500">500 anzeigen</option>
                        <option value="all">Alle anzeigen</option>
                    </select>
                </div>
                <div class="row search-options" style="margin:10px 0 14px;gap:18px">
                    <label class="checkbox"><input id="onlineToggle" type="checkbox">Suche auf Online-Portale erweitern</label>
                    <label id="allHouseholdsLabel" class="checkbox hidden"><input id="allHouseholdsToggle" type="checkbox">In allen freigegebenen Haushalten durchsuchen</label>
                </div>
                <div id="booksReloadNotice" class="notice hidden" style="margin:10px 0">Änderungen am Bestand oder an Metadaten sind verfügbar. <button id="booksReloadBtn" class="btn smallbtn" type="button">Neu laden</button></div>
                <div id="booksStats" class="stats" style="margin:10px 0 14px"></div>
                <div id="booksPaginationTop" class="row" style="margin:8px 0 12px"></div>
                <div id="booksResults"></div>
                <div id="booksPaginationBottom" class="row" style="margin:12px 0 0"></div>
                <div id="onlineResults" class="online-section hidden"></div>
            </div>
        </section>

        <section id="view-library" class="view">
            <div class="view-head">
                <div><h1>Büchereibücher</h1><div class="muted">Temporäre Bücher, Rückgabefristen und bereits zurückgegebene Titel.</div></div>
            </div>
            <div class="card">
                <div class="toolbar">
                    <input id="libraryReturnScan" class="search" inputmode="numeric" autocomplete="off" placeholder="ISBN zum Zurückgeben scannen" data-manager>
                    <button id="libraryReturnScanBtn" class="btn warning smallbtn" data-manager>Per Scan zurückgeben</button>
                    <select id="libraryScope" style="width:auto">
                        <option value="active">Aktuell ausgeliehen</option>
                        <option value="returned">Zurückgegeben</option>
                        <option value="all">Alle Büchereibücher</option>
                    </select>
                </div>
                <div id="libraryResults"></div>
            </div>
        </section>

        <section id="view-loans" class="view">
            <div class="view-head">
                <div><h1>Verleihungen</h1><div class="muted">Aktive und abgeschlossene Verleihungen verwalten.</div></div>
            </div>
            <div class="card">
                <div class="toolbar">
                    <select id="loanScope" style="width:auto">
                        <option value="active">Aktive Verleihungen</option>
                        <option value="returned">Zurückgegeben</option>
                        <option value="all">Alle</option>
                    </select>
                </div>
                <div id="loanResults"></div>
            </div>
        </section>

        <section id="view-reservations" class="view">
            <div class="view-head">
                <div><h1>Vormerkungen</h1><div class="muted">Reihenfolge, Benachrichtigung und Status der Vormerkungen.</div></div>
            </div>
            <div class="card"><div id="reservationResults"></div></div>
        </section>

        <section id="view-sharing" class="view">
            <div class="view-head">
                <div><h1>Freigaben</h1><div class="muted">Temporäre öffentliche Links und widerrufliche Zugriffe zwischen Konten verwalten.</div></div>
            </div>
            <div class="stack">
                <div class="card">
                    <h2 style="margin-top:0">Öffentliche Stöberlinks</h2>
                    <form id="shareLinkForm" class="toolbar">
                        <input name="description" class="search" maxlength="500" placeholder="Kurze Beschreibung, z. B. Auswahl für den Urlaub">
                        <label style="min-width:150px">Gültigkeit<select name="days"><option value="1">1 Tag</option><option value="7">7 Tage</option><option value="14" selected>14 Tage</option><option value="30">30 Tage</option><option value="90">90 Tage</option></select></label>
                        <button class="btn" type="submit">Link anlegen</button>
                    </form>
                    <div id="shareLinksResults" style="margin-top:14px"></div>
                </div>
                <div class="card">
                    <h2 style="margin-top:0">Freigabe zwischen Konten</h2>
                    <div class="notice">Jeder Zugriffsschlüssel kann genau einmal eingelöst werden. Danach bleibt er als eingelöst dokumentiert. Nicht eingelöste Schlüssel können gelöscht werden. Freigaben lassen sich pausieren, fortsetzen oder endgültig entziehen.</div>
                    <form id="accessKeyForm" class="toolbar" style="margin-top:14px">
                        <input name="note" class="search" maxlength="500" placeholder="Notiz zum Schlüssel, z. B. für Anna">
                        <button class="btn" type="submit">Einmalschlüssel erzeugen</button>
                    </form>
                    <div id="accessKeysResults" style="margin-top:14px"></div>
                    <h3>Zugriffsschlüssel eines anderen Haushalts einlösen</h3>
                    <form id="redeemAccessKeyForm" class="toolbar"><input name="key" class="search" required maxlength="40" placeholder="TRI-XXXX-XXXX-XXXX"><button class="btn secondary" type="submit">Einlösen</button></form>
                    <h3>Personen mit Zugriff auf meinen Haushalt</h3>
                    <div id="accessGrantsResults"></div>
                    <h3>Haushalte, auf die ich Zugriff habe</h3>
                    <div id="incomingAccessResults"></div>
                </div>
                <div class="card">
                    <h2 style="margin-top:0">Zugriffsprotokoll öffentlicher Links</h2>
                    <div id="shareLogsResults"></div>
                </div>
            </div>
        </section>


        <section id="view-backup" class="view">
            <div class="view-head">
                <div><h1>Backup und Restore</h1><div class="muted">Eigene Haushaltsdaten sichern oder als Administrator das gesamte System sichern.</div></div>
            </div>
            <div class="stack">
                <div class="card">
                    <h2 style="margin-top:0">Backup erstellen</h2>
                    <div class="row" style="gap:12px;flex-wrap:wrap">
                        <button id="backupHouseholdBtn" class="btn">Eigenen Haushalt sichern</button>
                        <button id="backupAllBtn" class="btn secondary" data-admin>Alles sichern</button>
                    </div>
                    <div class="hint" style="margin-top:12px">Haushaltsbackups enthalten den aktiven Haushalt mit Büchern, Exemplaren, Standorten, Historie, Verleihungen, Vormerkungen und Metadaten. Systembackups enthalten alle Tabellen dieses Tabellenpräfixes. Hochgeladene Cover und Buchdateien liegen zusätzlich im Ordner bookvault_data und müssen bei einem Serverumzug zusammen mit diesem Ordner gesichert werden.</div>
                </div>
                <div class="card">
                    <h2 style="margin-top:0">Backup wiederherstellen</h2>
                    <div class="notice warning"><strong>Vor Restore immer Datenbank sichern.</strong> Ein Haushaltsbackup wird als neuer Haushalt wiederhergestellt. Ein vollständiges Systembackup ersetzt als Administrator alle Tabellen dieses Präfixes.</div>
                    <form id="backupRestoreForm" class="stack" style="margin-top:14px">
                        <label>Backupdatei auswählen<input id="backupFile" type="file" accept="application/json,.json" required></label>
                        <div class="row"><button class="btn danger" type="submit">Backup wiederherstellen</button></div>
                    </form>
                    <div id="backupRestoreInfo" class="muted small"></div>
                </div>
            </div>
        </section>

        <section id="view-users" class="view">
            <div class="view-head">
                <div><h1>Benutzer</h1><div class="muted">Konten, E-Mail-Bestätigung, Sitzungen, Haushalte und sichere Rücksetzungen verwalten.</div></div>
                <div class="spacer"></div>
                <button id="newUserBtn" class="btn">Benutzer anlegen</button>
            </div>
            <div class="card"><div id="userResults"></div></div>
        </section>

        <section id="view-profile" class="view">
            <div class="view-head"><div><h1>Profil</h1><div class="muted">Eigene Kontodaten und Passwort ändern.</div></div></div>
            <div class="card" style="max-width:760px">
                <form id="profileForm" class="stack">
                    <div class="field-grid">
                        <label>Name<input name="display_name" required maxlength="120"></label>
                        <label id="householdNameField">Haushaltsname<input name="household_name" required maxlength="160"></label>
                        <label>E-Mail-Adresse<input name="email" type="email" required maxlength="191"></label>
                        <label>Aktuelles Passwort<input name="current_password" type="password" autocomplete="current-password"></label>
                        <label>Neues Passwort<input name="new_password" type="password" autocomplete="new-password" minlength="10" placeholder="Nur zum Ändern ausfüllen"></label>
                    </div>
                    <div class="row"><button class="btn" type="submit">Profil speichern</button></div>
                </form>

                <div class="notice" style="margin-top:24px">
                    <h2 style="margin-top:0">E-Mail-Bestätigung</h2>
                    <p id="emailVerificationStatus" class="small muted"></p>
                    <button id="sendVerificationBtn" class="btn secondary" type="button">Bestätigungslink erneut senden</button>
                </div>

                <div class="notice" style="margin-top:24px">
                    <h2 style="margin-top:0">Datenauskunft und Datenkopie</h2>
                    <p>
                        Hier kannst du eine maschinenlesbare Kopie der Daten herunterladen,
                        die TRIAMO automatisiert deinem Benutzerkonto zuordnet. Zum Schutz
                        deiner Daten muss das aktuelle Passwort erneut eingegeben werden.
                    </p>
                    <p class="small muted">
                        Daten anderer Personen, geheime Authentifizierungswerte und nicht
                        eindeutig zuordenbare Serverprotokolle werden nicht automatisch
                        ausgegeben. Für eine formelle oder weitergehende Auskunft nach
                        Art. 15 DSGVO schreibe bitte an
                        <a href="mailto:datenschutz@triamo.tschugg.eu">datenschutz@triamo.tschugg.eu</a>.
                    </p>
                    <form id="privacyExportForm" class="stack" style="margin-top:14px">
                        <label>
                            Aktuelles Passwort
                            <input
                                name="current_password"
                                type="password"
                                autocomplete="current-password"
                                required
                            >
                        </label>
                        <div class="row">
                            <button class="btn secondary" type="submit">
                                Datenkopie herunterladen
                            </button>
                        </div>
                    </form>
                    <div id="privacyExportInfo" class="muted small"></div>
                </div>

                <div id="cronInfo" class="notice hidden" style="margin-top:20px"></div>
            </div>
        </section>
    </main>
</div>

<div id="modalBackdrop" class="modal-backdrop hidden" role="dialog" aria-modal="true">
    <div class="modal">
        <div class="modal-head"><button id="modalBack" class="iconbtn hidden" aria-label="Zurück">←</button><h2 id="modalTitle">Dialog</h2><div class="spacer"></div><button id="modalClose" class="iconbtn" aria-label="Schließen">×</button></div>
        <div id="modalBody" class="modal-body"></div>
        <div id="modalActions" class="modal-actions"></div>
    </div>
</div>

<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">

(() => {
    'use strict';

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
    const appName = <?= json_encode(APP_NAME, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const maxJobAttempts = <?= (int)MAX_JOB_ATTEMPTS ?>;
    const state = {
        csrf: '',
        user: null,
        defaults: {loan_days: 28, reminder_days: 2},
        cronUrl: null,
        view: 'dashboard',
        users: [],
        locations: [],
        locationGroups: [],
        selectedLocationPrintIds: [],
        locationPrintSelectionInitialized: false,
        locationsPrintUrl: '',
        activeLocationId: null,
        scanPending: 0,
        workerRunning: false,
        pendingBookReload: false,
        cameraStream: null,
        cameraLoop: null,
        searchTimer: null,
        bookRequestSeq: 0,
        bookLimit: '200',
        bookOffset: 0,
        bookLocationFilter: '',
        bookLocationLabel: '',
        lastBookPagination: null,
        modalBackBookId: null,
        currentDetailBookId: null,
        allowRegistration: false,
        publicShareToken: '',
        publicShare: null,
        publicBookRequestSeq: 0,
    };

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[ch]);

    const attr = esc;

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: coverHtml(), coversHtml(), loadOnline(), metadataSourcesHtml().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function safeUrl(value) {
        try {
            const u = new URL(String(value || ''), location.href);
            return ['http:', 'https:'].includes(u.protocol) ? u.href : '';
        } catch {
            return '';
        }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: loadDashboard(), loadLibraryBooks(), loadLoans(), loadOnline(), loadPublicBooks(), loadReservations(), openBook(), +2 weitere.
     * Abhängigkeiten: safeUrl().
     */
    function coverHtml(item, large = false) {
        const url = safeUrl(item.cover || item.thumbnail || '');
        const cls = large ? 'big-cover' : 'cover';
        if (url) return `<img class="${cls}" src="${attr(url)}" alt="" loading="lazy">`;
        return `<div class="${cls}" style="display:grid;place-items:center;font-size:${large ? '2.4rem' : '1.2rem'}">📚</div>`;
    }

    document.addEventListener('error', event => {
        const image = event.target;
        if (!(image instanceof HTMLImageElement) || (!image.classList.contains('cover') && !image.classList.contains('big-cover'))) return;
        const placeholder = document.createElement('div');
        placeholder.className = image.className;
        placeholder.style.cssText = `display:grid;place-items:center;font-size:${image.classList.contains('big-cover') ? '2.4rem' : '1.2rem'}`;
        placeholder.textContent = '📚';
        image.replaceWith(placeholder);
    }, true);

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: historyHtml(), loadDashboard(), loadLibraryBooks(), loadLoans(), loadPublicShare(), loadReservations(), loadSharing(), +5 weitere.
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function fmtDate(value, withTime = false) {
        if (!value) return '–';
        const normalized = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T');
        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) return esc(value);
        return new Intl.DateTimeFormat('de-DE', withTime
            ? {dateStyle: 'medium', timeStyle: 'short'}
            : {dateStyle: 'medium'}).format(date);
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events, openLoanDialog().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function isoDateAfter(days) {
        const d = new Date();
        d.setDate(d.getDate() + Number(days || 28));
        return d.toISOString().slice(0, 10);
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: loadReservations(), loadUsers(), metadataSourcesHtml(), openBook(), renderBooks().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function statusBadge(status) {
        const map = {
            available: ['Verfügbar', 'ok'], loaned: ['Ausgeliehen', 'warn'], reserved: ['Reserviert', 'info'],
            lost: ['Verloren', 'bad'], ready: ['Vollständig', 'ok'], queued: ['In Warteschlange', 'info'],
            fetching: ['Wird geladen', 'info'], error: ['Fehler', 'bad'], active: ['Aktiv', 'ok'],
            fulfilled: ['Erledigt', 'ok'], cancelled: ['Storniert', 'bad'], member: ['Benutzer', 'info'],
            admin: ['Administrator', 'warn'], deleted: ['Archiviert', 'bad'],
            library_returned: ['An Bücherei zurück', 'info'], success: ['Treffer', 'ok'],
            not_found: ['Kein Treffer', 'warn'], skipped: ['Nicht eingerichtet', 'info'], deferred: ['Pausiert', 'warn'],
            pending: ['Offen', 'info'], retry: ['Neuer Versuch', 'warn'], failed: ['Fehlgeschlagen', 'bad'],
        };
        const [label, cls] = map[status] || [status || '–', ''];
        return `<span class="badge ${cls}">${esc(label)}</span>`;
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events, adoptCommunityMetadata(), bootstrap(), cancelReservation(), deleteBook(), deleteCopy(), editBook(), +16 weitere.
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function toast(message, type = '') {
        const el = document.createElement('div');
        el.className = `toast ${type}`.trim();
        el.textContent = message;
        $('#toastWrap').append(el);
        setTimeout(() => el.remove(), 4200);
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events, adoptCommunityMetadata(), bootstrap(), cancelReservation(), deleteBook(), deleteCopy(), editBook(), +34 weitere.
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    async function api(name, options = {}) {
        const params = new URLSearchParams(options.params || {});
        params.set('api', name);
        const request = {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
        };
        if (request.method !== 'GET') {
            request.headers['X-CSRF-Token'] = state.csrf;
            if (options.formData instanceof FormData) {
                request.body = options.formData;
            } else {
                request.headers['Content-Type'] = 'application/json';
                request.body = JSON.stringify(options.data || {});
            }
        }

        let response;
        try {
            response = await fetch(`${location.pathname}?${params.toString()}`, request);
        } catch {
            throw new Error('Der Server ist nicht erreichbar.');
        }

        let payload;
        try {
            payload = await response.json();
        } catch {
            throw new Error('Der Server hat keine gültige Antwort geliefert.');
        }

        if (!response.ok || payload.ok === false) {
            if (response.status === 401 || payload.auth_required) {
                state.user = null;
                showAuth('login');
            }
            const error = new Error(payload.error || `Fehler ${response.status}`);
            Object.assign(error, payload);
            throw error;
        }
        return payload;
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events, editCopy(), openAddCopy(), openLoanDialog(), openLocationForm(), openReturnDialog(), reserveBook(), +2 weitere.
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function formObject(form) {
        return Object.fromEntries(new FormData(form).entries());
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events, editCopy(), openAddCopy(), openLoanDialog(), openLocationForm(), openReturnDialog(), reserveBook(), +3 weitere.
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function setBusy(button, busy, label = 'Bitte warten …') {
        if (!button) return;
        if (busy) {
            button.dataset.oldLabel = button.textContent;
            button.textContent = label;
            button.disabled = true;
        } else {
            button.textContent = button.dataset.oldLabel || button.textContent;
            button.disabled = false;
        }
    }

    /**
     * Wartung: Öffnet einen Dialog oder Detailbereich in der Oberfläche.
     * Aufgerufen von: editBook(), editCopy(), openAddCopy(), openBook(), openCamera(), openEditUser(), openLegalDocument(), +8 weitere.
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function openModal(title, bodyHtml, actionsHtml = '', options = {}) {
        state.modalBackBookId = options.backBookId || null;
        if (!options.preserveDetail && !options.backBookId) state.currentDetailBookId = null;
        $('#modalBack').classList.toggle('hidden', !state.modalBackBookId);
        $('#modalTitle').textContent = title;
        $('#modalBody').innerHTML = bodyHtml;
        $('#modalActions').innerHTML = actionsHtml || '<button class="btn secondary" data-modal-close>Schließen</button>';
        $('#modalBackdrop').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        setTimeout(() => $('#modalBody input, #modalBody select, #modalBody textarea')?.focus(), 30);
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: closeModal(), openBook().
     */
    function modalBack() {
        const bookId = Number(state.modalBackBookId || 0);
        if (bookId) {
            state.modalBackBookId = null;
            openBook(bookId);
        } else {
            closeModal();
        }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events, modalBack(), openCamera(), openLoanDialog(), openLocationForm(), openReturnDialog(), reserveBook(), +2 weitere.
     * Abhängigkeiten: stopCamera().
     */
    function closeModal() {
        stopCamera();
        state.modalBackBookId = null;
        state.currentDetailBookId = null;
        $('#modalBack').classList.add('hidden');
        $('#modalBackdrop').classList.add('hidden');
        document.body.style.overflow = '';
        $('#modalBody').innerHTML = '';
        $('#modalActions').innerHTML = '';
    }

    $('#modalBack').addEventListener('click', modalBack);
    $('#modalClose').addEventListener('click', closeModal);
    $('#modalBackdrop').addEventListener('click', event => {
        if (event.target === $('#modalBackdrop')) closeModal();
    });


    /**
     * Wartung: Öffnet einen Dialog oder Detailbereich in der Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: openModal().
     */
    async function openLegalDocument(url, title) {
        openModal(title, '<div class="empty">Dokument wird geladen …</div>', '<button class="btn secondary" data-modal-close>Schließen</button>');
        try {
            const fragmentUrl = new URL(url, window.location.href);
            fragmentUrl.searchParams.set('fragment', '1');
            const response = await fetch(fragmentUrl.toString(), {credentials: 'same-origin', headers: {'X-Requested-With': 'fetch'}});
            if (!response.ok) throw new Error('Das Dokument konnte nicht geladen werden.');
            const html = await response.text();
            $('#modalTitle').textContent = title;
            $('#modalBody').innerHTML = html;
            $('#modalActions').innerHTML = `<a class="btn secondary" href="${fragmentUrl.pathname}" target="_blank" rel="noopener">Separat öffnen</a><button class="btn" data-modal-close>Schließen</button>`;
        } catch (error) {
            $('#modalBody').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
        }
    }

    document.addEventListener('click', event => {
        const legalLink = event.target.closest('[data-legal-link]');
        if (!legalLink) return;
        event.preventDefault();
        openLegalDocument(legalLink.getAttribute('href'), legalLink.textContent.trim() || 'Dokument');
    });
    document.addEventListener('click', event => {
        if (event.target.closest('[data-modal-back]')) modalBack();
        else if (event.target.closest('[data-modal-close]')) closeModal();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !$('#modalBackdrop').classList.contains('hidden')) {
            state.modalBackBookId ? modalBack() : closeModal();
        }
    });

    /**
     * Wartung: Lädt Daten für die jeweilige Ansicht und aktualisiert die Oberfläche.
     * Aufgerufen von: bootstrap().
     * Abhängigkeiten: api(), fmtDate(), loadPublicBooks().
     */
    async function loadPublicShare(token) {
        state.publicShareToken = token;
        $('#authShell').classList.add('hidden');
        $('#appShell').classList.add('hidden');
        $('#publicShareShell').classList.remove('hidden');
        try {
            const data = await api('public_share_bootstrap', {params: {token}});
            state.publicShare = data.share;
            $('#publicShareHero').innerHTML = `<div class="brand" style="color:#fff"><span class="brandmark">▦</span><span>${esc(appName)}</span></div>
                <h1>${esc(data.share.household_name)}</h1>
                ${data.share.description ? `<p>${esc(data.share.description)}</p>` : '<p>Freigegebener Buchbestand zum Stöbern.</p>'}
                <div class="small" style="opacity:.84">Freigabe gültig bis ${fmtDate(data.share.expires_at, true)}</div>`;
            await loadPublicBooks();
        } catch (error) {
            $('#publicShareHero').innerHTML = `<h1>Freigabe nicht verfügbar</h1><p>${esc(error.message)}</p>`;
            $('#publicBooksResults').innerHTML = '<div class="empty">Der Link kann nicht mehr verwendet werden.</div>';
        }
    }

    /**
     * Wartung: Lädt Daten für die jeweilige Ansicht und aktualisiert die Oberfläche.
     * Aufgerufen von: loadPublicShare().
     * Abhängigkeiten: api(), coverHtml(), renderPublicBookStats().
     */
    async function loadPublicBooks() {
        const seq = ++state.publicBookRequestSeq;
        const q = $('#publicBookSearch').value.trim();
        $('#publicBooksResults').innerHTML = '<div class="empty">Bücher werden geladen …</div>';
        try {
            const data = await api('public_share_books', {params: {token: state.publicShareToken, q}});
            if (seq !== state.publicBookRequestSeq) return;
            renderPublicBookStats(data.stats || {total: data.books.length, found: data.books.length, shown: data.books.length});
            if (!data.books.length) {
                $('#publicBooksResults').innerHTML = '<div class="empty">Keine passenden freigegebenen Bücher gefunden.</div>';
                return;
            }
            $('#publicBooksResults').innerHTML = `<div class="public-book-grid">${data.books.map(book => `<article class="public-book" data-public-book="${book.id}">
                ${coverHtml(book)}<div><div class="book-title">${esc(book.title)}</div>
                <div class="book-meta">${esc(book.authors || '')}</div>
                <div class="small muted">${esc([book.publisher, book.published_date].filter(Boolean).join(' · '))}</div>
                <div class="statusline"><span class="badge ${book.visibility === 'lendable' ? 'ok' : 'info'}">${book.visibility === 'lendable' ? 'Grundsätzlich verleihbar' : 'Nur sichtbar'}</span><span class="badge">${Number(book.copies_available)} verfügbar</span></div>
                </div></article>`).join('')}</div>`;
        } catch (error) {
            if (seq !== state.publicBookRequestSeq) return;
            $('#publicBooksResults').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
        }
    }

    /**
     * Wartung: Öffnet einen Dialog oder Detailbereich in der Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), coverHtml(), openModal().
     */
    async function openPublicBook(id) {
        openModal('Buchdetails', '<div class="empty">Buch wird geladen …</div>');
        try {
            const data = await api('public_share_book', {params: {token: state.publicShareToken, id}});
            const book = data.book;
            $('#modalBody').innerHTML = `<div class="details-grid"><div>${coverHtml(book, true)}</div><div>
                <h2 style="margin:0 0 6px">${esc(book.title)}</h2>${book.subtitle ? `<div>${esc(book.subtitle)}</div>` : ''}
                <div class="muted">${esc(book.authors || '')}</div>
                <div class="statusline"><span class="badge ${book.visibility === 'lendable' ? 'ok' : 'info'}">${book.visibility === 'lendable' ? 'Grundsätzlich verleihbar' : 'Nur sichtbar'}</span><span class="badge">${Number(book.copies_available)} verfügbar</span></div>
                <dl><dt class="muted small">ISBN</dt><dd>${esc(book.isbn13 || book.isbn10 || '–')}</dd>
                <dt class="muted small">Verlag / Datum</dt><dd>${esc([book.publisher,book.published_date].filter(Boolean).join(' · ') || '–')}</dd>
                <dt class="muted small">Seiten / Sprache</dt><dd>${esc([book.page_count,book.language].filter(Boolean).join(' · ') || '–')}</dd>
                <dt class="muted small">Kategorien</dt><dd>${esc(book.categories || '–')}</dd></dl></div></div>
                ${book.description ? `<h3>Beschreibung</h3><p>${esc(book.description).replace(/\n/g,'<br>')}</p>` : ''}
                ${data.files?.length ? `<h3>Dateien und digitale Ergänzungen</h3>${bookFilesHtml(data.files, false, state.publicShareToken)}` : ''}`;
            $('#modalActions').innerHTML = '<button class="btn secondary" data-modal-close>Schließen</button>';
        } catch (error) {
            $('#modalBody').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
        }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events, ensureHouseholdContext().
     * Abhängigkeiten: api(), loadPublicShare(), showApp(), showAuth(), toast().
     */
    async function bootstrap() {
        const shareToken = new URLSearchParams(location.search).get('share') || '';
        if (shareToken) {
            await loadPublicShare(shareToken);
            return;
        }
        try {
            const data = await api('bootstrap');
            state.csrf = data.csrf;
            state.user = data.user;
            state.allowRegistration = Boolean(data.allow_registration);
            state.defaults = data.defaults || state.defaults;
            state.cronUrl = data.cron_url;
            const params = new URLSearchParams(window.location.search);
            const verifyToken = params.get('verify_email_token');
            if (verifyToken) {
                try {
                    const verified = await api('email_verify', {method: 'POST', data: {token: verifyToken}});
                    toast(verified.message || 'E-Mail-Adresse bestätigt.');
                } catch (error) {
                    toast(error.message, 'error');
                }
                params.delete('verify_email_token');
                const cleanQuery = params.toString();
                history.replaceState({}, '', window.location.pathname + (cleanQuery ? '?' + cleanQuery : ''));
                return bootstrap();
            }
            const resetToken = params.get('reset_token');
            if (resetToken) {
                $('#resetForm').elements.token.value = resetToken;
                showAuth('reset');
            } else if (data.needs_setup) showAuth('setup');
            else if (!data.user) showAuth('login');
            else showApp();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /**
     * Wartung: Schaltet größere UI-Bereiche sichtbar.
     * Aufgerufen von: Globaler Ablauf/API/Events, bootstrap().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function showAuth(mode = 'login') {
        $('#publicShareShell').classList.add('hidden');
        $('#appShell').classList.add('hidden');
        $('#authShell').classList.remove('hidden');
        $('#setupForm').classList.toggle('hidden', mode !== 'setup');
        $('#loginForm').classList.toggle('hidden', mode !== 'login');
        $('#registerForm').classList.toggle('hidden', mode !== 'register');
        $('#forgotForm').classList.toggle('hidden', mode !== 'forgot');
        $('#resetForm').classList.toggle('hidden', mode !== 'reset');
        $('#showRegisterBtn').classList.toggle('hidden', !state.allowRegistration);
        $('#authIntro').textContent = mode === 'setup'
            ? 'Einmalige Einrichtung der Bibliotheksplattform.'
            : mode === 'register'
                ? 'Eigenes Konto und eigenen Haushalt anlegen.'
                : mode === 'forgot'
                    ? 'Einmallink zum Zurücksetzen des Passworts anfordern.'
                    : mode === 'reset'
                        ? 'Neues Passwort festlegen.'
                        : 'Mit deinem Bibliothekskonto anmelden.';
    }

    /**
     * Wartung: Schaltet größere UI-Bereiche sichtbar.
     * Aufgerufen von: bootstrap().
     * Abhängigkeiten: goTo(), runWorker().
     */
    function showApp() {
        $('#publicShareShell').classList.add('hidden');
        $('#authShell').classList.add('hidden');
        $('#appShell').classList.remove('hidden');
        $('#sidebarUser').textContent = state.user.display_name;
        const household = state.user.active_household || {};
        $('#sidebarRole').textContent = `${state.user.can_manage_household ? 'Eigener Haushalt' : 'Freigegebener Haushalt'} · ${household.name || ''}`;
        $('#topbarUser').textContent = state.user.display_name;
        $$('[data-manager]').forEach(el => el.classList.toggle('hidden', !state.user.can_manage_household));
        $$('[data-admin]').forEach(el => el.classList.toggle('hidden', state.user.role !== 'admin'));

        const switcher = $('#householdSwitcher');
        switcher.innerHTML = (state.user.households || []).map(item =>
            `<option value="${item.id}" ${Number(item.id) === Number(state.user.active_household_id) ? 'selected' : ''}>${esc(item.name)}${item.can_manage ? ' · eigener Bestand' : ' · freigegeben'}</option>`
        ).join('');
        switcher.classList.toggle('hidden', (state.user.households || []).length < 2);
        const hasOtherHouseholds = (state.user.households || []).some(item => Number(item.id) !== Number(state.user.active_household_id));
        $('#allHouseholdsLabel')?.classList.toggle('hidden', !hasOtherHouseholds);
        if ($('#allHouseholdsToggle')) {
            if (!hasOtherHouseholds) $('#allHouseholdsToggle').checked = false;
            $('#allHouseholdsToggle').disabled = Boolean(state.bookLocationFilter);
        }

        const profile = $('#profileForm');
        profile.elements.display_name.value = state.user.display_name || '';
        profile.elements.email.value = state.user.email || '';
        profile.elements.household_name.value = state.user.active_household_name || '';
        $('#householdNameField').classList.toggle('hidden', !state.user.can_manage_household);
        profile.elements.household_name.disabled = !state.user.can_manage_household;

        const verificationStatus = $('#emailVerificationStatus');
        const verificationButton = $('#sendVerificationBtn');
        if (state.user.email_verified_at) {
            verificationStatus.innerHTML = `<span class="badge ok">Bestätigt</span> ${esc(state.user.email)} wurde bestätigt.`;
            verificationButton.classList.add('hidden');
        } else {
            verificationStatus.innerHTML = `<span class="badge warn">Nicht bestätigt</span> Bitte bestätige ${esc(state.user.email)} über den Einmallink.`;
            verificationButton.classList.remove('hidden');
        }

        if (state.user.role === 'admin' && state.cronUrl) {
            $('#cronInfo').classList.remove('hidden');
            $('#cronInfo').innerHTML = `<strong>E-Mail-Erinnerungen aktivieren</strong><br>
                Lege bei ALL-INKL einen Cronjob an, der diese geschützte URL aufruft:<br>
                <code style="word-break:break-all">${esc(state.cronUrl)}</code><br>
                <span class="small">Empfehlung: alle 5 bis 15 Minuten. Der Cronjob verarbeitet Metadaten, Wiederholungsversuche und E-Mail-Erinnerungen für alle Haushalte.</span>`;
        } else {
            $('#cronInfo').classList.add('hidden');
        }
        goTo('dashboard');
        if (state.user.can_manage_household) setTimeout(() => runWorker(), 600);
    }

    $('#setupForm').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = $('button[type=submit]', form);
        setBusy(button, true);
        try {
            await api('setup', {method: 'POST', data: formObject(form)});
            toast('Einrichtung abgeschlossen.');
            await bootstrap();
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setBusy(button, false);
        }
    });

    $('#loginForm').addEventListener('submit', async event => {
        event.preventDefault();
        // Event.currentTarget wird nach einem await von manchen Browsern auf null gesetzt.
        // Deshalb die Formularreferenz vor dem ersten await sichern.
        const form = event.currentTarget;
        const button = $('button[type=submit]', form);
        setBusy(button, true);
        try {
            const data = await api('login', {method: 'POST', data: formObject(form)});
            state.csrf = data.csrf || state.csrf;
            form.reset();
            await bootstrap();
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setBusy(button, false);
        }
    });

    $('#showRegisterBtn').addEventListener('click', () => showAuth('register'));
    $('#showForgotBtn').addEventListener('click', () => showAuth('forgot'));
    $('#forgotBackBtn').addEventListener('click', () => showAuth('login'));
    $('#resetBackBtn').addEventListener('click', () => {
        history.replaceState({}, '', window.location.pathname);
        showAuth('login');
    });
    $('#forgotForm').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = $('button[type=submit]', form);
        setBusy(button, true);
        try {
            const result = await api('password_reset_request', {method: 'POST', data: formObject(form)});
            toast(result.message || 'Anfrage verarbeitet.');
            form.reset();
            showAuth('login');
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setBusy(button, false);
        }
    });
    $('#resetForm').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const button = $('button[type=submit]', form);
        setBusy(button, true);
        try {
            const result = await api('password_reset_complete', {method: 'POST', data: formObject(form)});
            toast(result.message || 'Passwort geändert.');
            form.reset();
            history.replaceState({}, '', window.location.pathname);
            showAuth('login');
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setBusy(button, false);
        }
    });
    $('#showLoginBtn').addEventListener('click', () => showAuth('login'));
    $('#registerForm').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = $('button[type=submit]', form);
        setBusy(button, true);
        try {
            const data = await api('register', {method: 'POST', data: formObject(form)});
            state.csrf = data.csrf || state.csrf;
            form.reset();
            toast('Konto und Haushalt wurden angelegt.');
            await bootstrap();
        } catch (error) {
            toast(error.message, 'error');
        } finally { setBusy(button, false); }
    });

    $('#householdSwitcher').addEventListener('change', async event => {
        const householdId = Number(event.currentTarget.value);
        try {
            await api('household_switch', {method: 'POST', data: {household_id: householdId}});
            closeModal();
            state.locations = [];
            state.locationGroups = [];
            state.activeLocationId = null;
            state.bookLocationFilter = '';
            state.bookLocationLabel = '';
            await bootstrap();
            toast('Haushalt gewechselt.');
        } catch (error) {
            toast(error.message, 'error');
        }
    });

    $('#logoutBtn').addEventListener('click', async () => {
        try {
            await api('logout', {method: 'POST'});
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            state.user = null;
            location.reload();
        }
    });

    const viewTitles = {
        dashboard: 'Übersicht', scan: 'Bücher erfassen', locations: 'Standorte', books: 'Bestand', library: 'Büchereibücher',
        loans: 'Verleihungen', reservations: 'Vormerkungen', sharing: 'Freigaben', backup: 'Backup', metadata: 'Metadaten', nerdstats: 'Statistiken', users: 'Benutzer', profile: 'Profil'
    };

    /**
     * Wartung: Wechselt die aktive Ansicht der Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events, applyBookSearch(), ensureHouseholdContext(), showApp().
     * Abhängigkeiten: loadBooks(), loadDashboard(), loadLibraryBooks(), loadLoans(), loadLocations(), loadMetadataQueue(), loadNerdStats(), +3 weitere.
     */
    function goTo(view) {
        if (['scan', 'locations', 'library', 'sharing', 'backup', 'metadata'].includes(view) && !state.user?.can_manage_household) view = 'dashboard';
        if (view === 'users' && state.user?.role !== 'admin') view = 'dashboard';
        state.view = view;
        $$('.view').forEach(el => el.classList.toggle('active', el.id === `view-${view}`));
        $$('#nav button').forEach(el => el.classList.toggle('active', el.dataset.view === view));
        $('#topbarTitle').textContent = viewTitles[view] || appName;
        $('#sidebar').classList.remove('open');

        if (view === 'dashboard') loadDashboard();
        if (view === 'scan') loadLocations().then(() => setTimeout(() => $('#scanInput')?.focus(), 50));
        if (view === 'locations') loadLocations(true);
        if (view === 'books') {
            if (state.user?.can_manage_household) loadLocations().then(loadBooks).catch(() => loadBooks());
            else loadBooks();
        }
        if (view === 'library') { loadLibraryBooks(); setTimeout(() => $('#libraryReturnScan')?.focus(), 50); }
        if (view === 'loans') loadLoans();
        if (view === 'reservations') loadReservations();
        if (view === 'sharing') loadSharing();
        if (view === 'metadata') loadMetadataQueue();
        if (view === 'nerdstats') loadNerdStats();
        if (view === 'users') loadUsers();
    }

    $('#nav').addEventListener('click', event => {
        const button = event.target.closest('button[data-view]');
        if (button) goTo(button.dataset.view);
    });
    document.addEventListener('click', event => {
        const go = event.target.closest('[data-go]');
        if (go) goTo(go.dataset.go);
    });
    $('#menuBtn').addEventListener('click', () => $('#sidebar').classList.toggle('open'));

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: loadLocations(), locationSelectHtml().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function locationOptions(selectedId = null) {
        return state.locations.filter(item => item.active || Number(item.id) === Number(selectedId)).map(item =>
            `<option value="${item.id}" ${Number(selectedId) === Number(item.id) ? 'selected' : ''}>${esc(item.path)} (${esc(item.code)})${item.active ? '' : ' – Fach nicht mehr vorhanden'}</option>`
        ).join('');
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: queueScan().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function activeLocation() {
        return state.locations.find(item => Number(item.id) === Number(state.activeLocationId))
            || state.locations.find(item => item.is_loose)
            || null;
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events, loadLocations(), queueScan().
     * Abhängigkeiten: beep(), toast().
     */
    function setActiveLocation(id, announce = false) {
        const chosen = state.locations.find(item => Number(item.id) === Number(id) && item.active)
            || state.locations.find(item => item.is_loose);
        if (!chosen) return;
        state.activeLocationId = Number(chosen.id);
        localStorage.setItem('hb_location_id', String(chosen.id));
        if ($('#scanLocationSelect')) $('#scanLocationSelect').value = String(chosen.id);
        if ($('#activeLocationPath')) $('#activeLocationPath').textContent = chosen.path;
        if ($('#activeLocationCode')) $('#activeLocationCode').textContent = chosen.code;
        if (announce) {
            toast(`Standort aktiv: ${chosen.path}`);
            beep(true);
        }
    }

    /**
     * Wartung: Lädt Daten für die jeweilige Ansicht und aktualisiert die Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events, editCopy(), goTo(), openAddCopy(), openLocationForm(), openManualBook(), queueScan().
     * Abhängigkeiten: api(), locationOptions(), renderLocations(), setActiveLocation().
     */
    async function loadLocations(forceRender = false) {
        try {
            const data = await api('locations', {params: {include_inactive: state.user?.can_manage_household ? 1 : 0}});
            state.locations = data.locations || [];
            state.locationGroups = data.groups || [];
            state.locationsPrintUrl = data.print_url || '';
            const activeIds = state.locations.filter(item => item.active).map(item => Number(item.id));
            if (!state.locationPrintSelectionInitialized) {
                state.selectedLocationPrintIds = [...activeIds];
                state.locationPrintSelectionInitialized = true;
            } else {
                state.selectedLocationPrintIds = state.selectedLocationPrintIds.filter(id => activeIds.includes(Number(id)));
            }
            const stored = Number(localStorage.getItem('hb_location_id') || 0);
            const preferred = state.locations.some(item => item.id === stored && item.active)
                ? stored
                : state.locations.find(item => item.is_loose)?.id;
            if (!state.activeLocationId || !state.locations.some(item => item.id === Number(state.activeLocationId) && item.active)) {
                state.activeLocationId = preferred || null;
            }
            if ($('#scanLocationSelect')) {
                $('#scanLocationSelect').innerHTML = locationOptions(state.activeLocationId);
                setActiveLocation(state.activeLocationId);
            }
            renderBookLocationOptions();
            if (forceRender || state.view === 'locations') renderLocations();
            return state.locations;
        } catch (error) {
            if ($('#locationResults')) $('#locationResults').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
            throw error;
        }
    }

    /**
     * Wartung: Füllt den Standortfilter der Bestandsseite mit Gruppen und einzelnen Fächern.
     * Aufgerufen von: loadLocations().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function renderBookLocationOptions() {
        const select = $('#bookLocationFilter');
        if (!select) return;
        const previous = state.bookLocationFilter || select.value;
        const options = ['<option value="">Alle Standorte</option>'];
        for (const group of state.locationGroups || []) {
            const active = (group.locations || []).filter(item => item.active);
            if (!active.length) continue;
            if (group.is_loose) {
                options.push(`<option value="id:${Number(active[0].id)}">${esc(group.path)}</option>`);
                continue;
            }
            options.push(`<option value="group:${attr(group.group_code)}">${esc(group.path)} · alle Fächer</option>`);
            for (const item of active) {
                options.push(`<option value="id:${Number(item.id)}">↳ ${esc(group.path)} · Fach ${Number(item.compartment_no || 0)}</option>`);
            }
        }
        if (previous && !options.some(option => option.includes(`value="${attr(previous)}"`))) {
            options.push(`<option value="${attr(previous)}">${esc(state.bookLocationLabel || previous)}</option>`);
        }
        select.innerHTML = options.join('');
        if ([...select.options].some(option => option.value === previous)) select.value = previous;
    }

    /**
     * Wartung: Rendert Daten als HTML in der Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events, loadLocations().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function renderLocations() {
        const groups = state.locationGroups || [];
        if (!groups.length) {
            $('#locationResults').innerHTML = '<div class="empty">Noch keine Standorte angelegt.</div>';
            return;
        }
        const selected = new Set(state.selectedLocationPrintIds.map(Number));
        $('#locationResults').innerHTML = `<div class="table-wrap"><table style="min-width:1100px">
            <thead><tr><th>Standort</th><th>Fächer und Barcodes</th><th>Aktuell gesamt</th><th>Stammplatz gesamt</th><th>Aktion</th></tr></thead>
            <tbody>${groups.map(group => {
                const printable = (group.locations || []).filter(item => item.active);
                const retired = (group.locations || []).filter(item => !item.active && !item.is_loose);
                const allSelected = printable.length > 0 && printable.every(item => selected.has(Number(item.id)));
                const showPerCompartmentCounts = printable.length > 1;
                const barcodeChoices = printable.map(item => `<label class="location-barcode-choice">
                    <input type="checkbox" data-location-print="${item.id}" ${selected.has(Number(item.id)) ? 'checked' : ''}>
                    <span class="location-barcode-meta">
                        <strong>${item.is_loose ? 'Lose' : `Fach ${Number(item.compartment_no || 0)}`}</strong>
                        <span class="location-code">${esc(item.code)}</span>
                        ${showPerCompartmentCounts ? `<span class="location-counts">Aktuell: ${Number(item.current_books || 0)} · Stammplatz: ${Number(item.home_books || 0)}</span>` : ''}
                    </span>
                    <img src="${attr(item.barcode_url)}" alt="Barcode ${attr(item.code)}">
                </label>`).join('');
                const retiredInfo = retired.length ? `<div class="notice warning" style="margin-top:8px"><strong>Nicht mehr vorhandene Fächer:</strong> ${retired.map(item =>
                    `Fach ${Number(item.compartment_no || 0)}${Number(item.current_books || 0) + Number(item.home_books || 0) > 0 ? ` (${Number(item.current_books || 0)} aktuell, ${Number(item.home_books || 0)} Stammplatz)` : ''}`
                ).join(', ')}. Die alten IDs und Buchzuordnungen bleiben erhalten. Durch Erhöhen der Fachanzahl werden diese Fächer wieder aktiviert.</div>` : '';
                const locationFilter = group.is_loose
                    ? `id:${Number(printable[0]?.id || group.locations?.[0]?.id || 0)}`
                    : `group:${group.group_code}`;
                return `<tr class="${group.active ? '' : 'archived-row'}">
                    <td><button class="title-link" type="button" data-location-books="${attr(locationFilter)}">${esc(group.path)}</button>${group.notes ? `<br><span class="small muted">${esc(group.notes)}</span>` : ''}${group.is_loose ? '<br><span class="badge info">Systemstandort</span>' : `<br><span class="badge">${Number(group.compartment_count)} Fächer</span> <span class="location-code">TRIAMO-${esc(group.group_code)}-N</span>`}</td>
                    <td>
                        <label class="checkbox location-select-all"><input type="checkbox" data-location-group-print="${attr(group.group_code)}" ${allSelected ? 'checked' : ''}>Alle dieser Gruppe drucken</label>
                        <div class="location-barcode-grid">${barcodeChoices || '<span class="muted small">Keine aktiven Fächer.</span>'}</div>${retiredInfo}
                    </td>
                    <td>${Number(group.current_books)}</td>
                    <td>${Number(group.home_books)}</td>
                    <td>${group.is_loose ? '–' : `<button class="btn secondary smallbtn" data-location-edit="${attr(group.group_code)}">Bearbeiten</button>`}</td>
                </tr>`;
            }).join('')}</tbody></table></div>`;
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function compartmentCountOptions(selected = 1) {
        return Array.from({length: 50}, (_, index) => index + 1).map(number =>
            `<option value="${number}" ${Number(selected) === number ? 'selected' : ''}>${number}</option>`
        ).join('');
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: openLocationForm().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function locationFormHtml(group = {}) {
        const count = Number(group.compartment_count || 1);
        return `<form id="locationForm" class="stack">
            <input type="hidden" name="original_group_code" value="${attr(group.group_code || '')}">
            <div class="field-grid">
                <label>Barcode-ID des Regals<input name="group_code" maxlength="30" value="${attr(group.group_code || '')}" placeholder="12345 oder TRIAMO-12345-1"></label>
                <label>Gebäude / Hauptstandort<input name="building" maxlength="160" required value="${attr(group.building || '')}" placeholder="z. B. Wohnhaus"></label>
                <label>Raum<input name="room" maxlength="160" value="${attr(group.room || '')}" placeholder="z. B. Wohnzimmer"></label>
                <label>Regal<input name="shelf" maxlength="160" value="${attr(group.shelf || '')}" placeholder="z. B. Schrank oder Regal 3"></label>
                <label>Anzahl Fächer<select name="compartment_count" required>${compartmentCountOptions(count)}</select></label>
                <label class="full">Notiz<textarea name="notes" maxlength="4000">${esc(group.notes || '')}</textarea></label>
                <label class="checkbox full"><input name="active" type="checkbox" value="1" ${group.active === false ? '' : 'checked'}>Standort aktiv</label>
            </div>
            ${group.group_code ? `<div class="notice warning"><strong>Achtung bei Änderung der Barcode-ID:</strong> Bestehende Buchzuordnungen bleiben korrekt, aber neue Etiketten enthalten dann TRIAMO-${esc(group.group_code)}-N beziehungsweise die neu eingetragene ID. Alte Barcodes bleiben als Alias gültig, sofern sie bisher im System bekannt waren.</div>` : '<div class="notice">Lässt du die Barcode-ID leer, erzeugt Triamo automatisch eine freie fünfstellige ID. Trägst du eine bestehende ID von alten Etiketten ein, werden genau diese Barcodes verwendet.</div>'}
        </form>`;
    }

    /**
     * Wartung: Öffnet einen Dialog oder Detailbereich in der Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), closeModal(), formObject(), loadLocations(), locationFormHtml(), openModal(), setBusy(), +1 weitere.
     */
    function openLocationForm(groupCode = null) {
        const group = groupCode ? state.locationGroups.find(row => row.group_code === String(groupCode)) : {};
        openModal(groupCode ? 'Standort bearbeiten' : 'Standort anlegen', locationFormHtml(group),
            '<button class="btn secondary" data-modal-close>Abbrechen</button><button id="saveLocationBtn" class="btn">Speichern</button>');
        $('#saveLocationBtn').addEventListener('click', async () => {
            const form = $('#locationForm');
            if (!form.reportValidity()) return;
            const button = $('#saveLocationBtn');
            setBusy(button, true);
            try {
                const payload = formObject(form);
                payload.active = form.elements.active.checked;
                payload.compartment_count = Number(payload.compartment_count || 1);
                const result = await api('location_save', {method: 'POST', data: payload});
                toast(result.warning || (groupCode ? 'Standort aktualisiert.' : 'Standort mit Fach-Barcodes angelegt.'), result.warning ? 'warn' : '');
                closeModal();
                await loadLocations(true);
            } catch (error) {
                toast(error.message, 'error');
            } finally { setBusy(button, false); }
        });
    }

    $('#scanLocationSelect').addEventListener('change', event => setActiveLocation(Number(event.currentTarget.value), true));
    $('#addLocationBtn').addEventListener('click', () => openLocationForm());
    $('#locationResults').addEventListener('change', event => {
        const single = event.target.closest('[data-location-print]');
        if (single) {
            const id = Number(single.dataset.locationPrint);
            const chosen = new Set(state.selectedLocationPrintIds.map(Number));
            single.checked ? chosen.add(id) : chosen.delete(id);
            state.selectedLocationPrintIds = [...chosen];
            renderLocations();
            return;
        }
        const groupToggle = event.target.closest('[data-location-group-print]');
        if (groupToggle) {
            const group = state.locationGroups.find(item => item.group_code === groupToggle.dataset.locationGroupPrint);
            const chosen = new Set(state.selectedLocationPrintIds.map(Number));
            (group?.locations || []).filter(item => item.active).forEach(item => {
                groupToggle.checked ? chosen.add(Number(item.id)) : chosen.delete(Number(item.id));
            });
            state.selectedLocationPrintIds = [...chosen];
            renderLocations();
        }
    });
    $('#selectAllLocationsBtn').addEventListener('click', () => {
        state.selectedLocationPrintIds = state.locations.filter(item => item.active).map(item => Number(item.id));
        renderLocations();
    });
    $('#selectNoLocationsBtn').addEventListener('click', () => {
        state.selectedLocationPrintIds = [];
        renderLocations();
    });
    $('#printLocationsBtn').addEventListener('click', async () => {
        if (!state.locationsPrintUrl) await loadLocations();
        if (!state.selectedLocationPrintIds.length) {
            toast('Bitte mindestens einen Barcode zum Drucken auswählen.', 'error');
            return;
        }
        const separator = state.locationsPrintUrl.includes('?') ? '&' : '?';
        const url = `${state.locationsPrintUrl}${separator}ids=${encodeURIComponent(state.selectedLocationPrintIds.join(','))}`;
        window.open(url, '_blank', 'noopener');
    });

    /**
     * Wartung: Lädt Daten für die jeweilige Ansicht und aktualisiert die Oberfläche.
     * Aufgerufen von: goTo(), openLoanDialog(), openReturnDialog(), saveBookForm().
     * Abhängigkeiten: api(), coverHtml(), fmtDate().
     */
    async function loadDashboard() {
        $('#stats').innerHTML = Array(8).fill('<div class="stat"><div class="skeleton"></div><div class="skeleton" style="height:28px;margin-top:8px"></div></div>').join('');
        try {
            const data = await api('dashboard');
            const s = data.stats;
            const cards = [
                ['Aktive Titel', s.titles], ['Exemplare', s.copies], ['Verfügbar', s.available],
                ['Ausgeliehen', s.loaned], ['Büchereibücher', s.library], ['Vormerkungen', s.reserved],
                ['Archiv', s.archived], ['Metadaten offen', s.metadata_pending]
            ];
            $('#stats').innerHTML = cards.map(([label, value]) =>
                `<div class="stat"><span class="muted">${esc(label)}</span><strong>${Number(value || 0)}</strong></div>`
            ).join('');

            if (!data.loans.length) {
                $('#dashboardLoans').innerHTML = '<div class="empty">Keine aktiven Verleihungen.</div>';
                return;
            }
            $('#dashboardLoans').innerHTML = `
                <div class="table-wrap desktop-table"><table><thead><tr><th>Buch</th>${state.user.can_manage_household ? '<th>Benutzer</th>' : ''}<th>Exemplar</th><th>Fällig</th><th>Status</th></tr></thead>
                <tbody>${data.loans.map(loan => `<tr>
                    <td><div class="book-cell">${coverHtml(loan)}<div><div class="book-title">${esc(loan.title)}</div><div class="book-meta">${esc(loan.authors || '')}</div></div></div></td>
                    ${state.user.can_manage_household ? `<td>${esc(loan.display_name)}</td>` : ''}
                    <td>${esc(loan.inventory_no)}</td><td>${fmtDate(loan.due_at)}</td>
                    <td>${loan.overdue ? '<span class="badge bad">Überfällig</span>' : '<span class="badge ok">Laufend</span>'}</td>
                </tr>`).join('')}</tbody></table></div>
                <div class="mobile-cards stack">${data.loans.map(loan => `<div class="book-card">${coverHtml(loan)}<div>
                    <div class="book-title">${esc(loan.title)}</div>
                    <div class="book-meta">${state.user.can_manage_household ? esc(loan.display_name) + ' · ' : ''}${esc(loan.inventory_no)}</div>
                    <div class="statusline">${loan.overdue ? '<span class="badge bad">Überfällig</span>' : '<span class="badge ok">Laufend</span>'}<span class="badge">${fmtDate(loan.due_at)}</span></div>
                </div></div>`).join('')}</div>`;
        } catch (error) {
            $('#stats').innerHTML = '';
            $('#dashboardLoans').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
        }
    }

    const scanLog = [];

    /**
     * Wartung: Rendert Daten als HTML in der Oberfläche.
     * Aufgerufen von: queueScan().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function renderScanLog() {
        if (!scanLog.length) {
            $('#scanLog').innerHTML = '<div class="empty">Noch keine Scans in dieser Sitzung.</div>';
            return;
        }
        $('#scanLog').innerHTML = scanLog.slice(0, 30).map(entry => `
            <div class="scan-entry ${entry.status}">
                <span class="scan-dot"></span>
                <div class="grow">
                    <div style="font-weight:800">${esc(entry.title || entry.isbn)}</div>
                    <div class="small muted">${esc(entry.message || entry.isbn)}</div>
                </div>
                <span class="small nowrap">${esc(entry.time)}</span>
            </div>`).join('');
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: queueScan().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function setScanPending(delta) {
        state.scanPending = Math.max(0, state.scanPending + delta);
        $('#scanPending').textContent = `${state.scanPending} offen`;
        $('#scanPending').className = `badge ${state.scanPending ? 'warn' : 'info'}`;
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: queueScan().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function uuid() {
        if (crypto.randomUUID) return crypto.randomUUID();
        return `${Date.now()}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`;
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: queueScan(), setActiveLocation(), submitLibraryReturnScan().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function beep(success = true) {
        try {
            const Context = window.AudioContext || window.webkitAudioContext;
            if (!Context) return;
            const ctx = new Context();
            const oscillator = ctx.createOscillator();
            const gain = ctx.createGain();
            oscillator.frequency.value = success ? 880 : 220;
            gain.gain.value = .04;
            oscillator.connect(gain).connect(ctx.destination);
            oscillator.start();
            oscillator.stop(ctx.currentTime + (success ? .07 : .18));
            oscillator.onended = () => ctx.close();
        } catch {}
        if (navigator.vibrate) navigator.vibrate(success ? 35 : [70, 40, 70]);
    }

    /**
     * Wartung: Normalisiert eine Eingabe im Browser.
     * Aufgerufen von: looksLikeLocationBarcode(), openCamera(), queueScan().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function normalizeLocationBarcode(raw) {
        return String(raw || '').trim()
            .replace(/[ßẞ§–—−_]/g, '-')
            .replace(/\s+/g, '')
            .replace(/^\*|\*$/g, '')
            .toUpperCase();
    }

    /**
     * Wartung: Erkennt Eingaben anhand ihres Musters.
     * Aufgerufen von: Globaler Ablauf/API/Events, openCamera(), queueScan().
     * Abhängigkeiten: normalizeLocationBarcode().
     */
    function looksLikeLocationBarcode(raw) {
        return /^TRIAMO-\d{5}-\d{1,3}$/.test(normalizeLocationBarcode(raw));
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: openCamera(), submitScanInput().
     * Abhängigkeiten: activeLocation(), api(), beep(), loadLocations(), looksLikeLocationBarcode(), normalizeLocationBarcode(), renderScanLog(), +5 weitere.
     */
    async function queueScan(raw) {
        const scanned = String(raw || '').trim();
        if (!scanned) return;

        const possibleLocationCode = normalizeLocationBarcode(scanned);
        if (looksLikeLocationBarcode(possibleLocationCode)) {
            try {
                const data = await api('location_resolve', {params: {code: possibleLocationCode}});
                if (!state.locations.some(item => item.id === Number(data.location.id))) {
                    await loadLocations();
                }
                setActiveLocation(Number(data.location.id), true);
                scanLog.unshift({
                    isbn: possibleLocationCode, status: 'ok', title: 'Standort gewechselt',
                    message: data.location.path,
                    time: new Date().toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit', second: '2-digit'})
                });
                renderScanLog();
            } catch (error) {
                toast(error.message, 'error');
                beep(false);
            } finally {
                if (state.view === 'scan') $('#scanInput').focus();
            }
            return;
        }

        const isbn = scanned;
        const selectedLocation = activeLocation();
        if (!selectedLocation) {
            toast('Bitte zuerst einen Standort auswählen.', 'error');
            beep(false);
            return;
        }
        const entry = {
            token: uuid(), isbn, status: 'pending', title: isbn,
            message: `Wird gespeichert · ${selectedLocation.path}`,
            time: new Date().toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit', second: '2-digit'})
        };
        scanLog.unshift(entry);
        renderScanLog();
        setScanPending(1);

        const isLibrary = $('#scanIsLibrary').checked;
        const libraryName = $('#scanLibraryName').value.trim();
        const libraryDue = $('#scanLibraryDue').value;
        localStorage.setItem('hb_library_name', libraryName);

        if (isLibrary && !libraryDue) {
            entry.status = 'error';
            entry.message = 'Bitte zuerst die Rückgabefrist des Büchereibuchs eintragen.';
            setScanPending(-1);
            renderScanLog();
            beep(false);
            return;
        }

        try {
            const data = await api('scan', {
                method: 'POST',
                data: {
                    isbn, location_id: selectedLocation.id, scan_token: entry.token,
                    is_library: isLibrary, library_name: libraryName, library_due_at: libraryDue
                }
            });
            entry.status = 'ok';
            entry.title = data.book?.title || isbn;
            entry.message = (data.copy_created && data.copy?.inventory_no
                ? `${data.message} · ${data.copy.inventory_no}`
                : data.message) + ` · ${selectedLocation.path}`;
            beep(true);
            scheduleWorker();
        } catch (error) {
            entry.status = 'error';
            entry.message = error.message;
            beep(false);
        } finally {
            setScanPending(-1);
            renderScanLog();
            if (state.view === 'scan') $('#scanInput').focus();
        }
    }

    /**
     * Wartung: Sendet ein Formular oder eine Scan-Eingabe an die API.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: queueScan().
     */
    function submitScanInput() {
        const input = $('#scanInput');
        const value = input.value;
        input.value = '';
        queueScan(value);
    }

    $('#scanInput').addEventListener('keydown', event => {
        const raw = event.currentTarget.value;
        const isbnLength = raw.replace(/[^0-9X]/gi, '').length;
        const shouldSubmit = event.key === 'Enter'
            || (event.key === 'Tab' && (looksLikeLocationBarcode(raw) || isbnLength >= 10));
        if (shouldSubmit) {
            event.preventDefault();
            submitScanInput();
        }
    });
    $('#scanSubmitBtn').addEventListener('click', submitScanInput);
    $('#scanLibraryName').value = localStorage.getItem('hb_library_name') || '';
    $('#scanLibraryDue').value = isoDateAfter(28);
    $('#scanIsLibrary').addEventListener('change', event => {
        $('#scanLibraryFields').classList.toggle('hidden', !event.currentTarget.checked);
        setTimeout(() => (event.currentTarget.checked ? $('#scanLibraryDue') : $('#scanInput'))?.focus(), 20);
    });

    let workerTimer = null;
    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: queueScan(), retryMetadata().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function scheduleWorker() {
        clearTimeout(workerTimer);
        workerTimer = setTimeout(runWorker, 350);
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events, runWorker().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function markBooksReloadAvailable(message = 'Änderungen am Bestand oder an Metadaten sind verfügbar.') {
        state.pendingBookReload = true;
        const box = $('#booksReloadNotice');
        if (!box) return;
        box.classList.toggle('hidden', state.view !== 'books');
        const btn = '<button id="booksReloadBtn" class="btn smallbtn" type="button">Neu laden</button>';
        box.innerHTML = `${esc(message)} ${btn}`;
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events, loadBooks().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function clearBooksReloadNotice() {
        state.pendingBookReload = false;
        const box = $('#booksReloadNotice');
        if (box) box.classList.add('hidden');
    }

    /**
     * Wartung: Rendert Daten als HTML in der Oberfläche.
     * Aufgerufen von: loadBooks().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function renderBookStats(stats) {
        const box = $('#booksStats');
        if (!box) return;
        stats = stats || {};
        const total = Number(stats.total || 0);
        const found = Number(stats.found || 0);
        const shown = Number(stats.shown || 0);
        const households = Number(stats.households || 1);
        const all = Boolean(stats.all_households);
        const parts = [
            ['Gesamtbestand', total],
            ['Gefunden', found],
            ['Angezeigt', shown],
        ];
        if (all) parts.push(['Haushalte', households]);
        box.innerHTML = parts.map(([label, value]) => `<div class="stat"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`).join('');
    }


    /**
     * Wartung: Rendert Daten als HTML in der Oberfläche.
     * Aufgerufen von: loadBooks().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function renderBookPagination(pagination) {
        pagination = pagination || {offset:0, limit:state.bookLimit, found:0, has_more:false};
        state.lastBookPagination = pagination;
        const found = Number(pagination.found || 0);
        const limitRaw = String(pagination.limit || state.bookLimit || '200');
        const offset = Number(pagination.offset || 0);
        const boxes = ['#booksPaginationTop', '#booksPaginationBottom'].map(sel => $(sel)).filter(Boolean);
        if (!boxes.length) return;
        if (limitRaw === 'all' || found <= Number(pagination.limit_numeric || limitRaw || 0)) {
            boxes.forEach(box => box.innerHTML = found ? `<span class="muted small">Alle ${found} Treffer werden angezeigt.</span>` : '');
            return;
        }
        const limit = Math.max(1, Number(pagination.limit_numeric || limitRaw || 200));
        const page = Math.floor(offset / limit) + 1;
        const pages = Math.max(1, Math.ceil(found / limit));
        const from = found ? offset + 1 : 0;
        const to = Math.min(found, offset + limit);
        const html = `<div class="row" style="gap:8px">
            <button class="btn secondary smallbtn" data-books-page="first" ${page <= 1 ? 'disabled' : ''}>« Anfang</button>
            <button class="btn secondary smallbtn" data-books-page="prev" ${page <= 1 ? 'disabled' : ''}>‹ Zurück</button>
            <span class="muted small">${from}–${to} von ${found} · Seite ${page}/${pages}</span>
            <button class="btn secondary smallbtn" data-books-page="next" ${page >= pages ? 'disabled' : ''}>Weiter ›</button>
            <button class="btn secondary smallbtn" data-books-page="last" ${page >= pages ? 'disabled' : ''}>Ende »</button>
        </div>`;
        boxes.forEach(box => box.innerHTML = html);
    }

    /**
     * Wartung: Rendert Daten als HTML in der Oberfläche.
     * Aufgerufen von: loadPublicBooks().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function renderPublicBookStats(stats) {
        const box = $('#publicBooksStats');
        if (!box) return;
        stats = stats || {};
        box.innerHTML = [
            ['Freigegebene Bücher', Number(stats.total || 0)],
            ['Gefunden', Number(stats.found || 0)],
            ['Angezeigt', Number(stats.shown || 0)],
        ].map(([label, value]) => `<div class="stat"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`).join('');
    }


    /**
     * Wartung: Lädt Daten für die jeweilige Ansicht und aktualisiert die Oberfläche.
     * Aufgerufen von: goTo().
     * Abhängigkeiten: api().
     */
    async function loadNerdStats() {
        $('#nerdStatsCards').innerHTML = '<div class="stat"><span>Lade</span><strong>…</strong></div>';
        $('#tagCloud').innerHTML = '<div class="empty">Wird geladen …</div>';
        $('#topAuthors').innerHTML = '<div class="empty">Wird geladen …</div>';
        $('#yearTimeline').innerHTML = '<div class="empty">Wird geladen …</div>';
        try {
            const data = await api('nerd_stats');
            const s = data.stats || {};
            $('#nerdStatsCards').innerHTML = [
                ['Titel', s.titles || 0],
                ['Exemplare', s.active_copies || s.copies || 0],
                ['Seiten je Titel', s.pages_by_titles || 0],
                ['Seiten inkl. Exemplare', s.pages_by_copies || 0],
            ].map(([label, value]) => `<div class="stat"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`).join('');

            const tags = s.tags || [];
            $('#tagCloud').innerHTML = tags.length ? tags.map(tag => {
                const size = Math.max(0.85, Math.min(1.9, 0.82 + Math.log2(Number(tag.count || 1) + 1) / 3));
                return `<button type="button" data-tag-search="${attr(tag.label)}" style="font-size:${size.toFixed(2)}rem">${esc(tag.label)} <span class="muted">${Number(tag.count || 0)}</span></button>`;
            }).join('') : '<div class="empty">Noch keine Schlagwörter vorhanden.</div>';

            const authors = s.top_authors || [];
            $('#topAuthors').innerHTML = authors.length ? `<div class="mini-list">${authors.map(row => `<button class="btn secondary smallbtn" type="button" data-tag-search="${attr(row.authors)}">${esc(row.authors)} <span class="badge">${Number(row.count || 0)}</span></button>`).join('')}</div>` : '<div class="empty">Noch keine Autorendaten vorhanden.</div>';

            const years = s.timeline || [];
            const max = Math.max(1, ...years.map(row => Number(row.count || 0)));
            $('#yearTimeline').innerHTML = years.length ? `<div class="bar-list">${years.map(row => `<div class="bar-row"><div class="bar-label">${esc(row.year)}</div><div class="bar-track"><div class="bar-fill" style="width:${Math.max(3, Number(row.count || 0) / max * 100)}%"></div></div><div class="small muted">${Number(row.count || 0)}</div></div>`).join('')}</div>` : '<div class="empty">Noch keine Jahreszahlen vorhanden.</div>';
        } catch (error) {
            $('#nerdStatsCards').innerHTML = '';
            $('#tagCloud').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
        }
    }

    $('#refreshNerdStatsBtn')?.addEventListener('click', loadNerdStats);

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: showApp().
     * Abhängigkeiten: api(), loadMetadataQueue(), markBooksReloadAvailable().
     */
    async function runWorker() {
        if (!state.user || state.workerRunning) return;
        state.workerRunning = true;
        $('#workerStatus').textContent = 'Metadaten werden geladen …';
        try {
            const data = await api('process_jobs', {method: 'POST'});
            if (data.processed) {
                $('#workerStatus').textContent = `${data.processed} Metadatenauftrag aktualisiert`;
                markBooksReloadAvailable(`${data.processed} Metadatenauftrag wurde aktualisiert.`);
                if (state.view === 'metadata') loadMetadataQueue();
            } else if (data.remaining) {
                const seconds = Math.max(1, Number(data.next_delay_seconds || 1));
                $('#workerStatus').textContent = seconds > 1
                    ? `Nächster Metadatenversuch in etwa ${seconds} Sekunden`
                    : 'Nächster Metadatenversuch läuft gleich';
            } else {
                $('#workerStatus').textContent = data.failed
                    ? 'Mindestens eine Metadatenquelle konnte nach mehreren Versuchen nicht geladen werden'
                    : 'Metadatenwarteschlange bereit';
            }

            if (data.remaining) {
                const seconds = Math.max(1, Number(data.next_delay_seconds || 1));
                const waitMs = Math.min(60000, seconds * 1000 + 350);
                clearTimeout(workerTimer);
                workerTimer = setTimeout(runWorker, waitMs);
            } else if (data.processed) {
                clearTimeout(workerTimer);
                workerTimer = setTimeout(runWorker, 500);
            }
        } catch (error) {
            $('#workerStatus').textContent = error.message;
        } finally {
            state.workerRunning = false;
        }
    }

    /**
     * Wartung: Rendert Daten als HTML in der Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events, loadMetadataQueue().
     * Abhängigkeiten: fmtDate().
     */
    function renderMetadataQueue(data) {
        const summary = data.summary || {};
        const queueCards = [
            ['Aufträge gesamt', summary.total || 0],
            ['Wartend', summary.waiting || 0],
            ['In Arbeit', summary.processing || 0],
            ['Fehler / abgebrochen', summary.failed || 0],
            ['Erledigt', summary.done || 0],
            ['Nächster Versuch', summary.next_delay_seconds ? `in ${summary.next_delay_seconds}s` : 'bereit']
        ];
        if (summary.google_backoff_until) queueCards.push(['Google pausiert bis', fmtDate(summary.google_backoff_until, true)]);
        $('#metadataQueueStats').innerHTML = queueCards.map(([label, value]) => `<div class="stat"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`).join('');

        const jobs = data.jobs || [];
        if (!jobs.length) {
            $('#metadataQueueResults').innerHTML = '<div class="empty">Keine Metadatenaufträge vorhanden.</div>';
            return;
        }
        const statusText = status => ({pending:'Wartend', retry:'Wiederholung', processing:'In Arbeit', failed:'Fehler / abgebrochen', done:'Erledigt'}[status] || status);
        const statusClass = status => status === 'done' ? 'ok' : status === 'failed' ? 'bad' : status === 'processing' ? 'warn' : 'info';
        $('#metadataQueueResults').innerHTML = `<div class="table-wrap"><table>
            <thead><tr><th>Buch</th><th>Status</th><th>Versuche</th><th>Nächster Lauf</th><th>Quellen</th><th>Fehler</th><th>Aktion</th></tr></thead>
            <tbody>${jobs.map(job => `<tr>
                <td>${job.book_id ? `<button class="title-link" data-book-open="${job.book_id}">${esc(job.title || job.isbn13 || job.isbn10 || 'Unbekanntes Buch')}</button>` : 'Unbekanntes Buch'}<div class="small muted">${esc(job.isbn13 || job.isbn10 || '')}</div></td>
                <td><span class="badge ${statusClass(job.status)}">${esc(statusText(job.status))}</span><div class="small muted">Buch: ${esc(job.metadata_status || '–')}</div></td>
                <td>${Number(job.attempts || 0)} / ${Number(maxJobAttempts || 4)}</td>
                <td>${job.status === 'pending' || job.status === 'retry' ? (Number(job.next_delay_seconds || 0) > 0 ? `in ${Number(job.next_delay_seconds)}s` : 'bereit') : esc(job.updated_at || '–')}</td>
                <td>${esc(job.source_summary || 'Noch keine Quellenantwort gespeichert')}</td>
                <td>${esc(job.last_error || job.metadata_error || '')}</td>
                <td>${['pending','retry','processing'].includes(job.status) ? `<button class="btn danger smallbtn" data-cancel-metadata-job="${job.id}">Abbrechen</button>` : '–'}</td>
            </tr>`).join('')}</tbody>
        </table></div>`;
    }

    /**
     * Wartung: Lädt Daten für die jeweilige Ansicht und aktualisiert die Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events, goTo(), runWorker().
     * Abhängigkeiten: api(), renderMetadataQueue().
     */
    async function loadMetadataQueue() {
        if (!state.user) return;
        $('#metadataQueueResults').innerHTML = '<div class="empty">Warteschlange wird geladen …</div>';
        try {
            const data = await api('metadata_queue');
            renderMetadataQueue(data);
        } catch (error) {
            $('#metadataQueueResults').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
        }
    }

    $('#refreshMetadataQueueBtn')?.addEventListener('click', loadMetadataQueue);
    $('#runMetadataQueueBtn')?.addEventListener('click', async () => {
        const button = $('#runMetadataQueueBtn');
        setBusy(button, true);
        try {
            const data = await api('process_jobs', {method: 'POST'});
            toast(data.processed ? `${data.processed} Auftrag verarbeitet.` : 'Kein Auftrag bereit.');
            await loadMetadataQueue();
            if (data.processed) markBooksReloadAvailable(`${data.processed} Metadatenauftrag wurde aktualisiert.`);
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setBusy(button, false);
        }
    });

    /**
     * Wartung: Stoppt laufende Browserressourcen wie Kamera oder Timer.
     * Aufgerufen von: closeModal(), openCamera().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function stopCamera() {
        if (state.cameraLoop) {
            cancelAnimationFrame(state.cameraLoop);
            state.cameraLoop = null;
        }
        if (state.cameraStream) {
            state.cameraStream.getTracks().forEach(track => track.stop());
            state.cameraStream = null;
        }
    }

    /**
     * Wartung: Öffnet einen Dialog oder Detailbereich in der Oberfläche.
     * Aufgerufen von: derzeit kein direkter interner Aufrufer.
     * Abhängigkeiten: closeModal(), looksLikeLocationBarcode(), normalizeLocationBarcode(), openModal(), queueScan(), stopCamera().
     */
    async function openCamera() {
        if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
            openModal('Kamera-Scan nicht verfügbar', `
                <div class="notice danger-note">Dieser Browser unterstützt die eingebaute Barcode-Erkennung nicht. Verwende Chrome oder Edge über HTTPS oder kopple einen Bluetooth-Scanner, der als Tastatur arbeitet.</div>`);
            return;
        }

        openModal('ISBN mit Kamera scannen', `
            <div class="stack">
                <div class="camera-box"><video id="cameraVideo" playsinline muted></video><div class="camera-line"></div></div>
                <div id="cameraHint" class="muted">Barcode ruhig und gut beleuchtet in die Mitte halten.</div>
            </div>`, '<button class="btn secondary" data-modal-close>Abbrechen</button>');

        try {
            const formats = await BarcodeDetector.getSupportedFormats();
            const wanted = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_39'].filter(format => formats.includes(format));
            if (!wanted.length) throw new Error('Der Browser unterstützt keine passenden Barcodes.');
            const detector = new BarcodeDetector({formats: wanted});
            state.cameraStream = await navigator.mediaDevices.getUserMedia({
                video: {facingMode: {ideal: 'environment'}, width: {ideal: 1280}, height: {ideal: 720}},
                audio: false
            });
            const video = $('#cameraVideo');
            video.srcObject = state.cameraStream;
            await video.play();

            let lastCheck = 0;
            const detect = async timestamp => {
                if (!state.cameraStream || !document.body.contains(video)) return;
                if (timestamp - lastCheck > 220 && video.readyState >= 2) {
                    lastCheck = timestamp;
                    try {
                        const codes = await detector.detect(video);
                        const code = codes.find(item => {
                            const raw = String(item.rawValue || '').trim();
                            const locationCode = normalizeLocationBarcode(raw);
                            const isbn = raw.replace(/[^0-9X]/gi, '');
                            return looksLikeLocationBarcode(locationCode)
                                || /^(97[89]\d{10}|\d{9}[\dX])$/i.test(isbn);
                        });
                        if (code) {
                            const raw = String(code.rawValue || '').trim();
                            const locationCode = normalizeLocationBarcode(raw);
                            const value = looksLikeLocationBarcode(locationCode)
                                ? locationCode
                                : raw.replace(/[^0-9X]/gi, '');
                            stopCamera();
                            closeModal();
                            $('#scanInput').value = '';
                            queueScan(value);
                            return;
                        }
                    } catch {}
                }
                state.cameraLoop = requestAnimationFrame(detect);
            };
            state.cameraLoop = requestAnimationFrame(detect);
        } catch (error) {
            stopCamera();
            $('#cameraHint').textContent = error.message || 'Die Kamera konnte nicht gestartet werden.';
            $('#cameraHint').className = 'notice danger-note';
        }
    }

    $('#cameraBtn').addEventListener('click', openCamera);


    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: renderBooks().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function bookActions(book) {
        const householdId = Number(book.household_id || state.user.active_household_id);
        const context = ` data-household-id="${householdId}"`;
        const actions = [`<button class="btn secondary smallbtn" data-book-open="${book.id}"${context}>Details</button>`];
        if (book.is_deleted) {
            if (book.can_manage && !book.library_history_only) {
                actions.push(`<button class="btn secondary smallbtn" data-book-restore="${book.id}"${context}>Wiederherstellen</button>`);
            }
            return actions.join('');
        }
        if (book.can_manage && book.copies_available > 0) {
            actions.push(`<button class="btn smallbtn" data-loan-book="${book.id}"${context}>Verleihen</button>`);
        }
        if (book.can_manage || book.visibility === 'lendable') {
            actions.push(`<button class="btn secondary smallbtn" data-reserve-book="${book.id}"${context}>Vormerken</button>`);
        }
        return actions.join('');
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: publicationLine(), renderBooks().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function searchMetaLink(value, label = null) {
        if (!value) return '';
        return `<button class="meta-link" data-search-value="${attr(value)}">${esc(label || value)}</button>`;
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: renderBooks().
     * Abhängigkeiten: searchMetaLink().
     */
    function publicationLine(book) {
        const year = String(book.published_date || '').match(/\d{4}/)?.[0] || book.published_date || '';
        const parts = [];
        if (book.publisher) parts.push(searchMetaLink(book.publisher));
        if (year) parts.push(searchMetaLink(year));
        return parts.join(' · ');
    }

    /**
     * Wartung: Rendert Daten als HTML in der Oberfläche.
     * Aufgerufen von: loadBooks().
     * Abhängigkeiten: bookActions(), coverHtml(), fmtDate(), publicationLine(), searchMetaLink(), statusBadge().
     */
    function renderBooks(books) {
        if (!books.length) {
            $('#booksResults').innerHTML = `<div class="empty">${$('#allHouseholdsToggle')?.checked ? 'In den freigegebenen Haushalten wurden keine passenden Bücher gefunden.' : 'In diesem Bestand wurden keine passenden Bücher gefunden.'}</div>`;
            return;
        }

        const badgeLine = book => `<div class="statusline">
            ${$('#allHouseholdsToggle')?.checked && book.household_name ? `<span class="badge info">${esc(book.household_name)}</span>` : ''}
            ${book.is_deleted ? '<span class="badge bad">Archiviert</span>' : ''}
            ${book.visibility === 'internal' ? '<span class="badge visibility-internal">Nur intern</span>' : book.visibility === 'visible' ? '<span class="badge visibility-visible">Sichtbar</span>' : '<span class="badge visibility-lendable">Verleihbar</span>'}
            ${book.library_active ? `<span class="badge warn">${book.library_active} Bücherei</span>` : book.library_total ? '<span class="badge info">Früheres Büchereibuch</span>' : ''}
            ${!book.is_deleted ? `<span class="badge ok">${book.copies_available} verfügbar</span>` : ''}
            ${book.copies_loaned ? `<span class="badge warn">${book.copies_loaned} ausgeliehen</span>` : ''}
            ${book.copies_reserved ? `<span class="badge info">${book.copies_reserved} reserviert</span>` : ''}
            ${Number(book.file_count || 0) ? `<span class="badge info">📎 ${Number(book.file_count)} Datei${Number(book.file_count) === 1 ? '' : 'en'}</span>` : ''}
            <span class="badge">${book.copies_total} aktiv · ${book.copies_all} historisch</span>
        </div>`;

        $('#booksResults').innerHTML = `
            <div class="table-wrap desktop-table">
                <table>
                    <thead><tr><th>Buch</th><th>ISBN</th><th>Metadaten</th><th>Bestand</th><th>Aktionen</th></tr></thead>
                    <tbody>${books.map(book => `<tr class="${book.is_deleted ? 'archived-row' : ''}">
                        <td><div class="book-cell">${coverHtml(book)}<div>
                            <button class="title-link" data-book-open="${book.id}" data-household-id="${Number(book.household_id || state.user.active_household_id)}">${esc(book.title)}</button>
                            <div class="book-meta">${book.authors ? searchMetaLink(book.authors) : ''}</div>
                            <div class="book-meta">${publicationLine(book)}</div>
                            ${book.nearest_library_due_at && !book.is_deleted ? `<div class="small" style="color:var(--accent)">Bücherei-Rückgabe: ${fmtDate(book.nearest_library_due_at)}</div>` : ''}
                        </div></div></td>
                        <td>${esc(book.isbn13 || book.isbn10 || '–')}</td>
                        <td>${statusBadge(book.metadata_status)}</td>
                        <td>${badgeLine(book)}</td>
                        <td><div class="table-actions">${bookActions(book)}</div></td>
                    </tr>`).join('')}</tbody>
                </table>
            </div>
            <div class="mobile-cards book-grid">${books.map(book => `<div class="book-card ${book.is_deleted ? 'archived' : ''}">
                ${coverHtml(book)}
                <div>
                    <button class="title-link" data-book-open="${book.id}" data-household-id="${Number(book.household_id || state.user.active_household_id)}">${esc(book.title)}</button>
                    <div class="book-meta">${book.authors ? searchMetaLink(book.authors) : ''}</div>
                    <div class="book-meta">${publicationLine(book)}</div>
                    ${badgeLine(book)}
                    <div class="table-actions" style="margin-top:10px">${bookActions(book)}</div>
                </div>
            </div>`).join('')}</div>`;
    }

    /**
     * Wartung: Lädt Daten für die jeweilige Ansicht und aktualisiert die Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events, adoptCommunityMetadata(), applyBookSearch(), deleteBook(), deleteCopy(), editCopy(), goTo(), +10 weitere.
     * Abhängigkeiten: api(), clearBooksReloadNotice(), loadOnline(), renderBookPagination(), renderBookStats(), renderBooks().
     */
    async function loadBooks() {
        const requestSeq = ++state.bookRequestSeq;
        const q = $('#bookSearch').value.trim();
        const availability = $('#availabilityFilter').value;
        const kind = $('#bookKindFilter').value;
        const locationFilter = state.bookLocationFilter || $('#bookLocationFilter')?.value || '';
        const allHouseholds = Boolean($('#allHouseholdsToggle')?.checked) && locationFilter === '';
        $('#booksResults').innerHTML = '<div class="empty">Bestand wird geladen …</div>';

        try {
            const data = await api('books', {params: {q, availability, kind, location: locationFilter, all_households: allHouseholds ? 1 : 0, limit: state.bookLimit, offset: state.bookOffset}});
            if (requestSeq !== state.bookRequestSeq) return;
            renderBookStats(data.stats || {total: data.books.length, found: data.books.length, shown: data.books.length});
            renderBookPagination(data.pagination || {offset: state.bookOffset, limit: state.bookLimit, found: data.stats?.found || data.books.length});
            renderBooks(data.books);
            clearBooksReloadNotice();
        } catch (error) {
            if (requestSeq !== state.bookRequestSeq) return;
            $('#booksResults').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
            renderBookPagination({found:0, offset:0, limit:state.bookLimit});
        }

        if (requestSeq !== state.bookRequestSeq) return;
        if ($('#onlineToggle').checked && q.length >= 2 && !locationFilter) {
            loadOnline(q, requestSeq);
        } else {
            $('#onlineResults').classList.add('hidden');
            $('#onlineResults').innerHTML = '';
        }
    }

    /**
     * Wartung: Lädt Daten für die jeweilige Ansicht und aktualisiert die Oberfläche.
     * Aufgerufen von: loadBooks().
     * Abhängigkeiten: api(), coverHtml(), safeUrl().
     */
    async function loadOnline(q, requestSeq = state.bookRequestSeq) {
        $('#onlineResults').classList.remove('hidden');
        $('#onlineResults').innerHTML = '<h2>Online-Ergebnisse</h2><div class="empty">Online-Portale werden durchsucht …</div>';
        try {
            const data = await api('online_search', {params: {q}});
            if (requestSeq !== state.bookRequestSeq) return;
            const portalLinks = `
                <div class="row" style="margin:8px 0 14px">
                    <a class="btn secondary smallbtn" target="_blank" rel="noopener" href="https://www.google.com/search?tbm=bks&q=${encodeURIComponent(q)}">Google Books</a>
                    <a class="btn secondary smallbtn" target="_blank" rel="noopener" href="https://openlibrary.org/search?q=${encodeURIComponent(q)}">Open Library</a>
                    <a class="btn secondary smallbtn" target="_blank" rel="noopener" href="https://www.justbooks.de/srl/${encodeURIComponent(q)}">JustBooks</a>
                    <a class="btn secondary smallbtn" target="_blank" rel="noopener" href="https://portal.dnb.de/opac/simpleSearch?query=${encodeURIComponent(q)}">DNB</a>
                    <a class="btn secondary smallbtn" target="_blank" rel="noopener" href="https://search.worldcat.org/search?q=${encodeURIComponent(q)}">WorldCat</a>
                    <a class="btn secondary smallbtn" target="_blank" rel="noopener" href="https://www.amazon.de/s?k=${encodeURIComponent(q)}">Amazon.de</a>
                </div>`;

            if (!data.results.length) {
                $('#onlineResults').innerHTML = `<h2>Online-Ergebnisse</h2>${portalLinks}<div class="empty">Keine API-Ergebnisse gefunden. Die direkten Portal-Suchen stehen oben bereit.</div>`;
                return;
            }

            $('#onlineResults').innerHTML = `<h2>Online-Ergebnisse</h2>${portalLinks}
                <div class="online-grid">${data.results.map(item => `<article class="online-card">
                    ${coverHtml(item)}
                    <div class="grow">
                        <div class="book-title">${esc(item.title)}</div>
                        <div class="book-meta">${esc(item.authors || '')}</div>
                        <div class="small muted">${esc(item.publisher || '')}${item.published_date ? ' · ' + esc(item.published_date) : ''}</div>
                        <div class="statusline"><span class="badge info">${esc(item.source)}</span>${item.isbn ? `<span class="badge">${esc(item.isbn)}</span>` : ''}</div>
                        ${safeUrl(item.url) ? `<a class="small" href="${attr(safeUrl(item.url))}" target="_blank" rel="noopener">Beim Portal öffnen</a>` : ''}
                    </div>
                </article>`).join('')}</div>`;
        } catch (error) {
            if (requestSeq !== state.bookRequestSeq) return;
            $('#onlineResults').innerHTML = `<h2>Online-Ergebnisse</h2><div class="empty">${esc(error.message)}</div>`;
        }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: goTo(), loadBooks().
     */
    function applyBookSearch(value) {
        clearTimeout(state.searchTimer);
        state.bookOffset = 0;
        $('#bookSearch').value = String(value || '');
        if (state.view === 'books') {
            loadBooks();
        } else {
            goTo('books');
        }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), bootstrap(), goTo().
     */
    async function ensureHouseholdContext(householdId) {
        householdId = Number(householdId || state.user.active_household_id);
        if (!householdId || householdId === Number(state.user.active_household_id)) return;
        const preserved = {
            q: $('#bookSearch')?.value || '',
            availability: $('#availabilityFilter')?.value || 'all',
            kind: $('#bookKindFilter')?.value || 'all',
            location: '',
            locationLabel: '',
            limit: $('#bookPageSize')?.value || state.bookLimit,
            online: Boolean($('#onlineToggle')?.checked),
            all: Boolean($('#allHouseholdsToggle')?.checked),
        };
        await api('household_switch', {method: 'POST', data: {household_id: householdId}});
        await bootstrap();
        $('#bookSearch').value = preserved.q;
        $('#availabilityFilter').value = preserved.availability;
        $('#bookKindFilter').value = preserved.kind;
        state.bookLocationFilter = preserved.location;
        state.bookLocationLabel = preserved.locationLabel;
        renderBookLocationOptions();
        $('#bookPageSize').value = preserved.limit; state.bookLimit = preserved.limit; state.bookOffset = 0;
        $('#onlineToggle').checked = preserved.online;
        $('#allHouseholdsToggle').checked = preserved.all && !$('#allHouseholdsLabel').classList.contains('hidden');
        goTo('books');
    }

    /**
     * Wartung: Setzt oder löscht den Standortfilter der Bestandsseite.
     * Aufgerufen von: Standortseite und Filterbedienelemente.
     * Abhängigkeiten: loadBooks(), renderBookLocationOptions().
     */
    function setBookLocationFilter(value = '', label = '') {
        state.bookLocationFilter = String(value || '');
        state.bookLocationLabel = String(label || '');
        state.bookOffset = 0;
        const allHouseholdsToggle = $('#allHouseholdsToggle');
        if (allHouseholdsToggle) {
            if (state.bookLocationFilter) allHouseholdsToggle.checked = false;
            allHouseholdsToggle.disabled = Boolean(state.bookLocationFilter);
        }
        renderBookLocationOptions();
        if ($('#bookLocationFilter')) $('#bookLocationFilter').value = state.bookLocationFilter;
        if (!state.bookLocationFilter && $('#bookLocationScan')) $('#bookLocationScan').value = '';
        if (state.view === 'books') loadBooks();
        else goTo('books');
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: derzeit kein direkter interner Aufrufer.
     * Abhängigkeiten: loadBooks().
     */
    function reloadBooksFromStart() { state.bookOffset = 0; loadBooks(); }
    $('#bookSearch').addEventListener('input', () => {
        clearTimeout(state.searchTimer);
        state.bookOffset = 0;
        state.searchTimer = setTimeout(loadBooks, 280);
    });
    $('#availabilityFilter').addEventListener('change', reloadBooksFromStart);
    $('#bookKindFilter').addEventListener('change', reloadBooksFromStart);
    $('#bookLocationFilter').addEventListener('change', event => {
        const option = event.currentTarget.selectedOptions[0];
        $('#bookLocationScan').value = '';
        setBookLocationFilter(event.currentTarget.value, option?.textContent || '');
    });
    const applyScannedBookLocation = () => {
        const raw = $('#bookLocationScan').value.trim();
        if (!raw) {
            setBookLocationFilter('');
            return;
        }
        const code = normalizeLocationBarcode(raw);
        if (looksLikeLocationBarcode(code)) {
            setBookLocationFilter(`code:${code}`, `Barcode ${code}`);
            return;
        }

        const needle = raw.toLocaleLowerCase('de-DE');
        const matches = [];
        for (const group of state.locationGroups || []) {
            const active = (group.locations || []).filter(item => item.active);
            if (!active.length) continue;
            const groupText = `${group.path || ''} ${group.group_code || ''}`.toLocaleLowerCase('de-DE');
            if (groupText.includes(needle)) {
                matches.push({
                    value: group.is_loose ? `id:${Number(active[0].id)}` : `group:${group.group_code}`,
                    label: group.path || raw,
                });
                continue;
            }
            for (const item of active) {
                const itemText = `${item.path || ''} ${item.code || ''} Fach ${item.compartment_no || ''}`.toLocaleLowerCase('de-DE');
                if (itemText.includes(needle)) {
                    matches.push({value: `id:${Number(item.id)}`, label: item.path || item.code || raw});
                }
            }
        }
        const unique = [...new Map(matches.map(item => [item.value, item])).values()];
        if (unique.length === 1) {
            setBookLocationFilter(unique[0].value, unique[0].label);
        } else if (unique.length > 1) {
            toast('Die Eingabe passt zu mehreren Standorten. Bitte im Pulldownfeld genauer auswählen.', 'error');
        } else {
            toast('Standort nicht gefunden. Bitte den Namen genauer eingeben oder einen Barcode im Format TRIAMO-12345-1 scannen.', 'error');
        }
    };
    $('#bookLocationApplyBtn').addEventListener('click', applyScannedBookLocation);
    $('#bookLocationScan').addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyScannedBookLocation();
        }
    });
    $('#bookLocationClearBtn').addEventListener('click', () => setBookLocationFilter(''));
    $('#bookPageSize').addEventListener('change', () => { state.bookLimit = $('#bookPageSize').value; state.bookOffset = 0; loadBooks(); });
    $('#onlineToggle').addEventListener('change', reloadBooksFromStart);
    $('#allHouseholdsToggle').addEventListener('change', reloadBooksFromStart);

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events, editCopy(), openAddCopy().
     * Abhängigkeiten: locationOptions().
     */
    function locationSelectHtml(selectedId = null, label = 'Standort') {
        const selected = selectedId || state.activeLocationId || state.locations.find(item => item.is_loose)?.id || '';
        return `<label class="full">${esc(label)}<select name="location_id" required>${locationOptions(selected)}</select></label>`;
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events, editCopy(), openAddCopy().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function libraryFieldsHtml(values = {}) {
        const checked = values.ownership === 'library' || values.is_library;
        return `
            <label class="checkbox full"><input name="is_library" type="checkbox" value="1" ${checked ? 'checked' : ''}>Büchereibuch</label>
            <div class="field-grid full ${checked ? '' : 'hidden'}" data-library-fields>
                <label>Bücherei<input name="library_name" maxlength="255" value="${attr(values.library_name || '')}" placeholder="z. B. Stadtbibliothek"></label>
                <label>Rückgabe bis<input name="library_due_at" type="date" value="${attr(values.library_due_at ? String(values.library_due_at).slice(0, 10) : '')}"></label>
            </div>`;
    }

    /**
     * Wartung: Bindet Ereignisse und dynamisches Verhalten an Formularfelder.
     * Aufgerufen von: editCopy(), openAddCopy(), openManualBook().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function bindLibraryFields(form) {
        const checkbox = form?.elements?.is_library;
        if (!checkbox) return;
        const fields = $('[data-library-fields]', form);
        const update = () => {
            fields?.classList.toggle('hidden', !checkbox.checked);
            if (form.elements.library_due_at) form.elements.library_due_at.required = checkbox.checked;
        };
        checkbox.addEventListener('change', update);
        update();
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: editBook(), openManualBook().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function bookFormHtml(book = {}) {
        return `<form id="bookForm" class="stack">
            <input type="hidden" name="id" value="${attr(book.id || '')}">
            <div class="field-grid">
                ${book.id ? '' : `<label>ISBN<input name="isbn" inputmode="numeric" value="${attr(book.isbn13 || '')}" placeholder="optional"></label>`}
                <label class="${book.id ? 'full' : ''}">Titel<input name="title" required maxlength="500" value="${attr(book.title || '')}"></label>
                <label>Untertitel<input name="subtitle" maxlength="500" value="${attr(book.subtitle || '')}"></label>
                <label>Autor<input name="authors" maxlength="1000" value="${attr(book.authors || '')}"></label>
                <label>Verlag<input name="publisher" maxlength="255" value="${attr(book.publisher || '')}"></label>
                <label>Erscheinungsdatum<input name="published_date" maxlength="30" value="${attr(book.published_date || '')}"></label>
                <label>Seitenzahl<input name="page_count" type="number" min="0" value="${attr(book.page_count || '')}"></label>
                <label>Sprache<input name="language" maxlength="20" value="${attr(book.language || '')}"></label>
                <label>Sichtbarkeit nach außen<select name="visibility">
                    <option value="internal" ${book.visibility === 'internal' ? 'selected' : ''}>Nur intern</option>
                    <option value="visible" ${book.visibility === 'visible' ? 'selected' : ''}>Sichtbar, nicht verleihbar</option>
                    <option value="lendable" ${!book.visibility || book.visibility === 'lendable' ? 'selected' : ''}>Sichtbar und verleihbar</option>
                </select></label>
                <label class="full">Kategorien<input name="categories" maxlength="4000" value="${attr(book.categories || '')}"></label>
                <label class="full">Beschreibung<textarea name="description" maxlength="60000">${esc(book.description || '')}</textarea></label>
                ${book.id ? '' : `
                    ${locationSelectHtml(state.activeLocationId)}
                    ${libraryFieldsHtml({library_name: $('#scanLibraryName')?.value || '', library_due_at: $('#scanLibraryDue')?.value || ''})}
                    <label class="full">Notiz zum Exemplar<textarea name="copy_notes" maxlength="4000"></textarea></label>
                `}
            </div>
        </form>`;
    }

    /**
     * Wartung: Öffnet einen Dialog oder Detailbereich in der Oberfläche.
     * Aufgerufen von: derzeit kein direkter interner Aufrufer.
     * Abhängigkeiten: bindLibraryFields(), bookFormHtml(), loadLocations(), openModal().
     */
    async function openManualBook() {
        await loadLocations();
        openModal('Buch manuell erfassen', bookFormHtml(), `
            <button class="btn secondary" data-modal-close>Abbrechen</button>
            <button id="saveBookBtn" class="btn">Buch speichern</button>`);
        bindLibraryFields($('#bookForm'));
        $('#saveBookBtn').addEventListener('click', saveBookForm);
    }

    /**
     * Wartung: Speichert Formulardaten über die API.
     * Aufgerufen von: derzeit kein direkter interner Aufrufer.
     * Abhängigkeiten: api(), closeModal(), formObject(), loadBooks(), loadDashboard(), loadLibraryBooks(), openBook(), +2 weitere.
     */
    async function saveBookForm() {
        const form = $('#bookForm');
        if (!form.reportValidity()) return;
        const button = $('#saveBookBtn');
        setBusy(button, true, 'Speichert …');
        try {
            const data = formObject(form);
            data.is_library = form.elements.is_library?.checked || false;
            const endpoint = data.id ? 'book_update' : 'book_create';
            const result = await api(endpoint, {method: 'POST', data});
            toast(data.id ? 'Buch aktualisiert.' : 'Buch und erstes Exemplar angelegt.');
            if (data.id) {
                await openBook(Number(data.id));
            } else {
                closeModal();
                if (result.book?.id && state.view === 'books') loadBooks();
            }
            if (state.view === 'books') loadBooks();
            if (state.view === 'dashboard') loadDashboard();
            if (state.view === 'library') loadLibraryBooks();
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setBusy(button, false);
        }
    }

    $('#manualBookBtn').addEventListener('click', openManualBook);
    $('#addBookBtn').addEventListener('click', openManualBook);

    const metadataFieldLabels = {
        title: 'Titel', subtitle: 'Untertitel', authors: 'Autor', publisher: 'Verlag',
        published_date: 'Datum', description: 'Beschreibung', page_count: 'Seiten',
        categories: 'Kategorien', language: 'Sprache', isbn10: 'ISBN-10', cover_url: 'Cover'
    };

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: openBook().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function sourceHint(book, field) {
        const source = book.metadata_field_sources?.[field]?.name;
        return source ? `<span class="field-source">${esc(source)}</span>` : '';
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: openBook().
     * Abhängigkeiten: fmtDate(), safeUrl(), statusBadge().
     */
    function metadataSourcesHtml(sources) {
        if (!sources.length) return '<div class="empty">Noch keine Quellenabfragen gespeichert.</div>';
        return `<div class="source-grid">${sources.map(source => {
            const fields = [
                ['Titel', source.title], ['Untertitel', source.subtitle], ['Autor', source.authors],
                ['Verlag', source.publisher], ['Datum', source.published_date], ['Seiten', source.page_count],
                ['Sprache', source.language], ['Kategorien', source.categories],
                ['Beschreibung', source.description ? String(source.description).slice(0, 260) + (String(source.description).length > 260 ? '…' : '') : '']
            ].filter(([, value]) => value !== null && value !== '');
            return `<article class="source-card">
                <h4>${esc(source.source_name)} ${statusBadge(source.fetch_status)}</h4>
                <div class="small muted">Abgerufen: ${fmtDate(source.fetched_at || source.updated_at, true)}${source.http_status ? ` · HTTP ${source.http_status}` : ''}${source.raw_size ? ` · Rohantwort ${Math.max(1, Math.round(source.raw_size / 1024))} KB gespeichert` : ''}</div>
                ${source.error_message ? `<div class="notice danger-note" style="margin-top:9px">${esc(source.error_message)}</div>` : ''}
                ${fields.length ? `<dl class="source-fields" style="margin-top:10px">${fields.map(([label, value]) => `<dt>${esc(label)}</dt><dd>${esc(value)}</dd>`).join('')}</dl>` : ''}
                <div class="table-actions" style="margin-top:10px">
                    ${source.raw_size ? `<button class="btn secondary smallbtn" data-metadata-raw="${source.id}" data-book-id="${state.currentDetailBookId}">Rohantwort öffnen</button>` : ''}
                    ${safeUrl(source.external_url) ? `<a class="btn secondary smallbtn" href="${attr(safeUrl(source.external_url))}" target="_blank" rel="noopener">Bei der Quelle öffnen</a>` : ''}
                    ${source.can_adopt ? `<button class="btn secondary smallbtn" data-adopt-metadata="${source.id}" data-book-id="${state.currentDetailBookId}">Für meinen Haushalt übernehmen</button>` : ''}
                </div>
            </article>`;
        }).join('')}</div>`;
    }

    /**
     * Wartung: Formatiert Werte für die Anzeige.
     * Aufgerufen von: openMetadataRaw().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function formatRawPayload(raw) {
        const text = String(raw || '');
        try { return JSON.stringify(JSON.parse(text), null, 2); } catch {}
        return text;
    }

    /**
     * Wartung: Öffnet einen Dialog oder Detailbereich in der Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), fmtDate(), formatRawPayload(), openModal().
     */
    async function openMetadataRaw(sourceId, bookId) {
        openModal('Rohantwort', '<div class="empty">Rohantwort wird geladen …</div>', '', {backBookId: bookId});
        try {
            const data = await api('metadata_raw', {params: {id: sourceId}});
            const source = data.source;
            $('#modalTitle').textContent = `Rohantwort · ${source.source_name}`;
            $('#modalBody').innerHTML = `<div class="notice">Gespeicherte Originalantwort vom ${fmtDate(source.fetched_at, true)}. Sie kann technische Daten der jeweiligen Quelle enthalten.</div>
                <pre style="white-space:pre-wrap;overflow:auto;max-height:68vh;background:#0d1d22;color:#d8f2ee;padding:16px;border-radius:12px;font-size:.78rem;line-height:1.45">${esc(formatRawPayload(source.raw))}</pre>`;
            $('#modalActions').innerHTML = `<button class="btn secondary" data-modal-back>Zurück zu den Buchdetails</button>`;
        } catch (error) {
            $('#modalBody').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
            $('#modalActions').innerHTML = `<button class="btn secondary" data-modal-back>Zurück</button>`;
        }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: openBook().
     * Abhängigkeiten: fmtDate().
     */
    function historyHtml(history) {
        if (!history.length) return '<div class="empty">Noch keine Historieneinträge.</div>';
        return `<div class="timeline">${history.map(entry => {
            const details = entry.details || {};
            const extras = [];
            if (details.location_path) extras.push(details.location_path);
            else if (details.location || details.shelf) extras.push([details.location, details.shelf].filter(Boolean).join(' · '));
            if (details.due_at || details.library_due_at) extras.push(`Fällig: ${fmtDate(details.due_at || details.library_due_at)}`);
            if (details.return_note) extras.push(details.return_note);
            if (details.borrower_name) extras.push(`Ausgeliehen an: ${details.borrower_name}`);
            if (details.library_name) extras.push(`Bücherei: ${details.library_name}`);
            if (details.inventory_no) extras.push(details.inventory_no);
            return `<div class="timeline-item">
                <strong>${esc(entry.summary)}</strong>
                <div class="small muted">${fmtDate(entry.occurred_at, true)}${entry.display_name ? ` · ${esc(entry.display_name)}` : ''}</div>
                ${extras.length ? `<div class="small">${extras.map(esc).join(' · ')}</div>` : ''}
            </div>`;
        }).join('')}</div>`;
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: openBook().
     * Abhängigkeiten: safeUrl().
     */
    function coversHtml(covers, book) {
        if (!covers.length) return '<div class="empty">Noch keine Coverdateien von den Quellen geladen.</div>';
        return `<div class="row" style="margin-bottom:10px">
                ${state.user.can_manage_household ? `<button class="btn secondary smallbtn" data-cover-select="0" data-book-id="${book.id}">Automatisch höchste Auflösung</button>` : ''}
                ${!book.selected_cover_id ? '<span class="badge ok">Automatik aktiv</span>' : ''}
            </div>
            <div class="cover-gallery">${covers.map(cover => {
                const active = cover.local_path && cover.local_path === book.cover_path;
                return `<article class="cover-option ${active ? 'selected' : ''}">
                    ${cover.cover ? `<img src="${attr(safeUrl(cover.cover))}" alt="Cover von ${attr(cover.source_name)}" loading="lazy">` : '<div class="empty">Kein Bild</div>'}
                    <strong>${esc(cover.source_name)}</strong>
                    <div class="small muted">${cover.width && cover.height ? `${cover.width} × ${cover.height} Pixel` : 'Auflösung unbekannt'}${cover.file_size ? ` · ${Math.max(1, Math.round(cover.file_size / 1024))} KB` : ''}</div>
                    ${cover.fetch_status !== 'success' ? `<div class="notice danger-note small">${esc(cover.error_message || 'Bild konnte nicht geladen werden.')}</div>` : ''}
                    <div class="statusline">${active ? '<span class="badge ok">Aktuell verwendet</span>' : ''}${cover.is_selected ? '<span class="badge info">Manuell gewählt</span>' : ''}</div>
                    ${state.user.can_manage_household && cover.fetch_status === 'success' ? `<button class="btn secondary smallbtn" data-cover-select="${cover.id}" data-book-id="${book.id}">Dieses Cover verwenden</button>` : ''}
                </article>`;
            }).join('')}</div>`;
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), loadBooks(), loadLibraryBooks(), openBook(), toast().
     */
    async function selectCover(bookId, coverId) {
        try {
            await api('cover_select', {method: 'POST', data: {book_id: bookId, cover_id: coverId}});
            toast(coverId ? 'Coverquelle ausgewählt.' : 'Automatische Coverauswahl aktiviert.');
            await openBook(bookId);
            if (state.view === 'books') loadBooks();
            if (state.view === 'library') loadLibraryBooks();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /**
     * Wartung: Formatiert Dateigrößen für die Oberfläche.
     * Aufgerufen von: bookFilesHtml().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function formatBytes(bytes) {
        bytes = Number(bytes || 0);
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
        return `${(bytes / 1024 / 1024).toFixed(bytes < 10 * 1024 * 1024 ? 1 : 0)} MB`;
    }

    /**
     * Wartung: Rendert eigene Cover-Uploadmöglichkeiten per URL, Datei, Drag-and-drop und Kamera.
     * Aufgerufen von: openBook().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function coverUploadHtml(book) {
        if (!book.can_manage || book.is_deleted) return '';
        return `<div class="card" style="margin-top:12px">
            <h4 style="margin-top:0">Eigenes Cover hinzufügen</h4>
            <div class="field-grid">
                <label class="full">Bildadresse
                    <div class="row"><input id="coverUrlInput" type="url" placeholder="https://…/cover.jpg"><button id="coverUrlAddBtn" class="btn secondary" type="button">Aus URL laden</button></div>
                </label>
                <div class="full upload-drop-zone" id="coverDropZone">
                    <strong>Bild hier hineinziehen</strong><br><span class="small muted">oder das Feld anklicken und eine JPEG-, PNG- oder WebP-Datei öffnen</span>
                </div>
                <div class="row full">
                    <button id="coverFileOpenBtn" class="btn secondary" type="button">Datei öffnen …</button>
                    <button id="coverCameraBtn" class="btn secondary" type="button">Foto aufnehmen</button>
                </div>
            </div>
            <input id="coverFileInput" type="file" accept="image/jpeg,image/png,image/webp" hidden>
            <input id="coverCameraInput" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" hidden>
        </div>`;
    }

    /**
     * Wartung: Rendert gespeicherte Buchdateien und deren Freigabeeinstellungen.
     * Aufgerufen von: openBook(), openPublicBook().
     * Abhängigkeiten: formatBytes(), fmtDate().
     */
    function bookFilesHtml(files, canManage = false, publicToken = '') {
        const rows = Array.isArray(files) ? files : [];
        const cards = rows.length ? `<div class="asset-grid">${rows.map(file => {
            const url = `${location.pathname}?book_file=${Number(file.id)}${publicToken ? `&share_file=${encodeURIComponent(publicToken)}` : ''}`;
            return `<article class="asset-card" data-book-file-card="${Number(file.id)}">
                <div class="file-name">📎 ${esc(file.original_name)}</div>
                <div class="file-meta">Gespeichert als ${esc(file.stored_name)} · ${formatBytes(file.file_size)} · ${fmtDate(file.created_at, true)}${file.uploaded_by_name ? ` · ${esc(file.uploaded_by_name)}` : ''}</div>
                ${canManage ? `
                    <label style="margin-top:10px">Kommentar<textarea data-book-file-comment="${Number(file.id)}" maxlength="10000">${esc(file.comment || '')}</textarea></label>
                    <label class="checkbox"><input type="checkbox" data-book-file-share="${Number(file.id)}" ${file.share_allowed ? 'checked' : ''}>Bei Haushalts- und öffentlicher Freigabe sichtbar</label>
                    <div class="table-actions">
                        <a class="btn secondary smallbtn" href="${attr(url)}" target="_blank" rel="noopener">Öffnen</a>
                        <button class="btn secondary smallbtn" type="button" data-book-file-save="${Number(file.id)}">Angaben speichern</button>
                        <button class="btn danger smallbtn" type="button" data-book-file-delete="${Number(file.id)}">Datei löschen</button>
                    </div>` : `
                    ${file.comment ? `<p>${esc(file.comment).replace(/\n/g, '<br>')}</p>` : ''}
                    <a class="btn secondary smallbtn" href="${attr(url)}" target="_blank" rel="noopener">Datei öffnen</a>`}
            </article>`;
        }).join('')}</div>` : '<div class="empty">Zu diesem Buch sind noch keine Dateien gespeichert.</div>';

        if (!canManage) return cards;
        return `${cards}
            <div class="card" style="margin-top:12px">
                <h4 style="margin-top:0">Dateien hinzufügen</h4>
                <div id="bookFileDropZone" class="upload-drop-zone"><strong>Dateien hier hineinziehen</strong><br><span class="small muted">PDF, eBook, Office-Datei, Archiv oder Bild · je Datei höchstens 50 MB</span></div>
                <input id="bookFileInput" type="file" multiple hidden accept=".pdf,.epub,.mobi,.azw,.azw3,.djvu,.txt,.md,.rtf,.csv,.doc,.docx,.odt,.xls,.xlsx,.ods,.ppt,.pptx,.odp,.zip,.cbz,.cbr,.jpg,.jpeg,.png,.webp">
                <div class="field-grid" style="margin-top:12px">
                    <label class="full">Kommentar für die neu hochgeladenen Dateien<textarea id="bookFileComment" maxlength="10000" placeholder="z. B. ergänzende Ausarbeitung, Leseprobe oder vollständiges eBook"></textarea></label>
                    <label class="checkbox full"><input id="bookFileShareAllowed" type="checkbox">Bei Freigaben sichtbar und abrufbar</label>
                    <div class="row full"><button id="bookFileOpenBtn" class="btn secondary" type="button">Dateien auswählen …</button><span id="bookFileSelectionInfo" class="small muted">Noch keine Dateien ausgewählt.</span></div>
                    <button id="bookFileUploadBtn" class="btn full" type="button">Ausgewählte Dateien hochladen</button>
                </div>
            </div>`;
    }

    /**
     * Wartung: Bindet Upload-, Drag-and-drop- und Dateiverwaltungsaktionen in den Buchdetails.
     * Aufgerufen von: openBook().
     * Abhängigkeiten: api(), openBook(), toast().
     */
    function bindBookAssetControls(bookId) {
        const uploadCover = async (file, camera = false) => {
            if (!file) return;
            const form = new FormData();
            form.append('book_id', String(bookId));
            form.append('cover_file', file, file.name || 'cover.jpg');
            if (camera) form.append('camera', '1');
            try {
                await api('cover_upload', {method: 'POST', formData: form});
                toast(camera ? 'Foto als Cover gespeichert.' : 'Coverdatei gespeichert.');
                await openBook(bookId);
                if (state.view === 'books') loadBooks();
            } catch (error) { toast(error.message, 'error'); }
        };

        $('#coverUrlAddBtn')?.addEventListener('click', async () => {
            const url = $('#coverUrlInput').value.trim();
            if (!url) return toast('Bitte eine Bildadresse eingeben.', 'error');
            const button = $('#coverUrlAddBtn');
            setBusy(button, true, 'Lädt …');
            try {
                await api('cover_url_add', {method: 'POST', data: {book_id: bookId, url}});
                toast('Cover von der URL gespeichert.');
                await openBook(bookId);
                if (state.view === 'books') loadBooks();
            } catch (error) { toast(error.message, 'error'); }
            finally { setBusy(button, false); }
        });

        const coverFileInput = $('#coverFileInput');
        const coverCameraInput = $('#coverCameraInput');
        $('#coverFileOpenBtn')?.addEventListener('click', () => coverFileInput?.click());
        $('#coverCameraBtn')?.addEventListener('click', () => coverCameraInput?.click());
        coverFileInput?.addEventListener('change', () => uploadCover(coverFileInput.files?.[0], false));
        coverCameraInput?.addEventListener('change', () => uploadCover(coverCameraInput.files?.[0], true));
        const coverDrop = $('#coverDropZone');
        coverDrop?.addEventListener('click', () => coverFileInput?.click());
        for (const type of ['dragenter', 'dragover']) coverDrop?.addEventListener(type, event => {
            event.preventDefault(); coverDrop.classList.add('dragover');
        });
        for (const type of ['dragleave', 'drop']) coverDrop?.addEventListener(type, event => {
            event.preventDefault(); coverDrop.classList.remove('dragover');
        });
        coverDrop?.addEventListener('drop', event => uploadCover(event.dataTransfer?.files?.[0], false));

        const fileInput = $('#bookFileInput');
        let pendingFiles = [];
        const setPendingFiles = files => {
            pendingFiles = [...(files || [])];
            const info = $('#bookFileSelectionInfo');
            if (info) info.textContent = pendingFiles.length
                ? `${pendingFiles.length} Datei${pendingFiles.length === 1 ? '' : 'en'} ausgewählt`
                : 'Noch keine Dateien ausgewählt.';
        };
        $('#bookFileOpenBtn')?.addEventListener('click', () => fileInput?.click());
        fileInput?.addEventListener('change', () => setPendingFiles(fileInput.files));
        const fileDrop = $('#bookFileDropZone');
        fileDrop?.addEventListener('click', () => fileInput?.click());
        for (const type of ['dragenter', 'dragover']) fileDrop?.addEventListener(type, event => {
            event.preventDefault(); fileDrop.classList.add('dragover');
        });
        for (const type of ['dragleave', 'drop']) fileDrop?.addEventListener(type, event => {
            event.preventDefault(); fileDrop.classList.remove('dragover');
        });
        fileDrop?.addEventListener('drop', event => setPendingFiles(event.dataTransfer?.files));

        $('#bookFileUploadBtn')?.addEventListener('click', async () => {
            if (!pendingFiles.length) return toast('Bitte mindestens eine Datei auswählen.', 'error');
            const button = $('#bookFileUploadBtn');
            const form = new FormData();
            form.append('book_id', String(bookId));
            form.append('comment', $('#bookFileComment')?.value || '');
            if ($('#bookFileShareAllowed')?.checked) form.append('share_allowed', '1');
            pendingFiles.forEach(file => form.append('book_files[]', file, file.name));
            setBusy(button, true, 'Lädt hoch …');
            try {
                await api('book_file_upload', {method: 'POST', formData: form});
                toast(`${pendingFiles.length} Datei${pendingFiles.length === 1 ? '' : 'en'} gespeichert.`);
                await openBook(bookId);
                if (state.view === 'books') loadBooks();
            } catch (error) { toast(error.message, 'error'); }
            finally { setBusy(button, false); }
        });

        $$('[data-book-file-save]').forEach(button => button.addEventListener('click', async () => {
            const id = Number(button.dataset.bookFileSave);
            const comment = $(`[data-book-file-comment="${id}"]`)?.value || '';
            const shareAllowed = Boolean($(`[data-book-file-share="${id}"]`)?.checked);
            setBusy(button, true, 'Speichert …');
            try {
                await api('book_file_update', {method: 'POST', data: {id, comment, share_allowed: shareAllowed}});
                toast('Dateiangaben gespeichert.');
                await openBook(bookId);
                if (state.view === 'books') loadBooks();
            } catch (error) { toast(error.message, 'error'); }
            finally { setBusy(button, false); }
        }));

        $$('[data-book-file-delete]').forEach(button => button.addEventListener('click', async () => {
            const id = Number(button.dataset.bookFileDelete);
            if (!confirm('Diese Datei endgültig aus dem Buchspeicher entfernen?')) return;
            try {
                await api('book_file_delete', {method: 'POST', data: {id}});
                toast('Datei entfernt.');
                await openBook(bookId);
                if (state.view === 'books') loadBooks();
            } catch (error) { toast(error.message, 'error'); }
        }));
    }

    /**
     * Wartung: Öffnet einen Dialog oder Detailbereich in der Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events, adoptCommunityMetadata(), deleteBook(), deleteCopy(), editCopy(), modalBack(), openAddCopy(), +9 weitere.
     * Abhängigkeiten: api(), coverHtml(), coversHtml(), fmtDate(), historyHtml(), metadataSourcesHtml(), openModal(), +2 weitere.
     */
    async function openBook(id) {
        state.currentDetailBookId = Number(id);
        openModal('Buchdetails', '<div class="empty">Buch wird geladen …</div>', '', {preserveDetail: true});
        try {
            const data = await api('book', {params: {id}});
            const book = data.book;
            const copies = data.copies;
            const reservations = data.reservations;

            const copiesHtml = copies.length ? `
                <div class="table-wrap"><table style="min-width:980px"><thead><tr><th>Inventar</th><th>Art</th><th>Standort</th><th>Status</th><th>Frist</th><th>Zuletzt gesehen</th><th>Verleihung</th>${state.user.can_manage_household ? '<th>Aktion</th>' : ''}</tr></thead>
                <tbody>${copies.map(copy => `<tr class="${copy.is_deleted ? 'archived-row' : ''}">
                    <td>${esc(copy.inventory_no)}</td>
                    <td>${copy.is_library ? '<span class="badge warn">Bücherei</span>' : '<span class="badge">Eigen</span>'}${copy.library_name ? `<br><span class="small muted">${esc(copy.library_name)}</span>` : ''}</td>
                    <td>${esc(copy.location_path || 'Kein Standort / lose')}${copy.location_retired ? '<br><span class="badge warn">Fach nicht mehr vorhanden</span>' : ''}${copy.location_is_loose && copy.home_location_path ? `<br><span class="small muted">Stammplatz: ${esc(copy.home_location_path)}</span>${copy.home_location_retired ? '<br><span class="badge warn">Stammfach nicht mehr vorhanden</span>' : ''}` : ''}${copy.notes ? `<br><span class="small muted">${esc(copy.notes)}</span>` : ''}</td>
                    <td>${copy.is_deleted ? statusBadge(copy.status === 'library_returned' ? 'library_returned' : 'deleted') : statusBadge(copy.status)}</td>
                    <td>${copy.is_library ? fmtDate(copy.library_due_at) : '–'}</td>
                    <td>${fmtDate(copy.last_seen_at, true)}${copy.seen_count ? `<br><span class="small muted">${copy.seen_count} Scans</span>` : ''}</td>
                    <td>${copy.borrower_name ? `${esc(copy.borrower_name)}<br><span class="small muted">bis ${fmtDate(copy.due_at)}</span>` : '–'}</td>
                    ${state.user.can_manage_household ? `<td><div class="table-actions">
                        ${!copy.is_deleted ? `<button class="btn secondary smallbtn" data-copy-edit="${copy.id}" data-book-id="${book.id}">Standort / Notiz</button>` : ''}
                        ${copy.active_loan_id && !copy.is_deleted ? `<button class="btn smallbtn" data-return-loan="${copy.active_loan_id}" data-book-id="${book.id}">Rückgabe</button>` : ''}
                        ${copy.is_library && !copy.is_deleted && copy.status !== 'loaned' ? `<button class="btn warning smallbtn" data-library-return="${copy.id}" data-book-id="${book.id}">An Bücherei zurück</button>` : ''}
                        ${!copy.is_deleted && copy.status !== 'loaned' ? `<button class="btn danger smallbtn" data-copy-delete="${copy.id}" data-book-id="${book.id}">Archivieren</button>` : ''}
                        ${copy.is_deleted && copy.status !== 'library_returned' ? `<button class="btn secondary smallbtn" data-copy-restore="${copy.id}" data-book-id="${book.id}">Wiederherstellen</button>` : ''}
                    </div></td>` : ''}
                </tr>`).join('')}</tbody></table></div>` : '<div class="empty">Keine Exemplare vorhanden.</div>';

            const reservationHtml = reservations.length
                ? `<ol>${reservations.map(r => `<li>${esc(r.display_name)} · ${fmtDate(r.created_at, true)}</li>`).join('')}</ol>`
                : '<span class="muted">Keine aktiven Vormerkungen.</span>';

            $('#modalBody').innerHTML = `
                ${book.is_deleted ? '<div class="notice danger-note">Dieses Buch ist archiviert und bleibt für die Suche und Historie erhalten.</div>' : ''}
                <div class="details-grid">
                    <div>${coverHtml(book, true)}</div>
                    <div>
                        <h2 style="margin:0 0 4px">${esc(book.title)} ${sourceHint(book, 'title')}</h2>
                        ${book.subtitle ? `<div style="font-size:1.08rem">${esc(book.subtitle)} ${sourceHint(book, 'subtitle')}</div>` : ''}
                        <div class="muted">${esc(book.authors || '')} ${sourceHint(book, 'authors')}</div>
                        <div class="statusline">${statusBadge(book.metadata_status)}${book.visibility === 'internal' ? '<span class="badge visibility-internal">Nur intern</span>' : book.visibility === 'visible' ? '<span class="badge visibility-visible">Sichtbar</span>' : '<span class="badge visibility-lendable">Verleihbar</span>'}${book.is_deleted ? '<span class="badge bad">Archiviert</span>' : ''}${book.library_active ? `<span class="badge warn">${book.library_active} Bücherei</span>` : ''}<span class="badge">${book.copies_total} aktiv</span><span class="badge">${book.copies_all} historisch</span></div>
                        <dl>
                            <dt class="muted small">ISBN</dt><dd>${esc(book.isbn13 || book.isbn10 || '–')}</dd>
                            <dt class="muted small">Verlag / Datum</dt><dd>${esc([book.publisher, book.published_date].filter(Boolean).join(' · ') || '–')} ${sourceHint(book, book.publisher ? 'publisher' : 'published_date')}</dd>
                            <dt class="muted small">Kategorien</dt><dd>${esc(book.categories || '–')} ${sourceHint(book, 'categories')}</dd>
                            <dt class="muted small">Aktive Quellen</dt><dd>${esc(book.metadata_source || '–')}</dd>
                            <dt class="muted small">Bestand bestätigt</dt><dd>${fmtDate(book.last_seen_at, true)}${book.seen_count ? ` · ${Number(book.seen_count)} Scans` : ''}</dd>
                        </dl>
                        ${book.metadata_error ? `<div class="notice danger-note">${esc(book.metadata_error)}</div>` : ''}
                    </div>
                </div>
                ${book.description ? `<h3 style="margin-top:22px">Beschreibung ${sourceHint(book, 'description')}</h3><p>${esc(book.description).replace(/\n/g, '<br>')}</p>` : ''}
                <h3 style="margin-top:22px">Exemplare und Standorte</h3>${copiesHtml}
                <h3 style="margin-top:22px">Coverquellen</h3>
                <p class="muted small">Alle verfügbaren Bilder werden lokal gespeichert. Ohne manuelle Auswahl verwendet das System automatisch die höchste Auflösung.</p>
                ${coversHtml(data.covers || [], book)}
                ${coverUploadHtml(book)}
                <h3 style="margin-top:22px">Dateien und digitale Ergänzungen</h3>
                <p class="muted small">Mehrere Dateien sind möglich. Der Originalname und Upload-Zeitpunkt bleiben gespeichert; serverseitig wird nach ISBN und laufender Nummer umbenannt.</p>
                ${bookFilesHtml(data.files || [], Boolean(data.can_manage))}
                <h3 style="margin-top:22px">Metadaten-Quellen</h3>
                <p class="muted small">Jede Quelle wird separat gespeichert. Die kleinen Quellenhinweise oben zeigen, welcher Datensatz für das jeweilige aktive Feld verwendet wird.</p>
                ${metadataSourcesHtml(data.metadata_sources)}
                <h3 style="margin-top:22px">Historie</h3>${historyHtml(data.history)}
                <h3 style="margin-top:22px">Vormerkungen</h3>${reservationHtml}`;

            const actions = [];
            if (state.user.can_manage_household) {
                if (book.is_deleted) {
                    if (!book.library_history_only) actions.push(`<button class="btn" data-book-restore="${book.id}">Wiederherstellen</button>`);
                } else {
                    actions.push(`<button class="btn secondary" data-book-add-copy="${book.id}">Exemplar ergänzen</button>`);
                    actions.push(`<button class="btn secondary" data-book-edit="${book.id}">Buch bearbeiten</button>`);
                    if (book.isbn13) actions.push(`<button class="btn secondary" data-book-retry="${book.id}">Alle Quellen neu laden</button>`);
                    if (book.copies_available > 0) actions.push(`<button class="btn" data-loan-book="${book.id}">Verleihen</button>`);
                    actions.push(`<button class="btn danger" data-book-delete="${book.id}">Archivieren</button>`);
                }
            }
            if (!book.is_deleted && (book.can_manage || book.visibility === 'lendable')) actions.push(`<button class="btn secondary" data-reserve-book="${book.id}">Vormerken</button>`);
            actions.push('<button class="btn secondary" data-modal-close>Schließen</button>');
            $('#modalActions').innerHTML = actions.join('');
            if (data.can_manage) bindBookAssetControls(book.id);
        } catch (error) {
            $('#modalBody').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
        }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), bookFormHtml(), openModal(), toast().
     */
    async function editBook(id) {
        try {
            const data = await api('book', {params: {id}});
            openModal('Buch bearbeiten', bookFormHtml(data.book), `
                <button class="btn secondary" data-modal-back>Abbrechen</button>
                <button id="saveBookBtn" class="btn">Änderungen speichern</button>`, {backBookId: id});
            $('#saveBookBtn').addEventListener('click', saveBookForm);
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /**
     * Wartung: Öffnet einen Dialog oder Detailbereich in der Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), bindLibraryFields(), formObject(), libraryFieldsHtml(), loadBooks(), loadLibraryBooks(), loadLocations(), +5 weitere.
     */
    async function openAddCopy(bookId) {
        await loadLocations();
        openModal('Exemplar ergänzen', `<form id="copyForm" class="stack">
            <input type="hidden" name="book_id" value="${bookId}">
            <div class="field-grid">
                ${locationSelectHtml(state.activeLocationId)}
                ${libraryFieldsHtml({library_name: $('#scanLibraryName')?.value || '', library_due_at: $('#scanLibraryDue')?.value || ''})}
                <label class="full">Notiz<textarea name="notes" maxlength="4000"></textarea></label>
            </div>
        </form>`, `<button class="btn secondary" data-modal-back>Abbrechen</button><button id="saveCopyBtn" class="btn">Exemplar anlegen</button>`, {backBookId: bookId});
        const form = $('#copyForm');
        bindLibraryFields(form);
        $('#saveCopyBtn').addEventListener('click', async () => {
            if (!form.reportValidity()) return;
            const button = $('#saveCopyBtn');
            setBusy(button, true);
            try {
                const payload = formObject(form);
                payload.is_library = form.elements.is_library.checked;
                await api('copy_add', {method: 'POST', data: payload});
                toast('Exemplar ergänzt.');
                await openBook(bookId);
                if (state.view === 'books') loadBooks();
                if (state.view === 'library') loadLibraryBooks();
            } catch (error) {
                toast(error.message, 'error');
            } finally {
                setBusy(button, false);
            }
        });
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), bindLibraryFields(), formObject(), libraryFieldsHtml(), loadBooks(), loadLibraryBooks(), loadLocations(), +5 weitere.
     */
    async function editCopy(copyId, bookId = state.currentDetailBookId) {
        try {
            await loadLocations();
            const bookData = await api('book', {params: {id: bookId}});
            const copy = bookData.copies.find(item => item.id === Number(copyId));
            if (!copy) throw new Error('Exemplar nicht gefunden.');
            openModal('Exemplar bearbeiten', `<form id="copyEditForm" class="stack">
                <input type="hidden" name="id" value="${copy.id}">
                <div class="field-grid">
                    ${locationSelectHtml(copy.location_id, 'Aktueller Standort')}
                    ${copy.location_is_loose && copy.home_location_path ? `<div class="notice full">Gespeicherter Stammplatz: <strong>${esc(copy.home_location_path)}</strong>. Die Auswahl „lose“ ändert diesen Stammplatz nicht.</div>` : ''}
                    <label>Status<select name="status" ${copy.status === 'loaned' ? 'disabled' : ''}>
                        <option value="available" ${copy.status === 'available' ? 'selected' : ''}>Verfügbar</option>
                        <option value="reserved" ${copy.status === 'reserved' ? 'selected' : ''}>Reserviert</option>
                        <option value="lost" ${copy.status === 'lost' ? 'selected' : ''}>Verloren</option>
                        ${copy.status === 'loaned' ? '<option value="loaned" selected>Ausgeliehen</option>' : ''}
                    </select></label>
                    ${libraryFieldsHtml(copy)}
                    <label class="full">Notiz<textarea name="notes" maxlength="4000">${esc(copy.notes || '')}</textarea></label>
                </div>
            </form>`, `<button class="btn secondary" data-modal-back>Abbrechen</button><button id="saveCopyEditBtn" class="btn">Speichern</button>`, {backBookId: bookId});
            const form = $('#copyEditForm');
            bindLibraryFields(form);
            $('#saveCopyEditBtn').addEventListener('click', async () => {
                if (!form.reportValidity()) return;
                const button = $('#saveCopyEditBtn');
                setBusy(button, true);
                try {
                    const payload = formObject(form);
                    payload.is_library = form.elements.is_library.checked;
                    payload.status = form.elements.status.value;
                    await api('copy_update', {method: 'POST', data: payload});
                    toast('Standort und Exemplardaten aktualisiert.');
                    await openBook(bookId);
                    if (state.view === 'books') loadBooks();
                    if (state.view === 'library') loadLibraryBooks();
                } catch (error) {
                    toast(error.message, 'error');
                } finally {
                    setBusy(button, false);
                }
            });
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), loadBooks(), openBook(), toast().
     */
    async function adoptCommunityMetadata(sourceId, bookId) {
        if (!confirm('Diese anonyme Benutzereingabe für deinen Haushalt übernehmen? Deine bisherigen manuellen Felder werden ersetzt.')) return;
        try {
            await api('community_metadata_adopt', {method:'POST', data:{source_id:sourceId}});
            toast('Benutzereingabe für deinen Haushalt übernommen.');
            await openBook(bookId);
            if (state.view === 'books') loadBooks();
        } catch (error) { toast(error.message,'error'); }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), openBook(), scheduleWorker(), toast().
     */
    async function retryMetadata(id) {
        try {
            await api('book_retry_metadata', {method: 'POST', data: {id}});
            toast('Alle Metadatenquellen wurden eingeplant.');
            await openBook(id);
            scheduleWorker();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /**
     * Wartung: Löscht oder archiviert den ausgewählten Eintrag über die API.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), loadBooks(), openBook(), toast().
     */
    async function deleteBook(id) {
        if (!confirm('Buch archivieren? Es bleibt ausgegraut in der Suche und die gesamte Historie bleibt erhalten.')) return;
        try {
            await api('book_delete', {method: 'POST', data: {id}});
            toast('Buch archiviert.');
            await openBook(id);
            if (state.view === 'books') loadBooks();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /**
     * Wartung: Stellt einen zuvor archivierten Eintrag wieder her.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), loadBooks(), openBook(), toast().
     */
    async function restoreBook(id) {
        try {
            await api('book_restore', {method: 'POST', data: {id}});
            toast('Buch wiederhergestellt.');
            await openBook(id);
            if (state.view === 'books') loadBooks();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /**
     * Wartung: Löscht oder archiviert den ausgewählten Eintrag über die API.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), loadBooks(), openBook(), toast().
     */
    async function deleteCopy(id, bookId) {
        if (!confirm('Dieses Exemplar archivieren? Die Historie bleibt erhalten.')) return;
        try {
            await api('copy_delete', {method: 'POST', data: {id}});
            toast('Exemplar archiviert.');
            await openBook(bookId);
            if (state.view === 'books') loadBooks();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /**
     * Wartung: Stellt einen zuvor archivierten Eintrag wieder her.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), loadBooks(), openBook(), toast().
     */
    async function restoreCopy(id, bookId) {
        try {
            await api('copy_restore', {method: 'POST', data: {id}});
            toast('Exemplar wiederhergestellt.');
            await openBook(bookId);
            if (state.view === 'books') loadBooks();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), loadBooks(), loadLibraryBooks(), openBook(), toast().
     */
    async function returnLibraryCopy(id, bookId) {
        if (!confirm('Dieses Buch als an die Bücherei zurückgegeben markieren?')) return;
        try {
            await api('library_return', {method: 'POST', data: {copy_id: id}});
            toast('Büchereibuch als zurückgegeben markiert.');
            if (!$('#modalBackdrop').classList.contains('hidden')) await openBook(bookId);
            if (state.view === 'books') loadBooks();
            if (state.view === 'library') loadLibraryBooks();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: openLoanDialog(), reserveBook().
     * Abhängigkeiten: api().
     */
    async function ensureUsers() {
        if (!state.user.can_manage_household) return [];
        const data = await api('borrowers');
        state.users = data.users || [];
        return state.users;
    }

    /**
     * Wartung: Öffnet einen Dialog oder Detailbereich in der Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), closeModal(), ensureUsers(), formObject(), isoDateAfter(), loadBooks(), loadDashboard(), +5 weitere.
     */
    async function openLoanDialog(bookId, conflictOverride = false) {
        const backBookId = state.currentDetailBookId === Number(bookId) ? Number(bookId) : null;
        try {
            const users = await ensureUsers();
            if (!users.length) {
                toast('Es gibt keinen aktiven Benutzer für die Verleihung.', 'warn');
                return;
            }
            openModal('Buch verleihen', `<form id="loanForm" class="stack">
                <input type="hidden" name="book_id" value="${bookId}">
                <label>Benutzer<select name="user_id" required>
                    ${users.map(user => `<option value="${user.id}">${esc(user.display_name)} · ${esc(user.email)}</option>`).join('')}
                </select></label>
                <label>Rückgabe bis<input name="due_at" type="date" required value="${isoDateAfter(state.defaults.loan_days)}"></label>
                ${conflictOverride ? `<label class="checkbox"><input type="checkbox" name="override_reservation" value="1" required>Vormerkungsreihenfolge bewusst übersteuern</label>` : ''}
            </form>`, `<button class="btn secondary" ${backBookId ? 'data-modal-back' : 'data-modal-close'}>Abbrechen</button><button id="createLoanBtn" class="btn">Verleihen</button>`, {backBookId});

            $('#createLoanBtn').addEventListener('click', async () => {
                const form = $('#loanForm');
                if (!form.reportValidity()) return;
                const button = $('#createLoanBtn');
                setBusy(button, true);
                const payload = formObject(form);
                payload.override_reservation = form.elements.override_reservation?.checked || false;
                try {
                    await api('loan_create', {method: 'POST', data: payload});
                    toast('Verleihung eingetragen.');
                    if (backBookId) await openBook(bookId); else closeModal();
                    if (state.view === 'books') loadBooks();
                    if (state.view === 'loans') loadLoans();
                    if (state.view === 'dashboard') loadDashboard();
                } catch (error) {
                    if (error.reservation_conflict) {
                        await openLoanDialog(bookId, true);
                        toast(error.message, 'warn');
                    } else {
                        toast(error.message, 'error');
                    }
                } finally {
                    setBusy(button, false);
                }
            });
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), closeModal(), ensureUsers(), formObject(), loadReservations(), openBook(), openModal(), +2 weitere.
     */
    async function reserveBook(bookId) {
        const backBookId = state.currentDetailBookId === Number(bookId) ? Number(bookId) : null;
        if (!state.user.can_manage_household) {
            try {
                await api('reservation_create', {method: 'POST', data: {book_id: bookId}});
                toast('Buch vorgemerkt.');
                if (backBookId) await openBook(bookId);
                if (state.view === 'reservations') loadReservations();
            } catch (error) {
                toast(error.message, 'error');
            }
            return;
        }

        try {
            const users = await ensureUsers();
            openModal('Buch vormerken', `<form id="reserveForm" class="stack">
                <input type="hidden" name="book_id" value="${bookId}">
                <label>Benutzer<select name="user_id" required>${users.map(user =>
                    `<option value="${user.id}">${esc(user.display_name)} · ${esc(user.email)}</option>`
                ).join('')}</select></label>
            </form>`, `<button class="btn secondary" ${backBookId ? 'data-modal-back' : 'data-modal-close'}>Abbrechen</button><button id="reserveBtn" class="btn">Vormerken</button>`, {backBookId});
            $('#reserveBtn').addEventListener('click', async () => {
                const button = $('#reserveBtn');
                setBusy(button, true);
                try {
                    await api('reservation_create', {method: 'POST', data: formObject($('#reserveForm'))});
                    toast('Buch vorgemerkt.');
                    if (backBookId) await openBook(bookId); else closeModal();
                    if (state.view === 'reservations') loadReservations();
                } catch (error) {
                    toast(error.message, 'error');
                } finally {
                    setBusy(button, false);
                }
            });
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /**
     * Wartung: Lädt Daten für die jeweilige Ansicht und aktualisiert die Oberfläche.
     * Aufgerufen von: editCopy(), goTo(), openAddCopy(), returnLibraryCopy(), saveBookForm(), selectCover(), submitLibraryReturnScan().
     * Abhängigkeiten: api(), coverHtml(), fmtDate().
     */
    async function loadLibraryBooks() {
        $('#libraryResults').innerHTML = '<div class="empty">Büchereibücher werden geladen …</div>';
        try {
            const data = await api('library_books', {params: {scope: $('#libraryScope').value}});
            if (!data.books.length) {
                $('#libraryResults').innerHTML = '<div class="empty">Keine Büchereibücher in dieser Ansicht.</div>';
                return;
            }
            const stateHtml = item => item.library_returned_at
                ? `<span class="badge info">Zurück am ${fmtDate(item.library_returned_at)}</span>`
                : item.overdue
                    ? '<span class="badge bad">Überfällig</span>'
                    : `<span class="badge warn">Rückgabe bis ${fmtDate(item.library_due_at)}</span>`;
            $('#libraryResults').innerHTML = `
                <div class="table-wrap desktop-table"><table>
                    <thead><tr><th>Buch</th><th>Bücherei</th><th>Standort</th><th>Frist</th><th>Status</th><th>Aktion</th></tr></thead>
                    <tbody>${data.books.map(item => `<tr class="${item.library_returned_at ? 'archived-row' : ''}">
                        <td><div class="book-cell">${coverHtml(item)}<div><div class="book-title">${esc(item.title)}</div><div class="book-meta">${esc(item.authors || '')}${item.isbn13 ? ' · ' + esc(item.isbn13) : ''}</div></div></div></td>
                        <td>${esc(item.library_name || '–')}</td>
                        <td>${esc(item.location_path || 'Kein Standort / lose')}${item.location_retired ? '<br><span class="badge warn">Fach nicht mehr vorhanden</span>' : ''}${item.location_is_loose && item.home_location_path ? `<br><span class="small muted">Stammplatz: ${esc(item.home_location_path)}</span>${item.home_location_retired ? '<br><span class="badge warn">Stammfach nicht mehr vorhanden</span>' : ''}` : ''}</td>
                        <td>${fmtDate(item.library_due_at)}</td>
                        <td>${stateHtml(item)}</td>
                        <td><div class="table-actions"><button class="btn secondary smallbtn" data-book-open="${item.book_id}">Details</button>${state.user.can_manage_household && !item.library_returned_at ? `<button class="btn warning smallbtn" data-library-return="${item.id}" data-book-id="${item.book_id}">Zurückgegeben</button>` : ''}</div></td>
                    </tr>`).join('')}</tbody>
                </table></div>
                <div class="mobile-cards stack">${data.books.map(item => `<div class="book-card ${item.library_returned_at ? 'archived-row' : ''}">
                    ${coverHtml(item)}<div><div class="book-title">${esc(item.title)}</div>
                    <div class="book-meta">${esc(item.library_name || 'Bücherei')} · ${esc(item.location_path || 'Kein Standort / lose')}</div>
                    <div class="statusline">${stateHtml(item)}</div>
                    <div class="table-actions" style="margin-top:9px"><button class="btn secondary smallbtn" data-book-open="${item.book_id}">Details</button>${state.user.can_manage_household && !item.library_returned_at ? `<button class="btn warning smallbtn" data-library-return="${item.id}" data-book-id="${item.book_id}">Zurückgegeben</button>` : ''}</div>
                    </div></div>`).join('')}</div>`;
        } catch (error) {
            $('#libraryResults').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
        }
    }

    $('#libraryScope').addEventListener('change', loadLibraryBooks);

    /**
     * Wartung: Sendet ein Formular oder eine Scan-Eingabe an die API.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), beep(), loadBooks(), loadLibraryBooks(), setBusy(), toast().
     */
    async function submitLibraryReturnScan() {
        const input = $('#libraryReturnScan');
        const isbn = input.value.trim();
        if (!isbn) return;
        input.value = '';
        const button = $('#libraryReturnScanBtn');
        setBusy(button, true, 'Prüft …');
        try {
            const data = await api('library_return_scan', {method: 'POST', data: {isbn}});
            toast(data.message);
            beep(true);
            await loadLibraryBooks();
            if (state.view === 'books') loadBooks();
        } catch (error) {
            toast(error.message, 'error');
            beep(false);
        } finally {
            setBusy(button, false);
            input.focus();
        }
    }
    $('#libraryReturnScanBtn').addEventListener('click', submitLibraryReturnScan);
    $('#libraryReturnScan').addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            submitLibraryReturnScan();
        }
    });

    /**
     * Wartung: Lädt Daten für die jeweilige Ansicht und aktualisiert die Oberfläche.
     * Aufgerufen von: goTo(), openLoanDialog(), openReturnDialog().
     * Abhängigkeiten: api(), coverHtml(), fmtDate().
     */
    async function loadLoans() {
        $('#loanResults').innerHTML = '<div class="empty">Verleihungen werden geladen …</div>';
        try {
            const data = await api('loans', {params: {scope: $('#loanScope').value}});
            if (!data.loans.length) {
                $('#loanResults').innerHTML = '<div class="empty">Keine Verleihungen in dieser Ansicht.</div>';
                return;
            }
            $('#loanResults').innerHTML = `
                <div class="table-wrap desktop-table"><table>
                    <thead><tr><th>Buch</th>${state.user.can_manage_household ? '<th>Benutzer</th>' : ''}<th>Verleihung</th><th>Fällig</th><th>Status</th>${state.user.can_manage_household ? '<th>Aktion</th>' : ''}</tr></thead>
                    <tbody>${data.loans.map(loan => `<tr>
                        <td><div class="book-cell">${coverHtml(loan)}<div><div class="book-title">${esc(loan.title)}</div><div class="book-meta">${esc(loan.inventory_no)} · ${esc(loan.isbn13 || '')}</div></div></div></td>
                        ${state.user.can_manage_household ? `<td>${esc(loan.display_name)}<br><span class="small muted">${esc(loan.email)}</span></td>` : ''}
                        <td>${fmtDate(loan.loaned_at)}</td><td>${fmtDate(loan.due_at)}</td>
                        <td>${loan.returned_at ? `<span class="badge ok">Zurück am ${fmtDate(loan.returned_at)}</span>` : loan.overdue ? '<span class="badge bad">Überfällig</span>' : '<span class="badge warn">Ausgeliehen</span>'}</td>
                        ${state.user.can_manage_household ? `<td>${loan.returned_at ? (loan.return_note ? esc(loan.return_note) : '–') : `<button class="btn smallbtn" data-return-loan="${loan.id}">Rückgabe</button>`}</td>` : ''}
                    </tr>`).join('')}</tbody>
                </table></div>
                <div class="mobile-cards stack">${data.loans.map(loan => `<div class="book-card">
                    ${coverHtml(loan)}<div><div class="book-title">${esc(loan.title)}</div>
                    <div class="book-meta">${state.user.can_manage_household ? esc(loan.display_name) + ' · ' : ''}${esc(loan.inventory_no)}</div>
                    <div class="statusline">${loan.returned_at ? '<span class="badge ok">Zurückgegeben</span>' : loan.overdue ? '<span class="badge bad">Überfällig</span>' : '<span class="badge warn">Ausgeliehen</span>'}<span class="badge">bis ${fmtDate(loan.due_at)}</span></div>
                    ${state.user.can_manage_household && !loan.returned_at ? `<button class="btn smallbtn" style="margin-top:9px" data-return-loan="${loan.id}">Rückgabe</button>` : ''}
                    </div></div>`).join('')}</div>`;
        } catch (error) {
            $('#loanResults').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
        }
    }

    $('#loanScope').addEventListener('change', loadLoans);

    /**
     * Wartung: Öffnet einen Dialog oder Detailbereich in der Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), closeModal(), formObject(), loadBooks(), loadDashboard(), loadLoans(), openBook(), +3 weitere.
     */
    function openReturnDialog(loanId, bookId = null) {
        const backBookId = Number(bookId || 0) || null;
        openModal('Rückgabe vermerken', `<form id="returnForm" class="stack">
            <input type="hidden" name="loan_id" value="${loanId}">
            <label>Rückgabe-Vermerk<textarea name="return_note" maxlength="6000" placeholder="optional, z. B. Zustand oder Hinweis"></textarea></label>
            <div class="notice">Ist eine Vormerkung vorhanden, wird dieses Exemplar automatisch reserviert und eine E-Mail in die Versandwarteschlange gelegt.</div>
        </form>`, `<button class="btn secondary" ${backBookId ? 'data-modal-back' : 'data-modal-close'}>Abbrechen</button><button id="returnBtn" class="btn">Rückgabe bestätigen</button>`, {backBookId});
        $('#returnBtn').addEventListener('click', async () => {
            const button = $('#returnBtn');
            setBusy(button, true);
            try {
                const data = await api('loan_return', {method: 'POST', data: formObject($('#returnForm'))});
                toast(data.reserved_for_next ? 'Rückgabe erfasst; Exemplar ist für die nächste Vormerkung reserviert.' : 'Rückgabe erfasst.');
                if (backBookId) await openBook(backBookId); else closeModal();
                if (state.view === 'loans') loadLoans();
                if (state.view === 'books') loadBooks();
                if (state.view === 'dashboard') loadDashboard();
            } catch (error) {
                toast(error.message, 'error');
            } finally {
                setBusy(button, false);
            }
        });
    }

    /**
     * Wartung: Lädt Daten für die jeweilige Ansicht und aktualisiert die Oberfläche.
     * Aufgerufen von: cancelReservation(), goTo(), reserveBook().
     * Abhängigkeiten: api(), coverHtml(), fmtDate(), statusBadge().
     */
    async function loadReservations() {
        $('#reservationResults').innerHTML = '<div class="empty">Vormerkungen werden geladen …</div>';
        try {
            const data = await api('reservations');
            if (!data.reservations.length) {
                $('#reservationResults').innerHTML = '<div class="empty">Keine Vormerkungen vorhanden.</div>';
                return;
            }
            $('#reservationResults').innerHTML = `
                <div class="table-wrap desktop-table"><table>
                    <thead><tr><th>Buch</th>${state.user.can_manage_household ? '<th>Benutzer</th>' : ''}<th>Position</th><th>Erstellt</th><th>Status</th><th>Aktion</th></tr></thead>
                    <tbody>${data.reservations.map(r => `<tr>
                        <td><div class="book-cell">${coverHtml(r)}<div><div class="book-title">${esc(r.title)}</div><div class="book-meta">${esc(r.authors || '')}</div></div></div></td>
                        ${state.user.can_manage_household ? `<td>${esc(r.display_name)}<br><span class="small muted">${esc(r.email)}</span></td>` : ''}
                        <td>${r.status === 'active' ? r.queue_position : '–'}</td><td>${fmtDate(r.created_at, true)}</td>
                        <td>${statusBadge(r.status)}${r.notified_at ? '<br><span class="small muted">benachrichtigt</span>' : ''}</td>
                        <td>${r.status === 'active' ? `<button class="btn danger smallbtn" data-cancel-reservation="${r.id}">Stornieren</button>` : '–'}</td>
                    </tr>`).join('')}</tbody>
                </table></div>
                <div class="mobile-cards stack">${data.reservations.map(r => `<div class="book-card">${coverHtml(r)}<div>
                    <div class="book-title">${esc(r.title)}</div>
                    <div class="book-meta">${state.user.can_manage_household ? esc(r.display_name) : esc(r.authors || '')}</div>
                    <div class="statusline">${statusBadge(r.status)}${r.status === 'active' ? `<span class="badge">Position ${r.queue_position}</span>` : ''}</div>
                    ${r.status === 'active' ? `<button class="btn danger smallbtn" style="margin-top:9px" data-cancel-reservation="${r.id}">Stornieren</button>` : ''}
                </div></div>`).join('')}</div>`;
        } catch (error) {
            $('#reservationResults').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
        }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: api(), loadReservations(), toast().
     */
    async function cancelReservation(id) {
        if (!confirm('Vormerkung stornieren?')) return;
        try {
            await api('reservation_cancel', {method: 'POST', data: {id}});
            toast('Vormerkung storniert.');
            loadReservations();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /**
     * Wartung: Unterstützt die Browseroberfläche bei Anzeige, Interaktion oder API-Kommunikation.
     * Aufgerufen von: loadSharing().
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function grantStatusHtml(grant) {
        if (grant.status === 'active') return '<span class="badge ok">Aktiv</span>';
        if (grant.status === 'paused') return '<span class="badge warn">Pausiert</span>';
        return '<span class="badge bad">Entzogen</span>';
    }

    /**
     * Wartung: Lädt Daten für die jeweilige Ansicht und aktualisiert die Oberfläche.
     * Aufgerufen von: Globaler Ablauf/API/Events, goTo().
     * Abhängigkeiten: api(), fmtDate(), grantStatusHtml().
     */
    async function loadSharing() {
        $('#shareLinksResults').innerHTML = '<div class="empty">Freigaben werden geladen …</div>';
        try {
            const data = await api('sharing');
            const links = data.links || [];
            $('#shareLinksResults').innerHTML = links.length ? `<div class="stack">${links.map(link => `<div class="source-card ${link.active ? '' : 'archived-row'}">
                <div class="row"><strong>${esc(link.description || 'Freigegebener Buchbestand')}</strong>${link.active ? '<span class="badge ok">Aktiv</span>' : '<span class="badge bad">Abgelaufen / widerrufen</span>'}<span class="spacer"></span><span class="small muted">bis ${fmtDate(link.expires_at, true)}</span></div>
                <div class="share-url"><code>${esc(link.url)}</code><button class="btn secondary smallbtn" data-copy-text="${attr(link.url)}">Kopieren</button></div>
                <div class="row small muted"><span>${Number(link.access_count)} Aufrufe</span><span>zuletzt ${fmtDate(link.last_accessed_at, true)}</span>${link.active ? `<button class="btn danger smallbtn" data-share-revoke="${link.id}">Widerrufen</button>` : ''}</div>
            </div>`).join('')}</div>` : '<div class="empty">Noch keine öffentlichen Links angelegt.</div>';

            const keys = data.keys || [];
            $('#accessKeysResults').innerHTML = keys.length ? `<div class="table-wrap"><table style="min-width:900px"><thead><tr><th>Einmalschlüssel</th><th>Notiz</th><th>Erstellt</th><th>Status</th><th>Aktion</th></tr></thead><tbody>${keys.map(key => {
                const fullKey = key.key || `…${key.key_hint}`;
                const status = key.status === 'unused'
                    ? '<span class="badge ok">Noch nicht eingelöst</span>'
                    : key.status === 'redeemed'
                        ? `<span class="badge info">Eingelöst</span>${key.redeemed_by_name ? `<br><span class="small muted">von ${esc(key.redeemed_by_name)} · ${fmtDate(key.redeemed_at, true)}</span>` : ''}`
                        : '<span class="badge bad">Ungültig</span>';
                return `<tr class="${key.status === 'unused' ? '' : 'archived-row'}"><td><code style="font-size:.92rem">${esc(fullKey)}</code></td><td>${esc(key.note || '–')}</td><td>${fmtDate(key.created_at, true)}</td><td>${status}</td><td><div class="table-actions">${key.key ? `<button class="btn secondary smallbtn" data-copy-text="${attr(key.key)}">Kopieren</button>` : ''}${key.can_delete ? `<button class="btn danger smallbtn" data-access-key-delete="${key.id}">Löschen</button>` : ''}</div></td></tr>`;
            }).join('')}</tbody></table></div>` : '<div class="empty">Noch kein Einmalschlüssel erzeugt.</div>';

            const grants = data.grants || [];
            $('#accessGrantsResults').innerHTML = grants.length ? `<div class="table-wrap"><table style="min-width:850px"><thead><tr><th>Name</th><th>Freigabe</th><th>Status</th><th>Aktion</th></tr></thead><tbody>${grants.map(grant => `<tr class="${grant.status === 'active' ? '' : 'archived-row'}"><td>${esc(grant.display_name)}<br><span class="small muted">${esc(grant.email)}</span></td><td>${fmtDate(grant.created_at, true)}</td><td>${grantStatusHtml(grant)}${grant.bilateral ? `<br><span class="badge info">Bilaterale Freigabe${grant.reverse_active ? '' : ' · Gegenseite pausiert'}</span>` : ''}</td><td><div class="table-actions">${grant.status === 'active' ? `<button class="btn secondary smallbtn" data-grant-pause="${grant.id}">Pausieren</button>` : grant.status === 'paused' ? `<button class="btn secondary smallbtn" data-grant-resume="${grant.id}">Fortsetzen</button>` : ''}${grant.status !== 'revoked' ? `<button class="btn danger smallbtn" data-grant-revoke="${grant.id}">Zugriff entziehen</button>` : ''}</div></td></tr>`).join('')}</tbody></table></div>` : '<div class="empty">Noch keine Person hat Zugriff auf diesen Haushalt.</div>';

            const incoming = data.incoming_grants || [];
            $('#incomingAccessResults').innerHTML = incoming.length ? `<div class="table-wrap"><table style="min-width:800px"><thead><tr><th>Haushalt</th><th>Erhalten</th><th>Status</th><th>Gegenzugriff</th></tr></thead><tbody>${incoming.map(grant => `<tr class="${grant.status === 'active' ? '' : 'archived-row'}"><td><strong>${esc(grant.household_name)}</strong><br><span class="small muted">${esc(grant.owner_name || grant.owner_email || '')}</span></td><td>${fmtDate(grant.created_at, true)}</td><td>${grantStatusHtml(grant)}${grant.bilateral ? '<br><span class="badge info">Bilaterale Freigabe</span>' : ''}</td><td>${grant.can_reciprocate ? `<button class="btn secondary smallbtn" data-grant-reciprocate="${grant.id}">Direkt Gegenzugriff erlauben</button>` : grant.reverse_active ? '<span class="badge ok">Aktiv</span>' : '<span class="badge warn">Pausiert</span>'}</td></tr>`).join('')}</tbody></table></div>` : '<div class="empty">Du hast noch keinen Zugriff auf einen anderen Haushalt.</div>';

            const logs = data.logs || [];
            $('#shareLogsResults').innerHTML = logs.length ? `<div class="table-wrap"><table><thead><tr><th>Zeitpunkt</th><th>Link</th><th>Browser</th><th>Referrer</th></tr></thead><tbody>${logs.map(log => `<tr><td>${fmtDate(log.accessed_at,true)}</td><td>${esc(log.description || `Link ${log.share_link_id}`)}</td><td class="small">${esc(log.user_agent || '–')}</td><td class="small">${esc(log.referrer || '–')}</td></tr>`).join('')}</tbody></table></div>` : '<div class="empty">Noch keine Zugriffe protokolliert.</div>';
        } catch (error) {
            $('#shareLinksResults').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
            $('#accessKeysResults').innerHTML = '';
            $('#accessGrantsResults').innerHTML = '';
            $('#incomingAccessResults').innerHTML = '';
        }
    }

    $('#shareLinkForm').addEventListener('submit', async event => {
        event.preventDefault(); const form=event.currentTarget; const button=$('button[type=submit]',form); setBusy(button,true);
        try { const result=await api('share_link_create',{method:'POST',data:formObject(form)}); form.reset(); toast('Freigabelink angelegt.'); await navigator.clipboard?.writeText(result.url).catch(()=>{}); await loadSharing(); }
        catch(error){toast(error.message,'error')} finally{setBusy(button,false)}
    });
    $('#accessKeyForm').addEventListener('submit', async event => {
        event.preventDefault(); const form=event.currentTarget; const button=$('button[type=submit]',form); setBusy(button,true);
        try { const data=await api('access_key_generate',{method:'POST',data:formObject(form)}); form.reset(); toast('Einmalschlüssel erzeugt.'); await navigator.clipboard?.writeText(data.key).catch(()=>{}); await loadSharing(); }
        catch(error){toast(error.message,'error')} finally{setBusy(button,false)}
    });
    $('#redeemAccessKeyForm').addEventListener('submit', async event => {
        event.preventDefault(); const form=event.currentTarget; const button=$('button[type=submit]',form); setBusy(button,true);
        try { const data=await api('access_key_redeem',{method:'POST',data:formObject(form)}); form.reset(); toast(`Zugriff auf „${data.household_name}“ eingerichtet.`); await bootstrap(); }
        catch(error){toast(error.message,'error')} finally{setBusy(button,false)}
    });

    /**
     * Wartung: Lädt Daten für die jeweilige Ansicht und aktualisiert die Oberfläche.
     * Aufgerufen von: goTo(), saveUser().
     * Abhängigkeiten: api(), statusBadge().
     */
    async function loadUsers() {
        $('#userResults').innerHTML = '<div class="empty">Benutzer werden geladen …</div>';
        try {
            const data = await api('users');
            state.users = data.users;
            const formatDate = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString('de-DE') : 'Noch nie';
            $('#userResults').innerHTML = `
                <div class="table-wrap desktop-table"><table>
                    <thead><tr><th>Name</th><th>Rolle</th><th>Status</th><th>E-Mail</th><th>Letzte Anmeldung</th><th>Haushalte</th><th>Aktion</th></tr></thead>
                    <tbody>${data.users.map(user => `<tr>
                        <td><strong>${esc(user.display_name)}</strong><br><span class="small muted">ID ${user.id}</span></td>
                        <td>${statusBadge(user.role)}</td>
                        <td>${user.anonymized ? '<span class="badge bad">Anonymisiert</span>' : user.active ? '<span class="badge ok">Aktiv</span>' : '<span class="badge bad">Deaktiviert</span>'}</td>
                        <td>${user.email_verified ? '<span class="badge ok">Bestätigt</span>' : '<span class="badge warn">Offen</span>'}<br><span class="small muted">${esc(user.email)}</span></td>
                        <td>${esc(formatDate(user.last_login_at))}</td>
                        <td>${(user.household_overview?.memberships || []).length} · ${user.household_overview?.active_public_share_links || 0} öffentliche Links</td>
                        <td><button class="btn secondary smallbtn" data-user-edit="${user.id}">Bearbeiten</button></td>
                    </tr>`).join('')}</tbody>
                </table></div>
                <div class="mobile-cards stack">${data.users.map(user => `<div class="card">
                    <div class="row"><div class="grow"><strong>${esc(user.display_name)}</strong><div class="small muted">${esc(user.email)}</div></div>${statusBadge(user.role)}</div>
                    <div class="statusline"><span class="badge">${user.active_loans} Verleihungen</span><span class="badge">${user.active_reservations} Vormerkungen</span>${user.email_verified ? '<span class="badge ok">E-Mail bestätigt</span>' : '<span class="badge warn">E-Mail offen</span>'}${user.active ? '<span class="badge ok">Aktiv</span>' : '<span class="badge bad">Deaktiviert</span>'}</div>
                    <div class="small muted" style="margin-top:8px">Letzte Anmeldung: ${esc(formatDate(user.last_login_at))}</div>
                    <button class="btn secondary smallbtn" style="margin-top:10px" data-user-edit="${user.id}">Bearbeiten</button>
                </div>`).join('')}</div>`;
        } catch (error) {
            $('#userResults').innerHTML = `<div class="empty">${esc(error.message)}</div>`;
        }
    }

    function userOverviewHtml(user) {
        if (!user?.id) return '';
        const overview = user.household_overview || {memberships: [], access_grants: [], active_public_share_links: 0};
        const memberships = overview.memberships || [];
        const grants = overview.access_grants || [];
        return `<div class="notice full">
            <strong>Kontoinformationen</strong>
            <div class="small" style="margin-top:8px">Erstellt: ${esc(user.created_at || '–')} · Letzte Anmeldung: ${esc(user.last_login_at || 'Noch nie')}</div>
            <div class="small">E-Mail: ${user.email_verified ? 'bestätigt' : 'nicht bestätigt'} · Aktive öffentliche Links: ${Number(overview.active_public_share_links || 0)}</div>
            <div class="small" style="margin-top:8px"><strong>Haushalte:</strong> ${memberships.length ? memberships.map(h => `${esc(h.name)}${h.is_owner ? ' (Eigentümer)' : ''}${h.membership_active ? '' : ' (inaktiv)'}`).join(', ') : 'keine'}</div>
            <div class="small" style="margin-top:4px"><strong>Erhaltene Freigaben:</strong> ${grants.length ? grants.map(g => `${esc(g.household_name)}${g.active ? '' : ' (inaktiv)'}`).join(', ') : 'keine'}</div>
        </div>`;
    }

    function userFormHtml(user = {}) {
        return `<form id="userForm" class="stack">
            <input type="hidden" name="id" value="${attr(user.id || '')}">
            <div class="field-grid">
                <label>Name<input name="display_name" required maxlength="120" value="${attr(user.display_name || '')}"></label>
                ${user.id ? '' : '<label>Haushaltsname<input name="household_name" required maxlength="160"></label>'}
                <label>E-Mail-Adresse<input name="email" type="email" required maxlength="191" value="${attr(user.email || '')}"></label>
                <label>Rolle<select name="role"><option value="member" ${user.role !== 'admin' ? 'selected' : ''}>Benutzer</option><option value="admin" ${user.role === 'admin' ? 'selected' : ''}>Administrator</option></select></label>
                <label>${user.id ? 'Neues Passwort (optional, besser Einmallink verwenden)' : 'Startpasswort (optional; leer = Einmallink)'}<input name="password" type="password" minlength="10" autocomplete="new-password"></label>
                ${user.id ? `<label class="checkbox full"><input name="active" type="checkbox" value="1" ${user.active ? 'checked' : ''}>Konto aktiv</label>` : ''}
                <label class="full">Eigenes Administratorpasswort zur Bestätigung<input name="admin_password" type="password" autocomplete="current-password" required></label>
                ${userOverviewHtml(user)}
                <div id="userActionInfo" class="notice full hidden"></div>
            </div>
        </form>`;
    }

    function openNewUser() {
        openModal('Benutzer anlegen', userFormHtml(), `<button class="btn secondary" data-modal-close>Abbrechen</button><button id="saveUserBtn" class="btn">Anlegen</button>`);
        $('#saveUserBtn').addEventListener('click', saveUser);
    }

    function openEditUser(id) {
        const user = state.users.find(item => item.id === Number(id));
        if (!user) return;
        openModal('Benutzer bearbeiten', userFormHtml(user), `
            <button class="btn secondary" data-modal-close>Schließen</button>
            ${user.anonymized ? '' : `<button id="sendResetLinkBtn" class="btn secondary">Passwortlink senden</button><button id="revokeSessionsBtn" class="btn warning">Sitzungen beenden</button><button id="anonymizeUserBtn" class="btn danger">Konto anonymisieren</button><button id="saveUserBtn" class="btn">Speichern</button>`}`);
        $('#saveUserBtn')?.addEventListener('click', saveUser);
        $('#sendResetLinkBtn')?.addEventListener('click', () => runUserSecurityAction('user_password_reset_link', user));
        $('#revokeSessionsBtn')?.addEventListener('click', () => runUserSecurityAction('user_sessions_revoke', user));
        $('#anonymizeUserBtn')?.addEventListener('click', () => openAnonymizeUser(user));
    }

    async function saveUser() {
        const form = $('#userForm');
        if (!form.reportValidity()) return;
        const button = $('#saveUserBtn');
        setBusy(button, true);
        const data = formObject(form);
        data.active = form.elements.active ? form.elements.active.checked : true;
        try {
            const result = await api(data.id ? 'user_update' : 'user_create', {method: 'POST', data});
            if (result.reset_url) {
                await navigator.clipboard?.writeText(result.reset_url).catch(() => {});
                toast('Benutzer angelegt. Der Einmallink wurde versendet und, soweit möglich, kopiert.');
            } else {
                toast(data.id ? 'Benutzer aktualisiert.' : 'Benutzer angelegt.');
            }
            closeModal();
            await loadUsers();
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setBusy(button, false);
        }
    }

    async function runUserSecurityAction(action, user) {
        const form = $('#userForm');
        if (!form.elements.admin_password.value) {
            toast('Bitte zuerst das eigene Administratorpasswort eingeben.', 'error');
            form.elements.admin_password.focus();
            return;
        }
        const button = action === 'user_password_reset_link' ? $('#sendResetLinkBtn') : $('#revokeSessionsBtn');
        setBusy(button, true);
        try {
            const result = await api(action, {method: 'POST', data: {id: user.id, admin_password: form.elements.admin_password.value}});
            if (result.reset_url) {
                await navigator.clipboard?.writeText(result.reset_url).catch(() => {});
                const info = $('#userActionInfo');
                info.classList.remove('hidden');
                info.innerHTML = `<strong>Einmallink versendet.</strong><br><span class="small">Der Link wurde, soweit möglich, in die Zwischenablage kopiert:</span><br><code style="word-break:break-all">${esc(result.reset_url)}</code>`;
            }
            toast(result.message || 'Aktion abgeschlossen.');
            if (result.self_logged_out) await bootstrap();
            else await loadUsers();
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setBusy(button, false);
        }
    }

    async function openAnonymizeUser(user) {
        const form = $('#userForm');
        const adminPassword = String(form.elements.admin_password.value || '');
        if (!adminPassword) {
            toast('Bitte zuerst das eigene Administratorpasswort eingeben.', 'error');
            form.elements.admin_password.focus();
            return;
        }
        try {
            const preview = await api('user_deletion_preview', {method: 'POST', data: {id: user.id, admin_password: adminPassword}});
            const owned = preview.owned_households || [];
            const candidateOptions = (preview.transfer_candidates || []).map(candidate => `<option value="${candidate.id}">${esc(candidate.display_name)} · ${esc(candidate.email)}</option>`).join('');
            openModal('Konto anonymisieren', `<form id="anonymizeUserForm" class="stack">
                <input type="hidden" name="id" value="${user.id}">
                <input type="hidden" name="admin_password" value="${attr(adminPassword)}">
                <div class="notice warning"><strong>Diese Aktion ist nicht rückgängig zu machen.</strong><br>Das Konto wird deaktiviert, Name und E-Mail-Adresse werden anonymisiert und alle Sitzungen beendet. Historische Vorgänge bleiben mit einem anonymen Kontodatensatz verknüpft.</div>
                <div class="small">Aktive Verleihungen: ${preview.counts.active_loans} · Aktive Vormerkungen: ${preview.counts.active_reservations} · Historische Verleihungen: ${preview.counts.loan_history} · Audit-Einträge: ${preview.counts.audit_entries}</div>
                ${owned.length ? `<label>Neuer Eigentümer für ${owned.length} Haushalt/Haushalte<select name="transfer_user_id" required><option value="">Bitte wählen</option>${candidateOptions}</select></label>` : '<input type="hidden" name="transfer_user_id" value="0">'}
                <label>Zur Bestätigung exakt eingeben:<br><code>${esc(preview.confirmation_phrase)}</code><input name="confirmation" required autocomplete="off"></label>
            </form>`, `<button class="btn secondary" data-modal-close>Abbrechen</button><button id="confirmAnonymizeBtn" class="btn danger">Endgültig anonymisieren</button>`);
            $('#confirmAnonymizeBtn').addEventListener('click', async () => {
                const deleteForm = $('#anonymizeUserForm');
                if (!deleteForm.reportValidity()) return;
                const button = $('#confirmAnonymizeBtn');
                setBusy(button, true);
                try {
                    const result = await api('user_anonymize', {method: 'POST', data: formObject(deleteForm)});
                    closeModal();
                    toast(result.message || 'Konto anonymisiert.');
                    await loadUsers();
                } catch (error) {
                    toast(error.message, 'error');
                } finally {
                    setBusy(button, false);
                }
            });
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    $('#newUserBtn').addEventListener('click', openNewUser);

    $('#sendVerificationBtn').addEventListener('click', async event => {
        const button = event.currentTarget;
        setBusy(button, true);
        try {
            const result = await api('email_verification_request', {method: 'POST', data: {}});
            toast(result.message || 'Bestätigungslink versendet.');
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setBusy(button, false);
        }
    });

    $('#profileForm').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = $('button[type=submit]', form);
        setBusy(button, true);
        try {
            await api('profile_update', {method: 'POST', data: formObject(form)});
            toast('Profil gespeichert.');
            form.elements.current_password.value = '';
            form.elements.new_password.value = '';
            await bootstrap();
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setBusy(button, false);
        }
    });


    /**
     * Wartung: Lädt die kontobezogene Datenschutz-Datenkopie nach erneuter Passwortprüfung herunter.
     * Aufgerufen von: privacyExportForm-Submit.
     * Abhängigkeiten: state.csrf, setBusy(), toast().
     */
    async function downloadPrivacyExport(currentPassword) {
        const params = new URLSearchParams({api: 'privacy_export'});
        let response;

        try {
            response = await fetch(`${location.pathname}?${params.toString()}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrf,
                },
                body: JSON.stringify({current_password: currentPassword}),
            });
        } catch {
            throw new Error('Der Server ist nicht erreichbar.');
        }

        if (!response.ok) {
            let payload = null;
            try {
                payload = await response.json();
            } catch {
                // Eine ungültige Fehlerantwort wird unten allgemein behandelt.
            }
            if (response.status === 401 || payload?.auth_required) {
                state.user = null;
                showAuth('login');
            }
            throw new Error(payload?.error || `Fehler ${response.status}`);
        }

        const blob = await response.blob();
        const disposition = response.headers.get('Content-Disposition') || '';
        const filenameMatch = disposition.match(/filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i);
        let filename = filenameMatch?.[1] || filenameMatch?.[2] || `triamo-datenauskunft-${new Date().toISOString().slice(0, 10)}.json`;
        try {
            filename = decodeURIComponent(filename);
        } catch {
            // Unverändert verwenden, falls der Dateiname nicht URL-kodiert ist.
        }

        const objectUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = filename;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
    }

    $('#privacyExportForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = $('button[type=submit]', form);
        const password = String(form.elements.current_password.value || '');
        const info = $('#privacyExportInfo');

        if (password === '') {
            toast('Bitte das aktuelle Passwort eingeben.', 'error');
            return;
        }

        setBusy(button, true, 'Datenkopie wird erstellt …');
        if (info) info.textContent = '';

        try {
            await downloadPrivacyExport(password);
            form.reset();
            if (info) info.textContent = 'Die Datenkopie wurde erstellt und heruntergeladen.';
            toast('Datenkopie heruntergeladen.');
        } catch (error) {
            if (info) info.textContent = error.message;
            toast(error.message, 'error');
        } finally {
            setBusy(button, false);
        }
    });


    /**
     * Wartung: Startet den Download einer erzeugten Datei.
     * Aufgerufen von: Globaler Ablauf/API/Events.
     * Abhängigkeiten: keine internen Hilfsfunktionen.
     */
    function downloadBackup(scope) {
        const params = new URLSearchParams({api: 'backup_export', scope});
        window.open(`${location.pathname}?${params.toString()}`, '_blank', 'noopener');
    }

    $('#backupHouseholdBtn')?.addEventListener('click', () => downloadBackup('household'));
    $('#backupAllBtn')?.addEventListener('click', () => downloadBackup('all'));
    $('#backupRestoreForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const file = $('#backupFile')?.files?.[0];
        if (!file) { toast('Bitte eine Backupdatei auswählen.', 'error'); return; }
        if (!confirm('Backup wirklich wiederherstellen? Ein Haushaltsbackup wird als neuer Haushalt angelegt. Ein vollständiges Systembackup ersetzt alle Tabellen dieses Präfixes.')) return;
        const button = $('button[type=submit]', form);
        setBusy(button, true, 'Wiederherstellen …');
        try {
            const backupText = await file.text();
            const result = await api('backup_restore', {method: 'POST', data: {backup_json: backupText}});
            $('#backupRestoreInfo').textContent = result.restore?.mode === 'all'
                ? 'Systembackup wurde wiederhergestellt. Die Seite wird neu geladen.'
                : 'Haushaltsbackup wurde als neuer Haushalt wiederhergestellt. Die Seite wird neu geladen.';
            toast('Backup wiederhergestellt.');
            setTimeout(() => location.reload(), 1000);
        } catch (error) {
            toast(error.message, 'error');
        } finally { setBusy(button, false); }
    });

    document.addEventListener('click', async event => {
        const open = event.target.closest('[data-book-open]');
        if (open) {
            try {
                await ensureHouseholdContext(Number(open.dataset.householdId || state.user.active_household_id));
                await openBook(Number(open.dataset.bookOpen));
            } catch (error) { toast(error.message, 'error'); }
            return;
        }

        const pageButton = event.target.closest('[data-books-page]');
        if (pageButton) {
            const direction = pageButton.dataset.booksPage;
            const pagination = state.lastBookPagination || {};
            const limit = state.bookLimit === 'all' ? 0 : Math.max(1, Number(pagination.limit_numeric || state.bookLimit || 200));
            if (limit > 0) {
                const maxFound = Number(pagination.found || 0);
                if (direction === 'first') state.bookOffset = 0;
                else if (direction === 'prev') state.bookOffset = Math.max(0, state.bookOffset - limit);
                else if (direction === 'next') state.bookOffset += limit;
                else if (direction === 'last' && maxFound) state.bookOffset = Math.max(0, Math.floor((maxFound - 1) / limit) * limit);
                loadBooks();
            }
            return;
        }

        const tagSearch = event.target.closest('[data-tag-search]');
        if (tagSearch) {
            applyBookSearch(tagSearch.dataset.tagSearch || '');
            return;
        }

        const edit = event.target.closest('[data-book-edit]');
        if (edit) editBook(Number(edit.dataset.bookEdit));

        const addCopy = event.target.closest('[data-book-add-copy]');
        if (addCopy) openAddCopy(Number(addCopy.dataset.bookAddCopy));

        const copyEdit = event.target.closest('[data-copy-edit]');
        if (copyEdit) editCopy(Number(copyEdit.dataset.copyEdit), Number(copyEdit.dataset.bookId));

        const copyDelete = event.target.closest('[data-copy-delete]');
        if (copyDelete) deleteCopy(Number(copyDelete.dataset.copyDelete), Number(copyDelete.dataset.bookId));

        const copyRestore = event.target.closest('[data-copy-restore]');
        if (copyRestore) restoreCopy(Number(copyRestore.dataset.copyRestore), Number(copyRestore.dataset.bookId));

        const libraryReturn = event.target.closest('[data-library-return]');
        if (libraryReturn) returnLibraryCopy(Number(libraryReturn.dataset.libraryReturn), Number(libraryReturn.dataset.bookId));

        const retry = event.target.closest('[data-book-retry]');
        if (retry) retryMetadata(Number(retry.dataset.bookRetry));

        const del = event.target.closest('[data-book-delete]');
        if (del) deleteBook(Number(del.dataset.bookDelete));

        const restore = event.target.closest('[data-book-restore]');
        if (restore) {
            try {
                await ensureHouseholdContext(Number(restore.dataset.householdId || state.user.active_household_id));
                restoreBook(Number(restore.dataset.bookRestore));
            } catch (error) { toast(error.message, 'error'); }
            return;
        }

        const loan = event.target.closest('[data-loan-book]');
        if (loan) {
            try {
                await ensureHouseholdContext(Number(loan.dataset.householdId || state.user.active_household_id));
                openLoanDialog(Number(loan.dataset.loanBook));
            } catch (error) { toast(error.message, 'error'); }
            return;
        }

        const reserve = event.target.closest('[data-reserve-book]');
        if (reserve) {
            try {
                await ensureHouseholdContext(Number(reserve.dataset.householdId || state.user.active_household_id));
                reserveBook(Number(reserve.dataset.reserveBook));
            } catch (error) { toast(error.message, 'error'); }
            return;
        }

        const returned = event.target.closest('[data-return-loan]');
        if (returned) openReturnDialog(Number(returned.dataset.returnLoan), Number(returned.dataset.bookId || 0));

        const cancel = event.target.closest('[data-cancel-reservation]');
        if (cancel) cancelReservation(Number(cancel.dataset.cancelReservation));

        const locationBooks = event.target.closest('[data-location-books]');
        if (locationBooks) {
            const label = locationBooks.textContent?.trim() || 'Standort';
            setBookLocationFilter(locationBooks.dataset.locationBooks || '', label);
            return;
        }

        const locationEdit = event.target.closest('[data-location-edit]');
        if (locationEdit) openLocationForm(locationEdit.dataset.locationEdit);

        const searchMeta = event.target.closest('[data-search-value]');
        if (searchMeta) {
            event.preventDefault();
            event.stopPropagation();
            applyBookSearch(searchMeta.dataset.searchValue || '');
        }

        const coverSelect = event.target.closest('[data-cover-select]');
        if (coverSelect) selectCover(Number(coverSelect.dataset.bookId), Number(coverSelect.dataset.coverSelect));

        const metadataRaw = event.target.closest('[data-metadata-raw]');
        if (metadataRaw) {
            openMetadataRaw(Number(metadataRaw.dataset.metadataRaw), Number(metadataRaw.dataset.bookId));
            return;
        }

        const reloadBooksBtn = event.target.closest('#booksReloadBtn');
        if (reloadBooksBtn) {
            clearBooksReloadNotice();
            loadBooks();
            if (state.currentDetailBookId && !$('#modalBackdrop').classList.contains('hidden')) openBook(state.currentDetailBookId);
            return;
        }

        const cancelMetadataJob = event.target.closest('[data-cancel-metadata-job]');
        if (cancelMetadataJob && confirm('Diesen Metadatenauftrag abbrechen? Die gespeicherten Quellenantworten bleiben sichtbar.')) {
            api('metadata_job_cancel', {method: 'POST', data: {job_id: Number(cancelMetadataJob.dataset.cancelMetadataJob)}})
                .then(data => { toast('Metadatenauftrag abgebrochen.'); renderMetadataQueue(data); markBooksReloadAvailable('Ein Metadatenauftrag wurde abgebrochen.'); })
                .catch(error => toast(error.message, 'error'));
            return;
        }

        const adopt = event.target.closest('[data-adopt-metadata]');
        if (adopt) adoptCommunityMetadata(Number(adopt.dataset.adoptMetadata), Number(adopt.dataset.bookId));

        const copyText = event.target.closest('[data-copy-text]');
        if (copyText) navigator.clipboard?.writeText(copyText.dataset.copyText || '').then(() => toast('Link kopiert.')).catch(() => toast('Kopieren nicht möglich.', 'error'));

        const shareRevoke = event.target.closest('[data-share-revoke]');
        if (shareRevoke && confirm('Diesen öffentlichen Link sofort widerrufen?')) api('share_link_revoke',{method:'POST',data:{id:Number(shareRevoke.dataset.shareRevoke)}}).then(()=>{toast('Link widerrufen.');loadSharing()}).catch(error=>toast(error.message,'error'));

        const keyDelete = event.target.closest('[data-access-key-delete]');
        if (keyDelete && confirm('Diesen noch nicht eingelösten Zugriffsschlüssel löschen?')) {
            api('access_key_delete',{method:'POST',data:{id:Number(keyDelete.dataset.accessKeyDelete)}})
                .then(()=>{toast('Einmalschlüssel gelöscht.');loadSharing()}).catch(error=>toast(error.message,'error'));
        }

        const grantPause = event.target.closest('[data-grant-pause]');
        if (grantPause) api('access_grant_pause',{method:'POST',data:{id:Number(grantPause.dataset.grantPause)}}).then(()=>{toast('Zugriff pausiert.');loadSharing()}).catch(error=>toast(error.message,'error'));

        const grantResume = event.target.closest('[data-grant-resume]');
        if (grantResume) api('access_grant_resume',{method:'POST',data:{id:Number(grantResume.dataset.grantResume)}}).then(()=>{toast('Zugriff fortgesetzt.');loadSharing()}).catch(error=>toast(error.message,'error'));

        const grantRevoke = event.target.closest('[data-grant-revoke]');
        if (grantRevoke && confirm('Dieser Person den Zugriff endgültig entziehen? Eine spätere Freigabe ist nur über einen neuen Schlüssel oder direkten Gegenzugriff möglich.')) api('access_grant_revoke',{method:'POST',data:{id:Number(grantRevoke.dataset.grantRevoke)}}).then(()=>{toast('Zugriff entzogen.');loadSharing()}).catch(error=>toast(error.message,'error'));

        const reciprocate = event.target.closest('[data-grant-reciprocate]');
        if (reciprocate && confirm('Dem Besitzer dieses Haushalts direkten Zugriff auf deinen aktuellen Haushalt erlauben?')) api('access_grant_reciprocate',{method:'POST',data:{id:Number(reciprocate.dataset.grantReciprocate)}}).then(()=>{toast('Gegenzugriff eingerichtet.');loadSharing()}).catch(error=>toast(error.message,'error'));

        const publicBook = event.target.closest('[data-public-book]');
        if (publicBook) openPublicBook(Number(publicBook.dataset.publicBook));

        const userEdit = event.target.closest('[data-user-edit]');
        if (userEdit) openEditUser(Number(userEdit.dataset.userEdit));
    });

    let publicSearchTimer = null;
    $('#publicBookSearch').addEventListener('input', () => { clearTimeout(publicSearchTimer); publicSearchTimer = setTimeout(loadPublicBooks, 250); });

    bootstrap();
})();
</script>
</body>
</html>
