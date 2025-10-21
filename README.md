# Shutdown (LESCO) — Complete PHP Service (Filesystem, Apache-Ready)

**Highlights**
- **Rich UI**: refreshed Bootstrap 5 interface with division-aware filtering, hero summary, responsive tables and interactive map
- **Admin console**: ingestion/probe dashboard, manual entry form, history/changelog viewers, quick stats and one-click storage backups
- **Data safety**: daily history snapshots (`storage/history/YYYY-MM-DD.json`) plus append-only change log (`storage/changelog.ndjson`)
- **Exports everywhere**: CSV/ICS/PDF downloads from the search view and REST export endpoint (`api.php?route=export`)
- **Configurable sources**: manage official, Facebook, CCMS and manual feeds via `storage/config.json`

## Quick Start
1) Upload to Apache/PHP 8.1+.
2) Make `storage/` writable by PHP.
3) Open `/public/admin.php` → Configure sources → **Fetch Latest Now**.
4) Use `/public/index.php` (Search, Map) and `/public/sources.php` (status).
5) Set cron: `php cron/ingest.php` every 10–15 minutes.

## API routes

| Route | Method | Description |
| --- | --- | --- |
| `api.php?route=schedule` | GET | Filterable shutdown list (`q`, `area`, `feeder`, `division`, `date`). |
| `api.php?route=ingest` | GET | Fetch all enabled sources, merge and persist schedule. |
| `api.php?route=probe` | GET | Dry run fetch with per-source counts/sample items. |
| `api.php?route=divisions` | GET | Return configured division names. |
| `api.php?route=export&format=csv` | GET | Export filtered schedule as CSV (`ics`/`json` also supported). |
| `api.php?route=addManual` | POST | Append a single manual CSV entry. |
| `api.php?route=history&day=YYYY-MM-DD` | GET | Retrieve historical snapshot for a day. |
| `api.php?route=changelog` | GET | Recent change log entries. |
| `api.php?route=config` | GET/POST | Read or update `config.json`. |
| `api.php?route=backup` | GET | Download ZIP of the `storage/` directory. |

## Sources (toggle in config.json)
- **official**: LESCO shutdown table (HTML)
- **facebook**: PR LESCO page parser (simple HTML text scrape; optional)
- **ccms**: PITC CCMS schedule feed (JSON/HTML, optional but recommended)
- **manual**: local CSV overrides

You can add more URLs to `storage/config.json` without code changes.

## Testing

Run the lightweight test suite (no external dependencies required):

```bash
php tests/run.php
```
