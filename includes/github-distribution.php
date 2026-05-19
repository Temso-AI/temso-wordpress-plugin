<?php
/**
 * GitHub-distribution marker.
 *
 * The presence of this file marks a build distributed outside the
 * WordPress.org directory (GitHub releases, or a zip handed to a client). It
 * defines TEMSO_GH_REPO, which is what activates Temso_Updater's GitHub-release
 * self-updater.
 *
 * The WordPress.org package omits this single file (see `bin/build.sh --wporg`),
 * so TEMSO_GH_REPO stays undefined there, Temso_Updater::boot() early-returns,
 * and WP core remains the only update source — required by the .org guidelines,
 * which forbid a hosted plugin from updating itself from an external source.
 *
 * @package Temso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TEMSO_GH_REPO' ) ) {
	define( 'TEMSO_GH_REPO', 'Temso-AI/temso-wordpress-plugin' );
}
