# Dead Code Detector

PHPStan extension that finds unused class members (methods, constants, enum cases, properties) in a PHP codebase, including dead cycles and transitively dead members. Can automatically remove detected dead code.

## How detection works

1. **Regular member usages** are collected from AST by `MethodCallCollector` and others (method calls, static calls, constructors, property accesses, constant fetches, etc.). These are standard visible usages in source code.

2. **Vendor-overridden members** are handled by `VendorUsageProvider`. When your class implements an interface or overrides a method from `vendor/`, it is marked as used because the vendor code is expected to call it. Library-specific providers must NOT re-emit these — the vendor provider already covers them.

3. **Truly magic calls** are invisible in source code and must be emitted by library-specific providers (e.g. Symfony DIC calling constructors, Nette `handleXxx`/`renderXxx` signal methods, Doctrine lifecycle listeners). These providers must 100% match what the framework actually does at runtime — ALWAYS verify against the vendor source code.

## Supported members

- Methods, constants, enum cases, properties are detected.
- Magic methods (`__get`, `__set`, `__call`, etc.) are never reported as dead. Only `__construct` and `__clone` are analyzed.

## Verifying code

- `composer check` - run all checks (composer, editorconfig, codesniffer, phpstan, coverage, collisions, dependencies)
- `composer fix:cs` - autofix coding style
- `composer check:types` - run PHPStan analysis only
- `composer check:tests` - run PHPUnit tests only

## Other rules

- All code (functions, syntax, dependencies) MUST be compatible with PHP 7.4+.
- Library usage providers must not create or mimic 3rd party class stubs. Instead, install the dependency as a dev dependency in composer.json.
