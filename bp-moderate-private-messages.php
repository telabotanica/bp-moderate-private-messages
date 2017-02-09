<?php
/*
 * @wordpress-plugin
 * Plugin Name:       BP Moderate Private Messages
 * Plugin URI:        https://github.com/telabotanica/bp-moderate-private-messages
 * GitHub Plugin URI: https://github.com/telabotanica/bp-moderate-private-messages
 * Description:       A BuddyPress plugin that allows to moderate private messages when the number of recipients exceeds the chosen limit
 * Version:           0.1
 * Author:            Tela Botanica
 * Author URI:        https://github.com/telabotanica
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       bp-moderate-private-messages
 * Domain Path:       /languages
 */

const BP_MPM_OPTION_RECIPIENTS_LIMIT = 'bp_mpm_recipients_limit';
const BP_MPM_MODERATED_MESSAGES_TABLE_NAME = 'bp_messages_moderated';
const BP_MPM_DEFAULT_RECIPIENTS_LIMIT = 10;


// activation hook : create table
register_activation_hook(__FILE__, 'bp_mpm_init_db');
// uninstall hook : drop table / delete wp_option
register_uninstall_hook(__FILE__, 'bp_mpm_clean_db');

add_action('bp_include', 'bp_mpm_init');

function bp_mpm_init() {
	// check if Buddypress private messages are enabled
	if (! bp_is_active('messages')) {
		add_action('admin_notices', 'bp_mpm_messages_component_disabled');
		return false;
	}

	// include admin management
	require_once __DIR__ . '/admin-manage-messages-moderation.php';

	// moderation hook for every message to be sent
	add_action('messages_message_before_save', 'bp_mpm_moderate_before_save', 10, 1);
}

function bp_mpm_messages_component_disabled() { ?>
	<div class="error notice is-dismissible">
		<p>
			<?php _e("BP Moderate Private Messages requires activation of Buddypress Messages component", 'bp-moderate-private-messages'); ?>
		</p>
	</div>
<?php }

/**
 * Creates the moderated messages table
 */
function bp_mpm_init_db() {
	// create plugin-specific table(s)
	global $wpdb;
	// moderated messages : copy of bp_messages_messages
	$create_moderated_messages_table = "
		CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}" . BP_MPM_MODERATED_MESSAGES_TABLE_NAME . "` (
			`id` bigint(20) NOT NULL AUTO_INCREMENT,
			`thread_id` bigint(20) NOT NULL,
			`sender_id` bigint(20) NOT NULL,
			`subject` varchar(200) NOT NULL,
			`message` longtext NOT NULL,
			`date_sent` datetime NOT NULL,
			PRIMARY KEY (`id`),
			KEY `sender_id` (`sender_id`),
			KEY `thread_id` (`thread_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
	;
	$wpdb->query($create_moderated_messages_table);

	// add default value for recipients limit, if not already set
	$recipients_limit = get_option(BP_MPM_OPTION_RECIPIENTS_LIMIT);
	if ($recipients_limit === false) {
		add_option(BP_MPM_OPTION_RECIPIENTS_LIMIT, BP_MPM_DEFAULT_RECIPIENTS_LIMIT);
	}
}

/**
 * Drops the moderated messages table, removes the config options
 */
function bp_mpm_clean_db() {
	// remove plugin-specific table(s)
	global $wpdb;
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}" . BP_MPM_MODERATED_MESSAGES_TABLE_NAME . ";");

	// clean wp_options table
	delete_option(BP_MPM_OPTION_RECIPIENTS_LIMIT);
}

/**
 * Moderates any message before it is "sent" (saved in the database), by saving
 * it in the moderated messages table and sending a notice to superadmins; only
 * moderates if the number of recipients exceeds the configured limit
 * 
 * @param $message object reference passed by BP_Messages_Message::send()
 */
function bp_mpm_moderate_before_save(&$message) {
	// superadmins bypass moderation
	if (is_super_admin()) {
		return;
	}

	//echo "Ça modère sa mémé : <pre>"; var_dump($message); echo "</pre><br><br>";

	// do we need moderation ?
	$recipients_limit = get_option(BP_MPM_OPTION_RECIPIENTS_LIMIT);
	//echo "Recipients limit: <pre>"; var_dump($recipients_limit); echo "</pre><br><br>";
	// == and not === because limit might be "" or 0 or "0", which means disabled
	if ($recipients_limit == false || intval($recipients_limit) > count($message->recipients)) {
		return;
	}

	// A. Save message in moderated messages table
	global $wpdb;
	$wpdb->query($wpdb->prepare(
		"INSERT INTO {$wpdb->prefix}" . BP_MPM_MODERATED_MESSAGES_TABLE_NAME . " VALUES(DEFAULT, %d, %d, %s, %s, %s);",
		($message->thread_id ? $message->thread_id : "''"),
		$message->sender_id,
		$message->subject,
		$message->message,
		$message->date_sent
	));

	// B. Modify current message and send it to all superadmins
	// 1. add an explicit prefix to the subject
	$message->subject = '[' . __("moderation needed", 'bp-moderate-private-messages') . '] ' . $message->subject;

	// 2. send it to superadmins
	// get_super_admins() doesn't return the user ID => method below works
	$args = array(
		'role' => 'Administrator'
	);
	$super_admins = get_users($args);
	$original_recipients = $message->recipients;
	$super_admins_recipients = array();
	//echo "Super admins: <pre>"; var_dump($super_admins); echo "</pre><br><br>";
	foreach ($super_admins as $super_admin) {
		$recipient = new stdClass();
		$recipient->user_id = $super_admin->ID;
		$super_admins_recipients[] = $recipient;
	}
	$message->recipients = $super_admins_recipients;

	// 3. add moderation link and instructions before original message contents
	$accept_link = '[lien acceptation]';
	$reject_link = '[lien rejet]';
	$sender = new WP_User($message->sender_id);
	//var_dump($sender);
	$new_message_contents = '';
	if (count($original_recipients) > 1) {
		$new_message_contents .= sprintf(
			__("The message below was sent by <strong>%s</strong> to <strong>%s</strong> members", 'bp-moderate-private-messages'),
			$sender->display_name,
			count($original_recipients)
		);
	} else {
		$original_recipient = new WP_User($original_recipients[0]->user_id);
		$new_message_contents .= sprintf(
			__("The message below was sent by <strong>%s</strong> to <strong>%s</strong>", 'bp-moderate-private-messages'),
			$sender->display_name,
			$original_recipient->display_name
		);
	}
	$new_message_contents .= ".\n"
		. __("Date", 'bp-moderate-private-messages') . ' : ' . $message->date_sent
		. "\n\n"
		. sprintf(
			__('You may <a href="%s">accept it</a> or <a href="%s">reject it</a>', 'bp-moderate-private-messages'),
			$accept_link,
			$reject_link
		) . '.'
		. "\n\n"
		. '-- ' . __('original message', 'bp-moderate-private-messages') . ' : --'
		. "\n\n"
		. $message->message; // original message
	// overwrite
	$message->message = $new_message_contents;

	//echo "Message bricolé: <pre>"; var_dump($message); echo "</pre><br><br>";
	//exit;
}
