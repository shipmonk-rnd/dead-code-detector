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
- By default, all overridden methods which declaration originates inside vendor are considered entrypoints
- Also, there are some basic entrypoint providers for `symfony` and `phpunit`
- For everything else, you can implement your own entrypoint provider

```neon
parameters:
    deadCode:
        entrypoints:
            symfony:
                enabled: true
            phpunit:
                enabled: true

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
  - Callbacks like `[$this, 'method']` are mostly not detected; prefer first class callables `$this->method(...)`
  - Any calls on mixed types are not detected, e.g. `$unknownClass->method()`
  - Expression method calls are not detected, e.g. `$this->$methodName()`
  - Anonymous classes are ignored
  - Does not check constructor calls
  - Does not check magic methods
  - No transitive check is performed (dead method called only from dead method)
  - No dead cycles are detected (e.g. dead method calling itself)

## Contributing
- Check your code by `composer check`
- Autofix coding-style by `composer fix:cs`
- All functionality must be tested

## Supported PHP versions
- v0.1: PHP 7.4 - 8.3
- v0.2: PHP 8.1 - 8.3
