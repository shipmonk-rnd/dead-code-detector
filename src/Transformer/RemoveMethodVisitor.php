<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Transformer;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use function array_fill_keys;
use function ltrim;

class RemoveMethodVisitor extends NodeVisitorAbstract
{

    private string $currentNamespace = '';

    private string $currentClass = '';

    /**
     * @var array<string, true>
     */
    private array $deadMethods;

    /**
     * @param list<string> $deadMethods
     */
    public function __construct(array $deadMethods)
    {
        $this->deadMethods = array_fill_keys($deadMethods, true);
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
            $methodKey = ltrim($this->currentNamespace . '\\' . $this->currentClass, '\\') . '::' . $node->name->name;

            if (isset($this->deadMethods[$methodKey])) {
                return NodeTraverser::REMOVE_NODE;
            }
        }

        return null;
    }

}
