<?php declare(strict_types = 1);

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

require_once __DIR__ . '/../doctrine-dql.php';
require_once __DIR__ . '/../doctrine-dql-nested.php';

$config = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true);

if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
    $config->enableNativeLazyObjects(true);
}

return new EntityManager(
    DriverManager::getConnection([
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ]),
    $config,
);
