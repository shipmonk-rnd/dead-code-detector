<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Node\InClassNode;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function preg_match;
use function preg_match_all;
use function stripos;
use function strpos;

final class NetteTesterUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    public function __construct(?bool $enabled)
    {
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('nette/tester');
    }

    public function getUsages(
        Node $node,
        Scope $scope
    ): array
    {
        if (!$this->enabled || !$node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            return [];
        }

        $classReflection = $node->getClassReflection();

        if (!$classReflection->is('Tester\TestCase')) {
            return [];
        }

        $usages = [];
        $className = $classReflection->getName();

        foreach ($classReflection->getNativeReflection()->getMethods() as $method) {
            $methodName = $method->getName();

            if ($method->isPublic() && preg_match('#^test[A-Z0-9_]#', $methodName) === 1) {
                $usages[] = $this->createUsage($className, $methodName, 'Test method');
            }

            if ($methodName === 'setUp' || $methodName === 'tearDown') {
                $usages[] = $this->createUsage($className, $methodName, 'Lifecycle method');
            }

            foreach ($this->getDataProviderMethods($method, $className, $methodName) as $usage) {
                $usages[] = $usage;
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getDataProviderMethods(
        ReflectionMethod $method,
        string $className,
        string $methodName
    ): array
    {
        $docComment = $method->getDocComment();

        if ($docComment === false || stripos($docComment, '@dataprovider') === false) {
            return [];
        }

        $usages = [];

        if (preg_match_all('#@dataprovider\s+(\S+)#i', $docComment, $matches) !== 0) {
            foreach ($matches[1] as $providerName) {
                if (strpos($providerName, '.') !== false) {
                    continue; // file reference, not a method name
                }

                $usages[] = $this->createUsage($className, $providerName, "Data provider method, used by $methodName");
            }
        }

        return $usages;
    }

    private function createUsage(
        string $className,
        string $methodName,
        string $reason
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($reason)),
            new ClassMethodRef(
                $className,
                $methodName,
                false,
            ),
        );
    }

}
