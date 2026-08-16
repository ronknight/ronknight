# Self-hosted visitor counter

A single-file PHP visitor counter that renders a shields-style SVG badge.
File-based storage (JSON + `flock`), no database required.

## Deploy

1. Upload the `counter/` folder to any PHP host (PHP 7.4+).
2. Make sure the folder is writable by the web server (it creates
   `counter_data.json` on first hit):
   ```bash
   chmod 775 counter
   ```
3. Test in a browser:
   `https://your-host.example/counter/counter.php?page=profile`

## Embed in the profile README

```markdown
![Profile views](https://your-host.example/counter/counter.php?page=profile&label=Profile%20views)
```

## Options

| Param   | Default         | Notes                                  |
|---------|-----------------|----------------------------------------|
| `page`  | `profile`       | Separate counters per page/repo        |
| `label` | `Profile views` | Badge label, URL-encoded, max 40 chars |
| `color` | `2ea44f`        | Hex color of the count side, no `#`    |

Every distinct `page` value keeps its own count in `counter_data.json`,
so one deployment can serve badges for many repos.

---

# Self-hosted GitHub stats cards

`stats.php` replaces the dead github-readme-stats cards with SVGs rendered
from live GitHub API data, including private-repo counts.

## Deploy

1. Copy `config.sample.php` to `config.php` and paste a **fine-grained PAT**
   with **Metadata: Read-only** on **all repositories** (no other permission).
2. Upload `stats.php` and `config.php` next to `counter.php`.
3. Test: `https://your-host.example/stats.php?card=stats` and `?card=langs`.

## Embed

```markdown
![](https://your-host.example/stats.php?card=stats)
![](https://your-host.example/stats.php?card=langs)
```

## Behavior

- Stats are computed from the GitHub API and cached in `stats_cache.json`
  for one hour, so cards load fast and never hit rate limits.
- If the token expires or the API is down, the last cached numbers are
  served indefinitely (with a small "cached" note) — the images never break.
  To renew, paste a fresh token into `config.php`; that's the whole rotation.
