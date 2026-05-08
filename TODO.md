# TODO

Open work items not yet scheduled. Add new items at the top with a date.

## 2026-05-08

- **Auto-restart unhealthy containers.** Today, `docker-compose.yml` uses
  `restart: unless-stopped`, which only restarts containers on *exit*. If a
  container's healthcheck flips to `unhealthy` but the process keeps running
  (e.g. the watcher's heartbeat goes stale because of a deadlock, or the
  `mysql` healthcheck fails while mysqld lingers), nothing kicks it.
  Investigate adding `willfarrell/autoheal` (or equivalent) to the compose
  stack so any container with `autoheal=true` label gets restarted when
  Docker reports it unhealthy. Pair this with the existing watcher heartbeat
  and `/api/health` endpoint so a wedged process actually gets recovered
  without manual intervention.
