<?php

declare(strict_types=1);

/**
 * Backward compatibility for the historical Namingo\Registrars namespace.
 *
 * Namingo\Cardo\DRS is the canonical namespace. The Composer package name
 * remains namingo/registrars, and existing applications may continue using
 * Namingo\Registrars without source changes.
 */
spl_autoload_register(static function (string $class): void {
    $legacyPrefix = 'Namingo\\Registrars\\';
    $canonicalPrefix = 'Namingo\\Cardo\\DRS\\';

    if (!str_starts_with($class, $legacyPrefix)) {
        return;
    }

    $canonicalClass = $canonicalPrefix . substr($class, strlen($legacyPrefix));

    if (
        class_exists($canonicalClass)
        || interface_exists($canonicalClass)
        || enum_exists($canonicalClass)
    ) {
        class_alias($canonicalClass, $class);
    }
});
