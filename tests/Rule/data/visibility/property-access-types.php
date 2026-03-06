<?php declare(strict_types = 1);

namespace VisibilityPropertyAccessTypes;

// Read vs write access with different origins

class ReadWriteSeparate {
    public string $readExternalWriteSelf = ''; // no error - read externally
    public string $readSelfWriteExternal = ''; // no error - written externally
    public string $bothSelf = ''; // error: Property VisibilityPropertyAccessTypes\ReadWriteSeparate::$bothSelf has useless public visibility (can be private)

    public function entry(): void {
        echo $this->bothSelf;
        $this->bothSelf = 'x';
        $this->readExternalWriteSelf = 'y';
        echo $this->readSelfWriteExternal;
    }
}

// Property accessed via hierarchy

class PropParent {
    public string $onlyChildAccess = ''; // error: Property VisibilityPropertyAccessTypes\PropParent::$onlyChildAccess has useless public visibility (can be protected)
    public string $selfAndChild = ''; // error: Property VisibilityPropertyAccessTypes\PropParent::$selfAndChild has useless public visibility (can be protected)

    public function parentEntry(): void {
        echo $this->selfAndChild;
    }
}

class PropChild extends PropParent {
    public function childEntry(): void {
        echo $this->onlyChildAccess;
        $this->selfAndChild = 'from child';
    }
}

// Static property accessed in hierarchy

class StaticPropParent {
    protected static string $data = ''; // error: Property VisibilityPropertyAccessTypes\StaticPropParent::$data has useless protected visibility (can be private)

    public function parentEntry(): void {
        echo self::$data;
    }
}

function test(): void {
    $rw = new ReadWriteSeparate();
    echo $rw->readExternalWriteSelf;
    $rw->readSelfWriteExternal = 'z';
    $rw->entry();

    $p = new PropParent();
    $p->parentEntry();

    $c = new PropChild();
    $c->childEntry();

    $sp = new StaticPropParent();
    $sp->parentEntry();
}
