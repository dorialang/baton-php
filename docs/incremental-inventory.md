# Incremental Inventory

Baton stores disposable private state at:

```text
build/.baton/inventory.json
```

Schema 1 records Baton and compiler revisions, workspace and member manifest
fingerprints, lock identity, selected package/target/profile facts, exact source
paths and SHA-256 hashes, generated-source provenance, metadata and test
inventory hashes, processor request/response and binary identities, build-plan
hashes, and successful output provenance.

Correctness never depends on timestamps alone. Content hashes invalidate reuse
after manifest, lock, member, source, dependency, processor, generated output,
compiler, target, profile, or selection changes, including same-size source
edits. Timestamps and sizes may only be hints. The file is written atomically,
contains no source bodies, is uncommitted and safely deletable, and is not a
package lock, compiler plan, public receipt, daemon database, or public package
contract. Missing or corrupt private inventory is rebuilt safely.
