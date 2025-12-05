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
use PhpParser\NodeVisitorAbstract;

final class PropertyWriteVisitor extends NodeVisitorAbstract
{

    public const IS_PROPERTY_WRITE = '_sm_is_property_write';

    public function enterNode(Node $node): ?Node
    {
        if (
            !$node instanceof Assign
            && !$node instanceof AssignRef
            && !$node instanceof AssignOp
            && !$node instanceof PostInc
            && !$node instanceof PostDec
            && !$node instanceof PreInc
            && !$node instanceof PreDec
        ) {
            return null;
        }

        $this->markPropertyWrites($node->var);

        return null;
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
