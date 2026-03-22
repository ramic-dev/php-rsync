#!/usr/bin/env php
<?php

declare(strict_types=1);

// ── Autoload ──────────────────────────────────────────────────────────────────

spl_autoload_register(static function (string $class): void {
    $prefix = 'Ramic\\Rsync\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require_once $file;
});

// ── Load .env ─────────────────────────────────────────────────────────────────

$envFile = __DIR__ . '/.env';
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v);
}

$currentKey = $env['SYNCGATE_KEY'] ?? '';
$url        = $env['SYNCGATE_URL'] ?? '';

if ($currentKey === '' || $url === '') {
    fwrite(STDERR, "SYNCGATE_KEY or SYNCGATE_URL missing in .env\n");
    exit(1);
}

// ── Generate new key ──────────────────────────────────────────────────────────

$newKey = bin2hex(random_bytes(16)); // 32-char hex

echo "New key: $newKey\n";

// ── Upload new .env to server ─────────────────────────────────────────────────

use Ramic\Rsync\Transport\HttpTransport;

$transport = new HttpTransport($url, $currentKey);

$remoteEnvContent = "SECRET_KEY=$newKey\n";
$remoteEnvPath    = '.ramic_tools/syncgate/.env';

echo "Uploading new .env to server... ";
$transport->sendFile($remoteEnvPath, $remoteEnvContent);
echo "OK\n";

// ── Update local .env ─────────────────────────────────────────────────────────

$env['SYNCGATE_KEY'] = $newKey;
$lines = [];
foreach ($env as $k => $v) {
    $lines[] = "$k=$v";
}
file_put_contents($envFile, implode("\n", $lines) . "\n");
echo "Local .env updated.\n";

// ── Test with new key ─────────────────────────────────────────────────────────

echo "Testing new key... ";
$transport2 = new HttpTransport($url, $newKey);
$files = $transport2->listFiles();
echo "OK — " . count($files) . " file(s) visible on remote.\n";
echo "\nDone. New secret key is active.\n";
