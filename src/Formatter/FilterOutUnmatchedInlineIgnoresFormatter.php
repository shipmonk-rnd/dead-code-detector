<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Formatter;

use LogicException;
use PHPStan\Analyser\Error;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\MissingServiceException;
use function strpos;
use function substr;

/**
 * This formatter solves the following issue https://github.com/phpstan/phpstan/issues/12328
 */
final class FilterOutUnmatchedInlineIgnoresFormatter implements ErrorFormatter
{

    private ErrorFormatter $originalFormatter;

    /**
     * @var list<string>
     */
    private array $identifiers;

    /**
     * @param list<string> $identifiers
     */
    public function __construct(
        Container $container,
        string $wrappedFormatter,
        array $identifiers
    )
    {
        try {
            /** @var ErrorFormatter $formatter */
            $formatter = $container->getService('errorFormatter.' . $wrappedFormatter);
            $this->originalFormatter = $formatter;
        } catch (MissingServiceException $e) {
            throw new LogicException('Invalid error formatter given: ' . $wrappedFormatter, 0, $e);
        }
        $this->identifiers = $identifiers;
    }

    public function formatErrors(
        AnalysisResult $analysisResult,
        Output $output
    ): int
    {
        if (!$this->isPartialAnalysis()) {
            return $this->originalFormatter->formatErrors($analysisResult, $output);
        }

        $modifiedAnalysisResult = $analysisResult->withFileSpecificErrors(
            $this->filterErrors($analysisResult->getFileSpecificErrors()),
        );

        return $this->originalFormatter->formatErrors($modifiedAnalysisResult, $output);
    }

    /**
     * @param list<Error> $errors
     * @return list<Error>
     */
    private function filterErrors(array $errors): array
    {
        $result = [];
        foreach ($errors as $error) {
            if (
                $error->getIdentifier() === 'ignore.unmatchedIdentifier'
                && $this->isDeadCodeIdentifier($error->getMessage())
            ) {
                continue;
            }

            $result[] = $error;
        }

        return $result;
    }

    private function isDeadCodeIdentifier(string $message): bool
    {
        foreach ($this->identifiers as $identifier) {
            if (strpos($message, $identifier) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isPartialAnalysis(): bool
    {
        /** @var array<string> $argv */
        $argv = $_SERVER['argv'] ?? [];
        foreach ($argv as $arg) {
            if (substr($arg, -4) === '.php') {
                return true;
            }
        }

        return false;
    }

}
