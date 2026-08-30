# Project Manifest

Every Baton project is rooted by `Baton.toml`. Baton searches the current
directory and its parents for that file, parses it as inert TOML data, and rejects
unknown fields.

## Schema 2

New local projects use schema 2:

```toml
manifest-version = 2

[package]
name = "hello"
version = "0.1.0"
edition = "2026"
publishable = false
kind = "binary"
entry = "src/main.doria"

[autoload.namespaces]
"" = "src/"
```

An unscoped name is local and must explicitly set `publishable = false`. Its
compiler identity is `local/<name>`; `local` is reserved as a scoped vendor.
User-facing output continues to use the manifest name. A scoped
`vendor/package` name defaults to publishable and may explicitly set either
publishability value. Package versions are strict SemVer; the required edition
is the string `"2026"`.

The package-level `kind = "binary"` and `entry` fields are the one-binary
shorthand. The target name is the final package-name segment. Packages needing
a library or several binaries use explicit targets instead:

```toml
[targets.library]
name = "blog"

[[targets.binary]]
name = "web"
entry = "src/web.doria"

[[targets.binary]]
name = "worker"
entry = "src/worker.doria"
```

The shorthand and explicit targets are mutually exclusive. Target names are
unique lowercase filesystem-safe slugs. There is no default-target field and no
generic `--target` selector. See [Package targets](targets.md).

## Autoload Mappings

Main and development source roots are explicit:

```toml
[autoload.namespaces]
"Acme\\Blog\\" = "src/"

[autoload-dev.namespaces]
"Acme\\Blog\\Tests\\" = {
    path = "tests/",
    include = ["**/*.doria"],
    exclude = ["**/Fixtures/**"],
}
```

The string form uses `include = ["**/*.doria"]` and no exclusions. Prefixes
use exact PascalCase segments and folded acronyms, end in `\`, and remain
independent of package identity. The empty prefix maps the root namespace.
Paths are project-relative and contained. Patterns support only `*`, `?`, and
`**`; exclude wins.

Ordinary `check`, `build`, and `run` activate main sources only. Development
sources are inventoried but become active only for tests or explicit
development tooling. See [Source discovery](source-discovery.md).

## Dependencies And Processors

Schema 2 separates source transport from source location. `source` is required.
Path sources use `path`; Git sources use `url` and exactly one of `rev`, `tag`,
or `branch`. Either may add a `version` constraint.

```toml
[dependencies]
"acme/core" = { source = "path", path = "../core", version = "^1.0" }
"acme/log" = { source = "git", url = "ssh://git@github.com/acme/log.git", rev = "7de4..." }

[dev-dependencies]
"acme/test-support" = { source = "path", path = "../test-support" }

[processors]
"acme/routes" = {
    source = "git",
    url = "https://github.com/acme/routes.git",
    tag = "v1.2.0",
    binary = "routes",
    attributes = ["Acme\\Metadata\\Route"],
}
```

The former `git = "..."` locator is rejected with a diagnostic showing
`source = "git"` and `url = "..."`; it is not a permanent alias. See
[Dependencies](dependencies.md) and [Attribute processors](processors.md).

## Workspace

`[workspace]` declares a required `members` array. A manifest without
`[package]` is a virtual root; one with `[package]` makes the root the implicit
`.` member. Members keep independent package and `internal` boundaries and share
one workspace-root lock. See [Workspaces](workspaces.md).

## Schema 1 Compatibility

Existing schema-1 projects retain their exact historical meaning:

```toml
manifest-version = 1

[package]
name = "hello-doria"
version = "0.1.0"
kind = "binary"
entry = "src/main.doria"
```

Schema 1 has one explicit binary entry, no edition, publishability, autoload,
targets, dependency tables, lockfile semantics, workspace, or processors. It
continues to invoke `doriac check <entry>` and
`doriac compile <entry> --target native`; it is never reinterpreted as schema 2.

## Paths And Versions

Entry and mapping paths resolve from the directory containing `Baton.toml`.
They must be relative, use exact filesystem case, remain inside the project
after symlink resolution, and contain no parent traversal, URL, drive-qualified,
or absolute spelling. Binary entries must identify readable UTF-8 `.doria`
files.

Package versions use SemVer, such as `1.4.2` or `2.0.0-rc.1`. Doria toolchain
versions use zero-padded CalVer, such as `2026.03.1-canary`; Baton never compares
or substitutes these domains.

## Build Output

Schema-2 output is target-scoped:

```text
build/<host-target>/<profile>/<target-name>/
├── <target-name>[.exe]  # binary build only
├── build-plan.json
└── build.json           # successful baton build only
```

A library build performs compiler checking and records `"artifact": null`; it
does not invent an archive. Schema 1 retains
`build/<host-target>/<profile>/<package>[.exe]` with its historical receipt.
Failed builds remove stale managed artifacts and receipts. Explicit binary
`--out` paths remain user-directed and receive no managed receipt.

`toolchain.json` is installed-toolchain metadata. `build.json` is a build
receipt. `Baton.lock` separately records the exact dependency graph and is
described in [Lockfile](lockfile.md).
