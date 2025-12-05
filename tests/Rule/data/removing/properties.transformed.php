<?php declare(strict_types = 1);

namespace Removal;

class ClassWithProperties
{
    public string $usedProperty;

    public function __construct()
    {
        $this->usedProperty = 'used';
    }

    public function getUsed(): string
    {
        return $this->usedProperty;
    }
}

function useClass(): void
{
    $obj = new ClassWithProperties();
    echo $obj->getUsed();
}
