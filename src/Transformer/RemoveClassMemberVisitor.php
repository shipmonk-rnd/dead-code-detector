<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Transformer;

use PhpParser\Node;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use function array_filter;
use function array_key_last;
use function array_pop;
use function is_string;

final class RemoveClassMemberVisitor extends NodeVisitorAbstract
{

    /**
     * @var list<string|null> fully qualified names filled by NameResolver; null stands for anonymous class
     */
    private array $currentClassStack = [];

    /**
     * @param array<string, array<value-of<MemberType>, array<string, mixed>>> $deadMembers className => [type => [memberName => mixed]]
     */
    public function __construct(
        private readonly array $deadMembers,
    )
    {
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof ClassLike) {
            $this->currentClassStack[] = $node->namespacedName?->toString();
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof ClassLike) {
            array_pop($this->currentClassStack);
            return null;
        }

        $currentClass = $this->getCurrentClass();

        if ($currentClass === null) {
            return null;
        }

        if ($node instanceof ClassMethod) {
            if (isset($this->deadMembers[$currentClass][MemberType::METHOD->value][$node->name->name])) {
                return NodeTraverser::REMOVE_NODE;
            }

            // Handle promoted properties in constructor parameters
            $node->params = array_filter($node->params, function (Param $param) use ($currentClass): bool {
                if (!$param->isPromoted() || !$param->var instanceof Variable) {
                    return true;
                }

                $paramName = $param->var->name;

                if (!is_string($paramName)) {
                    return true;
                }

                return !isset($this->deadMembers[$currentClass][MemberType::PROPERTY->value][$paramName]);
            });
        }

        if ($node instanceof ClassConst && $node->consts === []) {
            return NodeTraverser::REMOVE_NODE;
        }

        if ($node instanceof Const_) {
            if (isset($this->deadMembers[$currentClass][MemberType::CONSTANT->value][$node->name->name])) {
                return NodeTraverser::REMOVE_NODE;
            }
        }

        if ($node instanceof EnumCase) {
            if (isset($this->deadMembers[$currentClass][MemberType::CONSTANT->value][$node->name->name])) {
                return NodeTraverser::REMOVE_NODE;
            }
        }

        if ($node instanceof PropertyItem) {
            if (isset($this->deadMembers[$currentClass][MemberType::PROPERTY->value][$node->name->name])) {
                return NodeTraverser::REMOVE_NODE;
            }
        }

        if ($node instanceof Property && $node->props === []) {
            return NodeTraverser::REMOVE_NODE;
        }

        return null;
    }

    /**
     * Null when outside of any class; dead members inside anonymous classes are never removed
     */
    private function getCurrentClass(): ?string
    {
        $lastKey = array_key_last($this->currentClassStack);
        return $lastKey === null ? null : $this->currentClassStack[$lastKey];
    }

}
