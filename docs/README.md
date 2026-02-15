# Documentation Index

## Core Documentation

| Document | Description |
|----------|-------------|
| [API.md](API.md) | REST API reference (19 endpoints) |
| [DASHBOARD.md](DASHBOARD.md) | Dashboard user guide (desktop + mobile) |
| [TESTING.md](TESTING.md) | Testing infrastructure guide |
| [TROUBLESHOOTING.md](TROUBLESHOOTING.md) | Common issues and solutions |
| [BACKUP_GUIDE.md](BACKUP_GUIDE.md) | Database backup procedures |

## Quick Links

- **Main README:** [../README.md](../README.md)
- **Changelog:** [../CHANGELOG.md](../CHANGELOG.md)
- **License:** [../LICENSE](../LICENSE)

## Architecture Overview

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│ FileWatcher │────▶│  Database   │◀────│   REST API  │
│ (XML Parse) │     │(MySQL/PgSQL)│     │(19 endpoints│
└─────────────┘     └─────────────┘     └─────────────┘
                           ▲
                    ┌──────┴──────┐
                    │  Dashboard  │
                    │(Desktop+Mobile)│
                    └─────────────┘
```

## Component Summary

| Component | Files | Description |
|-----------|-------|-------------|
| API | 5 controllers | Calls, Units, Search, Stats, Logs |
| Dashboard | 2 views + 6 partials | Desktop and mobile interfaces |
| JavaScript | 9 modules | Dashboard, maps, charts, filters |
| Security | 3 classes | Input validation, rate limiting, headers |
| Tests | 4 suites, 142+ tests | Unit, integration, performance, security |

---

**Version:** 1.1.0 | **Last Updated:** 2026-02-15
