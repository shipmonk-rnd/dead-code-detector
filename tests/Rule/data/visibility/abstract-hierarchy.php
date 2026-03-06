<?php declare(strict_types = 1);

namespace VisibilityAbstractHierarchy;

// Abstract method implemented by children: visibility check on concrete implementations

abstract class AbstractBase {
    abstract public function abstractPublic(): void; // no error - abstract in non-trait, interface-like

    public function concreteOnlySelf(): void {} // error: Method VisibilityAbstractHierarchy\AbstractBase::concreteOnlySelf has useless public visibility (can be private)

    public function entry(): void {
        $this->concreteOnlySelf();
    }
}

class ConcreteA extends AbstractBase {
    public function abstractPublic(): void {} // no error - zero usages, skipped
}

class ConcreteB extends AbstractBase {
    public function abstractPublic(): void {} // no error - zero usages, skipped
}

// Abstract class extending another abstract class

abstract class TopAbstract {
    abstract public function chain(): void;
}

abstract class MiddleAbstract extends TopAbstract {
    // does not implement chain()
    public function middleMethod(): void {} // error: Method VisibilityAbstractHierarchy\MiddleAbstract::middleMethod has useless public visibility (can be protected)
}

class Bottom extends MiddleAbstract {
    public function chain(): void {} // no error - zero usages, skipped

    public function bottomEntry(): void {
        $this->middleMethod();
    }
}

// Abstract class with both abstract and concrete used together

abstract class Template {
    public function templateMethod(): void {
        $this->step();
    }

    abstract protected function step(): void; // no error - abstract in non-trait

    public function helperOnlySelf(): void {} // error: Method VisibilityAbstractHierarchy\Template::helperOnlySelf has useless public visibility (can be protected)
}

class ConcreteTemplate extends Template {
    protected function step(): void {
        $this->helperOnlySelf(); // calls parent's helperOnlySelf from hierarchy context
    }
}

function test(): void {
    $a = new ConcreteA();
    $a->entry();

    $b = new Bottom();
    $b->bottomEntry();

    /** @var TopAbstract $ta */
    $ta = $b;
    $ta->chain();

    $ct = new ConcreteTemplate();
    $ct->templateMethod();
}
