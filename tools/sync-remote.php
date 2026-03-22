#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Sync remote/.ramic_tools/ to the Altervista receiver.
 * Reads SYNCGATE_KEY and SYNCGATE_URL from .env in the project root.
 *
 * Usage:  php sync-remote.php
 */

// ── Autoload (no Composer needed) ────────────────────────────────────────────

$libRoot = dirname(__DIR__); // tools/ → project root

spl_autoload_register(static function (string $class) use ($libRoot): void {
    $prefix = 'Ramic\\Rsync\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $libRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ── Load .env ─────────────────────────────────────────────────────────────────

$envFile = $libRoot . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Missing .env file. Copy .env.example and fill in your values.\n");
    exit(1);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v);
}

$key = $env['SYNCGATE_KEY'] ?? '';
$url = $env['SYNCGATE_URL'] ?? '';

if ($key === '' || $key === 'your-secret-key-here') {
    fwrite(STDERR, "SYNCGATE_KEY not set in .env\n");
    exit(1);
}
if ($url === '') {
    fwrite(STDERR, "SYNCGATE_URL not set in .env\n");
    exit(1);
}

// ── Sync ──────────────────────────────────────────────────────────────────────

use Ramic\Rsync\Rsync;

$source = $libRoot . '/remote/';

echo "Syncing $source → $url\n";

// Note: no ->delete() here.
// The remote list action hides .ramic_tools/ entirely, so --delete would see
// every www/ file as "not in local source" and wipe the remote server.
$stats = Rsync::create()
    ->from($source)
    ->to($url)
    ->key($key)
    ->archive()
    ->verbose(fn(string $msg) => print("  $msg\n"))
    ->sync();

echo "\nDone.\n";
echo '  Files transferred : ' . $stats->getFilesTransferred() . "\n";
echo '  Files skipped     : ' . $stats->getFilesSkipped() . "\n";
echo '  Files deleted     : ' . $stats->getFilesDeleted() . "\n";
echo '  Bytes total       : ' . $stats->getBytesTotal() . "\n";
echo '  Duration          : ' . round($stats->getDuration(), 3) . "s\n";
