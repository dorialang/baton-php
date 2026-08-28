# Compiler Build Plans

For schema 2, Baton converts the selected package target and deterministic source
inventory into compiler build-plan schema 1. `doriac` does not read
`Baton.toml`, discover files, or resolve packages.

Plans contain the edition, compiler package identity, selected target, active
scopes, canonical machine-local package root, normalized namespace mappings,
stable source identities, direct normal dependency edges, and compiler profile.
Every reachable package has its own root, namespace mappings, main source
inventory, and direct edges. Dependency binary and development sources are not
active compiler inputs. Local manifest names use the
synthetic `local/<name>` compiler identity. Source identities use
`<compiler-package>:<package-relative-path>`. Package roots are canonical
machine-local inputs; source identities and paths remain package-relative.

Baton writes deterministic pretty JSON with fixed field order, binary-sorted
mappings and sources, and a trailing newline. It writes through a complete
same-directory temporary file and records SHA-256 over the exact final bytes.

Schema-2 commands use:

```text
doriac check --build-plan <managed-plan>
doriac compile --build-plan <managed-plan> --out <artifact>
```

The plan lives at
`build/<host-target>/<profile>/<target-name>/build-plan.json`. `check` writes no
receipt. A successful managed `build` writes versioned `build.json` containing
manifest and compiler identities, target, profile, exact compiler commit, plan
hash, exact lock identity, deterministic live path-dependency content facts, and
binary hash or `artifact: null` for a library. Receipts contain only relative
managed paths and no project, compiler, runtime, cache, or environment paths.

Schema 1 deliberately retains direct entry-file invocation and its historical
layout. This separation is compatibility behavior, not an internal shortcut.
