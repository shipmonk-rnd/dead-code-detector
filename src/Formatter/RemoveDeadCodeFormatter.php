<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Formatter;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Output\OutputEnhancer;
use ShipMonk\PHPStan\DeadCode\Rule\DeadCodeRule;
use ShipMonk\PHPStan\DeadCode\Transformer\FileSystem;
use ShipMonk\PHPStan\DeadCode\Transformer\RemoveDeadCodeTransformer;
use function array_keys;
use function count;

class RemoveDeadCodeFormatter implements ErrorFormatter
{

    private FileSystem $fileSystem;

    private OutputEnhancer $outputEnhancer;

    public function __construct(
        FileSystem $fileSystem,
        OutputEnhancer $outputEnhancer
    )
    {
        $this->fileSystem = $fileSystem;
        $this->outputEnhancer = $outputEnhancer;
    }

    public function formatErrors(
        AnalysisResult $analysisResult,
        Output $output
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

        /** @var array<string, array<string, array<string, list<ClassMemberUsage>>>> $deadMembersByFiles file => [identifier => [key => excludedUsages[]]] */
        $deadMembersByFiles = [];

        foreach ($analysisResult->getFileSpecificErrors() as $fileSpecificError) {
            if (
                $fileSpecificError->getIdentifier() !== DeadCodeRule::IDENTIFIER_METHOD
                && $fileSpecificError->getIdentifier() !== DeadCodeRule::IDENTIFIER_CONSTANT
                && $fileSpecificError->getIdentifier() !== DeadCodeRule::IDENTIFIER_ENUM_CASE
            ) {
                continue;
            }

            /** @var array<string, array{file: string, type: MemberType::*, excludedUsages: list<ClassMemberUsage>}> $metadata */
            $metadata = $fileSpecificError->getMetadata();

            foreach ($metadata as $memberKey => $data) {
                $file = $data['file'];
                $type = $data['type'];
                $deadMembersByFiles[$file][$type][$memberKey] = $data['excludedUsages'];
            }
        }

        $membersCount = 0;
        $filesCount = count($deadMembersByFiles);

        foreach ($deadMembersByFiles as $file => $deadMembersByType) {
            /** @var array<string, list<ClassMemberUsage>> $deadConstants */
            $deadConstants = $deadMembersByType[MemberType::CONSTANT] ?? [];
            /** @var array<string, list<ClassMemberUsage>> $deadMethods */
            $deadMethods = $deadMembersByType[MemberType::METHOD] ?? [];

            $membersCount += count($deadConstants) + count($deadMethods);

            $transformer = new RemoveDeadCodeTransformer(array_keys($deadMethods), array_keys($deadConstants));
            $oldCode = $this->fileSystem->read($file);
            $newCode = $transformer->transformCode($oldCode);
            $this->fileSystem->write($file, $newCode);

            foreach ($deadConstants as $constant => $excludedUsages) {
                $output->writeLineFormatted(" • Removed constant <fg=white>$constant</>");
                $this->printExcludedUsages($output, $excludedUsages);
            }

            foreach ($deadMethods as $method => $excludedUsages) {
                $output->writeLineFormatted(" • Removed method <fg=white>$method</>");
                $this->printExcludedUsages($output, $excludedUsages);
            }
        }

        $memberPlural = $membersCount === 1 ? '' : 's';
        $filePlural = $filesCount === 1 ? '' : 's';

        $output->writeLineFormatted('');
        $output->writeLineFormatted("Removed $membersCount dead member$memberPlural in $filesCount file$filePlural.");

        return 0;
    }

    /**
     * @param list<ClassMemberUsage> $excludedUsages
     */
    private function printExcludedUsages(
        Output $output,
        array $excludedUsages
    ): void
    {
        foreach ($excludedUsages as $excludedUsage) {
            $originLink = $this->getOriginLink($excludedUsage->getOrigin());

            if ($originLink === null) {
                continue;
            }

            $output->writeLineFormatted("<fg=yellow>   ! Excluded usage at {$originLink} left intact</>");
        }
    }

    private function getOriginLink(UsageOrigin $origin): ?string
    {
        if ($origin->getFile() === null || $origin->getLine() === null) {
            return null;
        }

        return $this->outputEnhancer->getOriginReference($origin);
    }

}
