<?php
/**
 * Media sideloading for published posts.
 *
 * When Temso publishes a post, its body HTML and featured image reference images
 * hosted in Temso's own public storage bucket. Hotlinking those is fragile (they
 * break if Temso moves the asset) and keeps them out of the site's media library.
 * This class pulls every *Temso-bucket* image into the WordPress media library and
 * rewrites the markup to point at the local copy.
 *
 * Two deliberate safety boundaries:
 *
 *  - Only Temso-bucket URLs are downloaded. Letting WordPress fetch arbitrary
 *    remote URLs found in post HTML is an SSRF/abuse vector, and copying random
 *    third-party images into a customer's library is undesirable. Every other
 *    `<img>` is left hotlinked, untouched.
 *  - Only raster image types are accepted (jpeg/png/webp/gif), verified by the
 *    downloaded bytes and not just the URL extension. SVG is rejected outright —
 *    a hosted SVG is a stored-XSS vector.
 *
 * Sideloading is idempotent: each attachment records its source URL in the
 * `_temso_source_url` meta, and a URL already sideloaded is reused instead of
 * downloaded again, so re-publishing a post never duplicates media.
 *
 * @package Temso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sideloads Temso-bucket images into the media library and rewrites markup.
 */
class Temso_Media {

	/**
	 * Attachment meta key mapping a media-library item back to its source URL.
	 *
	 * This is the dedup key: before downloading a URL we look for an existing
	 * attachment carrying it, and reuse that attachment when found.
	 */
	const SOURCE_URL_META = '_temso_source_url';

	/**
	 * Core meta key WordPress reads for an image's alt text.
	 */
	const ALT_META = '_wp_attachment_image_alt';

	/**
	 * Per-download network timeout, in seconds.
	 *
	 * Sideloading is synchronous inside the publish request, so a hung download
	 * must not stall the whole request near PHP's max_execution_time. A failed
	 * download falls back to the original URL (inline) or fails the publish
	 * (featured) rather than blocking indefinitely.
	 */
	const DOWNLOAD_TIMEOUT = 20;

