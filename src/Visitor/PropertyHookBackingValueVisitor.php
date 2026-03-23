<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure as ClosureExpr;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

final class PropertyHookBackingValueVisitor extends NodeVisitorAbstract
{

    private bool $found = false;

    public function __construct(
        private readonly string $propertyName,
    )
    {
    }

    public function isBackedProperty(): bool
    {
        return $this->found;
    }

    public function enterNode(Node $node): ?int
    {
        // closures and anonymous classes are separate compilation units,
        // $this->prop inside them does not count as backing value reference
        if ($node instanceof ClosureExpr || $node instanceof ArrowFunction || $node instanceof Class_) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if (
            ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch)
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && !($node->name instanceof Expr)
            && $node->name->toString() === $this->propertyName
        ) {
            $this->found = true;
            return NodeVisitor::STOP_TRAVERSAL;
        }

        return null;
    }

}
