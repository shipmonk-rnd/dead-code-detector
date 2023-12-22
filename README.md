# Dead code detector

PHPStan rules to find dead code in your project.

## Installation:

```sh
composer require --dev shipmonk/dead-code-detector
```

Use [official extension-installer](https://phpstan.org/user-guide/extension-library#installing-extensions) or enable just load the rules:

```neon
includes:
    - vendor/shipmonk/phpstan-rules/rules.neon
```


## Configuration:
- You need to mark all entrypoints of your code to get proper results.
- This is typically long whitelist of all code that is called by your framework and libraries.

```neon
services:
    -
        class: App\SymfonyEntrypointProvider
        tags:
            - shipmonk.deadCode.entrypointProvider
```
```php
class SymfonyEntrypointProvider implements EntrypointProvider
{

    public function __construct(
        private ReflectionProvider $reflectionProvider
    ) {}

    public function isEntrypoint(ReflectionMethod $method): bool
    {
        $methodName = $method->getName();
        $reflection = $this->reflectionProvider->getClass($method->getDeclaringClass()->getName());

        return $reflection->is(\Symfony\Bundle\FrameworkBundle\Controller\AbstractController::class)
            || $reflection->is(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class)
            || $reflection->is(\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface::class)
            || ($reflection->is(\Symfony\Component\Console\Command\Command::class) && in_array($methodName, ['execute', 'initialize', ...], true)
            // and many more
    }
}
```

## Limitations
This project is currently a working prototype with limited functionality:

- Only method calls are detected
  - Including static methods, trait methods, interface methods, first class callables, etc.
  - Callbacks like `[$this, 'method']` are mostly not detected
  - Any calls on mixed types are not detected, e.g. `$unknownClass->method()`
  - Expression method calls are not detected, e.g. `$this->$methodName()`
  - Anonymous classes are ignored
  - Does not check constructor calls
  - Does not check magic methods
  - No transitive check is performed (dead method called only from dead method)
  - No dead cycles are detected (e.g. dead method calling itself)
- Performance is not yet optimized
  - Expect higher memory usage

## Contributing
- Check your code by `composer check`
- Autofix coding-style by `composer fix:cs`
- All functionality must be tested
