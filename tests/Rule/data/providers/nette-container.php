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
