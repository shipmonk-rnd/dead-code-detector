<?php declare(strict_types=1);

namespace PropertyHooks13;

class MemoizedProperty
{
    public string $id {
        get => $this->id ??= $this->data['id'];
    }

    /**
     * @param array{
     *     'id': string,
     * } $data
     */
    public function __construct(
        private readonly array $data,
    ) {}
}

function test() {
    $m = new MemoizedProperty(['id' => 'foo']);
    echo $m->id;
}
