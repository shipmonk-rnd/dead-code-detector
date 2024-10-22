<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\Node\Stmt\TraitUseAdaptation\Precedence;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use ShipMonk\PHPStan\DeadCode\Crate\Kind;
use ShipMonk\PHPStan\DeadCode\Crate\Visibility;
use function array_fill_keys;
use function array_map;

/**
 * @implements Collector<ClassLike, array{
 *       kind: string,
 *       name: string,
 *       methods: array<string, array{line: int, abstract: bool, visibility: int-mask-of<Visibility::*>}>,
 *       parents: array<string, null>,
 *       traits: array<string, array{excluded?: list<string>, aliases?: array<string, string>}>,
 *       interfaces: array<string, null>,
 *  }>
 */
class ClassDefinitionCollector implements Collector
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
     *      methods: array<string, array{line: int, abstract: bool, visibility: int-mask-of<Visibility::*>}>,
     *      parents: array<string, null>,
     *      traits: array<string, array{excluded?: list<string>, aliases?: array<string, string>}>,
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
            $methods[$method->name->toString()] = [
                'line' => $method->getStartLine(),
                'abstract' => $method->isAbstract() || $node instanceof Interface_,
                'visibility' => $method->flags & (Visibility::PUBLIC | Visibility::PROTECTED | Visibility::PRIVATE),
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
     * @return array<string, array{excluded?: list<string>, aliases?: array<string, string>}>
     */
    private function getTraits(ClassLike $node): array
    {
        $traits = [];

        foreach ($node->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $traits[$trait->toString()] = [];
            }

            foreach ($traitUse->adaptations as $adaptation) {
                if ($adaptation instanceof Precedence) {
                    foreach ($adaptation->insteadof as $insteadof) {
                        $traits[$insteadof->toString()]['excluded'][] = $adaptation->method->toString();
                    }
                }

                if ($adaptation instanceof Alias && $adaptation->newName !== null) {
                    if ($adaptation->trait === null) {
                        // assign alias to all traits, wrong ones are eliminated in Rule logic
                        foreach ($traitUse->traits as $trait) {
                            $traits[$trait->toString()]['aliases'][$adaptation->method->toString()] = $adaptation->newName->toString();
                        }
                    } else {
                        $traits[$adaptation->trait->toString()]['aliases'][$adaptation->method->toString()] = $adaptation->newName->toString();
                    }
                }
            }
        }

        return $traits;
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
