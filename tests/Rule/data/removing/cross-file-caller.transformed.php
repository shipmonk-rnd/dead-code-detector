<?php declare(strict_types = 1);

namespace Removal;

class CrossFileCaller
{

    public function used(): void
    {
        (new CrossFileCallee())->used();
    }

}

function useCrossFileCaller(): void
{
    (new CrossFileCaller())->used();
}
