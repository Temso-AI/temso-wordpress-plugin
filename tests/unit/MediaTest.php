<?php
/**
 * Unit tests for Temso_Media.
 *
 * Pure unit tests: WordPress is never loaded. Brain Monkey stubs the WP and
 * media-stack functions the sideloader calls, so we can assert URL matching,
 * type rejection, dedup, inline rewriting, and featured-image handling without
 * any network or filesystem access.
 *
 * @package Temso
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-temso-media.php';

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in for the WordPress WP_Error class.
	 */
	class WP_Error {

		/** @var string */
		public $code;
		/** @var string */
		public $message;
		/** @var mixed */
		public $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

final class MediaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
		// Filters are pass-through: return the value being filtered (2nd arg).
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/* ----------------------------------------------------------------- *
	 * Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Stub the whole media stack for a successful sideload/attach.
	 *
	 * Individual tests override specific functions (e.g. download_url to fail).
	 *
	 * @param int    $id        Attachment ID media_handle_sideload returns.
	 * @param string $local_url Local URL wp_get_attachment_url returns.
	 * @param string $srcset    What wp_get_attachment_image_srcset returns.
	 * @param string $mime      MIME wp_getimagesize reports for the download.
	 */
	private function happy_path( int $id, string $local_url, string $srcset = '', string $mime = 'image/webp' ): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'download_url' )->justReturn( '/tmp/temso-download' );
		Functions\when( 'wp_getimagesize' )->justReturn(
			array(
				0      => 800,
				1      => 600,
				'mime' => $mime,
			)
		);
		Functions\when( 'media_handle_sideload' )->justReturn( $id );
		Functions\when( 'wp_get_attachment_url' )->justReturn( $local_url );
		Functions\when( 'wp_get_attachment_image_srcset' )->justReturn( $srcset );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'wp_read_image_metadata' )->justReturn( array() );
		Functions\when( 'wp_delete_file' )->justReturn( true );
		Functions\when( 'set_post_thumbnail' )->justReturn( true );
	}

	/* ----------------------------------------------------------------- *
	 * is_temso_bucket_url
	 * ----------------------------------------------------------------- */

	public function test_recognizes_path_style_bucket_url(): void {
		$this->assertTrue(
			Temso_Media::is_temso_bucket_url( 'https://storage.googleapis.com/temso-public-prod/img/cover.webp' )
		);
	}

	public function test_recognizes_virtual_hosted_bucket_url(): void {
		$this->assertTrue(
			Temso_Media::is_temso_bucket_url( 'https://temso-public-prod.storage.googleapis.com/img/cover.webp' )
		);
	}

	public function test_recognizes_content_media_bucket_url(): void {
		// The backend serves article (inline + featured) images from the dedicated
		// content-media bucket, so it must be rehostable too — path and host style.
		$this->assertTrue(
			Temso_Media::is_temso_bucket_url( 'https://storage.googleapis.com/temso-content-media-production/content-pieces/t/p/piece/abc.webp' )
		);
		$this->assertTrue(
			Temso_Media::is_temso_bucket_url( 'https://storage.googleapis.com/temso-content-media-development/content-pieces/t/p/piece/abc.webp' )
		);
		$this->assertTrue(
			Temso_Media::is_temso_bucket_url( 'https://temso-content-media-production.storage.googleapis.com/content-pieces/t/p/piece/abc.webp' )
		);
	}

	public function test_rejects_non_temso_host(): void {
		$this->assertFalse(
			Temso_Media::is_temso_bucket_url( 'https://example.com/photo.jpg' )
		);
		$this->assertFalse(
			Temso_Media::is_temso_bucket_url( 'https://storage.googleapis.com/someone-else/photo.jpg' )
		);
	}

	public function test_rejects_bucket_prefix_without_hyphen(): void {
		// A squatted bucket like "temso-publicevil" / "temso-content-mediaevil"
		// must not match — the trailing hyphen after the family prefix is required.
		$this->assertFalse(
			Temso_Media::is_temso_bucket_url( 'https://storage.googleapis.com/temso-publicevil/cover.webp' )
		);
		$this->assertFalse(
			Temso_Media::is_temso_bucket_url( 'https://temso-publicevil.storage.googleapis.com/cover.webp' )
		);
		$this->assertFalse(
			Temso_Media::is_temso_bucket_url( 'https://storage.googleapis.com/temso-content-mediaevil/cover.webp' )
		);
	}

	public function test_rejects_non_https_bucket_url(): void {
		$this->assertFalse(
			Temso_Media::is_temso_bucket_url( 'http://storage.googleapis.com/temso-public-prod/cover.webp' )
		);
	}

	/* ----------------------------------------------------------------- *
	 * Inline rewriting (rehost_inline_images)
	 * ----------------------------------------------------------------- */

	public function test_rewrites_temso_img_and_leaves_external_untouched(): void {
		$this->happy_path( 42, 'https://site.test/wp-content/uploads/2026/06/cover.webp' );

		$html = '<!-- wp:image --><figure class="wp-block-image"><img src="https://storage.googleapis.com/temso-public-prod/cover.webp" alt="A cover"/></figure><!-- /wp:image -->'
			. '<!-- wp:image --><figure class="wp-block-image"><img src="https://cdn.example.com/third-party.jpg" alt="Ext"/></figure><!-- /wp:image -->';

		$out = ( new Temso_Media() )->rehost_inline_images( $html, 7 );

		$this->assertStringContainsString( 'src="https://site.test/wp-content/uploads/2026/06/cover.webp"', $out );
		$this->assertStringContainsString( 'wp-image-42', $out );
		// The external image is left exactly as it was — never downloaded.
		$this->assertStringContainsString( 'src="https://cdn.example.com/third-party.jpg"', $out );
		$this->assertStringNotContainsString( 'storage.googleapis.com', $out );
	}

	public function test_inline_rewrite_preserves_alt_and_dimensions(): void {
		$this->happy_path( 9, 'https://site.test/uploads/cover.png' );

		$html = '<img src="https://storage.googleapis.com/temso-public-prod/cover.png" alt="Keep me" width="800" height="600"/>';
		$out  = ( new Temso_Media() )->rehost_inline_images( $html, 1 );

		$this->assertStringContainsString( 'alt="Keep me"', $out );
		$this->assertStringContainsString( 'width="800"', $out );
		$this->assertStringContainsString( 'height="600"', $out );
	}

	public function test_srcset_is_regenerated_from_local_attachment(): void {
		$this->happy_path(
			11,
			'https://site.test/uploads/cover.webp',
			'https://site.test/uploads/cover-300.webp 300w, https://site.test/uploads/cover-768.webp 768w'
		);

		$html = '<img src="https://storage.googleapis.com/temso-public-prod/cover.webp" srcset="https://storage.googleapis.com/temso-public-prod/cover-300.webp 300w" sizes="(max-width: 800px) 100vw, 800px"/>';
		$out  = ( new Temso_Media() )->rehost_inline_images( $html, 1 );

		$this->assertStringContainsString( 'srcset="https://site.test/uploads/cover-300.webp 300w, https://site.test/uploads/cover-768.webp 768w"', $out );
		$this->assertStringNotContainsString( 'storage.googleapis.com', $out );
	}

	public function test_srcset_dropped_when_attachment_has_none(): void {
		$this->happy_path( 12, 'https://site.test/uploads/cover.gif', '' );

		$html = '<img src="https://storage.googleapis.com/temso-public-prod/cover.gif" srcset="https://storage.googleapis.com/temso-public-prod/cover-300.gif 300w" sizes="100vw"/>';
		$out  = ( new Temso_Media() )->rehost_inline_images( $html, 1 );

		$this->assertStringNotContainsString( 'srcset', $out );
		$this->assertStringNotContainsString( 'sizes', $out );
		$this->assertStringNotContainsString( 'storage.googleapis.com', $out );
	}

	public function test_svg_temso_image_is_left_hotlinked_inline(): void {
		$downloaded = false;
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'download_url' )->alias(
			static function () use ( &$downloaded ) {
				$downloaded = true;
				return '/tmp/x';
			}
		);

		$html = '<img src="https://storage.googleapis.com/temso-public-prod/diagram.svg" alt="d"/>';
		$out  = ( new Temso_Media() )->rehost_inline_images( $html, 1 );

		$this->assertFalse( $downloaded, 'An SVG must never be downloaded.' );
		$this->assertSame( $html, $out );
	}

	public function test_disguised_content_type_is_left_hotlinked_inline(): void {
		// A .jpg URL whose bytes are not an allowed raster image (e.g. SVG/HTML
		// payload): wp_getimagesize reports no usable mime, so we do not rehost.
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'download_url' )->justReturn( '/tmp/x' );
		Functions\when( 'wp_getimagesize' )->justReturn( false );
		Functions\when( 'wp_delete_file' )->justReturn( true );
		$attached = false;
		Functions\when( 'media_handle_sideload' )->alias(
			static function () use ( &$attached ) {
				$attached = true;
				return 1;
			}
		);

		$html = '<img src="https://storage.googleapis.com/temso-public-prod/not-really.jpg" alt="x"/>';
		$out  = ( new Temso_Media() )->rehost_inline_images( $html, 1 );

		$this->assertFalse( $attached, 'A non-image payload must not be attached.' );
		$this->assertSame( $html, $out, 'A disguised payload stays hotlinked.' );
	}

	public function test_failed_inline_download_falls_back_to_original(): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'download_url' )->justReturn( new WP_Error( 'http_404', 'Not found' ) );

		$html = '<img src="https://storage.googleapis.com/temso-public-prod/cover.webp" alt="x"/>';
		$out  = ( new Temso_Media() )->rehost_inline_images( $html, 1 );

		$this->assertSame( $html, $out, 'A failed inline image stays hotlinked.' );
	}

	public function test_inline_dedup_reuses_existing_attachment(): void {
		$downloaded = false;
		Functions\when( 'get_posts' )->justReturn( array( 777 ) );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://site.test/uploads/existing.webp' );
		Functions\when( 'wp_get_attachment_image_srcset' )->justReturn( '' );
		Functions\when( 'download_url' )->alias(
			static function () use ( &$downloaded ) {
				$downloaded = true;
				return '/tmp/x';
			}
		);

		$html = '<!-- wp:image --><figure class="wp-block-image"><img src="https://storage.googleapis.com/temso-public-prod/cover.webp" alt="x"/></figure><!-- /wp:image -->';
		$out  = ( new Temso_Media() )->rehost_inline_images( $html, 1 );

		$this->assertFalse( $downloaded, 'A URL already in the library must not be downloaded again.' );
		$this->assertStringContainsString( 'src="https://site.test/uploads/existing.webp"', $out );
		$this->assertStringContainsString( 'wp-image-777', $out );
	}

	/* ----------------------------------------------------------------- *
	 * Gutenberg block id sync
	 * ----------------------------------------------------------------- */

	public function test_bare_block_comment_gets_attachment_id(): void {
		$this->happy_path( 55, 'https://site.test/uploads/cover.webp' );

		$html = '<!-- wp:image --><figure class="wp-block-image"><img src="https://storage.googleapis.com/temso-public-prod/cover.webp" alt="c"/></figure><!-- /wp:image -->';
		$out  = ( new Temso_Media() )->rehost_inline_images( $html, 3 );

		$this->assertStringContainsString( '<!-- wp:image {"id":55} -->', $out );
		$this->assertStringContainsString( 'wp-image-55', $out );
	}

	public function test_block_comment_with_flat_json_gains_id(): void {
		$this->happy_path( 56, 'https://site.test/uploads/cover.webp' );

		$html = '<!-- wp:image {"sizeSlug":"large"} --><figure class="wp-block-image"><img src="https://storage.googleapis.com/temso-public-prod/cover.webp" alt="c"/></figure><!-- /wp:image -->';
		$out  = ( new Temso_Media() )->rehost_inline_images( $html, 3 );

		$this->assertStringContainsString( '<!-- wp:image {"id":56,"sizeSlug":"large"} -->', $out );
		$this->assertStringContainsString( 'wp-image-56', $out );
	}

	public function test_block_comment_with_existing_id_is_replaced(): void {
		$this->happy_path( 57, 'https://site.test/uploads/cover.webp' );

		$html = '<!-- wp:image {"id":9,"sizeSlug":"large"} --><figure class="wp-block-image"><img src="https://storage.googleapis.com/temso-public-prod/cover.webp" class="wp-image-9" alt="c"/></figure><!-- /wp:image -->';
		$out  = ( new Temso_Media() )->rehost_inline_images( $html, 3 );

		$this->assertStringContainsString( '<!-- wp:image {"id":57,"sizeSlug":"large"} -->', $out );
		$this->assertStringContainsString( 'wp-image-57', $out );
		$this->assertStringNotContainsString( 'wp-image-9', $out );
		$this->assertStringNotContainsString( '"id":9', $out );
	}

	public function test_block_comment_with_nested_json_falls_back_to_plain_rewrite(): void {
		// Nested JSON cannot be edited safely by regex, so the block comment is
		// left untouched and the img gets a plain src rewrite (no class added),
		// which never creates a block-validity mismatch.
		$this->happy_path( 58, 'https://site.test/uploads/cover.webp' );

		$html = '<!-- wp:image {"id":1,"style":{"border":{"radius":"4px"}}} --><figure class="wp-block-image"><img src="https://storage.googleapis.com/temso-public-prod/cover.webp" alt="c"/></figure><!-- /wp:image -->';
		$out  = ( new Temso_Media() )->rehost_inline_images( $html, 3 );

		// Comment is preserved verbatim.
		$this->assertStringContainsString( '<!-- wp:image {"id":1,"style":{"border":{"radius":"4px"}}} -->', $out );
		// src is rewritten but no wp-image class is forced onto the img.
		$this->assertStringContainsString( 'src="https://site.test/uploads/cover.webp"', $out );
		$this->assertStringNotContainsString( 'wp-image-58', $out );
		$this->assertStringNotContainsString( 'storage.googleapis.com', $out );
	}

	public function test_linked_image_falls_back_to_plain_rewrite(): void {
		// figure > a > img is not the simple block shape, so it gets a plain
		// src rewrite with no class.
		$this->happy_path( 59, 'https://site.test/uploads/cover.webp' );

		$html = '<!-- wp:image --><figure class="wp-block-image"><a href="https://x.test"><img src="https://storage.googleapis.com/temso-public-prod/cover.webp" alt="c"/></a></figure><!-- /wp:image -->';
		$out  = ( new Temso_Media() )->rehost_inline_images( $html, 3 );

		$this->assertStringContainsString( 'src="https://site.test/uploads/cover.webp"', $out );
		$this->assertStringNotContainsString( 'storage.googleapis.com', $out );
		$this->assertStringNotContainsString( 'wp-image-59', $out );
	}

	/* ----------------------------------------------------------------- *
	 * Featured image: sideload_featured + apply_to_post
	 * ----------------------------------------------------------------- */

	public function test_sideload_featured_null_when_absent(): void {
		$this->assertNull( ( new Temso_Media() )->sideload_featured( null ) );
		$this->assertNull( ( new Temso_Media() )->sideload_featured( array( 'alt' => 'x' ) ) );
	}

	public function test_sideload_featured_returns_attachment(): void {
		$this->happy_path( 31, 'https://site.test/uploads/cover.webp' );

		$result = ( new Temso_Media() )->sideload_featured(
			array(
				'url' => 'https://storage.googleapis.com/temso-public-prod/cover.webp',
				'alt' => 'Cover',
			)
		);

		$this->assertSame( 31, $result['id'] );
		$this->assertSame( 'https://site.test/uploads/cover.webp', $result['url'] );
	}

	public function test_apply_to_post_sets_featured_thumbnail(): void {
		$thumb = null;
		$this->happy_path( 31, 'https://site.test/uploads/cover.webp' );
		Functions\when( 'set_post_thumbnail' )->alias(
			static function ( $post_id, $attachment_id ) use ( &$thumb ) {
				$thumb = array( $post_id, $attachment_id );
				return true;
			}
		);

		$result = ( new Temso_Media() )->apply_to_post(
			5,
			'<p>No inline images here.</p>',
			array(
				'id'  => 31,
				'url' => 'https://site.test/uploads/cover.webp',
			)
		);

		$this->assertSame( array( 5, 31 ), $thumb );
		$this->assertFalse( $result['content_changed'] );
	}

	public function test_apply_to_post_never_fails_after_the_post_is_written(): void {
		// apply_to_post() runs only after the post exists, so it must never return
		// a WP_Error — even when set_post_thumbnail() reports false (which it does
		// on an idempotent re-publish where the thumbnail is already set).
		$this->happy_path( 31, 'https://site.test/uploads/cover.webp' );
		Functions\when( 'set_post_thumbnail' )->justReturn( false );

		$result = ( new Temso_Media() )->apply_to_post(
			5,
			'<p>x</p>',
			array(
				'id'  => 31,
				'url' => 'https://site.test/uploads/cover.webp',
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['content_changed'] );
	}

	public function test_sideload_featured_download_failure_is_retryable(): void {
		$this->happy_path( 0, '' );
		Functions\when( 'download_url' )->justReturn( new WP_Error( 'http_request_failed', 'timeout' ) );

		$result = ( new Temso_Media() )->sideload_featured(
			array( 'url' => 'https://storage.googleapis.com/temso-public-prod/cover.webp' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'temso_publish_featured_failed', $result->get_error_code() );
		// A transient download failure is a 5xx so the backend retries.
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	public function test_sideload_featured_svg_is_permanent(): void {
		$this->happy_path( 0, '' );
		$downloaded = false;
		Functions\when( 'download_url' )->alias(
			static function () use ( &$downloaded ) {
				$downloaded = true;
				return '/tmp/x';
			}
		);

		$result = ( new Temso_Media() )->sideload_featured(
			array( 'url' => 'https://storage.googleapis.com/temso-public-prod/cover.svg' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertFalse( $downloaded, 'An SVG featured image must never be downloaded.' );
	}

	public function test_sideload_featured_bad_content_type_is_permanent(): void {
		$this->happy_path( 0, '' );
		Functions\when( 'download_url' )->justReturn( '/tmp/x' );
		Functions\when( 'wp_getimagesize' )->justReturn( false );

		$result = ( new Temso_Media() )->sideload_featured(
			array( 'url' => 'https://storage.googleapis.com/temso-public-prod/cover.jpg' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_sideload_featured_non_temso_url_is_permanent(): void {
		$this->happy_path( 0, '' );

		$result = ( new Temso_Media() )->sideload_featured(
			array( 'url' => 'https://evil.example.com/internal-probe' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/* ----------------------------------------------------------------- *
	 * No-op fast path
	 * ----------------------------------------------------------------- */

	public function test_apply_to_post_noop_when_no_media(): void {
		$result = ( new Temso_Media() )->apply_to_post( 1, '<p>Just text.</p>', null );

		$this->assertFalse( $result['content_changed'] );
		$this->assertSame( '<p>Just text.</p>', $result['html'] );
	}
}
