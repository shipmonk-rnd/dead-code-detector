# Dead code detector

PHPStan rules to find dead code in your project.

## Installation:

```sh
composer require --dev shipmonk/dead-code-detector
```

Use [official extension-installer](https://phpstan.org/user-guide/extension-library#installing-extensions) or just load the rules:

```neon
includes:
    - vendor/shipmonk/dead-code-detector/rules.neon
```


## Configuration:
- All entrypoints of your code (controllers, consumers, commands, ...) need to be known to the detector to get proper results
- By default, all overridden methods which declaration originates inside `vendor` are considered entrypoints
- Also, there are some built-in providers for some magic calls that occur in `doctrine`, `nette`, `symfony`, `phpstan` and `phpunit`
- For everything else, you can implement your own entrypoint provider, just tag it with `shipmonk.deadCode.entrypointProvider` and implement `ShipMonk\PHPStan\DeadCode\Provider\EntrypointProvider`

```neon
# phpstan.neon.dist
services:
    -
        class: App\MyEntrypointProvider
        tags:
            - shipmonk.deadCode.entrypointProvider
```
```php

use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Provider\EntrypointProvider;

class MyEntrypointProvider implements EntrypointProvider
{

    public function isEntrypoint(ReflectionMethod $method): bool
    {
        return $method->getDeclaringClass()->implementsInterface(ApiOutput::class));
    }
}
```

## Limitations:

- Only method calls are detected
  - Including static methods, trait methods, interface methods, first class callables, etc.
  - Any calls on mixed types are not detected, e.g. `$unknownClass->method()`
  - Expression method calls are not detected, e.g. `$this->$methodName()`
  - Anonymous classes are ignored
  - Does not check magic methods
  - No transitive check is performed (dead method called only from dead method)
  - No dead cycles are detected (e.g. dead method calling itself)

## Contributing
- Check your code by `composer check`
- Autofix coding-style by `composer fix:cs`
- All functionality must be tested

## Supported PHP versions
- PHP 7.4 - 8.3
