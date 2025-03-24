<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Debug;

use LogicException;
use PHPStan\Command\Output;
use PHPStan\DependencyInjection\Container;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionException;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Error\BlackMember;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Output\OutputEnhancer;
use function array_map;
use function array_sum;
use function array_unique;
use function count;
use function explode;
use function ltrim;
use function next;
use function preg_replace;
use function reset;
use function sprintf;
use function str_repeat;
use function strpos;

class DebugUsagePrinter
{

    private OutputEnhancer $outputEnhancer;

    private ReflectionProvider $reflectionProvider;

    /**
     * memberKey => usage info
     *
     * @var array<string, array{typename: string, usages?: list<CollectedUsage>, eliminationPath?: array<string, non-empty-list<ClassMemberUsage>>, neverReported?: string}>
     */
    private array $debugMembers;

    private bool $mixedExcluderEnabled;

    public function __construct(
        Container $container,
        OutputEnhancer $outputEnhancer,
        ReflectionProvider $reflectionProvider,
        bool $mixedExcluderEnabled
    )
    {
        $this->outputEnhancer = $outputEnhancer;
        $this->reflectionProvider = $reflectionProvider;
        $this->mixedExcluderEnabled = $mixedExcluderEnabled;
        $this->debugMembers = $this->buildDebugMemberKeys(
            // @phpstan-ignore offsetAccess.nonOffsetAccessible, offsetAccess.nonOffsetAccessible, missingType.checkedException, argument.type
            $container->getParameter('shipmonkDeadCode')['debug']['usagesOf'], // prevents https://github.com/phpstan/phpstan/issues/12740
        );
    }

    /**
     * @param array<MemberType::*, array<string, list<CollectedUsage>>> $mixedMemberUsages
     */
    public function printMixedMemberUsages(Output $output, array $mixedMemberUsages): void
    {
        if ($mixedMemberUsages === [] || !$output->isDebug() || $this->mixedExcluderEnabled) {
            return;
        }

        $totalCount = array_sum(array_map('count', $mixedMemberUsages));
        $maxExamplesToShow = 20;
        $examplesShown = 0;
        $plural = $totalCount > 1 ? 's' : '';
        $output->writeLineFormatted(sprintf('<fg=red>Found %d usage%s over unknown type</>:', $totalCount, $plural));

        foreach ($mixedMemberUsages as $memberType => $collectedUsages) {
            foreach ($collectedUsages as $memberName => $usages) {
                $examplesShown++;
                $memberTypeString = $memberType === MemberType::METHOD ? 'method' : 'constant';
                $output->writeFormatted(sprintf(' • <fg=white>%s</> %s', $memberName, $memberTypeString));

                $exampleCaller = $this->getExampleCaller($usages);

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
     * @param list<CollectedUsage> $usages
     */
    private function getExampleCaller(array $usages): ?string
    {
        foreach ($usages as $usage) {
            $origin = $usage->getUsage()->getOrigin();

            if ($origin->getFile() !== null) {
                return $this->outputEnhancer->getOriginReference($origin);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $analysedClasses
     */
    public function printDebugMemberUsages(Output $output, array $analysedClasses): void
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

                    $indent = str_repeat('  ', $depth) . '<fg=gray>calls</> ';

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
        $replaced = preg_replace('/^(m|c)\//', '', $memberKey);

        if ($replaced === null) {
            throw new LogicException('Failed to pretty member key ' . $memberKey);
        }

        return $replaced;
    }

    /**
     * @param list<string> $alternativeKeys
     */
    public function recordUsage(CollectedUsage $collectedUsage, array $alternativeKeys): void
    {
        $memberKeys = array_unique([
            $collectedUsage->getUsage()->getMemberRef()->toKey(),
            ...$alternativeKeys,
        ]);

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
    public function markMemberAsWhite(BlackMember $blackMember, array $eliminationPath): void
    {
        $memberKey = $blackMember->getMember()->toKey();

        if (!isset($this->debugMembers[$memberKey])) {
            return;
        }

        $this->debugMembers[$memberKey]['eliminationPath'] = $eliminationPath;
    }

    public function markMemberAsNeverReported(BlackMember $blackMember, string $reason): void
    {
        $memberKey = $blackMember->getMember()->toKey();

        if (!isset($this->debugMembers[$memberKey])) {
            return;
        }

        $this->debugMembers[$memberKey]['neverReported'] = $reason;
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

            if ($this->hasOwnMethod($classReflection, $memberName)) {
                $key = ClassMethodRef::buildKey($normalizedClass, $memberName);

            } elseif ($this->hasOwnConstant($classReflection, $memberName)) {
                $key = ClassConstantRef::buildKey($normalizedClass, $memberName);

            } elseif ($this->hasOwnProperty($classReflection, $memberName)) {
                throw new LogicException("Cannot debug '$debugMember', properties are not supported yet");

            } else {
                throw new LogicException("Member '$memberName' does not exist directly in '$normalizedClass'");
            }

            $result[$key] = [
                'typename' => $normalizedClass,
            ];
        }

        return $result;
    }

    private function hasOwnMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if (!$classReflection->hasMethod($methodName)) {
            return false;
        }

        try {
            return $classReflection->getNativeReflection()->getMethod($methodName)->getBetterReflection()->getDeclaringClass()->getName() === $classReflection->getName();
        } catch (ReflectionException $e) {
            return false;
        }
    }

    private function hasOwnConstant(ClassReflection $classReflection, string $constantName): bool
    {
        $constantReflection = $classReflection->getNativeReflection()->getReflectionConstant($constantName);

        if ($constantReflection === false) {
            return false;
        }

        return $constantReflection->getBetterReflection()->getDeclaringClass()->getName() === $classReflection->getName();
    }

    private function hasOwnProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        if (!$classReflection->hasProperty($propertyName)) {
            return false;
        }

        try {
            return $classReflection->getNativeReflection()->getProperty($propertyName)->getBetterReflection()->getDeclaringClass()->getName() === $classReflection->getName();
        } catch (ReflectionException $e) {
            return false;
        }
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

}
