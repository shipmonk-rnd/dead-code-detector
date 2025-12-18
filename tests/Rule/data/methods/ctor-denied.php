<?php declare(strict_types = 1);

namespace CtorDenied;

final class Utility
{
    private function __construct()
    {
    }
}

class NotUtility
{
    private function __construct(int $foo) // error: Unused CtorDenied\NotUtility::__construct
    {

    }

}

