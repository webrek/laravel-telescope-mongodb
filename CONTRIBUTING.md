# Contributing

Thanks for taking the time to improve this package. Bug fixes, watchers
that haven't been exercised yet, and documentation patches are all
welcome.

## Workflow

1. Fork and clone the repository.
2. Build the containerised toolchain once:
   ```bash
   make build
   make install
   ```
3. Create a branch off `main`.
4. Make your change. Add or update tests under `tests/Unit` or
   `tests/Feature`.
5. Run the full quality gate:
   ```bash
   make pint
   make stan
   make test
   ```
6. If your change is user-visible, add a line under the `[Unreleased]`
   section of `CHANGELOG.md`.
7. Open a pull request. The PR template will walk you through the
   checklist the maintainers expect.

## Local environment

Everything runs inside Docker — you do not need PHP, MongoDB, or
Composer on your host. See the [README](README.md#testing) for the full
list of `make` targets.

For an end-to-end smoke test against a real Laravel app:

```bash
make playground
make playground-up
```

## Style

- Code style is enforced by `vendor/bin/pint` with the configuration in
  `pint.json`. The CI build will fail otherwise.
- Static analysis runs at PHPStan level 6 with Larastan. Fix the finding
  rather than adding an ignore unless the analyser is genuinely wrong.
- Prefer integration tests that exercise the public Telescope contracts
  over unit tests that mock our own internals.

## Reporting issues

Use the issue templates. For security problems, follow
[`SECURITY.md`](SECURITY.md) instead of opening a public issue.
