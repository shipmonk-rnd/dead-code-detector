<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

class ClassAndMethod
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

}
