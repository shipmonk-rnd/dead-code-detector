<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\TrinaryLogic;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Enum\ClassLikeKind;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Enum\Visibility;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Hierarchy\ClassHierarchy;
use ShipMonk\PHPStan\DeadCode\Processor\CollectedDataProcessor;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function min;

/**
 * @implements Rule<CollectedDataNode>
 */
final class UselessVisibilityRule implements Rule
{

    public const IDENTIFIER_USELESS_METHOD_VISIBILITY = 'shipmonk.uselessMethodVisibility';
    public const IDENTIFIER_USELESS_PROPERTY_VISIBILITY = 'shipmonk.uselessPropertyVisibility';
    public const IDENTIFIER_USELESS_CONSTANT_VISIBILITY = 'shipmonk.uselessConstantVisibility';

    private const ORIGIN_SELF = 1;
    private const ORIGIN_HIERARCHY = 2;
    private const ORIGIN_EXTERNAL = 3;

    private CollectedDataProcessor $processor;

    private ClassHierarchy $classHierarchy;

    private bool $detectUselessMethodVisibility;

    private bool $detectUselessPropertyVisibility;

    private bool $detectUselessConstantVisibility;

    /**
     * memberKey => list<ClassMemberUsage>
     *
     * @var array<string, list<ClassMemberUsage>>
     */
    private array $memberUsages = [];

