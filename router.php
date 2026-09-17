<?php
declare(strict_types=1);

// PHP's development server ignores .htaccess. Block private project files here.
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$privateDirectories = ['/apache', '/data', '/includes', '/scripts', '/tests', '/example', '/.git', '/.runtime'];
$privateFiles = ['/schema.sql', '/README.md', '/Makefile', '/Dockerfile', '/compose.yaml', '/router.php'];

foreach ($privateDirectories as $directory) {
    if ($path === $directory || str_starts_with($path, $directory . '/')) {
        http_response_code(404);
        exit;
    }
}

if (in_array($path, $privateFiles, true)) {
    http_response_code(404);
    exit;
}

if ($path === '/') {
    require __DIR__ . '/index.php';
    exit;
}

$requestedFile = __DIR__ . $path;
if (is_file($requestedFile)) {
    return false;
}

http_response_code(404);
