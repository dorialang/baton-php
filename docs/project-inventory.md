# Project Inventory

`baton project --json` emits strict deterministic project document schema 1 for
language servers and other local tooling:

```console
baton project --json --development --offline
baton project --json --workspace --development --offline
```

The document contains selection, workspace members, exact package graph,
package roots, manifests, lock identity, source inventories, valid generated
source provenance, a compiler build-plan schema-1 tooling plan, and content
fingerprints. It may contain canonical local roots needed to read sources, but
never source bodies, credentials, environment values, processor logs, or cache
implementation keys.

Packages without dependencies do not require `Baton.lock`. Their lock fingerprint
is the SHA-256 digest of JSON `null`, so creating or removing a lock invalidates the
project snapshot without making lockless packages invalid.

The command validates manifests, the lock, and exact cached dependency content.
It invokes no compiler, runs no processor, edits no project file, and performs
no network operation in offline mode. Generated sources appear only when the
shared generated-source registry proves their compiler revision, owner,
processor, path, identity, and content hash. Missing or stale required output is
a diagnostic directing the user to an online check or build.

Native Testing Foundation completion does not change project or metadata
inventory schemas. Compiler-owned expectation chains remain source semantics;
Baton records schema-3 test identity/callable facts, authored ordinal, expected
compiler revision, and the versioned five-category vocabulary. It never stores
matcher plans, raw outcome records, Error objects, user output, or source bodies.
Outcome classification remains an execution/reporting concern rather than
project authority.

Language tooling consumes this protocol rather than parsing `Baton.toml`,
`Baton.lock`, private inventory, or processor responses. Aggregate workspace
plans retain package boundaries and direct edge categories; they do not invent
one runtime entry or flatten members into mutual dependencies.

The project document supplies source scopes and package graphs to compiler-backed
tooling. It does not duplicate compiler test facts. Low-level and behavioral
tests are unified by `doriac` metadata schema 3, and Baton consumes that test
table without reading source bodies or inferring declarations from paths.
