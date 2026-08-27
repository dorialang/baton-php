# Source Discovery

Baton discovers source from schema-2 namespace mappings once per command. It
does not parse Doria declarations; namespace/layout validation and all language
semantics remain compiler-owned.

The initial portable pattern language is deliberately small:

```text
*   zero or more non-separator characters
?   exactly one non-separator character
**  zero or more path segments
```

A file must match an include pattern, and any matching exclusion removes it.
Only regular `.doria` files enter handwritten inventory. Baton never rediscovers
its `build/` tree or `.git/`, and it does not skip arbitrary hidden directories.

Discovery canonicalizes mapping roots and files, validates every configured
path's exact case, follows only symlinks whose targets remain inside the project,
and rejects loops, duplicate canonical files, conflicting scopes, and ASCII
case-folded portable path collisions. Results are sorted by normalized
project-relative UTF-8 path with binary comparison, independent of mapping or
filesystem enumeration order.

`[autoload.namespaces]` creates main sources. `[autoload-dev.namespaces]`
creates development sources. Ordinary check/build/run plans include both
inventories but activate only `main`. The compiler therefore knows development
source identity without type-checking it in a production target.

Slice 1 also defines an internal validated generated-source input: generated
scope, main/development destination, one contents-or-existing-path source, and a
matching SHA-256 digest. Public manifests cannot declare generated roots, and
Slice 1 neither runs processors nor writes generated files. Slice 3 will use
this boundary without recursively treating output as handwritten source.
