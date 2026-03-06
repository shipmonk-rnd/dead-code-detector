<?php declare(strict_types = 1);

namespace DeadInvoke;

class CalledDirectly {

    public function __invoke(): void
    {
        $this->calledFromInvoke();
    }

    public function calledFromInvoke(): void {}
}

class CalledViaVariable {

    public function __invoke(): void {}
}

class CalledViaCallUserFunc {

    public function __invoke(int $x): void {}
}

class NeverInvoked {

    public function __invoke(): void // error: Unused DeadInvoke\NeverInvoked::__invoke
    {
        $this->calledFromDeadInvoke();
    }

    public function calledFromDeadInvoke(): void // error: Unused DeadInvoke\NeverInvoked::calledFromDeadInvoke
    {
    }
}

function test(): void {
    $direct = new CalledDirectly();
    $direct->__invoke();

    $var = new CalledViaVariable();
    $var();

    $cuf = new CalledViaCallUserFunc();
    call_user_func($cuf, 1);

    new NeverInvoked();
}
