<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Transformer;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeVisitorAbstract;
use function ltrim;

class RemoveMethodVisitor extends NodeVisitorAbstract
{

    private string $currentNamespace = '';

    private string $currentClass = '';

    /**
     * @var array<string, mixed>
     */
    private array $deadMethods;

    /**
     * @param array<string, mixed> $deadMethods
     */
    public function __construct(array $deadMethods)
    {
        $this->deadMethods = $deadMethods;
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
                return 3;
            }
        }

        return null;
    }

}
