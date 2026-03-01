<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionNamedType;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_map;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function lcfirst;
use function str_replace;
use function strpos;
use function strrpos;
use function substr;
use function ucwords;

final class LaravelUsageProvider implements MemberUsageProvider
{

    private const OBSERVER_EVENT_METHODS = [
        'creating', 'created', 'updating', 'updated', 'saving', 'saved',
        'deleting', 'deleted', 'restoring', 'restored', 'replicating',
        'retrieved', 'forceDeleting', 'forceDeleted', 'trashed',
    ];

    private ReflectionProvider $reflectionProvider;

    private bool $enabled;

    public function __construct(
        ReflectionProvider $reflectionProvider,
        ?bool $enabled
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('laravel/framework');
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
            $usages = [...$usages, ...$this->getMethodUsagesFromReflection($node)];
            $usages = [...$usages, ...$this->getObserverUsagesFromModelAttribute($node)];
        }

        if ($node instanceof StaticCall) {
            $usages = [...$usages, ...$this->getUsagesFromStaticCall($node, $scope)];
        }

        if ($node instanceof MethodCall) {
            $usages = [...$usages, ...$this->getUsagesFromMethodCall($node, $scope)];
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

            $note = $this->shouldMarkAsUsed($method, $classReflection);

            if ($note !== null) {
                $usages[] = $this->createUsage($classReflection->getNativeMethod($method->getName()), $note);
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromStaticCall(
        StaticCall $node,
        Scope $scope
    ): array
    {
        $callerType = $node->class instanceof Expr
            ? $scope->getType($node->class)
            : $scope->resolveTypeByName($node->class);

        $classNames = $callerType->getObjectClassNames();

        $usages = [];

        foreach ($classNames as $className) {
            if ($className === 'Illuminate\Support\Facades\Route' || $className === 'Illuminate\Routing\Router') {
                $usages = [...$usages, ...$this->getUsagesFromRouteCall($node, $scope)];
            }

            if ($className === 'Illuminate\Support\Facades\Event' || $className === 'Illuminate\Events\Dispatcher') {
                $usages = [...$usages, ...$this->getUsagesFromEventCall($node, $scope)];
            }

            if ($className === 'Illuminate\Support\Facades\Schedule' || $className === 'Illuminate\Console\Scheduling\Schedule') {
                $usages = [...$usages, ...$this->getUsagesFromScheduleCall($node, $scope)];
            }

            if ($className === 'Illuminate\Support\Facades\Gate' || $className === 'Illuminate\Auth\Access\Gate') {
                $usages = [...$usages, ...$this->getUsagesFromGateCall($node, $scope)];
            }
        }

        if (
            $node->name instanceof Identifier
            && $node->name->name === 'observe'
            && (new ObjectType('Illuminate\Database\Eloquent\Model'))->isSuperTypeOf($callerType)->yes()
        ) {
            $usages = [...$usages, ...$this->getUsagesFromObserveCall($node, $scope)];
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromRouteCall(
        StaticCall $node,
        Scope $scope
    ): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $methodName = $node->name->name;
        $usages = [];

        if (in_array($methodName, ['get', 'post', 'put', 'patch', 'delete', 'any'], true)) {
            foreach ($this->extractCallablesFromArg($node, $scope, 1) as [$className, $method]) {
                foreach ([$method, '__construct'] as $usedMethod) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $usedMethod, false),
                    );
                }
            }

            // Invokable controllers: Route::get('/path', Controller::class)
            foreach ($this->extractClassNamesFromArg($node, $scope, 1) as $className) {
                foreach (['__invoke', '__construct'] as $method) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $method, false),
                    );
                }
            }
        }

        if ($methodName === 'match') {
            foreach ($this->extractCallablesFromArg($node, $scope, 2) as [$className, $method]) {
                foreach ([$method, '__construct'] as $usedMethod) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $usedMethod, false),
                    );
                }
            }

            // Invokable controllers: Route::match(['GET'], '/path', Controller::class)
            foreach ($this->extractClassNamesFromArg($node, $scope, 2) as $className) {
                foreach (['__invoke', '__construct'] as $method) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $method, false),
                    );
                }
            }
        }

        if ($methodName === 'resource') {
            $resourceMethods = ['__construct', 'index', 'create', 'store', 'show', 'edit', 'update', 'destroy'];

            foreach ($this->extractClassNamesFromArg($node, $scope, 1) as $className) {
                foreach ($resourceMethods as $method) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $method, false),
                    );
                }
            }
        }

        if ($methodName === 'apiResource') {
            $apiResourceMethods = ['__construct', 'index', 'store', 'show', 'update', 'destroy'];

            foreach ($this->extractClassNamesFromArg($node, $scope, 1) as $className) {
                foreach ($apiResourceMethods as $method) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $method, false),
                    );
                }
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromEventCall(
        StaticCall $node,
        Scope $scope
    ): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $methodName = $node->name->name;
        $usages = [];

        if ($methodName === 'listen') {
            foreach ($this->extractClassNamesFromArg($node, $scope, 1) as $className) {
                foreach (['handle', '__construct'] as $method) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $method, false),
                    );
                }
            }
        }

        if ($methodName === 'subscribe') {
            foreach ($this->extractClassNamesFromArg($node, $scope, 0) as $className) {
                foreach (['subscribe', '__construct'] as $method) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $method, false),
                    );
                }
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromScheduleCall(
        StaticCall $node,
        Scope $scope
    ): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $methodName = $node->name->name;
        $usages = [];

        if ($methodName === 'job') {
            foreach ($this->extractClassNamesFromArg($node, $scope, 0) as $className) {
                foreach (['handle', '__construct'] as $method) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $method, false),
                    );
                }
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromGateCall(
        StaticCall $node,
        Scope $scope
    ): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $methodName = $node->name->name;
        $usages = [];

        if ($methodName === 'define') {
            foreach ($this->extractCallablesFromArg($node, $scope, 1) as [$className, $method]) {
                foreach ([$method, '__construct'] as $usedMethod) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $usedMethod, false),
                    );
                }
            }

            foreach ($this->extractClassNamesFromArg($node, $scope, 1) as $className) {
                foreach (['__invoke', '__construct'] as $method) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $method, false),
                    );
                }
            }
        }

        if ($methodName === 'policy') {
            foreach ($this->extractClassNamesFromArg($node, $scope, 1) as $policyClassName) {
                $usages = [...$usages, ...$this->markAllPublicPolicyMethods($policyClassName, UsageOrigin::createRegular($node, $scope))];
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromMethodCall(
        MethodCall $node,
        Scope $scope
    ): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        if ($node->name->name !== 'authorize') {
            return [];
        }

        $callerType = $scope->getType($node->var);

        $hasAuthorize = false;

        foreach ($callerType->getObjectClassNames() as $callerClassName) {
            if ($this->reflectionProvider->hasClass($callerClassName)) {
                $callerReflection = $this->reflectionProvider->getClass($callerClassName);

                if ($callerReflection->hasTraitUse('Illuminate\Foundation\Auth\Access\AuthorizesRequests')) {
                    $hasAuthorize = true;
                    break;
                }
            }
        }

        if (!$hasAuthorize) {
            return [];
        }

        return $this->getUsagesFromAuthorizeCall($node, $scope);
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromAuthorizeCall(
        MethodCall $node,
        Scope $scope
    ): array
    {
        $args = $node->getArgs();
        $abilityArg = $args[0] ?? null;
        $modelArg = $args[1] ?? null;

        if ($abilityArg === null) {
            return [];
        }

        $abilityType = $scope->getType($abilityArg->value);
        $abilityNames = [];

        foreach ($abilityType->getConstantStrings() as $stringType) {
            $abilityNames[] = $this->kebabToCamelCase($stringType->getValue());
        }

        if ($abilityNames === []) {
            return [];
        }

        $policyClassNames = [];

        if ($modelArg !== null) {
            $modelType = $scope->getType($modelArg->value);

            foreach ($modelType->getObjectClassNames() as $modelClassName) {
                $policyClassName = $this->resolvePolicyClassName($modelClassName);

                if ($policyClassName !== null) {
                    $policyClassNames[] = $policyClassName;
                }
            }

            foreach ($modelType->getConstantStrings() as $modelStringType) {
                $policyClassName = $this->resolvePolicyClassName($modelStringType->getValue());

                if ($policyClassName !== null) {
                    $policyClassNames[] = $policyClassName;
                }
            }
        }

        $usages = [];

        foreach ($policyClassNames as $policyClassName) {
            foreach ($abilityNames as $abilityName) {
                $usages[] = new ClassMethodUsage(
                    UsageOrigin::createRegular($node, $scope),
                    new ClassMethodRef($policyClassName, $abilityName, false),
                );
            }
        }

        return $usages;
    }

    private function resolvePolicyClassName(string $modelClassName): ?string
    {
        $lastSeparator = strrpos($modelClassName, '\\');

        if ($lastSeparator === false) {
            return null;
        }

        $namespace = substr($modelClassName, 0, $lastSeparator);
        $shortName = substr($modelClassName, $lastSeparator + 1);

        $firstSeparator = strpos($namespace, '\\');
        $rootNamespace = $firstSeparator !== false
            ? substr($namespace, 0, $firstSeparator)
            : $namespace;

        $policyClassName = $rootNamespace . '\\Policies\\' . $shortName . 'Policy';

        if ($this->reflectionProvider->hasClass($policyClassName)) {
            return $policyClassName;
        }

        return null;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function markAllPublicPolicyMethods(
        string $policyClassName,
        UsageOrigin $origin
    ): array
    {
        if (!$this->reflectionProvider->hasClass($policyClassName)) {
            return [];
        }

        $classReflection = $this->reflectionProvider->getClass($policyClassName);
        $nativeReflection = $classReflection->getNativeReflection();
        $usages = [];

        foreach ($nativeReflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $nativeReflection->getName()) {
                continue;
            }

            if ($method->isPublic()) {
                $usages[] = new ClassMethodUsage(
                    $origin,
                    new ClassMethodRef($policyClassName, $method->getName(), false),
                );
            }
        }

        return $usages;
    }

    private function kebabToCamelCase(string $string): string
    {
        if (strpos($string, '-') === false) {
            return $string;
        }

        return lcfirst(
            str_replace(
                '-',
                '',
                ucwords($string, '-'),
            ),
        );
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromObserveCall(
        StaticCall $node,
        Scope $scope
    ): array
    {
        $arg = $node->getArgs()[0] ?? null;

        if ($arg === null) {
            return [];
        }

        $argType = $scope->getType($arg->value);
        $observerClassNames = [];

        foreach ($argType->getConstantStrings() as $stringType) {
            $observerClassNames[] = $stringType->getValue();
        }

        foreach ($argType->getConstantArrays() as $arrayType) {
            foreach ($arrayType->getValueTypes() as $valueType) {
                foreach ($valueType->getConstantStrings() as $stringType) {
                    $observerClassNames[] = $stringType->getValue();
                }
            }
        }

        $usages = [];

        foreach ($observerClassNames as $observerClassName) {
            foreach ([...self::OBSERVER_EVENT_METHODS, '__construct'] as $method) {
                $usages[] = new ClassMethodUsage(
                    UsageOrigin::createRegular($node, $scope),
                    new ClassMethodRef($observerClassName, $method, false),
                );
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getObserverUsagesFromModelAttribute(InClassNode $node): array
    {
        $classReflection = $node->getClassReflection();

        if (!(new ObjectType('Illuminate\Database\Eloquent\Model'))->isSuperTypeOf(new ObjectType($classReflection->getName()))->yes()) {
            return [];
        }

        $nativeReflection = $classReflection->getNativeReflection();
        $attributes = $nativeReflection->getAttributes('Illuminate\Database\Eloquent\Attributes\ObservedBy');

        $usages = [];

        foreach ($attributes as $attribute) {
            $args = $attribute->getArguments();

            foreach ($args as $arg) {
                $classNames = is_array($arg) ? $arg : [$arg];

                foreach ($classNames as $className) {
                    if (!is_string($className)) {
                        continue;
                    }

                    foreach ([...self::OBSERVER_EVENT_METHODS, '__construct'] as $method) {
                        $usages[] = new ClassMethodUsage(
                            UsageOrigin::createVirtual($this, VirtualUsageData::withNote('Laravel observer via #[ObservedBy]')),
                            new ClassMethodRef($className, $method, false),
                        );
                    }
                }
            }
        }

        return $usages;
    }

    /**
     * Extracts [class, method] pairs from a callable array argument like [Controller::class, 'method'].
     *
     * @return list<array{string, string}>
     */
    private function extractCallablesFromArg(
        StaticCall $node,
        Scope $scope,
        int $argIndex
    ): array
    {
        $arg = $node->getArgs()[$argIndex] ?? null;

        if ($arg === null) {
            return [];
        }

        $argType = $scope->getType($arg->value);
        $callables = [];

        foreach ($argType->getConstantArrays() as $arrayType) {
            $callable = [];

            foreach ($arrayType->getValueTypes() as $valueType) {
                $callable[] = array_map(
                    static function ($stringType): string {
                        return $stringType->getValue();
                    },
                    $valueType->getConstantStrings(),
                );
            }

            if (count($callable) === 2) {
                foreach ($callable[0] as $className) {
                    foreach ($callable[1] as $methodName) {
                        $callables[] = [$className, $methodName];
                    }
                }
            }
        }

        return $callables;
    }

    /**
     * Extracts class names from a class-string argument like Controller::class.
     *
     * @return list<string>
     */
    private function extractClassNamesFromArg(
        StaticCall $node,
        Scope $scope,
        int $argIndex
    ): array
    {
        $arg = $node->getArgs()[$argIndex] ?? null;

        if ($arg === null) {
            return [];
        }

        $argType = $scope->getType($arg->value);
        $classNames = [];

        foreach ($argType->getConstantStrings() as $stringType) {
            $classNames[] = $stringType->getValue();
        }

        return $classNames;
    }

    private function shouldMarkAsUsed(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        return $this->isEloquentModelMethod($method, $classReflection)
            ?? $this->isCommandMethod($method, $classReflection)
            ?? $this->isJobMethod($method, $classReflection)
            ?? $this->isServiceProviderMethod($method, $classReflection)
            ?? $this->isMiddlewareMethod($method, $classReflection)
            ?? $this->isNotificationMethod($method, $classReflection)
            ?? $this->isFormRequestMethod($method, $classReflection)
            ?? $this->isFactoryMethod($method, $classReflection)
            ?? $this->isSeederMethod($method, $classReflection)
            ?? $this->isMigrationMethod($method, $classReflection)
            ?? $this->isPolicyMethod($method, $classReflection)
            ?? $this->isMailableMethod($method, $classReflection)
            ?? $this->isBroadcastEventMethod($method, $classReflection)
            ?? $this->isJsonResourceMethod($method, $classReflection)
            ?? $this->isValidationRuleMethod($method, $classReflection)
            ?? $this->isNotifiableMethod($method, $classReflection);
    }

    private function isEloquentModelMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Database\Eloquent\Model')) {
            return null;
        }

        $methodName = $method->getName();

        if ($method->isConstructor()) {
            return 'Laravel Eloquent model constructor';
        }

        if (in_array($methodName, ['boot', 'booted', 'casts', 'newFactory'], true)) {
            return 'Laravel Eloquent lifecycle/framework method';
        }

        if (strpos($methodName, 'scope') === 0 && $methodName !== 'scope') {
            return 'Laravel Eloquent query scope';
        }

        if ($this->methodReturnsType($method, 'Illuminate\Database\Eloquent\Relations')) {
            return 'Laravel Eloquent relationship';
        }

        if ($this->methodReturnsExactType($method, 'Illuminate\Database\Eloquent\Casts\Attribute')) {
            return 'Laravel Eloquent attribute accessor';
        }

        return null;
    }

    private function isCommandMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Console\Command')) {
            return null;
        }

        if ($method->isConstructor() || $method->getName() === 'handle') {
            return 'Laravel console command method';
        }

        return null;
    }

    private function isJobMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (
            !$classReflection->is('Illuminate\Contracts\Queue\ShouldQueue')
            && !$classReflection->hasTraitUse('Illuminate\Foundation\Bus\Dispatchable')
        ) {
            return null;
        }

        $jobMethods = [
            '__construct', 'handle', 'failed', 'middleware', 'retryUntil',
            'uniqueId', 'tags', 'backoff', 'uniqueVia', 'displayName',
        ];

        if (in_array($method->getName(), $jobMethods, true)) {
            return 'Laravel job method';
        }

        return null;
    }

    private function isServiceProviderMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Support\ServiceProvider')) {
            return null;
        }

        if ($method->isConstructor() || in_array($method->getName(), ['register', 'boot'], true)) {
            return 'Laravel service provider method';
        }

        return null;
    }

    private function isMiddlewareMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        $methodName = $method->getName();

        if ($methodName !== 'handle' && $methodName !== 'terminate' && !$method->isConstructor()) {
            return null;
        }

        if (!$classReflection->hasNativeMethod('handle')) {
            return null;
        }

        $handleMethod = $classReflection->getNativeReflection()->getMethod('handle'); // @phpstan-ignore missingType.checkedException
        $params = $handleMethod->getParameters();

        if ($params === []) {
            return null;
        }

        $firstParamType = $params[0]->getType();

        if (!$firstParamType instanceof ReflectionNamedType) {
            return null;
        }

        if ($firstParamType->getName() === 'Illuminate\Http\Request') {
            return 'Laravel middleware method';
        }

        return null;
    }

    private function isNotificationMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Notifications\Notification')) {
            return null;
        }

        $methodName = $method->getName();

        $notificationMethods = [
            'via', 'toMail', 'toArray', 'toDatabase', 'toBroadcast', 'toVonage', 'toSlack',
        ];

        if (in_array($methodName, $notificationMethods, true)) {
            return 'Laravel notification method';
        }

        return null;
    }

    private function isFormRequestMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Foundation\Http\FormRequest')) {
            return null;
        }

        $formRequestMethods = [
            'authorize', 'rules', 'messages', 'attributes',
            'prepareForValidation', 'passedValidation', 'failedValidation', 'failedAuthorization',
        ];

        if (in_array($method->getName(), $formRequestMethods, true)) {
            return 'Laravel form request method';
        }

        return null;
    }

    private function isFactoryMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Database\Eloquent\Factories\Factory')) {
            return null;
        }

        if (in_array($method->getName(), ['definition', 'configure'], true)) {
            return 'Laravel factory method';
        }

        return null;
    }

    private function isSeederMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Database\Seeder')) {
            return null;
        }

        if ($method->getName() === 'run') {
            return 'Laravel seeder method';
        }

        return null;
    }

    private function isMigrationMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Database\Migrations\Migration')) {
            return null;
        }

        if (in_array($method->getName(), ['up', 'down'], true)) {
            return 'Laravel migration method';
        }

        return null;
    }

    private function isPolicyMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        $className = $classReflection->getName();
        $lastSeparator = strrpos($className, '\\');
        $shortName = $lastSeparator !== false ? substr($className, $lastSeparator + 1) : $className;

        if (substr($shortName, -6) !== 'Policy') {
            return null;
        }

        $policyMethods = [
            'before', 'viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete',
        ];

        if (in_array($method->getName(), $policyMethods, true)) {
            return 'Laravel policy method';
        }

        return null;
    }

    private function isMailableMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Mail\Mailable')) {
            return null;
        }

        $mailableMethods = ['build', 'content', 'envelope', 'attachments', 'headers'];

        if (in_array($method->getName(), $mailableMethods, true)) {
            return 'Laravel mailable method';
        }

        return null;
    }

    private function isBroadcastEventMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Contracts\Broadcasting\ShouldBroadcast')) {
            return null;
        }

        $broadcastMethods = ['broadcastOn', 'broadcastWith', 'broadcastAs', 'broadcastWhen'];

        if (in_array($method->getName(), $broadcastMethods, true)) {
            return 'Laravel broadcast event method';
        }

        return null;
    }

    private function isJsonResourceMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Http\Resources\Json\JsonResource')) {
            return null;
        }

        $resourceMethods = ['toArray', 'with', 'additional', 'paginationInformation'];

        if (in_array($method->getName(), $resourceMethods, true)) {
            return 'Laravel JSON resource method';
        }

        return null;
    }

    private function isValidationRuleMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (
            !$classReflection->is('Illuminate\Contracts\Validation\ValidationRule')
            && !$classReflection->is('Illuminate\Contracts\Validation\Rule')
        ) {
            return null;
        }

        $ruleMethods = ['validate', 'passes', 'message'];

        if (in_array($method->getName(), $ruleMethods, true)) {
            return 'Laravel validation rule method';
        }

        return null;
    }

    private function isNotifiableMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection
    ): ?string
    {
        if (
            !$classReflection->hasTraitUse('Illuminate\Notifications\Notifiable')
            && !$classReflection->hasTraitUse('Illuminate\Notifications\RoutesNotifications')
        ) {
            return null;
        }

        if (strpos($method->getName(), 'routeNotificationFor') === 0) {
            return 'Laravel notification routing method';
        }

        return null;
    }

    /**
     * Checks if the method return type starts with the given prefix (for namespace matching).
     */
    private function methodReturnsType(
        ReflectionMethod $method,
        string $typePrefix
    ): bool
    {
        $returnType = $method->getReturnType();

        if (!$returnType instanceof ReflectionNamedType) {
            return false;
        }

        return strpos($returnType->getName(), $typePrefix) === 0;
    }

    /**
     * Checks if the method return type exactly matches the given type.
     */
    private function methodReturnsExactType(
        ReflectionMethod $method,
        string $type
    ): bool
    {
        $returnType = $method->getReturnType();

        if (!$returnType instanceof ReflectionNamedType) {
            return false;
        }

        return $returnType->getName() === $type;
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
