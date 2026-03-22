<?php
// php-rsync syncgate receiver — self-contained, PHP 7.4 compatible

// ─── CONFIGURATION ───────────────────────────────────────────────────────────

/**
 * Secret key shared with the local client.
 * Loaded from .env (same directory) if present; falls back to the default.
 * Create .env on the server once:
 *   SECRET_KEY=<your-random-key>
 * Generate a key: php -r "echo bin2hex(random_bytes(16));"
 */
$_envFile = __DIR__ . '/.env';
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        if (strpos($_line, '=') === false || ltrim($_line)[0] === '#') {
            continue;
        }
        list($_k, $_v) = explode('=', $_line, 2);
        if (trim($_k) === 'SECRET_KEY') {
            define('SECRET_KEY', trim($_v));
            break;
        }
    }
}
if (!defined('SECRET_KEY')) {
    // .env not found — fail closed rather than accepting the default key.
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Receiver not configured: create .env with SECRET_KEY']);
    exit;
}
if (SECRET_KEY === 'CHANGE_ME') {
    // Placeholder key still in place — refuse all requests.
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Receiver not configured: replace CHANGE_ME in .env']);
    exit;
}
unset($_envFile, $_line, $_k, $_v);

/**
 * Root directory this receiver manages (www/ root).
 * Two levels up: www/.ramic_tools/syncgate/ -> www/
 */
define('BASE_PATH', realpath(__DIR__ . '/../..'));

// ─────────────────────────────────────────────────────────────────────────────

header('Content-Type: application/json');

// ─── Rolling checksum (Tridgell & Mackerras) ─────────────────────────────────

function rsync_checksum_init(string $block): array
{
    $len = strlen($block);
    $a   = 0;
    $b   = 0;
    for ($i = 0; $i < $len; $i++) {
        $byte = ord($block[$i]);
        $a   += $byte;
        $b   += ($len - $i) * $byte;
    }
    return ['a' => $a % 65536, 'b' => $b % 65536, 'len' => $len];
}

function rsync_checksum_value(array $s): int
{
    return $s['a'] | ($s['b'] << 16);
}

// ─── Block checksums ─────────────────────────────────────────────────────────

/**
 * Stream a file block-by-block and return weak+strong checksums.
 * The file is never fully loaded into memory.
 */
function compute_block_checksums(string $filePath, int $blockSize): array
{
    $result = [];
    $fp     = fopen($filePath, 'rb');

    while (!feof($fp)) {
        $block = fread($fp, $blockSize);
        if (strlen($block) < $blockSize) {
            break; // skip partial last block
        }
        $result[] = [
            'weak'   => rsync_checksum_value(rsync_checksum_init($block)),
            'strong' => md5($block),
        ];
    }

    fclose($fp);
    return $result;
}

// ─── Delta applicator ────────────────────────────────────────────────────────

/**
 * Apply a delta to $destPath writing directly to $tmpPath (file-based, no full load).
 * Instructions: [['type'=>'copy','block'=>int] | ['type'=>'literal','data'=>base64]]
 */
function apply_delta(string $destPath, array $instructions, int $blockSize, string $tmpPath): void
{
    $dstFp = is_file($destPath) ? fopen($destPath, 'rb') : null;
    $tmpFp = fopen($tmpPath, 'wb');

    foreach ($instructions as $instr) {
        $type = $instr['type'] ?? '';

        if ($type === 'copy') {
            if ($dstFp === null) {
                respond_error('CopyInstruction on non-existent destination', 400);
            }
            $blockIndex = (int) $instr['block'];
            fseek($dstFp, $blockIndex * $blockSize);
            $data = fread($dstFp, $blockSize);
            if ($data === false || $data === '') {
                respond_error("CopyInstruction block $blockIndex out of bounds", 400);
            }
            fwrite($tmpFp, $data);
        } elseif ($type === 'literal') {
            $data = base64_decode($instr['data'], true);
            if ($data === false) {
                respond_error('Invalid base64 in literal instruction', 400);
            }
            fwrite($tmpFp, $data);
        } else {
            respond_error("Unknown instruction type: $type", 400);
        }
    }

    if ($dstFp !== null) {
        fclose($dstFp);
    }
    fclose($tmpFp);
}

// ─── HTTP helpers ─────────────────────────────────────────────────────────────

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function respond_error(string $message, int $status = 400): void
{
    respond(['ok' => false, 'error' => $message], $status);
}

function resolve_path(string $relPath): string
{
    $normalized = str_replace('\\', '/', $relPath);

    if (strpos($normalized, '..') !== false || $normalized[0] === '/') {
        respond_error('Path traversal detected', 403);
    }

    $base = BASE_PATH;
    if ($base === false || $base === '') {
        respond_error('BASE_PATH is not accessible', 500);
    }

    return $base . '/' . ltrim($normalized, '/');
}

