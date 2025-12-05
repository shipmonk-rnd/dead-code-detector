<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\NodeVisitorAbstract;

final class PropertyWriteVisitor extends NodeVisitorAbstract
{

    public const IS_PROPERTY_WRITE = '_sm_is_property_write';


    /**
     * @param Node[] $nodes
     * @return Node[]|null
     */
    public function beforeTraverse(array $nodes): ?array
    {
        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (
            ($node instanceof Assign || $node instanceof AssignRef)
            &&
            ($node->var instanceof PropertyFetch || $node->var instanceof StaticPropertyFetch) // TODO nullsafe?
        ) {
            $node->var->setAttribute(self::IS_PROPERTY_WRITE, true);
        }

        return null;
    }

}