    public function __construct(
        CollectedDataProcessor $processor,
        ClassHierarchy $classHierarchy,
        bool $detectUselessMethodVisibility,
        bool $detectUselessPropertyVisibility,
        bool $detectUselessConstantVisibility
    )
    {
        $this->processor = $processor;
        $this->classHierarchy = $classHierarchy;
        $this->detectUselessMethodVisibility = $detectUselessMethodVisibility;
        $this->detectUselessPropertyVisibility = $detectUselessPropertyVisibility;
        $this->detectUselessConstantVisibility = $detectUselessConstantVisibility;
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @param CollectedDataNode $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(
        Node $node,
        Scope $scope
    ): array
    {
        if ($node->isOnlyFilesAnalysis()) {
            return [];
        }

        if (
            !$this->detectUselessMethodVisibility
            && !$this->detectUselessPropertyVisibility
            && !$this->detectUselessConstantVisibility
        ) {
            return [];
        }

        // Reset state for new analysis
        $this->memberUsages = [];

        // Trigger shared processing
        $this->processor->processCollectedData($node);

        // Build memberUsages from known collected usages
        foreach ($this->processor->getKnownCollectedUsages() as $collectedUsage) {
            $memberUsage = $collectedUsage->getUsage();
            $accessType = $memberUsage->getAccessType();
            $alternativeMemberKeys = $this->processor->getAlternativeMemberKeys(
                $memberUsage->getMemberRef(),
                $accessType,
            );

            foreach ($alternativeMemberKeys as $key) {
                $this->memberUsages[$key][] = $memberUsage;
            }
        }

        // Run visibility analysis
        return $this->processUselessVisibility();
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processUselessVisibility(): array
    {
        $errors = [];
        $typeDefinitions = $this->processor->getTypeDefinitions();

        foreach ($typeDefinitions as $typeName => $typeDefinition) {
            $kind = $typeDefinition['kind'];
            $file = $typeDefinition['file'];

            // Skip interfaces entirely — all members must be public
            if ($kind === ClassLikeKind::INTERFACE) {
                continue;
            }

            if ($this->detectUselessMethodVisibility) {
                foreach ($typeDefinition['methods'] as $methodName => $methodData) {
                    $error = $this->checkMemberVisibility(
                        $typeName,
                        $methodName,
                        MemberType::METHOD,
                        $methodData['visibility'],
                        $file,
                        $methodData['line'],
                        $kind,
                        $methodData['abstract'],
                    );

                    if ($error !== null) {
                        $errors[] = $error;
                    }
                }
            }

            if ($this->detectUselessConstantVisibility) {
                foreach ($typeDefinition['constants'] as $constantName => $constantData) {
                    $error = $this->checkMemberVisibility(
                        $typeName,
                        $constantName,
                        MemberType::CONSTANT,
                        $constantData['visibility'],
                        $file,
                        $constantData['line'],
                        $kind,
                        false,
                    );

                    if ($error !== null) {
                        $errors[] = $error;
                    }
                }
            }

            if ($this->detectUselessPropertyVisibility) {
                foreach ($typeDefinition['properties'] as $propertyName => $propertyData) {
                    $error = $this->checkMemberVisibility(
                        $typeName,
                        $propertyName,
                        MemberType::PROPERTY,
                        $propertyData['visibility'],
                        $file,
                        $propertyData['line'],
                        $kind,
                        false,
                    );

                    if ($error !== null) {
                        $errors[] = $error;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param MemberType::* $memberType
     * @param int-mask-of<Visibility::*> $rawVisibility
     */
    private function checkMemberVisibility(
        string $typeName,
        string $memberName,
        int $memberType,
        int $rawVisibility,
        string $file,
        int $line,
        string $kind,
        bool $abstract
    ): ?IdentifierRuleError
    {
        $currentVisibility = $this->getEffectiveVisibility($rawVisibility);

        // Skip private members — already at maximum restriction
        if ($currentVisibility === Visibility::PRIVATE) {
            return null;
        }

        // Skip abstract trait methods
        if ($kind === ClassLikeKind::TRAIT && $abstract) {
            return null;
        }

        // Skip members that implement/override an interface member → must be public
        if ($this->hasInterfaceMember($typeName, $memberName, $memberType)) {
            return null;
        }

        // Build the member keys and check if it has any usages
        $memberKeys = $this->getMemberKeysForVisibilityCheck($typeName, $memberName, $memberType);

        // Skip members with zero usages — they are likely dead (DeadCodeRule handles them)
        $hasAnyUsage = false;

        foreach ($memberKeys as $key) {
            if (isset($this->memberUsages[$key]) && $this->memberUsages[$key] !== []) {
                $hasAnyUsage = true;
                break;
            }
        }

        if (!$hasAnyUsage) {
            return null;
        }

        // Determine parent visibility floor
        $parentVisibility = null;

        if ($kind !== ClassLikeKind::TRAIT) {
            $parentVisibility = $this->getParentMemberVisibility($typeName, $memberName, $memberType);
        }

        // Collect all usages for this member across all keys
        $allUsages = [];

        foreach ($memberKeys as $key) {
            foreach ($this->memberUsages[$key] ?? [] as $usage) {
                $allUsages[] = $usage;
            }
        }

        // Determine the broadest access level needed
        $maxOrigin = self::ORIGIN_SELF; // default: if no usages, member can be private

        foreach ($allUsages as $usage) {
            $origin = $this->classifyUsageOrigin($usage, $typeName);

            if ($origin === self::ORIGIN_EXTERNAL) {
                $maxOrigin = self::ORIGIN_EXTERNAL;
                break; // can't get broader than external
            }

            if ($origin === self::ORIGIN_HIERARCHY && $maxOrigin < self::ORIGIN_HIERARCHY) {
                $maxOrigin = self::ORIGIN_HIERARCHY;
            }
        }

        // Map origin to required visibility
        $requiredVisibility = match ($maxOrigin) {
            self::ORIGIN_EXTERNAL => Visibility::PUBLIC,
            self::ORIGIN_HIERARCHY => Visibility::PROTECTED,
            self::ORIGIN_SELF => Visibility::PRIVATE,
        };

        // Apply parent visibility floor
        if ($parentVisibility !== null) {
            $requiredVisibility = $this->maxVisibility($requiredVisibility, $parentVisibility);
        }

        // If any descendant overrides this member, it needs to be at least protected
        if ($requiredVisibility === Visibility::PRIVATE && $this->hasDescendantOverride($typeName, $memberName, $memberType)) {
            $requiredVisibility = Visibility::PROTECTED;
        }

        // Check if current visibility is broader than needed
        if ($this->isVisibilityBroader($currentVisibility, $requiredVisibility)) {
            return $this->buildVisibilityError(
                $typeName,
                $memberName,
                $memberType,
                $currentVisibility,
                $requiredVisibility,
                $file,
                $line,
            );
        }

        return null;
    }

    /**
     * @param MemberType::* $memberType
     * @return list<string>
     */
    private function getMemberKeysForVisibilityCheck(
        string $typeName,
        string $memberName,
        int $memberType
    ): array
    {
        $memberRef = $this->createMemberRef($typeName, $memberName, $memberType);
        $memberKeys = $memberRef->toKeys(AccessType::READ);

        // For properties, also check WRITE key
        if ($memberType === MemberType::PROPERTY) {
            $memberKeys = array_merge($memberKeys, $memberRef->toKeys(AccessType::WRITE));
        }

        return $memberKeys;
    }

    /**
     * @param int-mask-of<Visibility::*> $visibility
     * @return Visibility::PUBLIC|Visibility::PROTECTED|Visibility::PRIVATE
     */
    private function getEffectiveVisibility(int $visibility): int
    {
        if (($visibility & Visibility::PRIVATE) !== 0) {
            return Visibility::PRIVATE;
        }

        if (($visibility & Visibility::PROTECTED) !== 0) {
            return Visibility::PROTECTED;
        }

        return Visibility::PUBLIC; // default, also covers visibility=0
    }

    /**
     * @return self::ORIGIN_SELF|self::ORIGIN_HIERARCHY|self::ORIGIN_EXTERNAL
     */
    private function classifyUsageOrigin(
        ClassMemberUsage $usage,
        string $definingClassName
    ): int
    {
        $originClass = $usage->getOrigin()->getClassName();

        // Out-of-class scope (global functions, top-level code)
        if ($originClass === null) {
            return self::ORIGIN_EXTERNAL;
        }

        // Anonymous class is its own thing
        if ($this->processor->isAnonymousClass($originClass)) {
            return self::ORIGIN_EXTERNAL;
        }

        // Usage from unsupported magic method → treat as external (unknowable)
        if (array_key_exists((string) $usage->getOrigin()->getMemberName(), CollectedDataProcessor::UNSUPPORTED_MAGIC_METHODS)) {
            return self::ORIGIN_EXTERNAL;
        }

        // Same class
        if ($originClass === $definingClassName) {
            return self::ORIGIN_SELF;
        }

        // Defining class is a trait → check if origin is a host class
        $typeDefinitions = $this->processor->getTypeDefinitions();
        $definingKind = $typeDefinitions[$definingClassName]['kind'] ?? null;

        if ($definingKind === ClassLikeKind::TRAIT) {
            return $this->classifyUsageOriginForTrait($originClass, $definingClassName, $usage);
        }

        // Origin is a parent of defining class
        if (array_key_exists($originClass, $typeDefinitions[$definingClassName]['parents'] ?? [])) {
            return self::ORIGIN_HIERARCHY;
        }

        // Origin extends the defining class
        if (array_key_exists($definingClassName, $typeDefinitions[$originClass]['parents'] ?? [])) {
            return self::ORIGIN_HIERARCHY;
        }

        return self::ORIGIN_EXTERNAL;
    }

    /**
     * @return self::ORIGIN_SELF|self::ORIGIN_HIERARCHY|self::ORIGIN_EXTERNAL
     */
    private function classifyUsageOriginForTrait(
        string $originClass,
        string $traitName,
        ClassMemberUsage $usage
    ): int
    {
        $memberName = $usage->getMemberRef()->getMemberName();
        $memberType = $usage->getMemberType();
        $traitMembers = $this->processor->getTraitMembers();
        $typeDefinitions = $this->processor->getTypeDefinitions();

        // Find all "host classes" that directly use this trait for this member
        $hostClasses = [];

        foreach ($traitMembers[$memberType] ?? [] as $hostClass => $traitsUsed) {
            if (isset($traitsUsed[$traitName])) {
                foreach ($traitsUsed[$traitName] as $aliasedName => $originalName) {
                    if ($originalName === $memberName || $aliasedName === $memberName) {
                        $hostClasses[] = $hostClass;
                        break;
                    }
                }
            }
        }

        foreach ($hostClasses as $hostClass) {
            // Origin IS the host class → SELF (trait method is part of this class)
            if ($originClass === $hostClass) {
                return self::ORIGIN_SELF;
            }

            // Origin is in the extends hierarchy of a host class
            if (array_key_exists($originClass, $typeDefinitions[$hostClass]['parents'] ?? [])) {
                return self::ORIGIN_HIERARCHY;
            }

            if (array_key_exists($hostClass, $typeDefinitions[$originClass]['parents'] ?? [])) {
                return self::ORIGIN_HIERARCHY;
            }
        }

        return self::ORIGIN_EXTERNAL;
    }

    /**
     * @param MemberType::* $memberType
     * @return Visibility::PUBLIC|Visibility::PROTECTED|Visibility::PRIVATE|null
     */
    private function getParentMemberVisibility(
        string $className,
        string $memberName,
        int $memberType
    ): ?int
    {
        $typeDefinitions = $this->processor->getTypeDefinitions();
        $parentNames = array_keys($typeDefinitions[$className]['parents'] ?? []);
        $typeDefKey = $this->getTypeDefKeyForMemberType($memberType);

        foreach ($parentNames as $parentName) {
            $parentMemberData = $typeDefinitions[$parentName][$typeDefKey][$memberName] ?? null;

            if ($parentMemberData !== null) {
                return $this->getEffectiveVisibility($parentMemberData['visibility']);
            }
        }

        return null;
    }

    /**
     * @param MemberType::* $memberType
     * @return 'methods'|'constants'|'properties'
     */
    private function getTypeDefKeyForMemberType(int $memberType): string
    {
        if ($memberType === MemberType::METHOD) {
            return 'methods';
        }

        if ($memberType === MemberType::CONSTANT) {
            return 'constants';
        }

        return 'properties';
    }

    /**
     * @param MemberType::* $memberType
     */
    private function hasInterfaceMember(
        string $className,
        string $memberName,
        int $memberType
    ): bool
    {
        $typeDefinitions = $this->processor->getTypeDefinitions();
        $interfaces = $typeDefinitions[$className]['interfaces'] ?? [];
        $typeDefKey = $this->getTypeDefKeyForMemberType($memberType);

        foreach ($interfaces as $interfaceName => $unused) {
            if (isset($typeDefinitions[$interfaceName][$typeDefKey][$memberName])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param MemberType::* $memberType
     */
    private function hasDescendantOverride(
        string $className,
        string $memberName,
        int $memberType
    ): bool
    {
        $descendants = $this->classHierarchy->getClassDescendants($className);
        $typeDefinitions = $this->processor->getTypeDefinitions();
        $typeDefKey = $this->getTypeDefKeyForMemberType($memberType);

        foreach ($descendants as $descendant) {
            if (isset($typeDefinitions[$descendant][$typeDefKey][$memberName])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Visibility::PUBLIC|Visibility::PROTECTED|Visibility::PRIVATE $a
     * @param Visibility::PUBLIC|Visibility::PROTECTED|Visibility::PRIVATE $b
     * @return Visibility::PUBLIC|Visibility::PROTECTED|Visibility::PRIVATE
     */
    private function maxVisibility(
        int $a,
        int $b
    ): int
    {
        // PUBLIC(1) < PROTECTED(2) < PRIVATE(4) numerically
        // "broader" means lower numeric value
        return min($a, $b);
    }

    /**
     * Returns true if $current is broader than $required.
     *
     * @param Visibility::PUBLIC|Visibility::PROTECTED|Visibility::PRIVATE $current
     * @param Visibility::PUBLIC|Visibility::PROTECTED|Visibility::PRIVATE $required
     */
    private function isVisibilityBroader(
        int $current,
        int $required
    ): bool
    {
        // PUBLIC(1) < PROTECTED(2) < PRIVATE(4) numerically
        return $current < $required;
    }

    /**
     * @param MemberType::* $memberType
     * @return ClassMemberRef<string, string>
     */
    private function createMemberRef(
        string $typeName,
        string $memberName,
        int $memberType
    ): ClassMemberRef
    {
        return match ($memberType) {
            MemberType::METHOD => new ClassMethodRef($typeName, $memberName, false),
            MemberType::CONSTANT => new ClassConstantRef($typeName, $memberName, false, TrinaryLogic::createNo()),
            MemberType::PROPERTY => new ClassPropertyRef($typeName, $memberName, false),
        };
    }

    /**
     * @param MemberType::* $memberType
     * @param Visibility::PUBLIC|Visibility::PROTECTED|Visibility::PRIVATE $currentVisibility
     * @param Visibility::PUBLIC|Visibility::PROTECTED|Visibility::PRIVATE $requiredVisibility
     */
    private function buildVisibilityError(
        string $typeName,
        string $memberName,
        int $memberType,
        int $currentVisibility,
        int $requiredVisibility,
        string $file,
        int $line
    ): IdentifierRuleError
    {
        $currentLabel = $this->visibilityLabel($currentVisibility);
        $requiredLabel = $this->visibilityLabel($requiredVisibility);

        $dollar = $memberType === MemberType::PROPERTY ? '$' : '';
        $memberHuman = "{$typeName}::{$dollar}{$memberName}";

        $kindName = match ($memberType) {
            MemberType::METHOD => 'Method',
            MemberType::CONSTANT => 'Constant',
            MemberType::PROPERTY => 'Property',
        };

        $identifier = match ($memberType) {
            MemberType::METHOD => self::IDENTIFIER_USELESS_METHOD_VISIBILITY,
            MemberType::CONSTANT => self::IDENTIFIER_USELESS_CONSTANT_VISIBILITY,
            MemberType::PROPERTY => self::IDENTIFIER_USELESS_PROPERTY_VISIBILITY,
        };

        return RuleErrorBuilder::message("{$kindName} {$memberHuman} has useless {$currentLabel} visibility (can be {$requiredLabel})")
            ->file($file)
            ->line($line)
            ->identifier($identifier)
            ->build();
    }

    /**
     * @param Visibility::PUBLIC|Visibility::PROTECTED|Visibility::PRIVATE $visibility
     */
    private function visibilityLabel(int $visibility): string
    {
        return match ($visibility) {
            Visibility::PUBLIC => 'public',
            Visibility::PROTECTED => 'protected',
            Visibility::PRIVATE => 'private',
        };
    }

}
