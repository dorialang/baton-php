# Early Baton and Self-Contained Doria Toolchain Development Plan

## 1. Objective

Develop an early PHP implementation of **Baton** that provides a coherent Doria project workflow without requiring users to install:

* Rust
* Cargo
* PHP
* Composer
* The Doria source repository

Baton will live in its **own repository** and will assemble the public Doria toolchain from independently produced compiler artifacts.

The public experience becomes:

```bash
baton new hello
cd hello

baton check
baton run
baton build
baton build --release
```

Rust remains a dependency only for contributors developing `doriac`.

PHP and Composer remain dependencies only for contributors developing the bootstrap implementation of Baton.

Neither toolchain is exposed as a Doria user dependency.

---

## 2. Repository Ownership

The project should use two independently maintained repositories.

### Doria Compiler Repository

```text
dorialang/doria
```

Owns:

```text
doriac
doria-lsp
Doria language semantics
compiler diagnostics
compiler services
native code generation
compiler-facing machine output
prebuilt compiler artifacts
compiler component checksums
```

It does not contain:

```text
Baton source
Composer files
Baton templates
Baton PHP dependencies
private PHP runtime packaging
Baton release workflows
Baton project fixtures
Baton package-resolution logic
```

---

### Baton Repository (Bootstrap Implementation)

```text
dorialang/baton-php
```

Owns:

```text
Baton source
Baton.toml handling
project discovery
project templates
build orchestration
toolchain discovery
private PHP runtime
toolchain bundle assembly
installation archives
clean-machine installation tests
Baton documentation
future dependency resolution
future Baton.lock implementation
future workspaces and registry support
```

This repository is explicitly the **bootstrap implementation** of Baton.

A later long-term repository will exist:

```text
dorialang/baton
```

That future repository will be a **separate project with a clean history**, designed once Baton is rewritten in Doria itself. It will not inherit PHP-era implementation constraints or mixed-language history.

Decision 0124 makes that rewrite and cutover binding: Stage 33 first completes
and freezes the exercised product contract in this disposable repository; a
mandatory Pre-Stage-45 transition then parity-ports it into `dorialang/baton`,
transfers production release assembly, removes the PHP payload from public
archives, and blocks the unsuffixed `2026.03.1` release until completion.

This separation is deliberate.

Baton maintainers should not need write access to the compiler repository merely to update project templates, CLI behaviour, packaging, or dependency-management features.

Compiler maintainers should not inherit Composer updates, PHP dependency reviews, Baton fixtures, packaging failures, or unrelated release noise.

---

## 3. Architectural Status

This work should be recorded as:

> **Baton Bootstrap and Toolchain Distribution Track**

The early bootstrap milestones are not Stage 33 and do not satisfy Stage 33's
acceptance criteria. The same disposable repository later owns the three Stage
33 UX-contract slices under Decision 0124; completing them still does not
satisfy the Doria-native transition.

The complete Baton product contract remains scheduled after the compiler has
the required multi-file, namespace, attribute, and testing foundations. Its
permanent Doria implementation remains separately scheduled after the
filesystem, process, native-library, and network foundations.

The early bootstrap may begin sooner because it:

* Introduces no Doria language semantics.
* Does not resolve Doria namespaces.
* Does not parse Doria source.
* Does not implement test discovery.
* Does not implement dependency resolution.
* Only orchestrates compiler capabilities that already exist.
* Is independently maintained outside the compiler repository.

The permanent ownership boundary is:

```text
doriac
  Compilation, semantics, checking, lowering, code generation,
  diagnostics, and compiler-facing inspection.

Baton
  Projects, manifests, profiles, execution, tests, dependencies,
  workspaces, packaging, installation, and developer workflow.
```

---

## 4. Accepted End-to-End Plan Amendment

Decision 0124 and the end-to-end plan now record:

> A PHP bootstrap implementation of Baton may be developed before Stage 33 as a separate non-semantic tooling and distribution project. Baton is maintained in its own repository (`dorialang/baton-php`). It may wrap compiler capabilities that have already landed and establish installation, command dispatch, project discovery, minimal manifest handling, and prerelease toolchain packaging. It must not implement or silently settle Stage 31–33 semantics early. The pre-Stage-33 bootstrap milestones do not satisfy Stage 33. Once Stage 33 begins, the same disposable repository implements and exercises all three accepted product-contract slices without becoming the permanent implementation. A mandatory Pre-Stage-45 transition creates the clean Doria-native `dorialang/baton` repository, parity-ports the frozen contract, transfers production release assembly, removes the Baton PHP payload, and blocks the unsuffixed `2026.03.1` release until cutover. Bootstrap prereleases bundle compatible prebuilt `doriac` and `doria-lsp` binaries together with a private PHP runtime, making Rust and PHP contributor dependencies rather than Doria user dependencies.

Decision 0124 covers:

* Baton’s separate repository ownership.
* Bootstrap implementation language.
* Cross-repository compiler contract.
* Toolchain bundle structure.
* Compiler artifact discovery and verification.
* Minimal pre-Stage-33 manifest subset.
* Private PHP runtime policy.
* Release coordination.
* Migration to a Doria-native Baton.
* Separation from the later resolver, lockfile, and test decisions.

---

# Part I — Cross-Repository Contract

## 5. Milestone B0: Freeze the Bootstrap Boundary

### Supported Immediately

```text
baton --version
baton doctor
baton new <name>
baton check
baton build
baton build --release
baton run
```

### Explicitly Unavailable Until Later Stages

```text
baton test
path dependencies
registry dependencies
Baton.lock
workspaces
baton add
baton remove
baton publish
baton bench
build scripts
plugins
native dependency resolution
baton build --php-lib
self-update
```

