<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Command\Output;
use PHPStan\Diagnose\DiagnoseExtension;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ShipMonk\PHPStan\DeadCode\Collector\ClassDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Collector\ConstantFetchCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Collector\ProvidedUsagesCollector;
use ShipMonk\PHPStan\DeadCode\Compatibility\BackwardCompatibilityChecker;
use ShipMonk\PHPStan\DeadCode\Enum\ClassLikeKind;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Enum\Visibility;
use ShipMonk\PHPStan\DeadCode\Error\BlackMember;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Hierarchy\ClassHierarchy;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_merge_recursive;
use function array_slice;
use function array_sum;
use function array_values;
use function in_array;
use function sprintf;
use function strpos;

/**
 * @implements Rule<CollectedDataNode>
 */
class DeadCodeRule implements Rule, DiagnoseExtension
{

    public const IDENTIFIER_METHOD = 'shipmonk.deadMethod';
    public const IDENTIFIER_CONSTANT = 'shipmonk.deadConstant';

    private const UNSUPPORTED_MAGIC_METHODS = [
        '__invoke' => null,
        '__toString' => null,
        '__destruct' => null,
        '__call' => null,
        '__callStatic' => null,
        '__get' => null,
        '__set' => null,
        '__isset' => null,
        '__unset' => null,
        '__sleep' => null,
        '__wakeup' => null,
        '__serialize' => null,
        '__unserialize' => null,
        '__set_state' => null,
        '__debugInfo' => null,
    ];

    private ClassHierarchy $classHierarchy;

    /**
     * typename => data
     *
     * @var array<string, array{
     *      kind: string,
     *      name: string,
     *      file: string,
     *      constants: array<string, array{line: int}>,
     *      methods: array<string, array{line: int, abstract: bool, visibility: int-mask-of<Visibility::*>}>,
     *      parents: array<string, null>,
     *      traits: array<string, array{excluded?: list<string>, aliases?: array<string, string>}>,
     *      interfaces: array<string, null>
     * }>
     */
    private array $typeDefinitions = [];

    /**
     * @var array<string, list<string>>
     */
    private array $memberAlternativesCache = [];

    private bool $reportTransitivelyDeadAsSeparateError;

    private bool $trackMixedAccess;

    /**
     * memberKey => DeadMember
     *
     * @var array<string, BlackMember>
     */
    private array $blackMembers = [];

    /**
     * memberType => [memberName => ClassMemberUse[]]
     *
     * @var array<MemberType::*, array<string, list<ClassMemberUsage>>>
     */
    private array $mixedMemberUses = [];

    /**
     * @var array<string, list<string>> callerKey => memberUseKey[]
     */
    private array $usageGraph = [];

