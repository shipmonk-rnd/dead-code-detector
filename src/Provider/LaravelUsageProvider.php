<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function count;
use function in_array;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

final class LaravelUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    private const SERVICE_PROVIDER_METHODS = ['register', 'boot'];

    private const ROUTE_HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match'];

    private const ROUTE_CLASS_NAMES = [
        'Route',
        'Illuminate\\Support\\Facades\\Route',
    ];

    public function __construct(?bool $enabled)
    {
        $this->enabled = $enabled ?? $this->isLaravelInstalled();
    }

    /**
     * @return list<ClassMethodUsage>
     */
    public function getUsages(Node $node, Scope $scope): array
    {
        if (!$this->enabled) {
            return [];
        }

        return [
            ...$this->getRouteUsages($node, $scope),
            ...$this->getArtisanCommandUsages($node, $scope),
            ...$this->getServiceProviderUsages($node, $scope),
            ...$this->getEloquentAccessorMutatorUsages($node, $scope),
        ];
    }

    /**
     * Detects controller methods registered via Route::get/post/etc.
     *
     * Supports two syntaxes:
     * - Array:  Route::get('/path', [HomeController::class, 'index'])
     * - String: Route::get('/path', 'HomeController@index')
     *
     * @return list<ClassMethodUsage>
     */
    private function getRouteUsages(Node $node, Scope $scope): array
    {
        if (!$node instanceof StaticCall) {
            return [];
        }

        if (!$node->class instanceof Name) {
            return [];
        }

        if (!in_array((string) $node->class, self::ROUTE_CLASS_NAMES, true)) {
            return [];
        }

        if (!$node->name instanceof Identifier) {
            return [];
        }

        if (!in_array(strtolower($node->name->toString()), self::ROUTE_HTTP_METHODS, true)) {
            return [];
        }

        $actionArg = $node->args[1] ?? null;

        if (!$actionArg instanceof Arg) {
            return [];
        }

        $action = $actionArg->value;

        // String syntax: 'App\Http\Controllers\HomeController@index'
        if ($action instanceof String_) {
            $atPos = strpos($action->value, '@');

            if ($atPos === false) {
                return [];
            }

            $controllerClass = substr($action->value, 0, $atPos);
            $controllerMethod = substr($action->value, $atPos + 1);

            return [
                new ClassMethodUsage(
                    UsageOrigin::createVirtual($this, VirtualUsageData::withNote('Called via Laravel route')),
                    new ClassMethodRef($controllerClass, $controllerMethod, false),
                ),
            ];
        }

        // Array syntax: [HomeController::class, 'index']
        if (!$action instanceof Array_ || count($action->items) !== 2) {
            return [];
        }

        $classItem = $action->items[0]->value ?? null;
        $methodItem = $action->items[1]->value ?? null;

        if (!$classItem instanceof ClassConstFetch || !$classItem->class instanceof Name) {
            return [];
        }

        if (!$methodItem instanceof String_) {
            return [];
        }

        $controllerClass = $scope->resolveName($classItem->class);
        $controllerMethod = $methodItem->value;

        return [
            new ClassMethodUsage(
                UsageOrigin::createVirtual($this, VirtualUsageData::withNote('Called via Laravel route')),
                new ClassMethodRef($controllerClass, $controllerMethod, false),
            ),
        ];
    }

    /**
     * Marks handle() as used in classes extending Illuminate\Console\Command.
     *
     * @return list<ClassMethodUsage>
     */
    private function getArtisanCommandUsages(Node $node, Scope $scope): array
    {
        if (!$node instanceof ClassMethod || $node->name->toString() !== 'handle') {
            return [];
        }

        $classReflection = $scope->getClassReflection();

        if ($classReflection === null || !$classReflection->isSubclassOf('Illuminate\\Console\\Command')) {
            return [];
        }

        return [
            new ClassMethodUsage(
                UsageOrigin::createVirtual($this, VirtualUsageData::withNote('Called via artisan CLI')),
                new ClassMethodRef($classReflection->getName(), 'handle', false),
            ),
        ];
    }

    /**
     * Marks register() and boot() as used in classes extending Illuminate\Support\ServiceProvider.
     *
     * @return list<ClassMethodUsage>
     */
    private function getServiceProviderUsages(Node $node, Scope $scope): array
    {
        if (!$node instanceof ClassMethod
            || !in_array($node->name->toString(), self::SERVICE_PROVIDER_METHODS, true)
        ) {
            return [];
        }

        $classReflection = $scope->getClassReflection();

        if ($classReflection === null
            || !$classReflection->isSubclassOf('Illuminate\\Support\\ServiceProvider')
        ) {
            return [];
        }

        return [
            new ClassMethodUsage(
                UsageOrigin::createVirtual($this, VirtualUsageData::withNote('Called by Laravel service container')),
                new ClassMethodRef($classReflection->getName(), $node->name->toString(), false),
            ),
        ];
    }

    /**
     * Marks get*Attribute() and set*Attribute() methods as used in Eloquent models.
     *
     * Accessors and mutators are invoked via magic property access ($model->snake_case,
     * $model->camelCase, toArray(), etc.) which cannot be statically tracked.
     *
     * @return list<ClassMethodUsage>
     */
    private function getEloquentAccessorMutatorUsages(Node $node, Scope $scope): array
    {
        if (!$node instanceof ClassMethod) {
            return [];
        }

        $name = $node->name->toString();
        $attributeSuffix = 'Attribute';
        $suffixLength = strlen($attributeSuffix);

        $isAccessor = strpos($name, 'get') === 0 && substr($name, -$suffixLength) === $attributeSuffix;
        $isMutator = strpos($name, 'set') === 0 && substr($name, -$suffixLength) === $attributeSuffix;

        if (!$isAccessor && !$isMutator) {
            return [];
        }

        $classReflection = $scope->getClassReflection();

        if ($classReflection === null
            || !$classReflection->isSubclassOf('Illuminate\\Database\\Eloquent\\Model')
        ) {
            return [];
        }

        $note = $isAccessor ? 'Eloquent accessor' : 'Eloquent mutator';

        return [
            new ClassMethodUsage(
                UsageOrigin::createVirtual($this, VirtualUsageData::withNote($note)),
                new ClassMethodRef($classReflection->getName(), $name, false),
            ),
        ];
    }

    private function isLaravelInstalled(): bool
    {
        return InstalledVersions::isInstalled('laravel/framework');
    }

}