Recognized future commands may return stage-aware diagnostics:

```text
Error[B0102]: `baton test` Is Not Available in This Toolchain

The Doria test convention requires #[Test] attribute support and
lands with the Stage 33 Baton product contract.
```

Baton must not approximate future behaviour through temporary conventions that could later conflict with the accepted language or package design.

### Acceptance Criteria

* The bootstrap boundary is documented in both repositories.
* The end-to-end plan distinguishes the early bootstrap, Stage 33 PHP
  product-contract work, and the Pre-Stage-45 Doria-native transition.
* Every command maps to an already available compiler capability.
* No Stage 31–33 feature is represented as complete.
* Baton source and dependencies remain outside the compiler repository.

---

## 6. Milestone B1: Define the Compiler Distribution Contract

The compiler repository must expose a small, stable interface that Baton can consume without depending on compiler source code.

### Required Commands

```bash
doriac --version
doriac --version --json
doriac check <file>
doriac compile <file> --target <target> --out <path>
```

Additional machine-readable compiler commands may be added later, but Baton must not depend on undocumented output.

### Version Output

Human-readable example:

```text
doriac 2026.07.1-canary
```

Machine-readable example:

```json
{
  "schema": 1,
  "component": "doriac",
  "toolchainVersion": "2026.07.1-canary",
  "target": "linux-x86_64",
  "commit": "..."
}
```

### Diagnostic Ownership

Human compiler diagnostics remain owned by `doriac`.

Baton must forward them without:

* Changing their meaning.
* Reformatting their headings.
* Converting Title Case into sentence case.
* Assigning replacement compiler error codes.
* Attempting to recreate source spans.

Baton-specific problems use Baton diagnostic codes and Doria’s Title Case formatting convention.

### Compatibility Rule

Baton integrates with released compiler artifacts, not compiler internals.

It must not import:

* Rust crates from the compiler workspace.
* Compiler source files.
* Internal Rust data structures.
* Unstable debug output.
* Repository-relative build paths.

### Acceptance Criteria

* Baton can operate using only the documented compiler binaries.
* Baton does not require a Doria repository checkout.
* Machine-readable version output has an explicit schema.
* Compiler diagnostics remain compiler-owned.
* Breaking compiler-interface changes require an explicit schema or compatibility change.

---

## 7. Milestone B2: Publish Prebuilt Compiler Components

The Doria compiler repository publishes prebuilt components independently.

### Tier-1 Build Matrix

```text
Windows x86_64
Linux x86_64
Linux aarch64
macOS x86_64
macOS aarch64
```

### Components

```text
doriac
doria-lsp
```

### Compiler Release Assets

Example:

```text
doria-components-2026.07.1-canary-windows-x86_64.zip
doria-components-2026.07.1-canary-linux-x86_64.tar.gz
doria-components-2026.07.1-canary-linux-aarch64.tar.gz
doria-components-2026.07.1-canary-macos-x86_64.tar.gz
doria-components-2026.07.1-canary-macos-aarch64.tar.gz
```

Each component release contains:

```text
bin/doriac
bin/doria-lsp
component.json
SHA256SUMS
LICENSE
required third-party notices
```

### Component Manifest

```json
{
  "schema": 1,
  "toolchainVersion": "2026.07.1-canary",
  "platform": "linux",
  "architecture": "x86_64",
  "components": {
    "doriac": {
      "version": "2026.07.1-canary",
      "path": "bin/doriac",
      "sha256": "..."
    },
    "doria-lsp": {
      "version": "2026.07.1-canary",
      "path": "bin/doria-lsp",
      "sha256": "..."
    }
  }
}
```

### Compiler Repository Responsibilities

The compiler repository is responsible only for:

* Building the compiler components.
* Testing those components.
* Publishing their archives.
* Publishing checksums.
* Publishing component metadata.
* Maintaining the documented compiler contract.

It is not responsible for assembling or testing the complete Baton distribution.

### Acceptance Criteria

On each supported platform:

```bash
doriac --version --json
doriac check <fixture>
doriac compile <fixture> --target native --out <output>
```

works without:

```text
rustc
cargo
rustup
```

---

# Part II — The Baton Repository

## 8. Milestone B3: Create the Independent Baton Repository

### Repository Layout

```text
baton-php/
├── composer.json
├── composer.lock
├── bin/
│   └── baton
├── src/
├── templates/
├── tests/
│   ├── Unit/
│   ├── Integration/
│   ├── Distribution/
│   └── Fixtures/
├── packaging/
│   ├── php-runtime/
│   ├── launchers/
│   └── toolchain/
├── docs/
├── README.md
├── SECURITY.md
├── LICENSE
└── .github/
    └── workflows/
```

### Internal Modules

```text
Application
CommandRegistry
ProjectLocator
ManifestLoader
ManifestValidator
ToolchainLocator
ToolchainManifest
CompilerAdapter
ProcessRunner
BuildPlanner
BuildLayout
TemplateRenderer
Platform
Diagnostics
ComponentDownloader
ComponentVerifier
DistributionAssembler
```

### Independence Requirements

The Baton repository must be buildable and testable without cloning the Doria compiler repository.

Compiler integration tests obtain `doriac` through one of these mechanisms:

1. An explicitly supplied compiler artifact.
2. A pinned released compiler component.
3. A CI artifact URL supplied by a coordinated canary workflow.
4. A local developer override.

The default test workflow should use a pinned released component rather than the latest compiler branch.

### Acceptance Criteria

* No Baton source exists in the compiler repository.
* No Composer files exist in the compiler repository for Baton.
* Baton CI runs independently.
* Baton maintainers can update Baton without touching compiler CI.
* Compiler maintainers are not assigned Baton dependency or packaging work.

