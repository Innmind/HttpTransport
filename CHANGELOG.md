# Changelog

## [Unreleased]

### Added

- `Innmind\HttpTransport\Header\Timeout` to specify a request timeout

## 7.0.1 - 2023-11-05

### Fixed

- Curl timeout that was not applied correctly

## 7.0.0 - 2023-10-22

### Changed

- Requires `innmind/filesystem:~7.1`
- Requires `innmind/http:~7.0`

## 6.6.0 - 2023-09-17

### Added

- Support for `innmind/immutable:~5.0`

### Removed

- Support for PHP `8.1`

## 6.5.1 - 2023-05-19

### Fixed

- `Response`s' body underlying resources weren't garbage collected at the same time than their object wrapper

## 6.5.0 - 2023-04-05

### Added

- `Innmind\HttpTransport\MalformedResponse::raw(): Innmind\HttpTransport\MalformedResponse\Raw` to access the raw data sent by the server

## 6.4.1 - 2023-02-11

### Fixed

- Out of order unwrapping `Curl` responses when `maxConcurrency` is lower than the number of scheduled requests

## 6.4.0 - 2023-02-05

### Added

- `Innmind\HttpTransport\Curl::maxConcurrency()`
- `Innmind\HttpTransport\Curl::heartbeat()`

### Changed

- `Innmind\HttpTransport\Curl::__invoke()` now returns a deferred `Innmind\Immmutable\Either` to allow concurrency, the requests are sent only when unwrapping the returned `Either`

## 6.3.0 - 2023-01-29

### Added

- `Innmind\HttpTransport\Curl::of()` now accepts `Innmind\Stream\Capabilities` as third argument
- Support for `innmind/http:~6.0`

## 6.2.1 - 2023-01-15

### Fixed

- Header names containing a number or a dot now longer result in a `MalformedResponse`

## 6.2.0 - 2023-01-01

### Changed

- `Innmind\HttpTransport\ExponentialBackoff` now accepts `callable(Period): void` instead of `Halt` to allow calls to `OperatingSystem\CurrentProcess::halt()`

## 6.1.0 - 2022-12-18

### Added

- Support for `innmind/filesystem:~6.0`
