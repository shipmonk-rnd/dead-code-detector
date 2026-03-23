<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use Reflector;
use ShipMonk\PHPStan\DeadCode\Reflection\ReflectionHelper;
use function array_keys;
use function str_starts_with;
use function strlen;
use function substr;
use function ucfirst;

final class VendorUsageProvider extends ReflectionBasedMemberUsageProvider
{

    private readonly bool $enabled;

    /**
     * @var list<string>
     */
    private readonly array $vendorDirs;

    public function __construct(
        bool $enabled,
    )
    {
        $this->enabled = $enabled;
        $this->vendorDirs = array_keys(ClassLoader::getRegisteredLoaders());
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

    protected function shouldMarkPropertyAsRead(ReflectionProperty $property): ?VirtualUsageData
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->shouldMarkMemberAsUsed($property);
    }

    protected function shouldMarkPropertyAsWritten(ReflectionProperty $property): ?VirtualUsageData
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->shouldMarkMemberAsUsed($property);
    }

    /**
     * @param ReflectionMethod|ReflectionClassConstant|ReflectionProperty $member
     */
    private function shouldMarkMemberAsUsed(Reflector $member): ?VirtualUsageData
    {
        $reflectionClass = $member->getDeclaringClass();
        $memberString = ucfirst(ReflectionHelper::getMemberType($member));
        $usage = VirtualUsageData::withNote($memberString . ' overrides vendor one, thus is expected to be used by vendor code');

        do {
            if ($this->isForeignMember($reflectionClass, $member)) {
                return $usage;
            }

            foreach ($reflectionClass->getInterfaces() as $interface) {
                if ($this->isForeignMember($interface, $member)) {
                    return $usage;
                }
            }

            foreach ($reflectionClass->getTraits() as $trait) {
                if ($this->isForeignMember($trait, $member)) {
                    return $usage;
                }
            }

            $reflectionClass = $reflectionClass->getParentClass();
        } while ($reflectionClass !== false);

        return null;
    }

    /**
     * @param ReflectionMethod|ReflectionClassConstant|ReflectionProperty $member
     * @param ReflectionClass<object> $reflectionClass
     */
    private function isForeignMember(
        ReflectionClass $reflectionClass,
        Reflector $member,
    ): bool
    {
        if ($member instanceof ReflectionMethod && !$reflectionClass->hasMethod($member->getName())) {
            return false;
        }

        if ($member instanceof ReflectionClassConstant && !$reflectionClass->hasConstant($member->getName())) {
            return false;
        }

        if ($member instanceof ReflectionProperty && !$reflectionClass->hasProperty($member->getName())) {
            return false;
        }

        if ($reflectionClass->getExtensionName() !== false) {
            return false; // many built-in classes have stubs in PHPStan (with filepath in vendor); BuiltinUsageProvider will handle them
        }

        $filePath = $reflectionClass->getFileName();
        if ($filePath === false) {
            return false;
        }

        $pharPrefix = 'phar://';

        if (str_starts_with($filePath, $pharPrefix)) {
            /** @var string $filePath Cannot resolve to false */
            $filePath = substr($filePath, strlen($pharPrefix));
        }

        foreach ($this->vendorDirs as $vendorDir) {
            if (str_starts_with($filePath, $vendorDir)) {
                return true;
            }
        }

        return false;
    }

}
