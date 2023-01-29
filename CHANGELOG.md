# Changelog

## [Unreleased]

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
