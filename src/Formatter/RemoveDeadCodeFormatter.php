<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Formatter;

use LogicException;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Error\BlackMember;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Output\OutputEnhancer;
use ShipMonk\PHPStan\DeadCode\Rule\DeadCodeRule;
use ShipMonk\PHPStan\DeadCode\Transformer\FileSystem;
use ShipMonk\PHPStan\DeadCode\Transformer\RemoveDeadCodeTransformer;
use function count;

final class RemoveDeadCodeFormatter implements ErrorFormatter
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

        $deadMembersByFiles = [];

        foreach ($analysisResult->getFileSpecificErrors() as $fileSpecificError) {
            if (
                $fileSpecificError->getIdentifier() !== DeadCodeRule::IDENTIFIER_METHOD
                && $fileSpecificError->getIdentifier() !== DeadCodeRule::IDENTIFIER_CONSTANT
                && $fileSpecificError->getIdentifier() !== DeadCodeRule::IDENTIFIER_ENUM_CASE
                && $fileSpecificError->getIdentifier() !== DeadCodeRule::IDENTIFIER_PROPERTY_NEVER_READ
                && $fileSpecificError->getIdentifier() !== DeadCodeRule::IDENTIFIER_PROPERTY_NEVER_WRITTEN
            ) {
                continue;
            }

            /** @var list<array{blackMember: BlackMember, excludedUsages: list<ClassMemberUsage>}> $metadata */
            $metadata = $fileSpecificError->getMetadata();

            /** @var BlackMember $blackMember */
            foreach ($metadata as ['blackMember' => $blackMember, 'excludedUsages' => $excludedUsages]) {
                $className = $blackMember->getMember()->getClassName();
                $memberName = $blackMember->getMember()->getMemberName();
                $file = $blackMember->getFile();
                $type = $blackMember->getMember()->getMemberType();

                $deadMembersByFiles[$file][$className][$type][$memberName] = $excludedUsages;
            }
        }

        $membersCount = 0;
        $filesCount = count($deadMembersByFiles);

        foreach ($deadMembersByFiles as $file => $deadMembersByClass) {
            $transformer = new RemoveDeadCodeTransformer($deadMembersByClass);
            $oldCode = $this->fileSystem->read($file);
            $newCode = $transformer->transformCode($oldCode);
            $this->fileSystem->write($file, $newCode);

            foreach ($deadMembersByClass as $className => $deadMembersByType) {
                foreach ($deadMembersByType as $memberType => $deadMembers) {
                    foreach ($deadMembers as $memberName => $excludedUsages) {
                        $membersCount++;
                        $memberString = $this->getMemberTypeString($memberType);

                        $output->writeLineFormatted(" • Removed $memberString <fg=white>$className::$memberName</>");
                        $this->printExcludedUsages($output, $excludedUsages);
                    }
                }
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

    /**
     * @param MemberType::* $memberType
     */
    private function getMemberTypeString(int $memberType): string
    {
        if ($memberType === MemberType::METHOD) {
            return 'method';
        } elseif ($memberType === MemberType::CONSTANT) {
            return 'constant';
        } elseif ($memberType === MemberType::PROPERTY) {
            return 'property';
        } else {
            throw new LogicException("Unsupported member type: $memberType");
        }
    }

}
