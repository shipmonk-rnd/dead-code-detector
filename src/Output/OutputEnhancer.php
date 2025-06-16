<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Output;

use LogicException;
use PHPStan\File\RelativePathHelper;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function sprintf;
use function str_replace;

class OutputEnhancer
{

    private RelativePathHelper $relativePathHelper;

    private ?string $editorUrl;

    public function __construct(
        RelativePathHelper $relativePathHelper,
        ?string $editorUrl
    )
    {
        $this->relativePathHelper = $relativePathHelper;
        $this->editorUrl = $editorUrl;
    }

    public function getOriginLink(
        UsageOrigin $origin,
        string $title
    ): string
    {
        $file = $origin->getFile();
        $line = $origin->getLine();

        if ($line !== null) {
            $title = sprintf('%s:%s', $title, $line);
        }

        if ($file !== null && $line !== null) {
            return $this->getLinkOrPlain($title, $file, $line);
        }

        return $title;
    }

    public function getOriginReference(
        UsageOrigin $origin,
        bool $preferFileLine = true
    ): string
    {
        $file = $origin->getFile();
        $line = $origin->getLine();

        if ($file !== null && $line !== null) {
            $relativeFile = $this->relativePathHelper->getRelativePath($file);

            $title = $origin->getClassName() !== null && $origin->getMethodName() !== null && !$preferFileLine
                ? sprintf('%s::%s:%d', $origin->getClassName(), $origin->getMethodName(), $line)
                : sprintf('%s:%s', $relativeFile, $line);

            return $this->getLinkOrPlain($title, $file, $line);
        }

        if ($origin->getProvider() !== null) {
            $note = $origin->getNote() !== null ? " ({$origin->getNote()})" : '';
            return 'virtual usage from ' . $origin->getProvider() . $note;
        }

        throw new LogicException('Unknown state of usage origin');
    }

    private function getLinkOrPlain(
        string $title,
        string $file,
        int $line
    ): string
    {
        if ($this->editorUrl === null) {
            return $title;
        }

        $relativeFile = $this->relativePathHelper->getRelativePath($file);

        return sprintf(
            '<href=%s>%s</>',
            str_replace(['%file%', '%relFile%', '%line%'], [$file, $relativeFile, (string) $line], $this->editorUrl),
            $title,
        );
    }

}
