<?php declare(strict_types = 1);

namespace VisibilityTraitPropertyParentConflict;

// When a trait property also exists in the parent class of the host,
// the trait property visibility must remain compatible.
// Making it private would cause: "the definition differs and is considered incompatible"

abstract class ParentWithProperty {
    protected ?int $section = null;
    protected ?int $level = null;
}

trait TraitWithSameProperty {
    protected ?int $section = null; // no error — can't be private, parent class of host defines it as protected
    protected ?int $level = null; // no error — same reason

    public function getSection(): int { // error: Method VisibilityTraitPropertyParentConflict\TraitWithSameProperty::getSection has useless public visibility (can be private)
        return $this->section ?? 0;
    }

    public function getLevel(): int { // error: Method VisibilityTraitPropertyParentConflict\TraitWithSameProperty::getLevel has useless public visibility (can be private)
        return $this->level ?? 0;
    }
}

class ChildUsingTrait extends ParentWithProperty {
    use TraitWithSameProperty;

    public function entry(): void { // no error - used externally
        echo $this->getSection();
        echo $this->getLevel();
    }
}

function test(): void {
    $c = new ChildUsingTrait();
    $c->entry();
}
