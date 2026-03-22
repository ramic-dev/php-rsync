<?php

declare(strict_types=1);

namespace Ramic\Rsync\Queue;

use Ramic\Rsync\Sync\SyncEngine;
use Ramic\Rsync\Sync\SyncOptions;
use Ramic\Rsync\Transport\HttpTransport;
use Ramic\Rsync\Transport\RemoteSyncEngine;

/**
 * File-based sync queue with non-blocking enqueue and sequential execution.
 *
 * Flow:
 *   1. enqueue() writes a JSON job file and returns a job ID immediately.
 *   2. If no background worker is holding the lock, a new worker process
 *      is spawned (detached) and returns without waiting.
 *   3. The worker acquires an exclusive lock, processes all pending jobs in
 *      FIFO order, then exits.
 *   4. Concurrent enqueue() calls that arrive while a worker is running
 *      simply write their job files; the running worker picks them up in
 *      its loop before releasing the lock.
 *
 * Queue directory layout:
 *   <dir>/
 *     worker.lock          — flock target; held by the running worker
 *     job_<id>.json        — one file per job (pending / running / completed / failed)
 */
class SyncQueue
{
    private const LOCK_FILE  = 'worker.lock';
    private const JOB_GLOB   = 'job_*.json';

    public function __construct(private readonly string $dir)
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Add a sync job to the queue.
     * Non-blocking: always returns immediately, even if a sync is in progress.
     *
     * @return string Job ID that can be used to query status.
     */
    public function enqueue(string $source, string $destination, SyncOptions $options, string $authKey = ''): string
    {
        $jobId = $this->writeJob($source, $destination, $options, $authKey);
        $this->ensureWorkerRunning();
        return $jobId;
    }

    /**
     * Return the current status of a job.
     */
    public function status(string $jobId): JobStatus
    {
        $file = $this->jobPath($jobId);
        if (!file_exists($file)) {
            throw new \InvalidArgumentException("Unknown job: $jobId");
        }
        $data = json_decode(file_get_contents($file), true);
        return JobStatus::from($data['status']);
    }

    /**
     * Return the full job data (status, stats, error, timestamps…).
     */
    public function jobData(string $jobId): array
    {
        $file = $this->jobPath($jobId);
        if (!file_exists($file)) {
            throw new \InvalidArgumentException("Unknown job: $jobId");
        }
        return json_decode(file_get_contents($file), true);
    }

    /**
     * Process all pending jobs. Called by the background worker.
     * Acquires an exclusive non-blocking lock; exits silently if another
     * worker already holds it.
     */
    public function processAll(): void
    {
        $lockPath = $this->dir . '/' . self::LOCK_FILE;
        $lock     = fopen($lockPath, 'c');

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return; // Another worker is already running.
        }

        try {
            while ($job = $this->nextPendingJob()) {
                $this->runJob($job);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function ensureWorkerRunning(): void
    {
        $lockPath = $this->dir . '/' . self::LOCK_FILE;
        $lock     = fopen($lockPath, 'c');

        // Test whether a worker already holds the lock.
        $free = flock($lock, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);

        if ($free) {
            $this->spawnWorker();
        }
        // If locked, the running worker will pick up our job in its loop.
    }

    private function spawnWorker(): void
    {
        $script = realpath(dirname(__DIR__, 2) . '/bin/rsync-worker');
        if ($script === false || !file_exists($script)) {
            throw new \RuntimeException('Worker script not found. Expected: bin/rsync-worker');
        }

        $php  = escapeshellarg(PHP_BINARY);
        $scr  = escapeshellarg($script);
        $dir  = escapeshellarg($this->dir);
        $cmd  = "$php $scr $dir";

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start \"\" /B $cmd 1>NUL 2>&1", 'r'));
        } else {
            shell_exec("$cmd > /dev/null 2>&1 &");
        }
    }

    private function writeJob(string $source, string $destination, SyncOptions $options, string $authKey): string
    {
        $jobId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));

        file_put_contents(
            $this->jobPath($jobId),
            json_encode([
                'id'          => $jobId,
                'source'      => $source,
                'destination' => $destination,
                'auth_key'    => $authKey, // for HTTP transport; empty string for local jobs
                'options'     => $options->toArray(),
                'status'      => JobStatus::Pending->value,
                'created_at'  => microtime(true),
            ], JSON_PRETTY_PRINT),
        );

        return $jobId;
    }

    private function nextPendingJob(): ?array
    {
        $files = glob($this->dir . '/' . self::JOB_GLOB) ?: [];
        sort($files); // FIFO by filename (timestamp-based)

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data['status'] === JobStatus::Pending->value) {
                return ['file' => $file, 'data' => $data];
            }
        }

        return null;
    }

    private function runJob(array $job): void
    {
        $data = $job['data'];
        $file = $job['file'];

        // Mark as running.
        $data['status']     = JobStatus::Running->value;
        $data['started_at'] = microtime(true);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

        try {
            $options  = SyncOptions::fromArray($data['options']);
            $dest     = $data['destination'];
            $authKey  = $data['auth_key'] ?? '';
            $isRemote = str_starts_with($dest, 'http://') || str_starts_with($dest, 'https://');

            if ($isRemote) {
                $transport = new HttpTransport($dest, $authKey);
                $engine    = new RemoteSyncEngine($transport);
                $stats     = $engine->sync($data['source'], $options);
            } else {
                $engine = new SyncEngine();
                $stats  = $engine->sync($data['source'], $dest, $options);
            }

            $data['status']       = JobStatus::Completed->value;
            $data['completed_at'] = microtime(true);
            $data['stats']        = [
                'files_transferred' => $stats->getFilesTransferred(),
                'files_skipped'     => $stats->getFilesSkipped(),
                'files_deleted'     => $stats->getFilesDeleted(),
                'bytes_total'       => $stats->getBytesTotal(),
                'bytes_literal'     => $stats->getBytesLiteral(),
                'duration'          => $stats->getDuration(),
            ];
        } catch (\Throwable $e) {
            $data['status']    = JobStatus::Failed->value;
            $data['failed_at'] = microtime(true);
            $data['error']     = $e->getMessage();
        }

        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function jobPath(string $jobId): string
    {
        return $this->dir . '/job_' . $jobId . '.json';
    }
}
