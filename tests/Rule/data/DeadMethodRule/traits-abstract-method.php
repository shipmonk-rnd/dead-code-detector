<?php declare(strict_types = 1);

namespace TraitAbstractMethod;

trait MyTrait {

    public function test()
    {
        $this->abstractInTrait();
    }

    protected abstract function abstractInTrait(): void;
}

class User {
    use MyTrait;

    protected function abstractInTrait(): void
    {
    }
}


(new User())->test();
