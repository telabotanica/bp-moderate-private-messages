<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-bp-moderated-messages-list-table.php';

const BP_MPM_OPTIONS_HIDDEN_VALIDATION_FIELD_NAME = 'BP_MPM_OPTIONS_HIDDEN_VALIDATION_FIELD_NAME';

function bp_mpm_admin_manage_messages_moderation() {

	// Adds a "private messages" menu to the admin
	add_menu_page(
		__('Private Messages', 'bp-moderate-private-messages'),
		__('Private Messages', 'bp-moderate-private-messages'),
		'manage_options',
		'bp-moderate-private-messages',
		'',
		'dashicons-format-chat',
		70
	);

	// Adds a subpage with the same slug to overwrite default page
	$list_hook = add_submenu_page(
		'bp-moderate-private-messages',
		__('Messages awaiting moderation', 'bp-moderate-private-messages'),
		__('Messages awaiting moderation', 'bp-moderate-private-messages'),
		'manage_options',
		'bp-moderate-private-messages',
		'bp_mpm_moderated_messages_list'
	);
	add_action("load-$list_hook", 'bp_mpm_screen_options');

	// Adds an options subpage
	add_submenu_page(
		'bp-moderate-private-messages',
		__('Private messages moderation options', 'bp-moderate-private-messages'),
		__('Options', 'bp-moderate-private-messages'),
		'manage_options',
		'bp-moderate-private-messages-options',
		'bp_mpm_moderated_messages_options'
	);
}
add_action('admin_menu', 'bp_mpm_admin_manage_messages_moderation');

/**
 * Screen options
 */
function bp_mpm_screen_options() {
	global $bp_messages_list_table;

	$option = 'per_page'; // doesn't seem to work, as though it is displayed
	$args = array(
		'label'   => __('Messages', 'bp-moderate-private-messages'),
		'default' => 20,
		'option'  => 'messages_per_page'
	);
	add_screen_option($option, $args);

	$bp_messages_list_table = new BP_Moderated_Messages_List_Table();
}

/**
 * Depending on the "action" parameter :
 *  - displays a list of messages awaiting moderation (default)
 *  - displays a confirmation form to accept a message (action="accept")
 *  - displays a confirmation form to reject a message (action="reject")
 *  - accepts or rejects a message (action="doaccept" or "doreject" and
 *    confirmation form nonce field present)
 */
