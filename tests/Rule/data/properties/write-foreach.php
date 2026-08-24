<?php declare(strict_types=1);

namespace PropertyWriteForeach;

class Test
{
    private int $asValue;
    private string $asKey;
    private int $asListItem;
    private int $asNestedListItem;
    private static int $asStaticValue; // error: Property PropertyWriteForeach\Test::$asStaticValue is never read
    private int $writeOnly; // error: Property PropertyWriteForeach\Test::$writeOnly is never read

    /**
     * @param list<int> $items
     * @param array<string, mixed> $map
     * @param list<array{int}> $rows
     * @param list<array{array{int}}> $nested
     * @param list<int> $other
     */
    public function fill(array $items, array $map, array $rows, array $nested, array $other): void
    {
        foreach ($items as $this->asValue) {
        }

        foreach ($map as $this->asKey => $ignored) {
        }

        foreach ($rows as [$this->asListItem]) {
        }

        foreach ($nested as [[$this->asNestedListItem]]) {
        }

        foreach ($other as $this->writeOnly) {
        }

        foreach ($items as self::$asStaticValue) {
        }
    }

    public function read(): string
    {
        return $this->asValue . $this->asKey . $this->asListItem . $this->asNestedListItem;
    }
}

function test(Test $test): void
{
    $test->fill([], [], [], [], []);
    echo $test->read();
}
