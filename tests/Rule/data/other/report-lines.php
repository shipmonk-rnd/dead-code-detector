<?php declare(strict_types = 1);

namespace ReportLines;

class Foo
{
    public
    const
    BAR // error: Unused ReportLines\Foo::BAR
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

}
