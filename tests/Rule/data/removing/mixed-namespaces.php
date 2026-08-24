<?php declare(strict_types = 1);

namespace Removal {

    class MixedNamespaceDup
    {

        public function foo(): void
        {
        }

    }

    function useNamespacedDup(): void
    {
        new MixedNamespaceDup();
    }

}

namespace {

    class MixedNamespaceDup
    {

        public function foo(): void
        {
        }

        public function bar(): void
        {
        }

    }

    function useGlobalDup(): void
    {
        (new MixedNamespaceDup())->foo();
    }

}
