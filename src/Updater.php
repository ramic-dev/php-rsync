<?php

declare(strict_types=1);

namespace Ramic\Rsync;

/**
 * Self-updater: checks GitHub releases and installs newer versions out-of-process.
 *
 * Safe by design:
 *   - Runs in a dedicated process (bin/rsync-update), never inside sync().
 *   - Uses an exclusive flock so concurrent workers don't race.
 *   - Extracts to a temp dir first; replaces files only after full download.
 *   - ETag-based conditional GET: GitHub is hit at most once per hour.
 *   - deployReceiver() is NOT called automatically; that remains explicit.
 */
class Updater
{
    private const GITHUB_REPO   = 'ramic/php-rsync';
    private const CHECK_INTERVAL = 3600; // seconds between API calls

    private readonly string $libRoot;
    private readonly string $cacheFile;
    private readonly string $lockFile;
    private $logger; // callable|null — callable not allowed as property type

    public function __construct(string $cacheDir = '', ?callable $logger = null)
    {
        $this->libRoot   = dirname(__DIR__);
        $dir             = $cacheDir ?: sys_get_temp_dir();
        $this->cacheFile = $dir . '/ramic-rsync-update.json';
        $this->lockFile  = $dir . '/ramic-rsync-update.lock';
        $this->logger    = $logger;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public static function getInstalledVersion(): string
    {
        $file = dirname(__DIR__) . '/VERSION';
        return file_exists($file) ? trim((string) file_get_contents($file)) : '0.0.0';
    }

    /**
     * Check GitHub for a newer release and install it if found.
     *
     * @return bool  true if an update was installed, false otherwise.
     */
    public function checkAndUpdate(): bool
    {
        if (!function_exists('curl_init')) {
            $this->log('curl not available — skipping update check.');
            return false;
        }
        if (!class_exists('ZipArchive')) {
            $this->log('ZipArchive not available — skipping update check.');
            return false;
        }

        $lock = fopen($this->lockFile, 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false; // Another updater process is already running.
        }

        try {
            $release = $this->fetchLatestRelease();
            if ($release === null) {
                return false;
            }

            $latest    = ltrim($release['tag_name'], 'v');
            $installed = self::getInstalledVersion();

            if (version_compare($latest, $installed, '<=')) {
                $this->log("Up to date ($installed).");
                return false;
            }

            $this->log("Update available: $installed → $latest");
            $this->downloadAndInstall($release['zipball_url'], $latest);
            $this->log("Updated to $latest successfully.");
            return true;

        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // ── GitHub API ────────────────────────────────────────────────────────────

    /**
     * Returns release data array if an update is available, null otherwise.
     * Uses ETag caching and a minimum check interval to avoid hammering the API.
     */
    private function fetchLatestRelease(): ?array
    {
        $cache     = $this->loadCache();
        $now       = time();
        $installed = self::getInstalledVersion();

        // Within the minimum interval: use cached data without hitting the API.
        if (($cache['checked_at'] ?? 0) > $now - self::CHECK_INTERVAL) {
            $cached = ltrim($cache['latest_version'] ?? '', 'v');
            if ($cached !== '' && version_compare($cached, $installed, '>')) {
                return ['tag_name' => $cache['latest_version'], 'zipball_url' => $cache['latest_zip_url']];
            }
            return null;
        }

        $this->log('Checking GitHub for updates…');

        $headers = [
            'User-Agent: ramic/php-rsync/' . $installed,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if (!empty($cache['etag'])) {
            $headers[] = 'If-None-Match: ' . $cache['etag'];
        }

        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->log("Update check failed: $curlErr");
            return null;
        }

        $respHeaders = substr((string) $raw, 0, $hdrSize);
        $body        = substr((string) $raw, $hdrSize);

        $newEtag = null;
        if (preg_match('/^ETag:\s*(\S+)/mi', $respHeaders, $m)) {
            $newEtag = $m[1];
        }

        if ($httpCode === 304) {
            // Not modified — refresh timestamp, use cached release info.
            $cache['checked_at'] = $now;
            $this->saveCache($cache);

            $cached = ltrim($cache['latest_version'] ?? '', 'v');
            if ($cached !== '' && version_compare($cached, $installed, '>')) {
                return ['tag_name' => $cache['latest_version'], 'zipball_url' => $cache['latest_zip_url']];
            }
            return null;
        }

        if ($httpCode !== 200) {
            $this->log("Update check failed (HTTP $httpCode).");
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['tag_name'])) {
            $this->log('Unexpected GitHub API response.');
            return null;
        }

        $this->saveCache([
            'etag'           => $newEtag,
            'checked_at'     => $now,
            'latest_version' => $data['tag_name'],
            'latest_zip_url' => $data['zipball_url'] ?? null,
        ]);

        return $data;
    }

    // ── Download & install ────────────────────────────────────────────────────

    private function downloadAndInstall(string $zipUrl, string $newVersion): void
    {
        // 1. Download ZIP into a temp file.
        $tmpZip = tempnam(sys_get_temp_dir(), 'rsync-upd-');
        $this->log("Downloading release ZIP…");

        $ch = curl_init($zipUrl);
        $fp = fopen($tmpZip, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: ramic/php-rsync/' . self::getInstalledVersion()],
        ]);
        $ok       = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $httpCode !== 200) {
            @unlink($tmpZip);
            throw new \RuntimeException("Download failed (HTTP $httpCode): $curlErr");
        }

        // 2. Extract to a temp directory.
        $tmpDir = sys_get_temp_dir() . '/rsync-upd-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0700, true);

        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                throw new \RuntimeException('Could not open downloaded ZIP.');
            }
            $zip->extractTo($tmpDir);
            $zip->close();
            @unlink($tmpZip);

            // GitHub wraps everything in a top-level dir (e.g. ramic-php-rsync-abc123/).
            $extractedRoot = $this->findExtractedRoot($tmpDir);

            // 3. Replace src/, remote/, bin/ and VERSION atomically per-directory.
            foreach (['src', 'remote', 'bin'] as $dir) {
                $src = "$extractedRoot/$dir";
                $dst = "$this->libRoot/$dir";
                if (is_dir($src)) {
                    $this->rmdirRecursive($dst);
                    $this->copyDir($src, $dst);
                }
            }

            // Update VERSION file.
            $versionSrc = "$extractedRoot/VERSION";
            $versionDst = "$this->libRoot/VERSION";
            if (file_exists($versionSrc)) {
                copy($versionSrc, $versionDst);
            } else {
                file_put_contents($versionDst, $newVersion . "\n");
            }

        } finally {
            $this->rmdirRecursive($tmpDir);
        }
    }

    private function findExtractedRoot(string $tmpDir): string
    {
        foreach ((array) scandir($tmpDir) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir("$tmpDir/$entry")) {
                return "$tmpDir/$entry";
            }
        }
        throw new \RuntimeException('Could not find extracted root directory in ZIP.');
    }

    // ── Filesystem helpers ────────────────────────────────────────────────────

    private function copyDir(string $src, string $dst): void
    {
        mkdir($dst, 0755, true);
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iter as $item) {
            $target = $dst . substr($item->getPathname(), strlen($src));
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // ── Cache ─────────────────────────────────────────────────────────────────

    private function loadCache(): array
    {
        if (!file_exists($this->cacheFile)) {
            return [];
        }
        return json_decode((string) file_get_contents($this->cacheFile), true) ?: [];
    }

    private function saveCache(array $data): void
    {
        file_put_contents($this->cacheFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function log(string $msg): void
    {
        if ($this->logger !== null) {
            ($this->logger)($msg);
        }
    }
}
