<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use Reflector;

class BuiltinUsageProvider extends ReflectionBasedMemberUsageProvider
{

    private bool $enabled;

    public function __construct(bool $enabled)
    {
        $this->enabled = $enabled;
    }

    public function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->shouldMarkMemberAsUsed($method);
    }

    protected function shouldMarkConstantAsUsed(ReflectionClassConstant $constant): ?VirtualUsageData
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->shouldMarkMemberAsUsed($constant);
    }

    /**
     * @param ReflectionMethod|ReflectionClassConstant $member
     */
    private function shouldMarkMemberAsUsed(Reflector $member): ?VirtualUsageData
    {
        $reflectionClass = $member->getDeclaringClass();

        do {
            if ($this->isBuiltinMember($reflectionClass, $member)) {
                return $this->createUsageNote($member);
            }

            foreach ($reflectionClass->getInterfaces() as $interface) {
                if ($this->isBuiltinMember($interface, $member)) {
                    return $this->createUsageNote($member);
                }
            }

            foreach ($reflectionClass->getTraits() as $trait) {
                if ($this->isBuiltinMember($trait, $member)) {
                    return $this->createUsageNote($member);
                }
            }

            $reflectionClass = $reflectionClass->getParentClass();
        } while ($reflectionClass !== false);

        return null;
    }

    /**
     * @param ReflectionMethod|ReflectionClassConstant $member
     * @param ReflectionClass<object> $reflectionClass
     */
    private function isBuiltinMember(
        ReflectionClass $reflectionClass,
        Reflector $member
    ): bool
    {
        if ($member instanceof ReflectionMethod && !$reflectionClass->hasMethod($member->getName())) {
            return false;
        }

        if ($member instanceof ReflectionClassConstant && !$reflectionClass->hasConstant($member->getName())) {
            return false;
        }

        return $reflectionClass->getExtensionName() !== false;
    }

    /**
     * @param ReflectionMethod|ReflectionClassConstant $member
     */
    private function createUsageNote(Reflector $member): VirtualUsageData
    {
        $memberString = $member instanceof ReflectionMethod ? 'Method' : 'Constant';
        return VirtualUsageData::withNote("$memberString overrides builtin one, thus is assumed to be used by some PHP code.");
    }

}
