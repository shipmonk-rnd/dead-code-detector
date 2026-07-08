<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Visitor;

use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Node;

final class PhpDocConstFetchCollectingVisitor extends AbstractNodeVisitor
{

    /**
     * @var list<ConstFetchNode>
     */
    private array $constFetchNodes = [];

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof ConstFetchNode && $node->className !== '') {
            $this->constFetchNodes[] = $node;
        }

        return null;
    }

    /**
     * @return list<ConstFetchNode>
     */
    public function getConstFetchNodes(): array
    {
        return $this->constFetchNodes;
    }

}
