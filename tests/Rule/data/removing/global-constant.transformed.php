<?php declare(strict_types = 1);

namespace Removal;

class ClassWithGlobalConstSibling
{

    public function getUsedValue(): string
    {
        return 'used';
    }

}

const FOO = 2;

function useGlobalConst(): string
{
    return (new ClassWithGlobalConstSibling())->getUsedValue() . FOO;
}
