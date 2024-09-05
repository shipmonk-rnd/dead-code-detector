<?php declare(strict_types = 1);

namespace DeadTrait11;

trait SomeTrait {
    protected function foo(): void {} // error: Unused DeadTrait11\SomeTrait::foo
}


