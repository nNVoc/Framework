<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException(
        'Composer autoload not found. Run these tests from the framework project after installing dependencies.'
    );
}

require_once $autoload;
require_once __DIR__ . '/Support/Fixtures.php';
