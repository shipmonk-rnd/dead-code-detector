<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\Autoload\ClassLoader;
use LogicException;
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

    private const ORIGIN_VENDOR = 'vendor';
    private const ORIGIN_BUILTIN = 'builtin';

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

        do {
            $classForeignOrigin = $this->getForeignOrigin($reflectionClass, $member);
            if ($classForeignOrigin !== null) {
                return $this->createUsageNote($member, $classForeignOrigin);
            }

            foreach ($reflectionClass->getInterfaces() as $interface) {
                $interfaceForeignOrigin = $this->getForeignOrigin($interface, $member);
                if ($interfaceForeignOrigin !== null) {
                    return $this->createUsageNote($member, $interfaceForeignOrigin);
                }
            }

            foreach ($reflectionClass->getTraits() as $trait) {
                $traitForeignOrigin = $this->getForeignOrigin($trait, $member);
                if ($traitForeignOrigin !== null) {
                    return $this->createUsageNote($member, $traitForeignOrigin);
                }
            }

            $reflectionClass = $reflectionClass->getParentClass();
        } while ($reflectionClass !== false);

        return null;
    }

    /**
     * @param ReflectionMethod|ReflectionClassConstant $member
     * @param ReflectionClass<object> $reflectionClass
     * @return self::ORIGIN_*|null
     */
    private function getForeignOrigin(
        ReflectionClass $reflectionClass,
        Reflector $member
    ): ?string
    {
        if ($member instanceof ReflectionMethod && !$reflectionClass->hasMethod($member->getName())) {
            return null;
        }

        if ($member instanceof ReflectionClassConstant && !$reflectionClass->hasConstant($member->getName())) {
            return null;
        }

        if ($reflectionClass->getExtensionName() !== false) {
            return self::ORIGIN_BUILTIN;
        }

        $filePath = $reflectionClass->getFileName();

        if ($filePath === false) {
            return self::ORIGIN_BUILTIN;
        }

        $pharPrefix = 'phar://';

        if (strpos($filePath, $pharPrefix) === 0) {
            /** @var string $filePath Cannot resolve to false */
            $filePath = substr($filePath, strlen($pharPrefix));
        }

        foreach ($this->vendorDirs as $vendorDir) {
            if (strpos($filePath, $vendorDir) === 0) {
                return self::ORIGIN_VENDOR;
            }
        }

        return null;
    }

    /**
     * @param ReflectionMethod|ReflectionClassConstant $member
     * @param self::ORIGIN_* $foreignOrigin
     */
    private function createUsageNote(
        Reflector $member,
        string $foreignOrigin
    ): VirtualUsageData
    {
        $memberString = $member instanceof ReflectionMethod ? 'Method' : 'Constant';

        if ($foreignOrigin === self::ORIGIN_BUILTIN) {
            $append = 'thus is assumed to be used by some PHP code.';
        } elseif ($foreignOrigin === self::ORIGIN_VENDOR) {
            $append = 'thus is expected to be used by vendor code';
        } else {
            throw new LogicException('Unexpected foreign origin: ' . $foreignOrigin);
        }

        return VirtualUsageData::withNote("$memberString overrides $foreignOrigin one, $append");
    }

}
