<?php declare(strict_types = 1);

namespace Default;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

class MyRule implements Rule {

    public function getNodeType(): string
    {
        return '';
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return [];
    }

    public function unused(): array // error: Unused Default\MyRule::unused
    {
        return [];
    }

}
