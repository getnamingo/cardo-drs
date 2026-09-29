<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$canonicalClasses = [
    Namingo\Cardo\DRS\Registrar::class,
    Namingo\Cardo\DRS\Adapter\Mock::class,
    Namingo\Cardo\DRS\Contact::class,
    Namingo\Cardo\DRS\Exception\AuthException::class,
    Namingo\Cardo\DRS\TransferStatusEnum::class,
];

$legacyClasses = [
    'Namingo\\Registrars\\Registrar',
    'Namingo\\Registrars\\Adapter\\Mock',
    'Namingo\\Registrars\\Contact',
    'Namingo\\Registrars\\Exception\\AuthException',
    'Namingo\\Registrars\\TransferStatusEnum',
];

foreach ($canonicalClasses as $class) {
    if (!class_exists($class) && !enum_exists($class)) {
        throw new RuntimeException("Canonical symbol failed to autoload: {$class}");
    }
}

foreach ($legacyClasses as $class) {
    if (!class_exists($class) && !enum_exists($class)) {
        throw new RuntimeException("Legacy symbol failed to autoload: {$class}");
    }
}

if (!is_a('Namingo\\Registrars\\Registrar', Namingo\Cardo\DRS\Registrar::class, true)) {
    throw new RuntimeException('Legacy Registrar alias does not resolve to Cardo DRS.');
}

if (!is_a('Namingo\\Registrars\\Adapter\\Mock', Namingo\Cardo\DRS\Adapter\Mock::class, true)) {
    throw new RuntimeException('Legacy adapter alias does not resolve to Cardo DRS.');
}

echo "Cardo DRS compatibility test passed.\n";
