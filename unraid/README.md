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

Every start runs `artisan migrate`, so updates that change the database apply themselves.

Container environment variables always win over `/config/.env`, so the Unraid template drives the common settings and `.env` holds the rest.
Edit `.env` for API keys or anything the template does not expose, then restart.

## Unraid

Install from Community Apps, or add a container with template URL `https://raw.githubusercontent.com/RapidMark/unraid-templates/main/templates/nntmux.xml`.
MariaDB stops in seconds: it writes its redo log and replays it on the next start, while startup waits for it.
Unraid stops containers with its own Docker timeout (10 s by default), not the template's `--stop-timeout`.

### Database on a btrfs pool

On btrfs, copy-on-write turns every MariaDB page write into a new extent, so a large database
flushes slowly and writes stall. A new install turns copy-on-write off for `/config/mariadb`
when `/config` is mapped to the pool itself (`/mnt/cache/appdata/nntmux`, or an exclusive share).
Through `/mnt/user/...` the FUSE layer does not allow it.

To convert an existing database, stop the container, copy the folder into one with
copy-on-write off and swap it in (needs free space for a second copy):

```
cd /mnt/cache/appdata/nntmux
mkdir mariadb.nocow && chattr +C mariadb.nocow
chown --reference=mariadb mariadb.nocow && chmod --reference=mariadb mariadb.nocow
cp -a --reflink=never mariadb/. mariadb.nocow/
mv mariadb mariadb.old && mv mariadb.nocow mariadb
```

Start the container, and delete `mariadb.old` once it runs cleanly. `lsattr -d mariadb` shows `C` when it worked.
`DB_INNODB_LOG_FILE_SIZE` (default 4G) sets the redo log; a bigger log rides out write bursts.

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
