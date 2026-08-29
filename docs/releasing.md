# Releasing Baton and the Doria Toolchain

Before the mandatory native cutover, the `dorialang/baton-php` repository
assembles and publishes Doria toolchain prereleases. The compiler and
language-server repositories publish component artifacts; the bootstrap
combines exact compatible components with its private runtime and verifies the
result as users receive it. Decision 0124 transfers production release assembly
to the clean Doria-native `dorialang/baton` repository during the Pre-Stage-45
transition. This repository may not publish the unsuffixed `2026.03.1`
toolchain.

## Version format

Public toolchains use zero-padded CalVer:

```text
yyyy.mm.n-canary
yyyy.mm.n-rc
yyyy.mm.n
```

The year and month match the release date, and `n` is the sequence within that month. Project versions in `Baton.toml` remain SemVer.

Every public component reports the same toolchain CalVer. Repository commit histories and internal package versions remain independent.

## Bootstrap immutable inputs

A release assembly identifies:

- the toolchain CalVer and release channel;
- target platform and architecture;
- exact compiler and language-server component URLs;
- published SHA-256 checksums;
- component manifest schema and identity;
- the Baton source revision;
- the private PHP runtime revision and checksum;
- pinned Composer dependencies.

Release automation must never consume an unpinned `latest` artifact or a repository branch head.

## Staged component protocol

For a non-breaking compiler contract change:

1. Add the compiler capability while preserving the existing interface.
2. Publish matching canary components from `dorialang/doria`.
3. Update Baton against those exact components.
4. Run Baton integration and distribution tests.
5. Publish the assembled toolchain.

For a breaking machine-readable contract:

1. Introduce a new schema or command form beside the old one.
2. Publish a compiler that supports both.
3. Update Baton to the new contract.
4. Publish at least one compatible assembled toolchain.
5. Remove the old contract only after its documented compatibility window.

Cross-repository coordination relies on versioned artifacts and schemas, not matching branch names or sibling checkouts.

## Artifact matrix

The release produces:

```text
doria-toolchain-<version>-windows-x86_64.zip
doria-toolchain-<version>-linux-x86_64.tar.gz
doria-toolchain-<version>-linux-aarch64.tar.gz
doria-toolchain-<version>-macos-x86_64.tar.gz
doria-toolchain-<version>-macos-aarch64.tar.gz
```

Before cutover, each prerelease archive includes launchers, Baton, `doriac`,
`doria-lsp`, the private PHP runtime, templates, `toolchain.json`, the project
licence, and third-party notices. After cutover, production archives contain the
native `bin/baton`, `doriac`, `doria-lsp`, templates, metadata, licences, and no
Baton PHAR, Composer payload, PHP launcher, or private PHP runtime.

## Bootstrap prerelease assembly checklist

1. Download exact component archives.
2. Verify published checksums and component manifests.
3. Confirm toolchain CalVer, platform, and architecture.
4. Build the Baton PHAR without development dependencies.
5. Confirm the PHAR inventory contains the locked TOML parser and SemVer
   implementation, then run schema-1 and schema-2 project smokes through the
   PHAR without a source-tree autoloader. Include canonical source/url parsing,
   workspace and lock validation, development dependencies, graph commands,
   project JSON, test metadata, processor protocol validation, generated-source
   publication, and exact offline processor-cache behavior.
6. Build the matching isolated PHP runtime from the pinned specification.
7. Verify the runtime under `-n` and add the platform launcher.
8. Generate `toolchain.json` with relative component paths.
9. Calculate and record final component hashes.
10. Assemble the platform archive with normalized metadata.
11. Extract it into a clean environment.
12. Run user commands through the installed launcher.
13. Verify relocation, offline use, spaces, Unicode, and a read-only toolchain root.
14. Publish the archive, checksum, provenance, inventories, and test report.

Git is an external executable only for Git dependency acquisition. A release
must prove path-only projects work without Git and that Git dependencies use
noninteractive exact commits in the user-scoped cache rather than a project
`vendor/` directory.

The Baton and runtime artifacts are built with:

```bash
composer build:phar
php packaging/php-runtime/build.php
```

Release CI may add `--prepare` on a disposable runner. See [Private Baton runtime](runtime.md) for the immutable inputs, extension set, generated manifests, and security-update procedure.

## Doria-native production cutover

The Pre-Stage-45 transition must complete all of the following before an
unsuffixed release:

1. Build the production Baton executable from the clean `dorialang/baton`
   repository with the selected `doriac` component.
2. Run the shared PHP/Doria behavior suite for commands, manifests, lockfiles,
   resolution, workspaces, diagnostics, build plans, receipts, caches, tests,
   templates, paths, and offline operation.
3. Have native Baton build, check, test, and package its own repository.
4. Transfer templates, release workflows, archive ownership, provenance, and
   clean-machine matrices to `dorialang/baton`.
5. Assemble the native-only layout documented in
   [Architecture](architecture.md), verify relocation and offline use, and
   assert that no PHAR, Composer dependency, PHP launcher, or private runtime is
   present.
6. Freeze `dorialang/baton-php` as historical bootstrap and compatibility
   reference.

The unsuffixed `2026.03.1` release is blocked until every item passes on every
supported platform. A canary carrying the bootstrap is not a cutover pass.

## Clean-machine acceptance

The extracted distribution must run:

```bash
baton --version
baton doctor
baton new hello
cd hello
baton check
baton run
baton build --release
```

The environment must not provide Rust, Cargo, PHP, Composer, a repository checkout, or a previous Doria installation. Tests also place an incompatible `doriac` earlier on `PATH` to prove the bundled compiler wins.

Exercise compiler and program failure, forwarded arguments, interactive standard input, Ctrl+C, corrupt metadata, missing components, and modified component hashes.

## Published materials

Publish with every archive:

- SHA-256 checksum;
- CI provenance or attestation;
- compiler and language-server component provenance;
- Baton source revision;
- private runtime identity for bootstrap prereleases, or an explicit native-only
  inventory after cutover;
- dependency and licence inventories;
- archive-size and component-version reports;
- clean-machine test results.

Archive timestamps, file ordering, permissions, JSON ordering, line endings, and compression settings should be normalized. Reproducibility is measured and reported rather than assumed.
