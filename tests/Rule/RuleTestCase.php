<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use LogicException;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase as OriginalRuleTestCase;
use function array_diff;
use function array_values;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function ksort;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function sort;
use function sprintf;
use function trim;
use function uniqid;

/**
 * @template TRule of Rule
 * @extends OriginalRuleTestCase<TRule>
 */
abstract class RuleTestCase extends OriginalRuleTestCase
{

    /**
     * @param list<string> $files
     */
    protected function analyseFiles(array $files, bool $autofix = false): void
    {
        sort($files);

        $analyserErrors = $this->gatherAnalyserErrors($files);

        if ($autofix === true) {
            foreach ($files as $file) {
                $this->autofix($file, $analyserErrors);
            }

            self::fail('Autofixed. This setup should never remain in the codebase.');
        }

        if ($analyserErrors === []) {
            $this->expectNotToPerformAssertions();
        }

        $actualErrorsByFile = $this->processActualErrors($analyserErrors);

        foreach ($actualErrorsByFile as $file => $actualErrors) {
            $expectedErrors = $this->parseExpectedErrors($file);

            $extraErrors = array_diff($expectedErrors, $actualErrors);
            $missingErrors = array_diff($actualErrors, $expectedErrors);

            $extraErrorsString = $extraErrors === [] ? '' : "\n - Extra errors: " . implode("\n", $extraErrors);
            $missingErrorsString = $missingErrors === [] ? '' : "\n - Missing errors: " . implode("\n", $missingErrors);

            self::assertSame(
                implode("\n", $expectedErrors) . "\n",
                implode("\n", $actualErrors) . "\n",
                sprintf(
                    "Errors in file $file do not match. %s\n",
                    $extraErrorsString . $missingErrorsString,
                ),
            );
        }
    }

    /**
     * @param list<Error> $actualErrors
     * @return array<string, list<string>>
     */
    protected function processActualErrors(array $actualErrors): array
    {
        $resultToAssert = [];

        foreach ($actualErrors as $error) {
            $usedLine = $error->getLine() ?? -1;
            $key = sprintf('%04d', $usedLine) . '-' . uniqid();
            $resultToAssert[$error->getFile()][$key] = $this->formatErrorForAssert($error->getMessage(), $usedLine);

            self::assertNotNull($error->getIdentifier(), "Missing error identifier for error: {$error->getMessage()}");
            self::assertStringStartsWith('shipmonk.', $error->getIdentifier(), "Unexpected error identifier for: {$error->getMessage()}");
        }

        $finalResult = [];

        foreach ($resultToAssert as $file => $fileErrors) {
            ksort($fileErrors);
            $finalResult[$file] = array_values($fileErrors);
        }

        ksort($finalResult);

        return $finalResult;
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

            if ($analyserError->getFile() !== $file) {
                continue;
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
