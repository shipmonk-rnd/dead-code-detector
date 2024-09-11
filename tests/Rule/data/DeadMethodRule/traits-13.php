<?php declare(strict_types = 1);

namespace DeadTrait13;

trait SomeTrait {
    public function method(): void {} // error: Unused DeadTrait13\SomeTrait::method
}
