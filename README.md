# Dead code detector for PHP

[PHPStan](https://phpstan.org/) extension to find unused PHP code in your project with ease!

## Installation:

```sh
composer require --dev shipmonk/dead-code-detector
```

Use [official extension-installer](https://phpstan.org/user-guide/extension-library#installing-extensions) or just load the rules:

```neon
includes:
    - vendor/shipmonk/dead-code-detector/rules.neon
```

## Supported libraries
- Any overridden method that originates in `vendor` is not reported as dead
- We also support many magic calls in following libraries:

#### Symfony:
- **constructor calls for DIC services!**
   - [`phpstan/phpstan-symfony`](https://github.com/phpstan/phpstan-symfony) with `containerXmlPath` must be used
- `#[AsEventListener]` attribute
- `#[AsController]` attribute
- `#[AsCommand]` attribute
- `#[Required]` attribute
- `#[Route]` attributes
- `onKernelResponse`, `onKernelRequest`, etc

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


All those libraries are autoenabled when found within your composer dependencies. If you want to force enable/disable some of them, you can:

```neon
# phpstan.neon.dist
parameters:
    shipmonkDeadCode:
        entrypoints:
            phpunit:
                enabled: true
```

## Customization:
- If your application does some magic calls unknown to this library, you can implement your own entrypoint provider.
- Just tag it with `shipmonk.deadCode.entrypointProvider` and implement `ShipMonk\PHPStan\DeadCode\Provider\MethodEntrypointProvider`
- You can simplify your implementation by extending `ShipMonk\PHPStan\DeadCode\Provider\SimpleMethodEntrypointProvider`

```neon
# phpstan.neon.dist
services:
    -
        class: App\ApiOutputEntrypointProvider
        tags:
            - shipmonk.deadCode.entrypointProvider
```
```php

use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Provider\SimpleMethodEntrypointProvider;

class ApiOutputEntrypointProvider extends SimpleMethodEntrypointProvider
{

    public function isEntrypointMethod(ReflectionMethod $method): bool
    {
        return $method->getDeclaringClass()->implementsInterface(ApiOutput::class));
    }
}
```

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
         💡 Thus App\Entity\Address::setZip is transitively also unused
 ------ ------------------------------------------------------------------------
```

- If you want to report all dead methods individually, you can enable it in your `phpstan.neon.dist`:

```neon
parameters:
    shipmonkDeadCode:
        reportTransitivelyDeadMethodAsSeparateError: true
```

## Comparison with tomasvotruba/unused-public
- You can see [detailed comparison PR](https://github.com/shipmonk-rnd/dead-code-detector/pull/53)
- Basically, their analysis is less precise and less flexible. Mainly:
  - It cannot detect dead constructors
  - It does not properly detect calls within inheritance hierarchy
  - It does not offer any custom adjustments of used methods
  - It has almost no built-it library extensions
  - It ignores trait methods
  - Is lacks many minor features like class-string calls, dynamic method calls, array callbacks, nullsafe call chains etc

## Limitations:

- Only method calls are detected so far
  - Including **constructors**, static methods, trait methods, interface methods, first class callables, clone, etc.
  - Any calls on mixed types are not detected, e.g. `$unknownClass->method()`
  - Methods of anonymous classes are never reported as dead ([PHPStan limitation](https://github.com/phpstan/phpstan/issues/8410))
  - Most magic methods (e.g. `__get`, `__set` etc) are never reported as dead

## Contributing
- Check your code by `composer check`
- Autofix coding-style by `composer fix:cs`
- All functionality must be tested

## Supported PHP versions
- PHP 7.4 - 8.3
