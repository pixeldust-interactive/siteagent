<?php
/**
 * Plugin Name: Site Agent
 * Plugin URI: https://askmaisy.com/
 * Description: A private AI site administration agent that can inspect a WordPress site, answer administrative questions, propose controlled changes, record them, and roll supported changes back.
 * Version: 0.2.5
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Maisy
 * Author URI: https://askmaisy.com/
 * License: GPL-2.0-or-later
 * Text Domain: site-agent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SITE_AGENT_VERSION', '0.2.5' );
define( 'SITE_AGENT_FILE', __FILE__ );
define( 'SITE_AGENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SITE_AGENT_URL', plugin_dir_url( __FILE__ ) );
define( 'SITE_AGENT_BASENAME', plugin_basename( __FILE__ ) );

require_once SITE_AGENT_DIR . 'includes/class-database.php';
require_once SITE_AGENT_DIR . 'includes/class-redactor.php';
require_once SITE_AGENT_DIR . 'includes/class-codec.php';
require_once SITE_AGENT_DIR . 'includes/class-build-integrity.php';
require_once SITE_AGENT_DIR . 'includes/class-browser-guard.php';
require_once SITE_AGENT_DIR . 'includes/class-audit-log.php';
require_once SITE_AGENT_DIR . 'includes/class-rate-limiter.php';
require_once SITE_AGENT_DIR . 'includes/class-activator.php';
require_once SITE_AGENT_DIR . 'includes/class-indexer.php';
require_once SITE_AGENT_DIR . 'includes/class-retriever.php';
require_once SITE_AGENT_DIR . 'includes/class-diagnostics.php';
require_once SITE_AGENT_DIR . 'includes/class-plugin-impact.php';
require_once SITE_AGENT_DIR . 'includes/class-ledger.php';
require_once SITE_AGENT_DIR . 'includes/class-action-registry.php';
require_once SITE_AGENT_DIR . 'includes/class-approval-service.php';
require_once SITE_AGENT_DIR . 'includes/class-openai-client.php';
require_once SITE_AGENT_DIR . 'includes/class-agent.php';
require_once SITE_AGENT_DIR . 'includes/class-rest-controller.php';
require_once SITE_AGENT_DIR . 'includes/class-admin.php';
require_once SITE_AGENT_DIR . 'includes/class-retention.php';
require_once SITE_AGENT_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Site_Agent_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Site_Agent_Activator', 'deactivate' ) );

Site_Agent_Plugin::instance()->boot();
