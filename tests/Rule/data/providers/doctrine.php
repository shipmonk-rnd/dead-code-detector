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
