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

Send request via guzzle:

```php
use Innmind\Compose\{
    ContainerBuilder\ContainerBuilder,
    Loader\Yaml
};
use Innmind\Url\Path;
use Innmind\Http\Message\Request\Request;
use Innmind\Immutable\Map;

$container = (new ContainerBuilder(new Yaml))(
    new Path('container.yml'),
    (new Map('string', 'mixed'))->put('client', new Client)
);
$transport = $container->get('guzzle');

$response = $transport->fulfill(
    new Request(/* initialize your request */)
);
```

## Log the request

You can easily log all your request like so:

```php
use Psr\Log\LoggerInterface;

$container = (new ContainerBuilder(new Yaml))(
    new Path('container.yml'),
    (new Map('string', 'mixed'))
        ->put('client', new Client)
        ->put('logger', /* an instance of LoggerInterface */)
        ->put('log_level', 'info') // default to debug
);
$transport = $container->get('logger');

$transport->fulfill(/* your request */):
```

Here a message is logged before the request is sent and another one once its sent.
