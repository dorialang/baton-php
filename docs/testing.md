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
system process. A panic, escaping checked Error, signal, or abnormal status
fails that test without preventing later tests from running. Tests are not
parallelized, retried, sandboxed, or loaded into Baton itself.

Successful output is hidden by default. Failed stdout and stderr are replayed
with the package, test, and exit status. `--show-output` replays streams for
successful tests too. Development sources, direct development dependencies,
and valid generated development sources are active only in the tested package's
test graph; dependency-owned development dependencies do not become visible.
