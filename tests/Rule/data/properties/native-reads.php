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

// --- json_encode transitive ---

final class JsonEncodeNested
{
    public string $name;

    public function __construct()
    {
        $this->name = 'nested';
    }
}

final class JsonEncodeOuter
{
    public JsonEncodeNested $nested;
    public \DateTimeInterface $date;

    public function __construct()
    {
        $this->nested = new JsonEncodeNested();
        $this->date = new \DateTimeImmutable();
    }
}

final class JsonEncodeNestedJsonSerializable implements JsonSerializable
{
    public string $ignoredProp; // error: Property NativePropertyReads\JsonEncodeNestedJsonSerializable::$ignoredProp is never read // error: Property NativePropertyReads\JsonEncodeNestedJsonSerializable::$ignoredProp is never written

    public function jsonSerialize(): mixed
    {
        return ['custom' => 'data'];
    }
}

final class JsonEncodeOuterWithJsonSerializableNested
{
    public JsonEncodeNestedJsonSerializable $nested;

    public function __construct()
    {
        $this->nested = new JsonEncodeNestedJsonSerializable();
    }
}

// --- serialize transitive ---

final class SerializeNested
{
    public string $name;
    private int $id;

    public function __construct()
    {
        $this->name = 'nested';
        $this->id = 1;
    }
}

final class SerializeOuter
{
    public SerializeNested $nested;

    public function __construct()
    {
        $this->nested = new SerializeNested();
    }
}

final class SerializeNestedWithMagic
{
    public string $ignoredProp; // error: Property NativePropertyReads\SerializeNestedWithMagic::$ignoredProp is never read // error: Property NativePropertyReads\SerializeNestedWithMagic::$ignoredProp is never written

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return [];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
    }
}

final class SerializeOuterWithMagicNested
{
    public SerializeNestedWithMagic $nested;

    public function __construct()
    {
        $this->nested = new SerializeNestedWithMagic();
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

/** @param object $unknown */
function testGetObjectVarsUnknownType($unknown): void
{
    get_object_vars($unknown);
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

    $outer = new JsonEncodeOuter();
    json_encode($outer);

    $outerWithJsonSerializable = new JsonEncodeOuterWithJsonSerializableNested();
    json_encode($outerWithJsonSerializable);
}

// --- array_column ---

final class ArrayColumnClass
{
    public string $col;
    public string $idx;
    public string $idxNull;
    public string $unused; // error: Property NativePropertyReads\ArrayColumnClass::$unused is never read

    public function __construct()
    {
        $this->col = 'a';
        $this->idx = 'b';
        $this->idxNull = 'c';
        $this->unused = 'd';
    }
}

function testArrayColumn(): void
{
    $list = [new ArrayColumnClass()];
    array_column($list, 'col', 'idx');
    array_column($list, null, 'idxNull');
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

    $outer = new SerializeOuter();
    serialize($outer);

    $outerWithMagic = new SerializeOuterWithMagicNested();
    serialize($outerWithMagic);
}
