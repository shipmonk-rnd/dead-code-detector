<?php declare(strict_types = 1);

namespace Patchlevel\EventSourcing;

use Patchlevel\EventSourcing\Attribute\Aggregate;
use Patchlevel\EventSourcing\Attribute\Answer;
use Patchlevel\EventSourcing\Attribute\Apply;
use Patchlevel\EventSourcing\Attribute\Cleanup;
use Patchlevel\EventSourcing\Attribute\Handle;
use Patchlevel\EventSourcing\Attribute\OnFailed;
use Patchlevel\EventSourcing\Attribute\Setup;
use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\EventSourcing\Attribute\Subscriber;
use Patchlevel\EventSourcing\Attribute\Teardown;
use Patchlevel\EventSourcing\Message\Message;
use Patchlevel\EventSourcing\Metadata\Subscriber\ArgumentMetadata;
use Patchlevel\EventSourcing\Subscription\Cleanup\CleanupTaskHandler;
use Patchlevel\EventSourcing\Subscription\RunMode;
use Patchlevel\EventSourcing\Subscription\Subscriber\ArgumentResolver\ArgumentResolver;
use Patchlevel\EventSourcing\Subscription\Subscriber\BatchableSubscriber;
use Patchlevel\Hydrator\Normalizer\Normalizer;

#[Aggregate('some-aggregate')]
class SomeAggregate {

    #[Apply]
    public function apply(): void {
    }

    #[Handle]
    public static function create(): void {
    }
}

class SomeService {

    #[Handle]
    public function create(): void {
    }

    #[Answer]
    public function query(): void {
    }
}

class SomeSubscriber {

    #[Subscribe]
    public function onCreated(): void {
    }
}

#[Subscriber(id: 'teardown-subscriber', runMode: RunMode::FromBeginning)]
class SomeTeardownSubscriber {

    #[Subscribe]
    public function onCreated(): void {
    }

    #[Setup]
    public function create(): void {
    }

    #[Teardown]
    public function drop(): void {
    }

    #[OnFailed]
    public function onFailed(): void {
    }
}

#[Subscriber(id: 'cleanup-subscriber', runMode: RunMode::FromBeginning)]
class SomeCleanupSubscriber {

    #[Subscribe]
    public function onCreated(): void {
    }

    #[Cleanup]
    public function drop(): void {
    }
}
