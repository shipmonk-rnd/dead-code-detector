<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Helper\DeadCodeHelper;
use function array_merge;
use function array_values;
use function strpos;

/**
 * @implements Rule<CollectedDataNode>
 */
class DeadMethodRule implements Rule
{

    private ReflectionProvider $reflectionProvider;

    /**
     * parentMethodKey => childrenMethodKey[] that can mark parent as used
     *
     * @var array<string, list<string>>
     */
    private array $detectedDescendants = [];

    /**
     * traitMethodKey => traitUserMethodKey[]
     *
     * @var array<string, list<string>>
     */
    private array $detectedTraitUsages = [];

    /**
     * @var array<string, RuleError>
     */
    private array $errors = [];

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->reflectionProvider = $reflectionProvider;
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @param CollectedDataNode $node
     * @return list<RuleError>
     */
    public function processNode( // @phpstan-ignore method.childReturnType (Do not yet raise prod dependency of phpstan to 1.11, but use it in CI)
        Node $node,
        Scope $scope
    ): array
    {
        if ($node->isOnlyFilesAnalysis()) {
            return [];
        }

        $methodDeclarationData = $node->get(MethodDefinitionCollector::class);
        $methodCallData = $node->get(MethodCallCollector::class);

        $declaredMethods = [];

        foreach ($methodDeclarationData as $file => $methodsInFile) {
            foreach ($methodsInFile as $declared) {
                foreach ($declared as [$declaredMethodKey, $line]) {
                    if ($this->isAnonymousClass($declaredMethodKey)) {
                        continue;
                    }

                    $declaredMethods[$declaredMethodKey] = [$file, $line];

                    $this->fillDescendants($declaredMethodKey);
                    $this->fillTraitsUser($declaredMethodKey);
                }
            }
        }

        unset($methodDeclarationData);

        foreach ($methodCallData as $file => $callsInFile) {
            foreach ($callsInFile as $calls) {
                foreach ($calls as $calledMethodKey) {
                    if ($this->isAnonymousClass($calledMethodKey)) {
                        continue;
                    }

                    foreach ($this->getMethodsToMarkAsUsed($calledMethodKey) as $methodKeyToMarkAsUsed) {
                        unset($declaredMethods[$methodKeyToMarkAsUsed]);
                    }
                }
            }
        }

        unset($methodCallData);

        foreach ($declaredMethods as $methodKey => [$file, $line]) {
            $this->raiseError($methodKey, $file, $line);
        }

        return array_values($this->errors);
    }

    private function isAnonymousClass(string $methodKey): bool
    {
        // https://github.com/phpstan/phpstan/issues/8410 workaround, ideally this should not be ignored
        return strpos($methodKey, 'AnonymousClass') === 0;
    }

    /**
     * @return list<string>
     */
    private function getMethodsToMarkAsUsed(string $methodKey): array
    {
        $classAndMethod = DeadCodeHelper::splitMethodKey($methodKey);
        $reflection = $this->reflectionProvider->getClass($classAndMethod->className);
        $traitMethodKey = DeadCodeHelper::getDeclaringTraitMethodKey($reflection, $classAndMethod->methodName);

        $result = array_merge(
            $this->getDescendantsToMarkAsUsed($methodKey),
            $this->getTraitUsersToMarkAsUsed($traitMethodKey),
        );

        foreach ($reflection->getAncestors() as $ancestor) {
            if (!$ancestor->hasMethod($classAndMethod->methodName)) {
                continue;
            }

            $ancestorMethodKey = DeadCodeHelper::composeMethodKey($ancestor->getName(), $classAndMethod->methodName);
            $result[] = $ancestorMethodKey;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function getTraitUsersToMarkAsUsed(?string $traitMethodKey): array
    {
        if ($traitMethodKey === null) {
            return [];
        }

        $result = [];

        if (isset($this->detectedTraitUsages[$traitMethodKey])) {
            foreach ($this->detectedTraitUsages[$traitMethodKey] as $traitUserMethodKey) {
                $result[] = $traitUserMethodKey;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function getDescendantsToMarkAsUsed(string $methodKey): array
    {
        $result = [];

        if (isset($this->detectedDescendants[$methodKey])) {
            foreach ($this->detectedDescendants[$methodKey] as $descendantMethodKey) {
                $result[] = $descendantMethodKey;
            }
        }

        return $result;
    }

    private function fillTraitsUser(string $methodKey): void
    {
        $classAndMethod = DeadCodeHelper::splitMethodKey($methodKey);
        $reflection = $this->reflectionProvider->getClass($classAndMethod->className);
        $declaringTraitMethodKey = DeadCodeHelper::getDeclaringTraitMethodKey($reflection, $classAndMethod->methodName);

        if ($declaringTraitMethodKey === null) {
            return;
        }

        $this->detectedTraitUsages[$declaringTraitMethodKey][] = $methodKey;
    }

    private function fillDescendants(string $methodKey): void
    {
        $classAndMethod = DeadCodeHelper::splitMethodKey($methodKey);
        $reflection = $this->reflectionProvider->getClass($classAndMethod->className);

        foreach ($reflection->getAncestors() as $ancestor) {
            if (!$ancestor->hasMethod($classAndMethod->methodName)) {
                continue;
            }

            if ($ancestor->isTrait()) {
                continue;
            }

            $ancestorMethodKey = DeadCodeHelper::composeMethodKey($ancestor->getName(), $classAndMethod->methodName);
            $this->detectedDescendants[$ancestorMethodKey][] = $methodKey; // mark ancestor as markable by its child
        }
    }

    private function raiseError(
        string $methodKey,
        string $file,
        int $line
    ): void
    {
        $classAndMethod = DeadCodeHelper::splitMethodKey($methodKey);
        $reflection = $this->reflectionProvider->getClass($classAndMethod->className);
        $declaringTraitReflection = DeadCodeHelper::getDeclaringTraitReflection($reflection, $classAndMethod->methodName);

        if ($declaringTraitReflection !== null) {
            $traitMethodKey = DeadCodeHelper::composeMethodKey($declaringTraitReflection->getName(), $classAndMethod->methodName);

            $this->errors[$traitMethodKey] = RuleErrorBuilder::message("Unused {$traitMethodKey}")
                ->file($declaringTraitReflection->getFileName()) // @phpstan-ignore-line
                ->line($declaringTraitReflection->getMethod($classAndMethod->methodName)->getStartLine()) // @phpstan-ignore-line
                ->identifier('shipmonk.deadMethod')
                ->build();

        } else {
            $this->errors[$methodKey] = RuleErrorBuilder::message("Unused $methodKey")
                ->file($file)
                ->line($line)
                ->identifier('shipmonk.deadMethod')
                ->build();
        }
    }

}
