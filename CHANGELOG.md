# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.1.0] - 2026-09-06

### Added

- Test coverage for edge cases in storage and both limiter implementations:
  - `FileStorage::get()` returns `null` and deletes the file when its
    on-disk content is corrupted (not valid JSON), instead of throwing or
    returning garbage.
  - `FileStorage` never collides two different keys onto the same file and
    keeps their values fully independent.
  - `TokenBucketLimiter::attempt()` with `cost=0` always succeeds and never
    consumes any tokens.
  - A `TokenBucketLimiter` constructed with `capacity=0` rejects every
    attempt and reports `remaining()` as 0.
  - A `SlidingWindowLimiter` constructed with `maxRequests=0` rejects the
    very first attempt and reports `remaining()` as 0 (not negative).

No behavioral changes were needed — all new edge-case tests passed against
the existing implementation.
