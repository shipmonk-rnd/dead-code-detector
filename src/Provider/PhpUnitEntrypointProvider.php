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
        if (!$method->getDeclaringClass()->isSubclassOf(TestCase::class)) {
            return false;
        }

        return strpos($method->getName(), 'test') === 0
            || $this->hasAnnotation($method, '@test')
            || $this->hasAnnotation($method, '@after')
            || $this->hasAnnotation($method, '@afterClass')
            || $this->hasAnnotation($method, '@before')
            || $this->hasAnnotation($method, '@beforeClass')
            || $this->hasAnnotation($method, '@postCondition')
            || $this->hasAnnotation($method, '@preCondition')
            || $this->hasAttribute($method, 'PHPUnit\Framework\Attributes\Test')
            || $this->hasAttribute($method, 'PHPUnit\Framework\Attributes\After')
            || $this->hasAttribute($method, 'PHPUnit\Framework\Attributes\AfterClass')
            || $this->hasAttribute($method, 'PHPUnit\Framework\Attributes\Before')
            || $this->hasAttribute($method, 'PHPUnit\Framework\Attributes\BeforeClass')
            || $this->hasAttribute($method, 'PHPUnit\Framework\Attributes\PostCondition')
            || $this->hasAttribute($method, 'PHPUnit\Framework\Attributes\PreCondition');
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
        if (PHP_VERSION_ID < 8_00_00) {
            return;
        }

        foreach ($method->getAttributes('PHPUnit\Framework\Attributes\DataProvider') as $providerAttributeReflection) {
            $methodName = $providerAttributeReflection->getArguments()[0] ?? $providerAttributeReflection->getArguments()['methodName'] ?? null;

            if (is_string($methodName)) {
                yield $methodName;
            }
        }
    }

    private function hasAttribute(ReflectionMethod $method, string $attributeClass): bool
    {
        return PHP_VERSION_ID >= 8_00_00 && $method->getAttributes($attributeClass) !== [];
    }

    private function hasAnnotation(ReflectionMethod $method, string $string): bool
    {
        if ($method->getDocComment() === false) {
            return false;
        }

        return strpos($method->getDocComment(), $string) !== false;
    }

}
