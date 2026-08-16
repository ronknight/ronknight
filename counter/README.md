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
