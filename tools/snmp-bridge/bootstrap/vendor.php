<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    throw new RuntimeException('Autoload file is missing. Run composer dump-autoload or keep vendor/autoload.php in place.');
}

require $autoload;
