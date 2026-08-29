# Package Targets

Schema 2 supports one library and any number of named binaries. A package must
declare at least one target. The common one-binary package can use the
package-level shorthand; explicit target tables cannot be mixed with it.

```toml
[targets.library]
name = "blog"

[[targets.binary]]
name = "web"
entry = "src/web.doria"

[[targets.binary]]
name = "worker"
entry = "src/worker.doria"
```

Select targets with `--binary <name>` or `--library`. The selectors are mutually
exclusive. `check` and `build` auto-select only when the package has exactly one
target. `run` auto-selects only when there is exactly one binary; it never runs a
library. Ambiguous diagnostics list binaries and the library separately. Baton
does not provide `--target` or a manifest default-target field.

Each selected target has its own
`build/<host-target>/<profile>/<target-name>/` directory. Binary builds compile
the selected entry and name the executable after the target. Library builds run
the compiler checker, write a receipt with `artifact: null`, and produce no
archive until Doria defines a public native library format.

All configured binary entries are reserved. A binary plan marks only its
selected entry as `entry` and excludes other binary entries. A library plan
excludes every binary entry. Shared autoload sources remain available to all
targets.

A package consumed as a dependency must use schema 2 and declare a library
target. Baton never infers a library from a binary. Dependency build plans select
that library, include its main autoload sources, and exclude every binary entry
and development source.

In a workspace, select the owning member with `--package` before applying
`--binary` or `--library`. Aggregate `build` and `run` are intentionally absent;
aggregate `check`, `test`, `tree`, and `project` preserve package boundaries.
