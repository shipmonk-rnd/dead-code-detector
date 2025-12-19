<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionClassConstant;
use ReflectionEnumUnitCase;
use ReflectionMethod;
use ReflectionProperty;
use ShipMonk\PHPStan\DeadCode\Reflection\ReflectionHelper;
use function strpos;

final class ApiPhpDocUsageProvider extends ReflectionBasedMemberUsageProvider
{

    private ReflectionProvider $reflectionProvider;

    private bool $enabled;

    /**
     * @var list<string> $analysedPaths
     */
    private array $analysedPaths;

    /**
     * @param list<string> $analysedPaths
     */
    public function __construct(
        ReflectionProvider $reflectionProvider,
        bool $enabled,
        array $analysedPaths
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->enabled = $enabled;
        $this->analysedPaths = $analysedPaths;
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

    public function shouldMarkPropertyAsUsed(ReflectionProperty $property): ?VirtualUsageData
    {
        return $this->enabled ? $this->shouldMarkMemberAsUsed($property) : null;
    }

    /**
     * @param ReflectionClassConstant|ReflectionMethod|ReflectionProperty $member
     */
    public function shouldMarkMemberAsUsed(object $member): ?VirtualUsageData
    {
        $reflectionClass = $this->reflectionProvider->getClass($member->getDeclaringClass()->getName());
        $memberType = ReflectionHelper::getMemberType($member);
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
     * @param ReflectionClassConstant|ReflectionMethod|ReflectionProperty $member
     */
    private function isApiMember(
        ClassReflection $reflection,
        object $member
    ): bool
    {
        if (!$this->hasOwnMember($reflection, $member)) {
            return false;
        }

        if ($this->isOutsideAnalysedPaths($reflection)) {
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

        if ($member instanceof ReflectionProperty) {
            $property = $reflection->getNativeProperty($member->getName());
            $phpDoc = $property->getDocComment();

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
     * @param ReflectionClassConstant|ReflectionMethod|ReflectionProperty $member
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

        if ($member instanceof ReflectionProperty) {
            return ReflectionHelper::hasOwnProperty($reflection, $member->getName());
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

    private function isOutsideAnalysedPaths(ClassReflection $reflection): bool
    {
        $fileName = $reflection->getFileName();
        if ($fileName === null) {
            return true;
        }

        foreach ($this->analysedPaths as $path) {
            if (strpos($fileName, $path) === 0) {
                return false;
            }
        }

        return true;
    }

}
