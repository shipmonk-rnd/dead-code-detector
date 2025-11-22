<?php declare(strict_types = 1);

namespace Behat\Behat\Context;

interface Context
{
}

namespace Behat\Step;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Given
{
    public function __construct(public string $pattern) {}
}

#[Attribute(Attribute::TARGET_METHOD)]
class When
{
    public function __construct(public string $pattern) {}
}

#[Attribute(Attribute::TARGET_METHOD)]
class Then
{
    public function __construct(public string $pattern) {}
}

namespace Behat\Hook;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class BeforeScenario
{
}

#[Attribute(Attribute::TARGET_METHOD)]
class AfterScenario
{
}

#[Attribute(Attribute::TARGET_METHOD)]
class BeforeStep
{
}

#[Attribute(Attribute::TARGET_METHOD)]
class AfterStep
{
}

#[Attribute(Attribute::TARGET_METHOD)]
class BeforeFeature
{
}

#[Attribute(Attribute::TARGET_METHOD)]
class AfterFeature
{
}

namespace Behat\Transformation;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Transform
{
}

namespace Behat;

use Behat\Behat\Context\Context;
use Behat\Hook\AfterFeature;
use Behat\Hook\AfterScenario;
use Behat\Hook\AfterStep;
use Behat\Hook\BeforeFeature;
use Behat\Hook\BeforeScenario;
use Behat\Hook\BeforeStep;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Behat\Transformation\Transform;

class SomeService
{
}

class FeatureContext implements Context
{
    public function __construct(private SomeService $service)
    {
    }

    /**
     * @Given I have a product
     */
    public function iHaveAProduct(): void
    {
    }

    /**
     * @When I add it to the cart
     */
    public function iAddItToTheCart(): void
    {
    }

    /**
     * @Then I should see :count item in my cart
     */
    public function iShouldSeeItemInMyCart(int $count): void
    {
    }

    /**
     * @BeforeScenario
     */
    public function setUp(): void
    {
    }

    /**
     * @AfterScenario
     */
    public function tearDown(): void
    {
    }

    /**
     * @BeforeStep
     */
    public function beforeStep(): void
    {
    }

    /**
     * @AfterStep
     */
    public function afterStep(): void
    {
    }

    /**
     * @BeforeSuite
     */
    public static function setUpSuite(): void
    {
    }

    /**
     * @AfterSuite
     */
    public static function tearDownSuite(): void
    {
    }

    /**
     * @BeforeFeature
     */
    public static function setUpFeature(): void
    {
    }

    /**
     * @AfterFeature
     */
    public static function tearDownFeature(): void
    {
    }

    /**
     * @Transform :count
     */
    public function transformStringToNumber(string $count): int
    {
        return (int) $count;
    }
}

class AnotherContext implements Context
{
    /**
     * @Given /^I am on "([^"]*)" page$/
     */
    public function iAmOnPage(string $page): void
    {
    }

    /**
     * @When /^I click on "([^"]*)"$/
     */
    public function iClickOn(string $element): void
    {
    }
}

// Context using PHP 8 attributes
class AttributeContext implements Context
{
    public function __construct(private SomeService $service)
    {
    }

    #[Given('I have :count products')]
    public function iHaveProducts(int $count): void
    {
    }

    #[When('I add :count products to the cart')]
    public function iAddProductsToCart(int $count): void
    {
    }

    #[Then('I should see :count items in my cart')]
    public function iShouldSeeItemsInCart(int $count): void
    {
    }

    #[BeforeScenario]
    public function setUpWithAttribute(): void
    {
    }

    #[AfterScenario]
    public function tearDownWithAttribute(): void
    {
    }

    #[BeforeStep]
    public function beforeStepWithAttribute(): void
    {
    }

    #[AfterStep]
    public function afterStepWithAttribute(): void
    {
    }

    #[BeforeFeature]
    public static function setUpFeatureWithAttribute(): void
    {
    }

    #[AfterFeature]
    public static function tearDownFeatureWithAttribute(): void
    {
    }

    #[Transform]
    public function transformWithAttribute(string $value): int
    {
        return (int) $value;
    }
}
