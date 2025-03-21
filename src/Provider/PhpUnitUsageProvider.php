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
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPUnit\Framework\TestCase;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_merge;
use function is_string;
use function strpos;

class PhpUnitUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    private PhpDocParser $phpDocParser;

    private Lexer $lexer;

    public function __construct(?bool $enabled, PhpDocParser $phpDocParser, Lexer $lexer)
    {
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('phpunit/phpunit');
        $this->lexer = $lexer;
        $this->phpDocParser = $phpDocParser;
    }

    public function getUsages(Node $node, Scope $scope): array
    {
        if (!$this->enabled || !$node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            return [];
        }

        $classReflection = $node->getClassReflection();

        if (!$classReflection->is(TestCase::class)) {
            return [];
        }

        $usages = [];

        foreach ($classReflection->getNativeReflection()->getMethods() as $method) {
            $dataProviders = array_merge(
                $this->getDataProvidersFromAnnotations($method->getDocComment()),
                $this->getDataProvidersFromAttributes($method),
            );

            foreach ($dataProviders as $dataProvider) {
                if ($classReflection->hasNativeMethod($dataProvider)) {
                    $usages[] = $this->createUsage($classReflection->getNativeMethod($dataProvider), 'Data provider method');
                }
            }

            if ($this->isTestCaseMethod($method)) {
                $usages[] = $this->createUsage($classReflection->getNativeMethod($method->getName()), 'Test method');
            }
        }

        return $usages;
    }

    private function isTestCaseMethod(ReflectionMethod $method): bool
    {
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

    /**
     * @param false|string $rawPhpDoc
     * @return list<string>
     */
    private function getDataProvidersFromAnnotations($rawPhpDoc): array
    {
        if ($rawPhpDoc === false || strpos($rawPhpDoc, '@dataProvider') === false) {
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
     * @return list<string>
     */
    private function getDataProvidersFromAttributes(ReflectionMethod $method): array
    {
        $result = [];

        foreach ($method->getAttributes('PHPUnit\Framework\Attributes\DataProvider') as $providerAttributeReflection) {
            $methodName = $providerAttributeReflection->getArguments()[0] ?? $providerAttributeReflection->getArguments()['methodName'] ?? null;

            if (is_string($methodName)) {
                $result[] = $methodName;
            }
        }

        return $result;
    }

    private function hasAttribute(ReflectionMethod $method, string $attributeClass): bool
    {
        return $method->getAttributes($attributeClass) !== [];
    }

    private function hasAnnotation(ReflectionMethod $method, string $string): bool
    {
        if ($method->getDocComment() === false) {
            return false;
        }

        return strpos($method->getDocComment(), $string) !== false;
    }

    private function createUsage(ExtendedMethodReflection $getNativeMethod, string $reason): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($reason)),
            new ClassMethodRef(
                $getNativeMethod->getDeclaringClass()->getName(),
                $getNativeMethod->getName(),
                false,
            ),
        );
    }

}
