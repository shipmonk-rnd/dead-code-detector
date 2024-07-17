<?php declare(strict_types = 1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$pharFile = __DIR__ . '/vendor/phpstan/phpstan/phpstan.phar';
Phar::loadPhar($pharFile, 'phpstan.phar');

require_once('phar://phpstan.phar/preload.php'); // prepends PHPStan's PharAutolaoder to composer's autoloader

return (new Configuration())
    ->ignoreErrorsOnPath(__DIR__ . '/src/Provider', [ErrorType::DEV_DEPENDENCY_IN_PROD]) // providers are designed that way
    ->addPathToExclude(__DIR__ . '/tests/Rule/data');
