# NNTmux for Unraid

Single-container build of [NNTmux](https://github.com/NNTmux/newznab-tmux). The upstream app image is built as-is, then MariaDB, Redis and Manticore are added and everything runs under s6-overlay.

| service   | what                                                     |
|-----------|----------------------------------------------------------|
| mariadb   | MariaDB 11.4, bound to 127.0.0.1                         |
| redis     | cache, sessions, Horizon queues                          |
| manticore | search engine                                            |
| web       | FrankenPHP on port 80                                    |
| horizon   | queue workers                                            |
| scheduler | `artisan schedule:work`                                  |
| indexer   | tmux processing engine, `START_INDEXER=false` to skip it |

Tips and lessons learned (PreDB, hashed names, threads, backfill): [unraid-notes/NNTmux](https://github.com/RapidMark/unraid-notes/tree/main/NNTmux)

## Volumes

- `/config` holds `.env`, MariaDB, Redis, Manticore, Laravel storage and the install lock.
- `/data` holds `nzb/` and `covers/`, the parts that grow.

## First run

The container refuses to start without `ADMIN_USER`, `ADMIN_EMAIL`, `ADMIN_PASS` (12+ chars), `NNTP_SERVER`, `NNTP_USERNAME` and `NNTP_PASSWORD`.
It then writes `/config/.env` with an `APP_KEY` and a random DB password, creates the database and admin user, builds the Manticore tables and drops `/config/install/install.lock`.
Expect a few minutes.

Container environment variables always win over `/config/.env`, so the Unraid template drives the common settings and `.env` holds the rest.
Edit `.env` for API keys or anything the template does not expose, then restart.

## Unraid

Install from Community Apps, or add a container with template URL `https://raw.githubusercontent.com/RapidMark/unraid-templates/main/templates/nntmux.xml`.
The template passes `--stop-timeout 120` so MariaDB gets a clean shutdown.

## Building

```
cd unraid
make build     # nntmux-app:build from ../Dockerfile, then rapidmark/nntmux:dev
make lint
```

CI builds on pushes touching `unraid/` and when the weekly check finds something new, then pushes `rapidmark/nntmux:latest`, `:bookworm_<date>` and `:sha-<short>`.
The weekly check (Friday 18:00 UTC) merges upstream, compares the base image and looks for security updates; it only builds if one of them changed.
`DOCKERHUB.md` is the Docker Hub page and is published on its own when it changes.
Needs the `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` repo secrets.

## Updating upstream

The weekly check merges upstream automatically. To do it by hand, run the **Weekly update check** workflow, or:

```
git fetch upstream
git merge upstream/master
git push
```

The image is whatever commit master is at, so the merge is the pin.
