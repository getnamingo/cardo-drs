# Cardo DRS

A unified PHP library for working with multiple domain registrars through one consistent API.

Cardo DRS provides a common interface for domain availability checks, registrations, renewals, transfers, domain management, and other registrar operations. Applications can work with different registrar providers without implementing each provider API separately.

## Features

- Unified interface for multiple domain registrars
- Domain availability checks
- Domain registration
- Domain renewal
- Domain transfers
- Domain information retrieval
- Authorization-code retrieval
- Transfer-status checks
- Contact management
- Nameserver configuration
- Domain suggestions
- Auto-renew management
- Test and production API support
- Extensible registrar adapter architecture

## Supported registrars

The following adapters are currently available:

- Name.com
- OpenSRS

Additional registrar adapters may be added without changing the public library interface.

## Requirements

- PHP 8.3 or later
- PHP cURL extension
- Composer

Verify that the cURL extension is enabled:

```bash
php -m | grep curl
```

## Installation

Install the package with Composer:

```bash
composer require namingo/registrars
```

Until the package is published on Packagist, it can be installed from a local path or directly from its Git repository.

### Local Composer repository

Add the repository to your application's `composer.json`:

```json
{
    "require": {
        "namingo/registrars": "*"
    }
}
```

Then run:

```bash
composer update namingo/registrars
```

## Basic usage

Load the Composer autoloader:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
```

## Name.com example

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Namingo\Registrars\Adapter\NameCom;
use Namingo\Registrars\Registrar;

$adapter = new NameCom(
    'username',
    'api-token',
    'https://api.dev.name.com'
);

$registrar = new Registrar($adapter);

$available = $registrar->available('example-domain.com');

var_dump($available);
```

Use the production API endpoint when working with a live Name.com account:

```text
https://api.name.com
```

Use the development endpoint for testing:

```text
https://api.dev.name.com
```

## OpenSRS example

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Namingo\Registrars\Adapter\OpenSRS;
use Namingo\Registrars\Registrar;

$adapter = new OpenSRS(
    'api-key',
    'username',
    'password',
    'https://horizon.opensrs.net:55443'
);

$registrar = new Registrar($adapter);

$available = $registrar->available('example-domain.com');

var_dump($available);
```

## Creating a contact

```php
use Namingo\Registrars\Contact;

$contact = new Contact(
    'John',
    'Doe',
    '+1.5555555555',
    'john@example.com',
    '123 Example Street',
    '',
    '',
    'Example City',
    'Example State',
    'US',
    '12345',
    'Example Company',
    'owner'
);
```

## Registering a domain

```php
$registration = $registrar->purchase(
    'example-domain.com',
    [$contact],
    1
);
```

A custom nameserver list can also be provided:

```php
$registration = $registrar->purchase(
    'example-domain.com',
    [$contact],
    1,
    [
        'ns1.example.com',
        'ns2.example.com',
    ]
);
```

## Checking availability

```php
$available = $registrar->available('example-domain.com');

if ($available) {
    echo 'The domain is available.';
} else {
    echo 'The domain is unavailable.';
}
```

## Retrieving domain information

```php
$domain = $registrar->getDomain('example-domain.com');
```

## Renewing a domain

```php
$renewal = $registrar->renew(
    'example-domain.com',
    1
);
```

## Transferring a domain

```php
$registration = $registrar->transfer(
    'example-domain.com',
    'authorization-code',
    [$contact],
    1
);
```

Custom nameservers can be supplied during the transfer:

```php
$registration = $registrar->transfer(
    'example-domain.com',
    'authorization-code',
    [$contact],
    1,
    [
        'ns1.example.com',
        'ns2.example.com',
    ]
);
```

## Retrieving an authorization code

```php
$authorizationCode = $registrar->getAuthCode(
    'example-domain.com'
);
```

## Checking transfer status

```php
$status = $registrar->checkTransferStatus(
    'example-domain.com'
);
```

## Updating domain settings

```php
use Namingo\Registrars\UpdateDetails;

$details = new UpdateDetails(
    autoRenew: true
);

$registrar->updateDomain(
    'example-domain.com',
    $details
);
```

## Domain suggestions

```php
$suggestions = $registrar->suggest(
    ['example', 'example-domain'],
    ['com', 'net', 'org'],
    10
);
```

## Error handling

Registrar API requests may fail because of invalid credentials, malformed domain data, unavailable provider services, account restrictions, or network errors.

Production integrations should wrap registrar calls in exception handling:

```php
try {
    $available = $registrar->available('example-domain.com');
} catch (\Throwable $exception) {
    error_log($exception->getMessage());

    echo 'The registrar request failed.';
}
```

Do not expose registrar credentials, API responses containing private data, or internal exception details to end users.

## Creating an adapter

Registrar integrations are implemented as adapters. A new adapter should implement the library's registrar adapter contract and translate the common Cardo DRS operations into requests supported by the upstream provider.

An adapter is responsible for:

- Authentication
- API request construction
- Provider-specific response parsing
- Error normalization
- Mapping provider data to Cardo DRS objects
- Distinguishing test and production environments

Provider-specific behavior should remain inside the adapter so applications can continue using the same public interface.

## Namespace

The package uses the following root namespace:

```php
Namingo\Registrars
```

Examples:

```php
use Namingo\Registrars\Registrar;
use Namingo\Registrars\Contact;
use Namingo\Registrars\Adapter\NameCom;
use Namingo\Registrars\Adapter\OpenSRS;
```

## Security

Registrar credentials provide access to valuable domain assets. Applications using this library should:

- Store credentials outside the source code
- Use environment variables or a secrets manager
- Restrict access to configuration files
- Use test endpoints during development
- Log failures without logging passwords or API tokens
- Validate domain names and contact information before sending requests
- Keep PHP and package dependencies updated

Example environment variables:

```dotenv
REGISTRAR_USERNAME=
REGISTRAR_API_KEY=
REGISTRAR_PASSWORD=
REGISTRAR_API_URL=
```

## Project status

Cardo DRS is under active development.

Interfaces and adapter behavior may change before the first stable release. Test registrar operations carefully before using the library with production accounts.

## Acknowledgements

Cardo DRS is based on the registrar API implementation from the [Utopia Domains](https://github.com/utopia-php/domains) project.

Original project authors: Eldad Fux and Wess Cope.

The original software is distributed under the MIT License.

Cardo DRS contains modifications, namespace changes, additional integrations, and continued development maintained by the Namingo project.

## License

Cardo DRS is distributed under the MIT License.

This project includes software derived from Utopia Domains.