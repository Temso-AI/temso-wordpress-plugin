<?php
/**
 * Guards that every place a version is declared agrees.
 *
 * TEMSO_VERSION is the single source of truth (release-please bumps it and the
 * plugin-header "Version:" line together). readme.txt's "Stable tag" is no
 * longer kept in lockstep in the committed tree — bin/build.sh rewrites it
 * from TEMSO_VERSION when packaging, because the wordpress.org readme header
 * can't carry release-please's markers. A drift here silently breaks update
 * detection and which version wordpress.org serves, so it is worth a failing
 * test rather than a code-review catch.
 *
 * @package Temso
 */

use PHPUnit\Framework\TestCase;

final class VersionConsistencyTest extends TestCase {

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_plugin_header_and_constant_versions_match(): void {
		$plugin = file_get_contents( $this->root() . '/temso-ai.php' );

		$this->assertSame(
			1,
			preg_match( '/^\s*\*\s*Version:\s*([0-9A-Za-z.\-]+)/m', $plugin, $header )
		);
		$this->assertSame(
			1,
			preg_match( "/define\(\s*'TEMSO_VERSION',\s*'([0-9A-Za-z.\-]+)'\s*\)/", $plugin, $constant )
		);

		$this->assertSame(
			$header[1],
			$constant[1],
			'Plugin-header "Version:" and TEMSO_VERSION must match.'
		);
	}

	/**
	 * The readme keeps a syntactically valid Stable tag (wordpress.org requires
	 * it), and bin/build.sh's sync rewrites it to exactly TEMSO_VERSION — so the
	 * packaged/SVN readme can never drift from the source of truth, and the sync
	 * mechanism can't be silently removed without this failing.
	 */
	public function test_build_syncs_readme_stable_tag_to_constant(): void {
		$plugin = file_get_contents( $this->root() . '/temso-ai.php' );
		$readme = file_get_contents( $this->root() . '/readme.txt' );
		$build  = file_get_contents( $this->root() . '/bin/build.sh' );

		$this->assertSame(
			1,
			preg_match( "/define\(\s*'TEMSO_VERSION',\s*'([0-9A-Za-z.\-]+)'\s*\)/", $plugin, $constant )
		);
		$this->assertSame(
			1,
			preg_match( '/^Stable tag:\s*[0-9A-Za-z.\-]+\s*$/m', $readme ),
			'readme.txt must have a valid "Stable tag:" line for build.sh to sync.'
		);
		$this->assertStringContainsString(
			'Stable tag: $VERSION',
			$build,
			'bin/build.sh must sync readme.txt Stable tag from TEMSO_VERSION.'
		);

		$synced = preg_replace(
			'/^Stable tag:.*/m',
			'Stable tag: ' . $constant[1],
			$readme
		);
		$this->assertSame(
			1,
			preg_match( '/^Stable tag:\s*([0-9A-Za-z.\-]+)/m', $synced, $stable )
		);
		$this->assertSame(
			$constant[1],
			$stable[1],
			'build.sh sync must produce a Stable tag equal to TEMSO_VERSION.'
		);
	}
}
