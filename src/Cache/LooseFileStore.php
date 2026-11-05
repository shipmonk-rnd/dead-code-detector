<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use DirectoryIterator;
use LogicException;
use RuntimeException;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function substr;
use function unlink;

/**
 * One content-addressed file per record, written by parallel workers. Needs no locking and
 * dedupes for free; gc() later merges these into the bundle.
 */
final class LooseFileStore
{

    public function __construct(
        private readonly string $cacheDir,
    )
    {
    }

    public function path(string $hash): string
    {
        return $this->cacheDir . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2) . '.dat';
    }

    public function write(
        string $hash,
        string $content,
    ): void
    {
        $path = $this->path($hash);

        if (file_exists($path)) {
            return;
        }

        $this->ensureDirectory($this->cacheDir . '/' . substr($hash, 0, 2));

        if (file_put_contents($path, $content) === false) {
            throw new LogicException("Failed to write DCD cache file '{$path}'.");
        }
    }

    public function read(string $hash): ?string
    {
        $path = $this->path($hash);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new LogicException("Failed to read DCD cache file '{$path}'.");
        }

        return $content;
    }

    public function remove(string $hash): void
    {
        $path = $this->path($hash);

        if (!unlink($path)) {
            throw new LogicException("Failed to delete DCD cache file '{$path}'.");
        }
    }

    /**
     * @return list<string> hashes of all files currently on disk
     */
    public function findAll(): array
    {
        $hashes = [];

        foreach ($this->subdirectories() as $subdir) {
            foreach ($this->iterate($subdir->getPathname()) as $file) {
                if ($file->isDot() || $file->isDir()) {
                    continue;
                }

                $hashes[] = $subdir->getFilename() . $file->getBasename('.dat');
            }
        }

        return $hashes;
    }

    /**
     * A subdirectory stays when it still holds a record too large for the bundle.
     */
    public function removeEmptyDirectories(): void
    {
        foreach ($this->subdirectories() as $subdir) {
            $path = $subdir->getPathname();

            if (scandir($path) !== ['.', '..']) {
                continue;
            }

            if (!rmdir($path)) {
                throw new LogicException("Failed to delete DCD cache directory '{$path}'.");
            }
        }
    }

    /**
     * Parallel workers race for the same directory, so a failed mkdir is fine as long as it exists afterwards.
     */
    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new LogicException("Failed to create DCD cache directory '{$dir}'.");
        }
    }

    /**
     * @return list<DirectoryIterator>
     */
    private function subdirectories(): array
    {
        $subdirs = [];

        foreach ($this->iterate($this->cacheDir) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) {
                continue;
            }

            $subdirs[] = clone $entry;
        }

        return $subdirs;
    }

    private function iterate(string $dir): DirectoryIterator
    {
        try {
            return new DirectoryIterator($dir);
        } catch (RuntimeException $e) {
            throw new LogicException("Failed to list DCD cache directory '{$dir}'. Is another PHPStan process sharing the same tmpDir?", 0, $e);
        }
    }

}
