# Cardo DRS - Multi-Registrar Domain Management Library

Cardo DRS is a unified PHP interface for working with multiple domain registrar APIs through a **single, consistent API**.

It provides a common abstraction for domain registration and management operations, reducing the need to implement separate integration logic for every registrar. Applications can use the same interface for common tasks such as checking domain availability, registering and renewing domains, managing transfers, nameservers, and DNS records.

Cardo DRS uses a pluggable adapter architecture, making it straightforward to add support for additional registrars while keeping application code independent from provider-specific APIs.

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

## Installation

```bash
composer require namingo/cardo-drs
```

If you’re developing locally from the repo, ensure PSR‑4 autoloading is refreshed:

```bash
composer dump-autoload -o
```

## Quick Start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Namingo\Cardo\DRS\RegistrarAPI;

// Pick your registrar by brand string (case-insensitive):
// 'namesilo', 'godaddy', 'namecheap', 'dynadot', 'namecom', 'opensrs', 'opusdns'
$api = RegistrarAPI::make('namesilo', [
    'api_key' => 'YOUR_NAMESILO_API_KEY'
]);

// Check availability for one or more domains
$result = $api->checkAvailability(['example.com', 'mybrand.io']);
print_r($result);

// Set nameservers (works the same across all adapters)
$api->setNameServers('example.com', ['ns1.host.com', 'ns2.host.com']);
```

## Supported Providers

Registrar adapters require provider-specific API credentials. Pass the required credentials in the adapter configuration array; optional sandbox or endpoint settings can be supplied where supported.

| Provider    |
|------------|
| **NameSilo** |
| **GoDaddy** |
| **Namecheap** |
| **Dynadot** |
| **Name.com** |
| **OpenSRS** |
| **OpusDNS** |

## Credentials per Adapter

Each adapter accepts a config array. The keys below are the **minimum** you usually need.

### NameSilo
```php
$api = RegistrarAPI::make('namesilo', [
  'api_key' => 'YOUR_KEY'
]);
```

### GoDaddy
```php
$api = RegistrarAPI::make('godaddy', [
  'api_key'    => 'KEY',
  'api_secret' => 'SECRET',
  // optional: 'base' => 'https://api.godaddy.com/v1'  // defaults to production
]);
```

### Namecheap
```php
$api = RegistrarAPI::make('namecheap', [
  'api_user'  => 'USERNAME',
  'api_key'   => 'KEY',
  'client_ip' => 'SERVER_PUBLIC_IP',
  // optional: 'base' => 'https://api.namecheap.com/xml.response'
]);
```

### Dynadot
```php
$api = RegistrarAPI::make('dynadot', [
  'api_key' => 'KEY',
  // optional: 'base' => 'https://api.dynadot.com/api3.json'
]);
```

### Name.com
```php
$api = RegistrarAPI::make('namecom', [
  'username' => 'USERNAME',
  'token' => 'API_TOKEN',
  // optional: 'base' => 'https://api.dev.name.com' for testing
]);
```

### OpenSRS
```php
$api = RegistrarAPI::make('opensrs', [
  'api_key' => 'API_KEY',
  'username' => 'RESELLER_USERNAME',
  'password' => 'REGISTRATION_PASSWORD',
  // optional: 'endpoint' => 'https://horizon.opensrs.net:55443'
]);
```

### OpusDNS
```php
$api = RegistrarAPI::make('opusdns', [
  'api_key' => 'YOUR_API_KEY',
  // optional: 'sandbox' => true (https://sandbox.opusdns.com)
  // optional: 'base' => 'https://api.opusdns.com' (HTTPS API root, without /v1)
]);
```

The adapter follows the v1 API contract in the official
[OpusDNS PHP client](https://github.com/OpusDNS/opusdns-php-client), using Cardo's
existing HTTP transport. No additional package is required. `opus-dns` is also a
supported brand alias; `token` and `endpoint` are credential aliases.

```php
$api->registerDomain('example.com', [
  'years' => 1,
  'auto_renew' => false,
  'create_zone' => true,
  'contacts' => [
    'registrant' => 'contact_EXISTING_ID',
    'admin' => 'contact_EXISTING_ID',
    'tech' => 'contact_EXISTING_ID',
    'billing' => 'contact_EXISTING_ID',
  ],
  'nameservers' => ['ns1.opusdns.com', 'ns2.opusdns.com'],
]);
```

Supply only the contact roles required by the TLD. Each role accepts an existing
contact ID, a `['contact_id' => '...', 'attributes' => [...]]` handle, a contact
data array, or a list of handles/contact arrays. A top-level `registrant` array
is shorthand for `contacts['registrant']`. New contacts require `first_name`,
`last_name`, `email`, `phone`, `street` (or `address`/`address1`), `city`,
`postal_code` (or `zip`), and `country`; `org`, `state`, `fax`, `title` and
`disclose` are optional. Identical new contacts are reused within the same call.
Created contacts remain in the account if the subsequent domain request fails;
reuse their IDs on retry. Domain results include the submitted `contacts`; contact
creation failures include any `created_contact_ids` from earlier roles. No missing
roles are filled automatically.

`privacy => true` requests `disclose => false` for newly created contacts. It
does not change existing handles or purchase a privacy service, and registries
may still disclose data under their own rules. `attributes` and
`claims_notice_acceptance_hash` can be passed for TLD-specific requirements;
contact handles can carry inline `attributes`. Premium confirmation uses the
explicit decimal string `expected_price`, which is also accepted on renewal
and transfer.

Renewal reads the current expiry first, unless `current_expiry_date` is supplied.
Transfers accept `auth_code`, optional contacts and nameservers, and optional
`years`; omitting `years` leaves the transfer period to registry policy.

DNS operates on an **existing OpusDNS-hosted zone** (`create_zone => true` creates
one at registration/transfer; use `raw('/v1/dns', ...)` to create one separately).
Hosts accept `@`, relative names, or in-zone fully qualified names. Values are
DNS presentation-format RDATA: keep TXT quoting and domain-name trailing dots
where needed. MX `prio`/`priority` is prepended to the target; for SRV, put the
weight, port and target in `value`, with the priority in `prio`.

`setDNS()` sends the complete desired RRset list in one PUT; records of the same
name/type must use one TTL. `addDNS()` upserts one record. `getDNS()` flattens
RRsets and exposes `protected`; OpusDNS does not supply individual record IDs.
`delDNS()` matches `type`, `host`, `value`, and/or `prio`/`priority` and refuses
protected records. An empty or ID-only selector is rejected.

```php
// Raw paths include /v1. Optional query arrays use repeated keys.
$api->raw('/v1/domains/example.com', [
  'method' => 'PATCH',
  'body' => ['status_changes' => ['add' => ['clientTransferProhibited']]],
]);
```

---

## Common Operations (Unified Shape)

All adapters implement these methods (see `src/Core/BaseAdapter.php`). Return values are normalized as associative arrays so you can handle responses consistently.

```php
// Availability
$api->checkAvailability(['example.com']); 
// -> ['ok'=>bool, 'available'=>[], 'unavailable'=>[], 'invalid'=>[], 'raw'=>mixed]

