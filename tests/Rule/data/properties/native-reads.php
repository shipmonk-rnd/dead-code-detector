<?php declare(strict_types = 1);

namespace NativePropertyReads;

use JsonSerializable;
use Serializable;

// --- (array) cast ---

class ArrayCastClass
{
    public string $pub;
    protected string $prot;
    private string $priv;

    public function __construct()
    {
        $this->pub = 'a';
        $this->prot = 'b';
        $this->priv = 'c';
    }
}

class ArrayCastParent
{
    public string $parentProp;

    public function __construct()
    {
        $this->parentProp = 'a';
    }
}

class ArrayCastChild extends ArrayCastParent
{
    public string $childProp;

    public function __construct()
    {
        parent::__construct();
        $this->childProp = 'b';
    }
}

// --- get_object_vars ---

class GetObjectVarsClass
{
    public string $pub;
    protected string $prot;
    private string $priv;

    public function __construct()
    {
        $this->pub = 'a';
        $this->prot = 'b';
        $this->priv = 'c';
    }
}

// --- get_mangled_object_vars ---

class GetMangledClass
{
    public string $pub;
    protected string $prot;
    private string $priv;

    public function __construct()
    {
        $this->pub = 'a';
        $this->prot = 'b';
        $this->priv = 'c';
    }
}

// --- json_encode ---

final class JsonEncodeClass
{
    public string $pub;
    private string $priv;

    public function __construct()
    {
        $this->pub = 'a';
        $this->priv = 'b';
    }
}

final class JsonSerializableClass implements JsonSerializable
{
    public string $pub; // error: Property NativePropertyReads\JsonSerializableClass::$pub is never read // error: Property NativePropertyReads\JsonSerializableClass::$pub is never written
    private string $priv; // error: Property NativePropertyReads\JsonSerializableClass::$priv is never read // error: Property NativePropertyReads\JsonSerializableClass::$priv is never written

    public function jsonSerialize(): mixed
    {
        return ['custom' => 'data'];
    }
}

// --- serialize ---

final class SerializeDefault
{
    public string $pub;
    private string $priv;

    public function __construct()
    {
        $this->pub = 'a';
        $this->priv = 'b';
    }
}

final class SerializeWithMagic
{
    public string $pub; // error: Property NativePropertyReads\SerializeWithMagic::$pub is never read // error: Property NativePropertyReads\SerializeWithMagic::$pub is never written
    private string $priv; // error: Property NativePropertyReads\SerializeWithMagic::$priv is never read // error: Property NativePropertyReads\SerializeWithMagic::$priv is never written

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return ['custom' => 'data'];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
    }
}

final class SerializeWithSleep
{
    public string $pub;
    private string $priv;

    public function __construct()
    {
        $this->pub = 'a';
        $this->priv = 'b';
    }

    /** @return list<string> */
    public function __sleep(): array
    {
        return ['pub'];
    }
}

/**
 * @implements Serializable<string>
 */
final class SerializableInterfaceClass implements Serializable
{
    public string $pub; // error: Property NativePropertyReads\SerializableInterfaceClass::$pub is never read // error: Property NativePropertyReads\SerializableInterfaceClass::$pub is never written

    public function serialize(): string
    {
        return '';
    }

    public function unserialize(string $data): void
    {
    }
}

function testArrayCast(): void
{
    $obj = new ArrayCastClass();
    (array) $obj;

    $parent = new ArrayCastParent();
    (array) $parent;

    $child = new ArrayCastChild();
    (array) $child;
}

/** @param mixed $unknown */
function testArrayCastUnknownType($unknown): void
{
    (array) $unknown;
}

function testGetObjectVars(): void
{
    $obj = new GetObjectVarsClass();
    get_object_vars($obj);
}

function testGetMangledObjectVars(): void
{
    $obj = new GetMangledClass();
    get_mangled_object_vars($obj);
}

function testJsonEncode(): void
{
    $obj = new JsonEncodeClass();
    json_encode($obj);

    $serializable = new JsonSerializableClass();
    json_encode($serializable);
}

function testSerialize(): void
{
    $default = new SerializeDefault();
    serialize($default);

    $magic = new SerializeWithMagic();
    serialize($magic);

    $sleep = new SerializeWithSleep();
    serialize($sleep);

    $iface = new SerializableInterfaceClass();
    serialize($iface);
}
