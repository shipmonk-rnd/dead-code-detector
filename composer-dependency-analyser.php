<?php declare(strict_types = 1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$pharFile = __DIR__ . '/vendor/phpstan/phpstan/phpstan.phar';
Phar::loadPhar($pharFile, 'phpstan.phar');

require_once('phar://phpstan.phar/preload.php'); // prepends PHPStan's PharAutolaoder to composer's autoloader

$config = (new Configuration())
    ->ignoreErrorsOnPath(__DIR__ . '/src/Provider', [ErrorType::DEV_DEPENDENCY_IN_PROD]) // providers are designed that way
    ->ignoreErrorsOnExtensionAndPath('ext-simplexml', __DIR__ . '/src/Provider/SymfonyUsageProvider.php', [ErrorType::SHADOW_DEPENDENCY]) // guarded with extension_loaded()
    ->addPathToExclude(__DIR__ . '/tests/Rule/data');

if (PHP_VERSION_ID < 80100) {
    $config->ignoreUnknownClasses([
        'ReflectionEnum',
        'ReflectionEnumBackedCase',
        'ReflectionEnumUnitCase'
    ]);
}

return $config;
