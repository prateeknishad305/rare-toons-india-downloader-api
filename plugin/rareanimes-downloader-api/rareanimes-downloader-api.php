<?php
/**
 * Plugin Name:       RareToons Downloader API
 * Plugin URI:        https://www.rareanimes.foo/
 * Description:       A JSON downloader API for rareanimes.foo. Exposes every anime series, episode, quality and download link, and resolves host pages into direct file URLs.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            RareToons India
 * License:           GPL-2.0-or-later
 * Text Domain:       rareanimes-downloader-api
 *
 * @package RAI
 */

defined( 'ABSPATH' ) || exit;

define( 'RAI_PLUGIN_VERSION', '1.0.0' );
define( 'RAI_PLUGIN_FILE', __FILE__ );
define( 'RAI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RAI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once RAI_PLUGIN_DIR . 'includes/core/bootstrap.php';

require_once RAI_PLUGIN_DIR . 'includes/class-rai-cache.php';
require_once RAI_PLUGIN_DIR . 'includes/class-rai-source.php';
require_once RAI_PLUGIN_DIR . 'includes/class-rai-settings.php';
require_once RAI_PLUGIN_DIR . 'includes/class-rai-api.php';
require_once RAI_PLUGIN_DIR . 'includes/class-rai-plugin.php';

RAI_Plugin::instance();

register_activation_hook( __FILE__, array( 'RAI_Plugin', 'on_activation' ) );
