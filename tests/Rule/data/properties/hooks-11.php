<?php declare(strict_types=1);

namespace PropertyHooks11;

abstract class AbstractGetOnly
{
    public abstract string $virtualProp { get; }
}

class VirtualGetOnly
{
    public string $virtualProp {
        get => 'computed';
    }
}

class BackedGetOnly
{
    public string $backedProp { // error: Property PropertyHooks11\BackedGetOnly::$backedProp is never written
        get => strtoupper($this->backedProp);
    }
}

class NullsafeBackedGetOnly
{
    public string $backedProp { // error: Property PropertyHooks11\NullsafeBackedGetOnly::$backedProp is never written
        get => $this?->backedProp ?? 'default';
    }
}

function test(AbstractGetOnly $abstract): void {
    echo $abstract->virtualProp;
    echo new VirtualGetOnly()->virtualProp;
    echo new BackedGetOnly()->backedProp;
    echo new NullsafeBackedGetOnly()->backedProp;
}
