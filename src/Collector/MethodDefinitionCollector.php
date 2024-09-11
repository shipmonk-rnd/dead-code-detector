<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\Node\Stmt\TraitUseAdaptation\Precedence;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use ShipMonk\PHPStan\DeadCode\Crate\Kind;
use function array_fill_keys;
use function array_map;
use function strpos;

/**
 * @implements Collector<ClassLike, array{
 *       kind: string,
 *       name: string,
 *       methods: array<string, array{line: int}>,
 *       parents: array<string, null>,
 *       traits: array<string, array<string, array{useFrom?: string, alias?: string}>>,
 *       interfaces: array<string, null>,
 *  }>
 */
class MethodDefinitionCollector implements Collector
{

    public function getNodeType(): string
    {
        return ClassLike::class;
    }

    /**
     * @param ClassLike $node
     * @return array{
     *      kind: string,
     *      name: string,
     *      methods: array<string, array{line: int}>,
     *      parents: array<string, null>,
     *      traits: array<string, array<string, array{useFrom?: string, alias?: string}>>,
     *      interfaces: array<string, null>,
     * }|null
     */
    public function processNode(
        Node $node,
        Scope $scope
    ): ?array
    {
        if ($node->namespacedName === null) {
            return null;
        }

        $kind = $this->getKind($node);
        $typeName = $node->namespacedName->toString();

        $methods = [];

        foreach ($node->getMethods() as $method) {
            if ($this->isUnsupportedMethod($method)) {
                continue;
            }

            $methods[$method->name->toString()] = [
                'line' => $method->getStartLine(),
            ];
        }

        return [
            'kind' => $kind,
            'name' => $typeName,
            'methods' => $methods,
            'parents' => $this->getParents($node),
            'traits' => $this->getTraits($node),
            'interfaces' => $this->getInterfaces($node),
        ];
    }

    /**
     * @return array<string, null>
     */
    private function getParents(ClassLike $node): array
    {
        if ($node instanceof Class_) {
            if ($node->extends === null) {
                return [];
            }

            return [$node->extends->toString() => null];
        }

        if ($node instanceof Interface_) {
            return array_fill_keys(
                array_map(
                    static fn(Name $name) => $name->toString(),
                    $node->extends,
                ),
                null,
            );
        }

        return [];
    }

    /**
     * @return array<string, null>
     */
    private function getInterfaces(ClassLike $node): array
    {
        if ($node instanceof Class_ || $node instanceof Enum_) {
            return array_fill_keys(
                array_map(
                    static fn(Name $name) => $name->toString(),
                    $node->implements,
                ),
                null,
            );
        }

        return [];
    }

    /**
     * @return array<string, array<string, array{useFrom?: string, alias?: string}>>
     */
    private function getTraits(ClassLike $node): array
    {
        $traits = [];

        foreach ($node->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $traits[$trait->toString()] = [];
            }

            foreach ($traitUse->adaptations as $adaptation) {
                if ($adaptation->trait === null) {
                    continue; // TODO when??
                }

                if ($adaptation instanceof Precedence) {
                    foreach ($adaptation->insteadof as $insteadof) {
                        $traits[$insteadof->toString()][$adaptation->method->toString()]['useFrom'] = $adaptation->trait->toString();
                    }
                }

                if ($adaptation instanceof Alias && $adaptation->newName !== null) { // TODO when null?
                    $traits[$adaptation->trait->toString()][$adaptation->method->toString()]['alias'] = $adaptation->newName->toString();
                }
            }
        }

        return $traits;
    }

    private function isUnsupportedMethod(ClassMethod $method): bool
    {
        $methodName = $method->name->toString();

        if ($methodName === '__destruct') {
            return true;
        }

        if ($methodName !== '__construct' && strpos($methodName, '__') === 0) { // magic methods like __toString, __clone, __get, __set etc
            return true;
        }

        if ($methodName === '__construct' && $method->isPrivate()) { // e.g. classes with "denied" instantiation
            return true;
        }

        return false;
    }

    private function getKind(ClassLike $node): string
    {
        if ($node instanceof Class_) {
            return Kind::CLASSS;
        }

        if ($node instanceof Interface_) {
            return Kind::INTERFACE;
        }

        if ($node instanceof Trait_) {
            return Kind::TRAIT;
        }

        if ($node instanceof Enum_) {
            return Kind::ENUM;
        }

        throw new LogicException('Unknown class-like node');
    }

}
