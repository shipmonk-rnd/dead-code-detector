<?php declare(strict_types = 1);

namespace Default;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule as RuleFromVendor;

interface IMyRule extends RuleFromVendor
{
    public function getNodeType(): string;
}

class MyRule implements IMyRule {

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

class MyRuleDirect implements RuleFromVendor
{

    public function getNodeType(): string
    {
        return '';
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return [];
    }

}