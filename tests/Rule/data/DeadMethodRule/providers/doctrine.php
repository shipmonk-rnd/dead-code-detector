<?php

namespace Doctrine;

class MyEntity
{

    #[\Doctrine\ORM\Mapping\PreUpdate]
    public function onUpdate(PreUpdateEventArgs $args): void {}

}

#[\Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener(event: 'postUpdate', method: 'afterUpdate')]
#[\Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener(event: 'postPersist')]
class MyListener {

    public function afterUpdate(): void {}

    public function postPersist(): void {}

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
        return [];
    }

}
