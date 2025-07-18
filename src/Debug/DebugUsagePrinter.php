<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Debug;

use LogicException;
use PHPStan\Command\Output;
use PHPStan\DependencyInjection\Container;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\TrinaryLogic;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Error\BlackMember;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Output\OutputEnhancer;
use ShipMonk\PHPStan\DeadCode\Reflection\ReflectionHelper;
use function array_map;
use function array_sum;
use function count;
use function explode;
use function implode;
use function ltrim;
use function next;
use function reset;
use function sprintf;
use function str_repeat;
use function strpos;
use function substr;

class DebugUsagePrinter
{

    public const ANY_MEMBER = "\0";

    private OutputEnhancer $outputEnhancer;

    private ReflectionProvider $reflectionProvider;

    /**
     * memberKey => usage info
     *
     * @var array<string, array{typename: string, usages?: list<CollectedUsage>, eliminationPath?: array<string, non-empty-list<ClassMemberUsage>>, neverReported?: string}>
     */
    private array $debugMembers;

    public function __construct(
        Container $container,
        OutputEnhancer $outputEnhancer,
        ReflectionProvider $reflectionProvider
    )
    {
        $this->outputEnhancer = $outputEnhancer;
        $this->reflectionProvider = $reflectionProvider;
        $this->debugMembers = $this->buildDebugMemberKeys(
            // @phpstan-ignore offsetAccess.nonOffsetAccessible, offsetAccess.nonOffsetAccessible, missingType.checkedException, argument.type
            $container->getParameter('shipmonkDeadCode')['debug']['usagesOf'], // prevents https://github.com/phpstan/phpstan/issues/12740
        );
    }

    /**
     * @param array<MemberType::*, array<string, non-empty-list<CollectedUsage>>> $mixedMemberUsages
     */
    public function printMixedMemberUsages(
        Output $output,
        array $mixedMemberUsages
    ): void
    {
        if ($mixedMemberUsages === [] || !$output->isDebug()) {
            return;
        }

        $mixedEverythingUsages = [];
        $mixedClassNameUsages = [];

        foreach ($mixedMemberUsages as $memberType => $collectedUsagesByMemberName) {
            foreach ($collectedUsagesByMemberName as $memberName => $collectedUsages) {
                foreach ($collectedUsages as $collectedUsage) {
                    if ($collectedUsage->isExcluded()) {
                        continue;
                    }

                    if ($memberName === self::ANY_MEMBER) {
                        $mixedEverythingUsages[$memberType][] = $collectedUsage;
                    } else {
                        $mixedClassNameUsages[$memberType][$memberName][] = $collectedUsage;
                    }
                }
            }
        }

        $this->printMixedEverythingUsages($output, $mixedEverythingUsages);
        $this->printMixedClassNameUsages($output, $mixedClassNameUsages);
    }

