<?php

if (! class_exists('WP_List_Table')) {
	require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * A list table display of all messages awaiting moderation
 */
class BP_Moderated_Messages_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(array(
			'singular' => __('Message', 'bp-moderate-private-messages'),
			'plural'   => __('Messages', 'bp-moderate-private-messages'),
			'ajax'     => false
		));
	}

	/**
	 * Retrieve customer’s data from the database
	 *
	 * @param int $per_page
	 * @param int $page_number
	 *
	 * @return mixed
	 */
	public static function get_moderated_messages($per_page = 20, $page_number = 1, $search=null) {

		global $wpdb;

		$sql = "SELECT * FROM {$wpdb->prefix}" . BP_MPM_MODERATED_MESSAGES_TABLE_NAME
			. " WHERE deleted=0";
		// search
		if ($search !== null) {
			$search_like = esc_sql('%' . $search . '%');
			$clauses = array(
				"subject LIKE '$search_like'",
				"message LIKE '$search_like'"
			);
			$sql .= " AND (" . implode(' OR ', $clauses) . ")";
		}
		// order
		if (! empty($_REQUEST['orderby'])) {
			$sql .= ' ORDER BY ' . esc_sql($_REQUEST['orderby']);
			$sql .= ! empty($_REQUEST['order']) ? ' ' . esc_sql($_REQUEST['order']) : ' ASC';
		}
		$sql .= " LIMIT $per_page";
		$sql .= ' OFFSET ' . ($page_number - 1) * $per_page;

		$result = $wpdb->get_results($sql, 'ARRAY_A');

		return $result;
	}
	/**
	 * Returns the count of records in the database.
	 *
	 * @return null|string
	 */
	public static function record_count($search=null) {
		global $wpdb;

		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}" . BP_MPM_MODERATED_MESSAGES_TABLE_NAME
			. " WHERE deleted=0";

		// search
		if ($search !== null) {
			$search_like = esc_sql('%' . $search . '%');
			$clauses = array(
				"subject LIKE '$search_like'",
				"message LIKE '$search_like'"
			);
			$sql .= " AND (" . implode(' OR ', $clauses) . ")";
		}

		return $wpdb->get_var($sql);
	}

	/** Text displayed when no messages are awaiting moderation */
	public function no_items() {
		_e('No messages awaiting moderation', 'bp-moderate-private-messages');
	}

	/**
	 * Method for name column
	 *
	 * @param array $item an array of DB data
	 *
	 * @return string
	 */
	function column_subject($item) {
		$subject = stripslashes($item['subject']);
		$subject_excerpt = $subject;
		if (strlen($subject_excerpt) > 50) {
			$subject_excerpt = substr($subject_excerpt, 0, 45) . '...';
		}

		$title = '<span title="' . $subject . '"><strong>' . $subject_excerpt . '</strong></span>';

		$accept_link = admin_url() . 'admin.php?page=bp-moderate-private-messages&id=' . $item['id'] . '&action=accept';
		$reject_link = admin_url() . 'admin.php?page=bp-moderate-private-messages&id=' . $item['id'] . '&action=reject';
		$actions = array(
			'accept' => '<span class="activate"><a href="' . $accept_link . '">' . __('Accept', 'bp-moderate-private-messages') . '</a></span>',
			'reject' => '<span class="delete"><a href="' . $reject_link . '">' . __('Reject', 'bp-moderate-private-messages') . '</a></span>'
		);

		return $title . $this->row_actions($actions);
	}

	/**
	 * Render a column when no column specific method exists
	 * @param array $item
	 * @param string $column_name
	 * @return mixed
	 */
	public function column_default($item, $column_name) {
		switch ($column_name) {
			case 'sender_id':
				return bp_core_get_userlink($item[$column_name]);
			case 'recipients':
				return self::format_recipients_count($item['id'], $item['recipients']);
			case 'message':
				$message_excerpt = stripslashes($item[$column_name]);
				if (strlen($message_excerpt) > 150) {
					$message_excerpt = substr($message_excerpt, 0, 140) . '...';
				}
				return $message_excerpt;
			case 'date_sent':
				return $item[$column_name];
			default:
				return print_r($item, true); // Show the whole array for troubleshooting purposes
		}
	}

	/**
	 * Render the bulk edit checkbox
	 * @param array $item
	 * @return string
	 */
	function column_cb($item) {
		return sprintf(
			'<input type="checkbox" name="id[]" value="%s" />', $item['id']
		);
	}

	/**
	 * Associative array of columns
	 * @return array
	 */
	function get_columns() {
		$columns = array(
			'cb' => '<input type="checkbox" />',
			'subject' => __('Subject', 'bp-moderate-private-messages'),
			'sender_id' => __('Sender', 'bp-moderate-private-messages'),
			'recipients' => __('Recipients', 'bp-moderate-private-messages'),
			'message' => __('Message', 'bp-moderate-private-messages'),
			'date_sent' => __('Date', 'bp-moderate-private-messages')
		);
		return $columns;
	}

	/**
	 * Columns to make sortable.
	 * @return array
	 */
	public function get_sortable_columns() {
		$sortable_columns = array(
			'subject' => array('subject', true),
			'sender_id' => array('sender_id', true),
			'date_sent' => array('date_sent', true)
		);
		return $sortable_columns;
	}

	/**
	 * Returns an associative array containing the bulk action
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		$actions = array(
			'accept' => __('Accept', 'bp-moderate-private-messages'),
			'reject' => __('Reject', 'bp-moderate-private-messages')
		);
		return $actions;
	}

	/**
	 * Handles data query and filter, sorting, and pagination.
	 */
	public function prepare_items() {

		$this->_column_headers = $this->get_column_info();

		// optional search term
		$search = null;
		if (! empty($_REQUEST['s'])) {
			$search = $_REQUEST['s'];
		}

		$per_page     = $this->get_items_per_page('messages_per_page', 20);
		$current_page = $this->get_pagenum();
		$total_items  = self::record_count($search);

		$this->set_pagination_args(array(
			'total_items' => $total_items,
			'per_page'    => $per_page
		));

		$this->items = self::get_moderated_messages($per_page, $current_page, $search);
	}

	/**
	 * Return recipients count formated string
	 *
	 * In case of already sent recipients then return remaining recipients count
	 * against total count
	 *
	 * Format:
	 * [int]: total
	 * or
	 * [string]: remaining/total
	 *
	 * @param      string   $message_id  The message id
	 * @param      string   $recipients  The recipients list (integers separated by commas)
	 *
	 * @return     Mixed  	int or string (see above)
	 */
	static function format_recipients_count($message_id, $recipients) {
		global $wpdb;

		// check for already sent recipients
		$already_sent_users = $wpdb->get_results(
			"SELECT meta_value FROM {$wpdb->prefix}" . BP_MPM_MESSAGES_META_TABLE_NAME
			. " WHERE message_id = $message_id AND meta_key = '" . BP_MPM_ALREADY_SENT_META_NAME . "'"
		);

		if (strlen($already_sent_users[0]->meta_value)) {
			$count = count(explode(',', $recipients));
			return ($count - count(explode(',', $already_sent_users[0]->meta_value))) . '/' . $count;
		} else {
			return count(explode(',', $recipients));
		}
	}
}
