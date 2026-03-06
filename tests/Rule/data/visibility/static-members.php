<?php declare(strict_types = 1);

namespace VisibilityStaticMembers;

// Static methods

class WithStatics {
    public static function publicStaticSelf(): void {} // error: Method VisibilityStaticMembers\WithStatics::publicStaticSelf has useless public visibility (can be private)
    public static function publicStaticExternal(): void {} // no error - used externally
    protected static function protectedStaticSelf(): void {} // error: Method VisibilityStaticMembers\WithStatics::protectedStaticSelf has useless protected visibility (can be private)

    public function entry(): void {
        self::publicStaticSelf();
        self::protectedStaticSelf();
    }
}

// Static method in hierarchy

class StaticParent {
    public static function parentStatic(): void {} // error: Method VisibilityStaticMembers\StaticParent::parentStatic has useless public visibility (can be protected)
}

class StaticChild extends StaticParent {
    public function childEntry(): void {
        parent::parentStatic();
    }
}

// Static properties

class StaticProps {
    public static string $publicSelf = ''; // error: Property VisibilityStaticMembers\StaticProps::$publicSelf has useless public visibility (can be private)
    public static string $publicExternal = ''; // no error

    public function entry(): void {
        echo self::$publicSelf;
    }
}

function test(): void {
    $w = new WithStatics();
    $w->entry();
    WithStatics::publicStaticExternal();

    $c = new StaticChild();
    $c->childEntry();

    $sp = new StaticProps();
    $sp->entry();
    echo StaticProps::$publicExternal;
}