---

# Part III — Compiler Integration

## 9. Milestone B4: Implement Safe Compiler Invocation

All compiler invocations must go through `CompilerAdapter`.

### Process Safety

Use argument arrays rather than shell interpolation.

Do not do this:

```php
exec("doriac compile {$file} --out {$output}");
```

The implementation must correctly handle:

* Spaces in paths.
* Unicode paths.
* Windows path separators.
* Quotes in arguments.
* Standard input.
* Standard output.
* Standard error.
* Exit codes.
* Ctrl+C and termination.
* Arguments following `--`.

### Acceptance Criteria

* Unit tests use a fake compiler adapter.
* Integration tests use a real downloaded compiler component.
* Compiler exit codes are preserved.
* Compiler diagnostics are forwarded unchanged.
* No Baton class parses Doria source.

---

## 10. Milestone B5: Implement Toolchain Discovery

Baton should normally use the compiler bundled with the same distribution.

### Discovery Order

```text
1. Explicit `--compiler <path>` development override.
2. Compiler recorded in the installed toolchain manifest.
3. Compiler beside Baton in the installed toolchain.
4. `BATON_DORIAC` development override.
5. `PATH` lookup as the final bootstrap fallback.
```

A random `doriac` on `PATH` must not silently override the bundled compiler.
The PHP bootstrap enables the two development fallbacks automatically after
checking every installed source. The Doria-native Baton owns final public
distribution policy after the mandatory Pre-Stage-45 cutover.

### Version Validation

Baton must:

1. Read `toolchain.json`.
2. Locate the bundled compiler.
3. Query `doriac --version --json`.
4. Validate the version schema.
5. Verify the expected CalVer.
6. Verify platform and architecture.
7. Optionally verify the component hash.
8. Reject incompatible components.

Example:

```text
Error[B0201]: Incompatible Doria Compiler

Baton:  2026.07.3-canary
doriac: 2026.07.1-canary

This Baton distribution expects its bundled compiler.
```

### Acceptance Criteria

* The bundled compiler wins over `PATH`.
* Missing compiler metadata is diagnosed.
* Version mismatches are diagnosed.
* Invalid version JSON is diagnosed.
* `baton doctor` reports the actual selected component.

---

# Part IV — The Project Manifest

## 11. Milestone B6: Establish the Minimal Manifest Subset

The bootstrap uses `Baton.toml`, but only a versioned subset.

### Initial Manifest

```toml
manifest-version = 1

[package]
name = "hello-doria"
version = "0.1.0"
kind = "binary"
entry = "src/main.doria"
```

### Versioning Distinction

```text
Package version:   SemVer
Toolchain version: CalVer
```

The package version must not be compared with the Doria toolchain version.

### Edition Handling

Reserve an optional edition field, but do not generate one until the accepted language plan defines its values and behaviour.

### No Lockfile Yet

The bootstrap must not generate `Baton.lock`.

The internal distribution file `toolchain.json` is not a project lockfile and must never be presented as one.

### Validation

Validate:

* Manifest version.
* Required fields.
* Package-name syntax.
* SemVer package version.
* Supported package kind.
* Entry path.
* Project-root containment.
* Invalid TOML with line and column information.

### Acceptance Criteria

* `baton new` generates a valid manifest.
* Package versions use SemVer.
* Toolchain components use CalVer.
* No resolver or lockfile contract is introduced.

---

# Part V — Baton Commands

## 12. Milestone B7: Implement `baton new`

Generate:

```text
hello/
├── Baton.toml
├── src/
│   └── main.doria
└── .gitignore
```

### Template Rules

* Templates contain accepted Doria syntax only.
* Templates do not use `public`, `protected`, or `private`.
* Templates follow the Doria API naming style.
* Templates are tested against the compiler version with which they ship.
* Baton owns the templates; the compiler repository does not.

### Acceptance Criteria

```bash
baton new hello
cd hello
baton check
baton run
```

succeeds from a clean installation.

---

## 13. Milestone B8: Implement `baton check`

For the initial single-entry project:

```text
Baton.toml entry
    ↓
CompilerAdapter
    ↓
doriac check src/main.doria
```

Baton must:

* Walk upward to locate `Baton.toml`.
* Validate the manifest.
* Invoke the bundled compiler.
* Forward compiler diagnostics unchanged.
* Return the compiler’s exit code.

### Acceptance Criteria

* Valid projects exit successfully.
* Invalid programs preserve the compiler exit code.
* Running inside a nested project directory finds the project root.
* Running outside a project produces a Baton diagnostic.
* Paths containing spaces work across supported systems.

---

## 14. Milestone B9: Implement `baton build`

### Commands

```bash
baton build
baton build --release
```

### Build Layout

```text
build/
└── <host-target>/
    ├── development/
    │   └── <package-name>
    └── release/
        └── <package-name>
```

Do not use Cargo’s `target/` terminology for Doria project output.

### Build Metadata

```json
{
  "package": "hello-doria",
  "packageVersion": "0.1.0",
  "toolchainVersion": "2026.07.1-canary",
  "target": "linux-x86_64",
  "profile": "development"
}
```

This is build metadata, not `Baton.lock`.

### Acceptance Criteria

* Profiles do not overwrite one another.
* Build paths are deterministic.
* Baton does not inspect Doria semantics.
* The compiler remains responsible for generated output.

---

## 15. Milestone B10: Implement `baton run`

### Commands

```bash
baton run
baton run -- arg1 arg2
baton run --release -- arg1 arg2
```

Baton must:

1. Build the selected profile.
2. Refuse to execute when compilation fails.
3. Execute the newly produced binary.
4. Forward standard streams.
5. Forward the program exit code.
6. Preserve arguments after `--`.

### Acceptance Criteria

* Failed compilation never executes an older artifact.
* Interactive programs work.
* Program exit codes pass through.
* Unicode arguments and paths work.
* Signal handling works across supported platforms.

---

## 16. Milestone B11: Implement `baton doctor`

Report:

```text
Baton version
Toolchain version
Release channel
Baton executable path
Private PHP runtime path
doriac path and version
doriac native compile/runtime/linker probe
doria-lsp path and version
Host platform
Host architecture
Toolchain manifest status
Component hash status
Writable build and cache locations
```

Use clear statuses:

```text
PASS
WARNING
FAIL
```

The compiler probe uses a fixed minimal source and structured JSON diagnostics.
An identity-compatible compiler that cannot produce a native executable is a
`FAIL`, not a healthy toolchain.

### Acceptance Criteria

* Missing compiler components are detected.
* Version mismatches are detected.
* Corrupt component metadata is detected.
* The command works outside a Doria project.
* Sensitive environment values are not displayed.

---

# Part VI — Runtime Packaging

## 17. Milestone B12: Package the Baton Runtime

The public Baton command must not depend on a system PHP installation.

### Runtime Requirements
Package a minimal private PHP CLI runtime containing only what Baton requires.

Potential runtime requirements include:

* Core PHP CLI runtime.
* PHAR support.
* JSON support.
* Process execution.
* Filesystem operations.
* Hashing.
* Any extension required by the selected TOML implementation.

The exact extension list must be established through a packaging spike and then pinned in `dorialang/baton-php`.

The bootstrap should prefer the smallest practical runtime, but size reduction must not come at the expense of predictable cross-platform behaviour.

### Runtime Isolation

Invoke the private runtime with user configuration disabled:

```text
php -n
```

The runtime must not:

* Load the user’s `php.ini`.
* Load configuration from system PHP directories.
* Load system PHP extensions.
* Search the system extension directory.
* Execute PHP code supplied by a Doria project.
* Fall back silently to a system PHP installation.
* Depend on Composer being available at runtime.

The Baton launcher must always resolve the private runtime relative to the installed toolchain root.

### Runtime Ownership

All PHP runtime responsibilities belong to `dorialang/baton-php`, including:

* Runtime build scripts.
* Runtime configuration.
* Runtime update policy.
* Security updates.
* Dependency inventories.
* Licence notices.
* PHAR generation.
* Platform-specific runtime tests.

The Doria compiler repository has no responsibility for maintaining or packaging PHP.

### Licensing Requirements

The Baton distribution must include:

* The PHP licence.
* Third-party dependency licences.
* Composer package notices.
* Runtime extension notices.
* Any required attribution material.
* A generated dependency and licence inventory.

### Acceptance Criteria

On a clean machine where the following are unavailable:

```text
php
composer
rustc
cargo
```

the installed toolchain must successfully execute:

```bash
baton --version
baton doctor
baton new hello
baton run
```

The launcher must not consult or execute a system PHP installation even when one happens to be present.

---

# Part VII — Toolchain Assembly

## 18. Milestone B13: Assemble the Distribution in `dorialang/baton-php`

The Baton bootstrap repository owns assembly of complete user-facing
prerelease toolchains until the native cutover.

Its release workflow downloads exact compiler components published by
`dorialang/doria`, verifies them, adds Baton and its private PHP runtime, and
produces bootstrap installation archives. Production assembly transfers to
`dorialang/baton`; the unsuffixed `2026.03.1` release cannot use this workflow.

### Release Inputs

The assembly workflow receives:

```text
Toolchain CalVer
Release channel
Target platform
Target architecture
Doria component release URL
Expected Doria component checksum
Baton source revision
Private PHP runtime revision
```

The workflow must never download an unpinned `latest` compiler release.

Every compiler archive, runtime archive, and external packaging input must be selected by an immutable version, checksum, or commit identity.

### Bundle Layout

```text
doria-toolchain-<calver>-<platform>-<architecture>/
├── bin/
│   ├── baton
│   ├── doriac
│   └── doria-lsp
├── libexec/
│   └── doria/
│       ├── baton.phar
│       └── php/
│           └── <private PHP runtime>
├── share/
│   └── doria/
│       └── templates/
├── toolchain.json
├── LICENSE
└── LICENSES/
```

Windows uses the appropriate executable and launcher suffixes.

### Toolchain Manifest

```json
{
  "schema": 1,
  "toolchainVersion": "2026.07.1-canary",
  "channel": "canary",
  "platform": "linux",
  "architecture": "x86_64",
  "components": {
    "baton": {
      "version": "2026.07.1-canary",
      "path": "libexec/doria/baton.phar",
      "sha256": "..."
    },
    "doriac": {
      "version": "2026.07.1-canary",
      "path": "bin/doriac",
      "sha256": "..."
    },
    "doria-lsp": {
      "version": "2026.07.1-canary",
      "path": "bin/doria-lsp",
      "sha256": "..."
    },
    "php-runtime": {
      "version": "...",
      "path": "libexec/doria/php/...",
      "sha256": "..."
    }
  }
}
```

This is internal distribution metadata.

It is not:

* `Baton.toml`.
* `Baton.lock`.
* A package dependency manifest.
* A substitute for the future Baton resolver.

### Assembly Verification

The release workflow must:

1. Download the exact compiler component archive.
2. Verify its published checksum.
3. Read and validate its component manifest.
4. Confirm the requested CalVer.
5. Confirm the platform and architecture.
6. Verify the bundled compiler binaries.
7. Build Baton’s PHAR.
8. Add the matching private PHP runtime.
9. Generate `toolchain.json`.
10. Calculate final component hashes.
11. Assemble the installation archive.
12. Extract the completed archive into a clean environment.
13. Run the distribution test suite against the extracted archive.