// Purchase / Lifecycle
$api->registerDomain('example.com', [
  'years' => 1,
  'privacy' => true,
  'auto_renew' => true,
  // 'registrant' or adapter-specific contact fields (see adapter docs)
]);

$api->renewDomain('example.com', 1);   // -> ['ok'=>bool, 'raw'=>mixed]
$api->transferDomain('example.com', ['auth_code' => 'EPP']); // -> ['ok'=>bool, 'raw'=>mixed]
$api->getDomain('example.com');        // -> ['ok'=>bool, 'raw'=>mixed]

// DNS Records
$api->getDNS('example.com');           // -> ['ok'=>bool, 'records'=>[{'type','host','value','ttl','prio'?}], ...]

$api->setDNS('example.com', [
  ['type'=>'A','host'=>'@','value'=>'203.0.113.10','ttl'=>600],
  ['type'=>'CNAME','host'=>'www','value'=>'@','ttl'=>600],
]);

$api->addDNS('example.com', ['type'=>'TXT','host'=>'@','value'=>'v=spf1 -all','ttl'=>300]);
$api->delDNS('example.com', ['type'=>'TXT','host'=>'@']); // selector varies per adapter

// Nameservers (available for ALL adapters in this library)
$api->setNameServers('example.com', ['ns1.host.com','ns2.host.com']);
```

> ⚠️ **Contacts & Required Fields** differ slightly per registrar. The library forwards what you pass; check the registrar’s API docs if a call fails due to missing fields.

---

## Adapter‑Specific Examples

### NameSilo – Register a domain
```php
$api = RegistrarAPI::make('namesilo', ['api_key' => 'KEY']);
$api->registerDomain('brandnewdomain.com', [
  'years' => 1,
  'privacy' => true,
  'auto_renew' => false,
  'registrant' => [
    'first_name' => 'Jane',
    'last_name'  => 'Doe',
    'email'      => 'jane@example.com',
    'phone'      => '+1.5555555555',
    'address'    => '123 Street',
    'city'       => 'LA',
    'state'      => 'CA',
    'zip'        => '90001',
    'country'    => 'US'
  ]
]);
```

### GoDaddy – DNS update
```php
$api = RegistrarAPI::make('godaddy', ['api_key'=>'KEY','api_secret'=>'SECRET']);
$api->setDNS('example.com', [
  ['type'=>'A','host'=>'@','value'=>'198.51.100.20','ttl'=>600],
  ['type'=>'A','host'=>'blog','value'=>'198.51.100.21','ttl'=>600],
]);
```

### Namecheap – Set custom nameservers
```php
$api = RegistrarAPI::make('namecheap', ['api_user'=>'USER','api_key'=>'KEY','client_ip'=>'203.0.113.22']);
$api->setNameServers('example.com', ['ns1.customdns.com','ns2.customdns.com']);
```

### Dynadot – Check and register
```php
$api = RegistrarAPI::make('dynadot', ['api_key'=>'KEY']);
$check = $api->checkAvailability(['mynew.io']);
if (!empty($check['available'])) {
  $api->registerDomain('mynew.io', ['years'=>1,'privacy'=>true]);
}
```

---

## Raw Passthrough (Escape Hatch)

Need a command the wrapper doesn’t expose yet? Call the adapter directly:

```php
$gd = RegistrarAPI::make('godaddy', ['api_key'=>'KEY','api_secret'=>'SECRET']);
$res = $gd->raw('domains/suggestions?query=mybrand&limit=5'); // path relative to GoDaddy base
print_r($res);
```

---

## Adding a New Registrar

1. Create a class in `src/adapters/{Brand}.php`:
```php
<?php
namespace Namingo\Cardo\DRS\Adapters;

