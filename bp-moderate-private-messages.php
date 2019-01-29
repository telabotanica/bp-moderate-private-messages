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
const BP_MPM_OPTION_NOTIFY_WHEN_QUEUED = 'bp_mpm_notify_when_queued';
const BP_MPM_OPTION_NOTIFY_WHEN_ACCEPTED = 'bp_mpm_notify_when_accepted';
const BP_MPM_OPTION_NOTIFY_WHEN_REJECTED = 'bp_mpm_notify_when_rejected';
const BP_MPM_OPTION_SOFT_DELETE = 'bp_mpm_soft_delete';
const BP_MPM_MODERATED_MESSAGES_TABLE_NAME = 'bp_messages_moderated';
const BP_MPM_MESSAGES_META_TABLE_NAME = 'bp_messages_meta';
const BP_MPM_ALREADY_SENT_META_NAME = 'already_sent_to_users';
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
			`recipients` text NOT NULL,
			`subject` varchar(200) NOT NULL,
			`message` longtext NOT NULL,
			`date_sent` datetime NOT NULL,
			`deleted` tinyint(1) NOT NULL,
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
	// using === should prevent confusing the option being not set with the
	// option being set to "0"
	$notify_when_queued = get_option(BP_MPM_OPTION_NOTIFY_WHEN_QUEUED);
	if ($notify_when_queued === false) {
		add_option(BP_MPM_OPTION_NOTIFY_WHEN_QUEUED, "1");
	}
	$notify_when_accepted = get_option(BP_MPM_OPTION_NOTIFY_WHEN_ACCEPTED);
	if ($notify_when_accepted === false) {
		add_option(BP_MPM_OPTION_NOTIFY_WHEN_ACCEPTED, "1");
	}
	$notify_when_rejected = get_option(BP_MPM_OPTION_NOTIFY_WHEN_REJECTED);
	if ($notify_when_rejected === false) {
		add_option(BP_MPM_OPTION_NOTIFY_WHEN_REJECTED, "1");
	}
	$soft_delete = get_option(BP_MPM_OPTION_SOFT_DELETE);
	if ($soft_delete === false) {
		add_option(BP_MPM_OPTION_SOFT_DELETE, "1");
	}
}

/**
 * Drops the moderated messages table, removes the config options, removes the
 * notifications
 */
function bp_mpm_clean_db() {
	// remove plugin-specific table(s)
	global $wpdb;
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}" . BP_MPM_MODERATED_MESSAGES_TABLE_NAME . ";");

	// clean wp_options table
	delete_option(BP_MPM_OPTION_RECIPIENTS_LIMIT);

	// delete notifications to prevent incorrect formatting due to absence of
	// moderated messages data
	$wpdb->query("DELETE FROM {$wpdb->prefix}bp_notifications WHERE component_name = 'bp-moderate-private-messages';");
}

/**
 * Moderates any message before it is "sent" (saved in the database), by saving
 * it in the moderated messages table and sending a notice to superadmins; only
 * moderates if the number of recipients exceeds the configured limit
 * 
 * @param $message object reference passed by BP_Messages_Message::send()
 */
