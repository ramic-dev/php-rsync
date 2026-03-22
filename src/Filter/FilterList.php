<?php

declare(strict_types=1);

namespace Ramic\Rsync\Filter;

/**
 * Ordered list of include/exclude rules.
 * Rules are evaluated in order; the first match wins.
 * If no rule matches, the file is included.
 */
class FilterList
{
    /** @var FilterRule[] */
    private array $rules = [];

    public function addExclude(string $pattern): void
    {
        $this->rules[] = new FilterRule(FilterRule::EXCLUDE, $pattern);
    }

    public function addInclude(string $pattern): void
    {
        $this->rules[] = new FilterRule(FilterRule::INCLUDE, $pattern);
    }

    public function isExcluded(string $relativePath, bool $isDir = false): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($relativePath, $isDir)) {
                return $rule->isExclude();
            }
        }
        return false;
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /** @return FilterRule[] */
    public function getRules(): array
    {
        return $this->rules;
    }
}
