<?php declare(strict_types = 1);

namespace StreamWrapper;

class MyStreamWrapper
{
    public function stream_open($path, $mode, $options, &$opened_path) {}
    public function stream_read($count) {}
    public function unrelated($path, $mode, $options, &$opened_path) {} // error: Unused StreamWrapper\MyStreamWrapper::unrelated
}

function testAll() {
    stream_wrapper_register('myprotocol', MyStreamWrapper::class);
}
