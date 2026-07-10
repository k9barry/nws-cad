# Copilot Instructions for NWS CAD Project

This project keeps a single source of truth for conventions, architecture, commands,
and the PR/release workflow in [`CLAUDE.md`](../CLAUDE.md) at the repository root.

**Please read [`CLAUDE.md`](../CLAUDE.md) for all development guidance**, including:

- Common commands (tests, watcher, Docker stack, notifications CLI)
- Architecture (HTTP entry points, core classes, controller pattern, notifications pipeline)
- Cross-DB SQL rules and the three schema files that must stay in sync
- Coding conventions (strict types, prepared statements, XXE protection, DTO mapping)
- Test conventions that are load-bearing for CI (strict coverage metadata, `@covers`/`@uses`)
- The conventional-commit release policy and the qodo PR review protocol
- Key environment variables

This file intentionally stays thin to avoid drift; do not duplicate `CLAUDE.md` content here.