### Relocatability

The assembled toolchain must continue working when:

* Extracted into a different directory.
* Installed under a path containing spaces.
* Installed under a Unicode path.
* Moved after extraction.
* Invoked through a symbolic link.
* Invoked through an installation launcher.
* Installed in a read-only toolchain directory while project and cache directories remain writable.

No absolute build-machine paths may remain in the archive.

### Acceptance Criteria

* Baton assembles the toolchain without cloning the compiler repository.
* Compiler assets are checksum-verified.
* No unpinned compiler or runtime asset is used.
* The compiler repository does not run Baton packaging jobs.
* The final bundle works after relocation.
* The final archive contains no Composer development dependencies.
* Bootstrap prerelease toolchain assembly is fully owned by
  `dorialang/baton-php` until the mandatory native cutover.

---

# Part VIII — Cross-Repository Release Coordination

## 19. Milestone B14: Establish a Staged Release Protocol

Because Baton and `doriac` live in separate repositories, integration must rely on stable, versioned contracts rather than atomic cross-project commits.

### Non-Breaking Compiler Changes

Use this sequence:

1. Add or extend the compiler interface in `dorialang/doria`.
2. Preserve the existing interface.
3. Publish compiler canary components.
4. Update `dorialang/baton-php` to consume the new capability.
5. Run Baton’s integration tests against the canary components.
6. Publish the assembled Doria toolchain.

### Breaking Compiler Changes

When a compiler-facing contract must change:

1. Introduce a new schema or command form alongside the old one.
2. Publish a compiler release supporting both forms.
3. Update Baton to use the new contract.
4. Publish at least one compatible assembled toolchain.
5. Remove the old compiler contract only after the documented compatibility window.

Breaking changes must not be coordinated through assumptions about matching branch heads or repository commits.

### Contract Versioning

Machine-readable compiler output must carry an explicit schema:

```json
{
  "schema": 1
}
```

Changes that add optional fields may remain within the same schema when old consumers can safely ignore them.

Changes that rename fields, remove fields, change their meaning, or alter required structure must increment the schema.

### Toolchain Version Coordination

The public Doria toolchain components share the toolchain CalVer:

```text
2026.07.1-canary
```

The repositories may have independent commit histories, tags, and development cadences, but an assembled public toolchain must identify the exact compatible component releases it contains.

### Acceptance Criteria

* Baton does not track the compiler’s development branch by default.
* Public bundles consume exact released compiler components.
* Breaking machine interfaces have compatibility windows.
* Cross-repository integration is tested before publication.
* Compiler releases can occur without requiring a Baton release when the public contract remains compatible.
* Baton releases can occur without compiler changes when existing compiler capabilities are sufficient.

---

# Part IX — Release Engineering

## 20. Milestone B15: Add CalVer Release Automation

### Accepted Release Forms

```text
yyyy.mm.n-canary
yyyy.mm.n-rc
yyyy.mm.n
```

The exact permitted channels and stable-release gate must follow the accepted Doria release policy.

### Release Validation

The workflow must verify:

* The year matches the release date.
* The month matches the release date.
* The month is zero-padded.
* The release sequence is valid and unused.
* The channel is permitted.
* The Baton toolchain version matches the selected compiler components.
* Every public toolchain component reports the expected version.
* No toolchain release accidentally uses SemVer.

Package versions inside `Baton.toml` continue to use SemVer.

### Bootstrap Prerelease Toolchain Artifacts

Before native cutover, the `dorialang/baton-php` prerelease workflow produces:

```text
doria-toolchain-<version>-windows-x86_64.zip
doria-toolchain-<version>-linux-x86_64.tar.gz
doria-toolchain-<version>-linux-aarch64.tar.gz
doria-toolchain-<version>-macos-x86_64.tar.gz
doria-toolchain-<version>-macos-aarch64.tar.gz
```

### Published Materials

For every toolchain archive, publish:

* SHA-256 checksum.
* Build provenance or CI attestation.
* Compiler-component provenance.
* Baton source revision.
* Private PHP runtime identity for the bootstrap prerelease.
* Dependency inventory.
* Licence inventory.
* Archive-size report.
* Component version report.
* Clean-machine test results.

### Reproducible Archives

Normalize where practical:

* Archive timestamps.
* File ordering.
* File permissions.
* Generated JSON ordering.
* Line endings.
* Compression settings.

Reproducibility must be measured and reported rather than assumed.

### Acceptance Criteria

* Every target archive passes its clean-machine tests.
* Every component reports the expected CalVer.
* Published checksums match downloaded archives.
* Development dependencies are absent.
* Required licences and notices are present.
* Toolchain releases are assembled only from verified inputs.

---

## 21. Milestone B16: Clean-Machine Distribution Tests

Test the final extracted toolchain archives, not source-tree commands.

### Test Scenario

```bash
extract toolchain
add toolchain/bin to PATH

baton --version
baton doctor
baton new hello
cd hello
baton check
baton run
baton build --release
```

### Required Conditions

Test with:

* No Rust installation.
* No Cargo installation.
* No PHP installation.
* No Composer installation.
* No Doria repository checkout.
* No Baton repository checkout.
* No previous Doria installation.
* A fake incompatible `doriac` earlier on `PATH`.
* An installation path containing spaces.
* A project path containing spaces.
* Unicode installation paths.
* Unicode project paths.
* A read-only toolchain directory.
* Offline execution.
* A corrupt `toolchain.json`.
* A missing compiler component.
* A modified component whose hash no longer matches.
* Compiler failure.
* Program failure.
* Program arguments after `--`.
* Interactive standard input.
* Ctrl+C and process termination.

