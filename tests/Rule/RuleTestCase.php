<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use LogicException;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase as OriginalRuleTestCase;
use function array_values;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function ksort;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function sprintf;
use function trim;
use function uniqid;

/**
 * @template TRule of Rule
 * @extends OriginalRuleTestCase<TRule>
 */
abstract class RuleTestCase extends OriginalRuleTestCase
{

    protected function analyseFile(string $file, bool $autofix = false): void
    {
        $analyserErrors = $this->gatherAnalyserErrors([$file]);

        if ($autofix === true) {
            $this->autofix($file, $analyserErrors);
            self::fail("File $file was autofixed. This setup should never remain in the codebase.");
        }

        $actualErrors = $this->processActualErrors($analyserErrors);
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
            $usedLine = $error->getLine() ?? -1;
            $key = sprintf('%04d', $usedLine) . '-' . uniqid();
            $resultToAssert[$key] = $this->formatErrorForAssert($error->getMessage(), $usedLine);

            self::assertNotNull($error->getIdentifier(), "Missing error identifier for error: {$error->getMessage()}");
            self::assertStringStartsWith('shipmonk.', $error->getIdentifier(), "Unexpected error identifier for: {$error->getMessage()}");
        }

        ksort($resultToAssert);

        return array_values($resultToAssert);
    }

    /**
     * @return list<string>
     */
    private function parseExpectedErrors(string $file): array
    {
        $fileLines = $this->getFileLines($file);
        $expectedErrors = [];

        foreach ($fileLines as $line => $row) {
            /** @var array{0: list<string>, 1: list<non-empty-string>} $matches */
            $matched = preg_match_all('#// error:(.+)#', $row, $matches);

            if ($matched === false) {
                throw new LogicException('Error while matching errors');
            }

            if ($matched === 0) {
                continue;
            }

            foreach ($matches[1] as $error) {
                $actualLine = $line + 1;
                $key = sprintf('%04d', $actualLine) . '-' . uniqid();
                $expectedErrors[$key] = $this->formatErrorForAssert(trim($error), $actualLine);
            }
        }

        ksort($expectedErrors);

        return array_values($expectedErrors);
    }

    private function formatErrorForAssert(string $message, int $line): string
    {
        return sprintf('%02d: %s', $line, $message);
    }

    /**
     * @param list<Error> $analyserErrors
     */
    private function autofix(string $file, array $analyserErrors): void
    {
        $errorsByLines = [];

        foreach ($analyserErrors as $analyserError) {
            $line = $analyserError->getLine();

            if ($line === null) {
                throw new LogicException('Error without line number: ' . $analyserError->getMessage());
            }

            $errorsByLines[$line] = $analyserError;
        }

        $fileLines = $this->getFileLines($file);

        foreach ($fileLines as $line => &$row) {
            if (!isset($errorsByLines[$line + 1])) {
                continue;
            }

            $errorCommentPattern = '~ ?//.*$~';
            $errorMessage = $errorsByLines[$line + 1]->getMessage();
            $errorComment = ' // error: ' . $errorMessage;

            if (preg_match($errorCommentPattern, $row) === 1) {
                $row = preg_replace($errorCommentPattern, $errorComment, $row);
            } else {
                $row .= $errorComment;
            }
        }

        file_put_contents($file, implode("\n", $fileLines));
    }

    /**
     * @return list<string>
     */
    private function getFileLines(string $file): array
    {
        $fileData = file_get_contents($file);

        if ($fileData === false) {
            throw new LogicException('Error while reading data from ' . $file);
        }

        return explode("\n", $fileData);
    }

}
