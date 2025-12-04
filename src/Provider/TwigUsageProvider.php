<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\ArgumentsNormalizer;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use ReflectionException;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_map;
use function count;
use function explode;
use function in_array;
use function strpos;

final class TwigUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    /**
     * @var list<string>
     */
    private array $analysedPaths;

    private ReflectionProvider $reflectionProvider;

    /**
     * @param list<string> $analysedPaths
     */
    public function __construct(
        ReflectionProvider $reflectionProvider,
        array $analysedPaths,
        ?bool $enabled
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->analysedPaths = $analysedPaths;
        $this->enabled = $enabled ?? $this->isTwigInstalled();
    }

    private function isTwigInstalled(): bool
    {
        return InstalledVersions::isInstalled('twig/twig')
            || InstalledVersions::isInstalled('symfony/framework-bundle')
            || InstalledVersions::isInstalled('symfony/twig-bridge');
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

        if ($node instanceof Return_) {
            $usages = [
                ...$usages,
                ...$this->getUsagesFromTemplateReturn($node, $scope),
            ];
        }

        if ($node instanceof MethodCall) {
            $usages = [
                ...$usages,
                ...$this->getUsagesFromRenderCall($node, $scope),
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

    private function shouldMarkAsUsed(ReflectionMethod $method): ?string
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

    private function isMethodWithAsTwigFilterAttribute(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Twig\Attribute\AsTwigFilter');
    }

    private function isMethodWithAsTwigFunctionAttribute(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Twig\Attribute\AsTwigFunction');
    }

    private function isMethodWithAsTwigTestAttribute(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Twig\Attribute\AsTwigTest');
    }

    private function hasAttribute(
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

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromTemplateReturn(
        Return_ $node,
        Scope $scope
    ): array
    {
        if (!$this->isInControllerMethodWithTemplate($scope)) {
            return [];
        }

        if ($node->expr === null) {
            return [];
        }

        $returnType = $scope->getType($node->expr);
        $referencedClassNames = $this->extractObjectTypes($returnType);

        $usages = [];
        $visited = [];
        $rootContext = $this->getRootContext($node, $scope);

        foreach ($referencedClassNames as $className) {
            $usages = [
                ...$usages,
                ...$this->traverseClassNameRecursively($className, $visited, $rootContext),
            ];
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromRenderCall(
        MethodCall $node,
        Scope $scope
    ): array
    {
        if (!$scope->isInClass()) {
            return [];
        }

        if (!$scope->getClassReflection()->is('Symfony\Bundle\FrameworkBundle\Controller\AbstractController')) {
            return [];
        }

        if (!$node->name instanceof Identifier) {
            return [];
        }

        $methodName = $node->name->toString();

        // Check if it's one of the Twig rendering methods
        $twigRenderMethods = [
            'render' => 1,
            'renderView' => 1,
            'renderBlock' => 2,
            'renderBlockView' => 2,
            'stream' => 1,
        ];

        $parametersArgIndex = $twigRenderMethods[$methodName] ?? null;
        if ($parametersArgIndex === null) {
            return [];
        }

        $args = $node->getArgs();

        if (!isset($args[$parametersArgIndex])) {
            return [];
        }

        $parametersArg = $args[$parametersArgIndex];
        $parametersType = $scope->getType($parametersArg->value);

        $objectTypes = $this->extractObjectTypes($parametersType);

        $usages = [];
        $visited = [];
        $rootContext = $this->getRootContext($node, $scope);

        foreach ($objectTypes as $className) {
            $usages = [
                ...$usages,
                ...$this->traverseClassNameRecursively($className, $visited, $rootContext),
            ];
        }

        return $usages;
    }

    /**
     * @return non-empty-string
     */
    private function getRootContext(
        Node $node,
        Scope $scope
    ): string
    {
        $functionName = $scope->getFunctionName();
        if (!$scope->isInClass() || $functionName === null) {
            return 'unknown';
        }
        return "{$scope->getClassReflection()->getName()}::{$functionName}({$node->getStartLine()})";
    }

    private function isInControllerMethodWithTemplate(
        Scope $scope
    ): bool
    {
        if (!$scope->isInClass()) {
            return false;
        }
        $function = $scope->getFunction();
        if ($function === null) {
            return false;
        }
        if ($function->isMethodOrPropertyHook() && $function->isPropertyHook()) {
            return false;
        }
        $methodName = $function->getName();
        try {
            $attributes = $scope->getClassReflection()->getNativeReflection()->getMethod($methodName)->getAttributes();
        } catch (ReflectionException $e) {
            throw new LogicException("Method $methodName must exist as it was returned from Scope. Should never happen.", 0, $e);
        }

        foreach ($attributes as $attribute) {
            if (
                $attribute->getName() === 'Symfony\Bridge\Twig\Attribute\Template' // Symfony 6.2+ (TwigBridge)
                || $attribute->getName() === 'Sensio\Bundle\FrameworkExtraBundle\Configuration\Template' // SensioFrameworkExtraBundle (legacy)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractObjectTypes(Type $returnType): array
    {
        return $returnType->getReferencedClasses();
    }

    /**
     * @param non-empty-string $context
     * @param array<string, true> $visited
     * @return list<ClassMethodUsage>
     */
    private function traverseClassNameRecursively(
        string $className,
        array &$visited,
        string $context
    ): array
    {
        if (isset($visited[$className])) {
            return []; // Cycle detection
        }

        $visited[$className] = true;

        if (!$this->reflectionProvider->hasClass($className)) {
            return [];
        }

        $classReflection = $this->reflectionProvider->getClass($className);

        if ($this->shouldSkipClass($classReflection)) {
            return [];
        }

        return $this->getPublicMembersUsages($classReflection, $visited, $context);
    }

    /**
     * @param array<string, true> $visited
     * @param non-empty-string $context
     * @return list<ClassMethodUsage>
     */
    private function getPublicMembersUsages(
        ClassReflection $classReflection,
        array &$visited,
        string $context
    ): array
    {
        $usages = [];
        $className = $classReflection->getName();
        $nativeReflection = $classReflection->getNativeReflection();
        $shortClassName = $nativeReflection->getShortName();

        // Process public methods
        foreach ($nativeReflection->getMethods() as $method) {
            if (!$method->isPublic() || $method->isStatic()) {
                continue;
            }

            // Skip magic methods
            if ($this->shouldSkipMethod($method->getName())) {
                continue;
            }

            // Mark method as used
            $usages[] = $this->createMethodUsage($className, $method->getName(), $context);

            // Traverse method return type
            $extendedMethodReflection = $classReflection->getNativeMethod($method->getName());
            $variants = $extendedMethodReflection->getVariants();
            $newContext = "{$context} -> {$shortClassName}::{$method->getName()}";

            foreach ($variants as $variant) {
                $returnType = $variant->getReturnType();

                foreach ($returnType->getObjectClassNames() as $returnClassName) {
                    $usages = [
                        ...$usages,
                        ...$this->traverseClassNameRecursively(
                            $returnClassName,
                            $visited,
                            $newContext,
                        ),
                    ];
                }
            }
        }

        // Process public properties
        foreach ($nativeReflection->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }

            $propertyReflection = $classReflection->getNativeProperty($property->getName());
            $newContext = "{$context} -> {$shortClassName}::\${$property->getName()}";

            foreach ($propertyReflection->getReadableType()->getObjectClassNames() as $propertyClassName) {
                $usages = [
                    ...$usages,
                    ...$this->traverseClassNameRecursively(
                        $propertyClassName,
                        $visited,
                        $newContext,
                    ),
                ];
            }
        }

        return $usages;
    }

    /**
     * @param non-empty-string $context
     */
    private function createMethodUsage(
        string $className,
        string $methodName,
        string $context
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($context)),
            new ClassMethodRef($className, $methodName, false),
        );
    }

    private function shouldSkipMethod(string $methodName): bool
    {
        return strpos($methodName, '__') === 0;
    }

    private function shouldSkipClass(ClassReflection $classReflection): bool
    {
        if ($classReflection->isInternal()) {
            return true;
        }

        $fileName = $classReflection->getFileName();
        if ($fileName === null) {
            return true;
        }

        foreach ($this->analysedPaths as $path) {
            if (strpos($fileName, $path) === 0) {
                return false; // do not traverse non-analyzed classes (e.g. vendor)
            }
        }

        return true;
    }

}
