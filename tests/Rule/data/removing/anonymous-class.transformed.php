<?php declare(strict_types = 1);

namespace Removal;

class ClassWithAnonymousClass
{

    public function run(): void
    {
        $object = new class {

            public function foo(): int
            {
                return 1;
            }

        };
        echo $object->foo();
    }

}

function useAnonymousClass(): void
{
    (new ClassWithAnonymousClass())->run();
}
