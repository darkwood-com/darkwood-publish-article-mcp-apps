<?php

declare(strict_types=1);

namespace Darkwood\Flow;

/**
 * Minimal file lock for single-worker tick critical section.
 */
final class Lock
{
    /** @var resource|null */
    private $handle = null;
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function acquire(): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->handle = fopen($this->path, 'c');
        if ($this->handle === false) {
            return false;
        }
        return flock($this->handle, LOCK_EX | LOCK_NB);
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
