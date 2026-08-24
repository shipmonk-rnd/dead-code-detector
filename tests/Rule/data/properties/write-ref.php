<?php declare(strict_types = 1);

namespace PropertyWriteRef;

class Holder
{

    private int $instanceValue;

    private static int $staticValue;

    public function updateInstance(): void
    {
        $ref = &$this->instanceValue;
        $ref = 5;
    }

    public static function updateStatic(): void
    {
        $ref = &self::$staticValue;
        $ref = 6;
    }

    /**
     * @return array{int, int}
     */
    public function read(): array
    {
        return [$this->instanceValue, self::$staticValue];
    }

}

function test(Holder $holder): void
{
    $holder->updateInstance();
    Holder::updateStatic();
    print_r($holder->read());
}
