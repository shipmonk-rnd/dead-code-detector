<?php declare(strict_types = 1);

namespace Symfony;

use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;
use Symfony\Component\Scheduler\Attribute\AsSchedule;

enum SftpMode: string {
    case PASSIVE = 'passive'; // used in yaml via !php/enum
    case ACTIVE = 'active'; // error: Unused Symfony\SftpMode::ACTIVE
}

#[AsSchedule('default')]
class MyScheduleProvider {
    public function __construct() {}
}

#[AsCronTask('* * * * *')]
class MyCronTask {
    public function __construct() {}
    public function __invoke(): void {}
    public function dead(): void {} // error: Unused Symfony\MyCronTask::dead
}

#[AsPeriodicTask(60)]
class MyPeriodicTask {
    public function __construct() {}
    public function __invoke(): void {}
}

#[AsCronTask(expression: '0 * * * *', method: 'run')]
class MyCronTaskWithMethod {
    public function __construct() {}
    public function run(): void {}
    public function dead(): void {} // error: Unused Symfony\MyCronTaskWithMethod::dead
}

class MethodLevelSchedulerTask {
    #[AsCronTask('* * * * *')]
    public function cronJob(): void {}

    #[AsPeriodicTask(30)]
    public function periodicJob(): void {}

    public function dead(): void {} // error: Unused Symfony\MethodLevelSchedulerTask::dead
}
