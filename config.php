<?php
declare(strict_types=1);

const APP_ROOT = __DIR__;

function app_env_file(): string
{
    return (string)(getenv('MALINALLI_ENV_FILE') ?: APP_ROOT . '/.env');
}

function app_env(string $key, string $default = ''): string
{
    static $values = null;
    if ($values === null) {
        $file = app_env_file();
        $values = is_file($file) ? (parse_ini_file($file, false, INI_SCANNER_RAW) ?: []) : [];
    }
    return trim((string)($values[$key] ?? getenv($key) ?: $default));
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        app_env('DB_HOST', '127.0.0.1'),
        app_env('DB_PORT', '3306'),
        app_env('DB_NAME', 'malinalli_pairing')
    );
    $pdo = new PDO($dsn, app_env('DB_USER', 'malinalli_pairing'), app_env('DB_PASSWORD'), [
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
