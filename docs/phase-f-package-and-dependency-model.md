# Phase F Package And Dependency Model

This document records the accepted target contract for Baton's package system.
It follows Doria Decisions 0117, 0118, and 0128. Stage 33 implements the full
Phase F product contract in the executable PHP bootstrap.

## Schema Compatibility And Current Boundary

The bootstrap continues to read historical manifest schema 1 exactly:

```toml
manifest-version = 1

[package]
name = "hello-doria"
version = "0.1.0"
kind = "binary"
entry = "src/main.doria"
```

Schema 1 means one binary, one explicit entry file, no `autoload`, no
dependencies, no `Baton.lock`, and no workspace. It is not reinterpreted.

Stage 33 Slice 1 added strict schema 2 and source inventory. Slice 2 added normal
dependency resolution, locks, exact Git cache entries, and multi-package plans.
Slice 3 completes workspaces, development dependencies, metadata-driven tests,
processors, generated sources, graph commands, and project inventory.
The post-Stage-33 native testing foundation keeps that project contract intact
while schema 3 unifies low-level and behavioral compiler test records for Baton.
Slices 1 and 2 are implemented: core expectations are compiler-owned and Baton
continues to treat escaping assertions as generic failed isolated processes.
Slice 3 remains next for assertion-specific classification and hierarchical
presentation; the foundation remains incomplete and Stage 34 remains blocked.

## Schema 2

```toml
manifest-version = 2

[package]
name = "acme/blog"
version = "1.0.0"
edition = "2026"
kind = "binary"
entry = "src/main.doria"

[autoload.namespaces]
"Acme\\Blog\\" = "src/"

[autoload-dev.namespaces]
"Acme\\Blog\\Tests\\" = "tests/"

```

Unscoped local names require explicit `publishable = false` and map to the
reserved compiler identity `local/<name>`. Scoped `vendor/package` identities
default to publishable and may opt out. Schema 2 requires strict SemVer and
edition `"2026"`. Normal, development, processor, and workspace tables are
strict executable schema-2 contracts.

## Source Discovery

`autoload` tells Baton where a namespace's source files live. Baton finds those
files during the build and gives a deterministic source inventory to `doriac`.
Doria programs do not load source files while running.

The default mapping pattern is `**/*.doria`. An advanced mapping may supply
`path`, `include`, and `exclude` while preserving the same operation. Paths are
project-relative, canonical, contained by the package root, deterministically
ordered, free from symlink loops or escapes, and checked for exact casing and
cross-platform case collisions. The longest matching namespace prefix governs
layout validation.

Main sources come from `[autoload.namespaces]`. Development sources come from
`[autoload-dev.namespaces]` and participate only in tests, examples,
benchmarks, and development tooling. Baton or an explicit processor injects
generated roots under the build directory. Generated files are checked like
handwritten source and do not trigger recursive processing.

Every active main, development, generated, dependency, and explicitly included
file is checked even when no reachable declaration uses it.

## Hybrid Strict Layout

Namespace directory segments match exactly. Given `"Acme\\Blog\\" = "src/"`,
`namespace Acme\Blog\Http;` belongs beneath `src/Http/`.

An externally accessible type named `PostController` belongs in
`PostController.doria`, and a file has one primary externally accessible type.
Related `internal` helpers may share the file. Function and constant bundles may
use descriptive filenames. Generated bundles and selected binary entry files
have bounded exceptions to the type-filename rule.

Only a selected binary entry file may contain top-level executable statements.
Other package sources are declaration-only. Discovery order never defines
runtime initialization.

## `autoload`, `use`, `include`, And Dependencies

- `autoload` discovers a package's source files.
- `use` shortens a Doria symbol name; Baton does not interpret it.
- `include` adds one same-package source file at compile time, relative to the
  including file, with required include-once semantics.
- dependencies add other packages to the build graph.

Autoload and include deduplicate by canonical source identity. Include may add a
same-package file excluded from ordinary discovery. Cross-package source
traversal, remote includes, computed includes, and runtime includes are
rejected.

## Package Identity, Targets, And Access

Publishable identities use lowercase `vendor/package`. Package identity is
independent of Doria namespace identity. An unscoped name is an explicitly
local, non-publishable package and uses `publishable = false`.

A package may have at most one `[targets.library]` and zero or more
`[[targets.binary]]` tables. The common single-binary `kind` and `entry`
shorthand remains and cannot mix with explicit targets. Commands select with
`--binary <name>` or `--library`; there is no generic `--target` or default
target field.

Doria `internal` is package-wide, including the package's own development and
generated sources. It is not workspace-wide. Several packages may contribute
distinct declarations to one namespace, but duplicate fully qualified symbols
are compiler errors with both packages and sources identified.

## Baton-To-Compiler Build Plan

