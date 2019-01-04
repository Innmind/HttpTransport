# HttpTransport

| `master` | `develop` |
|----------|-----------|
| [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/Innmind/HttpTransport/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/Innmind/HttpTransport/?branch=master) | [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/Innmind/HttpTransport/badges/quality-score.png?b=develop)](https://scrutinizer-ci.com/g/Innmind/HttpTransport/?branch=develop) |
| [![Code Coverage](https://scrutinizer-ci.com/g/Innmind/HttpTransport/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/Innmind/HttpTransport/?branch=master) | [![Code Coverage](https://scrutinizer-ci.com/g/Innmind/HttpTransport/badges/coverage.png?b=develop)](https://scrutinizer-ci.com/g/Innmind/HttpTransport/?branch=develop) |
| [![Build Status](https://scrutinizer-ci.com/g/Innmind/HttpTransport/badges/build.png?b=master)](https://scrutinizer-ci.com/g/Innmind/HttpTransport/build-status/master) | [![Build Status](https://scrutinizer-ci.com/g/Innmind/HttpTransport/badges/build.png?b=develop)](https://scrutinizer-ci.com/g/Innmind/HttpTransport/build-status/develop) |

This library allows you to send [http](https://packagist.org/packages/innmind/http) request.

## Installation

```sh
composer require innmind/http-transport
```

## Usage

Send a request:

```php
use function Innmind\HttpTransport\bootstrap;
use Innmind\Http\Message\Request\Request;

$fulfill = bootstrap()['default']();

$response = $fulfill(
    new Request(/* initialize your request */)
);
```

## Log the request

You can easily log all your request like so:

```php
use Psr\Log\LoggerInterface;

$transports = bootstrap();
$guzzle = $transports['default']();
$log = $transports['logger'](/* an instance of LoggerInterface */);
$fulfill = $log(
    $default
);

$fulfill(/* your request */):
```

Here a message is logged before the request is sent and another one once its sent.
