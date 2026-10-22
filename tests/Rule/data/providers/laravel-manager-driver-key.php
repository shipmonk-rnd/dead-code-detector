<?php declare(strict_types = 1);

namespace LaravelManagerDriverKey;

use Illuminate\Support\MultipleInstanceManager;

class StoreManager extends MultipleInstanceManager
{
    protected $driverKey = 'store';

    public function getDefaultInstance(): string
    {
        return 'memory';
    }

    public function setDefaultInstance($name): void
    {
    }

    public function getInstanceConfig($name): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function createMemoryStore(array $config): object
    {
        return new \stdClass();
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function createMemoryDriver(array $config): object // error: Unused LaravelManagerDriverKey\StoreManager::createMemoryDriver
    {
        return new \stdClass();
    }
}
