<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use JsonException;
use LogicException;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

/**
 * @immutable
 */
abstract class ClassMemberUsage
{

    /**
     * Origin method of the usage, "where it was called from"
     * This is required for proper transitive dead code detection.
     *
     * @see UsageOriginDetector for typical usage
     */
    private ?ClassMethodRef $origin;

    public function __construct(?ClassMethodRef $origin)
    {
        if ($origin !== null && $origin->isPossibleDescendant()) {
            throw new LogicException('Origin should always be exact place in codebase.');
        }

        if ($origin !== null && $origin->getClassName() === null) {
            throw new LogicException('Origin should always be exact place in codebase, thus className should be known.');
        }

        $this->origin = $origin;
    }

    public function getOrigin(): ?ClassMethodRef
    {
        return $this->origin;
    }

    /**
     * @return MemberType::*
     */
    abstract public function getMemberType(): int;

    abstract public function getMemberRef(): ClassMemberRef;

    public function toHumanString(): string
    {
        $origin = $this->origin !== null ? $this->origin->toHumanString() : 'unknown';
        $callee = $this->getMemberRef()->toHumanString();

        return "$origin -> $callee";
    }

    public function serialize(): string
    {
        $origin = $this->getOrigin();
        $memberRef = $this->getMemberRef();

        $data = [
            't' => $this->getMemberType(),
            'o' => $origin === null
                ? null
                : [
                    'c' => $origin->getClassName(),
                    'm' => $origin->getMemberName(),
                    'd' => $origin->isPossibleDescendant(),
                ],
            'm' => [
                'c' => $memberRef->getClassName(),
                'm' => $memberRef->getMemberName(),
                'd' => $memberRef->isPossibleDescendant(),
            ],
        ];

        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('Serialization failure: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function deserialize(string $data): self
    {
        try {
            /** @var array{t: MemberType::*, o: array{c: string|null, m: string, d: bool}|null, m: array{c: string|null, m: string, d: bool}} $result */
            $result = json_decode($data, true, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('Deserialization failure: ' . $e->getMessage(), 0, $e);
        }

        $memberType = $result['t'];
        $origin = $result['o'] === null ? null : new ClassMethodRef($result['o']['c'], $result['o']['m'], $result['o']['d']);

        return $memberType === MemberType::CONSTANT
            ? new ClassConstantUsage(
                $origin,
                new ClassConstantRef($result['m']['c'], $result['m']['m'], $result['m']['d']),
            )
            : new ClassMethodUsage(
                $origin,
                new ClassMethodRef($result['m']['c'], $result['m']['m'], $result['m']['d']),
            );
    }

}
