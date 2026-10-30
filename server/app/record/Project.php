<?php

declare(strict_types=1);

namespace app\record;

/** A project or worktree row. Worktrees point at their original project through parent_id. */
final readonly class Project
{
    public function __construct(
        public string $id,
        public string $name,
        public string $path,
        public bool $archived,
        public ?string $parent_id,
        public ProjectVisibility $visibility,
    ) {
    }
}
