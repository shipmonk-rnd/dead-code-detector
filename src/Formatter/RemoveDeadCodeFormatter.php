<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Formatter;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use ShipMonk\PHPStan\DeadCode\Rule\DeadMethodRule;
use ShipMonk\PHPStan\DeadCode\Transformer\FileSystem;
use ShipMonk\PHPStan\DeadCode\Transformer\RemoveMethodCodeTransformer;
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

        $deadMethodKeysByFiles = [];

        foreach ($analysisResult->getFileSpecificErrors() as $fileSpecificError) {
            if ($fileSpecificError->getIdentifier() !== DeadMethodRule::ERROR_IDENTIFIER) {
                continue;
            }

            /** @var array<string, array{file: string, line: string}> $metadata */
            $metadata = $fileSpecificError->getMetadata();

            foreach ($metadata as $key => $data) {
                $deadMethodKeysByFiles[$data['file']][] = $key;
            }
        }

        $count = 0;

        foreach ($deadMethodKeysByFiles as $file => $blackMethodKeys) {
            $count += count($blackMethodKeys);

            $transformer = new RemoveMethodCodeTransformer($blackMethodKeys);
            $oldCode = $this->fileSystem->read($file);
            $newCode = $transformer->transformCode($oldCode);
            $this->fileSystem->write($file, $newCode);
        }

        $output->writeLineFormatted('Removed ' . $count . ' dead methods in ' . count($deadMethodKeysByFiles) . ' files.');

        return 0;
    }

}
