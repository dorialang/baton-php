# Toolchain Discovery and Validation

Baton normally runs the compiler bundled in the same Doria toolchain distribution. Selection is deterministic so a machine-global compiler cannot silently change a project's behavior.

## Discovery order

Baton evaluates compiler sources in this order:

1. An explicit `--compiler <path>` development override.
2. The `doriac` component recorded in the installed `toolchain.json`.
3. A `doriac` executable beside Baton.
4. `BATON_DORIAC`, only when `--development` is present.
5. `PATH`, only when `--development` is present.

Public mode never consults `BATON_DORIAC` or `PATH`.

Examples for source development:

```bash
php bin/baton doctor --compiler /absolute/path/to/doriac
BATON_DORIAC=/absolute/path/to/doriac php bin/baton check --development
```

An explicit path can live anywhere. No sibling repository layout is assumed.

## Compiler identity

Every selected compiler must successfully answer:

```bash
doriac --version --json
```

Schema 1 contains:

```json
{
  "schema": 1,
  "component": "doriac",
  "toolchainVersion": "2026.03.1-canary",
  "target": "linux-x86_64",
  "commit": "<source-commit>"
}
```

Baton rejects:

- invalid JSON or an unsupported schema;
- a component that does not identify itself as `doriac`;
- a toolchain CalVer mismatch;
- a platform or architecture mismatch;
- missing identity fields.

## Installed manifest

An installed toolchain records its exact components in `toolchain.json`:

```json
{
  "schema": 1,
  "toolchainVersion": "2026.03.1-canary",
  "channel": "canary",
  "platform": "linux",
  "architecture": "x86_64",
  "components": {
    "doriac": {
      "version": "2026.03.1-canary",
      "path": "bin/doriac",
      "sha256": "<64 lowercase hexadecimal characters>"
    },
    "doria-lsp": {
      "version": "2026.03.1-canary",
      "path": "bin/doria-lsp",
      "sha256": "<64 lowercase hexadecimal characters>"
    }
  }
}
```

Component paths must be relative and remain inside the toolchain root. Baton validates the manifest version, toolchain version, host platform, architecture, compiler version, and compiler SHA-256 digest before execution.
The language-server component must carry the same toolchain version, remain
inside the toolchain root, and match its recorded digest.

`toolchain.json` is internal distribution metadata. It is not a Doria project manifest, package lockfile, or dependency resolver input.

## Diagnostics

`baton doctor` reports Baton and toolchain versions, release channel, executable
and runtime paths, host identity, the selected compiler, `doria-lsp`, manifest
and hash status, and writable build and cache locations using `PASS`, `WARNING`,
and `FAIL`.

The command can run outside a Doria project and must not print secrets or unrelated environment values.
Outside a project, the build-location check is a warning rather than a failure.
When source development uses `--compiler` instead of an installed manifest,
component hashes, `doria-lsp`, and the private runtime cannot be verified and
are reported as warnings.

The cache check follows the host convention:

- `%LOCALAPPDATA%\Doria\cache` on Windows;
- `~/Library/Caches/Doria` on macOS;
- `$XDG_CACHE_HOME/doria`, or `~/.cache/doria`, on Linux.

Typical failures use Baton diagnostics:

| Code | Meaning |
| --- | --- |
| `B0201` | The selected compiler identity is incompatible |
| `B0202` | No acceptable compiler was found |
| `B0203` | The compiler process could not be started |
| `B0204` | The host or installed toolchain manifest is invalid |

The correction is to install a matching Doria toolchain or provide an exact compatible compiler artifact during development—not to rename an unrelated executable.
