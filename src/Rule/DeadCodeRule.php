<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Command\Output;
use PHPStan\Diagnose\DiagnoseExtension;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\TrinaryLogic;
use ShipMonk\PHPStan\DeadCode\Compatibility\BackwardCompatibilityChecker;
use ShipMonk\PHPStan\DeadCode\Debug\DebugUsagePrinter;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Enum\ClassLikeKind;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Enum\NeverReportedReason;
use ShipMonk\PHPStan\DeadCode\Enum\Visibility;
use ShipMonk\PHPStan\DeadCode\Error\BlackMember;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Processor\CollectedDataProcessor;
use function array_key_exists;
use function array_key_last;
use function array_map;
use function array_merge;
use function array_slice;
use function array_values;
use function ksort;

/**
 * @implements Rule<CollectedDataNode>
 */
final class DeadCodeRule implements Rule, DiagnoseExtension
{

    public const IDENTIFIER_METHOD = 'shipmonk.deadMethod';
    public const IDENTIFIER_CONSTANT = 'shipmonk.deadConstant';
    public const IDENTIFIER_ENUM_CASE = 'shipmonk.deadEnumCase';
    public const IDENTIFIER_PROPERTY_NEVER_READ = 'shipmonk.deadProperty.neverRead';
    public const IDENTIFIER_PROPERTY_NEVER_WRITTEN = 'shipmonk.deadProperty.neverWritten';

    private CollectedDataProcessor $processor;

    private DebugUsagePrinter $debugUsagePrinter;

    private bool $detectDeadMethods;

    private bool $detectDeadConstants;

    private bool $detectDeadEnumCases;

    private bool $detectNeverReadProperties;

    private bool $detectNeverWrittenProperties;

    private bool $reportTransitivelyDeadAsSeparateError;

    /**
     * memberKey => DeadMember
     *
     * @var array<string, BlackMember>
     */
    private array $blackMembers = [];

    /**
     * callerKey => array<calleeKey, usages[]>
     *
     * @var array<string, array<string, non-empty-list<ClassMemberUsage>>>
     */
    private array $usageGraph = [];

