# Releasing Baton and the Doria Toolchain

The `dorialang/baton-php` repository assembles and publishes complete Doria toolchain distributions. The compiler and language-server repositories publish component artifacts; Baton combines exact compatible components with its private runtime and verifies the result as users receive it.

## Version format

Public toolchains use zero-padded CalVer:

```text
yyyy.mm.n-canary
yyyy.mm.n-rc
yyyy.mm.n
```

The year and month match the release date, and `n` is the sequence within that month. Project versions in `Baton.toml` remain SemVer.

Every public component reports the same toolchain CalVer. Repository commit histories and internal package versions remain independent.

## Required immutable inputs

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

Each archive includes launchers, Baton, `doriac`, `doria-lsp`, the private PHP runtime, templates, `toolchain.json`, the project licence, and third-party notices.

## Assembly checklist

1. Download exact component archives.
2. Verify published checksums and component manifests.
3. Confirm toolchain CalVer, platform, and architecture.
4. Build the Baton PHAR without development dependencies.
5. Add the matching isolated PHP runtime and launchers.
6. Generate `toolchain.json` with relative component paths.
7. Calculate and record final component hashes.
8. Assemble the platform archive with normalized metadata.
9. Extract it into a clean environment.
10. Run user commands through the installed launcher.
11. Verify relocation, offline use, spaces, Unicode, and a read-only toolchain root.
12. Publish the archive, checksum, provenance, inventories, and test report.

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
- private runtime identity;
- dependency and licence inventories;
- archive-size and component-version reports;
- clean-machine test results.

Archive timestamps, file ordering, permissions, JSON ordering, line endings, and compression settings should be normalized. Reproducibility is measured and reported rather than assumed.
