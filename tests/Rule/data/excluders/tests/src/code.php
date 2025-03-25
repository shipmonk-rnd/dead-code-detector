<?php

class DeclaredInSrcUsedInTests {
    const CONST = 1; // error: Unused DeclaredInSrcUsedInTests::CONST (all usages excluded by tests excluder)
}

class DeclaredInSrcUsedInBoth {
    const CONST = 1;
}

class DeclaredInSrcUsedInSrc {
    const CONST = 1;
}

function test2() {
    echo DeclaredInSrcUsedInSrc::CONST;
    echo DeclaredInSrcUsedInBoth::CONST;
    echo DeclaredInTestsUsedInBoth::CONST;
    echo DeclaredInTestsUsedInSrc::CONST;
}
