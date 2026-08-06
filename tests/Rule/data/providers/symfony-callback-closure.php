<?php declare(strict_types = 1);

namespace SymfonyCallbackClosure;

use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Assert\Callback(static function (self $object, ExecutionContextInterface $context): void {
    if ($object->from !== null || $object->to !== null) {
        return;
    }

    $context->buildViolation('You must specify at least one date.')
        ->atPath('from')
        ->addViolation();
})]
#[Assert\Callback('validateRange')]
#[Assert\Callback(self::CALLBACK_METHOD)]
class CreatedAt
{

    private const CALLBACK_METHOD = 'validateConst';

    public function __construct(
        public readonly ?DateTimeImmutable $from = null,
        public readonly ?DateTimeImmutable $to = null,
    )
    {
    }

    public function validateRange(ExecutionContextInterface $context): void
    {
    }

    public function validateConst(ExecutionContextInterface $context): void
    {
    }

    public function unusedMethod(): void // error: Unused SymfonyCallbackClosure\CreatedAt::unusedMethod
    {
    }

}

new CreatedAt();
