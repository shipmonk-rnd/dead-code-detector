<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function strpos;
use function substr;

final class BladeUsageProvider implements MemberUsageProvider
{

    private const VIEW_FACADE_METHODS = [
        'make' => true,
        'first' => true,
        'composer' => true,
        'creator' => true,
    ];

    private readonly ReflectionProvider $reflectionProvider;

    private readonly TemplateViewDataTraverser $traverser;

    private readonly bool $enabled;

    private readonly bool $deduplicateAcrossViews;

    public function __construct(
        ReflectionProvider $reflectionProvider,
        TemplateViewDataTraverser $traverser,
        ?bool $enabled,
        bool $deduplicateAcrossViews,
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->traverser = $traverser;
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('laravel/framework');
        $this->deduplicateAcrossViews = $deduplicateAcrossViews;
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

        if ($node instanceof FuncCall) {
            $usages = [...$usages, ...$this->getUsagesFromViewHelper($node, $scope)];
        }

        if ($node instanceof StaticCall) {
            $usages = [...$usages, ...$this->getUsagesFromViewFacade($node, $scope)];
        }

        if ($node instanceof MethodCall) {
            $usages = [...$usages, ...$this->getUsagesFromMethodCall($node, $scope)];
        }

        return $usages;
    }

    /**
     * Handles view('template', ['key' => $model])
     *
     * @return list<ClassMemberUsage>
     */
    private function getUsagesFromViewHelper(
        FuncCall $node,
        Scope $scope,
    ): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        if ($node->name->toString() !== 'view') {
            return [];
        }

        $args = $node->getArgs();

        if (!isset($args[1])) {
            return [];
        }

        return $this->traverseDataArg($args[1]->value, $node, $scope);
    }

    /**
     * Handles View::make/first('template', $data) and View::composer/creator('view', Class::class)
     *
     * @return list<ClassMemberUsage>
     */
    private function getUsagesFromViewFacade(
        StaticCall $node,
        Scope $scope,
    ): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $methodName = $node->name->toString();

        if (!isset(self::VIEW_FACADE_METHODS[$methodName])) {
            return [];
        }

        $callerType = $node->class instanceof Expr
            ? $scope->getType($node->class)
            : $scope->resolveTypeByName($node->class);

        foreach ($callerType->getObjectClassNames() as $className) {
            if (!$this->reflectionProvider->hasClass($className)) {
                continue;
            }

            $classReflection = $this->reflectionProvider->getClass($className);

            if (
                $classReflection->is('Illuminate\Support\Facades\View')
                || $classReflection->is('Illuminate\Contracts\View\Factory')
            ) {
                if ($methodName === 'make' || $methodName === 'first') {
                    $args = $node->getArgs();

                    if (!isset($args[1])) {
                        return [];
                    }

                    return $this->traverseDataArg($args[1]->value, $node, $scope);
                }

                // View::composer('view', Class::class) / View::creator('view', Class::class)
                return $this->getUsagesFromComposerOrCreator($node, $node->getArgs(), $scope, $methodName);
            }
        }

        return [];
    }

    /**
     * @param array<Arg> $args
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromComposerOrCreator(
        Node $node,
        array $args,
        Scope $scope,
        string $methodName,
    ): array
    {
        if (!isset($args[1])) {
            return [];
        }

        $callbackType = $scope->getType($args[1]->value);
        $usages = [];

        // View::composer → compose, View::creator → create (see Illuminate\View\Concerns\ManagesEvents::classEventMethodForPrefix)
        $defaultMethod = $methodName === 'composer' ? 'compose' : 'create';

        foreach ($callbackType->getConstantStrings() as $stringType) {
            $value = $stringType->getValue();

            // Support 'Class@method' syntax
            $atPos = strpos($value, '@');

            if ($atPos !== false) {
                $callbackClassName = substr($value, 0, $atPos);
                $calledMethod = substr($value, $atPos + 1);
            } else {
                $callbackClassName = $value;
                $calledMethod = $defaultMethod;
            }

            foreach ([$calledMethod, '__construct'] as $method) {
                $usages[] = new ClassMethodUsage(
                    UsageOrigin::createRegular($node, $scope),
                    new ClassMethodRef($callbackClassName, $method, possibleDescendant: false),
                );
            }
        }

        return $usages;
    }

    /**
     * Handles:
     * - $factory->make('template', $data) / $factory->first($views, $data)
     * - $factory->composer('view', Class::class) / $factory->creator('view', Class::class)
     * - $response->view('template', $data)
     * - $view->with('key', $value) / $view->with(['key' => $value])
     *
     * @return list<ClassMemberUsage>
     */
    private function getUsagesFromMethodCall(
        MethodCall $node,
        Scope $scope,
    ): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $methodName = $node->name->toString();
        $callerType = $scope->getType($node->var);

        foreach ($callerType->getObjectClassNames() as $className) {
            if (!$this->reflectionProvider->hasClass($className)) {
                continue;
            }

            $classReflection = $this->reflectionProvider->getClass($className);

            if ($classReflection->is('Illuminate\Contracts\View\Factory')) {
                if ($methodName === 'make' || $methodName === 'first') {
                    $args = $node->getArgs();

                    if (!isset($args[1])) {
                        return [];
                    }

                    return $this->traverseDataArg($args[1]->value, $node, $scope);
                }

                if ($methodName === 'composer' || $methodName === 'creator') {
                    return $this->getUsagesFromComposerOrCreator($node, $node->getArgs(), $scope, $methodName);
                }
            }

            if ($classReflection->is('Illuminate\Contracts\Routing\ResponseFactory')) {
                if ($methodName === 'view') {
                    $args = $node->getArgs();

                    if (!isset($args[1])) {
                        return [];
                    }

                    return $this->traverseDataArg($args[1]->value, $node, $scope);
                }
            }

            if ($classReflection->is('Illuminate\Contracts\View\View')) {
                if ($methodName === 'with') {
                    return $this->getUsagesFromWithCall($node, $scope);
                }
            }
        }

        return [];
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function getUsagesFromWithCall(
        MethodCall $node,
        Scope $scope,
    ): array
    {
        $args = $node->getArgs();

        // with('key', $value) — extract from value arg
        if (isset($args[1])) {
            return $this->traverseDataArg($args[1]->value, $node, $scope);
        }

        // with(['key' => $value]) — extract from array arg
        if (isset($args[0])) {
            return $this->traverseDataArg($args[0]->value, $node, $scope);
        }

        return [];
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function traverseDataArg(
        Expr $dataExpr,
        Node $node,
        Scope $scope,
    ): array
    {
        $referencedClassNames = $scope->getType($dataExpr)->getReferencedClasses();
        $rootContext = $this->getRootContext($node, $scope);

        return $this->traverser->getUsages($referencedClassNames, $rootContext, $this, $this->deduplicateAcrossViews);
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

}
