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

$fulfill(/* your request */);
```

Here a message is logged before the request is sent and another one once its sent.

## Exponential Backoff

Sometimes when calling an external API it may not be available due to heavy load, in such case you could retry the http call after a certain amount of time leaving time for the API to recover. You can apply this pattern like so:

```php
use Innmind\TimeContinuum\TimeContinuum\Earth;
use Innmind\TimeWarp\Halt\Usleep;

$transports = bootstrap();
$guzzle = $transports['default']();
$fulfill = $transports['exponential_backoff'](
    $default,
    new Usleep,
    new Earth
);

$fulfill(/* your request */);
```

By default it will retry 5 times the request if the server is unavailable, following the given periods (in milliseconds) between each call: `100`, `271`, `738`, `2008` and `5459`.

## Circuit breaker

When a call to a certain domain fails you may want to all further calls to that domain to fail immediately as you know it means the host is down. Such pattern is called a circuit breaker.

```php
use Innmind\TimeContinuum\{
    TimeContinuum\Earth,
    Period\Earth\Minute,
};

$transports = bootstrap();
$guzzle = $transports['default']();
$fulfill = $transports['circuit_breaker'](
    $default,
    new Earth,
    new Minute(10)
);

$fulfill(/* your request */);
```

This code will _close the circuit_ for a given domain for 10 minutes in case a call results in a server error, after this delay the transport will let new request through as if nothing happened.
