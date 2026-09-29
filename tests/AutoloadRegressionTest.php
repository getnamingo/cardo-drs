<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "FAIL: Composer autoloader not found. Run composer install first.\n");
    exit(1);
}

require $autoload;

$class = \Namingo\Cardo\DRS\RegistrarAPI::class;

if (!class_exists($class)) {
    fwrite(STDERR, "FAIL: {$class} is not autoloadable from Composer's configured PSR-4 namespace.\n");
    exit(1);
}

$reflection = new ReflectionClass($class);
if ($reflection->getNamespaceName() !== 'Namingo\\Cardo\\DRS') {
    fwrite(STDERR, "FAIL: RegistrarAPI is declared in the wrong namespace.\n");
    exit(1);
}

echo "RegistrarAPI Composer autoload regression test passed.\n";
