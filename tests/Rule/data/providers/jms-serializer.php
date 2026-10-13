<?php declare(strict_types = 1);

namespace JmsSerializer;

use JMS\Serializer\Annotation\Accessor;
use JMS\Serializer\Annotation\AccessType;
use JMS\Serializer\Annotation\Exclude;
use JMS\Serializer\Annotation\ExclusionPolicy;
use JMS\Serializer\Annotation\Expose;
use JMS\Serializer\Annotation\PostDeserialize;
use JMS\Serializer\Annotation\PostSerialize;
use JMS\Serializer\Annotation\PreSerialize;
use JMS\Serializer\Annotation\ReadOnlyProperty;
use JMS\Serializer\Annotation\VirtualProperty;
use JMS\Serializer\EventDispatcher\Events;
use JMS\Serializer\EventDispatcher\EventSubscriberInterface;
use JMS\Serializer\EventDispatcher\ObjectEvent;
use JMS\Serializer\EventDispatcher\PreDeserializeEvent;
use JMS\Serializer\EventDispatcher\PreSerializeEvent;
use JMS\Serializer\GraphNavigatorInterface;
use JMS\Serializer\Handler\SubscribingHandlerInterface;

class Money
{
}

class ExplicitMethodSubscriber implements EventSubscriberInterface
{

    public static function getSubscribedEvents(): array
    {
        return [
            [
                'event' => 'serializer.post_serialize',
                'method' => 'onPostSerialize',
                'class' => Money::class,
                'format' => 'json',
            ],
            ['event' => Events::PRE_SERIALIZE, 'method' => 'onPreSerialize'],
        ];
    }

    public function onPostSerialize(ObjectEvent $event): void {}
    public function onPreSerialize(PreSerializeEvent $event): void {}
    public function onSerializerPostSerialize(ObjectEvent $event): void {} // error: Unused JmsSerializer\ExplicitMethodSubscriber::onSerializerPostSerialize
    public function unused(): void {} // error: Unused JmsSerializer\ExplicitMethodSubscriber::unused

}

class DefaultMethodSubscriber implements EventSubscriberInterface
{

    public static function getSubscribedEvents(): array
    {
        return [
            ['event' => 'serializer.pre_deserialize'],
            ['event' => Events::POST_DESERIALIZE, 'method' => null],
        ];
    }

    public function onSerializerPreDeserialize(PreDeserializeEvent $event): void {}
    public function onSerializerPostDeserialize(ObjectEvent $event): void {}
    public function onSerializerPreSerialize(PreSerializeEvent $event): void {} // error: Unused JmsSerializer\DefaultMethodSubscriber::onSerializerPreSerialize

}

class LoopSubscriber implements EventSubscriberInterface
{

    public static function getSubscribedEvents(): array
    {
        $events = [];

        foreach (['json', 'xml'] as $format) {
            $events[] = ['event' => Events::POST_SERIALIZE, 'method' => 'onPostSerialize', 'format' => $format];
            $events[] = ['event' => Events::PRE_SERIALIZE, 'format' => $format];
        }

        return $events;
    }

    public function onPostSerialize(ObjectEvent $event): void {}
    public function onSerializerPreSerialize(PreSerializeEvent $event): void {}
    public function onSerializerPostSerialize(ObjectEvent $event): void {} // error: Unused JmsSerializer\LoopSubscriber::onSerializerPostSerialize

}

abstract class ParentSubscriber implements EventSubscriberInterface
{

    public static function getSubscribedEvents(): array
    {
        return [
            ['event' => Events::POST_SERIALIZE, 'method' => 'onPostSerialize'],
        ];
    }

    abstract public function onPostSerialize(ObjectEvent $event): void;

}

class ChildSubscriber extends ParentSubscriber
{

    public function onPostSerialize(ObjectEvent $event): void {}
    public function unused(): void {} // error: Unused JmsSerializer\ChildSubscriber::unused

}

class ConditionalMethodSubscriber implements EventSubscriberInterface
{

