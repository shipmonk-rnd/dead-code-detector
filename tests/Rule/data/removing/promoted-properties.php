<?php declare(strict_types = 1);

namespace Removal;

class ClassWithPromotedProperties
{
    public function __construct(
        public string $usedPromoted,
        public string $deadPromoted,
        private int $deadPrivatePromoted,
    ) {
    }

    public function getUsed(): string
    {
        return $this->usedPromoted;
    }
}

function useClassWithPromotedProps(): void
{
    $obj = new ClassWithPromotedProperties('used', 'dead', 42);
    echo $obj->getUsed();
}
