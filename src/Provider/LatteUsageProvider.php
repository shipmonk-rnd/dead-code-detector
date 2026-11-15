<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use FilesystemIterator;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use SplFileInfo;
use UnexpectedValueException;
use function file_get_contents;
use function is_dir;
use function is_file;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function preg_split;
use function sprintf;
use function str_ends_with;
use function strtolower;
use const PREG_SET_ORDER;
use const PREG_SPLIT_NO_EMPTY;

/**
 * @see https://latte.nette.org/en/type-system
 */
final class LatteUsageProvider implements ActivatableUsageProvider
{

    private const ENGINE_CLASS = 'Latte\Engine';
    private const NETTE_TEMPLATE_CLASS = 'Nette\Bridges\ApplicationLatte\Template';
    private const NETTE_TEMPLATE_INTERFACE = 'Nette\Application\UI\Template';

    private readonly ReflectionProvider $reflectionProvider;

    private readonly TemplateViewDataTraverser $traverser;

    private readonly bool $enabled;

    /**
     * @var list<string>
     */
    private readonly array $templatePaths;

    /**
     * Lowercased class names referenced by {templateType} and {varType} tags in .latte files, lazily loaded.
     *
     * @var array<string, string>|null
     */
    private ?array $classesReferencedInTemplates = null;