### Acceptance Criterion

> A user can download and use Doria without knowing that `doriac` is bootstrapped in Rust or that Baton is bootstrapped in PHP.

---

# Part X — Progressive Integration With the End-to-End Plan

## 22. Stage 31 Integration

Stage 31 follows Decisions 0117 and 0118 in two compiler-facing slices. After
its multi-file and namespace work lands, Baton may add:

* Source-root discovery.
* Multi-file compiler invocation.
* Library project templates.
* Workspace-aware language-server roots.
* Compiler-supported package compilation roots.
* Versioned JSON build-plan construction for already resolved package inputs.

Baton must not:

* Resolve Doria namespaces itself.
* Parse imports using PHP.
* Reimplement the compiler’s module graph.
* Guess compilation units through regular-expression scanning.
* Parse dependency sources or lockfiles as part of Stage 31.

The compiler must expose the project-level compilation services Baton needs.

---

## 23. Stage 32 Integration

After attributes land, Baton may consume compiler-produced metadata required for test discovery.

Baton must not:

* Parse Doria attributes in PHP.
* Scan Doria source with regular expressions.
* Invent a temporary test annotation.
* Implement an independent reflection system.
* Treat runtime PHP attributes as Doria attribute semantics.

Attribute validation and interpretation remain compiler-owned.

---

## 24. Stage 33 Product-Contract Completion in the PHP UX Bootstrap

Stage 33 completes the capabilities deliberately omitted from the early
bootstrap and exercises the complete accepted user experience in
`dorialang/baton-php`. It freezes observable product behavior for the mandatory
Pre-Stage-45 Doria-native port. It does not promote PHP internals into permanent
architecture, make this repository the final Baton repository, or satisfy the
native transition.

Decision 0118 divides the work into three slices.

### Stage 33 Slice 1 - Complete

Schema 2, exact schema-1 compatibility, compile-time `autoload`, source scopes,
targets, deterministic single-package compiler build plans, target-scoped build
layouts, and receipts are implemented.

### Stage 33 Slice 2 - Complete

Path and Git resolution, SemVer constraint solving, one-version conflict
reporting, deterministic JSON `Baton.lock`, dependency commands, the global
content-addressed cache, offline resolution, multi-package compiler plans, and
dependency-aware receipts are implemented.

### Stage 33 Slice 3 - Complete

Workspaces, development dependencies, graph commands, incremental inventory,
`baton test`, explicit processor orchestration, generated-source writes, and
project inventory are implemented.

Stage 33 and Phase F are complete.

### Native Testing Foundation Slice 1 - Complete

Compiler-authored schema 3 test inventory now unifies `#[Test]` methods and
behavioral `describe`/`it`/`test` declarations. Baton discovers, filters, and
dispatches those records without parsing Doria source.

### Native Testing Foundation Slice 2 - Complete

Compiler-owned expectation execution, `AssertionError`, and generic isolated
process continuation are implemented.

### Native Testing Foundation Slice 3 - Complete

Collection and checked-Error expectations, strict DORIAO2/DORIAO3/DORIAO4
decoding, five-category classification, compiler-authored hierarchy, final
filtering, and structured reporting are implemented. The Native Testing
Foundation is complete and Stage 34 single class inheritance is next. The
mandatory Pre-Stage-45 Doria-native Baton transition remains scheduled and must
parity-port this frozen observable contract before the unsuffixed release.

### Add

```text
baton test
path dependencies
git dependencies
deterministic dependency resolution
Baton.lock
binary project templates
library project templates
official test reporting
workspace-aware editor integration
compile-time autoload
offline builds
```

### Decision Work

Decisions 0117 and 0118 settle:

* Full `Baton.toml` schema.
* `Baton.lock` encoding.
* Resolver identity.
* Package-source identity.
* Platform and architecture constraints.
* Feature selection.
* Native-library extension points.
* Binary-artifact extension points.
* Test discovery and execution.
* Cache keys.
* Reproducibility rules.
* Offline resolution behaviour.
* Conflict diagnostics.

The remaining public spellings for additional target tables, the local-only
package marker, optional features, target predicates, processor permissions,
and workspace package selection are bounded deferrals. They do not reopen the
accepted architecture.

The resolver must leave room for:

* Target-specific dependencies.
* Native libraries.
* Optional features.
* Build profiles.
* Binary artifacts.
* Future registries.

It must not assume every dependency is pure, target-independent Doria source.

The current PHP bootstrap accepts exact schema 1 and strict schema 2 projects.
It resolves normal, development, and processor path/Git graphs; validates
standalone lock schema 1 and workspace lock schema 2; orchestrates tests and
one-pass processors; publishes generated source atomically; emits graph and
project inventory; and preserves exact offline behavior.

### Stage 33 Acceptance Criterion

```bash
baton new game
cd game
baton test
```

is green without additional configuration.

At this stage Baton's public behavior is no longer only a compiler wrapper: the
PHP UX oracle exercises the official Doria project, package, build, and test
contract. Production implementation and distribution ownership still remain
with the mandatory Pre-Stage-45 Doria-native transition.

---

# Part XI — Later Native and PHP Integration

## 25. Stage 40 Integration

After the unsafe and FFI design settles the required security and ownership rules, Baton may add native dependency declarations and linking configuration through `Baton.toml`.

The compiler remains responsible for:

* ABI validation.
* Type checking.
* Ownership checks.
* Unsafe boundary enforcement.
* Code generation.

Baton remains responsible for:

