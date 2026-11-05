<?php
declare(strict_types=1);

const APP_ROOT = __DIR__;

function app_env_file(): string
{
    return (string)(getenv('WEBAPP2_ENV_FILE') ?: APP_ROOT . '/.env');
}

function app_env(string $key, string $default = ''): string
{
    static $values = null;
    if ($values === null) {
        $file = app_env_file();
        $values = is_file($file) ? (parse_ini_file($file, false, INI_SCANNER_RAW) ?: []) : [];
    }
    // Process/service environment takes precedence, which keeps deployments
    // portable across Ubuntu hosts, containers and systemd services.
    $environmentValue = getenv($key);
    if ($environmentValue !== false && $environmentValue !== '') {
        return trim((string)$environmentValue);
    }
    return trim((string)($values[$key] ?? $default));
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        app_env('WEBAPP2_DB_HOST', '127.0.0.1'),
        app_env('WEBAPP2_DB_PORT', '3306'),
        app_env('WEBAPP2_DB_NAME', 'webapp2_pairing')
    );
    $pdo = new PDO($dsn, app_env('WEBAPP2_DB_USER', 'webapp2_pairing'), app_env('WEBAPP2_DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require_once APP_ROOT . '/runtime.php';
