<?php
declare(strict_types=1);

// The built-in PHP server must expose only the two public application
// endpoints. Never serve configuration, schema, source or environment files.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicPaths = ['/pa/index.php', '/pb/index.php'];

if (!in_array($path, $publicPaths, true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";
    return true;
}

return false;
