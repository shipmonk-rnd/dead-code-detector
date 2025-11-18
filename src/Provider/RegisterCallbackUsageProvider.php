<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantStringType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_key_exists;
use function array_map;
use function array_merge;
use function count;
use function explode;
use function in_array;
use function strpos;

/**
 * Detects classes and methods registered via PHP callback registration functions:
 * - stream_wrapper_register, stream_filter_register (class name as string - marks all methods as used)
 * - register_shutdown_function, register_tick_function, header_register_callback, spl_autoload_register (callable)
 */
class RegisterCallbackUsageProvider implements MemberUsageProvider
{

    /**
     * Functions where the first parameter is a class name (string)
     *
     * @var array<string, int> Function name => parameter index (0-based)
     */
    private const CLASS_NAME_FUNCTIONS = [
        'stream_wrapper_register' => 1,
        'stream_filter_register' => 1,
    ];

    /**
     * Functions where the first parameter is a callable
     *
     * @var list<string>
     */
    private const CALLABLE_FUNCTIONS = [
        'register_shutdown_function',
        'register_tick_function',
        'header_register_callback',
        'spl_autoload_register',
    ];

    private ReflectionProvider $reflectionProvider;

    private bool $enabled;

    public function __construct(
        ReflectionProvider $reflectionProvider,
        bool $enabled
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->enabled = $enabled;
    }

