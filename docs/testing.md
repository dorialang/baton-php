# Testing

`baton test` discovers executable tests from `doriac metadata --schema-version
2`; Baton never parses Doria source. An executable test is a compiler-known
`#[Test]` application on a zero-parameter, non-generic top-level function whose
return type is exactly `void`. Package-internal test functions are valid.

```console
baton test
baton test --filter user
baton test --show-output
baton test --workspace
```

Filtering is a case-sensitive substring match over canonical function names.
With no filter, zero tests succeeds. An explicit filter matching no tests is a
diagnostic.

Baton generates one deterministic development entry dispatcher and compiles it
once per package and profile. Each selected test then runs in a fresh operating
system process. A panic, escaping checked Error, signal, or abnormal status
fails that test without preventing later tests from running. Tests are not
parallelized, retried, sandboxed, or loaded into Baton itself.

Successful output is hidden by default. Failed stdout and stderr are replayed
with the package, test, and exit status. `--show-output` replays streams for
successful tests too. Development sources, direct development dependencies,
and valid generated development sources are active only in the tested package's
test graph; dependency-owned development dependencies do not become visible.
