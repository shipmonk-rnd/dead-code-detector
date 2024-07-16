<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use function is_string;
use function strpos;
use const PHP_VERSION_ID;

class PhpUnitEntrypointProvider implements EntrypointProvider
{

    /**
     * @var array<string, array<string, bool>>
     */
    private array $dataProviders = [];

    private bool $enabled;

    private PhpDocParser $phpDocParser;

    private Lexer $lexer;

    public function __construct(bool $enabled, PhpDocParser $phpDocParser, Lexer $lexer)
    {
        $this->enabled = $enabled;
        $this->lexer = $lexer;
        $this->phpDocParser = $phpDocParser;
    }

    public function isEntrypoint(ReflectionMethod $method): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $this->gatherDataProviders($method);

        return $this->isTestCaseMethod($method)
            || $this->isDataProviderMethod($method);
    }

    private function isTestCaseMethod(ReflectionMethod $method): bool
    {
        return $method->getDeclaringClass()->isSubclassOf(TestCase::class)
            && strpos($method->getName(), 'test') === 0;
    }

    private function isDataProviderMethod(ReflectionMethod $originalMethod): bool
    {
        if (!$originalMethod->getDeclaringClass()->isSubclassOf(TestCase::class)) {
            return false;
        }

        $declaringClass = $originalMethod->getDeclaringClass();
        $declaringClassName = $declaringClass->getName();

        return $this->dataProviders[$declaringClassName][$originalMethod->getName()] ?? false;
    }

    private function gatherDataProviders(ReflectionMethod $originalMethod): void
    {
        if (!$originalMethod->getDeclaringClass()->isSubclassOf(TestCase::class)) {
            return;
        }

        $declaringClass = $originalMethod->getDeclaringClass();
        $declaringClassName = $declaringClass->getName();

        if (isset($this->dataProviders[$declaringClassName])) {
            return;
        }

        foreach ($declaringClass->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $declaringClassName) {
                continue; // dont iterate parents
            }

            foreach ($this->getDataProvidersFromAnnotations($method->getDocComment()) as $dataProvider) {
                $this->dataProviders[$declaringClassName][$dataProvider] = true;
            }

            foreach ($this->getDataProvidersFromAttributes($method) as $dataProvider) {
                $this->dataProviders[$declaringClassName][$dataProvider] = true;
            }
        }
    }

    /**
     * @param false|string $rawPhpDoc
     * @return iterable<string>
     */
    private function getDataProvidersFromAnnotations($rawPhpDoc): iterable
    {
        if ($rawPhpDoc === false) {
            return;
        }

        $tokens = new TokenIterator($this->lexer->tokenize($rawPhpDoc));
        $phpDoc = $this->phpDocParser->parse($tokens);

        foreach ($phpDoc->getTagsByName('@dataProvider') as $tag) {
            yield (string) $tag->value;
        }
    }

    /**
     * @return iterable<string>
     */
    private function getDataProvidersFromAttributes(ReflectionMethod $method): iterable
    {
        if (PHP_VERSION_ID < 80_000) {
            return;
        }

        foreach ($method->getAttributes('PHPUnit\Framework\Attributes\DataProvider') as $providerAttributeReflection) {
            $methodName = $providerAttributeReflection->getArguments()[0] ?? null;

            if (is_string($methodName)) {
                yield $methodName;
            }
        }
    }

}
