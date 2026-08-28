<?php declare(strict_types = 1);

namespace BehatProvider;

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

class FeatureContext implements Context
{
    public function __construct()
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

    public function helperMethodNotUsed(): void // error: Unused BehatProvider\FeatureContext::helperMethodNotUsed
    {
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
    public function __construct()
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

    public function anotherHelperNotUsed(): void // error: Unused BehatProvider\AttributeContext::anotherHelperNotUsed
    {
    }
}
