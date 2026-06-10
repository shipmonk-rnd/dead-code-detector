<?php

namespace ArrayCallableDynamicName;

class Test {

    public function run(string $methodName): void
    {
        if (is_callable([$this, $methodName])) {
            call_user_func([$this, $methodName]);
        }
    }

    public function maybeCalledDynamically(): void
    {
    }

}

(new Test())->run('maybeCalledDynamically');
