# Shutdown (LESCO) — Complete PHP Service (Filesystem, Apache-Ready)

**Highlights**
- **Rich UI**: refreshed Bootstrap 5 interface with division-aware filtering, hero summary, responsive tables and interactive map
- **Admin console**: ingestion/probe dashboard, manual entry form, history/changelog viewers, quick stats and one-click storage backups
- **Forecast insights**: seven-day analytics with charts, top-division breakdowns, type mix and longest work to help teams plan ahead
- **Data safety**: daily history snapshots (`storage/history/YYYY-MM-DD.json`) plus append-only change log (`storage/changelog.ndjson`)
- **Exports everywhere**: CSV/ICS/PDF downloads from the search view and REST export endpoint (`api.php?route=export`)
- **Configurable sources**: manage official tables, PDF bulletins, Facebook PR posts, CCMS feeds and manual overrides via `storage/config.json`

## Quick Start
1) Upload to Apache/PHP 8.1+.
2) Make `storage/` writable by PHP.
3) Open `/public/admin.php` → Configure sources → **Fetch Latest Now**.
4) Use `/public/index.php` (Search, Map) and `/public/sources.php` (status).
5) Set cron: `php cron/ingest.php` every 10–15 minutes.

## API routes

| Route | Method | Description |
| --- | --- | --- |
| `api.php?route=schedule` | GET | Filterable shutdown list (`q`, `area`, `feeder`, `division`, `date`); empty filters return the next upcoming outages. |
| `api.php?route=ingest` | GET | Fetch all enabled sources, merge and persist schedule. |
| `api.php?route=probe` | GET | Dry run fetch with per-source counts/sample items. |
| `api.php?route=divisions` | GET | Return configured division names. |
| `api.php?route=forecast` | GET | Seven-day forecast with daily counts, duration totals, type mix and upcoming highlights. |
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
- **pdf**: link to the latest published bulletin PDF (auto-parsed)
- **manual**: local CSV overrides

You can add more URLs to `storage/config.json` without code changes.

## Reference sources

- Lahore Electric Supply Company planned shutdown listings (HTML tables and PDF bulletins): <https://www.lesco.gov.pk/shutdownschedule> (mirrored at <https://www.lesco.gov.pk/TBR>)
- PITC CCMS Feeder Details feed used by Pakistani power distribution companies: <https://ccms.pitc.com.pk/FeederDetails>
- PR LESCO public announcements and urgent notices: <https://www.facebook.com/PRLESCO/>
- Base cartography for the interactive map provided by OpenStreetMap contributors via Leaflet tiles: <https://www.openstreetmap.org>

## Testing

Run the lightweight test suite (no external dependencies required):

```bash
php tests/run.php
```
