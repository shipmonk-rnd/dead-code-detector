<?php declare(strict_types = 1);

namespace VendorPhar;

use PharLibrary\PharLibraryInterface;

class MyCallback implements PharLibraryInterface
{

    public function callback(): void
    {
    }

    public function unused(): void // error: Unused VendorPhar\MyCallback::unused
    {
    }

}
