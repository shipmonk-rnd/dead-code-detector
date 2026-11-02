<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Formatter;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use PHPStan\Fixable\Patcher;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Output\OutputEnhancer;
use ShipMonk\PHPStan\DeadCode\Rule\DeadCodeRule;
use function count;
use function in_array;

/**
 * Applies fixes of dead code errors, same as `phpstan analyse --fix` does in its first round
 */
final class RemoveDeadCodeFormatter implements ErrorFormatter
{

    private const IDENTIFIERS = [
        DeadCodeRule::IDENTIFIER_METHOD,
        DeadCodeRule::IDENTIFIER_CONSTANT,
        DeadCodeRule::IDENTIFIER_ENUM_CASE,
        DeadCodeRule::IDENTIFIER_PROPERTY_NEVER_READ,
        DeadCodeRule::IDENTIFIER_PROPERTY_NEVER_WRITTEN,
    ];

    public function __construct(
        private readonly Patcher $patcher,
        private readonly OutputEnhancer $outputEnhancer,
    )
    {
    }

    public function formatErrors(
        AnalysisResult $analysisResult,
        Output $output,
    ): int
    {
        $internalErrors = $analysisResult->getInternalErrorObjects();

        foreach ($internalErrors as $internalError) {
            $output->writeLineFormatted('<error>' . $internalError->getMessage() . '</error>');
        }

        if (count($internalErrors) > 0) {
            $output->writeLineFormatted('');
            $output->writeLineFormatted('Fix listed internal errors first.');
            return 1;
        }

        $diffGroups = [];

        foreach ($analysisResult->getFileSpecificErrors() as $error) {
            $fixedErrorDiffs = $error->getFixedErrorDiffs(); // @phpstan-ignore method.internal (same as phpstan analyse --fix does)

            if (!in_array($error->getIdentifier(), self::IDENTIFIERS, true) || $fixedErrorDiffs === []) {
                continue;
            }

            $diffGroups[] = $fixedErrorDiffs;

            /** @var list<array{excludedUsages: list<ClassMemberUsage>}> $metadata */
            $metadata = $error->getMetadata();

            foreach ($metadata as ['excludedUsages' => $excludedUsages]) {
                foreach ($excludedUsages as $excludedUsage) {
                    $origin = $excludedUsage->getOrigin();

                    if ($origin->getFile() !== null && $origin->getLine() !== null) {
                        $output->writeLineFormatted("<fg=yellow>! Excluded usage at {$this->outputEnhancer->getOriginReference($origin)} left intact</>");
                    }
                }
            }
        }

        $result = $this->patcher->applyToFiles($diffGroups); // @phpstan-ignore phpstanApi.method (same as phpstan analyse --fix does)
        $applied = $result['appliedCount'];
        $filesCount = count($result['changedFiles']);

        $output->writeLineFormatted('');
        $output->writeLineFormatted("Removed dead code of $applied error" . ($applied === 1 ? '' : 's') . " in $filesCount file" . ($filesCount === 1 ? '' : 's') . '.');

        if ($result['skippedCount'] > 0) {
            $output->writeLineFormatted("Skipped {$result['skippedCount']} conflicting fix" . ($result['skippedCount'] === 1 ? '' : 'es') . ', run <fg=white>vendor/bin/phpstan analyse --fix</> to apply them in further rounds.');
        }

        return 0;
    }

}
