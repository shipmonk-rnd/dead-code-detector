<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;

final class BladeUsageProvider implements MemberUsageProvider
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
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('laravel/framework');
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
     * Handles View::make('template', $data) and View::first($views, $data)
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

        if ($methodName !== 'make' && $methodName !== 'first') {
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
                $args = $node->getArgs();

                if (!isset($args[1])) {
                    return [];
                }

                return $this->traverseDataArg($args[1]->value, $node, $scope);
            }
        }

        return [];
    }

    /**
     * Handles:
     * - $factory->make('template', $data) / $factory->first($views, $data)
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

        return $this->traverser->getUsages($referencedClassNames, $rootContext, $this);
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
