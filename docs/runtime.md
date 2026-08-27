# Private Baton Runtime

Public Doria toolchains carry the PHP CLI needed to execute Baton. The launcher resolves that runtime from the installed toolchain, invokes it with configuration loading disabled, and never searches for PHP or Composer on the host.

## Installed layout

The runtime-facing portion of a toolchain is:

```text
bin/
└── <baton|baton.cmd>
libexec/
└── doria/
    ├── baton.phar
    └── php/
        ├── bin/
        │   └── <php|php.exe>
        ├── runtime.json
        └── LICENSES/
```

Each platform includes only its native launcher and runtime executable (`php` or `php.exe`). The POSIX launcher follows symlinks before locating the toolchain root. Both launchers support relocation and paths containing spaces or Unicode.

The effective invocation is:

```text
<toolchain>/libexec/doria/php/bin/php -n <toolchain>/libexec/doria/baton.phar ...
```

There is no fallback to `php` on `PATH`. A missing private runtime or PHAR is an installation error.

## Pinned runtime

The runtime is a statically linked PHP 8.4 CLI built from the immutable inputs in [`packaging/php-runtime/spec.json`](../packaging/php-runtime/spec.json). The supported targets are:

- Linux x86_64
- Linux AArch64
- macOS x86_64
- macOS AArch64
- Windows x86_64

The extension set is intentionally small:

| Component | Reason |
| --- | --- |
| CLI and PHP core | Filesystem access and command execution |
| JSON | Manifests and machine-readable component protocols |
| Hash | Component and archive integrity |
| PHAR | The Baton application archive |
| iconv | Required by Symfony's mbstring compatibility layer |
| zlib | Required by the statically linked PHAR extension |
| pcntl and posix | Signal handling on Linux and macOS |

JSON, hashing, filesystem operations, and process execution are core capabilities
in this build. Manifest schema 2 uses the packaged pure-PHP
`php-collective/toml` parser, and package versions use packaged
`composer/semver`; neither requires another PHP extension.

The build verifies every downloaded source and builder asset by SHA-256, probes the finished executable under `-n`, and records the binary hash, sources, extensions, capabilities, and licence paths in `runtime.json`.

## Isolation contract

The private runtime:

- is always started with `-n`;
- has its compiled ini and scan paths pointed at a nonexistent private location;
- contains no shared extensions;
- does not read a user or system `php.ini`;
- does not scan system extension directories;
- does not execute PHP from a Doria project;
- does not require Composer after packaging.

Tests place a hostile PHP executable first on `PATH`, set hostile ini environment variables, relocate the toolchain into a path with spaces and Unicode, and invoke Baton through a symlink. The launcher must still use only the relative private runtime.

## Build the PHAR

Install contributor dependencies, then create the production archive:

```bash
composer install
composer build:phar
```

The build stages the locked production dependency set, removes development packages, generates an authoritative autoloader, and writes:

```text
build/baton.phar
build/baton.phar.sha256
build/baton-dependencies.json
build/LICENSES/composer/
```

The PHAR uses a SHA-256 signature and contains Baton's source, templates, and
production dependencies only, including the TOML and SemVer libraries required
to load schema-2 projects without system Composer. `baton-dependencies.json`
records every included Composer package and its copied licence notices.

## Build a private runtime

Runtime builds are host-native. First inspect the selected plan:

```bash
composer runtime:plan
```

Then build:

```bash
php packaging/php-runtime/build.php
```

Static PHP compilation requires the host C/C++ toolchain and the utilities checked by StaticPHP. On a disposable CI runner, `--prepare` allows the pinned builder to install missing build prerequisites:

```bash
php packaging/php-runtime/build.php --prepare
```

Use `--output` and `--work` to move generated files outside the default `build/` tree. The script never cross-compiles: the requested `--target` must match the host.

After a successful build, the script removes the large extracted-source, compiler-output, and toolchain scratch directories. It retains the verified downloads so a repeat build does not fetch them again. Pass `--keep-work` only when those transient files are needed to diagnose a failed or non-reproducible build.

## Updates and security

Runtime updates are explicit:

1. Select a supported PHP patch release and pinned StaticPHP release.
2. Update every source URL and SHA-256 in `spec.json`.
3. Review PHP, StaticPHP, zlib, and libiconv security advisories and release notes.
4. Build all five targets from clean work directories.
5. Run the runtime probe, launcher tests, PHAR smoke tests, and complete extracted-toolchain acceptance suite.
6. Review the generated runtime and Composer dependency/licence inventories.
7. Publish the new runtime only inside a matching CalVer toolchain archive.

Do not update an unpinned URL, branch head, `latest` asset, or prebuilt dependency bundle. A security update to any linked runtime component requires rebuilding and republishing every affected toolchain target.
