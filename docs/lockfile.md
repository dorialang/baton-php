# Lockfile

`Baton.lock` is deterministic, strict JSON owned by Baton. Commit it; do not edit
it by hand. Schema 1 records the root package and every reachable normal package,
including:

- authored and compiler package identities;
- exact SemVer package versions;
- semantic manifest fingerprints;
- sorted normal dependency edges and authored constraints;
- portable root-relative paths for path dependencies;
- canonical Git URLs, declared selectors, and exact 40-character commits.

The file excludes absolute roots, cache locations, credentials, target/profile
selection, toolchain facts, and path source-content hashes. Comment and whitespace
changes do not stale a lock; resolution-relevant manifest changes do.

Lock writes use a flushed atomic sibling replacement. `check`, `build`, `run`,
and `fetch` validate an existing lock and never update it. Use `baton update` for
an intentional graph change. A dependency-free project does not need a lock for
check, build, or run; explicit `baton install` and removal of the final
dependency leave a valid empty lock.

Schema-2 build receipts record the SHA-256 of the exact lock bytes. They also
record deterministic content fingerprints for live path dependencies. Git
content is already identified by its exact locked commit.
