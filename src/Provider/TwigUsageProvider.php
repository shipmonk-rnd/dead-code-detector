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
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\UnionType;
use ReflectionException;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_map;
use function count;
use function explode;
use function in_array;

final class TwigUsageProvider implements MemberUsageProvider
{

    private readonly ReflectionProvider $reflectionProvider;

    private readonly TemplateViewDataTraverser $traverser;

    private readonly bool $enabled;

    public function __construct(
        ReflectionProvider $reflectionProvider,
        TemplateViewDataTraverser $traverser,
        ?bool $enabled,
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->traverser = $traverser;
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
        Scope $scope,
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
        Scope $scope,
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
                    $callable[] = array_map(static function (ConstantStringType $stringType): string {
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
                    possibleDescendant: false,
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
        string $attributeClass,
    ): bool
    {
        return $method->getAttributes($attributeClass) !== [];
    }

    private function createUsage(
        ExtendedMethodReflection $methodReflection,
        string $reason,
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($reason)),
            new ClassMethodRef(
                $methodReflection->getDeclaringClass()->getName(),
                $methodReflection->getName(),
                possibleDescendant: false,
            ),
        );
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function getUsagesFromTemplateReturn(
        Return_ $node,
        Scope $scope,
    ): array
    {
        if (!$this->isInControllerMethodWithTemplate($scope)) {
            return [];
        }

        if ($node->expr === null) {
            return [];
        }

        $referencedClassNames = $scope->getType($node->expr)->getReferencedClasses();
        $rootContext = $this->getRootContext($node, $scope);

        return $this->traverser->getUsages($referencedClassNames, $rootContext, $this);
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function getUsagesFromRenderCall(
        MethodCall $node,
        Scope $scope,
    ): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $methodName = $node->name->toString();
        $parametersArgIndex = $this->getParametersArgIndex($node, $scope, $methodName);

        if ($parametersArgIndex === null) {
            return [];
        }

        $args = $node->getArgs();

        if (!isset($args[$parametersArgIndex])) {
            return [];
        }

        $parametersArg = $args[$parametersArgIndex];
        $referencedClassNames = $scope->getType($parametersArg->value)->getReferencedClasses();
        $rootContext = $this->getRootContext($node, $scope);

        return $this->traverser->getUsages($referencedClassNames, $rootContext, $this);
    }

    private function getParametersArgIndex(
        MethodCall $node,
        Scope $scope,
        string $methodName,
    ): ?int
    {
        $callerType = $scope->getType($node->var);

        if (!$callerType->isObject()->yes()) {
            return null;
        }

        foreach ($callerType->getObjectClassNames() as $className) {
            if (!$this->reflectionProvider->hasClass($className)) {
                continue;
            }

            $classReflection = $this->reflectionProvider->getClass($className);

            if ($classReflection->is('Twig\Environment')) {
                if ($methodName === 'render' || $methodName === 'display') {
                    return 1;
                }
            }

            if ($classReflection->is('Twig\TemplateWrapper')) {
                $wrapperMethods = [
                    'render' => 0,
                    'display' => 0,
                    'stream' => 0,
                    'streamBlock' => 1,
                    'renderBlock' => 1,
                    'displayBlock' => 1,
                ];

                return $wrapperMethods[$methodName] ?? null;
            }

            if ($classReflection->is('Symfony\Bundle\FrameworkBundle\Controller\AbstractController')) {
                $controllerMethods = [
                    'render' => 1,
                    'renderView' => 1,
                    'renderBlock' => 2,
                    'renderBlockView' => 2,
                    'stream' => 1,
                ];

                return $controllerMethods[$methodName] ?? null;
            }
        }

        return null;
    }

    /**
     * @return non-empty-string
     */
    private function getRootContext(
        Node $node,
        Scope $scope,
    ): string
    {
        $functionName = $scope->getFunctionName();
        if (!$scope->isInClass() || $functionName === null) {
            return 'unknown';
        }
        return "{$scope->getClassReflection()->getName()}::{$functionName}({$node->getStartLine()})";
    }

    private function isInControllerMethodWithTemplate(
        Scope $scope,
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

}
