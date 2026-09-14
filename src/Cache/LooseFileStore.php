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

        $dir = $this->cacheDir . '/' . substr($hash, 0, 2);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        if (file_put_contents($path, $content) === false) {
            throw new LogicException("Failed to write DCD cache file: {$path}");
        }
    }

    public function read(string $hash): ?string
    {
        $content = @file_get_contents($this->path($hash));

        return $content === false ? null : $content;
    }

    public function remove(string $hash): void
    {
        @unlink($this->path($hash));
    }

    /**
     * @return list<string> hashes of all files currently on disk
     */
    public function findAll(): array
    {
        $hashes = [];

        foreach ($this->subdirectories() as $subdir) {
            try {
                $files = new DirectoryIterator($subdir->getPathname());
            } catch (RuntimeException $e) {
                continue;
            }

            foreach ($files as $file) {
                if ($file->isDot() || $file->isDir()) {
                    continue;
                }

                $hashes[] = $subdir->getFilename() . $file->getBasename('.dat');
            }
        }

        return $hashes;
    }

    public function removeEmptyDirectories(): void
    {
        foreach ($this->subdirectories() as $subdir) {
            @rmdir($subdir->getPathname());
        }
    }

    /**
     * @return list<DirectoryIterator>
     */
    private function subdirectories(): array
    {
        try {
            $entries = new DirectoryIterator($this->cacheDir);
        } catch (RuntimeException $e) {
            return [];
        }

        $subdirs = [];

        foreach ($entries as $entry) {
            if ($entry->isDot() || !$entry->isDir()) {
                continue;
            }

            $subdirs[] = clone $entry;
        }

        return $subdirs;
    }

}
