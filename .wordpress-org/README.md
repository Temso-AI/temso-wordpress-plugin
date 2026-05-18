# WordPress.org directory assets

These are the **directory listing** images (icon, banner, screenshots). They
are NOT bundled in the plugin zip — at release they go into the SVN `/assets/`
folder (sibling of `/trunk/`), conventionally mirrored from this
`.wordpress-org/` directory by the deploy step.

Drop the real Temso brand files here with these exact names/sizes:

| File | Size | Purpose |
| --- | --- | --- |
| `icon-256x256.png` | 256×256 | Plugin icon (search results, plugin card). `icon.svg` also accepted. |
| `icon-128x128.png` | 128×128 | Fallback icon for older WP. |
| `banner-772x250.png` | 772×250 | Header banner on the plugin page. |
| `banner-1544x500.png` | 1544×500 | Retina banner (optional but recommended). |
| `screenshot-1.png` | any | Settings → Temso page. Order matches `== Screenshots ==` in `readme.txt`. |
| `screenshot-2.png` | any | (optional) Crawler data showing up in the Temso dashboard. |

PNG or JPG. Use the official Temso logo/brand colors — do not improvise these.
