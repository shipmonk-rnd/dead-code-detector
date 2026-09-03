<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Naming\CaseInsensitiveName;

/**
 * lorisleiva/laravel-actions gives a class using the AsObject (or AsAction) trait
 * static run()/runIf()/runUnless() methods that call $this->handle(...) from within
 * the trait itself. That call site lives in vendor code and is never analysed, so
 * handle() otherwise looks unused however many places call it via ::run().
 */
final class LaravelActionsUsageProvider implements MemberUsageProvider
{

    private const ACTION_TRAIT = 'Lorisleiva\Actions\Concerns\AsObject';

    private const RUN_METHODS = ['run', 'runIf', 'runUnless'];

    private readonly bool $enabled;

    public function __construct(
        ?bool $enabled,
    )
    {
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('lorisleiva/laravel-actions');
    }

    public function getUsages(
        Node $node,
        Scope $scope,
    ): array
    {
        if (!$this->enabled) {
            return [];
        }

        if (!$node instanceof StaticCall || !$node->name instanceof Identifier) {
            return [];
        }

        if (!CaseInsensitiveName::isOneOf($node->name->name, self::RUN_METHODS)) {
            return [];
        }

        $callerType = $node->class instanceof Expr
            ? $scope->getType($node->class)
            : $scope->resolveTypeByName($node->class);

        $usages = [];

        foreach ($callerType->getObjectClassReflections() as $classReflection) {
            if (!$this->isAction($classReflection)) {
                continue;
            }

            $usages[] = new ClassMethodUsage(
                UsageOrigin::createRegular($node, $scope),
                new ClassMethodRef($classReflection->getName(), 'handle', possibleDescendant: true),
            );
        }

        return $usages;
    }

    private function isAction(ClassReflection $classReflection): bool
    {
        return $classReflection->hasTraitUse(self::ACTION_TRAIT);
    }

}
