<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Transformer;

use PhpParser\Node;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use function array_filter;
use function is_string;
use function ltrim;

final class RemoveClassMemberVisitor extends NodeVisitorAbstract
{

    private string $currentNamespace = '';

    private string $currentClass = '';

    /**
     * @var array<string, array<int, array<string, mixed>>> className => [type => [memberName => mixed]]
     */
    private array $deadMembers;

    /**
     * @param array<string, array<int, array<string, mixed>>> $deadMembers className => [type => [memberName => mixed]]
     */
    public function __construct(
        array $deadMembers
    )
    {
        $this->deadMembers = $deadMembers;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Namespace_ && $node->name !== null) {
            $this->currentNamespace = $node->name->toString();

        } elseif ($node instanceof ClassLike && $node->name !== null) {
            $this->currentClass = $node->name->name;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof ClassMethod) {
            if (isset($this->deadMembers[$this->getCurrentClass()][MemberType::METHOD][$node->name->name])) {
                return NodeTraverser::REMOVE_NODE;
            }

            // Handle promoted properties in constructor parameters
            $node->params = array_filter($node->params, function (Param $param): bool {
                if (!$param->isPromoted() || !$param->var instanceof Variable) {
                    return true;
                }

                $paramName = $param->var->name;

                if (!is_string($paramName)) {
                    return true;
                }

                return !isset($this->deadMembers[$this->getCurrentClass()][MemberType::PROPERTY][$paramName]);
            });
        }

        if ($node instanceof ClassConst) {
            $allDead = true;

            foreach ($node->consts as $const) {
                if (!isset($this->deadMembers[$this->getCurrentClass()][MemberType::CONSTANT][$const->name->name])) {
                    $allDead = false;
                    break;
                }
            }

            if ($allDead) {
                return NodeTraverser::REMOVE_NODE;
            }
        }

        if ($node instanceof Const_) {
            if (isset($this->deadMembers[$this->getCurrentClass()][MemberType::CONSTANT][$node->name->name])) {
                return NodeTraverser::REMOVE_NODE;
            }
        }

        if ($node instanceof EnumCase) {
            if (isset($this->deadMembers[$this->getCurrentClass()][MemberType::CONSTANT][$node->name->name])) {
                return NodeTraverser::REMOVE_NODE;
            }
        }

        if ($node instanceof Property) {
            $allDead = true;

            foreach ($node->props as $prop) {
                if (!isset($this->deadMembers[$this->getCurrentClass()][MemberType::PROPERTY][$prop->name->name])) {
                    $allDead = false;
                    break;
                }
            }

            if ($allDead) {
                return NodeTraverser::REMOVE_NODE;
            }
        }

        return null;
    }

    private function getCurrentClass(): string
    {
        return ltrim($this->currentNamespace . '\\' . $this->currentClass, '\\');
    }

}
