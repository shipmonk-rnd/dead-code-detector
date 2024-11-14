<?php declare(strict_types = 1);

namespace DeadTraitConst13;

trait SomeTrait {
    const UNUSED = 1; // error: Unused DeadTraitConst13\SomeTrait::UNUSED
}
