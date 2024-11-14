<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Formatter;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use ShipMonk\PHPStan\DeadCode\Rule\DeadCodeRule;
use ShipMonk\PHPStan\DeadCode\Transformer\FileSystem;
use ShipMonk\PHPStan\DeadCode\Transformer\RemoveDeadCodeTransformer;
use function count;

class RemoveDeadCodeFormatter implements ErrorFormatter
{

    private FileSystem $fileSystem;

    public function __construct(FileSystem $fileSystem)
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

        $deadMembersByFiles = [];

        foreach ($analysisResult->getFileSpecificErrors() as $fileSpecificError) {
            if (
                $fileSpecificError->getIdentifier() !== DeadCodeRule::IDENTIFIER_METHOD
                && $fileSpecificError->getIdentifier() !== DeadCodeRule::IDENTIFIER_CONSTANT
            ) {
                continue;
            }

            /** @var array<string, array{file: string}> $metadata */
            $metadata = $fileSpecificError->getMetadata();
            $type = $fileSpecificError->getIdentifier();

            foreach ($metadata as $key => $data) {
                $deadMembersByFiles[$data['file']][$type][] = $key;
            }
        }

        $count = 0;

        foreach ($deadMembersByFiles as $file => $deadMembersByType) {
            $deadConstants = $deadMembersByType[DeadCodeRule::IDENTIFIER_CONSTANT] ?? [];
            $deadMethods = $deadMembersByType[DeadCodeRule::IDENTIFIER_METHOD] ?? [];

            $count += count($deadConstants) + count($deadMethods);

            $transformer = new RemoveDeadCodeTransformer($deadMethods, $deadConstants);
            $oldCode = $this->fileSystem->read($file);
            $newCode = $transformer->transformCode($oldCode);
            $this->fileSystem->write($file, $newCode);
        }

        $output->writeLineFormatted('Removed ' . $count . ' dead methods in ' . count($deadMembersByFiles) . ' files.');

        return 0;
    }

}
