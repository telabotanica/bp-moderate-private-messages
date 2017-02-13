<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

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
	add_submenu_page(
		'bp-moderate-private-messages',
		__('Messages awaiting moderation', 'bp-moderate-private-messages'),
		__('Messages awaiting moderation', 'bp-moderate-private-messages'),
		'manage_options',
		'bp-moderate-private-messages',
		'bp_mpm_moderated_messages_list'
	);

	// Adds an options subpage
	//$hook = 
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
 * Depending on the "action" parameter :
 *  - displays a list of messages awaiting moderation (default)
 *  - displays a confirmation form to accept a message (action="accept")
 *  - displays a confirmation form to reject a message (action="reject")
 *  - accepts or rejects a message (action="doaccept" or "doreject" and
 *    confirmation form nonce field present)
 */
function bp_mpm_moderated_messages_list() {
	// action requested
	$action = 'list';
	if (!empty($_REQUEST['action'])) {
		$action = $_REQUEST['action'];
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

	// common text for moderation operations
	if ($action == 'accept' || $action == 'reject' || $action == 'doaccept' || $action == 'doreject') {
		// get moderated message
		global $wpdb;
		$message = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}" . BP_MPM_MODERATED_MESSAGES_TABLE_NAME
			. " WHERE id = $id"
			. " AND deleted = 0;"
		);
		// display confirmation form
		?>
		<div class="wrap">
			<h1>
				<?php
				if ($action == 'accept') {
					_e('Accept a message', 'bp-moderate-private-messages');
				} elseif ($action == 'reject') {
					_e('Reject a message', 'bp-moderate-private-messages');
				}
				?>
			</h1>
			<?php
			// called with wrong message id or message already moderated ?
			if (! $message) {
				_e('Message not found', 'bp-moderate-private-messages');
				return;
			}
			$sender = new WP_User($message->sender_id);

			// do action for real ?
			if (($action == 'doaccept' || $action == 'doreject') && $id !== false) {
				check_admin_referer('accept_or_reject_message');
				// A. Send message (or not) and notifications
				if ($action == 'doaccept') {
					// 1. send the message by briefly disabling moderation hook
					remove_action('messages_message_before_save', 'bp_mpm_moderate_before_save', 10);
					// Send message
					$args = array(
						'recipients' => explode(',', $message->recipients),
						'subject' => $message->subject,
						'content' => $message->message,
						'sender_id' => $message->sender_id,
						'date_sent', $message->date_sent // doesn't seem to work...
					);
					$messageId = messages_new_message($args);
					echo "Message envoyé: ["; var_dump($messageId); echo "]<br>";
					add_action('messages_message_before_save', 'bp_mpm_moderate_before_save', 10, 1);

					// 2. notify sender ?
					$notify_when_accepted = get_option(BP_MPM_OPTION_NOTIFY_WHEN_ACCEPTED);
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
				if ($action == 'doreject') {
					// do nothing with the message
					// notify sender ?
					$notify_when_rejected = get_option(BP_MPM_OPTION_NOTIFY_WHEN_REJECTED);
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
				// B. Remove message from moderated messages list
				// soft delete ?
				$soft_delete = get_option(BP_MPM_OPTION_SOFT_DELETE);
				$query = '';
				if ($soft_delete == 1) {
					$query .= "UPDATE " . $wpdb->prefix . BP_MPM_MODERATED_MESSAGES_TABLE_NAME . " SET deleted=1";
				} else {
					$query .= "DELETE FROM " . $wpdb->prefix . BP_MPM_MODERATED_MESSAGES_TABLE_NAME;
				}
				$query .= " WHERE id=%d;";
				$wpdb->query($wpdb->prepare($query, $message->id));
				// confirmation message
				?>
				<div class="updated">
					<p>
						<strong><?php
							if($action == 'doaccept') {
								_e('Message accepted and sent succesfully', 'bp-moderate-private-messages');
							} elseif ($action == 'doreject') {
								_e('Message rejected succesfully', 'bp-moderate-private-messages');
							}
						?></strong>
					</p>
				</div>
				<?php

			} elseif (($action == 'accept' || $action == 'reject') && $id !== false) {
				// outputs the confirmation form ?>
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
						_e('this message', 'bp-moderate-private-messages');
						?> :
					</p>
					<ul>
						<li>
							<input name="id" value="<?php echo $id ?>" type="hidden">
							<strong>[<?php echo stripslashes($message->subject) ?>]</strong>
							<?php _e('sent to', 'bp-moderate-private-messages') ?>
							<strong><?php echo count(explode(',', $message->recipients)) ?></strong>
							<?php _e('recipients', 'bp-moderate-private-messages') ?>
							<?php _e('by', 'bp-moderate-private-messages') ?>
							<i><?php echo $sender->display_name ?></i>
							(<?php _e('date', 'bp-moderate-private-messages') ?> :
							<?php echo $message->date_sent ?>)
						</li>
					</ul>
					<input name="action" value="do<?php echo $action ?>" type="hidden">
					<p class="submit">
						<input name="submit" id="submit" class="button button-primary" type="submit"
							value="<?php _e('Confirm this action', 'bp-moderate-private-messages') ?>"
						>
					</p>
				</form>
				<?php
			} ?>
			</div>
		<?php
	} else {
		// default action: list messages awaiting moderation
		echo "Une liste de messages de guedin !!";
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
		<div class="updated"><p><strong>Options mises à jour</strong></p></div>
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
								Notify when queued
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
								Notify when accepted
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
								Notify when rejected
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