use Namingo\Cardo\DRS\Core\BaseAdapter;

class MyRegistrar extends BaseAdapter {
  protected string $brand = 'myregistrar';

  public function checkAvailability(array $domains): array { /* ... */ }
  public function registerDomain(string $domain, array $opts): array { /* ... */ }
  public function renewDomain(string $domain, int $years=1, array $opts=[]): array { /* ... */ }
  public function transferDomain(string $domain, array $opts): array { /* ... */ }
  public function getDomain(string $domain): array { /* ... */ }
  public function getDNS(string $domain): array { /* ... */ }
  public function setDNS(string $domain, array $records): array { /* ... */ }
  public function addDNS(string $domain, array $record): array { /* ... */ }
  public function delDNS(string $domain, array $selector): array { /* ... */ }
  public function setNameServers(string $domain, array $nameservers): array { /* ... */ }
  public function raw(string $op, array $params=[]): array { /* ... */ }
}
```
2. Composer autoloading will pick it up automatically with:
```php
$api = RegistrarAPI::make('myregistrar', [...creds...]);
```

---

## Error Handling

Every method returns a structure with:
- `ok` (bool) — quick success check
- `raw` — original parsed payload (JSON/XML/array)
- `http` — HTTP status code (when available)
- `err` — transport‑level error string (if any)

You can also wrap calls in try/catch if you layer exceptions in your project.

---

## Common Operations (Unified Shape)

All adapters implement these methods (see `src/Core/BaseAdapter.php`). Return values are normalized as associative arrays so you can handle responses consistently.

```php
// Availability
$api->checkAvailability(['example.com']); 

// Purchase / Lifecycle
$api->registerDomain('example.com', [
  'years' => 1,
  'privacy' => true,
  'auto_renew' => true,
  // 'registrant' or adapter-specific contact fields (see adapter docs)
]);

$api->renewDomain('example.com', 1);
$api->transferDomain('example.com', ['auth_code' => 'EPP']);
$api->getDomain('example.com');

// DNS Records
$api->getDNS('example.com');

$api->setDNS('example.com', [
  ['type'=>'A','host'=>'@','value'=>'203.0.113.10','ttl'=>600],
  ['type'=>'CNAME','host'=>'www','value'=>'@','ttl'=>600],
]);

$api->addDNS('example.com', ['type'=>'TXT','host'=>'@','value'=>'v=spf1 -all','ttl'=>300]);
$api->delDNS('example.com', ['type'=>'TXT','host'=>'@']); 

