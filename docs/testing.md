# Testing

`baton test` discovers the compiler's unified test records from `doriac metadata
--schema-version 3`; Baton never parses Doria source. The table contains both
compiler-known `#[Test]` functions and behavioral `describe`/`it`/`test`
declarations. Package-internal test functions and compiler-generated behavioral
test bodies are directly callable within the tested package.

```console
baton test
baton test --filter user
baton test --show-output
baton test --workspace
```

Filtering is a case-sensitive substring match over compiler-provided display
names, including nested suite paths. With no filter, zero tests succeeds. An
explicit filter matching no tests is a diagnostic.

Baton generates one deterministic development entry dispatcher and compiles it
once per package and profile. Dispatcher branches use stable compiler test
identities and call exact compiler-provided callable names; Baton never invents
or reflects over test symbols. Each selected test then runs in a fresh operating
system process. A panic, escaping checked Error, failed expectation, signal,
timeout, output limit, or abnormal status fails that test without preventing
later tests from running. Tests are not parallelized, retried, sandboxed, or
loaded into Baton itself.

Successful output is hidden by default. Failed stdout and stderr are replayed
with the package, test, and exit status. `--show-output` replays streams for
successful tests too. Development sources, direct development dependencies,
and valid generated development sources are active only in the tested package's
test graph; dependency-owned development dependencies do not become visible.

Behavioral declarations, fluent `expect`/`fail` assertions, matcher semantics,
bounded differences, and assertion effects remain compiler-owned. Baton does
not parse Doria source, stderr, or compiler diagnostics to identify outcomes.
Each isolated process receives one nonce-bearing managed outcome path through
all three runtime variables. Baton strictly validates DORIAO2 panic, DORIAO3
checked-Error, and DORIAO4 assertion records, rejects unknown or malformed
transport as infrastructure failure, and removes the record after decoding.
Metadata schema 3 remains unchanged.

Every selected test is classified into exactly one category: `Passed`,
`Assertion Failed`, `Unexpected Checked Error`, `Fatal Panic`, or `Abnormal
Process Failure`. Failed stdout and stderr remain separate and are replayed only
after the structured detail by default. Compiler-provided suite identities form
the hierarchy, source metadata order is preserved, and generated callable names
are never presented. Filtering remains a case-sensitive substring over the full
compiler-provided display name; an explicit miss reports `No Tests Match The
Filter` and exits nonzero.

The Native Testing Foundation is complete. Collection expectations and typed
checked-Error `toThrow` expectations use the same compiler-owned facts and
structured reporting boundary. Stage 34 single class inheritance is next.
