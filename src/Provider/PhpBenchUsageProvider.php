<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpBench\Attributes\AfterMethods;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\ParamProviders;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Node\InClassNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_merge;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function trim;

final class PhpBenchUsageProvider implements MemberUsageProvider
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
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('phpbench/phpbench');
        $this->phpDocParser = $phpDocParser;
        $this->lexer = $lexer;
    }

    public function getUsages(
        Node $node,
        Scope $scope,
    ): array
    {
        if (!$this->enabled || !$node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            return [];
        }

        $classReflection = $node->getClassReflection();
        $className = $classReflection->getName();

        if (!str_ends_with($className, 'Bench')) {
            return [];
        }

        $usages = [];

        foreach ($classReflection->getNativeReflection()->getMethods() as $method) {
            $methodName = $method->getName();

            $paramProviderMethods = array_merge(
                $this->getMethodNamesFromAnnotation($method->getDocComment(), '@ParamProviders'),
                $this->getParamProvidersFromAttributes($method),
            );

            foreach ($paramProviderMethods as $paramProvider) {
                $usages[] = $this->createUsage(
                    $className,
                    $paramProvider,
                    sprintf('Param provider method, used by %s', $methodName),
                );
            }

            $beforeAfterMethodsFromAttributes = array_merge(
                $this->getMethodNamesFromAttribute($method, BeforeMethods::class),
                $this->getMethodNamesFromAttribute($method, AfterMethods::class),
            );

            foreach ($beforeAfterMethodsFromAttributes as $beforeAfterMethod) {
                $usages[] = $this->createUsage(
                    $className,
                    $beforeAfterMethod,
                    sprintf('Before/After method, used by %s', $methodName),
                );
            }

            if ($this->isBenchmarkMethod($method)) {
                $usages[] = $this->createUsage($className, $methodName, 'Benchmark method');
            }

            if ($this->isBeforeOrAfterMethod($method)) {
                $usages[] = $this->createUsage($className, $methodName, 'Before/After method');
            }
        }

        return $usages;
    }

    private function isBenchmarkMethod(ReflectionMethod $method): bool
    {
        return str_starts_with($method->getName(), 'bench');
    }

    /**
     * @return list<string>
     */
    private function getMethodNamesFromAttribute(
        ReflectionMethod $method,
        string $attributeClass,
    ): array
    {
        $result = [];

        foreach ($method->getAttributes($attributeClass) as $attribute) {
            $methods = $attribute->getArguments()[0] ?? $attribute->getArguments()['methods'] ?? [];
            if (!is_array($methods)) {
                $methods = [$methods];
            }

            foreach ($methods as $methodName) {
                if (is_string($methodName)) {
                    $result[] = $methodName;
                }
            }
        }

        return $result;
    }

    private function isBeforeOrAfterMethod(ReflectionMethod $method): bool
    {
        $classReflection = $method->getDeclaringClass();
        $methodName = $method->getName();

        // Check class-level annotations
        $docComment = $classReflection->getDocComment();
        if ($docComment !== false) {
            $beforeMethodsFromAnnotations = $this->getMethodNamesFromAnnotation($docComment, '@BeforeMethods');
            $afterMethodsFromAnnotations = $this->getMethodNamesFromAnnotation($docComment, '@AfterMethods');

            if (in_array($methodName, $beforeMethodsFromAnnotations, true) || in_array($methodName, $afterMethodsFromAnnotations, true)) {
                return true;
            }
        }

        // Check class-level attributes
        foreach ($classReflection->getAttributes(BeforeMethods::class) as $attribute) {
            $methods = $attribute->getArguments()[0] ?? $attribute->getArguments()['methods'] ?? [];
            if (!is_array($methods)) {
                $methods = [$methods];
            }

            foreach ($methods as $beforeMethod) {
                if ($beforeMethod === $methodName) {
                    return true;
                }
            }
        }

        foreach ($classReflection->getAttributes(AfterMethods::class) as $attribute) {
            $methods = $attribute->getArguments()[0] ?? $attribute->getArguments()['methods'] ?? [];
            if (!is_array($methods)) {
                $methods = [$methods];
            }

            foreach ($methods as $afterMethod) {
                if ($afterMethod === $methodName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param false|string $rawPhpDoc
     * @return list<string>
     */
    private function getMethodNamesFromAnnotation(
        $rawPhpDoc,
        string $annotationName,
    ): array
    {
        if ($rawPhpDoc === false || !str_contains($rawPhpDoc, $annotationName)) {
            return [];
        }

        $tokens = new TokenIterator($this->lexer->tokenize($rawPhpDoc));
        $phpDoc = $this->phpDocParser->parse($tokens);

        $result = [];

        foreach ($phpDoc->getTagsByName($annotationName) as $tag) {
            $value = (string) $tag->value;

            // Extract content from parentheses: @BeforeMethods("setUp") -> "setUp"
            // or @BeforeMethods({"setUp", "tearDown"}) -> {"setUp", "tearDown"}
            if (preg_match('~\((.+)\)\s*$~', $value, $matches) === 1) {
                $value = $matches[1];
            }

            $value = trim($value);
            $value = trim($value, '"\'');

            // If it's a single method name, add it directly
            if (!str_contains($value, ',') && !str_contains($value, '{')) {
                $result[] = $value;
                continue;
            }

            // Handle array format: {"method1", "method2"}
            $value = trim($value, '{}');
            $methods = explode(',', $value);
            foreach ($methods as $method) {
                $method = trim($method);
                $method = trim($method, '"\'');
                if ($method !== '') {
                    $result[] = $method;
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function getParamProvidersFromAttributes(ReflectionMethod $method): array
    {
        $result = [];

        foreach ($method->getAttributes(ParamProviders::class) as $providerAttributeReflection) {
            $providers = $providerAttributeReflection->getArguments()[0]
                ?? $providerAttributeReflection->getArguments()['providers']
                ?? null;

            if (!is_array($providers)) {
                continue;
            }

            foreach ($providers as $provider) {
                if (!is_string($provider)) {
                    continue;
                }

                $result[] = $provider;
            }
        }

        return $result;
    }

    private function createUsage(
        string $className,
        string $methodName,
        string $reason,
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($reason)),
            new ClassMethodRef(
                $className,
                $methodName,
                possibleDescendant: false,
            ),
        );
    }

}
