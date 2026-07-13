<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Composer;

use Composer\Autoload\ClassLoader;
use function array_filter;
use function array_keys;
use function count;
use function file_get_contents;
use function is_array;
use function is_file;
use function json_decode;
use function json_last_error;
use function reset;
use function str_starts_with;
use const JSON_ERROR_NONE;

final class ComposerIntrospector
{

    /**
     * Autodetection is possible only when exactly one non-phar autoloader is registered.
     */
    public function autodetectVendorDir(): ?string
    {
        $vendorDirs = array_filter(array_keys(ClassLoader::getRegisteredLoaders()), static function (string $vendorDir): bool {
            return !str_starts_with($vendorDir, 'phar://');
        });

        if (count($vendorDirs) !== 1) {
            return null;
        }

        return reset($vendorDirs);
    }

    public function autodetectComposerJsonPath(): ?string
    {
        $vendorDir = $this->autodetectVendorDir();

        if ($vendorDir === null) {
            return null;
        }

        $composerJsonPath = $vendorDir . '/../composer.json';

        if (!is_file($composerJsonPath)) {
            return null;
        }

        return $composerJsonPath;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseComposerJson(string $composerJsonPath): array
    {
        if (!is_file($composerJsonPath)) {
            return [];
        }

        $composerJsonRawData = file_get_contents($composerJsonPath);

        if ($composerJsonRawData === false) {
            return [];
        }

        $composerJsonData = json_decode($composerJsonRawData, associative: true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($composerJsonData)) {
            return [];
        }

        return $composerJsonData; // @phpstan-ignore return.type (composer.json keys are strings)
    }

}
