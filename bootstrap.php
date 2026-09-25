<?php
/**
 * ezBookDash shared runtime bootstrap.
 *
 * Centralizes private runtime storage and applies one session policy to both
 * index.php and api.php. The default runtime directory is web-blocked; a
 * separate path can be supplied with EBK_STORAGE_DIR.
 */

declare(strict_types=1);

// Never leak filesystem warnings into HTML or JSON responses.
ini_set('display_errors', '0');

$config = require __DIR__ . '/config.php';

$envMap = [
    'base_url'   => 'EBK_BASE_URL',
    'api_token'  => 'EBK_API_TOKEN',
    'username'   => 'EBK_USERNAME',
    'password'   => 'EBK_PASSWORD',
    'timezone'   => 'EBK_TIMEZONE',
    'storage_dir'=> 'EBK_STORAGE_DIR',
];
foreach ($envMap as $key => $env) {
    $value = getenv($env);
    if ($value !== false && $value !== '') {
        $config[$key] = $value;
    }
}

$storageDir = trim((string)($config['storage_dir'] ?? ''));
if ($storageDir === '') {
    // Keep the default inside the application root so PHP open_basedir allows it.
    // The directory is protected by runtime/.htaccess and should still be moved
    // outside the public root with EBK_STORAGE_DIR in higher-security deployments.
    $storageDir = __DIR__ . '/runtime';
}
if (!preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $storageDir)) {
    $storageDir = __DIR__ . '/' . ltrim($storageDir, '/\\');
}
$storageDir = rtrim($storageDir, '/\\');
$cacheDir   = $storageDir . '/cache';
$dataDir    = $storageDir . '/data';
$sessionDir = $storageDir . '/sessions';
$legacySessionDir = $storageDir . '/legacy-sessions';

foreach ([$storageDir, $cacheDir, $dataDir, $sessionDir, $legacySessionDir] as $dir) {
    if (!@is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
}
if (!@is_dir($storageDir) || !@is_writable($storageDir) || !@is_dir($sessionDir) || !@is_writable($sessionDir)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('ezBookDash 私有数据目录不可写，请配置 EBK_STORAGE_DIR 并授予 PHP 进程读写权限。');
}

/* One-way compatibility migration. Old files remain untouched for rollback. */
$migrateFiles = static function (string $from, string $to, string $pattern, bool $removeSource = false): void {
    if (!@is_dir($from) || !@is_dir($to)) {
        return;
    }
    foreach ((array)glob($from . '/' . $pattern) as $source) {
        if (!is_file($source)) {
            continue;
        }
        $target = $to . '/' . basename($source);
        if (!@is_file($target)) {
            @copy($source, $target);
            @chmod($target, 0600);
        }
        if ($removeSource && @is_file($target)) {
            $targetSize = @filesize($target);
            $sourceSize = @filesize($source);
            if ($targetSize !== false && $sourceSize !== false && $targetSize === $sourceSize) {
                @unlink($source);
            }
        }
    }
};
$migrateFiles(__DIR__ . '/cache', $cacheDir, '*.json', true);
$migrateFiles(__DIR__ . '/cache/sessions', $legacySessionDir, 'sess_*', true);
$migrateFiles(__DIR__ . '/data', $dataDir, '*');

$isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
if (!empty($config['trust_proxy']) && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $isHttps = strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https';
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('EZBOOKDASHSESSID');
session_save_path($sessionDir);
$sessionLife = max(300, (int)($config['session_lifetime'] ?? 604800));
ini_set('session.gc_maxlifetime', (string)$sessionLife);
session_set_cookie_params([
    'lifetime' => $sessionLife,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

return [
    'config'      => $config,
    'storage_dir' => $storageDir,
    'cache_dir'   => $cacheDir,
    'data_dir'    => $dataDir,
    'session_dir' => $sessionDir,
    'https'       => $isHttps,
];