function bp_mpm_moderated_messages_list() {
	global $bp_messages_list_table;
	global $wpdb;

	// action requested
	$action = 'list';
	if (!empty($_REQUEST['action'])) {
		$action = $_REQUEST['action'];
	}
	$bulk_action = $bp_messages_list_table->current_action();
	if ($bulk_action) {
		$action = $bulk_action;
	}
	
	// message id
	$id = false;
	if (!empty($_REQUEST['id'])) {
		$id = $_REQUEST['id'];
	}
	// access control
	if (!current_user_can('administrator')) {
		wp_die( __("You don't have permission to access this page") );
	}

	// first-step actions requiring confirmation
	if ($action == 'accept' || $action == 'reject') {
		// get (multiple) message(s)
		$ids_clause = '';
		if (is_array($id)) {
			$ids_clause = " WHERE id IN (" . implode(',', $id) . ")";
		} else {
			$ids_clause = " WHERE id = $id";
		}
		$messages = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}" . BP_MPM_MODERATED_MESSAGES_TABLE_NAME
			. $ids_clause
			. " AND deleted = 0;"
		);
		// display confirmation form
		?>
		<div class="wrap">
			<h1>
				<?php
				if ($action == 'accept') {
					_e('Accept message(s)', 'bp-moderate-private-messages');
				} elseif ($action == 'reject') {
					_e('Reject message(s)', 'bp-moderate-private-messages');
				}
				?>
			</h1>
			<?php
			//var_dump($messages);
			// called with wrong message id or message already moderated ?
			if (! $messages) {
				_e('Message not found', 'bp-moderate-private-messages');
				return;
			} ?>
			<form method="post" name="moderate-messages" id="moderate-messages">
				<?php wp_nonce_field('accept_or_reject_message'); ?>
				<p>
					<?php
					_e('You chose to', 'bp-moderate-private-messages');
					echo ' ';
					if ($action == 'accept') {
						_e('accept', 'bp-moderate-private-messages');
					} elseif ($action == 'reject') {
						_e('reject', 'bp-moderate-private-messages');
					}
					echo ' ';
					if (is_array($id)) { // multiple messages
						_e('these messages', 'bp-moderate-private-messages');
					} else {
						_e('this message', 'bp-moderate-private-messages');
					}
					?> :
				</p>
				<ul>
				<?php
				// generic loop processing
				foreach ($messages as $message) {
					$sender = new WP_User($message->sender_id);
					// outputs the confirmation form ?>
					<li>
						<input name="id[]" value="<?php echo $message->id ?>" type="hidden">
						<strong>[<?php echo stripslashes($message->subject) ?>]</strong>
						<?php _e('sent to', 'bp-moderate-private-messages') ?>
						<strong><?php echo count(explode(',', $message->recipients)) ?></strong>
						<?php _e('recipients', 'bp-moderate-private-messages') ?>
						<?php _e('by', 'bp-moderate-private-messages') ?>
						<i><?php echo $sender->display_name ?></i>
						(<?php _e('date', 'bp-moderate-private-messages') ?> :
						<?php echo $message->date_sent ?>)
					</li>
				<?php
				}
				?>
				</ul>
				<input name="action" value="do<?php echo $action ?>" type="hidden">
				<p class="submit">
					<input name="submit" id="submit" class="button button-primary" type="submit"
						value="<?php _e('Confirm this action', 'bp-moderate-private-messages') ?>"
					>
				</p>
			</form>
		</div>
	<?php
	} else { // default action: list messages awaiting moderation
		$notice_messages = array();

		// do action for real ?
		if (($action == 'doaccept' || $action == 'doreject')) {
			check_admin_referer('accept_or_reject_message');

			// get (multiple) message(s)
			$ids_clause = '';
			if (is_array($id)) {
				$ids_clause = " WHERE id IN (" . implode(',', $id) . ")";
			} else {
				$ids_clause = " WHERE id = $id";
			}
			$messages = $wpdb->get_results(
				"SELECT * FROM {$wpdb->prefix}" . BP_MPM_MODERATED_MESSAGES_TABLE_NAME
				. $ids_clause
				. " AND deleted = 0;"
			);

			// A. Send message(s) (or not) and notifications
			if ($action == 'doaccept') {
				// briefly disable moderation hook to avoid a moderation loop
				remove_action('messages_message_before_save', 'bp_mpm_moderate_before_save', 10);

				// prepare query for message deletion
				$soft_delete = get_option(BP_MPM_OPTION_SOFT_DELETE);
				$delete_message_query = '';
				if ($soft_delete == 1) {
					$delete_message_query .= "UPDATE " . $wpdb->prefix . BP_MPM_MODERATED_MESSAGES_TABLE_NAME . " SET deleted=1";
				} else {
					$delete_message_query .= "DELETE FROM " . $wpdb->prefix . BP_MPM_MODERATED_MESSAGES_TABLE_NAME;
				}

				// prepare query for recipient deletion
				$delete_recipient_query = "UPDATE {$wpdb->prefix}" . BP_MPM_MESSAGES_META_TABLE_NAME
					. " SET meta_value= concat(ifnull(meta_value, ''), '%s') WHERE message_id = %s AND meta_key = '"
					. BP_MPM_ALREADY_SENT_META_NAME ."'"
				;

				$notify_when_accepted = get_option(BP_MPM_OPTION_NOTIFY_WHEN_ACCEPTED);
				foreach ($messages as $message) {
					// get already sent user id
					$already_sent_users = $wpdb->get_results(
						"SELECT meta_value FROM {$wpdb->prefix}" . BP_MPM_MESSAGES_META_TABLE_NAME
						. " WHERE message_id = {$message->id} AND meta_key = '" . BP_MPM_ALREADY_SENT_META_NAME . "'"
					);
					// remove already sent user id from recipients list
					$recipients = array_diff(
						explode(',',$message->recipients),
						explode(',',$already_sent_users[0]->meta_value)
					);

					foreach ($recipients as $recipient) {
						// Send message
						$args = array(
							'recipients' => $recipient,
							'subject' => $message->subject,
							'content' => $message->message,
							'sender_id' => $message->sender_id,
							'date_sent', $message->date_sent // doesn't seem to work...
						);
						$messageId = messages_new_message($args);
						// TODO test $messageId to check if it was successfully sent

						// Remove recipient
						$remove_recipient_query = sprintf($delete_recipient_query, ',' . $recipient, $message->id);
						$wpdb->query($remove_recipient_query);
					}

					// Remove sent message from DB
					$wpdb->query($delete_message_query . " WHERE id = $message->id");

					// notify sender ?
					if ($notify_when_accepted == 1) {
						bp_notifications_add_notification(array(
							'user_id'           => $message->sender_id,
							'item_id'           => $message->id,
							'component_name'    => 'bp-moderate-private-messages',
							'component_action'  => 'bp_mpm_messages_accepted',
							'date_notified'     => bp_core_current_time(),
							'is_new'            => 1,
						));
					}
				}

				// restore moderation hook
				add_action('messages_message_before_save', 'bp_mpm_moderate_before_save', 10, 1);
			}
			if ($action == 'doreject') {
				$notify_when_rejected = get_option(BP_MPM_OPTION_NOTIFY_WHEN_REJECTED);
				foreach ($messages as $message) {
					// do nothing with the message

					// notify sender(s) ?
					if ($notify_when_rejected == 1) {
						bp_notifications_add_notification(array(
							'user_id'           => $message->sender_id,
							'item_id'           => $message->id,
							'component_name'    => 'bp-moderate-private-messages',
							'component_action'  => 'bp_mpm_messages_rejected',
							'date_notified'     => bp_core_current_time(),
							'is_new'            => 1,
						));
					}
				}
			}
			// B. Remove message(s) from moderated messages list
			// soft delete ?
			$soft_delete = get_option(BP_MPM_OPTION_SOFT_DELETE);
			$query = '';
			if ($soft_delete == 1) {
				$query .= "UPDATE " . $wpdb->prefix . BP_MPM_MODERATED_MESSAGES_TABLE_NAME . " SET deleted=1";
			} else {
				$query .= "DELETE FROM " . $wpdb->prefix . BP_MPM_MODERATED_MESSAGES_TABLE_NAME;
			}
			$query .= $ids_clause;
			$wpdb->query($query);

			// confirmation message
			if ($action == 'doaccept') {
				$notice_messages[] = sprintf(__( '%s message(s) accepted successfully', 'bp-moderate-private-messages'), number_format_i18n(count($messages)));
			} elseif ($action == 'doreject') {
				$notice_messages[] = sprintf(__( '%s message(s) rejected successfully', 'bp-moderate-private-messages'), number_format_i18n(count($messages)));
			}
		}

		// Prepare the group items for display.
		$bp_messages_list_table->prepare_items();
		?>

		<div class="wrap">
			<h1>
				<?php _e('Messages awaiting moderation', 'bp-moderate-private-messages'); ?>

				<?php if (! empty($_REQUEST['s'])) : ?>
					<span class="subtitle"><?php printf(__('Search results for &#8220;%s&#8221;', 'buddypress'), wp_html_excerpt(esc_html(stripslashes($_REQUEST['s'])), 50 )); ?></span>
				<?php endif; ?>
			</h1>

			<?php // If the user has just made a change to a message, display the status messages ?>
			<?php if (! empty($notice_messages)) : ?>
				<div id="moderated" class="<?php echo (! empty($_REQUEST['error'])) ? 'error' : 'updated'; ?>"><p><?php echo implode("<br/>\n", $notice_messages); ?></p></div>
			<?php endif; ?>

			<?php //$bp_messages_list_table->views(); ?>

			<form id="bp-groups-form" action="" method="get">
				<?php $bp_messages_list_table->search_box(__('Search messages awaiting moderation', 'bp-moderate-private-messages'), 'bp-messages'); ?>
				<input type="hidden" name="page" value="bp-moderate-private-messages" />
			</form>
			<form id="bp-groups-form" action="" method="get">
				<?php $bp_messages_list_table->display(); ?>
				<input type="hidden" name="page" value="bp-moderate-private-messages" />
			</form>
		</div>

	<?php
	}
}

