<?php declare(strict_types = 1);

namespace Symfony;

use Doctrine\ORM\Mapping\Entity;
use ShipMonk\Logging\Logger;
use ShipMonk\Logging\StaticLogger;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Interact;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\DependencyInjection\Attribute\TaggedLocator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Component\Validator\Constraints as Assert;

class SomeController {

    #[Route('/some', name: 'some')]
    public function getAction(): void {
    }

    #[Required]
    private function setDependency(): void
    {
    }

    #[Unknown]
    public function dead(): void // error: Unused Symfony\SomeController::dead
    {
    }

}

#[AsCommand(name: 'app:create-user')]
class CreateUserCommand extends Command {

    public function __construct() {
        parent::__construct();
    }

    #[Interact]
    public function prompt() {
    }
}

class FooBundle extends Bundle {
    public function __construct() {
    }
}

class SomeSubscriber implements EventSubscriberInterface
{

    public function string(): void
    {
    }

    public function stringInArray(): void
    {
    }

    public function stringInArrayArray(): void
    {
    }

    public function onNonsense(): void // error: Unused Symfony\SomeSubscriber::onNonsense
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.exception' => 'string',
            'kernel.controller' => ['stringInArray', 1],
            'kernel.request' => [['stringInArrayArray', 0]],
        ];
    }

}

#[AsController]
class HelloController
{
    public function __construct() {}
}

#[AsCommand('name')]
class HelloCommand
{
    public function __construct() {}
}

enum InvokableCommandMode: string {
    case Dry = 'dry';
    case Wet = 'wet';
}

enum InvokableCommandUnused: string {
    case A = 'a';
    case B = 'b'; // error: Unused Symfony\InvokableCommandUnused::B
}

enum InvokableCommandModeViaExtend: string {
    case Fast = 'fast';
    case Slow = 'slow';
}

#[AsCommand('app:invokable')]
class InvokableCommand
{
    public function __invoke(InvokableCommandMode $mode): int
    {
        InvokableCommandUnused::A;
        return 0;
    }
}

class InvokableCommandViaExtend extends Command
{
    public function __invoke(InvokableCommandModeViaExtend $mode): int
    {
        return 0;
    }
}

class DicClassParent { // not present in DIC, but ctor is not dead
    public function __construct() {}
}

class DicClass1 extends DicClassParent {
    public function calledViaDic(): void {}
    public function calledViaDicFactory(): void {}
}

class DicClass2 {
    public function __construct() {}
    public function calledViaDicFactory(): void {}
    public function calledViaAnonymousServiceFactory(): void {}
}

class DicClass3 {
    public function __construct() {}
    public function create(): self {
        return new self();
    }
}

class DicClass4 {
    public function __construct() {}
}

class DicErroredService {
    public function __construct(string $name) {} // error: Unused Symfony\DicErroredService::__construct
}

class DicExcludedService {
    public function __construct() {} // error: Unused Symfony\DicExcludedService::__construct
}

class DicSyntheticService {
    public function __construct() {} // error: Unused Symfony\DicSyntheticService::__construct
}

class DicAbstractService {
    public function __construct() {} // error: Unused Symfony\DicAbstractService::__construct
}

class Sftp {
    const RETRY_LIMIT = 3; // used in yaml via !php/const
}

class ModelValidator
{
    public static function validate(): void {}
}

#[Assert\Callback([ModelValidator::class, 'validate'])]
#[Assert\Callback('validateBar')]
#[Assert\Callback(callback: 'validateBaz')]
class ValidatedModel
{
    #[Assert\Callback]
    public function validateFoo(): void {}

    public function validateBar(): void {}

    public function validateBaz(): void {}
}


#[UniqueEntity(repositoryMethod: 'findByUniqueName')]
#[Entity(repositoryClass: CompanyRepository::class)]
class Company {}

class CompanyRepository {
    public function findByUniqueName(): void {}
}

class SomeController3 {

    #[Route('/tagged', name: 'tagged')]
    public function getAction(
        #[TaggedIterator(TaggedInterface::class, defaultIndexMethod: 'getIndex')]
        $iterator,
        #[TaggedLocator(TaggedInterface::class, defaultIndexMethod: 'getKey')]
        $locator
    ): void {
    }

}

interface TaggedInterface {
    public function getIndex(): string;
    public function getKey(): string;
}

#[Autoconfigure(constructor: 'create')]
class AutoconfiguredFactory {
    public static function create(): self {
        return new self();
    }
}

#[Autoconfigure(calls: [['setLogger']])]
class AutoconfiguredWithCalls {
    public function setLogger(): void {}
    public function dead(): void {} // error: Unused Symfony\AutoconfiguredWithCalls::dead
}

