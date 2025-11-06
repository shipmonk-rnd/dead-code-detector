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
use function array_map;
use function array_merge;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;
use function strpos;
use function substr;
use function trim;

final class PhpBenchUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    private PhpDocParser $phpDocParser;

    private Lexer $lexer;

    public function __construct(
        ?bool $enabled,
        PhpDocParser $phpDocParser,
        Lexer $lexer
    )
    {
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('phpbench/phpbench');
        $this->phpDocParser = $phpDocParser;
        $this->lexer = $lexer;
    }

    public function getUsages(
        Node $node,
        Scope $scope
    ): array
    {
        if (!$this->enabled || !$node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            return [];
        }

        $classReflection = $node->getClassReflection();
        $className = $classReflection->getName();

        if (substr($className, -5) !== 'Bench') {
            return [];
        }

        $usages = [];

        foreach ($classReflection->getNativeReflection()->getMethods() as $method) {
            $methodName = $method->getName();

            $paramProviderMethods = array_merge(
                $this->getParamProvidersFromAnnotations($method->getDocComment()),
                $this->getParamProvidersFromAttributes($method),
            );

            foreach ($paramProviderMethods as $paramProvider) {
                $usages[] = $this->createUsage(
                    $className,
                    $paramProvider,
                    sprintf('Param provider method, used by %s', $methodName),
                );
            }

            if ($this->isBenchmarkMethod($method)) {
                $usages[] = $this->createUsage($className, $methodName, 'Benchmark method');
            }

            if (!$this->isBeforeOrAfterMethod($method, $className)) {
                continue;
            }

            $usages[] = $this->createUsage($className, $methodName, 'Before/After method');
        }

        return $usages;
    }

    private function isBenchmarkMethod(ReflectionMethod $method): bool
    {
        return strpos($method->getName(), 'bench') === 0;
    }

    private function isBeforeOrAfterMethod(
        ReflectionMethod $method,
        string $className
    ): bool
    {
        $classReflection = $method->getDeclaringClass();
        $methodName = $method->getName();

        // Check annotations
        $docComment = $classReflection->getDocComment();
        if ($docComment !== false) {
            $beforeMethodsFromAnnotations = $this->getBeforeMethodsFromAnnotations($docComment);
            $afterMethodsFromAnnotations = $this->getAfterMethodsFromAnnotations($docComment);

            if (in_array($methodName, $beforeMethodsFromAnnotations, true) || in_array($methodName, $afterMethodsFromAnnotations, true)) {
                return true;
            }
        }

        // Check attributes
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
    private function getParamProvidersFromAnnotations($rawPhpDoc): array
    {
        if ($rawPhpDoc === false || strpos($rawPhpDoc, '@ParamProviders') === false) {
            return [];
        }

        $tokens = new TokenIterator($this->lexer->tokenize($rawPhpDoc));
        $phpDoc = $this->phpDocParser->parse($tokens);

        $result = [];

        foreach ($phpDoc->getTagsByName('@ParamProviders') as $tag) {
            $value = (string) $tag->value;
            // Parse the value which could be like "provideData" or {"provideData", "provideMore"}
            // For simplicity, we'll extract method names from the string
            // This is a basic implementation - PhpBench uses simple format like @ParamProviders({"provideData"})
            $value = trim($value, '{}()');
            $methods = array_map('trim', explode(',', $value));
            foreach ($methods as $method) {
                $method = trim($method, '\'" ');
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

    /**
     * @param false|string $rawPhpDoc
     * @return list<string>
     */
    private function getBeforeMethodsFromAnnotations($rawPhpDoc): array
    {
        if ($rawPhpDoc === false || strpos($rawPhpDoc, '@BeforeMethods') === false) {
            return [];
        }

        $tokens = new TokenIterator($this->lexer->tokenize($rawPhpDoc));
        $phpDoc = $this->phpDocParser->parse($tokens);

        $result = [];

        foreach ($phpDoc->getTagsByName('@BeforeMethods') as $tag) {
            $value = (string) $tag->value;
            $value = trim($value, '{}()');
            $methods = array_map('trim', explode(',', $value));
            foreach ($methods as $method) {
                $method = trim($method, '\'" ');
                if ($method !== '') {
                    $result[] = $method;
                }
            }
        }

        return $result;
    }

    /**
     * @param false|string $rawPhpDoc
     * @return list<string>
     */
    private function getAfterMethodsFromAnnotations($rawPhpDoc): array
    {
        if ($rawPhpDoc === false || strpos($rawPhpDoc, '@AfterMethods') === false) {
            return [];
        }

        $tokens = new TokenIterator($this->lexer->tokenize($rawPhpDoc));
        $phpDoc = $this->phpDocParser->parse($tokens);

        $result = [];

        foreach ($phpDoc->getTagsByName('@AfterMethods') as $tag) {
            $value = (string) $tag->value;
            $value = trim($value, '{}()');
            $methods = array_map('trim', explode(',', $value));
            foreach ($methods as $method) {
                $method = trim($method, '\'" ');
                if ($method !== '') {
                    $result[] = $method;
                }
            }
        }

        return $result;
    }

    private function createUsage(
        string $className,
        string $methodName,
        string $reason
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($reason)),
            new ClassMethodRef(
                $className,
                $methodName,
                false,
            ),
        );
    }

}