    /**
     * @param list<string> $analysedPaths
     * @param list<string> $templatePaths
     */
    public function __construct(
        ReflectionProvider $reflectionProvider,
        TemplateViewDataTraverser $traverser,
        ?bool $enabled,
        array $analysedPaths,
        array $templatePaths,
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->traverser = $traverser;
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('latte/latte');
        $this->templatePaths = [...$analysedPaths, ...$templatePaths];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getUsages(
        Node $node,
        Scope $scope,
    ): array
    {
        if ($node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            return $this->getUsagesFromClass($node->getClassReflection());
        }

        if ($node instanceof MethodCall) {
            return $this->getUsagesFromRenderCall($node, $scope);
        }

        if ($node instanceof Assign) {
            return $this->getUsagesFromTemplatePropertyAssign($node, $scope);
        }

        return [];
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function getUsagesFromClass(ClassReflection $classReflection): array
    {
        $usages = [];
        $nativeReflection = $classReflection->getNativeReflection();
        $className = $nativeReflection->getName();

        // Engine::render() registers these methods of the passed parameters object as filters/functions
        foreach ($nativeReflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $className || !$method->isPublic()) {
                continue;
            }

            if ($method->getAttributes('Latte\Attributes\TemplateFilter') !== []) {
                $usages[] = $this->createMethodUsage($className, $method->getName(), 'Latte filter via #[TemplateFilter] attribute');
            }

            if ($method->getAttributes('Latte\Attributes\TemplateFunction') !== []) {
                $usages[] = $this->createMethodUsage($className, $method->getName(), 'Latte function via #[TemplateFunction] attribute');
            }
        }

        // Typed template: Template::render() passes $this to Engine::render(), which exposes its public properties as template variables
        if ($classReflection->is(self::NETTE_TEMPLATE_CLASS) && $className !== self::NETTE_TEMPLATE_CLASS) {
            $usages = [...$usages, ...$this->getTypedTemplateUsages($classReflection)];
        }

        // Classes declared by {templateType} or {varType} in templates may be accessed in any way from the template
        $classesInTemplates = $this->getClassesReferencedInTemplates();

        if (isset($classesInTemplates[strtolower($className)])) {
            $usages = [
                ...$usages,
                ...$this->traverser->getUsages([$className], sprintf('Latte template type %s', $className), $this),
            ];
        }

        return $usages;
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function getTypedTemplateUsages(ClassReflection $classReflection): array
    {
        $usages = [];
        $nativeReflection = $classReflection->getNativeReflection();
        $className = $nativeReflection->getName();
        $note = sprintf('Latte typed template %s', $className);

        foreach ($nativeReflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $className || !$property->isPublic() || $property->isStatic()) {
                continue;
            }

            $usages[] = new ClassPropertyUsage(
                UsageOrigin::createVirtual($this, VirtualUsageData::withNote($note)),
                new ClassPropertyRef($className, $property->getName(), possibleDescendant: false),
                AccessType::READ,
            );

            $propertyType = $classReflection->getNativeProperty($property->getName())->getReadableType();
            $usages = [
                ...$usages,
                ...$this->traverser->getUsages($propertyType->getReferencedClasses(), "{$note}::\${$property->getName()}", $this),
            ];
        }

        return $usages;
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function getUsagesFromRenderCall(
        MethodCall $node,
        Scope $scope,
    ): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $parametersArgIndex = $this->getParametersArgIndex($node->var, $node->name->toString(), $scope);

        if ($parametersArgIndex === null) {
            return [];
        }

        $parametersArg = $node->getArgs()[$parametersArgIndex] ?? null;

        if ($parametersArg === null) {
            return [];
        }

        return $this->traverseExpression($parametersArg->value, $node, $scope);
    }

    private function getParametersArgIndex(
        Expr $caller,
        string $methodName,
        Scope $scope,
    ): ?int
    {
        foreach ($scope->getType($caller)->getObjectClassNames() as $className) {
            if (!$this->reflectionProvider->hasClass($className)) {
                continue;
            }

            $classReflection = $this->reflectionProvider->getClass($className);

            if ($classReflection->is(self::ENGINE_CLASS)) {
                $engineMethods = [
                    'render' => 1,
                    'rendertostring' => 1,
                ];

                return $engineMethods[strtolower($methodName)] ?? null;
            }

            if ($classReflection->is(self::NETTE_TEMPLATE_CLASS)) {
                $templateMethods = [
                    'render' => 1,
                    'rendertostring' => 1,
                    'setparameters' => 0,
                    'add' => 1,
                ];

                return $templateMethods[strtolower($methodName)] ?? null;
            }
        }

        return null;
    }

    /**
     * Handles $template->foo = $value; (both dynamic and declared properties of a template)
     *
     * @return list<ClassMemberUsage>
     */
    private function getUsagesFromTemplatePropertyAssign(
        Assign $node,
        Scope $scope,
    ): array
    {
        if (!$node->var instanceof PropertyFetch) {
            return [];
        }

        $isTemplate = false;

        foreach ($scope->getType($node->var->var)->getObjectClassNames() as $className) {
            if (!$this->reflectionProvider->hasClass($className)) {
                continue;
            }

            $classReflection = $this->reflectionProvider->getClass($className);

            if ($classReflection->is(self::NETTE_TEMPLATE_CLASS) || $classReflection->is(self::NETTE_TEMPLATE_INTERFACE)) {
                $isTemplate = true;
                break;
            }
        }

        if (!$isTemplate) {
            return [];
        }

        return $this->traverseExpression($node->expr, $node, $scope);
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function traverseExpression(
        Expr $expr,
        Node $node,
        Scope $scope,
    ): array
    {
        $referencedClassNames = $scope->getType($expr)->getReferencedClasses();

        return $this->traverser->getUsages($referencedClassNames, $this->getRootContext($node, $scope), $this);
    }

    /**
     * @return non-empty-string
     */
    private function getRootContext(
        Node $node,
        Scope $scope,
    ): string
    {
        $functionName = $scope->getFunctionName();

        if (!$scope->isInClass() || $functionName === null) {
            return 'unknown';
        }

        return "{$scope->getClassReflection()->getName()}::{$functionName}({$node->getStartLine()})";
    }

    /**
     * @return array<string, string>
     */
    private function getClassesReferencedInTemplates(): array
    {
        if ($this->classesReferencedInTemplates !== null) {
            return $this->classesReferencedInTemplates;
        }

        $classes = [];

        foreach ($this->findLatteFiles() as $latteFile) {
            $contents = file_get_contents($latteFile);

            if ($contents === false) {
                throw new LogicException(sprintf('Unable to read Latte template %s', $latteFile));
            }

            foreach (self::findClassesInTemplate($contents) as $className) {
                $classes[strtolower($className)] = $className;
            }
        }

        return $this->classesReferencedInTemplates = $classes;
    }

    /**
     * Extracts class names from {templateType Class} and {varType Type $var} tags.
     *
     * @return list<string>
     */
    public static function findClassesInTemplate(string $contents): array
    {
        $types = [];

        if (preg_match_all('~\{templateType\s+([^\s}]+)\s*\}~', $contents, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $types[] = $match[1];
            }
        }

        if (preg_match_all('~\{varType\s+([^}]+?)\s+\$\w+\s*\}~', $contents, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $types[] = $match[1];
            }
        }

        $classes = [];

        foreach ($types as $type) {
            $parts = preg_split('~[\s|&?()<>,\[\]{}:]+~', $type, -1, PREG_SPLIT_NO_EMPTY);

            if ($parts === false) {
                continue;
            }

            foreach ($parts as $part) {
                if (preg_match('~^\\\\?[a-zA-Z_][a-zA-Z0-9_]*(\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$~', $part) === 1) {
                    $classes[] = ltrim($part, '\\');
                }
            }
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function findLatteFiles(): array
    {
        $files = [];

        foreach ($this->templatePaths as $path) {
            if (is_file($path)) {
                if (str_ends_with($path, '.latte')) {
                    $files[] = $path;
                }

                continue;
            }

            if (!is_dir($path)) {
                continue;
            }

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                );

                /** @var SplFileInfo $file */
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'latte') {
                        $files[] = $file->getPathname();
                    }
                }
            } catch (UnexpectedValueException $e) {
                throw new LogicException(sprintf('Unable to search Latte templates in %s', $path), 0, $e);
            }
        }

        return $files;
    }

    private function createMethodUsage(
        string $className,
        string $methodName,
        string $note,
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($note)),
            new ClassMethodRef($className, $methodName, possibleDescendant: false),
        );
    }

}