* Locating native libraries.
* Selecting target-specific artifacts.
* Passing link configuration to the compiler.
* Caching downloaded artifacts.
* Producing project-level diagnostics for missing dependencies.

---

## 26. Stage 41 Integration

Activate:

```bash
baton build --php-lib
```

Baton orchestrates:

* Doria library compilation.
* C-ABI bridge generation.
* Generated PHP adapter output.
* Package layout.
* Bridge metadata.
* Distribution assembly.

`doriac` owns compiler emission primitives, ABI rules, and export validation.

Baton owns the product workflow.

---

# Part XII — Mandatory Pre-Stage-45 Migration to Doria-Native Baton

## 27. Doria-Native Baton Transition And Release Gate

The PHP implementation is a bootstrap, not a permanent dependency.

The Doria-native implementation will live in:

```text
dorialang/baton
```

It will be a new repository with a clean project history.

The PHP implementation remains in:

```text
dorialang/baton-php
```

for historical maintenance, compatibility testing, and bootstrap reference
purposes after cutover. It no longer assembles production toolchains.

This transition runs after Stage 44 and before Stage 45 compiler self-hosting.
It is a binding stage beat under Decision 0124, not an unscheduled exit option.

### Rewrite Readiness

The transition begins only after Doria can comfortably express Baton's real
implementation needs and the owning stages have supplied the platform
foundations:

* Multi-file applications.
* Namespaces.
* Generic collections.
* Checked errors.
* Filesystem APIs.
* Environment APIs.
* Process execution.
* Path manipulation.
* TOML parsing.
* JSON parsing and generation.
* Cryptographic hashing.
* Testing.
* Cross-platform binaries.
* Archive handling or a stable library for it.
* Network access when registry support becomes relevant.

Stage 31 supplies multi-file applications and namespaces; the collection and
checked-error stages supply the core data model; Stage 32 supplies test
metadata; Stage 33 freezes Baton's product behavior; Stage 36a supplies
filesystem and child-process foundations; Stage 40 supplies reviewed native
integration; and Stage 44 supplies network/HTTP foundations. Environment, path,
TOML, JSON, cryptographic hashing, and archive support are explicit transition
entry gates and must come from reviewed Doria library/package APIs rather than
hidden Baton-only compiler intrinsics or PHP subprocesses.

### Porting Boundaries

Port behind stable conceptual interfaces:

```text
ManifestLoader
ManifestValidator
ToolchainLocator
ProcessRunner
BuildPlanner
BuildLayout
Resolver
ComponentVerifier
Diagnostics
```

The Doria implementation does not need to reproduce PHP-era internal class structure. Only the public behaviour and durable project contracts must remain compatible.

### Compatibility Tests

Run the PHP and Doria implementations against the same fixtures and compare:

* Exit codes and signal behavior.
* Build and installed paths.
* Compiler and language-server argument vectors.
* Manifest and lockfile parsing, validation, and canonical serialization.
* Deterministic source inventories, build plans, receipts, and cache keys.
* Dependency/workspace graphs, conflicts, and offline behavior.
* Toolchain discovery and component verification.
* Human, concise, and machine-readable diagnostics.
* Generated project layouts and templates.
* Test discovery, execution order, reporting, and failure behavior.
* Clean-machine installation, relocation, and read-only toolchain roots.

### Final Transition

The installed layout changes at the mandatory native cutover from:

```text
bin/baton
libexec/doria/baton.phar
libexec/doria/php/
```

to:

```text
bin/baton
```

without changing:

```text
Baton.toml
Baton.lock
build layout
CLI command names
exit-code contracts
diagnostic conventions
toolchain CalVer
package SemVer
```

Users must not need to migrate their projects merely because Baton’s implementation language changed.

Cutover is complete only when native Baton satisfies every Stage 33 acceptance
criterion, passes the shared suite on every supported platform, and builds,
checks, tests, and packages its own repository through the installed workflow.
Production templates, release automation, provenance, and complete toolchain
assembly then move to `dorialang/baton`; archives contain no PHAR, Composer
payload, PHP launcher, or private runtime. `dorialang/baton-php` freezes as
historical bootstrap and compatibility reference.

The unsuffixed `2026.03.1` toolchain release is blocked until every transition
criterion passes. A PHP-based canary does not satisfy this gate.

---

# Part XIII — Testing and Quality

## 28. Test Layers

### PHP Unit Tests

Cover:

* Manifest parsing.
* Manifest validation.
* Project discovery.
* Build planning.
* Toolchain discovery.
* Compiler-version validation.
* Path handling.
* Error rendering.
* Template generation.
* Component verification.

### Fake-Compiler Integration Tests

Use a deterministic fake `doriac` to verify:

* Exact argument vectors.
* Standard-stream forwarding.
* Exit-code forwarding.
* Version mismatch handling.
* Build-path selection.
* Development and release profile mapping.
* Malformed machine-readable output.
* Compiler process failure.

### Real-Compiler Integration Tests

Use an exact downloaded compiler component release.

These tests must not require a compiler repository checkout.

### Distribution Tests

Extract the final archive and test the installed commands exactly as users will run them.

Testing the PHAR directly from the source tree is insufficient.

### Cross-Platform Requirements

Process, filesystem, launcher, and path tests must include Windows from the beginning.

Windows support must not be deferred until after Unix behaviour has become entrenched.

---

## 29. Security Requirements