/**
 * Displays the options page
 */
function bp_mpm_moderated_messages_options() {
	// if form was submitted
	if( isset($_POST[BP_MPM_OPTIONS_HIDDEN_VALIDATION_FIELD_NAME]) && $_POST[BP_MPM_OPTIONS_HIDDEN_VALIDATION_FIELD_NAME] == 'Y' ) {
		// update option value
		update_option(BP_MPM_OPTION_RECIPIENTS_LIMIT, $_POST[BP_MPM_OPTION_RECIPIENTS_LIMIT]);
		update_option(BP_MPM_OPTION_NOTIFY_WHEN_QUEUED, (isset($_POST[BP_MPM_OPTION_NOTIFY_WHEN_QUEUED]) ? '1' : '0'));
		update_option(BP_MPM_OPTION_NOTIFY_WHEN_ACCEPTED, (isset($_POST[BP_MPM_OPTION_NOTIFY_WHEN_ACCEPTED]) ? '1' : '0'));
		update_option(BP_MPM_OPTION_NOTIFY_WHEN_REJECTED, (isset($_POST[BP_MPM_OPTION_NOTIFY_WHEN_REJECTED]) ? '1' : '0'));
		update_option(BP_MPM_OPTION_SOFT_DELETE, (isset($_POST[BP_MPM_OPTION_SOFT_DELETE]) ? '1' : '0'));
		?>
		<div class="updated">
			<p>
				<strong>
					<?php _e('Options updated', 'bp-moderate-private-messages') ?>
				</strong>
			</p>
		</div>
		<?php
	}

	// get current options values
	$recipients_limit = get_option(BP_MPM_OPTION_RECIPIENTS_LIMIT);
	$notify_when_queued = get_option(BP_MPM_OPTION_NOTIFY_WHEN_QUEUED);
	$notify_when_accepted = get_option(BP_MPM_OPTION_NOTIFY_WHEN_ACCEPTED);
	$notify_when_rejected = get_option(BP_MPM_OPTION_NOTIFY_WHEN_REJECTED);
	$soft_delete = get_option(BP_MPM_OPTION_SOFT_DELETE);

	?>
	<div class="wrap">
		<?php
		if (!current_user_can('manage_options')) {
			wp_die( __("You don't have permission to access this page") );
		} ?>

		<?php screen_icon(); ?>

		<!-- Titre -->
		<h2><?php _e('Private messages moderation options', 'bp-moderate-private-messages') ?></h2>

		<?php settings_errors(); ?>

		<form method="post" action="">
			<input type="hidden" name="<?php echo BP_MPM_OPTIONS_HIDDEN_VALIDATION_FIELD_NAME; ?>" value="Y">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="<?php echo BP_MPM_OPTION_RECIPIENTS_LIMIT ?>">
								<?php _e('Recipients limit', 'bp-moderate-private-messages') ?>
							</label>
						</th>
						<td>
							<input type="text"
								id="<?php echo BP_MPM_OPTION_RECIPIENTS_LIMIT ?>"
								name="<?php echo BP_MPM_OPTION_RECIPIENTS_LIMIT ?>"
								value="<?php echo $recipients_limit ?>"
							/>
							<p class="description">
								<?php _e('Moderation will be applied to any message sent to a number of recipients exceeding the number above', 'bp-moderate-private-messages') ?>
								<br>
								<?php _e('Set to 1 to moderate all messages, leave empty or set to 0 to disable moderation', 'bp-moderate-private-messages') ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="<?php echo BP_MPM_OPTION_NOTIFY_WHEN_QUEUED ?>">
								<?php _e('Notification options', 'bp-moderate-private-messages') ?>
							</label>
						</th>
						<td>
							<label>
								<input type="checkbox"
									id="<?php echo BP_MPM_OPTION_NOTIFY_WHEN_QUEUED ?>"
									name="<?php echo BP_MPM_OPTION_NOTIFY_WHEN_QUEUED ?>"
									<?php echo $notify_when_queued ? ' checked="checked"' : '' ?>
								/>
								<?php _e('Notify when queued', 'bp-moderate-private-messages') ?>
							</label>
							<p class="description">
								<?php _e('Notify the sender when a message was queued for moderation', 'bp-moderate-private-messages') ?>
							</p>
							<br>
							<label>
								<input type="checkbox"
									id="<?php echo BP_MPM_OPTION_NOTIFY_WHEN_ACCEPTED ?>"
									name="<?php echo BP_MPM_OPTION_NOTIFY_WHEN_ACCEPTED ?>"
									<?php echo $notify_when_accepted ? ' checked="checked"' : '' ?>
								/>
								<?php _e('Notify when accepted', 'bp-moderate-private-messages') ?>
							</label>
							<p class="description">
								<?php _e('Notify the sender when a message was accpeted', 'bp-moderate-private-messages') ?>
							</p>
							<br>
							<label>
								<input type="checkbox"
									id="<?php echo BP_MPM_OPTION_NOTIFY_WHEN_REJECTED ?>"
									name="<?php echo BP_MPM_OPTION_NOTIFY_WHEN_REJECTED ?>"
									<?php echo $notify_when_rejected ? ' checked="checked"' : '' ?>
								/>
								<?php _e('Notify when rejected', 'bp-moderate-private-messages') ?>
							</label>
							<p class="description">
								<?php _e('Notify the sender when a message was rejected', 'bp-moderate-private-messages') ?>
							</p>
						</td>
					</tr>
					<!--Forcing soft delete to prevent error in notifications formatting -->
					<!--<tr>
						<th scope="row">
							<label for="<?php echo BP_MPM_OPTION_SOFT_DELETE ?>">
								<?php _e('Soft delete', 'bp-moderate-private-messages') ?>
							</label>
						</th>
						<td>
							<label>
								<input type="checkbox"
									id="<?php echo BP_MPM_OPTION_SOFT_DELETE ?>"
									name="<?php echo BP_MPM_OPTION_SOFT_DELETE ?>"
									<?php echo $soft_delete ? ' checked="checked"' : '' ?>
								/>
								Enable soft delete
							</label>
							<p class="description">
								<?php _e('When accepted or rejected, keep messages stored in the moderated messages tables, with a "deleted" marker set to "1"', 'bp-moderate-private-messages') ?>
								<br>
								<?php _e('Note: when uninstalling this plugin, all moderated messages history will be lost', 'bp-moderate-private-messages') ?>
							</p>
						</td>
					</tr>-->
				</tbody>
			</table>
			<hr/>
			<!-- Enregistrer les modifications -->
			<p class="submit">
				<input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" />
			</p>
		</form>
	</div>	
<?php
}
