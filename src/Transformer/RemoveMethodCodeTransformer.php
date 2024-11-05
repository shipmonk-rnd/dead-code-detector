<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Transformer;

use LogicException;
use PhpParser\Lexer;
use PhpParser\NodeTraverser as PhpTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\Parser\Php8;
use PhpParser\PrettyPrinter\Standard as PhpPrinter;

class RemoveMethodCodeTransformer
{

    private Lexer $phpLexer;

    private Parser $phpParser;

    private PhpTraverser $cloningTraverser;

    private PhpTraverser $removingTraverser;

    private PhpPrinter $phpPrinter;

    /**
     * @param list<string> $deadMethodKeys
     */
    public function __construct(array $deadMethodKeys)
    {
        $this->phpLexer = new Lexer();
        $this->phpParser = new Php8($this->phpLexer);

        $this->cloningTraverser = new PhpTraverser();
        $this->cloningTraverser->addVisitor(new CloningVisitor());

        $this->removingTraverser = new PhpTraverser();
        $this->removingTraverser->addVisitor(new RemoveMethodVisitor($deadMethodKeys));

        $this->phpPrinter = new PhpPrinter();
    }

    public function transformCode(string $oldCode): string
    {
        $oldAst = $this->phpParser->parse($oldCode);

        if ($oldAst === null) {
            throw new LogicException('Failed to parse the code');
        }

        $oldTokens = $this->phpLexer->tokenize($oldCode);
        $newAst = $this->removingTraverser->traverse($this->cloningTraverser->traverse($oldAst));
        return $this->phpPrinter->printFormatPreserving($newAst, $oldAst, $oldTokens);
    }

}
