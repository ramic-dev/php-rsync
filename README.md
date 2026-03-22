# php-rsync

A pure-PHP implementation of the rsync delta-transfer algorithm. No system binaries required.

## How it works

Like the original rsync, file transfers use a rolling-checksum delta algorithm:

1. **BlockChecksums** — the destination file is streamed block by block; each block is fingerprinted with a fast rolling checksum (weak) and MD5 (strong).
2. **StreamDeltaProcessor** — a sliding window scans the source file in chunks; on a block match the block is copied directly from the destination via `fseek`; non-matching bytes are written immediately as literals. Neither file is ever fully loaded into memory.
3. The result is written atomically (temp file + rename).

Peak memory for a 1 GB file: ≈ 18 MB regardless of file size.

## Requirements

- PHP 8.1+
- **Local sync:** no extensions beyond the standard library
- **Remote sync (HTTP transport):** `curl` extension
- **Auto-updater (`bin/rsync-update`):** `curl` + `zip` extensions

## Installation

```bash
composer require ramic/php-rsync
```

## Usage

### Local sync

```php
use Ramic\Rsync\Rsync;

$stats = Rsync::create()
    ->from('/var/www/html/')
    ->to('/backup/html/')
    ->archive()          // recursive + preserve links, permissions, timestamps
    ->delete()           // remove files in dest that no longer exist in source
    ->exclude('.git')
    ->exclude('*.log')
    ->sync();

echo "Files transferred: " . $stats->getFilesTransferred() . "\n";
echo "Bytes transferred: " . $stats->getBytesTotal() . "\n";
echo "Delta efficiency:  " . round($stats->getDeltaEfficiency() * 100) . "%\n";
echo "Duration:          " . round($stats->getDuration(), 3) . "s\n";
```

### Remote sync (HTTP transport)

Deploy `remote/.ramic_tools/syncgate/receiver.php` on the remote server, then:

```php
$stats = Rsync::create()
    ->from('C:/local/folder/')
    ->to('https://example.com/.ramic_tools/syncgate/receiver.php')
    ->key('your-secret-key')
    ->archive()
    ->delete()
    ->sync();
```

#### Deploy / update the receiver

After updating the library, push the new `receiver.php` to the server in one call:

```php
Rsync::create()
    ->to('https://example.com/.ramic_tools/syncgate/receiver.php')
    ->key('your-secret-key')
    ->deployReceiver();          // optional: ->deployReceiver('/custom/path/receiver.php')
```

The remote receiver replaces itself atomically (temp file + rename).

### Options

| Method | rsync equivalent | Description |
|--------|-----------------|-------------|
| `archive()` | `-a` | Recursive + preserve links, permissions, timestamps |
| `recursive()` | `-r` | Recurse into subdirectories |
| `delete()` | `--delete` | Delete extraneous files from destination |
| `dryRun()` | `-n` | Show what would be done without making changes |
| `verbose($logger)` | `-v` | Pass a `callable(string): void` to receive log lines |
| `checksum()` | `-c` | Compare files by MD5 instead of mtime + size |
| `preserveTimes()` | `-t` | Preserve modification timestamps |
| `preservePermissions()` | `-p` | Preserve file permissions |
| `preserveLinks()` | `-l` | Preserve symbolic links |
| `exclude($pattern)` | `--exclude` | Exclude files matching a glob pattern |
| `include($pattern)` | `--include` | Include files matching a glob pattern (evaluated before excludes) |
| `blockSize($bytes)` | `--block-size` | Override the automatic delta block size |
| `key($secret)` | — | Secret key for remote HTTP transport |
| `deployReceiver($path)` | — | Push a new receiver.php to the remote endpoint |

### Exclude / include rules

Rules are evaluated in order; the first match wins. If no rule matches, the file is included.

```php
Rsync::create()
    ->from('/src/')
    ->to('/dst/')
    ->include('*.php')      // include PHP files explicitly
    ->exclude('vendor/')    // exclude vendor directory and everything inside
    ->exclude('*.log')
    ->sync();
```

Patterns support `*` (any characters except `/`) and `?`. Patterns ending with `/` match directories only. Patterns starting with `/` are anchored to the root of the transfer.

### Verbose logging

```php
Rsync::create()
    ->from('/src/')
    ->to('/dst/')
    ->verbose(fn(string $msg) => print($msg . "\n"))
    ->sync();
```

### Dry run

```php
$stats = Rsync::create()
    ->from('/src/')
    ->to('/dst/')
    ->dryRun()
    ->sync();

foreach ($stats->getTransferredFiles() as $file) {
    echo "would transfer: $file\n";
}
```

## Architecture

```
src/
├── Algorithm/
│   ├── RollingChecksum.php      # Sliding-window checksum (Tridgell & Mackerras, 1996)
│   ├── BlockChecksums.php       # Per-block weak+strong fingerprint index (fromFile / fromContent / fromArray)
│   ├── BlockSize.php            # Heuristic: max(700, √fileSize), capped at 128 KB
│   ├── StreamDeltaProcessor.php # Stream-based delta: O(chunk) memory, no full file load
│   ├── DeltaGenerator.php       # Instruction-based delta (used by HTTP transport)
│   └── DeltaApplicator.php      # Applies instruction list to reconstruct a file
├── FileSystem/
│   ├── FileInfo.php             # File metadata value object
│   └── Scanner.php              # Recursive directory scanner with filter support
├── Filter/
│   ├── FilterRule.php           # Single include/exclude rule (fnmatch-based)
│   └── FilterList.php           # Ordered rule list; first match wins
├── Sync/
│   ├── SyncOptions.php          # All sync options
│   ├── SyncStats.php            # Transfer statistics and delta efficiency ratio
│   └── SyncEngine.php           # Orchestrates scan → compare → StreamDeltaProcessor → write
├── Transport/
│   ├── TransportException.php   # HTTP transport error
│   ├── HttpTransport.php        # curl-based client for remote receiver.php
│   └── RemoteSyncEngine.php     # Orchestrates remote sync via HttpTransport
└── Rsync.php                    # Fluent public API

remote/
└── .ramic_tools/
    ├── .htaccess                # Allows HTTP access to the hidden directory (Apache)
    └── syncgate/
        ├── .htaccess            # Enables PHP 8 (AddHandler av-php8, Altervista-specific), disables directory listing
        ├── .env.example         # Template — copy to .env on the server and set SECRET_KEY
        └── receiver.php         # Self-contained HTTP endpoint — PHP 7.4 compatible,
                                 # no external dependencies, streaming checksums and delta apply
```

## Security note

The secret key passed to `key()` grants the remote endpoint full read/write/delete access to its managed directory. Treat it like a password: generate it with `php -r "echo bin2hex(random_bytes(16));"`, store it in `.env` (never committed), and rotate it with `bin/rsync-update` or the provided `tools/rotate-key.php` helper.

## Auto-update

The background worker (`bin/rsync-worker`) checks GitHub for a newer release at most once per hour and, if found, installs it automatically. This replaces `src/`, `remote/`, and `bin/` in-place; it does **not** call `deployReceiver()` automatically — run that explicitly after an update to push the new `receiver.php` to your remote server.

If you install via Composer and prefer to manage updates through `composer update`, disable the auto-updater by deleting or not using `bin/rsync-worker`.

## Limitations

- **Remote sync: instruction-based.** For remote transfers the delta instructions (including literal bytes) are serialised as JSON. For files with many changes this can result in large HTTP payloads. Chunked streaming per file is not yet implemented.
- **No owner/group preservation.** `chown`/`chgrp` are not implemented.
- **Symlinks on Windows** require elevated privileges.

## License

MIT
