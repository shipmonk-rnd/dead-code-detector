<?php

class DeclaredInTestsUsedInTests {
    const CONST = 1;
}

class DeclaredInTestsUsedInBoth {
    const CONST = 1;
}

class DeclaredInTestsUsedInSrc {
    const CONST = 1;
}

function test1() {
    echo DeclaredInSrcUsedInBoth::CONST;
    echo DeclaredInSrcUsedInTests::CONST;
    echo DeclaredInTestsUsedInTests::CONST;
    echo DeclaredInTestsUsedInBoth::CONST;
}
