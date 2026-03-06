<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Formatter;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use ShipMonk\PHPStan\DeadCode\Enum\Visibility;
use ShipMonk\PHPStan\DeadCode\Rule\UselessVisibilityRule;
use ShipMonk\PHPStan\DeadCode\Transformer\ChangeVisibilityTransformer;
use ShipMonk\PHPStan\DeadCode\Transformer\FileSystem;
use function count;

final class ChangeVisibilityFormatter implements ErrorFormatter
{

    private FileSystem $fileSystem;

    public function __construct(
        FileSystem $fileSystem
    )
    {
        $this->fileSystem = $fileSystem;
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

        $changesByFile = [];

        foreach ($analysisResult->getFileSpecificErrors() as $fileSpecificError) {
            if (
                $fileSpecificError->getIdentifier() !== UselessVisibilityRule::IDENTIFIER_USELESS_METHOD_VISIBILITY
                && $fileSpecificError->getIdentifier() !== UselessVisibilityRule::IDENTIFIER_USELESS_PROPERTY_VISIBILITY
                && $fileSpecificError->getIdentifier() !== UselessVisibilityRule::IDENTIFIER_USELESS_CONSTANT_VISIBILITY
            ) {
                continue;
            }

            /** @var array{className: string, memberName: string, memberType: int, newVisibility: int} $metadata */
            $metadata = $fileSpecificError->getMetadata();

            $file = $fileSpecificError->getFilePath();
            $className = $metadata['className'];
            $memberType = $metadata['memberType'];
            $memberName = $metadata['memberName'];
            $newVisibility = $metadata['newVisibility'];

            $changesByFile[$file][$className][$memberType][$memberName] = $newVisibility;
        }

        $membersCount = 0;
        $filesCount = count($changesByFile);

        foreach ($changesByFile as $file => $changesByClass) {
            $transformer = new ChangeVisibilityTransformer($changesByClass);
            $oldCode = $this->fileSystem->read($file);
            $newCode = $transformer->transformCode($oldCode);
            $this->fileSystem->write($file, $newCode);

            foreach ($changesByClass as $className => $changesByType) {
                foreach ($changesByType as $changes) {
                    foreach ($changes as $memberName => $newVisibility) {
                        $membersCount++;
                        $visibilityLabel = $this->visibilityLabel($newVisibility);

                        $output->writeLineFormatted(" • Changed <fg=white>$className::$memberName</> to <fg=cyan>$visibilityLabel</>");
                    }
                }
            }
        }

        $memberPlural = $membersCount === 1 ? '' : 's';
        $filePlural = $filesCount === 1 ? '' : 's';

        $output->writeLineFormatted('');
        $output->writeLineFormatted("Changed visibility of $membersCount member$memberPlural in $filesCount file$filePlural.");

        return 0;
    }

    private function visibilityLabel(int $visibility): string
    {
        return match ($visibility) {
            Visibility::PUBLIC => 'public',
            Visibility::PROTECTED => 'protected',
            Visibility::PRIVATE => 'private',
            default => 'unknown',
        };
    }

}
