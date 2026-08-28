# Dependency Cache

Git dependencies use one user-scoped, content-addressed cache. Doria projects do
not receive a `vendor/` directory.

| Platform | Default root |
| --- | --- |
| Linux | `$XDG_CACHE_HOME/doria/baton`, otherwise `$HOME/.cache/doria/baton` |
| macOS | `$HOME/Library/Caches/Doria/Baton` |
| Windows | `%LOCALAPPDATA%\Doria\Baton\Cache` |

Mirrors are keyed by canonical Git URL. Immutable checkouts are keyed by URL and
exact commit, carry an integrity marker, and are reused without network access.
Publication and concurrent writers are lock-protected and atomic. Incomplete or
corrupt entries fail offline; online operation may rebuild them from Git.

Git runs without interactive prompts, user/system configuration, hooks, smudge
filters, or submodules. Cache paths are derived from hashes rather than package
or selector text, and symlink boundaries cannot redirect cache writes.

Path dependencies stay at their declared live locations and never enter this
cache. Slice 2 adds no cache pruning or project-local package store.