	/**
	 * File extensions we are willing to sideload. SVG is intentionally absent.
	 */
	const ALLOWED_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'webp', 'gif' );

	/**
	 * MIME types the downloaded bytes must actually be. SVG/HTML/etc. are out.
	 */
	const ALLOWED_MIME = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

	/**
	 * Regex fragment matching the Temso-owned, world-readable GCS bucket name
	 * families the plugin will sideload from:
	 *
	 *  - temso-public-{env}        — tenant public assets (report logos, OG/share images)
	 *  - temso-content-media-{env} — content-piece images (inline + featured)
	 *
	 * The backend serves article media from the content-media bucket, so it must
	 * be allowlisted alongside temso-public or every post with images fails. The
	 * trailing hyphen is required so a squatted bucket (e.g. temso-publicevil)
	 * cannot match. Used for both the path-style and virtual-hosted host checks so
	 * the two stay in sync.
	 */
	const BUCKET_NAME_PATTERN = 'temso-(?:public|content-media)-[a-z0-9-]+';

	/*
	 * Public entry points.
	 *
	 * The publish flow is split in two so a strict featured-image failure can
	 * abort BEFORE any post is created or updated: sideload_featured() runs
	 * first, then the caller writes the post, then apply_to_post() attaches the
	 * thumbnail and rehosts inline images.
	 */

	/**
	 * Sideload the featured image, if any, before the post is written.
	 *
	 * Strict by design: returning a WP_Error here lets the caller fail the whole
	 * publish without having touched the post, so a transient failure can be
	 * retried cleanly and a permanent one leaves no orphaned/half-updated post.
	 * The attachment is created unattached (parent 0); set_post_thumbnail() does
	 * not require attachment parentage.
	 *
	 * @param array|null $featured_image Optional { url, alt } featured image.
	 * @return array|WP_Error|null { id: int, url: string }, null when absent, or
	 *                             a WP_Error carrying the right HTTP status.
	 */
	public function sideload_featured( $featured_image ) {
		if ( ! is_array( $featured_image ) || empty( $featured_image['url'] ) ) {
			return null;
		}

		$attachment = $this->sideload(
			$featured_image['url'],
			0,
			isset( $featured_image['alt'] ) ? $featured_image['alt'] : ''
		);

		if ( is_wp_error( $attachment ) ) {
			$data   = $attachment->get_error_data();
			$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 502;
			return new WP_Error(
				'temso_publish_featured_failed',
				'Featured image could not be sideloaded: ' . $attachment->get_error_message(),
				array( 'status' => $status )
			);
		}

		return $attachment;
	}

	/**
	 * Attach the featured thumbnail and rehost inline images on the saved post.
	 *
	 * This runs only after the post is written, so it is deliberately incapable
	 * of failing the publish: doing so would orphan the just-written post (or, on
	 * a create, duplicate it when the backend retries). The strict part of
	 * featured handling — the image being downloadable and a valid type — has
	 * already been enforced by sideload_featured() *before* the post was written.
	 * Assigning that already-sideloaded attachment as the thumbnail is a reliable
	 * meta write, and inline images are best-effort by contract (a broken one
	 * stays hotlinked), so both are applied here without a failure path.
	 *
	 * @param int        $post_id             Target post ID (already created/updated).
	 * @param string     $html                Post body (Gutenberg block markup).
	 * @param array|null $featured_attachment Result of sideload_featured(), or null.
	 * @return array { html: string, content_changed: bool }
	 */
	public function apply_to_post( $post_id, $html, $featured_attachment ) {
		if ( is_array( $featured_attachment ) && ! empty( $featured_attachment['id'] ) ) {
			set_post_thumbnail( $post_id, (int) $featured_attachment['id'] );
		}

		if ( false === stripos( (string) $html, '<img' ) ) {
			return array(
				'html'            => $html,
				'content_changed' => false,
			);
		}

		$new_html = $this->rehost_inline_images( (string) $html, $post_id );

		return array(
			'html'            => $new_html,
			'content_changed' => $new_html !== (string) $html,
		);
	}

	/**
	 * Rewrite every Temso-bucket `<img>` in the body to its local attachment.
	 *
	 * Two passes:
	 *
	 *  1. Native `wp:image` blocks — a `<!-- wp:image … -->` comment immediately
	 *     followed by `<figure>…<img>`. Here we can rewrite the `<img>` AND set
	 *     the block comment's `id` so the markup stays a valid, fully-native
	 *     block (the `wp-image-<id>` class and the comment `id` agree).
	 *  2. Everything else — standalone images, linked images, or blocks whose
	 *     comment JSON is too complex to edit safely. These get a plain `src`
	 *     (and `srcset`) rewrite with no class added, which never introduces a
	 *     block-validity mismatch.
	 *
	 * Pass 2 runs over all `<img>` tags, but anything pass 1 already rehosted now
	 * has a local `src`, so it is skipped — no double processing.
	 *
	 * @param string $html    Post body.
	 * @param int    $post_id Parent post ID for sideloaded attachments.
	 * @return string Rewritten body (unchanged when nothing matched).
	 */
	public function rehost_inline_images( $html, $post_id ) {
		// Pass 1: native wp:image blocks — rewrite the img and sync the block id.
		$pass1 = preg_replace_callback(
			'/(?P<comment><!--\s*wp:image(?:\s+(?P<json>\{[^{}]*\}))?\s*-->)(?P<between>\s*<figure\b[^>]*>\s*)(?P<img><img\b[^>]*>)/i',
			function ( $matches ) use ( $post_id ) {
				$rewrite = $this->rewrite_img_tag( $matches['img'], $post_id, true );
				if ( 0 === $rewrite['id'] ) {
					return $matches[0];
				}
				$comment = $this->build_block_comment( $matches['json'], $rewrite['id'] );
				return $comment . $matches['between'] . $rewrite['tag'];
			},
			$html
		);
		if ( is_string( $pass1 ) ) {
			$html = $pass1;
		}

		// Pass 2: any remaining Temso-bucket images — plain src/srcset rewrite.
		$pass2 = preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $matches ) use ( $post_id ) {
				$rewrite = $this->rewrite_img_tag( $matches[0], $post_id, false );
				return $rewrite['tag'];
			},
			$html
		);
		if ( is_string( $pass2 ) ) {
			$html = $pass2;
		}

		return $html;
	}

	/**
	 * Rewrite a single `<img>` tag if its src is a Temso-bucket URL.
	 *
	 * @param string $tag       The `<img>` tag string.
	 * @param int    $post_id   Parent post ID for the sideloaded attachment.
	 * @param bool   $add_class Whether to add the `wp-image-<id>` class (only for
	 *                          native blocks, where the comment id is also set).
	 * @return array { tag: string, id: int } the (possibly unchanged) tag and the
	 *               attachment id, or id 0 when the tag was left as-is.
	 */
	private function rewrite_img_tag( $tag, $post_id, $add_class ) {
		$src = self::get_attr( $tag, 'src' );
		if ( null === $src || '' === $src || ! self::is_temso_bucket_url( $src ) ) {
			return array(
				'tag' => $tag,
				'id'  => 0,
			);
		}

		$alt        = self::get_attr( $tag, 'alt' );
		$alt        = is_string( $alt ) ? sanitize_text_field( $alt ) : '';
		$attachment = $this->sideload( $src, $post_id, $alt );

		// Best-effort: a failed inline image stays hotlinked.
		if ( is_wp_error( $attachment ) ) {
			return array(
				'tag' => $tag,
				'id'  => 0,
			);
		}

		$tag = self::set_attr( $tag, 'src', $attachment['url'] );

		// The incoming srcset points at bucket variants we did not sideload;
		// regenerate it from the local attachment, or drop it (and its now
		// meaningless sizes) so no bucket hotlink remains.
		if ( null !== self::get_attr( $tag, 'srcset' ) ) {
			$srcset = wp_get_attachment_image_srcset( $attachment['id'] );
			if ( is_string( $srcset ) && '' !== $srcset ) {
				$tag = self::set_attr( $tag, 'srcset', $srcset );
			} else {
				$tag = self::remove_attr( $tag, 'srcset' );
				$tag = self::remove_attr( $tag, 'sizes' );
			}
		}

		if ( $add_class ) {
			$tag = self::set_image_class( $tag, $attachment['id'] );
		}

		return array(
			'tag' => $tag,
			'id'  => (int) $attachment['id'],
		);
	}

	/**
	 * Build a `wp:image` opening comment that references the attachment id.
	 *
	 * Keeps any other attributes the original comment carried, only adding or
	 * replacing `id`. The match only ever passes brace-free JSON here (nested
	 * objects fall through to the plain-rewrite pass), so the string edits are
	 * safe.
	 *
	 * @param string|null $json The original comment JSON (`{…}`), or '' when bare.
	 * @param int         $id   Attachment id to record.
	 * @return string
	 */
	private function build_block_comment( $json, $id ) {
		$id   = (int) $id;
		$json = is_string( $json ) ? trim( $json ) : '';

		if ( '' === $json || '{}' === $json ) {
			$new_json = '{"id":' . $id . '}';
		} elseif ( preg_match( '/"id"\s*:\s*\d+/', $json ) ) {
			$new_json = preg_replace( '/"id"\s*:\s*\d+/', '"id":' . $id, $json, 1 );
		} else {
			// Insert id as the first key: `{` -> `{"id":N,`.
			$new_json = preg_replace( '/^\{/', '{"id":' . $id . ',', $json, 1 );
		}

		return '<!-- wp:image ' . $new_json . ' -->';
	}

	/**
	 * Download a Temso-bucket image into the library, or reuse an existing copy.
	 *
	 * @param string $url     Source image URL.
	 * @param int    $post_id Parent post ID for a newly created attachment.
	 * @param string $alt     Alt text to store on a newly created attachment.
	 * @return array|WP_Error { id: int, url: string } or an error. The error data
	 *                        carries a `status` (400 for a permanent rejection,
	 *                        502 for a transient download failure) so the featured
	 *                        path can map it to the right HTTP code.
	 */
	private function sideload( $url, $post_id, $alt ) {
		$url = esc_url_raw( (string) $url );
		$alt = is_string( $alt ) ? sanitize_text_field( $alt ) : '';

		// Only our own bucket, https only — never fetch arbitrary remote URLs.
		if ( '' === $url || ! self::is_temso_bucket_url( $url ) ) {
			return new WP_Error(
				'temso_media_not_rehostable',
				'URL is not an allowed Temso media URL.',
				array( 'status' => 400 )
			);
		}

		// Cheap pre-filter on the URL extension: reject SVG and friends before
		// spending a download. The downloaded bytes are validated again below.
		if ( '' === self::allowed_extension( $url ) ) {
			return new WP_Error(
				'temso_media_unsupported_type',
				'Unsupported or disallowed image type.',
				array( 'status' => 400 )
			);
		}

		// Idempotency: reuse a previously sideloaded copy of this exact URL. Alt
		// is intentionally left untouched on reuse so a re-publish never clobbers
		// an alt the site owner edited in the media library.
		$existing = self::find_existing_attachment( $url );
		if ( $existing > 0 ) {
			$local = wp_get_attachment_url( $existing );
			if ( is_string( $local ) && '' !== $local ) {
				return array(
					'id'  => $existing,
					'url' => $local,
				);
			}
			// Stored attachment is broken; fall through and re-sideload.
		}

		// WordPress's media-handling helpers (download_url, media_handle_sideload,
		// wp_read_image_metadata) live in wp-admin/includes and are not loaded in
		// the front-end REST context this endpoint runs in, so pull them in on
		// demand. Mirrors Temso_Cache_Detect's load of wp-admin/includes/plugin.php
		// and WordPress's own documented sideload recipe; require_once is keyed on
		// the resolved path, so an already-loaded admin context is a no-op.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp = download_url( $url, self::DOWNLOAD_TIMEOUT );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error(
				'temso_media_download_failed',
				$tmp->get_error_message(),
				array( 'status' => 502 )
			);
		}

		// Validate the actual bytes, not just the URL extension: an SVG or HTML
		// payload served from a `.jpg` URL is a permanent rejection (400), never
		// a retryable one.
		$info = wp_getimagesize( $tmp );
		$mime = ( is_array( $info ) && ! empty( $info['mime'] ) ) ? strtolower( $info['mime'] ) : '';
		if ( ! in_array( $mime, self::ALLOWED_MIME, true ) ) {
			self::cleanup_temp( $tmp );
			return new WP_Error(
				'temso_media_unsupported_type',
				'Downloaded file is not an allowed image type.',
				array( 'status' => 400 )
			);
		}

		$file_array = array(
			'name'     => self::filename_for( $url ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id, '' );
		if ( is_wp_error( $attachment_id ) ) {
			self::cleanup_temp( $tmp );
			return new WP_Error(
				'temso_media_sideload_failed',
				$attachment_id->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$attachment_id = (int) $attachment_id;
		$local         = wp_get_attachment_url( $attachment_id );
		if ( $attachment_id <= 0 || ! is_string( $local ) || '' === $local ) {
			return new WP_Error(
				'temso_media_sideload_failed',
				'Sideload did not yield a usable attachment.',
				array( 'status' => 502 )
			);
		}

		update_post_meta( $attachment_id, self::SOURCE_URL_META, $url );
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, self::ALT_META, $alt );
		}

		return array(
			'id'  => $attachment_id,
			'url' => $local,
		);
	}

	/**
	 * Find an attachment previously sideloaded from the given source URL.
	 *
	 * @param string $url Source URL stored in SOURCE_URL_META.
	 * @return int Attachment ID, or 0 when none exists.
	 */
	private static function find_existing_attachment( $url ) {
		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'numberposts'            => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact-match dedup lookup keyed on our own indexed meta; required for idempotency.
					array(
						'key'   => self::SOURCE_URL_META,
						'value' => $url,
					),
				),
			)
		);

		return ( is_array( $ids ) && ! empty( $ids ) ) ? (int) $ids[0] : 0;
	}

	/**
	 * Whether a URL points at a Temso-owned storage host we may rehost.
	 *
	 * Matches Temso's world-readable Google Cloud Storage buckets (see
	 * BUCKET_NAME_PATTERN: temso-public-… and temso-content-media-…) in both
	 * path-style (host storage.googleapis.com, path beginning /<bucket>/) and
	 * virtual-hosted style (host <bucket>.storage.googleapis.com), plus any host
	 * configured via the TEMSO_MEDIA_CDN_HOST constant or temso_media_cdn_hosts
	 * filter. The bucket prefix requires the trailing hyphen so a squatted bucket
	 * like temso-publicevil cannot match. https is required. The final decision is
	 * filterable so the backend's precise rehostable-URL set can override host
	 * guessing in the future.
	 *
	 * @param string $url Candidate image URL.
	 * @return bool
	 */
	public static function is_temso_bucket_url( $url ) {
		$url    = (string) $url;
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );

		$match = false;

		if ( 'https' === $scheme && '' !== $host ) {
			if ( 'storage.googleapis.com' === $host && preg_match( '#^/' . self::BUCKET_NAME_PATTERN . '/#i', $path ) ) {
				$match = true;
			} elseif ( preg_match( '#^' . self::BUCKET_NAME_PATTERN . '\.storage\.googleapis\.com$#i', $host ) ) {
				$match = true;
			} elseif ( in_array( $host, self::cdn_hosts(), true ) ) {
				$match = true;
			}
		}

		/**
		 * Filter whether an image URL may be sideloaded into the media library.
		 *
		 * Lets a site (or a future backend-provided allowlist) override the
		 * host-pattern heuristic. Returning true for a non-Temso host re-enables
		 * the SSRF surface the default guards against, so override with care.
		 *
		 * @param bool   $match  Whether the default rules consider $url rehostable.
		 * @param string $url    The candidate URL.
		 * @param string $host   Lower-cased host parsed from $url.
		 * @param string $scheme Lower-cased scheme parsed from $url.
		 */
		return (bool) apply_filters( 'temso_media_is_rehostable_url', $match, $url, $host, $scheme );
	}

	/**
	 * Extra hosts (e.g. a Temso CDN domain) treated as rehostable.
	 *
	 * @return string[] Lower-cased hostnames.
	 */
	private static function cdn_hosts() {
		$hosts = array();
		if ( defined( 'TEMSO_MEDIA_CDN_HOST' ) && is_string( TEMSO_MEDIA_CDN_HOST ) && '' !== TEMSO_MEDIA_CDN_HOST ) {
			$hosts[] = strtolower( TEMSO_MEDIA_CDN_HOST );
		}

		/**
		 * Filter the list of additional hostnames treated as Temso media hosts.
		 *
		 * @param string[] $hosts Hostnames from the TEMSO_MEDIA_CDN_HOST constant.
		 */
		$hosts = apply_filters( 'temso_media_cdn_hosts', $hosts );
		if ( ! is_array( $hosts ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $hosts as $host ) {
			$host = strtolower( trim( (string) $host ) );
			if ( '' !== $host ) {
				$normalized[] = $host;
			}
		}

		return $normalized;
	}

	/**
	 * The allowed file extension of a URL, or '' when its type is not accepted.
	 *
	 * @param string $url Source URL.
	 * @return string Lower-cased extension, or '' when disallowed/absent.
	 */
	private static function allowed_extension( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ? $ext : '';
	}

	/**
	 * A safe upload filename derived from the source URL.
	 *
	 * @param string $url Source URL (already validated to an allowed extension).
	 * @return string
	 */
	private static function filename_for( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( wp_basename( $path ) );

		// wp_basename strips the directory; guarantee a non-empty, typed name.
		if ( '' === $name || false === strpos( $name, '.' ) ) {
			$ext  = self::allowed_extension( $url );
			$name = 'temso-image' . ( '' !== $ext ? '.' . $ext : '' );
		}

		return $name;
	}

	/**
	 * Delete the leftover temp download after a failed sideload.
	 *
	 * On failure media_handle_sideload leaves the temp file in place, so the
	 * caller cleans up. wp_delete_file is always loaded in a real request; the
	 * guard simply keeps a stubbed test environment happy.
	 *
	 * @param string $path Temp file path from download_url().
	 */
	private static function cleanup_temp( $path ) {
		if ( is_string( $path ) && '' !== $path && function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $path );
		}
	}

	/*
	 * Tag attribute helpers.
	 *
	 * Operate on a single `<img>` tag string. Gutenberg/Temso markup uses
	 * well-formed, double-quoted attributes; single-quoted and unquoted forms
	 * are handled too for resilience.
	 */

	/**
	 * Read an attribute's value from a tag, or null when absent.
	 *
	 * @param string $tag  The tag string.
	 * @param string $name Attribute name.
	 * @return string|null
	 */
	private static function get_attr( $tag, $name ) {
		$quoted = preg_quote( $name, '/' );
		if ( preg_match( '/\s' . $quoted . '\s*=\s*"([^"]*)"/i', $tag, $m ) ) {
			return html_entity_decode( $m[1], ENT_QUOTES );
		}
		if ( preg_match( '/\s' . $quoted . '\s*=\s*\'([^\']*)\'/i', $tag, $m ) ) {
			return html_entity_decode( $m[1], ENT_QUOTES );
		}
		if ( preg_match( '/\s' . $quoted . '\s*=\s*([^\s">]+)/i', $tag, $m ) ) {
			return html_entity_decode( $m[1], ENT_QUOTES );
		}

		return null;
	}

	/**
	 * Set (or add) an attribute on a tag, preserving its existing quote style.
	 *
	 * @param string $tag   The tag string.
	 * @param string $name  Attribute name.
	 * @param string $value Raw attribute value (escaped for output here).
	 * @return string
	 */
	private static function set_attr( $tag, $name, $value ) {
		$quoted = preg_quote( $name, '/' );
		$esc    = esc_attr( $value );

		if ( preg_match( '/\s' . $quoted . '\s*=\s*"[^"]*"/i', $tag ) ) {
			return preg_replace( '/(\s' . $quoted . '\s*=\s*")[^"]*(")/i', '${1}' . $esc . '${2}', $tag, 1 );
		}
		if ( preg_match( '/\s' . $quoted . '\s*=\s*\'[^\']*\'/i', $tag ) ) {
			return preg_replace( '/(\s' . $quoted . '\s*=\s*\')[^\']*(\')/i', '${1}' . $esc . '${2}', $tag, 1 );
		}
		if ( preg_match( '/\s' . $quoted . '\s*=\s*[^\s">]+/i', $tag ) ) {
			return preg_replace( '/(\s' . $quoted . '\s*=\s*)[^\s">]+/i', '${1}"' . $esc . '"', $tag, 1 );
		}

		// Absent: insert before the tag's closing `>` (or `/>`).
		return preg_replace( '/\s*\/?>$/', ' ' . $name . '="' . $esc . '"$0', $tag, 1 );
	}

	/**
	 * Remove an attribute from a tag entirely.
	 *
	 * @param string $tag  The tag string.
	 * @param string $name Attribute name.
	 * @return string
	 */
	private static function remove_attr( $tag, $name ) {
		$quoted   = preg_quote( $name, '/' );
		$patterns = array(
			'/\s' . $quoted . '\s*=\s*"[^"]*"/i',
			'/\s' . $quoted . '\s*=\s*\'[^\']*\'/i',
			'/\s' . $quoted . '\s*=\s*[^\s">]+/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $tag ) ) {
				return preg_replace( $pattern, '', $tag, 1 );
			}
		}

		return $tag;
	}

	/**
	 * Ensure an `<img>` carries exactly one `wp-image-<id>` class.
	 *
	 * @param string $tag           The img tag string.
	 * @param int    $attachment_id Attachment ID to reference.
	 * @return string
	 */
	private static function set_image_class( $tag, $attachment_id ) {
		$token = 'wp-image-' . (int) $attachment_id;
		$class = self::get_attr( $tag, 'class' );

		if ( null === $class || '' === trim( $class ) ) {
			return self::set_attr( $tag, 'class', $token );
		}

		if ( preg_match( '/\bwp-image-\d+\b/', $class ) ) {
			$class = preg_replace( '/\bwp-image-\d+\b/', $token, $class, 1 );
		} else {
			$class .= ' ' . $token;
		}

		return self::set_attr( $tag, 'class', $class );
	}
}
