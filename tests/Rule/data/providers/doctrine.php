<?php

namespace Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;

enum InvoiceStatus: string {
    case Closed = 'closed';
    case Open = 'open';
    const Unused = 'unused'; // error: Unused Doctrine\InvoiceStatus::Unused
}

class MyEntity
{

    #[\Doctrine\ORM\Mapping\Column(type: Types::STRING, enumType: InvoiceStatus::class)]
    private InvoiceStatus $status;

    private string $notMapped; // error: Property Doctrine\MyEntity::$notMapped is never read // error: Property Doctrine\MyEntity::$notMapped is never written

    #[\Doctrine\ORM\Mapping\PreUpdate]
    public function onUpdate(PreUpdateEventArgs $args): void {}

    #[\Doctrine\ORM\Mapping\PostRemove]
    public function onRemove(): void {}

}

#[\Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener(event: 'postUpdate', method: 'afterUpdate')]
#[\Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener(event: 'postPersist')]
class MyListener {

    public function afterUpdate(): void {}

    public function postPersist(): void {}

}

class FooRepository extends EntityRepository {

    public function __construct(
        EntityManagerInterface $em,
        ClassMetadata $class
    ) {
        parent::__construct($em, $class);
    }

}

class OldListenerHeuristics {

    public function postUpdate(): void {}

    public function postPersist(): void {}

    public function postGenerateSchemaTable(): void {}

    public function postGenerateSchema(): void {}

    public function deadCode(): void // error: Unused Doctrine\OldListenerHeuristics::deadCode
    {

    }

}

class MySubscriber implements \Doctrine\Common\EventSubscriber {

    public function getSubscribedEvents() {
        return [
            'someMethod',
        ];
    }

    public function someMethod(): void {}
    public function someMethod2(): void {} // error: Unused Doctrine\MySubscriber::someMethod2

}

#[\Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener(event: 'postGenerateSchema')]
class FixDoctrineMigrationTableSchema {

    public function postGenerateSchema(): void {}

    public function unusedMethod(): void {} // error: Unused Doctrine\FixDoctrineMigrationTableSchema::unusedMethod

}

#[\Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener(event: 'postLoad')]
class AsDoctrineListenerWithInvoke {

    public function __invoke(): void {}

    public function unusedMethod(): void {} // error: Unused Doctrine\AsDoctrineListenerWithInvoke::unusedMethod

}

#[\Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag('doctrine.event_listener', ['event' => 'postGenerateSchema'])]
class FixDoctrineMigrationTableSchemaWithAutoconfigureTag {

    public function postGenerateSchema(): void {}

    public function unusedMethod(): void {} // error: Unused Doctrine\FixDoctrineMigrationTableSchemaWithAutoconfigureTag::unusedMethod

}

// The doctrine.event_listener tag has no 'method' attribute, Symfony ignores such key
#[\Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag('doctrine.event_listener', ['event' => 'myTaggedCustomEvent', 'method' => 'ignoredMethod'])]
class AutoconfigureTagWithUnsupportedMethodAttribute {

    public function myTaggedCustomEvent(): void {}

    public function ignoredMethod(): void {} // error: Unused Doctrine\AutoconfigureTagWithUnsupportedMethodAttribute::ignoredMethod

}

// Test multiple AsDoctrineListener attributes on same class (IS_REPEATABLE)
#[\Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener(event: 'postPersist')]
#[\Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener(event: 'postUpdate')]
#[\Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener(event: 'preRemove')]
class MultipleAsDoctrineListeners {

    public function postPersist(): void {}

    public function postUpdate(): void {}

    public function preRemove(): void {}

    public function unusedMethod(): void {} // error: Unused Doctrine\MultipleAsDoctrineListeners::unusedMethod

}

// Test multiple AutoconfigureTag attributes
#[\Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag('doctrine.event_listener', ['event' => 'myFirstCustomEvent'])]
#[\Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag('doctrine.event_listener', ['event' => 'mySecondCustomEvent'])]
class MultipleAutoconfigureTags {

    public function myFirstCustomEvent(): void {}

    public function mySecondCustomEvent(): void {}

    public function unusedMethod(): void {} // error: Unused Doctrine\MultipleAutoconfigureTags::unusedMethod

}

// Custom event dispatched via EventManager::dispatchEvent, not present in the known ORM event list
#[\Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag('doctrine.event_listener', ['event' => 'myCustomEvent'])]
class CustomEventAutoconfigureTag {

    public function myCustomEvent(): void {}

    public function unusedMethod(): void {} // error: Unused Doctrine\CustomEventAutoconfigureTag::unusedMethod

}

// Named-argument form of the attributes array
#[\Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag(name: 'doctrine.event_listener', attributes: ['event' => 'myOtherCustomEvent'])]
class CustomEventAutoconfigureTagNamedArgs {

    public function myOtherCustomEvent(): void {}

    public function unusedMethod(): void {} // error: Unused Doctrine\CustomEventAutoconfigureTagNamedArgs::unusedMethod

}

// No method named after the event: Symfony falls back to __invoke
#[\Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag('doctrine.event_listener', ['event' => 'myInvokedCustomEvent'])]
class CustomEventAutoconfigureTagInvoke {

    public function __invoke(): void {}

    public function unusedMethod(): void {} // error: Unused Doctrine\CustomEventAutoconfigureTagInvoke::unusedMethod

}
