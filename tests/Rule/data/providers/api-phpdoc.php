<?php

namespace ApiPhpdoc1;


/** @api */
interface PublicApiInterface {

    const CONST1 = 1;
    const CONST2 = 2;

    public function method1() {}
    public function method2() {}

}

/** @api */
class PublicApi {

    const CONST1 = 1;
    const CONST2 = 2;

    public function method1() {}
    public function method2() {}

}

class PartialPublicApi {

    /** @api */
    const CONST1 = 1;
    const CONST2 = 2; // error: Unused ApiPhpdoc1\PartialPublicApi::CONST2

    /** @api */
    public function method1() {}
    public function method2() {} // error: Unused ApiPhpdoc1\PartialPublicApi::method2

}


interface PartialPublicApiInterface {

    /** @api */
    const CONST1 = 1;
    const CONST2 = 2; // error: Unused ApiPhpdoc1\PartialPublicApiInterface::CONST2

    /** @api */
    public function method1() {}
    public function method2() {} // error: Unused ApiPhpdoc1\PartialPublicApiInterface::method2

}

class InheritedPublicApi1 extends PublicApi {

    const CONST1 = 1;
    const CONST2 = 2;
    const CONST3 = 3; // error: Unused ApiPhpdoc1\InheritedPublicApi1::CONST3

    public function method1() {}
    public function method2() {}
    public function method3() {} // error: Unused ApiPhpdoc1\InheritedPublicApi1::method3

}

class InheritedPublicApi2 implements PublicApiInterface {

    const CONST1 = 1;
    const CONST2 = 2;
    const CONST3 = 3; // error: Unused ApiPhpdoc1\InheritedPublicApi2::CONST3

    public function method1() {}
    public function method2() {}
    public function method3() {} // error: Unused ApiPhpdoc1\InheritedPublicApi2::method3

}

interface InheritedPublicApi3 extends PublicApiInterface {

    const CONST1 = 1;
    const CONST2 = 2;
    const CONST3 = 3; // error: Unused ApiPhpdoc1\InheritedPublicApi3::CONST3

    public function method1() {}
    public function method2() {}
    public function method3() {} // error: Unused ApiPhpdoc1\InheritedPublicApi3::method3
}

class InheritedPublicApi4 implements PartialPublicApiInterface {

    const CONST1 = 1;
    const CONST2 = 2; // error: Unused ApiPhpdoc1\InheritedPublicApi4::CONST2
    const CONST3 = 3; // error: Unused ApiPhpdoc1\InheritedPublicApi4::CONST3

    public function method1() {}
    public function method2() {} // error: Unused ApiPhpdoc1\InheritedPublicApi4::method2
    public function method3() {} // error: Unused ApiPhpdoc1\InheritedPublicApi4::method3
}
