<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use Throwable;
use function error_reporting;
use function is_array;
use function ob_end_clean;
use function ob_start;
use const E_ALL;
use const E_DEPRECATED;

trait NoFatalErrorTestTrait
{

    /**
     * Ensure we test real PHP code
     * - mainly targets invalid class/trait/iface compositions
     *
     * @param list<array{0: string|list<string>, 1?: mixed}> $fileProviders
     */
    private function doTestNoFatalError(array $fileProviders): void
    {
        // when lowest versions are installed, we get "Implicitly marking parameter xxx as nullable is deprecated" for symfony deps
        $previousErrorReporting = error_reporting(E_ALL & ~E_DEPRECATED);

        try {
            $required = [];

            foreach ($fileProviders as $args) {
                $files = is_array($args[0]) ? $args[0] : [$args[0]];

                foreach ($files as $file) {
                    if (isset($required[$file])) {
                        continue;
                    }

                    try {
                        ob_start();
                        require $file;
                        ob_end_clean();
                    } catch (Throwable $e) {
                        self::fail("Fatal error in {$e->getFile()}:{$e->getLine()}:\n {$e->getMessage()}");
                    }

                    $required[$file] = true;
                }
            }

            $this->expectNotToPerformAssertions();
        } finally {
            error_reporting($previousErrorReporting);
        }
    }

}
