<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function in_array;

/**
 * See: https://php.net/manual/en/class.streamwrapper.php
 */
class StreamWrapperUsageProvider implements MemberUsageProvider
{

    private const STREAM_WRAPPER_METHODS = [
        'dir_closedir',
        'dir_opendir',
        'dir_readdir',
        'dir_rewinddir',
        'mkdir',
        'rename',
        'rmdir',
        'stream_cast',
        'stream_close',
        'stream_eof',
        'stream_flush',
        'stream_lock',
        'stream_metadata',
        'stream_open',
        'stream_read',
        'stream_seek',
        'stream_set_option',
        'stream_stat',
        'stream_tell',
        'stream_truncate',
        'stream_write',
        'unlink',
        'url_stat',
    ];

    private bool $enabled;

    public function __construct(
        bool $enabled
    )
    {
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
        $functionNames = $this->getFunctionNames($node, $scope);

        if (in_array('stream_wrapper_register', $functionNames, true)) {
            return $this->handleStreamWrapperRegister($node, $scope);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function getFunctionNames(
        FuncCall $node,
        Scope $scope
    ): array
    {
        if ($node->name instanceof Name) {
            return [$node->name->toString()];
        }

        $functionNames = [];
        foreach ($scope->getType($node->name)->getConstantStrings() as $constantString) {
            $functionNames[] = $constantString->getValue();
        }

        return $functionNames;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function handleStreamWrapperRegister(
        FuncCall $node,
        Scope $scope
    ): array
    {
        $secondArg = $node->getArgs()[1] ?? null;
        if ($secondArg === null) {
            return [];
        }

        $argType = $scope->getType($secondArg->value);

        $usages = [];
        $classNames = [];
        foreach ($argType->getConstantStrings() as $constantString) {
            $classNames[] = $constantString->getValue();
        }

        foreach ($classNames as $className) {
            foreach (self::STREAM_WRAPPER_METHODS as $methodName) {
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

        return $usages;
    }

}
