<?php

namespace NetteContainerProvider;

class RegisteredScalar // - NetteContainerProvider\RegisteredScalar
{
    public function __construct() {}
}

class RegisteredNamed // name: NetteContainerProvider\RegisteredNamed
{
    public function __construct() {}
}

class RegisteredEntity // entity: NetteContainerProvider\RegisteredEntity(arg)
{
    public function __construct(string $arg) {}
}

class RegisteredViaCreate // viaCreate: { create: NetteContainerProvider\RegisteredViaCreate }
{
    public function __construct() {}
}

class RegisteredViaFactoryKey // viaFactory: { factory: NetteContainerProvider\RegisteredViaFactoryKey }
{
    public function __construct() {}
}

class RegisteredViaClass // viaClass: { class: NetteContainerProvider\RegisteredViaClass }
{
    public function __construct() {}
}

class RegisteredViaType // viaType: { type: NetteContainerProvider\RegisteredViaType }
{
    public function __construct() {}
}

class ServiceParent // not in container, but RegisteredChild inherits its constructor
{
    public function __construct() {}
}

class RegisteredChild extends ServiceParent // child: NetteContainerProvider\RegisteredChild (inherits constructor)
{
}

class ImportedService // imported: { type: NetteContainerProvider\ImportedService, imported: true }
{
    public function __construct() {} // error: Unused NetteContainerProvider\ImportedService::__construct
}

class NotRegistered
{
    public function __construct() {} // error: Unused NetteContainerProvider\NotRegistered::__construct
}

class StaticFactoryService
{
    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    public static function notFactory(): void {} // error: Unused NetteContainerProvider\StaticFactoryService::notFactory
}

class StaticFactoryViaCreate
{
    public function __construct(int $arg) {}

    public static function create(int $arg): self
    {
        return new self($arg);
    }
}

class RegisteredWithArgument
{
    public function __construct(InstantiatedArgument $argument) {}
}

class InstantiatedArgument
{
    public function __construct() {}
}

class ImportedWithArgument
{
    public function __construct() {} // error: Unused NetteContainerProvider\ImportedWithArgument::__construct
}

class NotInstantiatedImportedArgument
{
    public function __construct() {} // error: Unused NetteContainerProvider\NotInstantiatedImportedArgument::__construct
}

class InParameters
{
    public function __construct() {} // error: Unused NetteContainerProvider\InParameters::__construct
}

class ExtensionStatement
{
    public function __construct(?string $arg) {}
}

class StaticFactoryWithoutParentheses
{
    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    public static function createViaCreateKey(): self
    {
        return new self();
    }

    public static function createViaArrayCallable(): self
    {
        return new self();
    }

    public static function createViaCreateKeyArrayCallable(): self
    {
        return new self();
    }

    public static function register(RegisteredWithArgument $service): void {}
}
