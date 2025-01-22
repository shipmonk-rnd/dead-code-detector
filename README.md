# Dead code detector for PHP

[PHPStan](https://phpstan.org/) extension to find unused PHP code in your project with ease!

## Summary:

- ✅ **PHPStan** extension
- ♻️ **Dead cycles** detection
- 🔗 **Transitive dead** member detection
- 🧹 **Automatic removal** of unused code
- 📚 **Popular libraries** support
- ✨ **Customizable** usage providers

## Installation:

```sh
composer require --dev shipmonk/dead-code-detector
```

Use [official extension-installer](https://phpstan.org/user-guide/extension-library#installing-extensions) or just load the rules:

```neon
# phpstan.neon.dist
includes:
    - vendor/shipmonk/dead-code-detector/rules.neon
```

## Supported libraries:

#### Symfony:
- **Calls made by DIC over your services!**
   - constructors, calls, factory methods
   - [`phpstan/phpstan-symfony`](https://github.com/phpstan/phpstan-symfony) with `containerXmlPath` must be used
- `#[AsEventListener]` attribute
- `#[AsController]` attribute
- `#[AsCommand]` attribute
- `#[Required]` attribute
- `#[Route]` attributes
- `EventSubscriberInterface::getSubscribedEvents`
- `onKernelResponse`, `onKernelRequest`, etc
- `!php const` references in `config` yamls

#### Doctrine:
- `#[AsEntityListener]` attribute
- `Doctrine\ORM\Events::*` events
- `Doctrine\Common\EventSubscriber` methods
- lifecycle event attributes `#[PreFlush]`, `#[PostLoad]`, ...

#### PHPUnit:
- **data provider methods**
- `testXxx` methods
- annotations like `@test`, `@before`, `@afterClass` etc
- attributes like `#[Test]`, `#[Before]`, `#[AfterClass]` etc


#### PHPStan:
- constructor calls for DIC services (rules, extensions, ...)

#### Nette:
- `handleXxx`, `renderXxx`, `actionXxx`, `injectXxx`, `createComponentXxx`
- `SmartObject` magic calls for `@property` annotations


All those libraries are autoenabled when found within your composer dependencies.
If you want to force enable/disable some of them, you can:

```neon
parameters:
    shipmonkDeadCode:
        usageProviders:
            phpunit:
                enabled: true
```

## Generic usage providers:

#### Reflection:
- Any constant or method accessed via `ReflectionClass` is detected as used
  - e.g. `$reflection->getConstructor()`, `$reflection->getConstant('NAME')`, `$reflection->getMethods()`, ...

#### Vendor:
- Any overridden method that originates in `vendor` is not reported as dead
  - e.g. implementing `Psr\Log\LoggerInterface::log` is automatically considered used

Those providers are enabled by default, but you can disable them if needed.

## Excluding usages in tests:
- By default, all usages within scanned paths can mark members as used
- But that might not be desirable if class declared in `src` is only used in `tests`
- You can exclude those usages by enabling `tests` usage excluder:

```neon
parameters:
    shipmonkDeadCode:
        usageExcluders:
            tests:
                enabled: true
```

## Customization:
- If your application does some magic calls unknown to this library, you can implement your own usage provider.
- Just tag it with `shipmonk.deadCode.memberUsageProvider` and implement `ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider`

```neon
services:
    -
        class: App\ApiOutputUsageProvider
        tags:
            - shipmonk.deadCode.memberUsageProvider
```

> [!IMPORTANT]
> _The interface & tag changed in [0.7](../../releases/tag/0.7.0). If you are using PHPStan 1.x, those were [used differently](../../blob/0.5.0/README.md#customization)._

### Reflection-based customization:
- For simple reflection usecases, you can just extend `ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider`:

```php

use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;

class ApiOutputUsageProvider extends ReflectionBasedMemberUsageProvider
{

    public function shouldMarkMethodAsUsed(ReflectionMethod $method): bool
    {
        // all methods from our ApiOutput interface are called automatically (e.g. during serialization)
        return $method->getDeclaringClass()->implementsInterface(ApiOutput::class);
    }

}
```

### AST-based customization:
- For more complex usecases that are deducible only from AST (e.g. serialization calls), you just stick with raw `MemberUsageProvider` interface:

```php

use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
use Symfony\Component\Serializer\SerializerInterface;

class DeserializationUsageProvider implements MemberUsageProvider
{

    /**
     * @return list<ClassMemberUsage>
     */
    public function getUsages(Node $node, Scope $scope): array
    {
        if (!$node instanceof MethodCall) {
            return [];
        }

        if (
            // our deserialization calls constructor
            $scope->getType($node->var)->getObjectClassNames() === [SerializerInterface::class] &&
            $node->name->toString() === 'deserialize'
        ) {
            $secondArgument = $node->getArgs()[1]->value;
            $serializedClass = $scope->getType($secondArgument)->getConstantStrings()[0];

            // record the method it was called from (needed for proper transitive dead code elimination)
            $originRef = $this->getOriginMethodRef($scope);

            // record the hidden constructor call
            $constructorRef = new ClassMethodRef($serializedClass->getValue(), '__construct', false);

            return [new ClassMethodUsage($originRef, $constructorRef)];
        }

        return [];
    }

    private function getOriginMethodRef(Scope $scope): ?ClassMethodRef
    {
        return new ClassMethodRef(
            $scope->getClassReflection()->getName(),
            $scope->getFunction()->getName(),
            false,
        );
    }

}
```

### Excluding usages:

You can exclude any usage based on custom logic, just implement `MemberUsageExcluder` and register it with `shipmonk.deadCode.memberUsageExcluder` tag:

```php

use ShipMonk\PHPStan\DeadCode\Excluder\MemberUsageExcluder;

class MyUsageExcluder implements MemberUsageExcluder
{

    public function shouldExclude(ClassMemberUsage $usage, Node $node, Scope $scope): bool
    {
        // ...
    }

}
```

```neon
# phpstan.neon.dist
services:
    -
        class: App\MyUsageExcluder
        tags:
            - shipmonk.deadCode.memberUsageExcluder
```

The same interface is used for exclusion of test-only usages, see above.

## Dead cycles & transitively dead methods
- This library automatically detects dead cycles and transitively dead methods (methods that are only called from dead methods)
- By default, it reports only the first dead method in the subtree and the rest as a tip:

```
 ------ ------------------------------------------------------------------------
  Line   src/App/Facade/UserFacade.php
 ------ ------------------------------------------------------------------------
  26     Unused App\Facade\UserFacade::updateUserAddress
         🪪  shipmonk.deadMethod
         💡 Thus App\Entity\User::updateAddress is transitively also unused
         💡 Thus App\Entity\Address::setPostalCode is transitively also unused
         💡 Thus App\Entity\Address::setCountry is transitively also unused
         💡 Thus App\Entity\Address::setStreet is transitively also unused
         💡 Thus App\Entity\Address::MAX_STREET_CHARS is transitively also unused
 ------ ------------------------------------------------------------------------
```

- If you want to report all dead methods individually, you can enable it in your `phpstan.neon.dist`:

```neon
parameters:
    shipmonkDeadCode:
        reportTransitivelyDeadMethodAsSeparateError: true
```

## Automatic removal of dead code
- If you are sure that the reported methods are dead, you can automatically remove them by running PHPStan with `removeDeadCode` error format:

```bash
vendor/bin/phpstan analyse --error-format removeDeadCode
```

```diff
class UserFacade
{
-    public const TRANSITIVELY_DEAD = 1;
-
-    public function deadMethod(): void
-    {
-        echo self::TRANSITIVELY_DEAD;
-    }
}
```


## Calls over unknown types
- In order to prevent false positives, we support even calls over unknown types (e.g. `$unknown->method()`) by marking all methods named `method` as used
  - Such behaviour might not be desired for strictly typed codebases, because e.g. single `new $unknown()` will mark all constructors as used
  - Thus, you can disable this feature in your `phpstan.neon.dist`:
- The same applies to constant fetches over unknown types (e.g. `$unknown::CONSTANT`)

```neon
parameters:
    shipmonkDeadCode:
        trackMixedAccess: false
```

- If you want to check how many of those cases are present in your codebase, you can run PHPStan analysis with `-vvv` and you will see some diagnostics:

```
Found 2 usages over unknown type:
 • setCountry method, for example in App\Entity\User::updateAddress
 • setStreet method, for example in App\Entity\User::updateAddress
```

## Comparison with tomasvotruba/unused-public
- You can see [detailed comparison PR](https://github.com/shipmonk-rnd/dead-code-detector/pull/53)
- Basically, their analysis is less precise and less flexible. Mainly:
  - It cannot detect dead constructors
  - It does not properly detect calls within inheritance hierarchy
  - It does not offer any custom adjustments of used methods
  - It has almost no built-in library extensions
  - It ignores trait methods
  - Is lacks many minor features like class-string calls, dynamic method calls, array callbacks, nullsafe call chains etc
  - It cannot detect dead cycles nor transitively dead methods
  - It has no built-in dead code removal

## Limitations:
- Methods of anonymous classes are never reported as dead ([PHPStan limitation](https://github.com/phpstan/phpstan/issues/8410))
- Abstract trait methods are never reported as dead
- Most magic methods (e.g. `__get`, `__set` etc) are never reported as dead
    - Only supported are: `__construct`, `__clone`

### Other problematic cases:

#### Constructors:
- For symfony apps & PHPStan extensions, we simplify the detection by assuming all DIC classes have used constructor.
- For other apps, you may get false-positives if services are created magically.
  - To avoid those, you can easily disable constructor analysis with single ignore:

```neon
parameters:
    ignoreErrors:
        - '#^Unused .*?::__construct$#'
```

#### Private constructors:
- Those are never reported as dead as those are often used to deny class instantiation

#### Interface methods:
- If you never call interface method over the interface, but only over its implementors, it gets reported as dead
- But you may want to keep the interface method to force some unification across implementors
  - The easiest way to ignore it is via custom `MemberUsageProvider`:

```php
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;

class IgnoreDeadInterfaceUsageProvider extends ReflectionBasedMemberUsageProvider
{
    public function shouldMarkMethodAsUsed(ReflectionMethod $method): bool
    {
        return $method->getDeclaringClass()->isInterface();
    }
}
```


## Future scope:
- Dead class property detection
- Dead class detection

## Contributing
- Check your code by `composer check`
- Autofix coding-style by `composer fix:cs`
- All functionality must be tested

## Supported PHP versions
- PHP 7.4 - 8.4
