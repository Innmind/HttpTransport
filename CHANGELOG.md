# Changelog

## 6.2.0 - 2023-01-01

### Changed

- `Innmind\HttpTransport\ExponentialBackoff` now accepts `callable(Period): void` instead of `Halt` to allow calls to `OperatingSystem\CurrentProcess::halt()`

## 6.1.0 - 2022-12-18

### Added

- Support for `innmind/filesystem:~6.0`
