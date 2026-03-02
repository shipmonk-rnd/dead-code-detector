<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Transformer;

use LogicException;
use PhpParser\Lexer;
use PhpParser\NodeTraverser as PhpTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\Parser\Php8;
use PhpParser\PrettyPrinter\Standard as PhpPrinter;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

final class RemoveDeadCodeTransformer
{

    private readonly Lexer $phpLexer;

    private readonly Parser $phpParser;

    private readonly PhpTraverser $cloningTraverser;

    private readonly PhpTraverser $removingTraverser;

    private readonly PhpPrinter $phpPrinter;

    /**
     * @param array<string, array<value-of<MemberType>, array<string, mixed>>> $deadMembersByClass className => [type => [memberName => mixed]]
     */
    public function __construct(
        array $deadMembersByClass,
    )
    {
        $this->phpLexer = new Lexer();
        $this->phpParser = new Php8($this->phpLexer);

        $this->cloningTraverser = new PhpTraverser();
        $this->cloningTraverser->addVisitor(new CloningVisitor());

        $this->removingTraverser = new PhpTraverser();
        $this->removingTraverser->addVisitor(new RemoveClassMemberVisitor($deadMembersByClass));

        $this->phpPrinter = new PhpPrinter();
    }

    public function transformCode(string $oldCode): string
    {
        $oldAst = $this->phpParser->parse($oldCode);

        if ($oldAst === null) {
            throw new LogicException('Failed to parse the code');
        }

        $oldTokens = $this->phpParser->getTokens();
        $newAst = $this->removingTraverser->traverse($this->cloningTraverser->traverse($oldAst));
        return $this->phpPrinter->printFormatPreserving($newAst, $oldAst, $oldTokens);
    }

}
