<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Debug;

use LogicException;
use PHPStan\Command\Output;
use PHPStan\File\RelativePathHelper;
use PHPStan\Reflection\ReflectionProvider;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Error\BlackMember;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_map;
use function array_sum;
use function count;
use function explode;
use function sprintf;
use function str_replace;
use function strpos;

class DebugUsagePrinter
{

    private RelativePathHelper $relativePathHelper;

    private ReflectionProvider $reflectionProvider;

    private bool $trackMixedAccess;

    private ?string $editorUrl;

    /**
     * memberKey => usage info
     *
     * @var array<string, array{usages?: list<CollectedUsage>, eliminationPath?: list<string>, neverReported?: string}>
     */
    private array $debugMembers;

    /**
     * @param list<string> $debugMembers
     */
    public function __construct(
        RelativePathHelper $relativePathHelper,
        ReflectionProvider $reflectionProvider,
        ?string $editorUrl,
        bool $trackMixedAccess,
        array $debugMembers
    )
    {
        $this->relativePathHelper = $relativePathHelper;
        $this->reflectionProvider = $reflectionProvider;
        $this->editorUrl = $editorUrl;
        $this->trackMixedAccess = $trackMixedAccess;
        $this->debugMembers = $this->buildDebugMemberKeys($debugMembers);
    }

    /**
     * @param array<MemberType::*, array<string, list<CollectedUsage>>> $mixedMemberUsages
     */
    public function printMixedMemberUsages(Output $output, array $mixedMemberUsages): void
    {
        if ($mixedMemberUsages === [] || !$output->isDebug() || !$this->trackMixedAccess) {
            return;
        }

        $totalCount = array_sum(array_map('count', $mixedMemberUsages));
        $maxExamplesToShow = 20;
        $examplesShown = 0;
        $output->writeLineFormatted(sprintf('<fg=red>Found %d usages over unknown type</>:', $totalCount));

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
                return $this->getOriginReference($origin);
            }
        }

        return null;
    }

    public function printDebugMemberUsages(Output $output): void
    {
        if ($this->debugMembers === [] || !$output->isDebug()) {
            return;
        }

        $output->writeLineFormatted("\n<fg=red>Usage debugging information:</>");

        foreach ($this->debugMembers as $memberKey => $debugMember) {
            $output->writeLineFormatted(sprintf("\n<fg=cyan>%s</>", $memberKey));

            if (isset($debugMember['eliminationPath'])) {
                $output->writeLineFormatted("|\n| Elimination path:");

                foreach ($debugMember['eliminationPath'] as $index => $eliminationPath) {
                    $entrypoint = $index === 0 ? '(entrypoint)' : '';
                    $output->writeLineFormatted(sprintf('|  -> <fg=white>%s</> %s', $eliminationPath, $entrypoint));
                }
            }

            if (isset($debugMember['neverReported'])) {
                $output->writeLineFormatted(sprintf("|\n| <fg=yellow>Is never reported as dead: %s</>", $debugMember['neverReported']));
            }

            if (isset($debugMember['usages'])) {
                $output->writeLineFormatted(sprintf("|\n| <fg=green>Found %d usages:</>", count($debugMember['usages'])));

                foreach ($debugMember['usages'] as $collectedUsage) {
                    $origin = $collectedUsage->getUsage()->getOrigin();
                    $output->writeFormatted(sprintf('|  • <fg=white>%s</>', $this->getOriginReference($origin)));

                    if ($collectedUsage->isExcluded()) {
                        $output->writeFormatted(sprintf(' - <fg=yellow>Excluded by %s</>', $collectedUsage->getExcludedBy()));
                    }

                    $output->writeLineFormatted('');
                }
            }

            $output->writeLineFormatted('');
        }
    }

    private function getOriginReference(UsageOrigin $origin): string
    {
        $file = $origin->getFile();
        $line = $origin->getLine();

        if ($file !== null && $line !== null) {
            $relativeFile = $this->relativePathHelper->getRelativePath($file);

            if ($this->editorUrl === null) {
                return sprintf(
                    '%s:%s',
                    $relativeFile,
                    $line,
                );
            }

            return sprintf(
                '<href=%s>%s:%s</>',
                str_replace(['%file%', '%relFile%', '%line%'], [$file, $relativeFile, (string) $line], $this->editorUrl),
                $relativeFile,
                $line,
            );
        }

        if ($origin->getReason() !== null) {
            return $origin->getReason();
        }

        throw new LogicException('Unknown state of usage origin');
    }

    public function recordUsage(CollectedUsage $collectedUsage): void
    {
        $memberKey = $collectedUsage->getUsage()->getMemberRef()->toKey();

        if (!isset($this->debugMembers[$memberKey])) {
            return;
        }

        $this->debugMembers[$memberKey]['usages'][] = $collectedUsage;
    }

    /**
     * @param list<string> $usagePath
     */
    public function markMemberAsWhite(BlackMember $blackMember, array $usagePath): void
    {
        $memberKey = $blackMember->getMember()->toKey();

        if (!isset($this->debugMembers[$memberKey])) {
            return;
        }

        $this->debugMembers[$memberKey]['eliminationPath'] = $usagePath;
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
     * @return array<string, array{usages?: list<CollectedUsage>, eliminationPath?: list<string>, neverReported?: string}>
     */
    private function buildDebugMemberKeys(array $debugMembers): array
    {
        $result = [];

        foreach ($debugMembers as $debugMember) {
            if (strpos($debugMember, '::') === false) {
                throw new LogicException("Invalid debug member format: $debugMember");
            }

            [$class, $memberName] = explode('::', $debugMember); // @phpstan-ignore offsetAccess.notFound

            if (!$this->reflectionProvider->hasClass($class)) {
                throw new LogicException("Class $class does not exist");
            }

            $classReflection = $this->reflectionProvider->getClass($class);

            if ($classReflection->hasMethod($memberName)) {
                $key = ClassMethodRef::buildKey($class, $memberName);

            } elseif ($classReflection->hasConstant($memberName)) {
                $key = ClassConstantRef::buildKey($class, $memberName);

            } elseif ($classReflection->hasProperty($memberName)) {
                throw new LogicException('Properties are not yet supported');

            } else {
                throw new LogicException("Member $memberName does not exist in $class");
            }

            $result[$key] = [];
        }

        return $result;
    }

}
