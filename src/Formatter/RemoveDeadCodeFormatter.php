<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Formatter;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use PHPStan\Fixable\ErrorFixer;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Error\BlackMember;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Output\OutputEnhancer;
use ShipMonk\PHPStan\DeadCode\Rule\DeadCodeRule;
use function count;
use function in_array;

/**
 * Fixes dead code errors like one round of `phpstan analyse --fix` and lists the removed members
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
        private readonly ErrorFixer $errorFixer,
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

        $deadCodeErrors = [];

        foreach ($analysisResult->getFileSpecificErrors() as $error) {
            if (in_array($error->getIdentifier(), self::IDENTIFIERS, true)) {
                $deadCodeErrors[] = $error;
            }
        }

        $result = $this->errorFixer->fix($deadCodeErrors);
        $removedMembers = [];

        foreach ($result->getFixedErrors() as $error) {
            /** @var list<array{blackMember: BlackMember, excludedUsages: list<ClassMemberUsage>}> $metadata */
            $metadata = $error->getMetadata();

            foreach ($metadata as ['blackMember' => $blackMember, 'excludedUsages' => $excludedUsages]) {
                $member = $blackMember->getMember();
                $memberKey = "{$member->getMemberType()->value}:{$member->getClassName()}::{$member->getMemberName()}";

                // a property never read and never written is removed by two errors
                if (isset($removedMembers[$memberKey])) {
                    continue;
                }

                $removedMembers[$memberKey] = true;
                $memberString = $this->getMemberTypeString($member->getMemberType());

                $output->writeLineFormatted(" • Removed $memberString <fg=white>{$member->getClassName()}::{$member->getMemberName()}</>");
                $this->printExcludedUsages($output, $excludedUsages);
            }
        }

        $membersCount = count($removedMembers);
        $filesCount = count($result->getChangedFiles());
        $memberPlural = $membersCount === 1 ? '' : 's';
        $filePlural = $filesCount === 1 ? '' : 's';

        $output->writeLineFormatted('');
        $output->writeLineFormatted("Removed $membersCount dead member$memberPlural in $filesCount file$filePlural.");

        $skippedCount = count($result->getSkippedErrors());

        if ($skippedCount > 0) {
            $output->writeLineFormatted("Skipped $skippedCount conflicting fix" . ($skippedCount === 1 ? '' : 'es') . ', run <fg=white>vendor/bin/phpstan analyse --fix</> to apply them in further rounds.');
        }

        return 0;
    }

    /**
     * @param list<ClassMemberUsage> $excludedUsages
     */
    private function printExcludedUsages(
        Output $output,
        array $excludedUsages,
    ): void
    {
        foreach ($excludedUsages as $excludedUsage) {
            $origin = $excludedUsage->getOrigin();

            if ($origin->getFile() === null || $origin->getLine() === null) {
                continue;
            }

            $output->writeLineFormatted("<fg=yellow>   ! Excluded usage at {$this->outputEnhancer->getOriginReference($origin)} left intact</>");
        }
    }

    private function getMemberTypeString(MemberType $memberType): string
    {
        return match ($memberType) {
            MemberType::METHOD => 'method',
            MemberType::CONSTANT => 'constant',
            MemberType::PROPERTY => 'property',
        };
    }

}
