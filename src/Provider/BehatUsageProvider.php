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
use function explode;
use function preg_match;
use function preg_replace;

final class BehatUsageProvider implements MemberUsageProvider
{

    /**
     * Copied from Behat\Behat\Context\Reader\AnnotatedContextReader::DOCLINE_TRIMMER_REGEX
     */
    private const DOCLINE_TRIMMER_REGEX = '/^\/\*\*\s*|^\s*\*\s*|\s*\*\/$|\s*$/';

    /**
     * Mirrors Behat\Behat\Definition\Context\Annotation\DefinitionAnnotationReader (step needs a pattern)
     */
    private const STEP_ANNOTATION_REGEX = '/^@(?:given|when|then)\s+.+$/i';

    /**
     * Mirrors Behat\Behat\Hook\Context\Annotation\HookAnnotationReader (arguments are optional)
     */
    private const HOOK_ANNOTATION_REGEX = '/^@(?:beforesuite|aftersuite|beforefeature|afterfeature|beforescenario|afterscenario|beforestep|afterstep)(?:\s+.+)?$/i';

    /**
     * Mirrors Behat\Behat\Transformation\Context\Annotation\TransformationAnnotationReader (any suffix matches)
     */
    private const TRANSFORM_ANNOTATION_REGEX = '/^@transform/i';

    private readonly bool $enabled;

    public function __construct(?bool $enabled)
    {
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('behat/behat');
    }

    public function getUsages(
        Node $node,
        Scope $scope,
    ): array
    {
        if (!$this->enabled || !$node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            return [];
        }

        $classReflection = $node->getClassReflection();

        if (!$classReflection->implementsInterface('Behat\Behat\Context\Context')) {
            return [];
        }

        $usages = [];
        $className = $classReflection->getName();

        foreach ($classReflection->getNativeReflection()->getMethods() as $method) {
            $methodName = $method->getName();

            if ($method->isConstructor()) {
                $usages[] = $this->createUsage($className, $methodName, 'Behat context constructor');
            } elseif ($this->isBehatContextMethod($method)) {
                $usages[] = $this->createUsage($className, $methodName, 'Behat step definition or hook');
            }
        }

        return $usages;
    }

    private function isBehatContextMethod(ReflectionMethod $method): bool
    {
        return $this->hasStepOrHookAnnotation($method)
            || $this->hasAttribute($method, 'Behat\Step\Given')
            || $this->hasAttribute($method, 'Behat\Step\When')
            || $this->hasAttribute($method, 'Behat\Step\Then')
            || $this->hasAttribute($method, 'Behat\Hook\BeforeScenario')
            || $this->hasAttribute($method, 'Behat\Hook\AfterScenario')
            || $this->hasAttribute($method, 'Behat\Hook\BeforeStep')
            || $this->hasAttribute($method, 'Behat\Hook\AfterStep')
            || $this->hasAttribute($method, 'Behat\Hook\BeforeSuite')
            || $this->hasAttribute($method, 'Behat\Hook\AfterSuite')
            || $this->hasAttribute($method, 'Behat\Hook\BeforeFeature')
            || $this->hasAttribute($method, 'Behat\Hook\AfterFeature')
            || $this->hasAttribute($method, 'Behat\Transformation\Transform');
    }

    /**
     * Mirrors line-based annotation parsing of Behat\Behat\Context\Reader\AnnotatedContextReader
     */
    private function hasStepOrHookAnnotation(ReflectionMethod $method): bool
    {
        $docComment = $method->getDocComment();

        if ($docComment === false) {
            return false;
        }

        foreach (explode("\n", $docComment) as $docLine) {
            $trimmedLine = (string) preg_replace(self::DOCLINE_TRIMMER_REGEX, '', $docLine);

            if (
                preg_match(self::STEP_ANNOTATION_REGEX, $trimmedLine) === 1
                || preg_match(self::HOOK_ANNOTATION_REGEX, $trimmedLine) === 1
                || preg_match(self::TRANSFORM_ANNOTATION_REGEX, $trimmedLine) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasAttribute(
        ReflectionMethod $method,
        string $attributeClass,
    ): bool
    {
        return $method->getAttributes($attributeClass) !== [];
    }

    private function createUsage(
        string $className,
        string $methodName,
        string $reason,
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($reason)),
            new ClassMethodRef(
                $className,
                $methodName,
                possibleDescendant: false,
            ),
        );
    }

}
