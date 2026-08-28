# Dependencies

Schema-2 packages declare normal dependencies in `[dependencies]`. A dependency
must expose a library target and its authored key must match the dependency
package's `name`.

```toml
[dependencies]
"acme/core" = { path = "../core", version = "^1.2" }
"acme/log" = { git = "https://github.com/acme/log.git", tag = "v2.0.0", version = "~2.0" }
```

## Sources

- `path` is relative to the declaring `Baton.toml`. Path packages remain live;
  Baton rereads their manifest and sources.
- `git` accepts a canonical HTTPS or SSH URL without credentials, query text, or
  fragments. Exactly one of `rev`, `tag`, or `branch` is required.
- Git package identities are scoped `vendor/package` names. Unscoped packages
  are local, non-publishable path dependencies and compile as `local/<name>`.

Package constraints use SemVer exact versions, caret ranges, tilde ranges, or
comparator conjunctions such as `>=1.4 <2.0`. Prereleases must be explicit. OR,
wildcards, stability flags, development versions, and Doria toolchain CalVer are
not package constraints.

One graph selects one version and one source for each package identity. Baton
reports conflicting chains, source substitution, and cycles rather than choosing
by declaration order. Only direct dependencies are source-visible to a package;
the compiler enforces that boundary and package-wide `internal` access.

## Commands

```text
baton install [--offline]
baton add <package> --path <path> [--version <constraint>] [--offline]
baton add <package> --git <url> (--rev <rev> | --tag <tag> | --branch <branch>) [--version <constraint>] [--offline]
baton remove <package> [--offline]
baton update [package ...] [--offline]
baton fetch [package ...] [--offline]
```

`install` creates a lock when no lock is present, including a canonical empty
lock when invoked explicitly for a dependency-free schema-2 project; an
existing lock is installed exactly. `update` deliberately resolves new lock
facts, either for the complete graph or selected identities. Selected updates do
not silently move unselected Git pins. `add` and `remove` replace the manifest
and lock as one transaction. `fetch` acquires exact locked content without
editing either file.

`tree`, `why`, development dependencies, workspaces, tests, and processors remain
Stage 33 Slice 3 work.

See [Lockfile](lockfile.md), [Dependency cache](dependency-cache.md), and
[Offline operation](offline.md).
