<?php

declare(strict_types=1);

namespace Ramic\Rsync\Sync;

class SyncStats
{
    private int   $filesTransferred = 0;
    private int   $filesSkipped     = 0;
    private int   $filesDeleted     = 0;
    private int   $dirsCreated      = 0;
    private int   $bytesTotal       = 0;  // total size of transferred files
    private int   $bytesLiteral     = 0;  // bytes actually sent (not matched by delta)
    private float $startTime;
    private float $endTime          = 0.0;

    /** @var string[] */
    private array $transferredFiles = [];
    /** @var string[] */
    private array $deletedFiles     = [];

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function finish(): void
    {
        $this->endTime = microtime(true);
    }

    public function recordTransfer(string $path, int $total, int $literal): void
    {
        $this->filesTransferred++;
        $this->bytesTotal   += $total;
        $this->bytesLiteral += $literal;
        $this->transferredFiles[] = $path;
    }

    public function recordSkip(): void  { $this->filesSkipped++; }

    public function recordDelete(string $path): void
    {
        $this->filesDeleted++;
        $this->deletedFiles[] = $path;
    }

    public function recordDirCreated(): void { $this->dirsCreated++; }

    // Getters

    public function getFilesTransferred(): int  { return $this->filesTransferred; }
    public function getFilesSkipped(): int      { return $this->filesSkipped; }
    public function getFilesDeleted(): int      { return $this->filesDeleted; }
    public function getDirsCreated(): int       { return $this->dirsCreated; }
    public function getBytesTotal(): int        { return $this->bytesTotal; }
    public function getBytesLiteral(): int      { return $this->bytesLiteral; }

    /** @return string[] */
    public function getTransferredFiles(): array { return $this->transferredFiles; }
    /** @return string[] */
    public function getDeletedFiles(): array     { return $this->deletedFiles; }

    public function getDuration(): float
    {
        return ($this->endTime > 0 ? $this->endTime : microtime(true)) - $this->startTime;
    }

    /**
     * Ratio of bytes saved by the delta algorithm (0.0 = none saved, 1.0 = all saved).
     */
    public function getDeltaEfficiency(): float
    {
        if ($this->bytesTotal === 0) {
            return 0.0;
        }
        return 1.0 - ($this->bytesLiteral / $this->bytesTotal);
    }
}
