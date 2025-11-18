<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\TrinaryLogic;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_key_first;
use function count;
use function explode;
use function in_array;
use function strpos;

/**
 * Marks classes and methods as used when registered via PHP's register_* functions
 * - stream_wrapper_register
 * - stream_filter_register
 * - register_shutdown_function
 * - register_tick_function
 * - header_register_callback
 * - spl_autoload_register
 */
class RegisterCallbackUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    private ReflectionProvider $reflectionProvider;

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

        if (!$node->name instanceof Name) {
            return [];
        }

        $functionName = $node->name->toString();

        // Handle stream_wrapper_register and stream_filter_register
        if (in_array($functionName, ['stream_wrapper_register', 'stream_filter_register'], true)) {
            return $this->getUsagesForClassRegistration($node, $scope, $functionName);
        }

        // Handle callback registration functions
        if (in_array($functionName, ['register_shutdown_function', 'register_tick_function', 'header_register_callback', 'spl_autoload_register'], true)) {
            return $this->getUsagesForCallbackRegistration($node, $scope, $functionName);
        }

        return [];
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesForClassRegistration(
        FuncCall $node,
        Scope $scope,
        string $functionName
    ): array
    {
        // For stream_wrapper_register and stream_filter_register, the class name is the second parameter
        if (count($node->args) < 2) {
            return [];
        }

        $args = $node->args;
        /** @var Arg $secondArg */
        $secondArg = $args[1];

        $classNames = [];

        // Get possible class names from the argument
        $argType = $scope->getType($secondArg->value);
        foreach ($argType->getConstantStrings() as $constantString) {
            $classNames[] = $constantString->getValue();
        }

        if ($classNames === []) {
            return [];
        }

        $usages = [];

        foreach ($classNames as $className) {
            if (!$this->reflectionProvider->hasClass($className)) {
                continue;
            }

            $classReflection = $this->reflectionProvider->getClass($className);

            // Mark the constructor as used to indicate the class is instantiated
            if ($classReflection->hasConstructor()) {
                $usages[] = new ClassMethodUsage(
                    UsageOrigin::createRegular($node, $scope),
                    new ClassMethodRef(
                        $className,
                        '__construct',
                        false,
                    ),
                );
            }

            // For stream wrappers, mark all required methods as used
            if ($functionName === 'stream_wrapper_register') {
                foreach ($this->getStreamWrapperMethods() as $methodName) {
                    if ($classReflection->hasMethod($methodName)) {
                        $usages[] = new ClassMethodUsage(
                            UsageOrigin::createRegular($node, $scope),
                            new ClassMethodRef(
                                $className,
                                $methodName,
                                false,
                            ),
                        );
                    }
                }
            }

            // For stream filters, mark filter-related methods as used
            if ($functionName === 'stream_filter_register') {
                foreach ($this->getStreamFilterMethods() as $methodName) {
                    if ($classReflection->hasMethod($methodName)) {
                        $usages[] = new ClassMethodUsage(
                            UsageOrigin::createRegular($node, $scope),
                            new ClassMethodRef(
                                $className,
                                $methodName,
                                false,
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
    private function getUsagesForCallbackRegistration(
        FuncCall $node,
        Scope $scope,
        string $functionName
    ): array
    {
        if (count($node->args) === 0) {
            return [];
        }

        $args = $node->args;
        /** @var Arg $firstArg */
        $firstArg = $args[array_key_first($args)];

        return $this->extractUsagesFromCallable($firstArg->value, $node, $scope);
    }

    /**
     * Extract class method usages from a callable expression
     *
     * @return list<ClassMethodUsage>
     */
    private function extractUsagesFromCallable(
        Node\Expr $callableExpr,
        Node $node,
        Scope $scope
    ): array
    {
        // Handle array callables like [ClassName::class, 'methodName'] or [$object, 'methodName']
        if ($callableExpr instanceof Array_) {
            return $this->extractUsagesFromArrayCallable($callableExpr, $node, $scope);
        }

        // Handle string callables
        $callableType = $scope->getType($callableExpr);
        foreach ($callableType->getConstantStrings() as $constantString) {
            $callableString = $constantString->getValue();

            // Check if it's a static method call like 'ClassName::methodName'
            if (strpos($callableString, '::') !== false) {
                [$className, $methodName] = explode('::', $callableString, 2);

                if ($this->reflectionProvider->hasClass($className)) {
                    return [
                        new ClassMethodUsage(
                            UsageOrigin::createRegular($node, $scope),
                            new ClassMethodRef(
                                $className,
                                $methodName,
                                false,
                            ),
                        ),
                    ];
                }
            }
        }

        return [];
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function extractUsagesFromArrayCallable(
        Array_ $arrayExpr,
        Node $node,
        Scope $scope
    ): array
    {
        if (count($arrayExpr->items) !== 2) {
            return [];
        }

        $items = $arrayExpr->items;

        // Both items must be set
        if ($items[0] === null || $items[1] === null) {
            return [];
        }

        /** @var ArrayItem $firstItem */
        $firstItem = $items[0];
        /** @var ArrayItem $secondItem */
        $secondItem = $items[1];

        $usages = [];

        // Get the class name from the first element
        $classNames = [];

        // Handle ClassName::class
        if ($firstItem->value instanceof ClassConstFetch) {
            $classConstFetch = $firstItem->value;

            if ($classConstFetch->name instanceof Node\Identifier && $classConstFetch->name->toString() === 'class') {
                if ($classConstFetch->class instanceof Name) {
                    $classNames[] = $scope->resolveName($classConstFetch->class);
                } else {
                    // Handle $object::class or other expressions
                    $classType = $scope->getType($classConstFetch->class);
                    foreach ($classType->getObjectClassNames() as $className) {
                        $classNames[] = $className;
                    }
                }
            }
        } else {
            // Handle object or class name string
            $firstType = $scope->getType($firstItem->value);

            // Try to get class names from object type
            foreach ($firstType->getObjectClassNames() as $className) {
                $classNames[] = $className;
            }

            // Try to get class names from string type
            foreach ($firstType->getConstantStrings() as $constantString) {
                $classNames[] = $constantString->getValue();
            }
        }

        // Get the method name from the second element
        $methodNames = [];
        $secondType = $scope->getType($secondItem->value);

        foreach ($secondType->getConstantStrings() as $constantString) {
            $methodNames[] = $constantString->getValue();
        }

        // Create usages for all combinations
        foreach ($classNames as $className) {
            foreach ($methodNames as $methodName) {
                if (!$this->reflectionProvider->hasClass($className)) {
                    continue;
                }

                $classReflection = $this->reflectionProvider->getClass($className);

                if ($classReflection->hasMethod($methodName)) {
                    $usages[] = new ClassMethodUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassMethodRef(
                            $className,
                            $methodName,
                            false,
                        ),
                    );
                }
            }
        }

        return $usages;
    }

    /**
     * @return list<string>
     */
    private function getStreamWrapperMethods(): array
    {
        // Common methods that stream wrappers implement
        // See: https://www.php.net/manual/en/class.streamwrapper.php
        return [
            'stream_open',
            'stream_close',
            'stream_read',
            'stream_write',
            'stream_flush',
            'stream_seek',
            'stream_tell',
            'stream_eof',
            'stream_stat',
            'url_stat',
            'dir_opendir',
            'dir_readdir',
            'dir_rewinddir',
            'dir_closedir',
            'stream_metadata',
            'stream_truncate',
            'stream_lock',
            'stream_cast',
            'stream_set_option',
            'unlink',
            'rename',
            'mkdir',
            'rmdir',
        ];
    }

    /**
     * @return list<string>
     */
    private function getStreamFilterMethods(): array
    {
        // Methods that stream filters implement
        // See: https://www.php.net/manual/en/class.php-user-filter.php
        return [
            'filter',
            'onCreate',
            'onClose',
        ];
    }

}
