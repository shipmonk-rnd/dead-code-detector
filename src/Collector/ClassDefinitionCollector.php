<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\Node\Stmt\TraitUseAdaptation\Precedence;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use ShipMonk\PHPStan\DeadCode\Enum\ClassLikeKind;
use ShipMonk\PHPStan\DeadCode\Enum\Visibility;
use function array_fill_keys;
use function array_map;
use function count;

/**
 * @implements Collector<ClassLike, array{
 *       kind: string,
 *       name: string,
 *       cases: array<string, array{line: int}>,
 *       constants: array<string, array{line: int}>,
 *       methods: array<string, array{line: int, params: int, abstract: bool, visibility: int-mask-of<Visibility::*>}>,
 *       parents: array<string, null>,
 *       traits: array<string, array{excluded?: list<string>, aliases?: array<string, string>}>,
 *       interfaces: array<string, null>,
 *  }>
 */
class ClassDefinitionCollector implements Collector
{

    private ReflectionProvider $reflectionProvider;

    private bool $detectDeadMethods;

    private bool $detectDeadConstants;

    private bool $detectDeadEnumCases;

    public function __construct(
        ReflectionProvider $reflectionProvider,
        bool $detectDeadMethods,
        bool $detectDeadConstants,
        bool $detectDeadEnumCases
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->detectDeadMethods = $detectDeadMethods;
        $this->detectDeadConstants = $detectDeadConstants;
        $this->detectDeadEnumCases = $detectDeadEnumCases;
    }

    public function getNodeType(): string
    {
        return ClassLike::class;
    }

    /**
     * @param ClassLike $node
     * @return array{
     *      kind: string,
     *      name: string,
     *      cases: array<string, array{line: int}>,
     *      constants: array<string, array{line: int}>,
     *      methods: array<string, array{line: int, params: int, abstract: bool, visibility: int-mask-of<Visibility::*>}>,
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
        $reflection = $this->reflectionProvider->getClass($typeName);

        $methods = [];
        $constants = [];
        $cases = [];

        if ($this->detectDeadMethods) {
            foreach ($node->getMethods() as $method) {
                $methods[$method->name->toString()] = [
                    'line' => $method->name->getStartLine(),
                    'params' => count($method->params),
                    'abstract' => $method->isAbstract() || $node instanceof Interface_,
                    'visibility' => $method->flags & (Visibility::PUBLIC | Visibility::PROTECTED | Visibility::PRIVATE),
                ];
            }
        }

        if ($this->detectDeadConstants) {
            foreach ($node->getConstants() as $constant) {
                foreach ($constant->consts as $const) {
                    $constants[$const->name->toString()] = [
                        'line' => $const->getStartLine(),
                    ];
                }
            }
        }

        if ($this->detectDeadEnumCases) {
            foreach ($this->getEnumCases($node) as $case) {
                $cases[$case->name->toString()] = [
                    'line' => $case->name->getStartLine(),
                ];
            }
        }

        return [
            'kind' => $kind,
            'name' => $typeName,
            'methods' => $methods,
            'cases' => $cases,
            'constants' => $constants,
            'parents' => $this->getParents($reflection),
            'traits' => $this->getTraits($node),
            'interfaces' => $this->getInterfaces($reflection),
        ];
    }

    /**
     * @return array<string, null>
     */
    private function getParents(ClassReflection $reflection): array
    {
        $parents = [];

        foreach ($reflection->getParentClassesNames() as $parent) {
            $parents[$parent] = null;
        }

        return $parents;
    }

    /**
     * @return array<string, null>
     */
    private function getInterfaces(ClassReflection $reflection): array
    {
        return array_fill_keys(array_map(static fn (ClassReflection $reflection) => $reflection->getName(), $reflection->getInterfaces()), null);
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
            return ClassLikeKind::CLASSS;
        }

        if ($node instanceof Interface_) {
            return ClassLikeKind::INTERFACE;
        }

        if ($node instanceof Trait_) {
            return ClassLikeKind::TRAIT;
        }

        if ($node instanceof Enum_) {
            return ClassLikeKind::ENUM;
        }

        throw new LogicException('Unknown class-like node');
    }

    /**
     * @return list<EnumCase>
     */
    private function getEnumCases(ClassLike $node): array
    {
        if (!$node instanceof Enum_) {
            return [];
        }

        $result = [];

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof EnumCase) {
                $result[] = $stmt;
            }
        }

        return $result;
    }

}
