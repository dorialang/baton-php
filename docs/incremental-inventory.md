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

Test inventory entries retain the stable test identity, display name, suite path,
origin, authored spelling, compiler callable identity and canonical name, source,
and byte start. Display and dispatch identities remain separate; the inventory is
never used to rediscover a test from Doria source.

Core Slice-2 assertions add no inventory fields. Assertion effects and matcher
plans remain compiler-internal, while an escaping assertion is handled through
the existing process exit and raw-output boundary. Metadata schema 3 and test
inventory schema 1 remain exact; Slice 3 classification must not become private
inventory authority.

Correctness never depends on timestamps alone. Content hashes invalidate reuse
after manifest, lock, member, source, dependency, processor, generated output,
compiler, target, profile, or selection changes, including same-size source
edits. Timestamps and sizes may only be hints. The file is written atomically,
contains no source bodies, is uncommitted and safely deletable, and is not a
package lock, compiler plan, public receipt, daemon database, or public package
contract. Missing or corrupt private inventory is rebuilt safely.