Baton resolves manifests, source mappings, dependency and workspace graphs,
generated roots, targets, and profiles into a versioned JSON build plan. The
plan explicitly carries package roots, stable source identities, scopes,
origins, namespace mappings, entries, dependency edges, direct dependencies,
package access boundaries, compiler options, target, and profile.

`doriac` owns Doria parsing, declaration indexing, namespace and import
resolution, type and ownership checking, package visibility, duplicate symbols,
MIR, code generation, and compiler caches. It does not parse `Baton.toml` or
fetch packages. Baton does not parse Doria declarations or maintain a semantic
symbol index.

Standalone `doriac` remains usable with explicit files or a compiler-owned
build plan.

## Dependency Resolution

Only a package's own declarations, the standard library/prelude, and directly
declared dependencies are source-visible. Transitive dependencies are not.
Externally accessible signatures cannot leak a type from an undeclared direct
dependency.

One build graph resolves one version per package identity. Conflicts report
every contributing chain, constraint, and source. Every normal dependency cycle
is rejected. Development and processor edges remain typed categories rather
than being flattened into normal visibility.

The first source transports are path and Git. A Git dependency chooses exactly
one of `rev`, `tag`, or `branch`; the lockfile always records the exact commit.
Path dependencies remain live inputs. Source transport is distinct from
artifact role so future registries, verified archives, native libraries,
processors, binary tools, and prebuilt artifacts fit without replacing the
model. Registry, archive, native-feed, binary-feed, and publishing behavior are
not part of the initial resolver.

Packages use SemVer exact, caret, tilde, and bounded comparator constraints.
Prereleases are explicit. OR expressions, Composer stability flags, implicit
development stability, and toolchain CalVer ranges are rejected. Toolchain
releases remain CalVer.

## Lockfile And Build Receipt

`Baton.lock` is deterministic, machine-generated JSON and is never hand-edited.
Applications and libraries commit the project-root lockfile. Workspaces commit
one schema-2 lock at the workspace root. Entries carry package/version identity, category, edges, source kind,
canonical URL or manifest-relative path, exact resolution, and integrity data.
Remote URLs contain no secrets; committed path entries contain no absolute
local paths.

Compiler, target, profile, flag, native-toolchain, generated-source,
path-content-hash, lock-identity, and build-plan facts belong in a versioned
build receipt such as `build.json`, not in the lockfile.

`baton install` uses an existing lock exactly or creates one when absent.
`baton update` deliberately re-resolves all or selected dependencies. Ordinary
check, build, run, and test commands may install locked content but never update
a valid lockfile silently.

## Workspaces And Categories

A workspace has deterministic members, one lockfile, shared build storage, and
one shared dependency cache. Each member retains its manifest, identity,
autoload mappings, targets, dependencies, and `internal` boundary. Duplicate
identities or paths, root escapes, ambiguous globs, and cycles are rejected.

Normal dependencies enter normal builds. Development dependencies enter tests,
examples, benchmarks, and development tooling only. Processors are explicit,
locked build tools kept separate from source dependencies and from package
source visibility.

No package-defined arbitrary build scripts, PHP hooks, shell hooks, or implicit
install/build commands are accepted. Processors write generated files under the
build directory and do not mutate handwritten source by default. Baton does not
claim sandboxing until a real sandbox and permission model exist.

## Cache, Offline Mode, And Commands

Exact dependency sources use a global content-addressed cache. Projects do not
receive a local `vendor/` directory by default. Workspace storage contains
build plans, receipts, generated sources, inventories, compiler-cache
references, and artifacts.

Offline install/check/build/run/test/project never reaches the network. It uses the lockfile,
live path dependencies, and cached exact Git sources. Missing content is
reported without version substitution. Processor execution is prohibited
offline; only an exact complete processor cache entry may be reused.

`install`, `add`, `remove`, `update`, `fetch`, `tree`, `why`, `test`, and
`project` are implemented. See
[Dependencies](dependencies.md), [Lockfile](lockfile.md), [Dependency cache](dependency-cache.md),
and [Offline operation](offline.md).

## Delivery Sequence

Stages 31 and 32 are complete. Stage 33 Slices 1, 2, and 3 are complete. Stage
33 and Phase F remain complete. Native Testing Foundation Slice 1 adds unified
behavioral discovery without reopening Phase F; the broader foundation remains
in progress, and Stage 34 single class inheritance waits for its completion.
Those slices run in this disposable PHP UX bootstrap to validate and freeze the
observable contract. Decision 0124 then requires a parity-gated Pre-Stage-45
port to the clean Doria-native `dorialang/baton` repository, production release
ownership transfer, and removal of the Baton PHP payload before the unsuffixed
`2026.03.1` release.

The active language sequence remains unchanged. This document does not begin
those implementation stages.
