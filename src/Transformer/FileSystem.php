<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Transformer;

use LogicException;
use function file_get_contents;
use function file_put_contents;

class FileSystem
{

    public function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new LogicException('Could not read file: ' . $path);
        }

        return $contents;
    }

    public function write(
        string $path,
        string $content
    ): void
    {
        $success = file_put_contents($path, $content);

        if ($success === false) {
            throw new LogicException('Could not write to file: ' . $path);
        }
    }

}
