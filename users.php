<?PHP
/*
* Plugin Name: Users
* Description: This plugin to create custom contact list-tables from database using WP_List_Table class.
* Version:     1.0
* Author:      Alejandro Diaz
*/

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class User_List_Table extends WP_List_Table
{
	function __construct()
    {
        global $status, $page;

        parent::__construct(array(
            'singular' => 'user',
            'plural' => 'users',
        ));
    }
	
	public function column_default($user, $column_name)
    {
        return $user->$column_name;
    }

	public function column_cb($user)
    {
        return sprintf(
            '<input type="checkbox" name="uid[]" value="%s" />',
            $user->ID
        );
    }
	
	public function get_columns() {
        $columns = [
            'cb' => '<input type="checkbox" />',
            'display_name' => __('Name', 'user'),
            'user_email' => __('E-Mail', 'user'),
            'role' => __('Role', 'user'),
            'status' => __('Status', 'user'),
        ];

        return $columns;
    }
	
	public function column_display_name($user)
    {
        $actions = [
            'edit' => sprintf(
            	'<a href="?page=users_edit&uid=%s">%s</a>',
            	$user->ID,
            	__('Edit', 'user')
            ),
            'change-status' => sprintf(
            	'<a href="?page=%s&action=change_status&uid=%s">%s</a>',
            	$_REQUEST['page'],
            	$user->ID,
            	__('Change Status', 'user')
            ),
        ];

        return sprintf('%s %s',
            $user->display_name,
            $this->row_actions($actions)
        );
    }
	
	public function column_role($user)
    {
		$user_meta = get_userdata( $user->ID );
    	$user_roles = $user_meta->roles;
		
		return implode(', ', $user_roles);
    }

    public function column_status($user)
    {
    	return ($user->user_status) ? __('Active', 'user') : __('Inactive', 'user');
    }
	
	function get_sortable_columns()
    {
        $sortable_columns = array(
            'display_name' => array('display_name', true),
            'user_email' => array('user_email', true)
        );
        return $sortable_columns;
    }
	
	function get_bulk_actions()
    {
        $actions = array(
            'change_status' => 'Change status'
        );
        return $actions;
    }
	
	function process_bulk_action()
    {
		global $wpdb;
		if ('change_status' === $this->current_action()) 
		{
			$ids = isset($_REQUEST['uid']) ? $_REQUEST['uid'] : array();
			if (is_array($ids)) {
                foreach ($ids as $id) {
                    $this->update_status($id);
                }
            } else {
                $this->update_status($ids);
            }
		}
	}
	
	private function update_status($uid)
    {
        global $wpdb;
        $user = get_user_by('ID', $uid);

        $result = $wpdb->update(
            $wpdb->users,
            [
                'user_status' => ($user->user_status) ? 0 : 1,
            ],
            [ 'ID' => $user->ID ]
        );

        return $result;
    }
	
	public function prepare_items()
    {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = [$columns, $hidden, $sortable];

        $this->process_bulk_action();

        $per_page = 10;
        $paged = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' )  : 1;
        $offset = ( $paged - 1 ) * $per_page;

        $orderby = (isset($_REQUEST['orderby']) && in_array($_REQUEST['orderby'], array_keys($this->get_sortable_columns()))) ? $_REQUEST['orderby'] : 'display_name';
        $order = (isset($_REQUEST['order']) && in_array($_REQUEST['order'], array('asc', 'desc'))) ? $_REQUEST['order'] : 'asc';
        $role = isset($_REQUEST['role']) ? $_REQUEST['role'] : '';

        $args = [
            'role' => $role,
            'count_total' => true,
            'fields' => ['ID', 'display_name', 'user_email', 'user_status'],
            'orderby' => $orderby,
            'order' => $order,
            'number' => $per_page,
            'offset' => $offset,
        ];

        $users = new WP_User_Query( $args );
		$this->items = $users->get_results();
        $total_items = $users->get_total();

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ));
    }
	
}

	function user_admin_menu()
	{
		add_menu_page(
			__('Users', 'user'), 
			__('Users', 'user'), 
			'activate_plugins', 
			'users', 
			'users_page_handler'
		);
		
		add_submenu_page(
			null,
			__('Users', 'user'), 
			__('Users', 'user'), 
			'activate_plugins', 
			'users_edit', 
			'users_edit_page_handler'
		);
	}
	add_action('admin_menu', 'user_admin_menu');

	function users_page_handler()
	{
		global $wpdb;

		$table = new User_List_Table();
		$table->prepare_items();

		$message = '';
		if ('delete' === $table->current_action()) {
			$message = '<div class="updated below-h2" id="message"><p>' . sprintf(__('Items deleted: %d', 'wpbc'), count($_REQUEST['id'])) . '</p></div>';
		}
		?>
			<div class="wrap">

				<div class="icon32 icon32-posts-post" id="icon-edit"><br></div>
				<h2><?php _e('Users', 'wpbc')?></h2>
				<?php echo $message; ?>

				<form id="contacts-table" method="POST">
					<p class="search-box">
						<select id="role" name="role">
							<option value=""><?php _e('All Roles', 'user'); ?></option>
							<?php wp_dropdown_roles($data->role); ?>
						</select>
						<input type="submit" id="search-submit" class="button" value="<?php _e('Filter by Role', 'user'); ?>">
					</p>
					<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>"/>
					<?php $table->display() ?>
				</form>

			</div>
		<?php
	}
	
	
	function users_edit_page_handler()
	{	
		global $wpdb;

		$message = '';
		$notice = '';

		$uid = $_REQUEST['uid'] ?? 0;
		$user = false;
		
		if ( isset($_REQUEST['nonce']) && wp_verify_nonce($_REQUEST['nonce'], basename(__FILE__))) 
		{
			$user = $_REQUEST;
			if ($user['uid'] > 0) 
			{
				$update = $wpdb->update(
                    $wpdb->users,
                    [
                        'display_name' => $user['display_name'],
                        'user_status' => $user['user_status'],
                    ],
                    [ 'ID' => $user['uid'] ]
                );
				
				if ( $update ) {
                    $message = __('User was successfully updated', 'user');
                } else {
                    $notice = __('There was an error while updating user', 'user');
                }

                $user = get_user_by('ID', $user['uid']);
			}
		}else{
			if ($uid > 0) {
				$user = get_user_by('ID', $uid);
				if (false === $user) {
					$notice = __('User not found', 'user');
				}
			}
		}
		add_meta_box('users_form_meta_box', 'Edit user', 'edit_metabox', 'user', 'normal', 'default');
		
		?>
		<div class="wrap">
		<div class="icon32 icon32-posts-post" id="icon-edit"><br></div>
		<h2><?php _e('User', 'user')?> <a class="add-new-h2"
									href="<?php echo get_admin_url(get_current_blog_id(), 'admin.php?page=users');?>"><?php _e('back to list', 'user')?></a>
		</h2>

		<?php if (!empty($notice)): ?>
		<div id="notice" class="error"><p><?php echo $notice ?></p></div>
		<?php endif;?>
		<?php if (!empty($message)): ?>
		<div id="message" class="updated"><p><?php echo $message ?></p></div>
		<?php endif;?>

		<form id="form" method="POST">
			<input type="hidden" name="nonce" value="<?php echo wp_create_nonce(basename(__FILE__))?>"/>
			<?php /* NOTICE: here we storing id to determine will be item added or updated */ ?>
			<input type="hidden" name="id" value="<?php  echo($user->id); ?>"/>

			<div class="metabox-holder" id="poststuff">
				<div id="post-body">
					<div id="post-body-content">
						<?php /* And here we call our custom meta box */ ?>
						<?php do_meta_boxes('user', 'normal', $user); ?>
						<input type="submit" value="<?php _e('Save', 'user')?>" id="submit" class="button-primary" name="submit">
					</div>
				</div>
			</div>
		</form>
	</div>
	<?php
	}
	
	
