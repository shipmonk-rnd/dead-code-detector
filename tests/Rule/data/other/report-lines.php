<?php declare(strict_types = 1);

namespace ReportLines;

use ShipMonk\InputMapper\Compiler\Validator\Int\AssertPositiveInt;

class Foo
{
    public
    const
    BAR // error: Unused ReportLines\Foo::BAR
    =
    1
    ;

    #[
        Prop
    ]
    public
    int
        $foo // error: Unused ReportLines\Foo::foo
        =
        1
    ;

    #[
        Bar
    ]
    public
    function
    endpoint // error: Unused ReportLines\Foo::endpoint
    ()
    :
    void
    {
    }

    public
    function
    __construct // error: Unused ReportLines\Foo::__construct
    (
        #[
            Valid
        ]
        public
        int
        $promoted // error: Unused ReportLines\Foo::promoted
        ,
    )
    {

    }

}

enum MyEnum: string
{
    case
    A // error: Unused ReportLines\MyEnum::A
    =
    'A'
    ;
}
