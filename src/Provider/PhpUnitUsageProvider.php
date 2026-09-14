<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Node\InClassNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPUnit\Framework\TestCase;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function count;
use function explode;
use function in_array;
use function is_string;
use function ltrim;
use function str_contains;
use function str_starts_with;

final class PhpUnitUsageProvider implements ActivatableUsageProvider
{

    private readonly bool $enabled;

    private readonly PhpDocParser $phpDocParser;

    private readonly Lexer $lexer;

    public function __construct(
        ?bool $enabled,
        PhpDocParser $phpDocParser,
        Lexer $lexer,
    )
    {
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('phpunit/phpunit');
        $this->phpDocParser = $phpDocParser;
        $this->lexer = $lexer;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getUsages(
        Node $node,
        Scope $scope,
    ): array
    {
        if (!$node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            return [];
        }

        $classReflection = $node->getClassReflection();

        if (!$classReflection->is(TestCase::class)) {
            return [];
        }

        $usages = [];
        $className = $classReflection->getName();

        foreach ($classReflection->getNativeReflection()->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue; // inherited test methods are emitted for their declaring class
            }

            $methodName = $method->getName();

            $annotationDataProviders = $this->getDataProvidersFromAnnotations($method->getDocComment());
            [$localDataProviderMethods, $externalDataProviderMethods] = $this->getDataProvidersFromAttributes($method);

            foreach ($externalDataProviderMethods as [$externalClassName, $externalMethodName]) {
                $usages[] = $this->createUsage($externalClassName, $externalMethodName, "External data provider method, used by $className::$methodName", possibleDescendant: false);
            }

            foreach ($annotationDataProviders as $dataProvider) {
                $parts = explode('::', $dataProvider, 2);

                if (count($parts) === 2) {
                    $providerClassName = ltrim($parts[0], '\\');
                    $usages[] = $this->createUsage($providerClassName, $parts[1], "External data provider method (annotation), used by $className::$methodName", possibleDescendant: false);
                } else {
                    $usages[] = $this->createUsage($className, $dataProvider, "Data provider method, used by $methodName", possibleDescendant: true);
                }
            }

            foreach ($localDataProviderMethods as $dataProvider) {
                $usages[] = $this->createUsage($className, $dataProvider, "Data provider method, used by $methodName", possibleDescendant: true);
            }

            if ($this->isTestCaseMethod($methodName, $method)) {
                $usages[] = $this->createUsage($className, $methodName, 'Test method', possibleDescendant: false);
            }
        }

        return $usages;
    }

    private function isTestCaseMethod(
        string $methodName,
        ReflectionMethod $method,
    ): bool
    {
        return str_starts_with($methodName, 'test')
            || $this->hasAnyAnnotation($method, [
                '@test',
                '@after',
                '@afterClass',
                '@before',
                '@beforeClass',
                '@postCondition',
                '@preCondition',
            ])
            || $this->hasAnyAttribute($method, [
                'PHPUnit\Framework\Attributes\Test',
                'PHPUnit\Framework\Attributes\After',
                'PHPUnit\Framework\Attributes\AfterClass',
                'PHPUnit\Framework\Attributes\Before',
                'PHPUnit\Framework\Attributes\BeforeClass',
                'PHPUnit\Framework\Attributes\PostCondition',
                'PHPUnit\Framework\Attributes\PreCondition',
            ]);
    }

    /**
     * @param false|string $rawPhpDoc
     * @return list<string>
     */
    private function getDataProvidersFromAnnotations($rawPhpDoc): array
    {
        if ($rawPhpDoc === false || !str_contains($rawPhpDoc, '@dataProvider')) {
            return [];
        }

        $tokens = new TokenIterator($this->lexer->tokenize($rawPhpDoc));
        $phpDoc = $this->phpDocParser->parse($tokens);

        $result = [];

        foreach ($phpDoc->getTagsByName('@dataProvider') as $tag) {
            $result[] = (string) $tag->value;
        }

        return $result;
    }

    /**
     * @return array{list<string>, list<array{string, string}>}
     */
    private function getDataProvidersFromAttributes(ReflectionMethod $method): array
    {
        $externalDataProviderMethods = [];
        $localDataProviderMethods = [];

        foreach ($method->getAttributes() as $providerAttributeReflection) {
            if ($providerAttributeReflection->getName() === 'PHPUnit\Framework\Attributes\DataProviderExternal') {
                $className = $providerAttributeReflection->getArguments()[0] ?? $providerAttributeReflection->getArguments()['className'] ?? null;
                $methodName = $providerAttributeReflection->getArguments()[1] ?? $providerAttributeReflection->getArguments()['methodName'] ?? null;

                if (is_string($className) && is_string($methodName)) {
                    $externalDataProviderMethods[] = [$className, $methodName];
                }

                continue;
            }

            if ($providerAttributeReflection->getName() === 'PHPUnit\Framework\Attributes\DataProvider') {
                $methodName = $providerAttributeReflection->getArguments()[0] ?? $providerAttributeReflection->getArguments()['methodName'] ?? null;

                if (is_string($methodName)) {
                    $localDataProviderMethods[] = $methodName;
                }
            }
        }

        return [$localDataProviderMethods, $externalDataProviderMethods];
    }

    /**
     * @param array<string> $attributeClasses
     */
    private function hasAnyAttribute(
        ReflectionMethod $method,
        array $attributeClasses,
    ): bool
    {
        foreach ($method->getAttributes() as $attribute) {
            if (in_array($attribute->getName(), $attributeClasses, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $strings
     */
    private function hasAnyAnnotation(
        ReflectionMethod $method,
        array $strings,
    ): bool
    {
        $docComment = $method->getDocComment();
        if ($docComment === false) {
            return false;
        }

        foreach ($strings as $string) {
            if (str_contains($docComment, $string)) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHPUnit resolves data providers on the concrete test class, so a provider referenced
     * from an inherited test method may be implemented in a descendant.
     */
    private function createUsage(
        string $className,
        string $methodName,
        string $reason,
        bool $possibleDescendant,
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($reason)),
            new ClassMethodRef(
                $className,
                $methodName,
                $possibleDescendant,
            ),
        );
    }

}
