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
use function unlink;

final class UsageCacheStorage
{

    private string $cacheDir;

    /**
     * @var array<string, true>
     */
    private array $referencedHashes = [];

    public function __construct(string $tmpDir)
    {
        $this->cacheDir = $tmpDir . '/dcd';
    }

    /**
     * @param non-empty-list<CollectedUsage> $usages
     * @return string Hash identifier
     */
    public function write(
        array $usages,
        string $scopeFile,
    ): string
    {
        $serialized = array_map(
            static fn (CollectedUsage $usage): string => $usage->serialize($scopeFile),
            $usages,
        );

        $content = implode("\n", $serialized);
        $hash = md5($content);

        $filePath = $this->getFilePath($hash);

        if (!file_exists($filePath)) {
            $this->ensureDirectoryExists();
            file_put_contents($filePath, $content);
        }

        return $hash;
    }

    /**
     * @return non-empty-list<CollectedUsage>
     */
    public function read(
        string $hash,
        string $scopeFile,
    ): array
    {
        $this->referencedHashes[$hash] = true;

        $filePath = $this->getFilePath($hash);

        if (!file_exists($filePath)) {
            throw new LogicException(
                "DCD cache file not found for hash '{$hash}' at '{$filePath}'. "
                . 'Please clear the PHPStan result cache and re-run the analysis.',
            );
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new LogicException("Could not read DCD cache file: {$filePath}");
        }

        return array_map(
            static fn (string $data): CollectedUsage => CollectedUsage::deserialize($data, $scopeFile),
            explode("\n", $content),
        );
    }

    public function gc(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        try {
            $iterator = new DirectoryIterator($this->cacheDir);
        } catch (RuntimeException $e) {
            return;
        }

        foreach ($iterator as $file) {
            if ($file->isDot() || $file->isDir()) {
                continue;
            }

            $hash = $file->getBasename('.dat');

            if (!isset($this->referencedHashes[$hash])) {
                @unlink($file->getPathname());
            }
        }
    }

    private function getFilePath(string $hash): string
    {
        return $this->cacheDir . '/' . $hash . '.dat';
    }

    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0777, true);
        }
    }

}