#[Autoconfigure(calls: [['setCache' => ['@redis_cache']]])]
class AutoconfiguredWithCallsKeyFormat {
    public function setCache(): void {}
    public function dead(): void {} // error: Unused Symfony\AutoconfiguredWithCallsKeyFormat::dead
}

class RequiredPropertyService {
    #[Required]
    public object $dependency; // error: Property Symfony\RequiredPropertyService::$dependency is never read

    public object $unused; // error: Property Symfony\RequiredPropertyService::$unused is never read // error: Property Symfony\RequiredPropertyService::$unused is never written
}

class ImportInput {
    #[\Symfony\Component\Console\Attribute\Argument]
    public string $file;

    #[\Symfony\Component\Console\Attribute\Option]
    public bool $force = false;

    public string $notAnInput; // error: Property Symfony\ImportInput::$notAnInput is never read // error: Property Symfony\ImportInput::$notAnInput is never written

    #[Interact]
    public function askForConfirmation(): void {}
}

#[AsCommand(name: 'app:import')]
class ImportCommand extends Command {
    public function __invoke(
        #[\Symfony\Component\Console\Attribute\MapInput] ImportInput $input,
    ): int {
        echo $input->file;
        return 0;
    }
}

class OrphanedInput {
    #[\Symfony\Component\Console\Attribute\Argument]
    public string $name; // error: Property Symfony\OrphanedInput::$name is never read // error: Property Symfony\OrphanedInput::$name is never written

    #[Interact]
    public function askSomething(): void {} // error: Unused Symfony\OrphanedInput::askSomething
}

class NestedFiltersInput {
    #[\Symfony\Component\Console\Attribute\Argument]
    public string $tag;

    #[\Symfony\Component\Console\Attribute\Option]
    public bool $strict = false;

    public string $notAnInput; // error: Property Symfony\NestedFiltersInput::$notAnInput is never read // error: Property Symfony\NestedFiltersInput::$notAnInput is never written

    #[Interact]
    public function askForTag(): void {}

    public function deadOnNested(): void {} // error: Unused Symfony\NestedFiltersInput::deadOnNested
}

class WrappedImportInput {
    #[\Symfony\Component\Console\Attribute\Argument]
    public string $name;

    #[\Symfony\Component\Console\Attribute\MapInput]
    public NestedFiltersInput $filters;
}

#[AsCommand(name: 'app:import-wrapped')]
class WrappedImportCommand extends Command {
    public function __invoke(
        #[\Symfony\Component\Console\Attribute\MapInput] WrappedImportInput $input,
    ): int {
        echo $input->name;
        echo $input->filters->tag;
        return 0;
    }
}

class EventListenerOnMethodNotInDic
{
    public function __construct() {} // ctor required to invoke the #[AsEventListener] method below

    #[AsEventListener]
    public function __invoke(object $event): void {}
}

#[AsEventListener]
class EventListenerOnClassNotInDic
{
    public function __construct() {} // ctor required to invoke the listener

    public function __invoke(object $event): void {}
}

class CreateUserDto {
    public function __construct(
        public readonly string $name,
        public readonly string $email,
    ) {}
}

class MapPayloadController {
    #[Route('/api/users', methods: ['POST'])]
    public function create(
        #[\Symfony\Component\HttpKernel\Attribute\MapRequestPayload] CreateUserDto $dto,
    ): void {
        echo $dto->name;
        echo $dto->email;
    }
}

class QueryStringDto {
    public function __construct(
        public readonly int $page,
        public readonly int $limit, // error: Property Symfony\QueryStringDto::$limit is never read
    ) {}
}

class QueryStringController {
    #[Route('/api/items')]
    public function list(
        #[\Symfony\Component\HttpKernel\Attribute\MapQueryString] QueryStringDto $query,
    ): void {
        echo $query->page;
    }
}

class NullableQueryDto {
    public function __construct(
        public readonly string $sort,
    ) {}
}

class NullableQueryController {
    #[Route('/api/nullable')]
    public function list(
        #[\Symfony\Component\HttpKernel\Attribute\MapQueryString] ?NullableQueryDto $query = null,
    ): void {
        echo $query?->sort;
    }
}

class SetterBasedDto {
    private string $name; // error: Property Symfony\SetterBasedDto::$name is never read

    public function setName(string $name): void
    {
        $this->name = $name;
    }
}

class SetterController {
    #[Route('/api/setter')]
    public function create(
        #[\Symfony\Component\HttpKernel\Attribute\MapRequestPayload] SetterBasedDto $dto,
    ): void {}
}