* Never construct compiler commands through shell-string interpolation.
* Prefer the bundled compiler over `PATH`.
* Do not load user PHP configuration.
* Do not load system PHP extensions.
* Do not execute PHP from a Doria project.
* Do not introduce build scripts or plugins in the bootstrap.
* Validate all template extraction paths.
* Prevent manifest entry paths from escaping the project root.
* Verify downloaded compiler checksums.
* Verify bundled component identities.
* Publish release provenance.
* Pin release dependencies.
* Generate third-party licence inventories.
* Treat manifests as data, never executable configuration.
* Do not add registry network access before the package-security model exists.
* Do not expose secrets through `baton doctor`.
* Do not allow environment overrides silently in public mode.
* Clearly mark all developer-only overrides.

---

# Part XIV — Documentation

## 30. User Documentation

The primary quick start becomes:

```bash
baton new hello
cd hello
baton run
```

It must not begin with Cargo or Composer.

Documentation should be divided into three audiences.

### Using Doria

Requires no:

```text
Rust
Cargo
PHP
Composer
repository checkout
```

### Contributing to `doriac`

Requires Rust and Cargo.

This documentation belongs to `dorialang/doria`.

### Contributing to Baton Bootstrap

Requires PHP and Composer.

This documentation belongs to `dorialang/baton-php`.

The PHP development workflow must not be presented as the ordinary installation path.

---

# Part XV — Invalidated Assumptions

## 31. Repository and Release Corrections

The following assumptions are explicitly invalid:

1. **Baton Will Not Begin in the Compiler Repository**

   * There is no extraction phase.
   * The repository boundary exists from the first Baton commit.

2. **The Bootstrap Repository Is `dorialang/baton-php`**

   * `dorialang/baton` is reserved until the mandatory Pre-Stage-45 transition,
     then becomes the production repository.

3. **Atomic Compiler and Baton Commits Are Not Required**

   * Integration occurs through released, versioned contracts.

4. **Baton CI Does Not Run in the Compiler Repository**

   * The compiler publishes components.
   * Baton assembles and tests the user-facing distribution.

5. **Baton Dependencies Do Not Affect Compiler Maintainers**

   * Composer, PHP, PHAR, TOML, launcher, and packaging dependencies remain Baton-owned.

6. **Project Templates Are Baton-Owned**

   * They are not compiler repository content.

7. **The Compiler Repository Does Not Package PHP**

   * Private PHP runtime ownership belongs entirely to `dorialang/baton-php`
     during bootstrap prereleases and disappears from production archives at
     native cutover.

8. **The Compiler Repository Does Not Publish Complete Toolchains**

   * It publishes compiler components.
   * `dorialang/baton-php` publishes bootstrap prereleases before cutover.
   * `dorialang/baton` publishes production distributions after cutover.

9. **Compiler Contracts Must Be Explicit**

   * Baton never depends on compiler implementation details or source-tree layouts.

10. **The Doria-Native Rewrite Gets a Clean Repository**

    * The Pre-Stage-45 `dorialang/baton` repository does not inherit the
      bootstrap repository's PHP history.
    * The rewrite and production cutover are mandatory before the unsuffixed
      `2026.03.1` release, not an optional later migration.

11. **Toolchain Releases Use CalVer**

    * Package versions inside project manifests continue using SemVer.

12. **Diagnostics Use Title Case**

    * Baton-specific diagnostic headings follow the same convention as Doria compiler diagnostics.

---

# Part XVI — Definition of Done

## 32. Bootstrap Completion Criteria

The early Baton effort is complete when:

* `dorialang/baton-php` exists as an independent repository.
* `dorialang/baton` remains reserved for the mandatory Doria-native transition.
* No Baton source or Composer dependency exists in `dorialang/doria`.
* The end-to-end plan records the repository and ownership boundary.
* The compiler repository publishes prebuilt `doriac` and `doria-lsp` components.
* Compiler components expose stable machine-readable version metadata.
* Baton downloads and verifies exact compiler release artifacts.
* Baton supports `new`, `check`, `build`, `build --release`, `run`, and `doctor`.
* Baton uses a minimal versioned `Baton.toml`.
* Package versions use SemVer.
* Toolchain components use CalVer.
* Baton includes an isolated private PHP runtime.
* Bootstrap prerelease archives are assembled in `dorialang/baton-php`.
* Clean-machine tests pass without Rust, Cargo, PHP, or Composer.
* Compiler maintainers do not inherit Baton dependency or packaging work.
* Baton maintainers can update project workflow without modifying compiler source.
* Cross-repository compiler contracts are schema-versioned.
* The PHP implementation can be parity-ported without changing durable project contracts.

## 33. Doria-Native Transition Completion Criteria

The Baton transition is complete when:

* the clean `dorialang/baton` repository contains the production Doria source;
* all Stage 33 behavior is represented in the shared implementation-neutral
  compatibility suite;
* PHP and Doria agree on commands, arguments, exit/signal behavior, schemas,
  diagnostics, resolution, workspaces, caches, tests, paths, and offline use;
* native Baton builds, checks, tests, and packages its own repository;
* production templates and release/toolchain assembly belong to
  `dorialang/baton`;
* clean-machine native-only archives pass on every supported platform;
* no production archive contains a PHAR, Composer payload, PHP launcher, or
  private PHP runtime;
* `dorialang/baton-php` is frozen as historical and compatibility reference;
* the unsuffixed `2026.03.1` release gate records the native cutover as passed.

## Final Product Principle

> **The repositories should reflect actual ownership boundaries.**

`dorialang/doria` builds the language, compiler, and compiler components.

`dorialang/baton-php` builds the bootstrap project experience, validates the
Stage 33 contract, and assembles prerelease toolchains only until cutover.

`dorialang/baton` contains the clean Doria-native implementation and owns
production toolchain assembly after the mandatory Pre-Stage-45 cutover.

The implementation languages of the bootstrap tools remain contributor details, not user installation requirements.
