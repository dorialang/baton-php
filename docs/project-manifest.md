# Project Manifest

Every Baton project is rooted by a `Baton.toml` file. Baton searches the current directory and each parent directory until it finds that file.

## Schema 1

```toml
manifest-version = 1

[package]
name = "hello-doria"
version = "0.1.0"
kind = "binary"
entry = "src/main.doria"
```

All five fields are required.

| Field | Type | Rule |
| --- | --- | --- |
| `manifest-version` | integer | Must be `1` |
| `package.name` | string | Lowercase letters, digits, `-`, and `_`; must begin and end with a letter or digit |
| `package.version` | string | A SemVer package version |
| `package.kind` | string | `binary` |
| `package.entry` | string | A project-relative path contained by the project root |

Examples of valid package names include `hello`, `hello-doria`, and `tool_2`. Names such as `Hello`, `-tool`, and `tool/cli` are invalid.

## Entry paths

The entry path is resolved from the directory containing `Baton.toml`, not from the caller's current subdirectory.

Entry paths must:

- be relative;
- remain inside the project root;
- avoid `..` path segments;
- use an explicit source filename.

Absolute Windows and Unix paths are rejected.

## Version domains

`package.version` is the version of the project and uses SemVer:

```text
1.4.2
2.0.0-rc.1
```

The Doria toolchain uses zero-padded CalVer:

```text
2026.03.1
2026.03.1-canary
```

These values have different meanings and are never compared.

## Generated project

`baton new hello-doria` creates the manifest, `src/main.doria`, and project ignore rules as one operation. It refuses to overwrite an existing destination.

Project build output belongs under:

```text
build/<host-target>/development/
build/<host-target>/release/
```

`toolchain.json` never belongs in a Doria project. It is installed-toolchain metadata, not `Baton.toml` and not a package lockfile.
