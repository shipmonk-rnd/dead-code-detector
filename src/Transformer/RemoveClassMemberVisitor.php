<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Transformer;

use PhpParser\Node;
use PhpParser\Node\Const_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use function array_fill_keys;
use function ltrim;

class RemoveClassMemberVisitor extends NodeVisitorAbstract
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
     * @param list<string> $deadMethods
     * @param list<string> $deadConstants
     */
    public function __construct(
        array $deadMethods,
        array $deadConstants
    )
    {
        $this->deadMethods = array_fill_keys($deadMethods, true);
        $this->deadConstants = array_fill_keys($deadConstants, true);
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
