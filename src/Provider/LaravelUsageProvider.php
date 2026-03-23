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
use PHPStan\Type\Constant\ConstantStringType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_map;
use function array_slice;
use function count;
use function explode;
use function implode;
use function in_array;
use function lcfirst;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strpos;
use function strrpos;
use function substr;
use function ucwords;

final class LaravelUsageProvider implements MemberUsageProvider
{

    private readonly bool $enabled;

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        ?bool $enabled,
    )
    {
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

        if ($node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            $usages = [...$usages, ...$this->getMethodUsagesFromReflection($node)];
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
        Scope $scope,
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

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromRouteCall(
        StaticCall $node,
        Scope $scope,
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
                        new ClassMethodRef($className, $usedMethod, possibleDescendant: false),
                    );
                }
            }

            // String syntax: Route::get('/path', 'Controller@method')
            foreach ($this->extractControllerAtMethodFromArg($node, $scope, 1) as [$className, $method]) {
                foreach ([$method, '__construct'] as $usedMethod) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $usedMethod, possibleDescendant: false),
                    );
                }
            }

            // Invokable controllers: Route::get('/path', Controller::class)
            foreach ($this->extractClassNamesFromArg($node, $scope, 1) as $className) {
                foreach (['__invoke', '__construct'] as $method) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $method, possibleDescendant: false),
                    );
                }
            }
        }

        if ($methodName === 'match') {
            foreach ($this->extractCallablesFromArg($node, $scope, 2) as [$className, $method]) {
                foreach ([$method, '__construct'] as $usedMethod) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $usedMethod, possibleDescendant: false),
                    );
                }
            }

            // String syntax: Route::match(['GET'], '/path', 'Controller@method')
            foreach ($this->extractControllerAtMethodFromArg($node, $scope, 2) as [$className, $method]) {
                foreach ([$method, '__construct'] as $usedMethod) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $usedMethod, possibleDescendant: false),
                    );
                }
            }

            // Invokable controllers: Route::match(['GET'], '/path', Controller::class)
            foreach ($this->extractClassNamesFromArg($node, $scope, 2) as $className) {
                foreach (['__invoke', '__construct'] as $method) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $method, possibleDescendant: false),
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
                        new ClassMethodRef($className, $method, possibleDescendant: false),
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
                        new ClassMethodRef($className, $method, possibleDescendant: false),
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
        Scope $scope,
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
                        new ClassMethodRef($className, $method, possibleDescendant: false),
                    );
                }
            }
        }

        if ($methodName === 'subscribe') {
            foreach ($this->extractClassNamesFromArg($node, $scope, 0) as $className) {
                foreach (['subscribe', '__construct'] as $method) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $method, possibleDescendant: false),
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
        Scope $scope,
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
                        new ClassMethodRef($className, $method, possibleDescendant: false),
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
        Scope $scope,
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
                        new ClassMethodRef($className, $usedMethod, possibleDescendant: false),
                    );
                }
            }

            foreach ($this->extractClassNamesFromArg($node, $scope, 1) as $className) {
                foreach (['__invoke', '__construct'] as $method) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef($className, $method, possibleDescendant: false),
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
        Scope $scope,
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
        Scope $scope,
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
                $policyClassNames = [...$policyClassNames, ...$this->resolvePolicyClassNames($modelClassName)];
            }

            foreach ($modelType->getConstantStrings() as $modelStringType) {
                $policyClassNames = [...$policyClassNames, ...$this->resolvePolicyClassNames($modelStringType->getValue())];
            }
        }

        $usages = [];

        foreach ($policyClassNames as $policyClassName) {
            foreach ($abilityNames as $abilityName) {
                $usages[] = new ClassMethodUsage(
                    UsageOrigin::createRegular($node, $scope),
                    new ClassMethodRef($policyClassName, $abilityName, possibleDescendant: false),
                );
            }
        }

        return $usages;
    }

    /**
     * @return list<string>
     *
     * @see \Illuminate\Auth\Access\Gate::guessPolicyName()
     */
    private function resolvePolicyClassNames(string $modelClassName): array
    {
        $lastSeparator = strrpos($modelClassName, '\\');

        if ($lastSeparator === false) {
            return [];
        }

        $classDirname = substr($modelClassName, 0, $lastSeparator);
        $classBasename = substr($modelClassName, $lastSeparator + 1);
        $policyBasename = $classBasename . 'Policy';

        $segments = explode('\\', $classDirname);
        $segmentCount = count($segments);

        $candidates = [];

        if (str_contains($classDirname, '\\Models\\')) {
            $candidates[] = str_replace('\\Models\\', '\\Models\\Policies\\', $classDirname) . '\\' . $policyBasename;
            $candidates[] = str_replace('\\Models\\', '\\Policies\\', $classDirname) . '\\' . $policyBasename;
        }

        for ($i = $segmentCount; $i >= 1; $i--) {
            $candidates[] = implode('\\', array_slice($segments, 0, $i)) . '\\Policies\\' . $policyBasename;
        }

        $result = [];

        foreach ($candidates as $candidate) {
            if ($this->reflectionProvider->hasClass($candidate)) {
                $result[] = $candidate;
            }
        }

        return $result;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function markAllPublicPolicyMethods(
        string $policyClassName,
        UsageOrigin $origin,
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
                    new ClassMethodRef($policyClassName, $method->getName(), possibleDescendant: false),
                );
            }
        }

        return $usages;
    }

    private function kebabToCamelCase(string $string): string
    {
        if (!str_contains($string, '-')) {
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
     * Extracts [class, method] pairs from a callable array argument like [Controller::class, 'method'].
     *
     * @return list<array{string, string}>
     */
    private function extractCallablesFromArg(
        StaticCall $node,
        Scope $scope,
        int $argIndex,
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
                    static fn (ConstantStringType $stringType): string => $stringType->getValue(),
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
        int $argIndex,
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

    /**
     * Extracts [class, method] pairs from a 'Controller@method' string argument.
     *
     * @return list<array{string, string}>
     */
    private function extractControllerAtMethodFromArg(
        StaticCall $node,
        Scope $scope,
        int $argIndex,
    ): array
    {
        $arg = $node->getArgs()[$argIndex] ?? null;

        if ($arg === null) {
            return [];
        }

        $argType = $scope->getType($arg->value);
        $callables = [];

        foreach ($argType->getConstantStrings() as $stringType) {
            $value = $stringType->getValue();
            $atPos = strpos($value, '@');

            if ($atPos !== false) {
                $className = substr($value, 0, $atPos);
                $methodName = substr($value, $atPos + 1);
                $callables[] = [$className, $methodName];
            }
        }

        return $callables;
    }

    private function shouldMarkAsUsed(
        ReflectionMethod $method,
        ClassReflection $classReflection,
    ): ?string
    {
        return $this->isCommandMethod($method, $classReflection)
            ?? $this->isJobMethod($method, $classReflection)
            ?? $this->isServiceProviderMethod($method, $classReflection)
            ?? $this->isMiddlewareMethod($method, $classReflection)
            ?? $this->isNotificationMethod($method, $classReflection)
            ?? $this->isFormRequestMethod($method, $classReflection)
            ?? $this->isPolicyMethod($method, $classReflection)
            ?? $this->isMailableMethod($method, $classReflection)
            ?? $this->isBroadcastEventMethod($method, $classReflection)
            ?? $this->isJsonResourceMethod($method, $classReflection)
            ?? $this->isNotifiableMethod($method, $classReflection)
            ?? $this->isEventListenerMethod($method, $classReflection);
    }

    private function isCommandMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection,
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
        ClassReflection $classReflection,
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
        ClassReflection $classReflection,
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
        ClassReflection $classReflection,
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
        ClassReflection $classReflection,
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
        ClassReflection $classReflection,
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

    private function isPolicyMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection,
    ): ?string
    {
        $className = $classReflection->getName();

        if (!str_contains($className, '\\Policies\\')) {
            return null;
        }

        $lastSeparator = strrpos($className, '\\');
        $shortName = $lastSeparator !== false ? substr($className, $lastSeparator + 1) : $className;

        if (!str_ends_with($shortName, 'Policy')) {
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
        ClassReflection $classReflection,
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
        ClassReflection $classReflection,
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Contracts\Broadcasting\ShouldBroadcast')) {
            return null;
        }

        $broadcastMethods = ['broadcastWith', 'broadcastAs', 'broadcastWhen'];

        if (in_array($method->getName(), $broadcastMethods, true)) {
            return 'Laravel broadcast event method';
        }

        return null;
    }

    private function isJsonResourceMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection,
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Http\Resources\Json\JsonResource')) {
            return null;
        }

        $resourceMethods = ['paginationInformation'];

        if (in_array($method->getName(), $resourceMethods, true)) {
            return 'Laravel JSON resource method';
        }

        return null;
    }

    private function isNotifiableMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection,
    ): ?string
    {
        if (
            !$classReflection->hasTraitUse('Illuminate\Notifications\Notifiable')
            && !$classReflection->hasTraitUse('Illuminate\Notifications\RoutesNotifications')
        ) {
            return null;
        }

        if (str_starts_with($method->getName(), 'routeNotificationFor')) {
            return 'Laravel notification routing method';
        }

        return null;
    }

    private function isEventListenerMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection,
    ): ?string
    {
        $methodName = $method->getName();

        if ($method->isPublic() && (str_starts_with($methodName, 'handle') || $methodName === '__invoke')) {
            if ($this->firstParamHasClassType($methodName, $classReflection)) {
                return 'Laravel auto-discovered event listener method';
            }

            return null;
        }

        if ($method->isConstructor() && $this->hasAutoDiscoveredListenerMethod($classReflection)) {
            return 'Laravel auto-discovered event listener method';
        }

        return null;
    }

    private function hasAutoDiscoveredListenerMethod(ClassReflection $classReflection): bool
    {
        foreach ($classReflection->getNativeReflection()->getMethods() as $classMethod) {
            if (!$classMethod->isPublic()) {
                continue;
            }

            $name = $classMethod->getName();

            if (!str_starts_with($name, 'handle') && $name !== '__invoke') {
                continue;
            }

            if ($this->firstParamHasClassType($name, $classReflection)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @see \Illuminate\Support\Reflector::getParameterClassNames()
     */
    private function firstParamHasClassType(
        string $methodName,
        ClassReflection $classReflection,
    ): bool
    {
        foreach ($classReflection->getNativeMethod($methodName)->getVariants() as $variant) {
            $params = $variant->getParameters();

            if ($params === []) {
                return false;
            }

            return $params[0]->getType()->getObjectClassNames() !== [];
        }

        return false;
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
                false,
            ),
        );
    }

}
