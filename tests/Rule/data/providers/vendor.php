<?php declare(strict_types = 1);

namespace Default;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Properties\ReadWritePropertiesExtensionProvider;
use PHPStan\Rules\Rule as RuleFromVendor;
use PHPUnit\Framework\TestCase;

interface IMyRule extends RuleFromVendor
{
    public function getNodeType(): string;
}

interface MyPropertiesExtensionProvider extends ReadWritePropertiesExtensionProvider {

    public const EXTENSION_TAG = 'overridden-from-vendor';

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

class MyTest extends TestCase {
    protected $backupGlobals;
    protected $dead; // error: Property Default\MyTest::$dead is never read
}
