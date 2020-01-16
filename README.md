# HttpTransport

| `develop` |
|-----------|
| [![codecov](https://codecov.io/gh/Innmind/HttpTransport/branch/develop/graph/badge.svg)](https://codecov.io/gh/Innmind/HttpTransport) |
| [![Build Status](https://github.com/Innmind/HttpTransport/workflows/CI/badge.svg)](https://github.com/Innmind/HttpTransport/actions?query=workflow%3ACI) |

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
    new Request(/* initialize your request */),
);
```

## Log the request

You can easily log all your requests like so:

```php
use Psr\Log\LoggerInterface;

$transports = bootstrap();
$guzzle = $transports['default']();
$log = $transports['logger'](/* an instance of LoggerInterface */);
$fulfill = $log(
    $default,
);

$fulfill(/* your request */);
```

Here a message is logged before the request is sent and another one once it's sent.

## Exponential Backoff

Sometimes when calling an external API it may not be available due to heavy load, in such case you could retry the http call after a certain amount of time leaving time for the API to recover. You can apply this pattern like so:

```php
use Innmind\TimeContinuum\Earth\Clock;
use Innmind\TimeWarp\Halt\Usleep;

$transports = bootstrap();
$guzzle = $transports['default']();
$fulfill = $transports['exponential_backoff'](
    $default,
    new Usleep,
    new Clock,
);

$fulfill(/* your request */);
```

By default it will retry 5 times the request if the server is unavailable, following the given periods (in milliseconds) between each call: `100`, `271`, `738`, `2008` and `5459`.

## Circuit breaker

When a call to a certain domain fails you may want to all further calls to that domain to fail immediately as you know it means the host is down. Such pattern is called a circuit breaker.

```php
use Innmind\TimeContinuum\Earth\{
    Clock,
    Period\Minute,
};

$transports = bootstrap();
$guzzle = $transports['default']();
$fulfill = $transports['circuit_breaker'](
    $default,
    new Earth,
    new Minute(10),
);

$fulfill(/* your request */);
```

This code will _close the circuit_ for a given domain for 10 minutes in case a call results in a server error, after this delay the transport will let new request through as if nothing happened.
