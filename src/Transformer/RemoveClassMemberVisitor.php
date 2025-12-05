<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Transformer;

use PhpParser\Node;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use function array_fill_keys;
use function array_filter;
use function is_string;
use function ltrim;

final class RemoveClassMemberVisitor extends NodeVisitorAbstract
{

    private string $currentNamespace = '';

    private string $currentClass = '';

    /**
     * @var array<string, true>
     */
    private array $deadMethods;

    /**
     * @var array<string, true>
     */
    private array $deadConstants;

    /**
     * @var array<string, true>
     */
    private array $deadProperties;

    /**
     * @param list<string> $deadMethods
     * @param list<string> $deadConstants
     * @param list<string> $deadProperties
     */
    public function __construct(
        array $deadMethods,
        array $deadConstants,
        array $deadProperties
    )
    {
        $this->deadMethods = array_fill_keys($deadMethods, true);
        $this->deadConstants = array_fill_keys($deadConstants, true);
        $this->deadProperties = array_fill_keys($deadProperties, true);
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
            $methodKey = $this->getNamespacedName($node->name);

            if (isset($this->deadMethods[$methodKey])) {
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

                $propertyKey = ltrim($this->currentNamespace . '\\' . $this->currentClass, '\\') . '::' . $paramName;

                return !isset($this->deadProperties[$propertyKey]);
            });
        }

        if ($node instanceof ClassConst) {
            $allDead = true;

            foreach ($node->consts as $const) {
                $constKey = $this->getNamespacedName($const->name);

                if (!isset($this->deadConstants[$constKey])) {
                    $allDead = false;
                    break;
                }
            }

            if ($allDead) {
                return NodeTraverser::REMOVE_NODE;
            }
        }

        if ($node instanceof Const_) {
            $constKey = $this->getNamespacedName($node->name);

            if (isset($this->deadConstants[$constKey])) {
                return NodeTraverser::REMOVE_NODE;
            }
        }

        if ($node instanceof EnumCase) {
            $enumCaseKey = $this->getNamespacedName($node->name);

            if (isset($this->deadConstants[$enumCaseKey])) {
                return NodeTraverser::REMOVE_NODE;
            }
        }

        if ($node instanceof Property) {
            $allDead = true;

            foreach ($node->props as $prop) {
                $propertyKey = $this->getNamespacedName($prop->name);

                if (!isset($this->deadProperties[$propertyKey])) {
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

    /**
     * @param Name|Identifier $name
     */
    private function getNamespacedName(Node $name): string
    {
        return ltrim($this->currentNamespace . '\\' . $this->currentClass, '\\') . '::' . $name->name;
    }

}