function bp_mpm_moderate_before_save(&$message) {
	// superadmins are not affected by moderation
	if (is_super_admin()) {
		return;
	}

	// do we need moderation ?
	$recipients_limit = get_option(BP_MPM_OPTION_RECIPIENTS_LIMIT);
	//echo "Recipients limit: <pre>"; var_dump($recipients_limit); echo "</pre><br><br>";
	// == and not === because limit might be "" or 0 or "0", which means disabled
	if ($recipients_limit == false || intval($recipients_limit) > count($message->recipients)) {
		return;
	}

	// A. Save message in moderated messages table
	global $wpdb;
	// formatting recipients
	$recipients = array();
	foreach($message->recipients as $recipient) {
		$array_recipient = (array)$recipient;
		$recipients[] = array_pop($array_recipient);
	}
	// insert
	$wpdb->query($wpdb->prepare(
		"INSERT INTO {$wpdb->prefix}" . BP_MPM_MODERATED_MESSAGES_TABLE_NAME . " VALUES(DEFAULT, %d, %d, %s, %s, %s, %s, 0);",
		($message->thread_id ? $message->thread_id : "''"),
		$message->sender_id,
		implode(',', $recipients),
		$message->subject,
		$message->message,
		$message->date_sent
	));
	$queued_message_id = $wpdb->insert_id;

	// insert meta
	$wpdb->query($wpdb->prepare(
		"INSERT INTO {$wpdb->prefix}" . BP_MPM_MESSAGES_META_TABLE_NAME . " VALUES(DEFAULT, %d, %s, '');",
		$queued_message_id,
		BP_MPM_ALREADY_SENT_META_NAME
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
	$accept_link = admin_url() . 'admin.php?page=bp-moderate-private-messages&id=' . $queued_message_id . '&action=accept';
	$reject_link = admin_url() . 'admin.php?page=bp-moderate-private-messages&id=' . $queued_message_id . '&action=reject';
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

	// C. Notifications
	// shall we notify sender that a message was queud for moderation ?
	$notify_when_queued = get_option(BP_MPM_OPTION_NOTIFY_WHEN_QUEUED);
	if ($notify_when_queued == 1) {
		bp_notifications_add_notification(array(
			'user_id'           => $message->sender_id,
			'item_id'           => $queued_message_id,
			'component_name'    => 'bp-moderate-private-messages',
			'component_action'  => 'bp_mpm_messages_queued_for_moderation',
			'date_notified'     => bp_core_current_time(),
			'is_new'            => 1,
		));
	}
	// no need to notify the superadmins; the moderation message sent to them
	// generates a notification

	//echo "Message bricolé: <pre>"; var_dump($message); echo "</pre><br><br>";
	//exit;
}

/**
 * Registers a new "component" for notifications :
 * 
 * Taken from :
 * https://webdevstudios.com/2015/10/06/buddypress-adding-custom-notifications/
 */
function bp_mpm_custom_filter_notifications_get_registered_components($component_names = array()) {
	// Force $component_names to be an array
	if (! is_array($component_names)) {
		$component_names = array();
	}
	// Add 'custom' component to registered components array
	array_push($component_names, 'bp-moderate-private-messages');
	// Return component's with 'custom' appended
	return $component_names;
}
add_filter('bp_notifications_get_registered_components', 'bp_mpm_custom_filter_notifications_get_registered_components');

/**
 * Registers new notification types :
 *  - to inform the sender that a message was queued for moderation
 *  - to inform the sender that a message was accepted
 *  - to inform the sender that a message was rejected
 * 
 * Taken from :
 * https://webdevstudios.com/2015/10/06/buddypress-adding-custom-notifications/
 */
function bp_mpm_custom_format_buddypress_notifications($action, $item_id, $secondary_item_id, $total_items, $format, $component_action_name, $component_name, $id) {
	// common task: fetch moderated message subject from db
	if (in_array($component_action_name, array('bp_mpm_messages_queued_for_moderation', 'bp_mpm_messages_accepted', 'bp_mpm_messages_rejected'))) {
		global $wpdb;
		$message_subject = $wpdb->get_col(
			"SELECT subject FROM {$wpdb->prefix}" . BP_MPM_MODERATED_MESSAGES_TABLE_NAME
			. " WHERE id = $item_id;"
		);
		$message_subject = $message_subject[0];
		// truncate subject if too long
		if (strlen($message_subject) > 40) {
			$message_subject = substr($message_subject, 0, 37) . '...';
		}
	}

	// New custom notification : a message was queued for moderation
	if ('bp_mpm_messages_queued_for_moderation' === $component_action_name) {
		$custom_text = sprintf(__('Your message [%s] was queued for moderation', 'bp-moderate-private-messages'), $message_subject);
		// WordPress Toolbar
		if ('string' === $format) {
			return $custom_text;
		// Deprecated BuddyBar
		} else {
			return array(
				'text' => $custom_text
			);
		}
	}
	// New custom notification : a message was accepted
	elseif ('bp_mpm_messages_accepted' === $component_action_name) {
		$custom_text = sprintf(__('Your message [%s] was accepted and sent to its recipients', 'bp-moderate-private-messages'), $message_subject);
		// WordPress Toolbar
		if ('string' === $format) {
			return $custom_text;
		// Deprecated BuddyBar
		} else {
			return array(
				'text' => $custom_text
			);
		}
	}
	// New custom notification : a message was rejected
	elseif ('bp_mpm_messages_rejected' === $component_action_name) {
		$custom_text = sprintf(__('Your message [%s] was rejected', 'bp-moderate-private-messages'), $message_subject);
		// WordPress Toolbar
		if ('string' === $format) {
			return $custom_text;
		// Deprecated BuddyBar
		} else {
			return array(
				'text' => $custom_text
			);
		}
	}
	return $action;
}
add_filter('bp_notifications_get_notifications_for_user', 'bp_mpm_custom_format_buddypress_notifications', 10, 8);
