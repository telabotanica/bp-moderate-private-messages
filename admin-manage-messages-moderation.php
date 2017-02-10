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

function bp_mpm_moderated_messages_list() {
	echo "Une liste de messages de guedin !!";
}

function bp_mpm_moderated_messages_options() {
	// if form was submitted
	if( isset($_POST[BP_MPM_OPTIONS_HIDDEN_VALIDATION_FIELD_NAME]) && $_POST[BP_MPM_OPTIONS_HIDDEN_VALIDATION_FIELD_NAME] == 'Y' ) {
		// update option value
		update_option(BP_MPM_OPTION_RECIPIENTS_LIMIT, $_POST[BP_MPM_OPTION_RECIPIENTS_LIMIT]);
		update_option(BP_MPM_OPTION_NOTIFY_WHEN_QUEUED, (isset($_POST[BP_MPM_OPTION_NOTIFY_WHEN_QUEUED]) ? '1' : '0'));
		update_option(BP_MPM_OPTION_NOTIFY_WHEN_ACCEPTED, (isset($_POST[BP_MPM_OPTION_NOTIFY_WHEN_ACCEPTED]) ? '1' : '0'));
		update_option(BP_MPM_OPTION_NOTIFY_WHEN_REJECTED, (isset($_POST[BP_MPM_OPTION_NOTIFY_WHEN_REJECTED]) ? '1' : '0'));
		?>
		<div class="updated"><p><strong>Options mises à jour</strong></p></div>
		<?php
	}

	// get current options values
	$recipients_limit = get_option(BP_MPM_OPTION_RECIPIENTS_LIMIT);
	$notify_when_queued = get_option(BP_MPM_OPTION_NOTIFY_WHEN_QUEUED);
	$notify_when_accepted = get_option(BP_MPM_OPTION_NOTIFY_WHEN_ACCEPTED);
	$notify_when_rejected = get_option(BP_MPM_OPTION_NOTIFY_WHEN_REJECTED);

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