    /**
     * @param array<MemberType::*, array<string, non-empty-list<CollectedUsage>>> $mixedMemberUsages
     */
    private function printMixedClassNameUsages(
        Output $output,
        array $mixedMemberUsages
    ): void
    {
        $totalCount = array_sum(array_map('count', $mixedMemberUsages));

        if ($totalCount === 0) {
            return;
        }

        $maxExamplesToShow = 20;
        $examplesShown = 0;
        $plural = $totalCount > 1 ? 's' : '';
        $output->writeLineFormatted(sprintf('<fg=yellow>Found %d usage%s over unknown type</>:', $totalCount, $plural));

        foreach ($mixedMemberUsages as $memberType => $collectedUsages) {
            foreach ($collectedUsages as $memberName => $usages) {
                $examplesShown++;
                $memberAccessString = $memberType === MemberType::METHOD ? 'method' : 'constant';
                $output->writeFormatted(sprintf(' • <fg=white>%s</> %s', $memberName, $memberAccessString));

                $exampleCaller = $this->getExampleCaller($usages);
                $output->writeFormatted(sprintf(', for example in <fg=white>%s</>', $exampleCaller));

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
     * @param array<MemberType::*, non-empty-list<CollectedUsage>> $fullyMixedUsages
     */
    private function printMixedEverythingUsages(
        Output $output,
        array $fullyMixedUsages
    ): void
    {
        if ($fullyMixedUsages === []) {
            return;
        }

        foreach ($fullyMixedUsages as $memberType => $collectedUsages) {
            $fullyMixedCount = count($collectedUsages);

            $memberTypeString = $memberType === MemberType::METHOD ? 'method' : 'constant';
            $memberAccessString = $memberType === MemberType::METHOD ? 'call' : 'fetch';
            $fullyMixedPlural = $fullyMixedCount > 1 ? ($memberType === MemberType::METHOD ? 's' : 'es') : '';
            $output->writeLineFormatted(sprintf('<fg=red>Found %d UNKNOWN %s%s over UNKNOWN type!!</>', $fullyMixedCount, $memberAccessString, $fullyMixedPlural));

            foreach ($collectedUsages as $usages) {
                $output->writeLineFormatted(
                    sprintf(
                        ' • %s in <fg=white>%s</>',
                        $memberType === MemberType::METHOD ? 'method call' : 'constant fetch',
                        $this->getExampleCaller([$usages]),
                    ),
                );
            }

            $output->writeLineFormatted('');
            $output->writeLineFormatted(sprintf(
                'Such usages basically break whole dead code analysis, because any %s on any class can be %sed there!',
                $memberTypeString,
                $memberAccessString,
            ));
            $output->writeLineFormatted('All those usages were ignored!');
            $output->writeLineFormatted('');
        }
    }

    /**
     * @param non-empty-list<CollectedUsage> $usages
     */
    private function getExampleCaller(array $usages): string
    {
        foreach ($usages as $usage) {
            $origin = $usage->getUsage()->getOrigin();

            if ($origin->getFile() !== null) {
                return $this->outputEnhancer->getOriginReference($origin);
            }
        }

        foreach ($usages as $usage) {
            $origin = $usage->getUsage()->getOrigin();
            return $this->outputEnhancer->getOriginReference($origin); // show virtual usages only as last resort
        }
    }

    /**
     * @param array<string, mixed> $analysedClasses
     */
    public function printDebugMemberUsages(
        Output $output,
        array $analysedClasses
    ): void
    {
        if ($this->debugMembers === [] || !$output->isDebug()) {
            return;
        }

        $output->writeLineFormatted("\n<fg=red>Usage debugging information:</>");

        foreach ($this->debugMembers as $memberKey => $debugMember) {
            $typeName = $debugMember['typename'];

            $output->writeLineFormatted(sprintf("\n<fg=cyan>%s</>", $this->prettyMemberKey($memberKey)));

            if (isset($debugMember['eliminationPath'])) {
                $output->writeLineFormatted("|\n| <fg=green>Marked as alive at:</>");
                $depth = 1;

                foreach ($debugMember['eliminationPath'] as $fragmentKey => $fragmentUsages) {
                    if ($depth === 1) {
                        $entrypoint = $this->outputEnhancer->getOriginReference($fragmentUsages[0]->getOrigin(), false);
                        $output->writeLineFormatted(sprintf('| <fg=gray>entry</> <fg=white>%s</>', $entrypoint));
                    }

                    $usage = $this->getUsageWord($fragmentUsages[0]->getMemberType());
                    $indent = str_repeat('  ', $depth) . "<fg=gray>$usage</> ";

                    $nextFragmentUsages = next($debugMember['eliminationPath']);
                    $nextFragmentFirstUsage = $nextFragmentUsages !== false ? reset($nextFragmentUsages) : null;
                    $nextFragmentFirstUsageOrigin = $nextFragmentFirstUsage instanceof ClassMemberUsage ? $nextFragmentFirstUsage->getOrigin() : null;

                    $pathFragment = $nextFragmentFirstUsageOrigin === null
                        ? $this->prettyMemberKey($fragmentKey)
                        : $this->outputEnhancer->getOriginLink($nextFragmentFirstUsageOrigin, $this->prettyMemberKey($fragmentKey));

                    $output->writeLineFormatted(sprintf('| %s<fg=white>%s</>', $indent, $pathFragment));

                    $depth++;
                }
            } elseif (!isset($analysedClasses[$typeName])) {
                $output->writeLineFormatted("|\n| <fg=yellow>Not defined within analysed files!</>");

            } elseif (isset($debugMember['usages'])) {
                $output->writeLineFormatted("|\n| <fg=yellow>Dead because:</>");

                if ($this->allUsagesExcluded($debugMember['usages'])) {
                    $output->writeLineFormatted('| all usages are excluded');
                } else {
                    $output->writeLineFormatted('| all usages originate in unused code');
                }
            }

            if (isset($debugMember['usages'])) {
                $plural = count($debugMember['usages']) > 1 ? 's' : '';
                $output->writeLineFormatted(sprintf("|\n| <fg=green>Found %d usage%s:</>", count($debugMember['usages']), $plural));

                foreach ($debugMember['usages'] as $collectedUsage) {
                    $origin = $collectedUsage->getUsage()->getOrigin();
                    $output->writeFormatted(sprintf('|  • <fg=white>%s</>', $this->outputEnhancer->getOriginReference($origin)));

                    if ($collectedUsage->isExcluded()) {
                        $output->writeFormatted(sprintf(' - <fg=yellow>excluded by %s excluder</>', $collectedUsage->getExcludedBy()));
                    }

                    $output->writeLineFormatted('');
                }
            } elseif (isset($debugMember['neverReported'])) {
                $output->writeLineFormatted(sprintf("|\n| <fg=yellow>Is never reported as dead: %s</>", $debugMember['neverReported']));
            } else {
                $output->writeLineFormatted("|\n| <fg=yellow>No usages found</>");
            }

            $output->writeLineFormatted('');
        }
    }

    private function prettyMemberKey(string $memberKey): string
    {
        if (
            strpos($memberKey, 'm/') === false
            && strpos($memberKey, 'c/') === false
            && strpos($memberKey, 'e/') === false
        ) {
            throw new LogicException("Invalid member key format: '$memberKey'");
        }

        return substr($memberKey, 2);
    }

    /**
     * @param list<string> $alternativeKeys
     */
    public function recordUsage(
        CollectedUsage $collectedUsage,
        array $alternativeKeys
    ): void
    {
        if ($alternativeKeys === []) {
            // this can happen for references outside analysed files
            $originalRef = $collectedUsage->getUsage()->getMemberRef();
            $memberKeys = $originalRef->hasKnownClass() && $originalRef->hasKnownMember()
                    ? $originalRef->toKeys()
                    : [];
        } else {
            $memberKeys = $alternativeKeys;
        }

        foreach ($memberKeys as $memberKey) {
            if (!isset($this->debugMembers[$memberKey])) {
                continue;
            }

            $this->debugMembers[$memberKey]['usages'][] = $collectedUsage;
        }
    }

    /**
     * @param array<string, non-empty-list<ClassMemberUsage>> $eliminationPath
     */
    public function markMemberAsWhite(
        BlackMember $blackMember,
        array $eliminationPath
    ): void
    {
        $memberKeys = $blackMember->getMember()->toKeys();

        foreach ($memberKeys as $memberKey) {
            if (!isset($this->debugMembers[$memberKey])) {
                continue;
            }

            $this->debugMembers[$memberKey]['eliminationPath'] = $eliminationPath;
        }
    }

    public function markMemberAsNeverReported(
        BlackMember $blackMember,
        string $reason
    ): void
    {
        $memberKeys = $blackMember->getMember()->toKeys();

        foreach ($memberKeys as $memberKey) {
            if (!isset($this->debugMembers[$memberKey])) {
                continue;
            }

            $this->debugMembers[$memberKey]['neverReported'] = $reason;
        }
    }

    /**
     * @param list<string> $debugMembers
     * @return array<string, array{typename: string, usages?: list<CollectedUsage>, eliminationPath?: array<string, non-empty-list<ClassMemberUsage>>, neverReported?: string}>
     */
    private function buildDebugMemberKeys(array $debugMembers): array
    {
        $result = [];

        foreach ($debugMembers as $debugMember) {
            if (strpos($debugMember, '::') === false) {
                throw new LogicException("Invalid debug member format: '$debugMember', expected 'ClassName::memberName'");
            }

            [$class, $memberName] = explode('::', $debugMember); // @phpstan-ignore offsetAccess.notFound
            $normalizedClass = ltrim($class, '\\');

            if (!$this->reflectionProvider->hasClass($normalizedClass)) {
                throw new LogicException("Class '$normalizedClass' does not exist");
            }

            $classReflection = $this->reflectionProvider->getClass($normalizedClass);

            if (ReflectionHelper::hasOwnMethod($classReflection, $memberName)) {
                $keys = (new ClassMethodRef($normalizedClass, $memberName, false))->toKeys();

            } elseif (ReflectionHelper::hasOwnConstant($classReflection, $memberName)) {
                $keys = (new ClassConstantRef($normalizedClass, $memberName, false, TrinaryLogic::createNo()))->toKeys();

            } elseif (ReflectionHelper::hasOwnEnumCase($classReflection, $memberName)) {
                $keys = (new ClassConstantRef($normalizedClass, $memberName, false, TrinaryLogic::createYes()))->toKeys();

            } elseif (ReflectionHelper::hasOwnProperty($classReflection, $memberName)) {
                throw new LogicException("Cannot debug '$debugMember', properties are not supported yet");

            } else {
                throw new LogicException("Member '$memberName' does not exist directly in '$normalizedClass'");
            }

            if (count($keys) !== 1) {
                throw new LogicException('Found definition should always relate to single member, but got: ' . implode(', ', $keys));
            }

            $result[$keys[0]] = [
                'typename' => $normalizedClass,
            ];
        }

        return $result;
    }

    /**
     * @param list<CollectedUsage> $collectedUsages
     */
    private function allUsagesExcluded(array $collectedUsages): bool
    {
        foreach ($collectedUsages as $collectedUsage) {
            if (!$collectedUsage->isExcluded()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param MemberType::* $memberType
     */
    private function getUsageWord(int $memberType): string
    {
        if ($memberType === MemberType::METHOD) {
            return 'calls';
        } elseif ($memberType === MemberType::CONSTANT) {
            return 'fetches';
        } else {
            throw new LogicException("Unsupported member type: $memberType");
        }
    }

}
