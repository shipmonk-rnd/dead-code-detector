<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use LogicException;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase as OriginalRuleTestCase;
use function explode;
use function file_get_contents;
use function implode;
use function preg_match_all;
use function sprintf;
use function trim;

/**
 * @template TRule of Rule
 * @extends OriginalRuleTestCase<TRule>
 */
abstract class RuleTestCase extends OriginalRuleTestCase
{

    protected function analyseFile(string $file): void
    {
        $actualErrors = $this->processActualErrors($this->gatherAnalyserErrors([$file]));
        $expectedErrors = $this->parseExpectedErrors($file);

        self::assertSame(
            implode("\n", $expectedErrors) . "\n",
            implode("\n", $actualErrors) . "\n",
        );
    }

    /**
     * @param list<Error> $actualErrors
     * @return list<string>
     */
    protected function processActualErrors(array $actualErrors): array
    {
        $resultToAssert = [];

        foreach ($actualErrors as $error) {
            $resultToAssert[] = $this->formatErrorForAssert($error->getMessage(), $error->getLine());

            self::assertNotNull($error->getIdentifier(), "Missing error identifier for error: {$error->getMessage()}");
            self::assertStringStartsWith('shipmonk.', $error->getIdentifier(), $error->getMessage());
        }

        return $resultToAssert;
    }

    /**
     * @return list<string>
     */
    private function parseExpectedErrors(string $file): array
    {
        $fileData = file_get_contents($file);

        if ($fileData === false) {
            throw new LogicException('Error while reading data from ' . $file);
        }

        $fileDataLines = explode("\n", $fileData);

        $expectedErrors = [];

        foreach ($fileDataLines as $line => $row) {
            $matches = [];
            /** @var array{0: list<string>, 1: list<string>} $matches */
            $matched = preg_match_all('#// error:(.+)#', $row, $matches);

            if ($matched === false) {
                throw new LogicException('Error while matching errors');
            }

            if ($matched === 0) {
                continue;
            }

            foreach ($matches[1] as $error) {
                $expectedErrors[] = $this->formatErrorForAssert(trim($error), $line + 1);
            }
        }

        return $expectedErrors;
    }

    private function formatErrorForAssert(string $message, ?int $line): string
    {
        return sprintf('%02d: %s', $line ?? -1, $message);
    }

}
