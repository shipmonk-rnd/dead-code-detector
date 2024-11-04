<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

use LogicException;
use function count;
use function explode;

/**
 * @immutable
 */
class Call
{

    public ?Method $caller;

    public Method $callee;

    public bool $possibleDescendantCall;

    public function __construct(
        ?Method $caller,
        Method $callee,
        bool $possibleDescendantCall
    )
    {
        $this->caller = $caller;
        $this->callee = $callee;
        $this->possibleDescendantCall = $possibleDescendantCall;
    }

    public function toString(): string
    {
        $callerRef = $this->caller === null ? '' : $this->caller->toString();
        $calleeRef = $this->callee->toString();

        return "{$callerRef}->$calleeRef;" . ($this->possibleDescendantCall ? '1' : '');
    }

    public static function fromString(string $callKey): self
    {
        $split1 = explode(';', $callKey);

        if (count($split1) !== 2) {
            throw new LogicException("Invalid method key: $callKey");
        }

        [$edgeKey, $possibleDescendantCall] = $split1;

        $split2 = explode('->', $edgeKey);

        if (count($split2) !== 2) {
            throw new LogicException("Invalid method key: $callKey");
        }

        [$callerKey, $calleeKey] = $split2;

        $calleeSplit = explode('::', $calleeKey);

        if (count($calleeSplit) !== 2) {
            throw new LogicException("Invalid method key: $callKey");
        }

        [$calleeClassName, $calleeMethodName] = $calleeSplit;
        $callee = new Method(
            $calleeClassName === Method::UNKNOWN_CLASS ? null : $calleeClassName,
            $calleeMethodName,
        );

        if ($callerKey === '') {
            $caller = null;
        } else {
            $callerSplit = explode('::', $callerKey);

            if (count($callerSplit) !== 2) {
                throw new LogicException("Invalid method key: $callKey");
            }

            [$callerClassName, $callerMethodName] = $callerSplit;
            $caller = new Method($callerClassName, $callerMethodName);
        }

        return new self(
            $caller,
            $callee,
            $possibleDescendantCall === '1',
        );
    }

}
