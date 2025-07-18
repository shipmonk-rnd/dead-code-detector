<?php

namespace DebugForeign;

(new \DateTime)->format('Y-m-d H:i:s');

function test(\DateTime $d, string $method): void{
    $d->$method(); // requested debug of DateTime::format wont show this usage as mixed member usages are expanded only for classes defined in analysed files
}
