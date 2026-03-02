<?php declare(strict_types=1);

namespace PropertyHooks12;

class VirtualDespiteClosure
{
    public string $virtualProp {
        get {
            $fn = function () {
                return $this->virtualProp; // closure reference does not count as backing value reference
            };
            return $fn();
        }
    }
}

class VirtualDespiteArrowFunction
{
    public string $virtualProp {
        get {
            $fn = fn () => $this->virtualProp; // arrow function reference does not count as backing value reference
            return $fn();
        }
    }
}

class VirtualDespiteAnonymousClass
{
    public string $virtualProp {
        get {
            $obj = new class {
                public function get(): string {
                    return $this->virtualProp; // anonymous class reference does not count as backing value reference
                }
            };
            return $obj->get();
        }
    }
}

function test(): void {
    echo new VirtualDespiteClosure()->virtualProp;
    echo new VirtualDespiteArrowFunction()->virtualProp;
    echo new VirtualDespiteAnonymousClass()->virtualProp;
}
