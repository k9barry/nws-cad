# Documentation Index

## Core Documentation

| Document | Description |
|----------|-------------|
| [API.md](API.md) | REST API reference |
| [DASHBOARD.md](DASHBOARD.md) | Dashboard user guide (desktop + mobile) |
| [NOTIFICATIONS.md](NOTIFICATIONS.md) | Notification channels: ntfy, Pushover, webhook |
| [PIPELINE.md](PIPELINE.md) | End-to-end message pipeline runbook (XML → notification) |
| [TESTING.md](TESTING.md) | Testing infrastructure guide |
| [TROUBLESHOOTING.md](TROUBLESHOOTING.md) | Common issues and quick diagnostics |
| [BACKUP_GUIDE.md](BACKUP_GUIDE.md) | Database backup procedures |

## Quick Links

- **Main README:** [../README.md](../README.md)
- **Changelog:** [../CHANGELOG.md](../CHANGELOG.md)
- **License:** [../LICENSE](../LICENSE)
- **Design specs:** [superpowers/specs/](superpowers/specs/) — historical design rationale (frozen as written)

## Architecture Overview

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│ FileWatcher │────▶│  Database   │◀────│   REST API  │
│ (XML Parse) │     │(MySQL/PgSQL)│     │             │
└──────┬──────┘     └─────────────┘     └──────┬──────┘
       │                   ▲                   │
       │                   │                   ▼
       │            ┌──────┴───────┐    ┌─────────────┐
       │            │ notification │    │  Dashboard  │
       └───────────▶│   _outbox    │    │(Desktop +   │
            event   └──────┬───────┘    │  Mobile)    │
                           │            └─────────────┘
                  tick     │
                  ┌────────┘
                  ▼
       ┌──────────────────────┐
       │  ntfy / Pushover /   │
       │       webhook        │
       └──────────────────────┘
```

Trace any single message through the pipeline in [PIPELINE.md](PIPELINE.md).

## Component Summary

| Component | Description |
|-----------|-------------|
| API | Controllers for Calls, Units, Search, Stats, Logs, Notifications, Outbox, Health |
| File watcher | Single long-lived process: ingests XML + drives the outbox tick |
| Dashboard | Server-rendered HTML (desktop + mobile views) |
| Notifications | Per-channel transactional outbox; ntfy/Pushover/webhook channel implementations |
| Security | Trusted-proxy guard, identity resolution, URL/input validation, CORS, log scrubbing |
| Tests | Unit, integration, performance, security — strict coverage metadata via PHPUnit 10.5 |

---

**Last Updated:** 2026-05-12
