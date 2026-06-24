<?php declare(strict_types = 1);

namespace PhpatProvider;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

// Registered via the "phpat.test" service tag (see DeadCodeRuleTest container mock).
// phpat invokes its public test* / #[TestRule] methods, so they are not dead.
class RegisteredArchitectureTest
{

    public function testSrcShouldNotDependOnTests(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::AllOf())
            ->shouldNotDependOn()
            ->classes(Selector::AllOf());
    }

    #[TestRule]
    public function customNamedRule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::AllOf())
            ->shouldNotDependOn()
            ->classes(Selector::AllOf());
    }

    public function unusedHelper(): void // error: Unused PhpatProvider\RegisteredArchitectureTest::unusedHelper
    {
    }

}

// Not registered as a phpat test → its test method really is dead.
class UnregisteredArchitectureTest
{

    public function testOrphanRule(): Rule // error: Unused PhpatProvider\UnregisteredArchitectureTest::testOrphanRule
    {
        return PHPat::rule()
            ->classes(Selector::AllOf())
            ->shouldNotDependOn()
            ->classes(Selector::AllOf());
    }

}
