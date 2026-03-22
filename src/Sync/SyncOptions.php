<?php

declare(strict_types=1);

namespace Ramic\Rsync\Sync;

use Ramic\Rsync\Filter\FilterList;

class SyncOptions
{
    public bool $recursive          = false;
    public bool $preserveLinks      = false;
    public bool $preservePermissions = false;
    public bool $preserveTimes      = false;
    public bool $delete             = false;
    public bool $dryRun             = false;
    public bool $verbose            = false;
    public bool $checksum           = false; // compare by checksum instead of mtime+size
    public ?int $blockSize          = null;  // null = auto
    public FilterList $filter;

    public function __construct()
    {
        $this->filter = new FilterList();
    }

    /**
     * Archive mode: equivalent to -rlptD in rsync.
     * Enables recursive, preserveLinks, preservePermissions, preserveTimes.
     */
    public function applyArchive(): void
    {
        $this->recursive           = true;
        $this->preserveLinks       = true;
        $this->preservePermissions = true;
        $this->preserveTimes       = true;
    }

    public function toArray(): array
    {
        $rules = [];
        foreach ($this->filter->getRules() as $rule) {
            $rules[] = ['type' => $rule->type, 'pattern' => $rule->pattern];
        }

        return [
            'recursive'           => $this->recursive,
            'preserveLinks'       => $this->preserveLinks,
            'preservePermissions' => $this->preservePermissions,
            'preserveTimes'       => $this->preserveTimes,
            'delete'              => $this->delete,
            'dryRun'              => $this->dryRun,
            'verbose'             => $this->verbose,
            'checksum'            => $this->checksum,
            'blockSize'           => $this->blockSize,
            'filter'              => $rules,
        ];
    }

    public static function fromArray(array $data): self
    {
        $opts = new self();
        $opts->recursive           = (bool) ($data['recursive']           ?? false);
        $opts->preserveLinks       = (bool) ($data['preserveLinks']       ?? false);
        $opts->preservePermissions = (bool) ($data['preservePermissions'] ?? false);
        $opts->preserveTimes       = (bool) ($data['preserveTimes']       ?? false);
        $opts->delete              = (bool) ($data['delete']              ?? false);
        $opts->dryRun              = (bool) ($data['dryRun']              ?? false);
        $opts->verbose             = (bool) ($data['verbose']             ?? false);
        $opts->checksum            = (bool) ($data['checksum']            ?? false);
        $opts->blockSize           = isset($data['blockSize']) ? (int) $data['blockSize'] : null;

        foreach ($data['filter'] ?? [] as $rule) {
            if (($rule['type'] ?? '') === \Ramic\Rsync\Filter\FilterRule::EXCLUDE) {
                $opts->filter->addExclude($rule['pattern']);
            } else {
                $opts->filter->addInclude($rule['pattern']);
            }
        }

        return $opts;
    }
}
