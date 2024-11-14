<?php declare(strict_types = 1);

namespace DeadEnum;

enum TestEnum: string {


    public function unusedEnumMethod(): void // error: Unused DeadEnum\TestEnum::unusedEnumMethod
    {
        self::cases();
    }
}
