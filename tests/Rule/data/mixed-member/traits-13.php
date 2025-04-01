<?php declare(strict_types = 1);

namespace MixedMemberTrait13;

trait SomeTrait {
    public function method(): void {} // error: Unused MixedMemberTrait13\SomeTrait::method
}
