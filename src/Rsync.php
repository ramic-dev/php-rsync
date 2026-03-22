<?php

declare(strict_types=1);

namespace Ramic\Rsync;

use Ramic\Rsync\Queue\SyncQueue;
use Ramic\Rsync\Sync\SyncEngine;
use Ramic\Rsync\Sync\SyncOptions;
use Ramic\Rsync\Sync\SyncStats;
use Ramic\Rsync\Transport\HttpTransport;
use Ramic\Rsync\Transport\RemoteSyncEngine;

/**
 * Fluent entry point that mirrors the rsync CLI interface.
 *
 *   $stats = Rsync::create()
 *       ->from('/var/www/html/')
 *       ->to('/backup/html/')
 *       ->archive()
 *       ->delete()
 *       ->exclude('.git')
 *       ->exclude('*.log')
 *       ->sync();
 */
class Rsync
{
    private string $source      = '';
    private string $destination = '';
    private string $authKey     = '';
    private SyncOptions $options;

    public function __construct()
    {
        $this->options = new SyncOptions();
    }

    public static function create(): self
    {
        return new self();
    }

    public function from(string $path): self
    {
        $this->source = $path;
        return $this;
    }

    public function to(string $path): self
    {
        $this->destination = $path;
        return $this;
    }

    /**
     * Archive mode: recursive + preserve links, permissions, timestamps.
     * Equivalent to rsync -a.
     */
    public function archive(): self
    {
        $this->options->applyArchive();
        return $this;
    }

    public function recursive(): self
    {
        $this->options->recursive = true;
        return $this;
    }

    public function delete(): self
    {
        $this->options->delete = true;
        return $this;
    }

    public function dryRun(): self
    {
        $this->options->dryRun = true;
        return $this;
    }

    public function verbose(callable $logger = null): self
    {
        $this->options->verbose = true;
        if ($logger !== null) {
            $this->getEngine()->setLogger($logger);
        }
        return $this;
    }

    public function checksum(): self
    {
        $this->options->checksum = true;
        return $this;
    }

    public function preserveTimes(): self
    {
        $this->options->preserveTimes = true;
        return $this;
    }

    public function preservePermissions(): self
    {
        $this->options->preservePermissions = true;
        return $this;
    }

    public function preserveLinks(): self
    {
        $this->options->preserveLinks = true;
        return $this;
    }

    public function exclude(string $pattern): self
    {
        $this->options->filter->addExclude($pattern);
        return $this;
    }

    public function include(string $pattern): self
    {
        $this->options->filter->addInclude($pattern);
        return $this;
    }

    /**
     * Override the automatic block size used by the delta algorithm (in bytes).
     */
    /**
     * Secret key for authenticating with the remote syncgate/receiver.php.
     * Required when the destination is an HTTP(S) URL.
     */
    public function key(string $key): self
    {
        $this->authKey = $key;
        return $this;
    }

    public function blockSize(int $bytes): self
    {
        if ($bytes <= 0) {
            throw new \InvalidArgumentException("Block size must be a positive integer, got $bytes.");
        }
        $this->options->blockSize = $bytes;
        return $this;
    }

    /**
     * Push the local receiver.php to the remote server, replacing it atomically.
     * Useful after a library update to keep the remote endpoint in sync.
     *
     * @param string|null $localPath Path to the local receiver.php.
     *                               Defaults to remote/.ramic_tools/syncgate/receiver.php
     *                               relative to the library root.
     */
    public function deployReceiver(?string $localPath = null): void
    {
        if (!str_starts_with($this->destination, 'http://') && !str_starts_with($this->destination, 'https://')) {
            throw new \LogicException('deployReceiver() only works with HTTP/HTTPS destinations.');
        }
        if ($this->authKey === '') {
            throw new \LogicException('Call key() before deployReceiver().');
        }

        $path = $localPath ?? (dirname(__DIR__) . '/remote/.ramic_tools/syncgate/receiver.php');

        if (!file_exists($path)) {
            throw new \RuntimeException("Local receiver not found at: $path");
        }

        $transport = new HttpTransport($this->destination, $this->authKey);
        $transport->updateReceiver($path);
    }

    /**
     * Add this sync job to the queue and return a job ID immediately.
     * The sync runs asynchronously in a background worker.
     *
     * @return string Job ID — pass to $queue->status() or $queue->jobData()
     */
    public function enqueue(SyncQueue $queue): string
    {
        if ($this->source === '') {
            throw new \LogicException('Source path not set. Call from() first.');
        }
        if ($this->destination === '') {
            throw new \LogicException('Destination path not set. Call to() first.');
        }

        return $queue->enqueue($this->source, $this->destination, $this->options, $this->authKey);
    }

    public function sync(): SyncStats
    {
        if ($this->source === '') {
            throw new \LogicException('Source path not set. Call from() first.');
        }
        if ($this->destination === '') {
            throw new \LogicException('Destination path not set. Call to() first.');
        }

        // Remote sync: destination is an HTTP(S) URL pointing to receiver.php
        if (str_starts_with($this->destination, 'http://') || str_starts_with($this->destination, 'https://')) {
            if ($this->authKey === '') {
                throw new \LogicException('A secret key is required for remote sync. Call key() first.');
            }
            $transport = new HttpTransport($this->destination, $this->authKey);
            $engine    = new RemoteSyncEngine($transport);
            if ($this->options->verbose && $this->engine instanceof RemoteSyncEngine) {
                // logger already set via verbose()
            }
            return $engine->sync($this->source, $this->options);
        }

        return $this->getEngine()->sync($this->source, $this->destination, $this->options);
    }

    // -------------------------------------------------------------------------

    private ?SyncEngine $engine = null;

    private function getEngine(): SyncEngine
    {
        return $this->engine ??= new SyncEngine();
    }
}
