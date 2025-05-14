<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use function strpos;
use function ucfirst;

class ApiPhpDocUsageProvider extends ReflectionBasedMemberUsageProvider
{

    private bool $enabled;

    public function __construct(bool $enabled)
    {
        $this->enabled = $enabled;
    }

    public function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        return $this->enabled ? $this->shouldMarkMemberAsUsed($method) : null;
    }

    public function shouldMarkConstantAsUsed(ReflectionClassConstant $constant): ?VirtualUsageData
    {
        return $this->enabled ? $this->shouldMarkMemberAsUsed($constant) : null;
    }

    /**
     * @param ReflectionClassConstant|ReflectionMethod $member
     */
    public function shouldMarkMemberAsUsed(object $member): ?VirtualUsageData
    {
        $reflectionClass = $member->getDeclaringClass();
        $memberType = $member instanceof ReflectionClassConstant ? 'constant' : 'method';
        $memberName = $member->getName();

        if ($this->isApiClass($reflectionClass)) {
            return VirtualUsageData::withNote("Class {$reflectionClass->getName()} is public @api");
        }

        do {
            if ($this->isApiMember($reflectionClass, $memberName)) {
                return VirtualUsageData::withNote(ucfirst("$memberType {$reflectionClass->getName()}::{$memberName} is public @api"));
            }

            foreach ($reflectionClass->getInterfaces() as $interface) {
                if ($this->isApiClass($interface)) {
                    return VirtualUsageData::withNote("Interface {$interface->getName()} is public @api");
                }

                if ($this->isApiMember($interface, $memberName)) {
                    return VirtualUsageData::withNote("Interface $memberType {$interface->getName()}::{$memberName} is public @api");
                }
            }

            $reflectionClass = $reflectionClass->getParentClass();
        } while ($reflectionClass !== false);

        return null;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function isApiClass(ReflectionClass $reflection): bool
    {
        $phpDoc = $reflection->getDocComment();

        if ($phpDoc !== false && strpos($phpDoc, '@api') !== false) {
            return true;
        }

        return false;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function isApiMember(ReflectionClass $reflection, string $memberName): bool
    {
        if ($reflection->hasMethod($memberName)) {
            $phpDoc = $reflection->getMethod($memberName)->getDocComment(); // @phpstan-ignore missingType.checkedException (ReflectionException handled by hasMethod)

            if ($phpDoc !== false && strpos($phpDoc, '@api') !== false) {
                return true;
            }
        }

        if ($reflection->hasConstant($memberName)) {
            /** @var ReflectionClassConstant $constant */
            $constant = $reflection->getReflectionConstant($memberName);
            $phpDoc = $constant->getDocComment();

            if ($phpDoc !== false && strpos($phpDoc, '@api') !== false) {
                return true;
            }
        }

        return false;
    }

}
