# Continuous deployment (GitHub Actions → self-hosted host)

`.github/workflows/deploy.yml` deploys the stack to your production host after
every release, gated by a manual approval. It is a thin trigger around
`scripts/deploy.sh` — the same script you can run by hand on the host.

## How it works

```
push to main ──▶ Release workflow ──▶ cuts vX.Y.Z tag + GitHub Release
                                          │  (workflow_run: "Release" completed)
                                          ▼
                                   Deploy ▸ gate job  (github-hosted, no approval)
                                          │  release commit at tip of main? → yes
                                          ▼
                                   Deploy ▸ deploy job (self-hosted, ON the host)
                                          │  environment: production → waits for your ⏸ Approve
                                          ▼
                                   scripts/deploy.sh vX.Y.Z in $DEPLOY_DIR
                                   backup → checkout → build → migrate → up → verify
                                          │  any failure → automatic rollback
                                          ▼
                                   stack now running vX.Y.Z
```

**Why `workflow_run` and not `release: published`?** The Release workflow
creates the tag and Release using `GITHUB_TOKEN`, and GitHub deliberately does
**not** fire `push:tags` or `release:published` from `GITHUB_TOKEN` activity
(the anti-recursion rule). `workflow_run` fires on the *Release workflow
finishing*, independent of the token, so no personal access token is needed.

**Why the `gate` job?** `workflow_run` fires after *every* push to main,
including no-release pushes (docs, chores). The github-hosted `gate` job checks
whether a version was actually cut — the tip of `main` is a
`chore(release): vX.Y.Z` commit — and only then lets the gated `deploy` job run.
This keeps the approval prompt from firing on pushes that produced no release.

## One-time setup

### 1. Register a self-hosted runner on the host

The `deploy` job runs `runs-on: [self-hosted, nws-cad]`, so the runner needs the
`nws-cad` label. On the production host:

1. Repo → **Settings → Actions → Runners → New self-hosted runner**. Follow the
   download/config steps GitHub shows.
2. When prompted for labels, add **`nws-cad`** (the `self-hosted` label is
   automatic).
3. Run it as a service so it survives reboots:
   ```bash
   sudo ./svc.sh install
   sudo ./svc.sh start
   ```

**Runner user prerequisites:**
- Can run Docker without sudo — add it to the `docker` group
  (`sudo usermod -aG docker <runner-user>`) and re-login.
- Owns (or can read/write) the deploy directory and its `var/` tree.
- The deploy directory is a **git clone with `origin` set to this repo** and can
  `git fetch` (the runner's own GitHub auth does not extend to the clone — use a
  read-only deploy key or HTTPS credentials on the host if the repo is private).

### 2. Create the `production` Environment (the approval gate)

Repo → **Settings → Environments → New environment → `production`**. Under
**Deployment protection rules**, enable **Required reviewers** and add yourself.
Now each deploy pauses in the Actions tab until you click **Approve**.

> Prefer fully-automatic deploys instead? Delete the required-reviewer rule (or
> remove `environment: production` from the `deploy` job). Everything else works
> unchanged.

### 3. Point CD at the on-host clone (`DEPLOY_DIR`)

Set a **variable** (not a secret) named `DEPLOY_DIR` to the absolute path of the
clone on the host — e.g. `/opt/nws-cad`. Either scope works:
- Repo-wide: **Settings → Secrets and variables → Actions → Variables → New**.
- Or scope it to the environment: **Settings → Environments → production →
  Environment variables**.

The self-hosted runner's own checkout directory is **not** the deploy directory
— it has no `.env` and no `var/data`. The workflow deliberately ignores it and
operates `$DEPLOY_DIR` instead, so your real config and database volumes are
used.

## Running it

- **Automatic:** merge a releasable PR to `main`. Release cuts the tag, Deploy's
  gate approves, and (with the gate on) the deploy waits for your **Approve** in
  the Actions tab, then deploys.
- **Manual / on-demand:** Actions → **Deploy → Run workflow**. Leave *ref* blank
  for the latest tag, or type a specific tag.
- **Rollback:** Actions → **Deploy → Run workflow**, set *ref* to the previous
  good tag (e.g. `v2.0.3`) and *force* = true. `deploy.sh` checks that tag out,
  rebuilds, and brings the stack up. (Note: migrations are additive/idempotent
  and are **not** reversed — restore a DB backup if a schema rollback is needed;
  see `docs/BACKUP_GUIDE.md`.)

## Host fallback — run the deploy by hand

CD just wraps the script; you can always deploy directly on the host:

```bash
cd /opt/nws-cad
scripts/deploy.sh              # latest v* tag
scripts/deploy.sh v2.0.4       # a specific tag
FORCE=1 scripts/deploy.sh v2.0.3   # redeploy / roll back to a tag
```

`deploy.sh` automates `docs/deployment/RUNBOOK.md`: it fetches tags, backs up
the DB (if running), checks out the target tag, builds, brings the DB up and
waits for readiness, applies `database/migrations/*` for the active `DB_TYPE`,
recreates the stack, and verifies `/api/health` — rolling back to the previous
commit if any step fails. It is idempotent (no-op if already on the target,
unless `FORCE=1`).

## Security notes

- No SSH exposure and no long-lived deploy keys in GitHub: the runner lives on
  the host and pulls work from GitHub over its outbound connection.
- The `production` environment scopes any deploy-only secrets/variables and the
  approval gate to production alone.
- Keep the runner user's Docker access in mind — membership in the `docker`
  group is root-equivalent on that host. Use a dedicated, least-privilege
  account for the runner.
