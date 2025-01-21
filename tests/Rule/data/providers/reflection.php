<?php declare(strict_types = 1);

namespace Reflection;

use PHPUnit\Framework\TestCase;
use ShipMonk\PHPStan\DeadCode\Rule\RuleTestCase;

interface MyParent
{
    const IFACE_CONSTANT = 1;
}

class Holder1 implements MyParent
{
    const CLASS_CONSTANT = 1;

    public function used() {}
    public function notUsed() {} // error: Unused Reflection\Holder1::notUsed
}

class Holder2
{
    const CONST1 = 1;
    const CONST2 = 2; // error: Unused Reflection\Holder2::CONST2
    const CONST3 = 3;

    public function __construct() {}
    public function notUsed() {} // error: Unused Reflection\Holder2::notUsed
}

class Holder3
{
    const CONST1 = 1;
    const CONST2 = 2;
    const CONST3 = 3;

    public function used1() {}
    public function used2() {}
}

class Holder4
{
    public function __construct() {} // error: Unused Reflection\Holder4::__construct
}

class Holder5
{
    public function __construct() {}
}

abstract class HolderParent {}
class Holder6 extends HolderParent
{
    const NOT_IN_PARENT = 1;
}

enum EnumHolder1 {
    const CONST1 = 1;
    public function used() {}
}

enum TransitiveHolder {
    const TRANSITIVELY_DEAD = 1; // error: Unused Reflection\TransitiveHolder::TRANSITIVELY_DEAD

    public function test() // error: Unused Reflection\TransitiveHolder::test
    {
        (new \ReflectionClass(self::class))->getConstant('TRANSITIVELY_DEAD');
    }
}


abstract class GetAllConstantsParent {
    public static function getConstants()
    {
        // this is not yet supported as we are unaware of children in collectors (and thus in ReflectionUsageProvider)
        // it might be solvable by making ClassConstantRef::$constantName nullable
        //  - calling getConstants() should mark all constants as used (on all children as the generic class might be just phpdoc based, not string-literal based)
        return (new \ReflectionClass(static::class))->getConstants();
    }

    /**
     * @param \ReflectionClass<self> $reflection
     */
    public static function getConstants2(\ReflectionClass $reflection)
    {
        // same problem as above
        return $reflection->getConstants();
    }
}

class GetAllConstantsChild extends GetAllConstantsParent {
    const CONSTANT = 1; // error: Unused Reflection\GetAllConstantsChild::CONSTANT
}

GetAllConstantsChild::getConstants();
GetAllConstantsChild::getConstants2();


$reflection1 = new \ReflectionClass(Holder1::class);
$reflection1->getConstants();
$reflection1->getMethod('used');

$reflection2 = new \ReflectionClass(Holder2::class);
$reflection2->getReflectionConstant('CONST1');
$reflection2->getConstant('CONST3');
$reflection2->getConstructor();

$reflection3 = new \ReflectionClass(Holder3::class);
$reflection3->getMethods();
$reflection3->getReflectionConstants();

$reflection4 = new \ReflectionClass(Holder4::class);
$reflection4->newInstanceWithoutConstructor();

$reflection4 = new \ReflectionClass(Holder5::class);
$reflection4->newInstance();

$enumReflection1 = new \ReflectionClass(EnumHolder1::class);
$enumReflection1->getConstants();
$enumReflection1->getMethod('used');

/**
 * @param class-string<HolderParent> $fqn
 */
function testMemberOnlyInDescendant(string $fqn) {
    $classReflection = new \ReflectionClass($fqn);

    if ($classReflection->hasConstant('NOT_IN_PARENT')) {
        echo $classReflection->getConstant('NOT_IN_PARENT');
    }
}
