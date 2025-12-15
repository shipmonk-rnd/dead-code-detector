<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\File\RelativePathHelper;
use PHPStan\Reflection\ReflectionProvider;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Excluder\MemberUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyUsage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use function get_class;
use function sprintf;

/**
 * @implements Collector<Node, list<string>>
 */
final class ProvidedUsagesCollector implements Collector
{

    use BufferedUsageCollector;

    private RelativePathHelper $relativePathHelper;

    private ReflectionProvider $reflectionProvider;

    /**
     * @var list<MemberUsageProvider>
     */
    private array $memberUsageProviders;

    /**
     * @var list<MemberUsageExcluder>
     */
    private array $memberUsageExcluders;

    /**
     * @param list<MemberUsageProvider> $memberUsageProviders
     * @param list<MemberUsageExcluder> $memberUsageExcluders
     */
    public function __construct(
        RelativePathHelper $relativePathHelper,
        ReflectionProvider $reflectionProvider,
        array $memberUsageProviders,
        array $memberUsageExcluders
    )
    {
        $this->relativePathHelper = $relativePathHelper;
        $this->reflectionProvider = $reflectionProvider;
        $this->memberUsageProviders = $memberUsageProviders;
        $this->memberUsageExcluders = $memberUsageExcluders;
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return non-empty-list<string>|null
     */
    public function processNode(
        Node $node,
        Scope $scope
    ): ?array
    {
        foreach ($this->memberUsageProviders as $memberUsageProvider) {
            $newUsages = $memberUsageProvider->getUsages($node, $scope);

            foreach ($newUsages as $newUsage) {
                $collectedUsage = $this->resolveExclusion($newUsage, $node, $scope);

                $this->validateUsage($newUsage, $memberUsageProvider, $node, $scope);
                $this->usages[] = $collectedUsage;

                foreach ($this->getDerivedUsages($memberUsageProvider, $collectedUsage) as $derivedUsage) {
                    $derivedCollectedUsage = $this->resolveExclusion($derivedUsage, $node, $scope);
                    $this->usages[] = $derivedCollectedUsage;
                }
            }
        }

        return $this->emitUsages($scope);
    }

    private function validateUsage(
        ClassMemberUsage $usage,
        MemberUsageProvider $provider,
        Node $node,
        Scope $scope
    ): void
    {
        $origin = $usage->getOrigin();
        $originClass = $origin->getClassName();
        $originMethod = $origin->getMemberName();

        $context = sprintf(
            "It emitted usage of %s by %s for node '%s' in '%s' on line %s",
            $usage->getMemberRef()->toHumanString(),
            get_class($provider),
            get_class($node),
            $scope->getFile(),
            $node->getStartLine(),
        );

        if ($originClass !== null) {
            if (!$this->reflectionProvider->hasClass($originClass)) {
                throw new LogicException("Class '{$originClass}' does not exist. $context");
            }

            if ($originMethod !== null && !$this->reflectionProvider->getClass($originClass)->hasMethod($originMethod)) {
                throw new LogicException("Method '{$originMethod}' does not exist in class '$originClass'. $context");
            }
        }
    }

    private function resolveExclusion(
        ClassMemberUsage $usage,
        Node $node,
        Scope $scope
    ): CollectedUsage
    {
        $excluderName = null;

        foreach ($this->memberUsageExcluders as $excludedUsageDecider) {
            if ($excludedUsageDecider->shouldExclude($usage, $node, $scope)) {
                $excluderName = $excludedUsageDecider->getIdentifier();
                break;
            }
        }

        return new CollectedUsage($usage, $excluderName);
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function getDerivedUsages(
        MemberUsageProvider $originalProvider,
        CollectedUsage $collectedUsage
    ): array
    {
        $derivedUsages = [];
        $usage = $collectedUsage->getUsage();
        $memberRef = $usage->getMemberRef();
        if (
            $memberRef->getMemberType() === MemberType::METHOD
            && $memberRef->getMemberName() === '__construct'
        ) {
            if ($memberRef->getClassName() === null) {
                return []; // TODO how to ensure all promoted properties are considered written?
            }

            if (!$this->reflectionProvider->hasClass($memberRef->getClassName())) {
                return [];
            }

            $classReflection = $this->reflectionProvider->getClass($memberRef->getClassName());
            $constructor = $classReflection->getNativeReflection()->getConstructor();
            if ($constructor === null) {
                return [];
            }
            foreach ($constructor->getParameters() as $parameter) {
                if (!$parameter->isPromoted()) {
                    continue;
                }

                $originalNote = $this->getDerivedNote($usage->getOrigin());
                $derivedUsages[] = new ClassPropertyUsage(
                    UsageOrigin::createVirtual($originalProvider, VirtualUsageData::withNote('Derived from constructor usage from: ' . $originalNote)),
                    new ClassPropertyRef(
                        $memberRef->getClassName(),
                        $parameter->getName(),
                        $memberRef->isPossibleDescendant(),
                    ),
                    AccessType::WRITE,
                );
            }
        }

        return $derivedUsages;
    }

    private function getDerivedNote(UsageOrigin $origin): string
    {
        if ($origin->getNote() !== null) {
            return $origin->getNote();
        }

        if ($origin->getFile() !== null && $origin->getLine() !== null) {
            return $this->relativePathHelper->getRelativePath($origin->getFile()) . ':' . $origin->getLine();
        }

        throw new LogicException('Unable to determine derived note. Should not happen if UsageOrigin static constructors were used');
    }

}
