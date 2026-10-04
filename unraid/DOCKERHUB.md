# NNTmux

[NNTmux](https://github.com/NNTmux/newznab-tmux) Usenet indexer in one container: web UI, MariaDB, Redis, Manticore, queue workers and the tmux indexer. Fill in your Usenet server and an admin login, and it sets itself up on first run.

Made for Unraid (search **NNTmux** in Community Apps), works anywhere Docker runs.

## Quick start

```
docker run -d --name NNTmux \
  -p 8085:80 \
  -v /path/to/appdata:/config \
  -v /path/to/nzb-data:/data \
  -e APP_URL=http://your-server:8085 \
  -e NNTP_SERVER=news.example.com -e NNTP_PORT=563 -e NNTP_SSLENABLED=true \
  -e NNTP_USERNAME=you -e NNTP_PASSWORD=secret -e NNTP_CONNECTIONS=10 \
  -e ADMIN_USER=admin -e ADMIN_EMAIL=you@example.com -e ADMIN_PASS=at-least-12-chars \
  -e SCRAPE_IRC_USERNAME=yourname_k7q2x \
  --stop-timeout 120 \
  rapidmark/nntmux:latest
```

First start takes a few minutes. Then open port 8085 and log in.

## Volumes

| Path | What |
|---|---|
| `/config` | Settings, database, Redis and search index. Keep it on fast storage. |
| `/data` | NZBs and covers. Grows large. |

## Settings

| Variable | Default | What |
|---|---|---|
| `NNTP_SERVER`, `NNTP_PORT`, `NNTP_SSLENABLED`, `NNTP_USERNAME`, `NNTP_PASSWORD` | | Your Usenet provider. Required. |
| `NNTP_CONNECTIONS` | `10` | Connections NNTmux may use. Leave some for your downloader. |
| `ADMIN_USER`, `ADMIN_EMAIL`, `ADMIN_PASS` | | Admin account, created on first run. Password 12+ characters. |
| `APP_URL` | | The URL you use to reach the site. |
| `SCRAPE_IRC_USERNAME` | | Turns on the PreDB IRC scraper. Must be unique, add a few random characters. |
| `DB_INNODB_BUFFER_POOL_SIZE` | `1G` | Database memory. Set to about half your spare RAM. |
| `MIN_RELEASE_SIZE_MB` | `50` | Skips tiny junk fragments of obfuscated posts. |
| `CHECK_PASSWORDED_RARS` | `true` | Looks inside releases so hashed names get renamed. |
| `ADD_PAR2` | `true` | Uses PAR2 file names to rename releases. |
| `FIX_NAMES` | `true` | Renames releases from NFOs, PAR2 and file names. |
| `PULSE_ENABLED` | `false` | Laravel Pulse. Grows the database fast. |
| `START_INDEXER` | `true` | `false` runs only the web UI. |
| `TMDB_APIKEY`, `TVDB_APIKEY`, `OMDB_APIKEY`, `FANARTTV_APIKEY`, `TRAKTTV_APIKEY` | | Optional metadata keys. |
| `TZ`, `PUID`, `PGID` | `Etc/UTC`, `99`, `100` | Timezone and the user files are written as. |

## Tips

Getting real names for hashed posts, filling the PreDB, threads and more: [unraid-notes/NNTmux](https://github.com/RapidMark/unraid-notes/tree/main/NNTmux)

## Tags

`latest`, `sha-<commit>`, `bookworm_<date>`. A new image is built when NNTmux releases changes or the base image gets security updates.

## Links

- Source: [RapidMark/NNTmux](https://github.com/RapidMark/NNTmux) (`unraid/`)
- Unraid template: [RapidMark/unraid-templates](https://github.com/RapidMark/unraid-templates)
- Issues: [RapidMark/NNTmux/issues](https://github.com/RapidMark/NNTmux/issues)