    public static function getSubscribedEvents(): array
    {
        $entry = ['event' => Events::PRE_SERIALIZE];

        if (PHP_OS_FAMILY === 'Windows') {
            $entry['method'] = 'onWindows';
        }

        return [$entry];
    }

    public function onWindows(PreSerializeEvent $event): void {}
    public function onSerializerPreSerialize(PreSerializeEvent $event): void {}
    public function onSerializerPostSerialize(ObjectEvent $event): void {} // error: Unused JmsSerializer\ConditionalMethodSubscriber::onSerializerPostSerialize

}

class ClosureInsideSubscriber implements EventSubscriberInterface
{

    public static function getSubscribedEvents(): array
    {
        $unrelated = static function (): array {
            return [
                ['event' => Events::POST_SERIALIZE, 'method' => 'notRegistered'],
            ];
        };

        return [
            ['event' => Events::PRE_SERIALIZE, 'method' => 'onPreSerialize'],
        ];
    }

    public function onPreSerialize(PreSerializeEvent $event): void {}
    public function notRegistered(ObjectEvent $event): void {} // error: Unused JmsSerializer\ClosureInsideSubscriber::notRegistered

}

class NotSubscriber
{

    public static function getSubscribedEvents(): array // error: Unused JmsSerializer\NotSubscriber::getSubscribedEvents
    {
        return [
            ['event' => Events::POST_SERIALIZE, 'method' => 'onPostSerialize'],
        ];
    }

    public function onPostSerialize(ObjectEvent $event): void {} // error: Unused JmsSerializer\NotSubscriber::onPostSerialize

}

class ExplicitMethodHandler implements SubscribingHandlerInterface
{

    public static function getSubscribingMethods(): array
    {
        return [
            [
                'direction' => GraphNavigatorInterface::DIRECTION_SERIALIZATION,
                'format' => 'json',
                'type' => Money::class,
                'method' => 'serializeMoney',
            ],
            [
                'format' => 'xml',
                'type' => Money::class,
                'method' => 'convertMoney',
            ],
        ];
    }

    public function serializeMoney(): void {}
    public function convertMoney(): void {}
    public function serializeMoneyToJson(): void {} // error: Unused JmsSerializer\ExplicitMethodHandler::serializeMoneyToJson
    public function deserializeMoneyFromJson(): void {} // error: Unused JmsSerializer\ExplicitMethodHandler::deserializeMoneyFromJson

}

class DefaultMethodHandler implements SubscribingHandlerInterface
{

    public static function getSubscribingMethods(): array
    {
        return [
            [
                'direction' => GraphNavigatorInterface::DIRECTION_DESERIALIZATION,
                'format' => 'json',
                'type' => Money::class,
            ],
            [
                'direction' => GraphNavigatorInterface::DIRECTION_SERIALIZATION,
                'format' => 'yaml',
                'type' => 'Money',
                'method' => null,
            ],
            [
                'format' => 'xml',
                'type' => Money::class,
            ],
        ];
    }

    public function deserializeMoneyFromJson(): void {}
    public function serializeMoneyToJson(): void {} // error: Unused JmsSerializer\DefaultMethodHandler::serializeMoneyToJson
    public function serializeMoneyToYaml(): void {}
    public function deserializeMoneyFromYaml(): void {} // error: Unused JmsSerializer\DefaultMethodHandler::deserializeMoneyFromYaml
    public function serializeMoneyToXml(): void {}
    public function deserializeMoneyFromXml(): void {}

}

class LoopHandler implements SubscribingHandlerInterface
{

    public static function getSubscribingMethods(): array
    {
        $methods = [];

        foreach (['json', 'xml'] as $format) {
            $methods[] = [
                'type' => Money::class,
                'direction' => GraphNavigatorInterface::DIRECTION_SERIALIZATION,
                'format' => $format,
            ];
            $methods[] = [
                'type' => Money::class,
                'direction' => GraphNavigatorInterface::DIRECTION_DESERIALIZATION,
                'format' => $format,
                'method' => 'deserializeMoney',
            ];
        }

        return $methods;
    }

