<?php declare(strict_types = 1);

namespace MixedMemberTrait11;

trait SomeTrait {
    protected function foo(): void {} // error: Unused MixedMemberTrait11\SomeTrait::foo
}


