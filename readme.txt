=== Temso AI ===
Contributors: temso
Tags: analytics, bots, crawlers, ai, logs
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
<!-- x-release-please-start-version -->
Stable tag: 0.1.0
<!-- x-release-please-end -->
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stream front-end request logs from your WordPress origin to Temso so AI-crawler and bot traffic shows up in your dashboard.

== Description ==

Temso captures every request your WordPress origin serves over HTTP and sends it to Temso in the background. Unlike CDN-based setups, it needs no DNS, Worker, or log-drain configuration — install, paste two values, done.

* Captures all server-side requests — front-end, wp-admin, REST, AJAX, login, xmlrpc — the same coverage as the Temso Cloudflare/Vercel connectors. Bot classification happens in Temso.
* Non-blocking, batched delivery — adds no perceptible latency to your pages.
* No cookies, no JavaScript, no client-side tracking.
* Visitor IPs are sent over TLS and hashed in Temso before storage; raw IPs are never retained.
* WordPress Multisite supported (activate and connect per site).

Requests served from full-page cache never reach PHP and therefore can't be captured by any origin plugin — this is expected and matches the documented CDN-cache limitation.

== Installation ==

1. Install and activate the plugin.
2. Open **Settings → Temso**.
3. Paste the **Ingest URL** and **API key** from your Temso project (Crawlers → Add source → WordPress).
4. Make sure **Tracking** is enabled and save.

== Privacy ==

This plugin sends the following per front-end request to the Temso API endpoint you configure: timestamp, request URL, HTTP method, response status, user agent, referer, and the visitor IP address. The IP address is transmitted over TLS and hashed by Temso before storage — it is never stored in raw form, and the plugin sets no cookies and adds no client-side tracking.

Suggested privacy-policy snippet:

> This site uses Temso to measure automated (bot and AI-crawler) traffic. For each page request, technical metadata (URL, time, browser user agent, referring page, and a one-way hash of your IP address) is sent to Temso. No cookies are set and you are not personally identified. See https://temso.ai/privacy for details.

== Screenshots ==

1. The Settings → Temso page: paste your Ingest URL and API key.
2. Captured WordPress traffic appearing in the Temso crawler dashboard.

== Changelog ==

= 0.1.0 =
* Initial release: front-end request capture with batched, non-blocking delivery to Temso.
