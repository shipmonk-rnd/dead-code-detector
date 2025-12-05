<?php declare(strict_types = 1);

namespace Removal;

class ClassWithProperties
{
    public string $usedProperty;

    public string $deadProperty {
        get {
            return strtolower($this->deadProperty);
        }
    }

    public int $deadProperty1, $deadProperty2;

    private static string $deadStaticProperty;

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
