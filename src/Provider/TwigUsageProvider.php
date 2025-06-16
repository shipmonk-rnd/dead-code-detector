<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\ArgumentsNormalizer;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\UnionType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_map;
use function count;
use function explode;
use function in_array;

class TwigUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    public function __construct(?bool $enabled)
    {
        $this->enabled = $enabled ?? $this->isTwigInstalled();
    }

    private function isTwigInstalled(): bool
    {
        return InstalledVersions::isInstalled('twig/twig');
    }

    public function getUsages(
        Node $node,
        Scope $scope
    ): array
    {
        if (!$this->enabled) {
            return [];
        }

        $usages = [];

        if ($node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            $usages = [
                ...$usages,
                ...$this->getMethodUsagesFromReflection($node),
            ];
        }

        if ($node instanceof New_) {
            $usages = [
                ...$usages,
                ...$this->getMethodUsageFromNew($node, $scope),
            ];
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getMethodUsageFromNew(
        New_ $node,
        Scope $scope
    ): array
    {
        if (!$node->class instanceof Name) {
            return [];
        }

        if (!in_array($node->class->toString(), [
            'Twig\TwigFilter',
            'Twig\TwigFunction',
            'Twig\TwigTest',
        ], true)) {
            return [];
        }

        $callerType = $scope->resolveTypeByName($node->class);
        $methodReflection = $scope->getMethodReflection($callerType, '__construct');

        if ($methodReflection === null) {
            return [];
        }

        $parametersAcceptor = ParametersAcceptorSelector::selectFromArgs(
            $scope,
            $node->getArgs(),
            $methodReflection->getVariants(),
            $methodReflection->getNamedArgumentsVariants(),
        );
        $arg = (ArgumentsNormalizer::reorderNewArguments($parametersAcceptor, $node) ?? $node)->getArgs()[1] ?? null;

        if ($arg === null) {
            return [];
        }

        $argType = $scope->getType($arg->value);

        $argTypes = $argType instanceof UnionType ? $argType->getTypes() : [$argType];

        $callables = [];

        foreach ($argTypes as $callableType) {
            foreach ($callableType->getConstantArrays() as $arrayType) {
                $callable = [];

                foreach ($arrayType->getValueTypes() as $valueType) {
                    $callable[] = array_map(static function ($stringType): string {
                        return $stringType->getValue();
                    }, $valueType->getConstantStrings());
                }

                if (count($callable) === 2) {
                    foreach ($callable[0] as $className) {
                        foreach ($callable[1] as $methodName) {
                            $callables[] = [$className, $methodName];
                        }
                    }
                }
            }

            foreach ($callableType->getConstantStrings() as $stringType) {
                $callable = explode('::', $stringType->getValue());

                if (count($callable) === 2) {
                    $callables[] = $callable;
                }
            }
        }

        $usages = [];

        foreach ($callables as $callable) {
            $usages[] = new ClassMethodUsage(
                UsageOrigin::createRegular($node, $scope),
                new ClassMethodRef(
                    $callable[0],
                    $callable[1],
                    false,
                ),
            );
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getMethodUsagesFromReflection(InClassNode $node): array
    {
        $classReflection = $node->getClassReflection();
        $nativeReflection = $classReflection->getNativeReflection();

        $usages = [];

        foreach ($nativeReflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $nativeReflection->getName()) {
                continue;
            }

            $usageNote = $this->shouldMarkAsUsed($method);

            if ($usageNote !== null) {
                $usages[] = $this->createUsage($classReflection->getNativeMethod($method->getName()), $usageNote);
            }
        }

        return $usages;
    }

    protected function shouldMarkAsUsed(ReflectionMethod $method): ?string
    {
        if ($this->isMethodWithAsTwigFilterAttribute($method)) {
            return 'Twig filter method via #[AsTwigFilter] attribute';
        }

        if ($this->isMethodWithAsTwigFunctionAttribute($method)) {
            return 'Twig function method via #[AsTwigFunction] attribute';
        }

        if ($this->isMethodWithAsTwigTestAttribute($method)) {
            return 'Twig test method via #[AsTwigTest] attribute';
        }

        return null;
    }

    protected function isMethodWithAsTwigFilterAttribute(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Twig\Attribute\AsTwigFilter');
    }

    protected function isMethodWithAsTwigFunctionAttribute(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Twig\Attribute\AsTwigFunction');
    }

    protected function isMethodWithAsTwigTestAttribute(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Twig\Attribute\AsTwigTest');
    }

    protected function hasAttribute(
        ReflectionMethod $method,
        string $attributeClass
    ): bool
    {
        return $method->getAttributes($attributeClass) !== [];
    }

    private function createUsage(
        ExtendedMethodReflection $methodReflection,
        string $reason
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($reason)),
            new ClassMethodRef(
                $methodReflection->getDeclaringClass()->getName(),
                $methodReflection->getName(),
                false,
            ),
        );
    }

}
