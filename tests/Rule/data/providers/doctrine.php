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

    private string $notMapped; // error: Property Doctrine\MyEntity::notMapped is never read

    #[\Doctrine\ORM\Mapping\PreUpdate]
    public function onUpdate(PreUpdateEventArgs $args): void {}

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

#[\Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag('doctrine.event_listener', event: 'postGenerateSchema')]
class FixDoctrineMigrationTableSchemaWithAutoconfigureTag {

    public function postGenerateSchema(): void {}

    public function unusedMethod(): void {} // error: Unused Doctrine\FixDoctrineMigrationTableSchemaWithAutoconfigureTag::unusedMethod

}

#[\Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag('doctrine.event_listener', event: 'postGenerateSchema', method: 'onPostGenerateSchema')]
class FixDoctrineMigrationTableSchemaWithAutoconfigureTagAndMethod {

    public function onPostGenerateSchema(): void {}

    public function unusedMethod(): void {} // error: Unused Doctrine\FixDoctrineMigrationTableSchemaWithAutoconfigureTagAndMethod::unusedMethod

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
#[\Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag('doctrine.event_listener', event: 'postPersist')]
#[\Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag('doctrine.event_listener', event: 'postUpdate', method: 'afterUpdate')]
class MultipleAutoconfigureTags {

    public function postPersist(): void {}

    public function afterUpdate(): void {}

    public function unusedMethod(): void {} // error: Unused Doctrine\MultipleAutoconfigureTags::unusedMethod

}
