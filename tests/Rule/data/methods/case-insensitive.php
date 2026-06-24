<?php declare(strict_types = 1);

namespace DeadCaseInsensitive;

class Calculator
{

    public function getIdByReference(): int // used below via a differently-cased call
    {
        return 1;
    }

    public static function createInstance(): self // used below via a differently-cased static call
    {
        return new self();
    }

    public function reallyUnused(): void // error: Unused DeadCaseInsensitive\Calculator::reallyUnused
    {
    }

}

trait GreetingTrait
{

    public function sayHello(): string // used below via a differently-cased call on the consumer
    {
        return 'hi';
    }

}

class Service
{

    use GreetingTrait;

    public function run(Calculator $calc): void
    {
        $calc->getIdByreference();    // lowercase 'r'
        Calculator::CREATEINSTANCE(); // upper-cased static call
        $this->SAYHELLO();            // upper-cased trait method call
    }

}

function entry(Service $service, Calculator $calc): void
{
    $service->Run($calc); // upper-cased 'R'
}