    public function serializeMoneyToJson(): void {}
    public function serializeMoneyToXml(): void {}
    public function deserializeMoney(): void {}
    public function deserializeMoneyFromJson(): void {} // error: Unused JmsSerializer\LoopHandler::deserializeMoneyFromJson
    public function serializeMoneyToYaml(): void {} // error: Unused JmsSerializer\LoopHandler::serializeMoneyToYaml

}

class NotHandler
{

    public static function getSubscribingMethods(): array // error: Unused JmsSerializer\NotHandler::getSubscribingMethods
    {
        return [
            ['format' => 'json', 'type' => Money::class],
        ];
    }

    public function serializeMoneyToJson(): void {} // error: Unused JmsSerializer\NotHandler::serializeMoneyToJson
    public function deserializeMoneyFromJson(): void {} // error: Unused JmsSerializer\NotHandler::deserializeMoneyFromJson

}

class Callbacks
{

    #[PreSerialize]
    private function beforeSerialization(): void {}

    #[PostSerialize]
    protected function afterSerialization(): void {}

    #[PostDeserialize]
    public function afterDeserialization(): void {}

    private function unused(): void {} // error: Unused JmsSerializer\Callbacks::unused

}

class ChildCallbacks extends Callbacks
{

    protected function afterSerialization(): void {}

    public function unusedInChild(): void {} // error: Unused JmsSerializer\ChildCallbacks::unusedInChild

}

class VirtualProperties
{

    #[VirtualProperty]
    public function getTotal(): int
    {
        return 0;
    }

    #[VirtualProperty(name: 'label')]
    #[Exclude(if: 'context.getDirection() === 2')]
    public function getConditionalLabel(): string
    {
        return '';
    }

    #[VirtualProperty]
    #[Exclude]
    public function getExcluded(): int // error: Unused JmsSerializer\VirtualProperties::getExcluded
    {
        return 0;
    }

    public function getNotVirtual(): int // error: Unused JmsSerializer\VirtualProperties::getNotVirtual
    {
        return 0;
    }

}

#[ExclusionPolicy(ExclusionPolicy::ALL)]
class VirtualPropertiesOfExcludeAllPolicy
{

    #[VirtualProperty]
    #[Exclude]
    public function getTotal(): int
    {
        return 0;
    }

}

#[Exclude]
class VirtualPropertiesOfExcludedClass
{

    #[VirtualProperty]
    public function getTotal(): int // error: Unused JmsSerializer\VirtualPropertiesOfExcludedClass::getTotal
    {
        return 0;
    }

    #[PostDeserialize]
    public function afterDeserialization(): void {}

}

trait CallbacksTrait
{

    #[PostDeserialize]
    private function initialize(): void {}

    #[VirtualProperty]
    public function getAge(): int
    {
        return 0;
    }

    public function unusedInTrait(): void {} // error: Unused JmsSerializer\CallbacksTrait::unusedInTrait

}

class CallbacksFromTrait
{

    use CallbacksTrait;

}

class Accessors
{

    #[Accessor(getter: 'getName', setter: 'setName')]
    public ?string $name = null;

    #[Accessor(['getter' => 'getLegacy'])]
    public ?string $legacy = null;

    #[Accessor(getter: 'getFixed', setter: 'setFixed')]
    #[ReadOnlyProperty]
    public ?string $fixed = null;

    #[Accessor(getter: 'getExcluded', setter: 'setExcluded')]
    #[Exclude]
    public ?string $excluded = null; // error: Property JmsSerializer\Accessors::$excluded is never read

    #[Accessor(getter: 'getConditional')]
    #[Exclude(if: 'context.getDirection() === 2')]
    public ?string $conditional = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getLegacy(): ?string
    {
        return $this->legacy;
    }

    public function setLegacy(?string $legacy): void // error: Unused JmsSerializer\Accessors::setLegacy
    {
        $this->legacy = $legacy;
    }

    public function getFixed(): ?string
    {
        return $this->fixed;
    }