// Nameservers
$api->setNameServers('example.com', ['ns1.host.com','ns2.host.com']);
```

> ⚠️ **Contacts & Required Fields** differ slightly per registrar.

---

## Full Tutorials per Adapter

### NameSilo – Complete Flow
```php
use Namingo\Cardo\DRS\RegistrarAPI;

$api = RegistrarAPI::make('namesilo', ['api_key' => 'KEY']);

// 1. Check availability
$check = $api->checkAvailability(['newdomain123.com']);
if (!empty($check['available'])) {

    // 2. Register
    $api->registerDomain('newdomain123.com', [
      'years' => 1,
      'privacy' => true,
      'auto_renew' => false,
      'registrant' => [
        'first_name' => 'Jane',
        'last_name'  => 'Doe',
        'email'      => 'jane@example.com',
        'phone'      => '+1.5555555555',
        'address'    => '123 Street',
        'city'       => 'LA',
        'state'      => 'CA',
        'zip'        => '90001',
        'country'    => 'US'
      ]
    ]);

    // 3. Set nameservers
    $api->setNameServers('newdomain123.com', ['ns1.custom.com','ns2.custom.com']);

    // 4. Add DNS
    $api->addDNS('newdomain123.com', ['type'=>'A','host'=>'@','value'=>'203.0.113.55','ttl'=>600]);
}
```

### GoDaddy – Complete Flow
```php
$api = RegistrarAPI::make('godaddy', ['api_key'=>'KEY','api_secret'=>'SECRET']);

$check = $api->checkAvailability(['newbrand.io']);
if (!empty($check['available'])) {
    $api->registerDomain('newbrand.io', [
        'years' => 1,
        'privacy' => true
    ]);
    $api->setDNS('newbrand.io', [
      ['type'=>'A','host'=>'@','value'=>'198.51.100.20','ttl'=>600],
      ['type'=>'CNAME','host'=>'www','value'=>'@','ttl'=>600],
    ]);
}
```

### Namecheap – Complete Flow
```php
$api = RegistrarAPI::make('namecheap', [
    'api_user'=>'USER',
    'api_key'=>'KEY',
    'client_ip'=>'203.0.113.22'
]);

$check = $api->checkAvailability(['coolbrand.net']);
if (!empty($check['available'])) {
    $api->registerDomain('coolbrand.net', [
        'years' => 1,
        'privacy' => true
    ]);
    $api->setNameServers('coolbrand.net', ['ns1.customdns.com','ns2.customdns.com']);
}
```

### Dynadot – Complete Flow
```php
$api = RegistrarAPI::make('dynadot', ['api_key'=>'KEY']);

$check = $api->checkAvailability(['mynew.io']);
if (!empty($check['available'])) {
    $api->registerDomain('mynew.io', ['years'=>1,'privacy'=>true]);
    $api->setDNS('mynew.io', [
      ['type'=>'A','host'=>'@','value'=>'192.0.2.123','ttl'=>600],
      ['type'=>'TXT','host'=>'@','value'=>'v=spf1 -all','ttl'=>300],
    ]);
}
```

---

## Adding a New Registrar

1. Create `src/adapters/{Brand}.php`
2. Extend `BaseAdapter` and implement abstract methods
3. Example:

```php
namespace Namingo\Cardo\DRS\Adapters;

use Namingo\Cardo\DRS\Core\BaseAdapter;

class MyRegistrar extends BaseAdapter {
  protected string $brand = 'myregistrar';
  public function checkAvailability(array $domains): array { /* ... */ }
  // ... implement all abstract methods ...
}
```


## Support

Need help, found a bug, or have an idea for Cardo DRS?

- **Email:** [help@namingo.org](mailto:help@namingo.org)
- **Discord:** Join the community on [Discord](https://discord.gg/97R9VCrWgc)
- **GitHub Issues:** Report bugs or request features in [GitHub Issues](https://github.com/getnamingo/cardo-drs/issues)

Questions, feedback, and contributions are always welcome.

## Support This Project

If you find Cardo DRS useful, consider donating:

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

Cardo DRS is licensed under the MIT License.

Cardo DRS is based on [josuamarcelc/registrar-api](https://github.com/josuamarcelc/registrar-api), originally created by [josuamarcelc](https://josuamarcelc.com/), and is further developed and maintained by Terbora Ltd.

The Name.com and OpenSRS integrations are also based in part on the MIT-licensed registrar adapters from [utopia-php/domains](https://github.com/utopia-php/domains), with adaptations and additional Cardo DRS functionality.