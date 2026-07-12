# TODO

Open work items not yet scheduled. Add new items at the top with a date.

_No open items._

## Done

- **Auto-restart unhealthy containers** (2026-05-08 → done 2026-07-12). Added a
  `willfarrell/autoheal` sidecar to `docker-compose.yml` that restarts any
  container labeled `autoheal=true` (`app`, `api`, `mysql`, `postgres`) when
  Docker reports its healthcheck unhealthy. This covers wedged processes that
  don't exit (stale watcher heartbeat, hung DB ping), which
  `restart: unless-stopped` alone misses.
