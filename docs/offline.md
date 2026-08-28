# Offline Operation

Pass `--offline` to `install`, `add`, `remove`, `update`, `fetch`, `check`,
`build`, or `run` to prohibit every network-capable Git operation.

Offline operation may use:

- live path dependencies;
- an existing valid `Baton.lock`;
- exact Git metadata and checkouts already present in the global cache.

It never follows a moved branch or tag, substitutes a different version, or
falls back to the network after a miss. Missing or corrupt exact content is a
Baton diagnostic. Path-only and dependency-free projects remain usable offline.

Offline is an invocation policy in Slice 2, not a persistent manifest setting.
