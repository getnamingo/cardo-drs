<?php
namespace Namingo\Cardo\DRS;

use Namingo\Cardo\DRS\Core\BaseAdapter;

final class RegistrarAPI
{
    /** brand aliases (input => canonical Studly) */
    private const ALIAS = [
        'namesilo'  => 'Namesilo',
        'name-silo' => 'Namesilo',
        'go-daddy'  => 'GoDaddy',
        'godaddy'   => 'GoDaddy',
        'namecheap'  => 'Namecheap',
        'dynadot'  => 'Dynadot',
        'name.com'  => 'NameCom',
        'namecom'   => 'NameCom',
        'name-com'  => 'NameCom',
        'opensrs'   => 'OpenSRS',
        'open-srs'  => 'OpenSRS',
    ];

    public static function make(string $brand, array $creds): BaseAdapter
    {
        $brandKey = strtolower(trim($brand));
        $studly   = self::ALIAS[$brandKey] ?? self::studly($brand);

        // Try both namespace casings + with/without "Adapter" suffix
        $namespaces = ['\\Namingo\\Cardo\\DRS\\Adapters\\'];
        $candidates = [];
        foreach ($namespaces as $ns) {
            $candidates[] = $ns . $studly;
            $candidates[] = $ns . $studly . 'Adapter';  // e.g. \...Adapters\CloudflareAdapter
        }

        foreach ($candidates as $class) {
            if (class_exists($class)) {
                $obj = new $class($creds);
                if (!$obj instanceof BaseAdapter) {
                    throw new \RuntimeException("$class must extend BaseAdapter");
                }
                return $obj;
            }
        }

        throw new \InvalidArgumentException(
            "Unknown registrar brand: {$brand}. Tried: " . implode(', ', $candidates)
        );
    }

    private static function studly(string $s): string
    {
        $s = preg_replace('/[^a-z0-9]+/i', ' ', $s);
        $s = ucwords(strtolower(trim($s)));
        return str_replace(' ', '', $s);
    }
}
