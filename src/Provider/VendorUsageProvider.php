<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use Reflector;
use function array_keys;
use function strlen;
use function strpos;
use function substr;

class VendorUsageProvider extends ReflectionBasedMemberUsageProvider
{

    /**
     * @var list<string>
     */
    private array $vendorDirs;

    private bool $enabled;

    public function __construct(bool $enabled)
    {
        $this->vendorDirs = array_keys(ClassLoader::getRegisteredLoaders());
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
        $memberString = $member instanceof ReflectionMethod ? 'Method' : 'Constant';
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
     * @param ReflectionMethod|ReflectionClassConstant $member
     * @param ReflectionClass<object> $reflectionClass
     */
    private function isForeignMember(
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

        if ($reflectionClass->getExtensionName() !== false) {
            return false; // many built-in classes have stubs in PHPStan (with filepath in vendor); BuiltinUsageProvider will handle them
        }

        $filePath = $reflectionClass->getFileName();
        if ($filePath === false) {
            return false;
        }

        $pharPrefix = 'phar://';

        if (strpos($filePath, $pharPrefix) === 0) {
            /** @var string $filePath Cannot resolve to false */
            $filePath = substr($filePath, strlen($pharPrefix));
        }

        foreach ($this->vendorDirs as $vendorDir) {
            if (strpos($filePath, $vendorDir) === 0) {
                return true;
            }
        }

        return false;
    }

}
