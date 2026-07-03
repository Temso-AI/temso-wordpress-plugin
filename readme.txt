=== Temso AI ===
Contributors: temsoai
Tags: analytics, bots, crawlers, ai, logs
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stream front-end request logs from your WordPress origin to Temso so AI-crawler and bot traffic shows up in your dashboard.

== Description ==

Make AI SEO your next growth channel. Temso captures every request your WordPress origin serves over HTTP and streams it to Temso in the background. That's how Temso sees which AI crawlers (like GPTBot and ClaudeBot) and visitors reach your content, so you can track and grow how you get recommended across ChatGPT, Gemini, and other AI search engines.

No DNS changes, no extra infrastructure. Install, paste two values, and you're done.
* Captures all server-side requests: front-end, wp-admin, REST, AJAX, login, and xmlrpc. Bot classification happens in Temso.
* Non-blocking, batched delivery that adds no perceptible latency to your pages.
* No cookies, no JavaScript, and no client-side tracking.
* Visitor IPs are sent over TLS and hashed in Temso before storage. Raw IPs are never retained.
* WordPress Multisite supported. Activate and connect per site.

**Publishing (optional).** Temso can also publish content back to this site. You enable it from Temso: open Settings → Integrations → WordPress, generate a one-time setup link, and paste it into **Settings → Temso AI → Publishing** here. The plugin and Temso exchange a publish shared secret automatically — you never copy a secret by hand. Once connected, the plugin accepts signed, server-to-server requests from Temso and creates or updates WordPress posts — writing Yoast and Rank Math SEO metadata too when those plugins are installed. Publishing is authenticated by an HMAC signature over each request, is completely independent of request tracking and the ingest API key, and stays disabled until you connect it. The publish shared secret is stored only on this site.

**Publishing (optional).** Temso can also publish content back to this site. You enable it from Temso: open Settings → Integrations → WordPress, generate a one-time setup link, and paste it into **Settings → Temso AI → Publishing** here. The plugin and Temso exchange a publish shared secret automatically — you never copy a secret by hand. Once connected, the plugin accepts signed, server-to-server requests from Temso and creates or updates WordPress posts — writing Yoast and Rank Math SEO metadata too when those plugins are installed. Publishing is authenticated by an HMAC signature over each request, is completely independent of request tracking and the ingest API key, and stays disabled until you connect it. The publish shared secret is stored only on this site.

Learn more at [temso.ai](https://temso.ai/).

Requests served from full-page cache never reach PHP, so no origin plugin can capture them. This is expected and matches the documented CDN-cache limitation.

== Installation ==

1. Install and activate the plugin.
2. Open **Settings → Temso**.
3. Paste the **Ingest URL** and **API key** from your Temso project (Crawlers → Add source → WordPress).
4. Make sure **Tracking** is enabled and save.

To enable publishing (optional): in Temso open **Settings → Integrations → WordPress**, generate a one-time setup link, then paste it into the **Publishing** section of **Settings → Temso** and click **Connect publishing**. This is separate from traffic ingest — the ingest API key is never used for publishing.

== Privacy ==

This plugin sends the following per HTTP request that WordPress serves (front-end, wp-admin, REST, AJAX, login, xmlrpc) to the Temso API endpoint you configure: timestamp, request URL, HTTP method, response status, user agent, referer, and the visitor IP address. The IP address is transmitted over TLS and hashed by Temso before storage — it is never stored in raw form, and the plugin sets no cookies and adds no client-side tracking.

When publishing is configured, the plugin also receives data from Temso in the inbound direction: the article title, HTML body, slug, and SEO metadata (meta title, meta description, canonical URL) arrive in signed requests from Temso and are stored as WordPress post content and post meta on this site.

== Frequently Asked Questions ==

= Do I need a Temso account? =

Yes. The plugin is an interface to the Temso service — create a source under Crawlers → Add source → WordPress in your Temso project to get the Ingest URL and API key.

= Why are my counts lower than my own analytics? =

Requests served from a full-page cache (a caching plugin or a CDN) never reach PHP, so no origin plugin can see them. Temso measures cache misses; expect lower numbers than total traffic.

= What data leaves my site? =

Per request: timestamp, URL, HTTP method, response status, user agent, referer, and the visitor IP. The IP is sent over TLS and hashed by Temso before storage — never retained raw. No cookies, no JavaScript, no client-side tracking. See the Privacy section.

= Does it slow down my site? =

No. Delivery is batched and non-blocking — the visitor response is never delayed by the upload.

= Does it work on WordPress Multisite? =

Yes. Activate and connect it per site; each site uses its own Ingest URL and key.

== Changelog ==

= 1.0.1 =
* Fix: fix wordpress deploy

= 1.0.0 =
* Fix: enforce release
* Continuous Integration: add manual WordPress.org SVN deploy pipeline

= 0.4.6 =
* Fix: add sanitize for the key input in the admin panel

= 0.4.5 =
* Fix: point Plugin URI at the GitHub repo to differ from Author URI

= 0.4.4 =
* Fix: add Test connection button to verify Ingest URL and API key
* Fix: add Test connection button to verify Ingest URL and API key

= 0.4.3 =
* Fix: bump Tested up to 7.0 to match current WP release

= 0.4.2 =
* Fix: end wp-org strip markers with `.` for WPCS InlineComment rule
* Fix: strip self-updater references from the wordpress.org build

= 0.4.1 =
* Fix: ready readme.txt for .org review and auto-sync it from release-please

= 0.4.0 =
* Packaging: WordPress.org submission readiness — plugin headers, build pipeline, and uninstall flow finalized.

= 0.3.0 =
* Captures every server-side request (front-end, wp-admin, REST, AJAX, login, xmlrpc).
* Security hardening: stricter request-header sanitization, HTTPS-only ingest endpoint, bounded delivery buffer.

= 0.2.0 =
* Initial public release: request capture with batched, non-blocking background delivery, settings screen, and multisite support.

== Upgrade Notice ==

= 0.4.0 =
Packaging refresh for the WordPress.org launch. No behavior change.

= 0.3.0 =
Full server-side request coverage and security hardening. Recommended for all users.
