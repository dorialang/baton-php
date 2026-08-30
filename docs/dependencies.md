# Dependencies

Schema-2 packages declare normal dependencies in `[dependencies]`. A dependency
must expose a library target and its authored key must match the dependency
package's `name`.

```toml
[dependencies]
"acme/core" = { source = "path", path = "../core", version = "^1.2" }
"acme/log" = { source = "git", url = "https://github.com/acme/log.git", tag = "v2.0.0", version = "~2.0" }
```

## Sources

- `source` is required and selects the transport. A URL alone never selects Git.
- `path` is relative to the declaring `Baton.toml`. Path packages remain live;
  Baton rereads their manifest and sources.
- `git` uses `url` with a canonical HTTPS or SSH locator without credentials, query text, or
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
baton add <package> --source path --path <path> [--version <constraint>] [--dev] [--offline]
baton add <package> --source git --url <url> (--rev <rev> | --tag <tag> | --branch <branch>) [--version <constraint>] [--dev] [--offline]
baton remove <package> [--dev] [--offline]
baton update [package ...] [--offline]
baton fetch [package ...] [--offline]
baton tree [--development]
baton why <package> [--development]
```

`install` creates a lock when no lock is present, including a canonical empty
lock when invoked explicitly for a dependency-free schema-2 project; an
existing lock is installed exactly. `update` deliberately resolves new lock
facts, either for the complete graph or selected identities. Selected updates do
not silently move unselected Git pins. `add` and `remove` replace the manifest
and lock as one transaction. `fetch` acquires exact locked content without
editing either file.

The old `git =` manifest locator and `--git` command option are rejected with
the exact `source = "git"`/`url = "..."` migration. `[dev-dependencies]` uses
the same resolver and lock rules but activates only for the selected package's
tests and explicit development tooling. `[processors]` uses the same source
model while remaining outside ordinary Doria source visibility.

See [Lockfile](lockfile.md), [Dependency cache](dependency-cache.md), and
[Offline operation](offline.md).
