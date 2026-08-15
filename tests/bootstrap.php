<?php

declare( strict_types = 1 );

$mwRoot = __DIR__ . '/../../..';

// Load MW vendor autoloader first (includes third-party libraries)
if ( file_exists( $mwRoot . '/vendor/autoload.php' ) ) {
	$loader = require $mwRoot . '/vendor/autoload.php';
} else {
	$loader = require __DIR__ . '/../vendor/autoload.php';
}

// Register MediaWiki core namespaces for standalone test execution
$loader->addPsr4( 'MediaWiki\\', $mwRoot . '/includes/' );
$loader->addPsr4( 'Wikimedia\\Rdbms\\', $mwRoot . '/includes/libs/rdbms/' );

// Load extension autoloader
require_once __DIR__ . '/../vendor/autoload.php';