    public function __construct(
        ClassHierarchy $classHierarchy,
        bool $reportTransitivelyDeadMethodAsSeparateError,
        bool $trackMixedAccess,
        BackwardCompatibilityChecker $checker
    )
    {
        $this->classHierarchy = $classHierarchy;
        $this->reportTransitivelyDeadAsSeparateError = $reportTransitivelyDeadMethodAsSeparateError;
        $this->trackMixedAccess = $trackMixedAccess;

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

        /** @var list<ClassMemberUsage> $memberUses */
        $memberUses = [];

        $methodDeclarationData = $node->get(ClassDefinitionCollector::class);
        $methodCallData = $node->get(MethodCallCollector::class);
        $constFetchData = $node->get(ConstantFetchCollector::class);
        $providedUsagesData = $node->get(ProvidedUsagesCollector::class);

        /** @var array<string, list<list<string>>> $memberUseData */
        $memberUseData = array_merge_recursive($methodCallData, $providedUsagesData, $constFetchData);
        unset($methodCallData, $providedUsagesData, $constFetchData);

        foreach ($memberUseData as $usesPerFile) {
            foreach ($usesPerFile as $useStrings) {
                foreach ($useStrings as $useString) {
                    $memberUse = ClassMemberUsage::deserialize($useString);

                    if ($memberUse->getMemberRef()->getClassName() === null) {
                        $this->mixedMemberUses[$memberUse->getMemberType()][$memberUse->getMemberRef()->getMemberName()][] = $memberUse;
                        continue;
                    }

                    $memberUses[] = $memberUse;
                }
            }
        }

        foreach ($methodDeclarationData as $file => $data) {
            foreach ($data as $typeData) {
                $typeName = $typeData['name'];
                $this->typeDefinitions[$typeName] = [
                    'kind' => $typeData['kind'],
                    'name' => $typeName,
                    'file' => $file,
                    'constants' => $typeData['constants'],
                    'methods' => $typeData['methods'],
                    'parents' => $typeData['parents'],
                    'traits' => $typeData['traits'],
                    'interfaces' => $typeData['interfaces'],
                ];
            }
        }

        unset($methodDeclarationData);

        foreach ($this->typeDefinitions as $typeName => $typeDefinition) {
            $methods = $typeDefinition['methods'];
            $constants = $typeDefinition['constants'];
            $file = $typeDefinition['file'];

            $ancestorNames = $this->getAncestorNames($typeName);

            $this->fillTraitMethodUsages($typeName, $this->getTraitUsages($typeName), $this->getTypeMethods($typeName));
            $this->fillTraitConstantUsages($typeName, $this->getTraitUsages($typeName), $this->getTypeConstants($typeName));
            $this->fillClassHierarchy($typeName, $ancestorNames);

            foreach ($methods as $methodName => $methodData) {
                $methodRef = new ClassMethodRef($typeName, $methodName, false);
                $methodKey = $methodRef->toKey();

                $this->blackMembers[$methodKey] = new BlackMember($methodRef, $file, $methodData['line']);

                foreach ($this->mixedMemberUses[MemberType::METHOD][$methodName] ?? [] as $originalCall) {
                    $memberUses[] = new ClassMethodUsage(
                        $originalCall->getOrigin(),
                        new ClassMethodRef($typeName, $methodName, $originalCall->getMemberRef()->isPossibleDescendant()),
                    );
                }
            }

            foreach ($constants as $constantName => $constantData) {
                $constantRef = new ClassConstantRef($typeName, $constantName, false);
                $constantKey = $constantRef->toKey();

                $this->blackMembers[$constantKey] = new BlackMember($constantRef, $file, $constantData['line']);

                foreach ($this->mixedMemberUses[MemberType::CONSTANT][$constantName] ?? [] as $originalFetch) {
                    $memberUses[] = new ClassConstantUsage(
                        $originalFetch->getOrigin(),
                        new ClassConstantRef($typeName, $constantName, $originalFetch->getMemberRef()->isPossibleDescendant()),
                    );
                }
            }
        }

        $whiteMemberKeys = [];

        foreach ($memberUses as $memberUse) {
            $isWhite = $this->isConsideredWhite($memberUse);

            $alternativeMemberKeys = $this->getAlternativeMemberKeys($memberUse->getMemberRef());
            $alternativeOriginKeys = $memberUse->getOrigin() !== null ? $this->getAlternativeMemberKeys($memberUse->getOrigin()) : [];

            foreach ($alternativeMemberKeys as $alternativeMemberKey) {
                foreach ($alternativeOriginKeys as $alternativeOriginKey) {
                    $this->usageGraph[$alternativeOriginKey][] = $alternativeMemberKey;
                }

                if ($isWhite) {
                    $whiteMemberKeys[] = $alternativeMemberKey;
                }
            }
        }

        foreach ($whiteMemberKeys as $whiteCalleeKey) {
            $this->markTransitivesWhite($whiteCalleeKey);
        }

        foreach ($this->blackMembers as $blackMemberKey => $blackMember) {
            if ($this->isNeverReportedAsDead($blackMember)) {
                unset($this->blackMembers[$blackMemberKey]);
            }
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
     * @param array<string, array{excluded?: list<string>, aliases?: array<string, string>}> $usedTraits
     * @param list<string> $overriddenMethods
     */
    private function fillTraitMethodUsages(string $typeName, array $usedTraits, array $overriddenMethods): void
    {
        foreach ($usedTraits as $traitName => $adaptations) {
            $traitMethods = $this->typeDefinitions[$traitName]['methods'] ?? [];

            $excludedMethods = array_merge(
                $overriddenMethods,
                $adaptations['excluded'] ?? [],
            );

            foreach ($traitMethods as $traitMethod => $traitMethodData) {
                $declaringTraitMethodDefinition = ClassMethodRef::buildKey($traitName, $traitMethod);
                $aliasMethodName = $adaptations['aliases'][$traitMethod] ?? null;

                // both method names need to work
                if ($aliasMethodName !== null) {
                    $aliasMethodDefinition = ClassMethodRef::buildKey($typeName, $aliasMethodName);
                    $this->classHierarchy->registerTraitUsage($declaringTraitMethodDefinition, $aliasMethodDefinition);
                }

                if (in_array($traitMethod, $excludedMethods, true)) {
                    continue; // was replaced by insteadof
                }

                $overriddenMethods[] = $traitMethod;
                $usedTraitMethodDefinition = ClassMethodRef::buildKey($typeName, $traitMethod);
                $this->classHierarchy->registerTraitUsage($declaringTraitMethodDefinition, $usedTraitMethodDefinition);
            }

            $this->fillTraitMethodUsages($typeName, $this->getTraitUsages($traitName), $overriddenMethods);
        }
    }

    /**
     * @param array<string, array{excluded?: list<string>, aliases?: array<string, string>}> $usedTraits
     * @param list<string> $overriddenConstants
     */
    private function fillTraitConstantUsages(string $typeName, array $usedTraits, array $overriddenConstants): void
    {
        foreach ($usedTraits as $traitName => $_) {
            $traitConstants = $this->typeDefinitions[$traitName]['constants'] ?? [];

            $excludedConstants = $overriddenConstants;

            foreach ($traitConstants as $traitConstant => $__) {
                $declaringTraitConstantKey = ClassConstantRef::buildKey($traitName, $traitConstant);

                if (in_array($traitConstant, $excludedConstants, true)) {
                    continue;
                }

                $overriddenConstants[] = $traitConstant;
                $traitUserConstantKey = ClassConstantRef::buildKey($typeName, $traitConstant);
                $this->classHierarchy->registerTraitUsage($declaringTraitConstantKey, $traitUserConstantKey);
            }

            $this->fillTraitConstantUsages($typeName, $this->getTraitUsages($traitName), $overriddenConstants);
        }
    }

    /**
     * @param list<string> $ancestorNames
     */
    private function fillClassHierarchy(string $typeName, array $ancestorNames): void
    {
        foreach ($ancestorNames as $ancestorName) {
            $this->classHierarchy->registerClassPair($ancestorName, $typeName);
        }
    }

    private function isAnonymousClass(?string $className): bool
    {
        // https://github.com/phpstan/phpstan/issues/8410 workaround, ideally this should not be ignored
        return $className !== null && strpos($className, 'AnonymousClass') === 0;
    }

    /**
     * @return list<string>
     */
    private function getAlternativeMemberKeys(ClassMemberRef $member): array
    {
        if ($member->getClassName() === null) {
            throw new LogicException('Those were eliminated above, should never happen');
        }

        $memberKey = $member->toKey();
        $possibleDescendant = $member->isPossibleDescendant();
        $cacheKey = $memberKey . ';' . ($possibleDescendant ? '1' : '0');

        if (isset($this->memberAlternativesCache[$cacheKey])) {
            return $this->memberAlternativesCache[$cacheKey];
        }

        $result = [$memberKey];

        if ($possibleDescendant) {
            foreach ($this->classHierarchy->getClassDescendants($member->getClassName()) as $descendantName) {
                $result[] = $member::buildKey($descendantName, $member->getMemberName());
            }
        }

        // each descendant can be a trait user
        foreach ($result as $resultKey) {
            $traitMethodKey = $this->classHierarchy->getDeclaringTraitMemberKey($resultKey);

            if ($traitMethodKey !== null) {
                $result[] = $traitMethodKey;
            }
        }

        $this->memberAlternativesCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @param array<string, null> $visitedKeys
     */
    private function markTransitivesWhite(string $callerKey, array $visitedKeys = []): void
    {
        $visitedKeys = $visitedKeys === [] ? [$callerKey => null] : $visitedKeys;
        $calleeKeys = $this->usageGraph[$callerKey] ?? [];

        unset($this->blackMembers[$callerKey]);

        foreach ($calleeKeys as $calleeKey) {
            if (array_key_exists($calleeKey, $visitedKeys)) {
                continue;
            }

            if (!isset($this->blackMembers[$calleeKey])) {
                continue;
            }

            $this->markTransitivesWhite($calleeKey, array_merge($visitedKeys, [$calleeKey => null]));
        }
    }

    /**
     * @param array<string, null> $visitedKeys
     * @return list<string>
     */
    private function getTransitiveDeadCalls(string $callerKey, array $visitedKeys = []): array
    {
        $visitedKeys = $visitedKeys === [] ? [$callerKey => null] : $visitedKeys;
        $calleeKeys = $this->usageGraph[$callerKey] ?? [];

        $result = [];

        foreach ($calleeKeys as $calleeKey) {
            if (array_key_exists($calleeKey, $visitedKeys)) {
                continue;
            }

            if (!isset($this->blackMembers[$calleeKey])) {
                continue;
            }

            $result[] = $calleeKey;

            foreach ($this->getTransitiveDeadCalls($calleeKey, array_merge($visitedKeys, [$calleeKey => null])) as $transitiveDead) {
                $result[] = $transitiveDead;
            }
        }

        return $result;
    }

    /**
     * @return list<non-empty-list<BlackMember>>
     */
    private function groupDeadMembers(): array
    {
        $deadGroups = [];

        /** @var array<string, true> $deadMethodsWithCaller */
        $deadMethodsWithCaller = [];

        foreach ($this->usageGraph as $caller => $callees) {
            if (!array_key_exists($caller, $this->blackMembers)) {
                continue;
            }

            foreach ($callees as $callee) {
                if (array_key_exists($callee, $this->blackMembers)) {
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

            $deadGroups[$deadMemberKey][] = $blackMember;
            $methodsGrouped[$deadMemberKey] = true;

            $transitiveMethodKeys = $this->getTransitiveDeadCalls($deadMemberKey);

            foreach ($transitiveMethodKeys as $transitiveMethodKey) {
                $deadGroups[$deadMemberKey][] = $this->blackMembers[$transitiveMethodKey]; // @phpstan-ignore offsetAccess.notFound
                $methodsGrouped[$transitiveMethodKey] = true;
            }
        }

        // now only cycles remain, lets pick group representatives based on first occurrence
        foreach ($this->blackMembers as $deadMemberKey => $blackMember) {
            if (isset($methodsGrouped[$deadMemberKey])) {
                continue;
            }

            $transitiveDeadMethods = $this->getTransitiveDeadCalls($deadMemberKey);

            $deadGroups[$deadMemberKey][] = $blackMember;
            $methodsGrouped[$deadMemberKey] = true;

            foreach ($transitiveDeadMethods as $transitiveDeadMethodKey) {
                $deadGroups[$deadMemberKey][] = $this->blackMembers[$transitiveDeadMethodKey]; // @phpstan-ignore offsetAccess.notFound
                $methodsGrouped[$transitiveDeadMethodKey] = true;
            }
        }

        return array_values($deadGroups);
    }

    /**
     * @param non-empty-list<BlackMember> $blackMembersGroup
     */
    private function buildError(array $blackMembersGroup): IdentifierRuleError
    {
        $representative = $blackMembersGroup[0];

        $humanMemberString = $representative->member->toHumanString();
        $builder = RuleErrorBuilder::message("Unused $humanMemberString")
            ->file($representative->file)
            ->line($representative->line)
            ->identifier($representative->getErrorIdentifier());

        $metadata = [];
        $metadata[$humanMemberString] = [
            'file' => $representative->file,
            'line' => $representative->line,
            'transitive' => false,
        ];

        foreach (array_slice($blackMembersGroup, 1) as $transitivelyDeadMember) {
            $transitiveDeadMemberRef = $transitivelyDeadMember->member->toHumanString();
            $builder->addTip("Thus $transitiveDeadMemberRef is transitively also unused");

            $metadata[$transitiveDeadMemberRef] = [
                'file' => $transitivelyDeadMember->file,
                'line' => $transitivelyDeadMember->line,
                'transitive' => true,
            ];
        }

        $builder->metadata($metadata);

        return $builder->build();
    }

    /**
     * @return list<string>
     */
    private function getAncestorNames(string $typeName): array
    {
        return array_merge(
            array_keys($this->typeDefinitions[$typeName]['parents'] ?? []),
            array_keys($this->typeDefinitions[$typeName]['interfaces'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private function getTypeMethods(string $typeName): array
    {
        return array_keys($this->typeDefinitions[$typeName]['methods'] ?? []);
    }

    /**
     * @return list<string>
     */
    private function getTypeConstants(string $typeName): array
    {
        return array_keys($this->typeDefinitions[$typeName]['constants'] ?? []);
    }

    /**
     * @return array<string, array{excluded?: list<string>, aliases?: array<string, string>}>
     */
    private function getTraitUsages(string $typeName): array
    {
        return $this->typeDefinitions[$typeName]['traits'] ?? [];
    }

    private function isConsideredWhite(ClassMemberUsage $memberUsage): bool
    {
        return $memberUsage->getOrigin() === null
            || $this->isAnonymousClass($memberUsage->getOrigin()->getClassName())
            || (array_key_exists($memberUsage->getOrigin()->getMemberName(), self::UNSUPPORTED_MAGIC_METHODS));
    }

    private function isNeverReportedAsDead(BlackMember $blackMember): bool
    {
        if (!$blackMember->member instanceof ClassMethodRef) {
            return false;
        }

        $typeName = $blackMember->member->getClassName();
        $memberName = $blackMember->member->getMemberName();

        if ($typeName === null) {
            throw new LogicException('Ensured by BlackMember constructor');
        }

        $kind = $this->typeDefinitions[$typeName]['kind'] ?? null;
        $abstract = $this->typeDefinitions[$typeName]['methods'][$memberName]['abstract'] ?? false;
        $visibility = $this->typeDefinitions[$typeName]['methods'][$memberName]['visibility'] ?? 0;

        if ($kind === ClassLikeKind::TRAIT && $abstract) {
            // abstract methods in traits make sense (not dead) only when called within the trait itself, but that is hard to detect for now, so lets ignore them completely
            // the difference from interface methods (or abstract methods) is that those methods can be called over the interface, but you cannot call method over trait
            return true;
        }

        if ($memberName === '__construct' && ($visibility & Visibility::PRIVATE) !== 0) {
            // private constructors are often used to deny instantiation
            return true;
        }

        if (array_key_exists($memberName, self::UNSUPPORTED_MAGIC_METHODS)) {
            return true;
        }

        return false;
    }

    public function print(Output $output): void
    {
        if ($this->mixedMemberUses === [] || !$output->isDebug() || !$this->trackMixedAccess) {
            return;
        }

        $totalCount = array_sum(array_map('count', $this->mixedMemberUses));
        $maxExamplesToShow = 20;
        $examplesShown = 0;
        $output->writeLineFormatted(sprintf('<fg=red>Found %d usages over unknown type</>:', $totalCount));

        foreach ($this->mixedMemberUses as $memberType => $memberUses) {
            foreach ($memberUses as $memberName => $uses) {
                $examplesShown++;
                $memberTypeString = $memberType === MemberType::METHOD ? 'method' : 'constant';
                $output->writeFormatted(sprintf(' • <fg=white>%s</> %s', $memberName, $memberTypeString));

                $exampleCaller = $this->getExampleCaller($uses);

                if ($exampleCaller !== null) {
                    $output->writeFormatted(sprintf(', for example in <fg=white>%s</>', $exampleCaller));
                }

                $output->writeLineFormatted('');

                if ($examplesShown >= $maxExamplesToShow) {
                    break 2;
                }
            }
        }

        if ($totalCount > $maxExamplesToShow) {
            $output->writeLineFormatted(sprintf('... and %d more', $totalCount - $maxExamplesToShow));
        }

        $output->writeLineFormatted('');
        $output->writeLineFormatted('Thus, any member named the same is considered used, no matter its declaring class!');
        $output->writeLineFormatted('');
    }

    /**
     * @param list<ClassMemberUsage> $uses
     */
    private function getExampleCaller(array $uses): ?string
    {
        foreach ($uses as $call) {
            if ($call->getOrigin() !== null) {
                return $call->getOrigin()->toHumanString();
            }
        }

        return null;
    }

}
