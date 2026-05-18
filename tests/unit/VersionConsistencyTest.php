<?php
/**
 * Guards that every place a version is declared agrees.
 *
 * The GitHub-release updater compares TEMSO_VERSION against the release tag,
 * and WordPress reads the plugin-header "Version:" line — a drift between
 * these and readme.txt's "Stable tag" silently breaks update detection, so
 * it is worth a failing test rather than a code review catch.
 *
 * @package Temso
 */

use PHPUnit\Framework\TestCase;

final class VersionConsistencyTest extends TestCase {

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_plugin_header_constant_and_readme_versions_match(): void {
		$plugin = file_get_contents( $this->root() . '/temso-ai.php' );
		$readme = file_get_contents( $this->root() . '/readme.txt' );

		$this->assertSame(
			1,
			preg_match( '/^\s*\*\s*Version:\s*([0-9A-Za-z.\-]+)/m', $plugin, $header )
		);
		$this->assertSame(
			1,
			preg_match( "/define\(\s*'TEMSO_VERSION',\s*'([0-9A-Za-z.\-]+)'\s*\)/", $plugin, $constant )
		);
		$this->assertSame(
			1,
			preg_match( '/^\s*Stable tag:\s*([0-9A-Za-z.\-]+)/m', $readme, $stable )
		);

		$this->assertSame(
			$header[1],
			$constant[1],
			'Plugin-header "Version:" and TEMSO_VERSION must match.'
		);
		$this->assertSame(
			$header[1],
			$stable[1],
			'Plugin-header "Version:" and readme.txt "Stable tag" must match.'
		);
	}
}