    public function __construct(
        CollectedDataProcessor $processor,
        DebugUsagePrinter $debugUsagePrinter,
        bool $detectDeadMethods,
        bool $detectDeadConstants,
        bool $detectDeadEnumCases,
        bool $detectNeverReadProperties,
        bool $detectNeverWrittenProperties,
        bool $reportTransitivelyDeadMethodAsSeparateError,
        BackwardCompatibilityChecker $checker
    )
    {
        $this->processor = $processor;
        $this->debugUsagePrinter = $debugUsagePrinter;
        $this->detectDeadMethods = $detectDeadMethods;
        $this->detectDeadConstants = $detectDeadConstants;
        $this->detectDeadEnumCases = $detectDeadEnumCases;
        $this->detectNeverReadProperties = $detectNeverReadProperties;
        $this->detectNeverWrittenProperties = $detectNeverWrittenProperties;
        $this->reportTransitivelyDeadAsSeparateError = $reportTransitivelyDeadMethodAsSeparateError;

        $checker->check();
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

        $this->blackMembers = [];
        $this->usageGraph = [];

        $this->processor->processCollectedData($node);

        $typeDefinitions = $this->processor->getTypeDefinitions();

        foreach ($typeDefinitions as $typeName => $typeDefinition) {
            $methods = $typeDefinition['methods'];
            $constants = $typeDefinition['constants'];
            $cases = $typeDefinition['cases'];
            $properties = $typeDefinition['properties'];
            $file = $typeDefinition['file'];

            foreach ($methods as $methodName => $methodData) {
                $methodRef = new ClassMethodRef($typeName, $methodName, false);
                $methodKeys = $methodRef->toKeys(AccessType::READ);

                if ($this->detectDeadMethods) {
                    foreach ($methodKeys as $methodKey) {
                        $this->blackMembers[$methodKey] = new BlackMember($methodRef, AccessType::READ, $file, $methodData['line']);
                    }
                }
            }

            foreach ($constants as $constantName => $constantData) {
                $constantRef = new ClassConstantRef($typeName, $constantName, false, TrinaryLogic::createNo());
                $constantKeys = $constantRef->toKeys(AccessType::READ);

                if ($this->detectDeadConstants) {
                    foreach ($constantKeys as $constantKey) {
                        $this->blackMembers[$constantKey] = new BlackMember($constantRef, AccessType::READ, $file, $constantData['line']);
                    }
                }
            }

            foreach ($cases as $enumCaseName => $enumCaseData) {
                $enumCaseRef = new ClassConstantRef($typeName, $enumCaseName, false, TrinaryLogic::createYes());
                $enumCaseKeys = $enumCaseRef->toKeys(AccessType::READ);

                if ($this->detectDeadEnumCases) {
                    foreach ($enumCaseKeys as $enumCaseKey) {
                        $this->blackMembers[$enumCaseKey] = new BlackMember($enumCaseRef, AccessType::READ, $file, $enumCaseData['line']);
                    }
                }
            }

            foreach ($properties as $propertyName => $propertyData) {
                $accessTypes = [];
                if ($this->detectNeverReadProperties) {
                    $accessTypes[] = AccessType::READ;
                }
                if (
                    $this->detectNeverWrittenProperties
                    && !$propertyData['default'] // consider properties with default values as written
                    && !($propertyData['virtual'] && !$propertyData['setHook']) // virtual properties without set hook cannot be written to
                ) {
                    $accessTypes[] = AccessType::WRITE;
                }
                foreach ($accessTypes as $accessType) {
                    $propertyRef = new ClassPropertyRef($typeName, $propertyName, false);
                    $propertyKeys = $propertyRef->toKeys($accessType);

                    foreach ($propertyKeys as $propertyKey) {
                        $this->blackMembers[$propertyKey] = new BlackMember($propertyRef, $accessType, $file, $propertyData['line']);
                    }
                }
            }
        }

        $this->debugUsagePrinter->markAnalysedMembers($this->blackMembers);

        return $this->processKnownCollectedUsages();
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processKnownCollectedUsages(): array
    {
        /** @var array<string, non-empty-list<ClassMemberUsage>> $whiteMembers */
        $whiteMembers = [];

        foreach ($this->processor->getKnownCollectedUsages() as $collectedUsage) {
            $memberUsage = $collectedUsage->getUsage();
            $accessType = $memberUsage->getAccessType();
            $isWhite = $this->isConsideredWhite($memberUsage);

            $alternativeMemberKeys = $this->processor->getAlternativeMemberKeys($memberUsage->getMemberRef(), $accessType);
            $alternativeOriginKeys = $memberUsage->getOrigin()->hasClassMemberRef()
                ? $this->processor->getAlternativeMemberKeys($memberUsage->getOrigin()->toClassMemberRef(), $memberUsage->getOrigin()->getAccessType())
                : [];

            foreach ($alternativeMemberKeys as $alternativeMemberKey) {
                foreach ($alternativeOriginKeys as $alternativeOriginKey) {
                    $this->usageGraph[$alternativeOriginKey][$alternativeMemberKey][] = $memberUsage;
                }

                if ($isWhite) {
                    $whiteMembers[$alternativeMemberKey][] = $collectedUsage->getUsage();
                }
            }

            $this->debugUsagePrinter->recordUsage($collectedUsage, $alternativeMemberKeys);
        }

        $visited = [];
        foreach ($whiteMembers as $whiteCalleeKey => $usages) {
            $this->markReachableAsWhite([$whiteCalleeKey => $usages], $visited, true);
        }
        unset($visited);

        foreach ($this->blackMembers as $blackMemberKey => $blackMember) {
            $neverReportedReason = $this->isNeverReportedAsDead($blackMember);

            if ($neverReportedReason !== null) {
                $this->debugUsagePrinter->markMemberAsNeverReported($blackMember, $neverReportedReason);

                unset($this->blackMembers[$blackMemberKey]);
            }
        }

        foreach ($this->processor->getExcludedCollectedUsages() as $excludedMemberUsage) {
            $excludedMemberRef = $excludedMemberUsage->getUsage()->getMemberRef();
            $accessType = $excludedMemberUsage->getUsage()->getAccessType();
            $alternativeExcludedMemberKeys = $this->processor->getAlternativeMemberKeys($excludedMemberRef, $accessType);

            foreach ($alternativeExcludedMemberKeys as $alternativeExcludedMemberKey) {
                if (!isset($this->blackMembers[$alternativeExcludedMemberKey])) {
                    continue;
                }

                $this->blackMembers[$alternativeExcludedMemberKey]->addExcludedUsage($excludedMemberUsage);
            }

            $this->debugUsagePrinter->recordUsage($excludedMemberUsage, $alternativeExcludedMemberKeys);
        }

        if ($this->reportTransitivelyDeadAsSeparateError) {
            $errorGroups = array_map(static fn (BlackMember $member): array => [$member], $this->blackMembers);
        } else {
            $errorGroups = $this->groupDeadMembers();
        }

        $errors = [];

        foreach ($errorGroups as $deadGroup) {
            $errors[] = $this->buildError($deadGroup);
        }

        return $errors;
    }

    /**
     * @param non-empty-array<string, non-empty-list<ClassMemberUsage>> $stack callerKey => usages[]
     * @param array<string, true> $visited
     */
    private function markReachableAsWhite(
        array $stack,
        array &$visited,
        bool $transitiveWalk
    ): void
    {
        $callerKey = array_key_last($stack);
        $callees = $this->usageGraph[$callerKey] ?? [];

        if (isset($this->blackMembers[$callerKey])) {
            $this->debugUsagePrinter->markMemberAsWhite($this->blackMembers[$callerKey], $stack);

            unset($this->blackMembers[$callerKey]);
        }

        $visited[$callerKey] = true;

        if (!$transitiveWalk) {
            return;
        }

        foreach ($callees as $calleeKey => $usages) {
            if (isset($visited[$calleeKey])) {
                continue;
            }

            $this->markReachableAsWhite(array_merge($stack, [$calleeKey => $usages]), $visited, $this->shouldPropagate($usages));
        }
    }

    /**
     * @param non-empty-list<ClassMemberUsage> $usages
     */
    private function shouldPropagate(array $usages): bool
    {
        return $usages[0]->isPropagating();
    }

    /**
     * @param array<string, null> $visitedKeys
     * @return list<string>
     */
    private function getTransitiveDeadCalls(
        string $callerKey,
        array $visitedKeys = []
    ): array
    {
        $visitedKeys = $visitedKeys === [] ? [$callerKey => null] : $visitedKeys;
        $callees = $this->usageGraph[$callerKey] ?? [];

        $result = [];

        foreach ($callees as $calleeKey => $calleeInfo) {
            if (array_key_exists($calleeKey, $visitedKeys)) {
                continue;
            }

            if (!isset($this->blackMembers[$calleeKey])) {
                continue;
            }

            if (!$this->shouldPropagate($calleeInfo)) {
                continue;
            }

            $result[] = $calleeKey;
            $visitedKeys[$calleeKey] = null;

            foreach ($this->getTransitiveDeadCalls($calleeKey, $visitedKeys) as $transitiveDead) {
                $result[] = $transitiveDead;
                $visitedKeys[$transitiveDead] = null;
            }
        }

        return $result;
    }

    /**
     * @return list<non-empty-list<BlackMember>>
     */
    private function groupDeadMembers(): array
    {
        ksort($this->blackMembers);

        $deadGroups = [];

        /** @var array<string, true> $deadMethodsWithCaller */
        $deadMethodsWithCaller = [];

        foreach ($this->usageGraph as $caller => $callees) {
            if (!array_key_exists($caller, $this->blackMembers)) {
                continue;
            }

            foreach ($callees as $callee => $calleeInfo) {
                if (array_key_exists($callee, $this->blackMembers) && $this->shouldPropagate($calleeInfo)) {
                    $deadMethodsWithCaller[$callee] = true;
                }
            }
        }

        $methodsGrouped = [];

        foreach ($this->blackMembers as $deadMemberKey => $blackMember) {
            if (isset($methodsGrouped[$deadMemberKey])) {
                continue;
            }

            if (isset($deadMethodsWithCaller[$deadMemberKey])) {
                continue; // has a caller, thus should be part of a group, not a group representative
            }

            $deadGroups[$deadMemberKey][$deadMemberKey] = $blackMember;
            $methodsGrouped[$deadMemberKey] = true;

            $transitiveMethodKeys = $this->getTransitiveDeadCalls($deadMemberKey);

            foreach ($transitiveMethodKeys as $transitiveMethodKey) {
                $deadGroups[$deadMemberKey][$transitiveMethodKey] = $this->blackMembers[$transitiveMethodKey]; // @phpstan-ignore offsetAccess.notFound
                $methodsGrouped[$transitiveMethodKey] = true;
            }
        }

        // now only cycles remain, lets pick group representatives based on first occurrence
        foreach ($this->blackMembers as $deadMemberKey => $blackMember) {
            if (isset($methodsGrouped[$deadMemberKey])) {
                continue;
            }

            $transitiveDeadMethods = $this->getTransitiveDeadCalls($deadMemberKey);

            $deadGroups[$deadMemberKey][$deadMemberKey] = $blackMember;
            $methodsGrouped[$deadMemberKey] = true;

            foreach ($transitiveDeadMethods as $transitiveDeadMethodKey) {
                $deadGroups[$deadMemberKey][$transitiveDeadMethodKey] = $this->blackMembers[$transitiveDeadMethodKey]; // @phpstan-ignore offsetAccess.notFound
                $methodsGrouped[$transitiveDeadMethodKey] = true;
            }
        }

        return array_map('array_values', array_values($deadGroups));
    }

    /**
     * @param non-empty-list<BlackMember> $blackMembersGroup
     */
    private function buildError(array $blackMembersGroup): IdentifierRuleError
    {
        $representative = $blackMembersGroup[0];

        $exclusionMessage = $representative->getExclusionMessage();
        $excludedUsages = $representative->getExcludedUsages();

        $mainErrorMessage = $this->buildMainErrorMessages($representative);

        $builder = RuleErrorBuilder::message("{$mainErrorMessage}{$exclusionMessage}")
            ->file($representative->getFile())
            ->line($representative->getLine())
            ->identifier($representative->getErrorIdentifier());

        $metadata = [];
        $metadata[] = [
            'blackMember' => $representative,
            'transitive' => false,
            'excludedUsages' => $excludedUsages,
        ];

        $tips = [];

        foreach (array_slice($blackMembersGroup, 1) as $transitivelyDeadMember) {
            $exclusionMessage = $transitivelyDeadMember->getExclusionMessage();
            $excludedUsages = $transitivelyDeadMember->getExcludedUsages();

            $tips[] = $this->buildTransitiveErrorMessages($transitivelyDeadMember) . $exclusionMessage;
            $metadata[] = [
                'blackMember' => $transitivelyDeadMember,
                'transitive' => true,
                'excludedUsages' => $excludedUsages,
            ];
        }

        $builder->metadata($metadata);

        ksort($tips);

        foreach ($tips as $tip) {
            $builder->addTip($tip);
        }

        return $builder->build();
    }

    private function buildMainErrorMessages(BlackMember $blackMember): string
    {
        $memberHumanString = $blackMember->getMember()->toHumanString();

        if ($blackMember->getMember()->getMemberType() === MemberType::PROPERTY) {
            if ($blackMember->getAccessType() === AccessType::READ) {
                return "Property {$memberHumanString} is never read";
            } else {
                return "Property {$memberHumanString} is never written";
            }
        } else {
            return "Unused {$memberHumanString}";
        }
    }

    private function buildTransitiveErrorMessages(BlackMember $blackMember): string
    {
        $memberHumanString = $blackMember->getMember()->toHumanString();

        if ($blackMember->getMember()->getMemberType() === MemberType::PROPERTY) {
            if ($blackMember->getAccessType() === AccessType::READ) {
                return "Thus {$memberHumanString} is transitively never read";
            } else {
                return "Thus {$memberHumanString} is transitively never written";
            }
        } else {
            return "Thus {$memberHumanString} is transitively unused";
        }
    }

    private function isConsideredWhite(ClassMemberUsage $memberUsage): bool
    {
        return $memberUsage->getOrigin()->getClassName() === null // out-of-class scope
            || $this->processor->isAnonymousClass($memberUsage->getOrigin()->getClassName())
            || (array_key_exists((string) $memberUsage->getOrigin()->getMemberName(), CollectedDataProcessor::UNSUPPORTED_MAGIC_METHODS));
    }

    /**
     * @return NeverReportedReason::*|null
     */
    private function isNeverReportedAsDead(BlackMember $blackMember): ?string
    {
        if (!$blackMember->getMember() instanceof ClassMethodRef) {
            return null;
        }

        $typeName = $blackMember->getMember()->getClassName();
        $memberName = $blackMember->getMember()->getMemberName();

        $typeDefinitions = $this->processor->getTypeDefinitions();

        $kind = $typeDefinitions[$typeName]['kind'] ?? null;
        $params = $typeDefinitions[$typeName]['methods'][$memberName]['params'] ?? 0;
        $abstract = $typeDefinitions[$typeName]['methods'][$memberName]['abstract'] ?? false;
        $visibility = $typeDefinitions[$typeName]['methods'][$memberName]['visibility'] ?? 0;

        if ($kind === ClassLikeKind::TRAIT && $abstract) {
            // abstract methods in traits make sense (not dead) only when called within the trait itself, but that is hard to detect for now, so lets ignore them completely
            // the difference from interface methods (or abstract methods) is that those methods can be called over the interface, but you cannot call method over trait
            return NeverReportedReason::ABSTRACT_TRAIT_METHOD;
        }

        if ($memberName === '__construct' && ($visibility & Visibility::PRIVATE) !== 0 && $params === 0) {
            // private constructors with zero parameters are often used to deny instantiation
            return NeverReportedReason::PRIVATE_CONSTRUCTOR_NO_PARAMS;
        }

        if (array_key_exists($memberName, CollectedDataProcessor::UNSUPPORTED_MAGIC_METHODS)) {
            return NeverReportedReason::UNSUPPORTED_MAGIC_METHOD;
        }

        return null;
    }

    public function print(Output $output): void
    {
        $this->debugUsagePrinter->printMixedMemberUsages($output, $this->processor->getMixedClassNameUsages());
        $this->debugUsagePrinter->printDebugMemberUsages($output, $this->processor->getTypeDefinitions());
    }

}
