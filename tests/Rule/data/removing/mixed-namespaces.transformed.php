<?php declare(strict_types = 1);

namespace Removal {

    class MixedNamespaceDup
    {
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

    }

    function useGlobalDup(): void
    {
        (new MixedNamespaceDup())->foo();
    }

}