    public function setFixed(?string $fixed): void // error: Unused JmsSerializer\Accessors::setFixed
    {
        $this->fixed = $fixed;
    }

    public function getExcluded(): ?string // error: Unused JmsSerializer\Accessors::getExcluded
    {
        return $this->excluded;
    }

    public function setExcluded(?string $excluded): void // error: Unused JmsSerializer\Accessors::setExcluded
    {
        $this->excluded = $excluded;
    }

    public function getConditional(): ?string
    {
        return $this->conditional;
    }

}

#[ExclusionPolicy('all')]
class AccessorsOfExcludeAllPolicy
{

    #[Expose]
    #[Accessor(getter: 'getExposed')]
    public ?string $exposed = null;

    #[Accessor(getter: 'getHidden')]
    public ?string $hidden = null; // error: Property JmsSerializer\AccessorsOfExcludeAllPolicy::$hidden is never read

    public function getExposed(): ?string
    {
        return $this->exposed;
    }

    public function getHidden(): ?string // error: Unused JmsSerializer\AccessorsOfExcludeAllPolicy::getHidden
    {
        return $this->hidden;
    }

}

class PublicMethodAccessParent
{

    public function getId(): int
    {
        return 0;
    }

    public function setId(int $id): void {} // error: Unused JmsSerializer\PublicMethodAccessParent::setId

}

#[AccessType(type: 'public_method')]
class PublicMethodAccess extends PublicMethodAccessParent
{

    #[ReadOnlyProperty]
    public int $id = 0; // error: Property JmsSerializer\PublicMethodAccess::$id is never read

    public ?string $name = null;

    #[ReadOnlyProperty]
    public bool $active = false;

    #[ReadOnlyProperty]
    public bool $children = false;

    #[AccessType(['type' => 'property'])]
    public ?string $direct = null; // error: Property JmsSerializer\PublicMethodAccess::$direct is never read

    #[Accessor(getter: 'loadCustom')]
    #[ReadOnlyProperty]
    public ?string $custom = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function isName(): bool // error: Unused JmsSerializer\PublicMethodAccess::isName
    {
        return $this->name !== null;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void // error: Unused JmsSerializer\PublicMethodAccess::setActive
    {
        $this->active = $active;
    }

    public function hasChildren(): bool
    {
        return $this->children;
    }

    public function getDirect(): ?string // error: Unused JmsSerializer\PublicMethodAccess::getDirect
    {
        return $this->direct;
    }

    public function loadCustom(): ?string
    {
        return $this->custom;
    }

    public function getCustom(): ?string // error: Unused JmsSerializer\PublicMethodAccess::getCustom
    {
        return $this->custom;
    }

}

class PublicMethodAccessOfProperty
{

    #[AccessType(['value' => 'public_method'])]
    public ?string $name = null;

    public ?string $direct = null; // error: Property JmsSerializer\PublicMethodAccessOfProperty::$direct is never read

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getDirect(): ?string // error: Unused JmsSerializer\PublicMethodAccessOfProperty::getDirect
    {
        return $this->direct;
    }

}

#[ReadOnlyProperty]
class ReadOnlyClass
{

    #[Accessor(getter: 'getName', setter: 'setName')]
    public ?string $name = null;

    #[Accessor(setter: 'setWritable')]
    #[ReadOnlyProperty(readOnly: false)]
    public ?string $writable = null; // error: Property JmsSerializer\ReadOnlyClass::$writable is never read

    #[Accessor(setter: 'setLegacy')]
    #[\JMS\Serializer\Annotation\ReadOnly]
    public ?string $legacy = null; // error: Property JmsSerializer\ReadOnlyClass::$legacy is never read

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void // error: Unused JmsSerializer\ReadOnlyClass::setName
    {
        $this->name = $name;
    }

    public function setWritable(?string $writable): void
    {
        $this->writable = $writable;
    }

    public function setLegacy(?string $legacy): void // error: Unused JmsSerializer\ReadOnlyClass::setLegacy
    {
        $this->legacy = $legacy;
    }

}
