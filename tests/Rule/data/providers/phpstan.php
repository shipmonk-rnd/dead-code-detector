<?php declare(strict_types = 1);

namespace PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;

class MyRule implements \PHPStan\Rules\Rule {

    public function __construct()
    {
    }

    public function getNodeType(): string
    {
        return '';
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return [];
    }
}