    public function getUsages(
        Node $node,
        Scope $scope
    ): array
    {
        if (!$this->enabled) {
            return [];
        }

        if (!$node instanceof FuncCall) {
            return [];
        }

        return $this->processFunctionCall($node, $scope);
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function processFunctionCall(
        FuncCall $node,
        Scope $scope
    ): array
    {
        $functionName = $this->getFunctionName($node, $scope);

        if ($functionName === null) {
            return [];
        }

        // Handle functions that register classes by name
        if (array_key_exists($functionName, self::CLASS_NAME_FUNCTIONS)) {
            return $this->handleClassNameParameter($node, $scope, self::CLASS_NAME_FUNCTIONS[$functionName]);
        }

        // Handle functions that register callables
        if (in_array($functionName, self::CALLABLE_FUNCTIONS, true)) {
            return $this->handleCallableParameter($node, $scope, 0);
        }

        return [];
    }

    private function getFunctionName(
        FuncCall $node,
        Scope $scope
    ): ?string
    {
        if ($node->name instanceof Name) {
            return $node->name->toString();
        }

        // Dynamic function call
        $nameType = $scope->getType($node->name);
        $constantStrings = $nameType->getConstantStrings();

        if (count($constantStrings) === 1) {
            return $constantStrings[0]->getValue();
        }

        return null;
    }

    /**
     * Mark all methods of registered classes as used
     *
     * @return list<ClassMethodUsage>
     */
    private function handleClassNameParameter(
        FuncCall $node,
        Scope $scope,
        int $paramIndex
    ): array
    {
        if (!isset($node->args[$paramIndex])) {
            return [];
        }

        /** @var Arg $arg */
        $arg = $node->args[$paramIndex];
        $argType = $scope->getType($arg->value);

        $usages = [];

        // Get class names from the type - handle both string types and object types
        $classNames = [];

        // Handle constant strings (e.g., 'MyClass' or MyClass::class)
        foreach ($argType->getConstantStrings() as $constantString) {
            $classNames[] = $constantString->getValue();
        }

        // Handle object types
        $classNames = array_merge($classNames, $argType->getObjectClassNames());

        foreach ($classNames as $className) {
            // If the class exists, mark all its methods as used
            if ($this->reflectionProvider->hasClass($className)) {
                $classReflection = $this->reflectionProvider->getClass($className);
                $nativeReflection = $classReflection->getNativeReflection();

                foreach ($nativeReflection->getMethods() as $method) {
                    // Only mark methods declared in this class, not inherited ones
                    if ($method->getDeclaringClass()->getName() === $className) {
                        $usages[] = new ClassMethodUsage(
                            UsageOrigin::createRegular($node, $scope),
                            new ClassMethodRef(
                                $className,
                                $method->getName(),
                                false, // exact class, not descendants
                            ),
                        );
                    }
                }
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function handleCallableParameter(
        FuncCall $node,
        Scope $scope,
        int $paramIndex
    ): array
    {
        if (!isset($node->args[$paramIndex])) {
            return [];
        }

        /** @var Arg $arg */
        $arg = $node->args[$paramIndex];

        // Handle array callables: ['ClassName', 'methodName'] or [$object, 'methodName']
        if ($arg->value instanceof Array_) {
            return $this->handleArrayCallable($arg->value, $node, $scope);
        }

        // Handle string callables: 'functionName' or 'ClassName::methodName'
        $argType = $scope->getType($arg->value);

        $usages = [];

        foreach ($argType->getConstantStrings() as $constantString) {
            $callable = $constantString->getValue();

            // Check if it's a static method call 'ClassName::methodName'
            if (strpos($callable, '::') !== false) {
                $usages = [
                    ...$usages,
                    ...$this->handleStaticMethodString($callable, $node, $scope),
                ];
            }
            // Otherwise, it's a function name, not a class method - skip it
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function handleArrayCallable(
        Array_ $array,
        Node $node,
        Scope $scope
    ): array
    {
        if (count($array->items) !== 2) {
            return [];
        }

        $items = $array->items;

        // Check that both items exist and are not unpacked
        // @phpstan-ignore offsetAccess.notFound, offsetAccess.notFound, offsetAccess.notFound, offsetAccess.notFound, identical.alwaysFalse, identical.alwaysFalse
        if ($items[0] === null || $items[0]->unpack || $items[1] === null || $items[1]->unpack) {
            return [];
        }

        /** @var ArrayItem $firstItem */
        $firstItem = $items[0]; // @phpstan-ignore offsetAccess.notFound
        /** @var ArrayItem $secondItem */
        $secondItem = $items[1]; // @phpstan-ignore offsetAccess.notFound

        // Second item should be the method name (string)
        $methodNameType = $scope->getType($secondItem->value);
        $methodNames = [];

        foreach ($methodNameType->getConstantStrings() as $constantString) {
            $methodNames[] = $constantString->getValue();
        }

        if ($methodNames === []) {
            return [];
        }

        // First item can be a class name (string) or an object instance
        $classOrObjectType = $scope->getType($firstItem->value);

        $usages = [];

        // Try to get class names from the type
        $classNames = array_merge(
            $classOrObjectType->getObjectClassNames(), // For object instances
            array_map(
                static fn (ConstantStringType $type): string => $type->getValue(),
                $classOrObjectType->getConstantStrings(), // For class name strings
            ),
        );

        foreach ($classNames as $className) {
            foreach ($methodNames as $methodName) {
                $usages[] = new ClassMethodUsage(
                    UsageOrigin::createRegular($node, $scope),
                    new ClassMethodRef(
                        $className,
                        $methodName,
                        true,
                    ),
                );
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function handleStaticMethodString(
        string $callable,
        Node $node,
        Scope $scope
    ): array
    {
        $parts = explode('::', $callable);

        if (count($parts) !== 2) {
            return [];
        }

        [$className, $methodName] = $parts;

        // Resolve the actual declaring class if the class exists
        if ($this->reflectionProvider->hasClass($className)) {
            $classReflection = $this->reflectionProvider->getClass($className);

            if ($classReflection->hasMethod($methodName)) {
                $methodReflection = $classReflection->getMethod($methodName, $scope);
                $className = $methodReflection->getDeclaringClass()->getName();
            }
        }

        return [
            new ClassMethodUsage(
                UsageOrigin::createRegular($node, $scope),
                new ClassMethodRef(
                    $className,
                    $methodName,
                    true,
                ),
            ),
        ];
    }

}
