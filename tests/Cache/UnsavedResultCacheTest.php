<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use function array_map;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function implode;
use function is_dir;
use function md5_file;
use function mkdir;
use function rmdir;
use function sort;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use const PHP_BINARY;

/**
 * Runs the PHPStan binary, PHPStan decides in AnalyseApplication if it saves the result cache
 */
final class UnsavedResultCacheTest extends TestCase
{

    private const CALLER = <<<'PHP'
        <?php declare(strict_types = 1);

        namespace App;

        final class Caller
        {

            public function __construct()
            {
                (new Service())->used();
            }

        }

        PHP;

    private const SERVICE = <<<'PHP'
        <?php declare(strict_types = 1);

        namespace App;

        final class Service
        {

            public function used(): void
            {
            }

            public function other(): void
            {
            }

        }

        PHP;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/' . uniqid('dcd-test-unsaved-result-cache-', true);
        $rulesNeon = __DIR__ . '/../../rules.neon';

        self::assertTrue(mkdir($this->projectDir . '/src', recursive: true));
        $this->writeFile('src/Caller.php', self::CALLER);
        $this->writeFile('src/Service.php', self::SERVICE);
        $this->writeFile('phpstan.neon', <<<NEON
            includes:
                - {$rulesNeon}
            parameters:
                level: 0
                paths:
                    - src
                tmpDir: tmp

            NEON);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->projectDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->projectDir);
    }

    /**
     * @param list<string> $extraArguments
     */
    #[DataProvider('provideRunsThatDoNotSaveResultCache')]
    public function testNextRunWorksAfterRunThatDoesNotSaveResultCache(
        ?string $callerContent,
        array $extraArguments,
    ): void
    {
        $resultCachePath = $this->projectDir . '/tmp/resultCache.php';

        $firstRunErrors = $this->runPhpStan([]);
        self::assertCount(2, $firstRunErrors);
        self::assertStringContainsString('Unused App\\Caller::__construct', $firstRunErrors[0]);
        self::assertStringContainsString('Unused App\\Service::other', $firstRunErrors[1]);
        $resultCacheHash = md5_file($resultCachePath);

        $this->writeFile('edited/Caller.php', str_replace('->used()', '->other()', self::CALLER));

        if ($callerContent !== null) {
            $this->writeFile('src/Caller.php', $callerContent);
        }

        $this->runPhpStan($extraArguments);
        self::assertSame($resultCacheHash, md5_file($resultCachePath), 'The second run must not save the result cache');

        $this->writeFile('src/Caller.php', self::CALLER);

        self::assertSame($firstRunErrors, $this->runPhpStan([]));
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function provideRunsThatDoNotSaveResultCache(): iterable
    {
        yield 'editor mode' => [null, ['--tmp-file', 'edited/Caller.php', '--instead-of', 'src/Caller.php']];
        yield 'syntax error' => [str_replace('->used();', '->used(', self::CALLER), []];
    }

    /**
     * @param list<string> $extraArguments
     * @return list<string> internal errors and errors of files, one per line
     */
    private function runPhpStan(array $extraArguments): array
    {
        $arguments = [
            PHP_BINARY,
            __DIR__ . '/../../vendor/bin/phpstan',
            'analyse',
            '--no-progress',
            '--error-format=raw',
            ...$extraArguments,
        ];

        $command = 'cd ' . escapeshellarg($this->projectDir) . ' && ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>/dev/null';
        exec($command, $outputLines);
        sort($outputLines);

        return $outputLines;
    }

    private function writeFile(
        string $relativePath,
        string $content,
    ): void
    {
        $path = $this->projectDir . '/' . $relativePath;

        if (!is_dir(dirname($path))) {
            self::assertTrue(mkdir(dirname($path)));
        }

        self::assertNotFalse(file_put_contents($path, $content));
    }

}
