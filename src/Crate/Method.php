<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

use function strpos;

/**
 * @immutable
 */
class Method
{

    public string $className;

    public string $methodName;

    public function __construct(
        string $className,
        string $methodName
    )
    {
        $this->className = $className;
        $this->methodName = $methodName;
    }

    public function toString(): string
    {
        return $this->className . '::' . $this->methodName;
    }

    public static function isUnsupported(string $methodName): bool
    {
        if ($methodName === '__destruct') {
            return true;
        }

        if (
            strpos($methodName, '__') === 0
            && $methodName !== '__construct'
            && $methodName !== '__clone'
        ) {
            return true; // magic methods like __toString, __get, __set etc
        }

        return false;
    }

}
