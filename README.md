# HttpTransport

[![Build Status](https://github.com/innmind/httptransport/workflows/CI/badge.svg?branch=master)](https://github.com/innmind/httptransport/actions?query=workflow%3ACI)
[![codecov](https://codecov.io/gh/innmind/httptransport/branch/develop/graph/badge.svg)](https://codecov.io/gh/innmind/httptransport)
[![Type Coverage](https://shepherd.dev/github/innmind/httptransport/coverage.svg)](https://shepherd.dev/github/innmind/httptransport)

This library allows you to send [http](https://packagist.org/packages/innmind/http) request.

> [!IMPORTANT]
> to use this library correctly you must use [`vimeo/psalm`](https://packagist.org/packages/vimeo/psalm).

## Installation

```sh
composer require innmind/http-transport
```

## Usage

Send a request:

```php
use Innmind\HttpTransport\Curl;
use Innmind\TimeContinuum\Clock;
use Innmind\Http\Request;

$fulfill = Curl::of(Clock::live());

$either = $fulfill(
    Request::of(/* initialize your request */),
);
```

`2xx` responses will be on the right side of `$either`, all errors and other kinds of responses will be on the left side.

> [!IMPORTANT]
> you must call `match` to the returned `Either` otherwise the request will not be sent, but you can still call other methods on the `Either` before calling `match`.

## Concurrency

By default there is no limit of concurrency for the `Curl` transport. But if you call many requests before unwrapping the results you may want to configure the max concurrency like below.

```php
use Innmind\HttpTransport\Curl;
use Innmind\Http\{
    Request,
    Response,
    Method,
    ProtocolVersion,
};
use Innmind\Url\Url;
use Innmind\Immutable\Sequence;

$fulfill = Curl::of(Clock::live())->maxConcurrency(5);
$responses = Sequence::of(
    'https://github.com/user/repo-a',
    'https://github.com/user/repo-b',
    'https://github.com/user/repo-c',
    // etc...
)
    ->map(static fn($url) => Request::of(
        Url::of($url),
        Method::get,
        ProtocolVersion::v20,
    ))
    ->map($fulfill)
    ->flatMap(static fn($either) => $either->match(
        static fn($success) => Sequence::of($success->response()),
        static fn() => Sequence::of(), // discard errors
    ))
    ->toList();
$responses; // list<Response>
```

Let's say you have `100` urls to fetch, there will never be more than `5` requests being done in parallel.

## Log the request

You can easily log all your requests like so:

```php
use Innmind\HttpTransport\Logger
use Psr\Log\LoggerInterface;

$fulfill = Logger::psr(/* an instance of Transport */, /* an instance of LoggerInterface */)

$fulfill(/* your request */);
```

Here a message is logged before the request is sent and another one once it's sent.

## Exponential Backoff

Sometimes when calling an external API it may not be available due to heavy load, in such case you could retry the http call after a certain amount of time leaving time for the API to recover. You can apply this pattern like so:

```php
use Innmind\HttpTransport\ExponentialBackoff;
use Innmind\TimeWarp\Halt\Usleep;

$fulfill = ExponentialBackoff::of(
    /* an instance of Transport */,
    Usleep::new(),
);

$fulfill(/* your request */);
```

By default it will retry 5 times the request if the server is unavailable, following the given periods (in milliseconds) between each call: `100`, `271`, `738`, `2008` and `5459`.

## Circuit breaker

When a call to a certain domain fails you may want to all further calls to that domain to fail immediately as you know it means the host is down. Such pattern is called a circuit breaker.

```php
use Innmind\HttpTransport\CircuitBreaker;
use Innmind\TimeContinuum\{
    Clock,
    Period,
};

$fulfill = CircuitBreaker::of(
    /* an instance of CircuitBreaker */,
    Clock::live(),
    Period::minute(10),
);

$fulfill(/* your request */);
```

This code will _open the circuit_ for a given domain for 10 minutes in case a call results in a server error, after this delay the transport will let new request through as if nothing happened.

## Follow redirections

By default the transports do not follow redirections to give you full control on what to do. But you can wrap your transport with `FollowRedirections` like this:

```php
use Innmind\HttpTransport\FollowRedirections;

$fulfill = FollowRedirections::of(/* an instance of Transport */);

$fulfill(/* your request */);
```

To avoid infinite loops it will follow up to 5 consecutive redirections.

> [!IMPORTANT]
> as defined in the [rfc](https://datatracker.ietf.org/doc/html/rfc2616/#section-10.3.2), requests with methods other than `GET` and `HEAD` that results in redirection with the codes `301`, `302`, `307` and `308` will **NOT** be redirected. It will be up to you to implement the redirection as you need to make sure such redirection is safe.
