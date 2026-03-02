<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode;

use PHPStan\Testing\PHPStanTestCase;
use function array_merge;
use function array_values;
use function file_get_contents;
use function is_array;
use function str_contains;

final class ParamsArePassedToServicesTest extends PHPStanTestCase
{

    /**
     * @return string[]
     */
    public static function getAdditionalConfigFiles(): array
    {
        return array_merge(
            parent::getAdditionalConfigFiles(),
            [__DIR__ . '/../rules.neon'],
        );
    }

    public function test(): void
    {
        $parameters = self::getContainer()->getParameters();
        self::assertArrayHasKey('shipmonkDeadCode', $parameters);
        self::assertIsArray($parameters['shipmonkDeadCode']);

        $paths = $this->getArrayPaths($parameters['shipmonkDeadCode'], 'shipmonkDeadCode');

        $neonContents = file_get_contents(__DIR__ . '/../rules.neon');
        self::assertNotFalse($neonContents);

        foreach ($paths as $path) {
            self::assertTrue(
                str_contains($neonContents, "%$path%"),
                'Usage of parameter %' . $path . '% not found in rules.neon. It should probably be passed to some service.',
            );
        }
    }

    /**
     * @param array<mixed> $input
     * @return list<string>
     */
    private function getArrayPaths(
        array $input,
        string $currentPath = '',
    ): array
    {
        $resultPaths = [];

        foreach ($input as $key => $value) {
            $newPathSegment = $currentPath === '' ? (string) $key : $currentPath . '.' . $key;

            if (is_array($value)) {
                if ($this->isList($value)) {
                    // Non-empty lists are passed as a whole, add path without recursing
                    // Empty lists are ignored (no useful value to pass)
                    if ($value !== []) {
                        $resultPaths[] = $newPathSegment;
                    }
                } else {
                    $resultPaths = array_merge($resultPaths, $this->getArrayPaths($value, $newPathSegment));
                }
            } else {
                $resultPaths[] = $newPathSegment;
            }
        }

        return $resultPaths;
    }

    /**
     * @param array<mixed> $array
     */
    private function isList(array $array): bool
    {
        return array_values($array) === $array;
    }

}