function atomic_write(string $absPath, string $content): void
{
    $dir = dirname($absPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $tmp = tempnam($dir, '.syncgate_tmp_');
    if ($tmp === false) {
        respond_error("Cannot create temp file in: $dir", 500);
    }

    if (file_put_contents($tmp, $content) === false) {
        @unlink($tmp);
        respond_error("Write to temp file failed", 500);
    }

    if (!rename($tmp, $absPath)) {
        @unlink($tmp);
        respond_error("Atomic rename failed: $absPath", 500);
    }
}

// ─── Parse & authenticate ─────────────────────────────────────────────────────

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    respond_error('Invalid JSON body');
}

if (($body['key'] ?? '') !== SECRET_KEY) {
    respond_error('Unauthorized', 401);
}

$action = $body['action'] ?? '';

// ─── Actions ─────────────────────────────────────────────────────────────────

switch ($action) {

    case 'list': {
        $base    = BASE_PATH;
        $baseLen = strlen(str_replace('\\', '/', $base)) + 1;
        $files   = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $base,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $absNorm = str_replace('\\', '/', $item->getPathname());
            $relPath = substr($absNorm, $baseLen);

            // Never expose .ramic_tools/
            if ($relPath === '.ramic_tools' || strncmp($relPath, '.ramic_tools/', 13) === 0) {
                continue;
            }

            $files[$relPath] = [
                'size'  => $item->isFile() ? $item->getSize() : 0,
                'mtime' => $item->getMTime(),
                'type'  => $item->isDir() ? 'dir' : ($item->isLink() ? 'link' : 'file'),
            ];
        }

        respond(['ok' => true, 'files' => $files]);
        break;
    }

    case 'checksums': {
        $path      = $body['path'] ?? '';
        $blockSize = (int) ($body['block_size'] ?? 0);

        if ($blockSize <= 0) {
            respond_error('block_size must be a positive integer');
        }

        $absPath = resolve_path($path);

        if (!is_file($absPath)) {
            respond(['ok' => true, 'checksums' => []]);
        }

        $checksums = compute_block_checksums($absPath, $blockSize);

        respond(['ok' => true, 'checksums' => $checksums]);
        break;
    }

    case 'apply_delta': {
        $path         = $body['path'] ?? '';
        $blockSize    = (int) ($body['block_size'] ?? 0);
        $instructions = $body['instructions'] ?? [];
        $mtime        = isset($body['mtime']) ? (int) $body['mtime'] : null;

        if ($blockSize <= 0) {
            respond_error('block_size must be a positive integer');
        }

        $absPath = resolve_path($path);
        $dir     = dirname($absPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmp = tempnam($dir, '.syncgate_tmp_');
        if ($tmp === false) {
            respond_error('Cannot create temp file', 500);
        }
        apply_delta($absPath, $instructions, $blockSize, $tmp);
        if (!rename($tmp, $absPath)) {
            @unlink($tmp);
            respond_error('Atomic rename failed', 500);
        }

        if ($mtime !== null) {
            touch($absPath, $mtime);
        }

        respond(['ok' => true]);
        break;
    }

    case 'send_file': {
        $path    = $body['path'] ?? '';
        $content = base64_decode($body['content'] ?? '', true);
        $mtime   = isset($body['mtime']) ? (int) $body['mtime'] : null;

        if ($content === false) {
            respond_error('Invalid base64 content');
        }

        $absPath = resolve_path($path);
        atomic_write($absPath, $content);

        if ($mtime !== null) {
            touch($absPath, $mtime);
        }

        respond(['ok' => true]);
        break;
    }

    case 'delete': {
        $absPath = resolve_path($body['path'] ?? '');

        if (is_file($absPath) || is_link($absPath)) {
            if (!unlink($absPath)) {
                respond_error("Cannot delete: {$body['path']}");
            }
        } elseif (is_dir($absPath)) {
            if (!rmdir($absPath)) {
                respond_error("Cannot remove directory (may not be empty): {$body['path']}");
            }
        }

        respond(['ok' => true]);
        break;
    }

    case 'mkdir': {
        $absPath = resolve_path($body['path'] ?? '');

        if (!is_dir($absPath) && !mkdir($absPath, 0755, true)) {
            respond_error("Cannot create directory: {$body['path']}", 500);
        }

        respond(['ok' => true]);
        break;
    }

    // Atomically replace this script with a new version sent by the client.
    case 'update_receiver': {
        $newContent = base64_decode($body['content'] ?? '', true);

        if ($newContent === false || strlen($newContent) < 50) {
            respond_error('Invalid or empty receiver content');
        }

        $selfPath = __FILE__;
        $tmp      = tempnam(dirname($selfPath), '.syncgate_upd_');

        if ($tmp === false) {
            respond_error('Cannot create temp file for self-update', 500);
        }

        if (file_put_contents($tmp, $newContent) === false) {
            @unlink($tmp);
            respond_error('Write failed during self-update', 500);
        }

        if (!rename($tmp, $selfPath)) {
            @unlink($tmp);
            respond_error('Rename failed during self-update', 500);
        }

        respond(['ok' => true]);
        break;
    }

    default:
        respond_error("Unknown action: $action");
}
