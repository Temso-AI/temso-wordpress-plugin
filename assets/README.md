# Bundled assets

Assets **shipped inside the plugin zip** (unlike `.wordpress-org/`, which is
directory-listing imagery only).

| File | Used by |
| --- | --- |
| `logo.svg` | Settings → Temso page header. Rendered at 28×28; square works best. Falls back to text-only if absent, so the plugin is fine without it. |

Drop the real Temso logo here as `logo.svg`. Keep it small/clean — no raster
bloat in the distributed package.
