<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeVisitorAbstract;
use function array_pop;
use function count;

final class PropertyWriteVisitor extends NodeVisitorAbstract
{

    public const IS_PROPERTY_WRITE = '_sm_is_property_write';
    public const IS_PROPERTY_WRITE_AND_READ = '_sm_is_property_write_and_read';

    /**
     * @var list<Node>
     */
    private array $nodeStack = [];

    public function beforeTraverse(array $nodes): ?array
    {
        $this->nodeStack = [];
        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        $this->nodeStack[] = $node;

        if (
            $node instanceof Assign
            || $node instanceof AssignRef
            || $node instanceof AssignOp
        ) {
            $this->markPropertyWrites($node->var);
        }

        if (
            $node instanceof PostInc
            || $node instanceof PostDec
            || $node instanceof PreInc
            || $node instanceof PreDec
        ) {
            $expr = $node->var;

            if ($this->isFetch($expr)) {
                $expr->setAttribute(self::IS_PROPERTY_WRITE, true);

                if (!$this->hasUnusedResult()) {
                    $expr->setAttribute(self::IS_PROPERTY_WRITE_AND_READ, true);
                }
            }
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        array_pop($this->nodeStack);
        return null;
    }

    private function hasUnusedResult(): bool
    {
        $count = count($this->nodeStack);
        if ($count < 2) {
            return false;
        }

        $parent = $this->nodeStack[$count - 2]; // @phpstan-ignore offsetAccess.notFound (Ensured by condition above)
        return $parent instanceof Expression;
    }

    private function markPropertyWrites(Expr $expr): void
    {
        if ($this->isFetch($expr)) { // $this->prop =
            $expr->setAttribute(self::IS_PROPERTY_WRITE, true);
        }

        if ($expr instanceof List_) { // [$this->first, $this->last] =
            foreach ($expr->items as $item) {
                if ($item === null) {
                    continue;
                }
                if ($this->isFetch($item->value)) {
                    $item->value->setAttribute(self::IS_PROPERTY_WRITE, true);
                }
            }
        }

        while ($expr instanceof ArrayDimFetch) { // $this->array[] =
            if ($this->isFetch($expr->var)) {
                $expr->var->setAttribute(self::IS_PROPERTY_WRITE, true);
                break;
            }
            $expr = $expr->var;
        }
    }

    private function isFetch(Expr $expr): bool
    {
        // NullsafePropertyFetch not needed: Can't use nullsafe operator in write context
        return $expr instanceof PropertyFetch || $expr instanceof StaticPropertyFetch;
    }

}
