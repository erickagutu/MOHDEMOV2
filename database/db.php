<?php
/**
 * Database Connection & Core Security Configuration
 * Ministry of Health — State Department for Medical Services (PP-SDOMS)
 * Native PHP 8.1+ & PDO MySQL (Zero Node.js Dependencies)
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Load environment variables if .env exists
function load_env($filePath = __DIR__ . '/../.env') {
    if (!file_exists($filePath)) {
        $filePath = __DIR__ . '/../.env.example';
    }
    if (file_exists($filePath)) {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) continue;
            if (strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val, " \t\n\r\0\x0B\"'");
                if (!isset($_ENV[$key])) {
                    $_ENV[$key] = $val;
                    putenv("$key=$val");
                }
            }
        }
    }
}
load_env();

// Database credentials
$db_host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
$db_port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
$db_name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'chasonet_mohdemov2';
$db_user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'chasonet_moh';
$db_pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: 'cognito.88';

$pdo = null;
$db_connected = false;

try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    $db_connected = true;
} catch (PDOException $e) {
    $db_connected = false;
    $db_error = $e->getMessage();
}

/**
 * Global CSRF Protection
 */
function get_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

/**
 * Authentication & RBAC Helpers
 */
function get_current_user_data(): array {
    if (!empty($_SESSION['user'])) {
        return $_SESSION['user'];
    }
    return [];
}

function require_auth() {
    if (empty($_SESSION['user'])) {
        header('Location: /index.php');
        exit;
    }
}

function is_admin(): bool {
    $u = get_current_user_data();
    return !empty($u['is_admin']);
}

function can_edit(): bool {
    $u = get_current_user_data();
    return !empty($u['can_edit']) || !empty($u['is_admin']);
}

/**
 * Formatting & Audit Trail
 */
function format_kes($amount): string {
    return 'KES ' . number_format((float)$amount, 2);
}

function log_audit_trail($action, $module, $record_id = null, $details = '') {
    global $pdo, $db_connected;
    $u = get_current_user_data();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if ($db_connected && $pdo) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_name, user_email, action, module, record_id, details, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $u['full_name'],
                $u['email'],
                $action,
                $module,
                $record_id,
                $details,
                $ip
            ]);
        } catch (Exception $e) {
            // Silently capture error
        }
    }
}
