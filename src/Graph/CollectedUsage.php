<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use JsonException;
use LogicException;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

final class CollectedUsage
{

    private ClassMemberUsage $usage;

    private ?string $excludedBy;

    public function __construct(
        ClassMemberUsage $usage,
        ?string $excludedBy
    )
    {
        $this->usage = $usage;
        $this->excludedBy = $excludedBy;
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

    public function concretizeMixedUsage(string $className): self
    {
        return new self(
            $this->usage->concretizeMixedUsage($className),
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
            't' => $this->usage->getMemberType(),
            'o' => [
                    'c' => $origin->getClassName(),
                    'm' => $origin->getMethodName(),
                    'f' => $origin->getFile() === $scopeFile ? '_' : $origin->getFile(),
                    'l' => $origin->getLine(),
                    'p' => $origin->getProvider(),
                    'n' => $origin->getNote(),
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

    public static function deserialize(string $data, string $scopeFile): self
    {
        try {
            /** @var array{e: string|null, t: MemberType::*, o: array{c: string|null, m: string|null, f: string|null, l: int|null, p: string|null, n: string|null}, m: array{c: string|null, m: string, d: bool}} $result */
            $result = json_decode($data, true, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('Deserialization failure: ' . $e->getMessage(), 0, $e);
        }

        $memberType = $result['t'];
        $origin = new UsageOrigin(
            $result['o']['c'],
            $result['o']['m'],
            $result['o']['f'] === '_' ? $scopeFile : $result['o']['f'],
            $result['o']['l'],
            $result['o']['p'],
            $result['o']['n'],
        );
        $exclusionReason = $result['e'];

        $usage = $memberType === MemberType::CONSTANT
            ? new ClassConstantUsage(
                $origin,
                new ClassConstantRef($result['m']['c'], $result['m']['m'], $result['m']['d']),
            )
            : new ClassMethodUsage(
                $origin,
                new ClassMethodRef($result['m']['c'], $result['m']['m'], $result['m']['d']),
            );

        return new self($usage, $exclusionReason);
    }

}
