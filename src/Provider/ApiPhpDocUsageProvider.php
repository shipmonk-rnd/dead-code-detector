<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionClassConstant;
use ReflectionEnumUnitCase;
use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Reflection\ReflectionHelper;
use function strpos;

class ApiPhpDocUsageProvider extends ReflectionBasedMemberUsageProvider
{

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

    public function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        return $this->enabled ? $this->shouldMarkMemberAsUsed($method) : null;
    }

    public function shouldMarkConstantAsUsed(ReflectionClassConstant $constant): ?VirtualUsageData
    {
        return $this->enabled ? $this->shouldMarkMemberAsUsed($constant) : null;
    }

    public function shouldMarkEnumCaseAsUsed(ReflectionEnumUnitCase $enumCase): ?VirtualUsageData
    {
        return $this->enabled ? $this->shouldMarkMemberAsUsed($enumCase) : null;
    }

    /**
     * @param ReflectionClassConstant|ReflectionMethod $member
     */
    public function shouldMarkMemberAsUsed(object $member): ?VirtualUsageData
    {
        $reflectionClass = $this->reflectionProvider->getClass($member->getDeclaringClass()->getName());
        $memberType = $member instanceof ReflectionClassConstant ? 'constant' : 'method';
        $memberName = $member->getName();

        if ($this->isApiMember($reflectionClass, $member)) {
            return VirtualUsageData::withNote("Class {$reflectionClass->getName()} is public @api");
        }

        do {
            foreach ($reflectionClass->getInterfaces() as $interface) {
                if ($this->isApiMember($interface, $member)) {
                    return VirtualUsageData::withNote("Interface $memberType {$interface->getName()}::{$memberName} is public @api");
                }
            }

            foreach ($reflectionClass->getParents() as $parent) {
                if ($this->isApiMember($parent, $member)) {
                    return VirtualUsageData::withNote("Class $memberType {$parent->getName()}::{$memberName} is public @api");
                }
            }

            $reflectionClass = $reflectionClass->getParentClass();
        } while ($reflectionClass !== null);

        return null;
    }

    /**
     * @param ReflectionClassConstant|ReflectionMethod $member
     */
    private function isApiMember(
        ClassReflection $reflection,
        object $member
    ): bool
    {
        if (!$this->hasOwnMember($reflection, $member)) {
            return false;
        }

        if ($this->isApiClass($reflection)) {
            return true;
        }

        if ($member instanceof ReflectionClassConstant) {
            $constant = $reflection->getConstant($member->getName());
            $phpDoc = $constant->getDocComment();

            if ($this->isApiPhpDoc($phpDoc)) {
                return true;
            }

            return false;
        }

        $phpDoc = $reflection->getNativeMethod($member->getName())->getDocComment();

        if ($this->isApiPhpDoc($phpDoc)) {
            return true;
        }

        return false;
    }

    /**
     * @param ReflectionClassConstant|ReflectionMethod $member
     */
    private function hasOwnMember(
        ClassReflection $reflection,
        object $member
    ): bool
    {
        if ($member instanceof ReflectionEnumUnitCase) {
            return ReflectionHelper::hasOwnEnumCase($reflection, $member->getName());
        }

        if ($member instanceof ReflectionClassConstant) {
            return ReflectionHelper::hasOwnConstant($reflection, $member->getName());
        }

        return ReflectionHelper::hasOwnMethod($reflection, $member->getName());
    }

    private function isApiClass(ClassReflection $reflection): bool
    {
        $phpDoc = $reflection->getResolvedPhpDoc();

        if ($phpDoc === null) {
            return false;
        }

        if ($this->isApiPhpDoc($phpDoc->getPhpDocString())) {
            return true;
        }

        return false;
    }

    private function isApiPhpDoc(?string $phpDoc): bool
    {
        return $phpDoc !== null && strpos($phpDoc, '@api') !== false;
    }

}
