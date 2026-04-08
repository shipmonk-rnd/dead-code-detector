<?php declare(strict_types = 1);

namespace SymfonyUx;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\Attribute\PreDehydrate;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Symfony\UX\TwigComponent\Attribute\PreMount;

#[AsTwigComponent]
class AlertComponent
{
    public function __construct() {}

    public function mount(): void {}

    #[PreMount]
    public function preMount(): array { return []; }

    #[PostMount]
    public function postMount(): void {}

    #[ExposeInTemplate]
    public string $message = '';

    #[ExposeInTemplate(getter: 'getFormattedMessage')]
    public string $formatted = '';

    public function getFormattedMessage(): string { return $this->message; }

    #[ExposeInTemplate]
    public function computedValue(): string { return ''; }

    public string $notExposed = ''; // error: Property SymfonyUx\AlertComponent::$notExposed is never read

    public function deadMethod(): void {} // error: Unused SymfonyUx\AlertComponent::deadMethod
}

#[AsLiveComponent]
class CounterComponent
{
    public function __construct() {}

    public function mount(): void {}

    #[LiveProp]
    public int $count = 0;

    #[LiveProp]
    public ?string $label = null;

    #[LiveAction]
    public function increment(): void {}

    #[LiveListener('itemUpdated')]
    public function onItemUpdated(): void {}

    #[PostHydrate]
    public function afterHydrate(): void {}

    #[PreDehydrate]
    public function beforeDehydrate(): void {}

    #[PreReRender]
    public function beforeReRender(): void {}

    public int $notLiveProp = 0; // error: Property SymfonyUx\CounterComponent::$notLiveProp is never read

    public function deadMethod(): void {} // error: Unused SymfonyUx\CounterComponent::deadMethod
}

#[AsLiveComponent(defaultAction: 'render')]
class CustomActionComponent
{
    public function __construct() {}

    public function render(): void {}

    #[LiveProp(hydrateWith: 'hydrateDate', dehydrateWith: 'dehydrateDate')]
    public string $date = '';

    public function hydrateDate(string $data): string { return $data; }

    public function dehydrateDate(string $date): string { return $date; }

    #[LiveProp(onUpdated: 'onNameUpdated')]
    public string $name = '';

    public function onNameUpdated(string $oldValue): void {}

    #[LiveProp(modifier: 'modifyStatus')]
    public string $status = '';

    public function modifyStatus(LiveProp $prop, string $propName): LiveProp { return $prop; }

    #[LiveProp(fieldName: 'getFieldName()')]
    public string $dynamicField = '';

    public function getFieldName(): string { return 'field'; }

    public function deadMethod(): void {} // error: Unused SymfonyUx\CustomActionComponent::deadMethod
}
