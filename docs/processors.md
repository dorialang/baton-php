# Attribute Processors

Processors are schema-2 package dependencies declared explicitly in
`[processors]`:

```toml
[processors]
"acme/routes" = {
    source = "git",
    url = "https://github.com/acme/routes.git",
    tag = "v1.2.0",
    binary = "routes",
    attributes = ["Acme\\Metadata\\Route"],
}
```

Path processors use `source = "path"` and `path`. Processor packages use the
normal resolver, lock, exact Git cache, and one-version rules, but are not Doria
source dependencies. Their selected native binary target is compiled by
`doriac` with normal dependencies only; processors cannot declare processors or
workspaces and do not activate development dependencies.

## Execution And Security

The declaration authorizes Baton to execute arbitrary native code with the
user's account authority. Baton does not provide a processor sandbox,
filesystem isolation, network isolation, or a permission prompt.

Baton obtains typed applications from compiler metadata schema 2, filters by
owning package and exact declared attribute, sends processor protocol version 1
JSON on stdin, reads response JSON from stdout, and labels stderr as log output.
Processes use an argument vector, sanitized bounded environment, 300-second
timeout, 64 MiB response limit, and 16 MiB retained stderr limit.

## Output And Caching

Validated UTF-8 Doria output is atomically published under:

```text
build/generated/<processor-package>/<main|development>/
```

Paths cannot escape, collide by case, overwrite handwritten source, or replace
another processor's output. A successful empty result removes that processor's
stale output. Generated files enter the final compiler plan, receipts, private
inventory, and project document, but never trigger a recursive processor pass.

The exact cache key includes protocol, compiler revision, graph fingerprint,
processor source and binary identity, attribute filter, and request bytes.
Offline mode never builds or executes a processor; it accepts only an exact,
complete cached result and otherwise fails rather than exposing stale output.