function edit_metabox($user)
{
	?>
	<table cellspacing="2" cellpadding="5" style="width: 100%;" class="form-table">
		<tbody>
		<tr class="form-field">
			<th valign="top" scope="row">
				<label for="name"><?php _e('Name', 'user')?></label>
			</th>
			<td>
				<input id="display_name" name="display_name" type="text" style="width: 95%" value="<?php echo esc_attr($user->display_name)?>"
					   size="50" class="code" placeholder="<?php _e('Your name', 'user')?>" required>
			</td>
		</tr>
		<tr class="form-field">
			<th valign="top" scope="row">
				<label for="email"><?php _e('E-Mail', 'user')?></label>
			</th>
			<td>
				<input disabled id="user_email" name="user_email" type="email" style="width: 95%" value="<?php echo esc_attr($user->user_email)?>"
					   size="50" class="code" placeholder="<?php _e('Your E-Mail', 'user')?>" required>
			</td>
		</tr>
		<tr class="form-field">
			<th valign="top" scope="row">
				<label for="age"><?php _e('Role', 'user')?></label>
			</th>
			<td>
				<input disabled id="age" name="age" type="text" style="width: 95%" value="<?php echo esc_attr($user->roles[0])?>"
					   size="50" class="code" placeholder="<?php _e('Your age', 'user')?>" required>
			</td>
		</tr>
		<tr class="form-field">
			<th valign="top" scope="row">
				<label><?php _e('Status:', 'user')?></label>
			</th>
			<td>
				<label for="status-active">
					<input type="radio" name="user_status" id="status-active"
						value="1" <?php echo ($user->user_status) ? 'checked':''?>>
					<?php _e('Active', 'user'); ?>
				</label>
				<label for="status-inactive">
					<input type="radio" name="user_status" id="status-inactive"
						value="0" <?php echo (!$user->user_status) ? 'checked':''?>>
					<?php _e('Inactive', 'user'); ?>
				</label>
			</td>
		</tr>
		</tbody>
	</table>
	<?php
	}
?>