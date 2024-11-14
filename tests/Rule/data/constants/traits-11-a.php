<?php declare(strict_types = 1);

namespace DeadTraitConst11;

trait SomeTrait {
    const FOO = 1; // error: Unused DeadTraitConst11\SomeTrait::FOO
}


