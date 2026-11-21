<?php
/**
 * Plugin Name:       SubmitDock – Headless Form Backend
 * Plugin URI:        https://github.com/bealinawaz/submitdock
 * Description:       Receive form submissions from any website or app through a REST API endpoint. Submissions are saved in WordPress and emailed to you.
 * Version:           1.2.3
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Ali Nawaz
 * Author URI:        https://bealinawaz.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       submitdock
 * Domain Path:       /languages
 *
 * @package SubmitDock
 */

defined( 'ABSPATH' ) || exit;

define( 'SUBMITDOCK_VERSION', '1.2.3' );
define( 'SUBMITDOCK_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-submitdock-settings.php';
require_once __DIR__ . '/includes/class-submitdock-submissions.php';
require_once __DIR__ . '/includes/class-submitdock-spam.php';
require_once __DIR__ . '/includes/class-submitdock-api.php';
require_once __DIR__ . '/includes/class-submitdock-privacy.php';

SubmitDock_Submissions::init();
SubmitDock_Spam::init();
SubmitDock_API::init();
SubmitDock_Privacy::init();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-submitdock-admin.php';
	SubmitDock_Admin::init();
}

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( SubmitDock_Spam::CRON );
	}
);
