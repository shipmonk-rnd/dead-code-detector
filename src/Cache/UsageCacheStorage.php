<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use DirectoryIterator;
use LogicException;
use RuntimeException;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use function array_map;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_dir;
use function md5;
use function mkdir;
use function substr;
use function unlink;

final class UsageCacheStorage
{

    private string $cacheDir;

    private readonly bool $offloadCollectorData;

    /**
     * @var array<string, true>
     */
    private array $readHashes = [];

    public function __construct(
        string $tmpDir,
        bool $offloadCollectorData,
    )
    {
        $this->cacheDir = $tmpDir . '/dcd';
        $this->offloadCollectorData = $offloadCollectorData;
    }

    /**
     * @param non-empty-list<CollectedUsage> $usages
     * @return non-empty-list<string>
     */
    public function write(
        array $usages,
        string $scopeFile,
    ): array
    {
        $serialized = array_map(
            static fn (CollectedUsage $usage): string => $usage->serialize($scopeFile),
            $usages,
        );

        if (!$this->offloadCollectorData) {
            return $serialized;
        }

        $content = implode("\n", $serialized);
        $hash = md5($content);

        $filePath = $this->getFilePath($hash);

        if (!file_exists($filePath)) {
            $this->ensureDirectoryExists($hash);
            file_put_contents($filePath, $content);
        }

        return [$hash];
    }

    /**
     * @return non-empty-list<CollectedUsage>
     */
    public function read(
        string $data,
        string $scopeFile,
    ): array
    {
        if (!$this->offloadCollectorData) {
            return [CollectedUsage::deserialize($data, $scopeFile)];
        }

        $this->readHashes[$data] = true;

        $filePath = $this->getFilePath($data);

        if (!file_exists($filePath)) {
            throw new LogicException(
                "DCD cache file not found for hash '{$data}' at '{$filePath}'. "
                . 'Please clear the PHPStan result cache and re-run the analysis.',
            );
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new LogicException("Could not read DCD cache file: {$filePath}");
        }

        return array_map(
            static fn (string $line): CollectedUsage => CollectedUsage::deserialize($line, $scopeFile),
            explode("\n", $content),
        );
    }

    /**
     * Delete all files in cacheDir that were not read by this cache instance
     */
    public function gc(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        try {
            $subdirs = new DirectoryIterator($this->cacheDir);
        } catch (RuntimeException $e) {
            return;
        }

        foreach ($subdirs as $subdir) {
            if ($subdir->isDot() || !$subdir->isDir()) {
                continue;
            }

            try {
                $files = new DirectoryIterator($subdir->getPathname());
            } catch (RuntimeException $e) {
                continue;
            }

            foreach ($files as $file) {
                if ($file->isDot() || $file->isDir()) {
                    continue;
                }

                $hash = $subdir->getFilename() . $file->getBasename('.dat');

                if (!isset($this->readHashes[$hash])) {
                    @unlink($file->getPathname());
                }
            }
        }
    }

    private function getFilePath(string $hash): string
    {
        $prefix = substr($hash, 0, 2);

        return $this->cacheDir . '/' . $prefix . '/' . substr($hash, 2) . '.dat';
    }

    private function ensureDirectoryExists(string $hash): void
    {
        $dir = $this->cacheDir . '/' . substr($hash, 0, 2);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

}
