<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use JsonException;
use LogicException;
use PHPStan\TrinaryLogic;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use TypeError;
use ValueError;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

final class CollectedUsage
{

    public function __construct(
        private readonly ClassMemberUsage $usage,
        private readonly ?string $excludedBy,
    )
    {
    }

    public function getUsage(): ClassMemberUsage
    {
        return $this->usage;
    }

    public function isExcluded(): bool
    {
        return $this->excludedBy !== null;
    }

    public function getExcludedBy(): string
    {
        if ($this->excludedBy === null) {
            throw new LogicException('Usage is not excluded, use isExcluded() before calling this method');
        }

        return $this->excludedBy;
    }

    public function concretizeMixedClassNameUsage(string $className): self
    {
        return new self(
            $this->usage->concretizeMixedClassNameUsage($className),
            $this->excludedBy,
        );
    }

    /**
     * Scope file is passed to optimize transferred data size (and thus result cache size)
     * - PHPStan itself transfers all collector data along with scope file
     * - thus if our data match those already-transferred ones, lets omit those
     *
     * @see https://github.com/phpstan/phpstan-src/blob/2fe4e0f94e75fe8844a21fdb81799f01f0591dfe/src/Analyser/FileAnalyser.php#L198
     */
    public function serialize(string $scopeFile): string
    {
        $origin = $this->usage->getOrigin();
        $memberRef = $this->usage->getMemberRef();

        $data = [
            'e' => $this->excludedBy,
            't' => $this->usage->getMemberType()->value,
            'a' => $this->usage->getAccessType()->value,
            'o' => [
                    'c' => $origin->getClassName(),
                    'm' => $origin->getMemberName(),
                    'a' => $origin->getAccessType()?->value,
                    't' => $origin->getMemberType()?->value,
                    'f' => $origin->getFile() === $scopeFile ? '_' : $origin->getFile(),
                    'l' => $origin->getLine(),
                    'p' => $origin->getProvider(),
                    'n' => $origin->getNote(),
                ],
            'm' => [
                'c' => $memberRef->getClassName(),
                'm' => $memberRef->getMemberName(),
                'd' => $memberRef->isPossibleDescendant(),
                'e' => $memberRef instanceof ClassConstantRef ? $this->serializeTrinary($memberRef->isEnumCase()) : null,
            ],
        ];

        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('Serialization failure: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function deserialize(
        string $data,
        string $scopeFile,
    ): self
    {
        try {
            /** @var array{e: string|null, t: value-of<MemberType>, a: value-of<AccessType>, o: array{c: string|null, m: string|null, a: value-of<AccessType>|null, t: value-of<MemberType>|null, f: string|null, l: int|null, p: string|null, n: string|null}, m: array{c: string|null, m: string, d: bool, e: int}} $result */
            $result = json_decode($data, associative: true, depth: 3, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('Deserialization failure: ' . $e->getMessage(), 0, $e);
        }

        try {
            $memberType = MemberType::from($result['t']);
            $accessType = AccessType::from($result['a']);

            $origin = new UsageOrigin(
                className: $result['o']['c'],
                memberName: $result['o']['m'],
                memberType: $result['o']['t'] !== null ? MemberType::from($result['o']['t']) : null,
                accessType: $result['o']['a'] !== null ? AccessType::from($result['o']['a']) : null,
                fileName: $result['o']['f'] === '_' ? $scopeFile : $result['o']['f'],
                line: $result['o']['l'],
                provider: $result['o']['p'],
                note: $result['o']['n'],
            );
        } catch (TypeError | ValueError $e) {
            throw new LogicException('Deserialization failure: ' . $e->getMessage(), 0, $e);
        }

        $exclusionReason = $result['e'];

        $usage = match ($memberType) {
            MemberType::CONSTANT => new ClassConstantUsage(
                $origin,
                new ClassConstantRef(
                    $result['m']['c'],
                    $result['m']['m'],
                    $result['m']['d'],
                    self::deserializeTrinary($result['m']['e']),
                ),
            ),
            MemberType::METHOD => new ClassMethodUsage(
                $origin,
                new ClassMethodRef($result['m']['c'], $result['m']['m'], $result['m']['d']),
            ),
            MemberType::PROPERTY => new ClassPropertyUsage(
                $origin,
                new ClassPropertyRef($result['m']['c'], $result['m']['m'], $result['m']['d']),
                $accessType,
            ),
        };

        return new self($usage, $exclusionReason);
    }

    private function serializeTrinary(TrinaryLogic $isEnumCaseFetch): int
    {
        return match (true) {
            $isEnumCaseFetch->no() => -1,
            $isEnumCaseFetch->yes() => 1,
            default => 0,
        };
    }

    public static function deserializeTrinary(int $value): TrinaryLogic
    {
        return match ($value) {
            -1 => TrinaryLogic::createNo(),
            1 => TrinaryLogic::createYes(),
            default => TrinaryLogic::createMaybe(),
        };
    }

}
