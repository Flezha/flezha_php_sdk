# flezha/sdk

The Flezha PHP SDK, a poll host over `flz_core`'s C ABI through `ext-ffi`. The
SDK contains no kernel code: transport, framing, ordering, per-cell
exclusivity, durability, retries, timers, credit, routing, steps runs and
reconnect all live in the core. This package brings the authoring surface, the
codec for user types, the concurrency host and idiomatic errors.

**Support tier:**
[tier 2](https://github.com/flezha/flezha/blob/main/docs/public/publishing/maturity.md#sdk-php),
passes the full conformance run in CI exactly as tier 1 does, with every capability
present but `local_send`, which its single-threaded serving defers, and is permanently
supported; it is not yet run by the maintainer in a live deployment, so a defect specific
to it is likely to be found by its users first.

[`examples/echo`](examples/echo/README.md) is the smallest complete runner.

## Install

```bash
composer require flezha/sdk
```

The package is not on Packagist yet. Until it is, install it from a checkout as
[`examples/echo`](examples/echo/README.md#building-from-a-checkout) shows.

The package is also a Composer plugin, which fetches the native library below.
Composer asks once whether to trust it, and a non-interactive install needs it
allowed in the project's `composer.json`:

```json
"config": { "allow-plugins": { "flezha/sdk": true } }
```

## Supported PHP versions

PHP **8.4 or newer**, with `ext-ffi` and `ext-mbstring`. 8.4 is the line the SDK
is built, tested and benchmarked on. The optional `SwooleExecutor` also needs
`ext-swoole` 6, built against a thread-safe PHP with `--enable-swoole-thread`;
it is checked when it is selected, not at install. The default `SyncExecutor`
needs nothing beyond `ext-ffi`.

## The native library

As Composer installs the package, its plugin downloads the one `libflz_core_ffi`
binary the host needs from the GitHub release matching the package's version,
checks it against that release's SHA-256 manifest, and places it in the
package's `lib/<platform>/`. Releases carry it for `linux-x64`, `linux-arm64`,
`macos-x64`, `macos-arm64` and `windows-x64`. A failed download or a checksum
mismatch refuses the install rather than leave a package that cannot load.

`FLEZHA_NATIVE_LIB_DIR` names a directory to load the binary from in preference
to the package's own. When it already holds the binary at install, nothing is
downloaded, which is how an offline install works. An install that skipped the
plugin has no binary, and the first call into the core refuses naming how to
finish: allow the plugin and run `composer reinstall flezha/sdk`, or fetch the
binary by hand as the message says.

## Local mode, testing and the relay API

- **Local mode**: `LocalRelay::start` runs a relay's declared graph in your own
  process, with no controller. See
  [Local mode](https://github.com/flezha/flezha/blob/main/docs/public/topics/core/running_and_testing.md#local-mode).
- **Testing**: `Flezha\Testing\Harness` drives one tasklet and checks its
  replay. See
  [Check a tasklet on its own](https://github.com/flezha/flezha/blob/main/docs/public/topics/core/running_and_testing.md#check-a-tasklet-on-its-own).
- **The relay API**: a relay's entry channels, answering a caller, `call(...)`
  to another relay, and `$runner->send` and `$runner->call` from application
  code. See
  [Sending and calling](https://github.com/flezha/flezha/blob/main/docs/public/topics/core/sending_and_calling.md).

Full documentation:
[the PHP SDK guide](https://github.com/flezha/flezha/blob/main/docs/internal/sdk/php_sdk.md).

Licensed under Apache-2.0.
