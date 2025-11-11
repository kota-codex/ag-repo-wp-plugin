<?php
/**
 * Plugin Name: Argentum Package Manager Module Repository
 * Description: Argentum programming language has built-in package manager can download modules from external repos. This WP plugin organizes module repo by linking modules to external URLs (github for example)
 * Version: 1.0.0
 * Author: Andrey Kalmatskiy ak@aglang.org
 */

if (!defined('ABSPATH')) exit;

global $ag_repo_db_version;
$ag_repo_db_version = '1.0';

function ag_repo_install() {
    global $wpdb;
    global $ag_repo_db_version;

    $table_users = $wpdb->prefix . 'ag_repo_users';
    $table_modules = $wpdb->prefix . 'ag_repo_modules';

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $sql1 = "CREATE TABLE $table_users (
        id BIGINT UNSIGNED PRIMARY KEY,
        name VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
        email VARCHAR(191) COLLATE utf8mb4_bin NOT NULL
    ) COLLATE utf8mb4_bin;";
    dbDelta($sql1);

    $sql2 = "CREATE TABLE $table_modules (
        name VARCHAR(191) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
        description TEXT COLLATE utf8mb4_bin NOT NULL,
        version BIGINT UNSIGNED NOT NULL,
        url TEXT COLLATE utf8mb4_bin NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL
    ) COLLATE utf8mb4_bin;";
    dbDelta($sql2);

    add_option('ag_repo_db_version', $ag_repo_db_version);
}
register_activation_hook(__FILE__, 'ag_repo_install');

// REST API
add_action('rest_api_init', function () {
    register_rest_route('repo/v1', '/add', [
        'methods'  => 'POST',
        'callback' => 'ag_repo_add_module',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'args' => [
            'name' => ['required' => true, 'type' => 'string'],
            'description' => ['required' => false, 'type' => 'string'],
            'version' => ['required' => true, 'type' => 'integer'],
            'url' => ['required' => true, 'type' => 'string'],
        ],
    ]);

    register_rest_route('repo/v1', '/(?P<name>[a-zA-Z0-9]+)/(?P<version>\d+)', [
        'methods'  => 'GET',
        'callback' => 'ag_repo_get_module',
        'permission_callback' => '__return_true',
    ]);
});

function ag_repo_add_module(WP_REST_Request $request) {
    global $wpdb;
    $table_users = $wpdb->prefix . 'ag_repo_users';
    $table_modules = $wpdb->prefix . 'ag_repo_modules';

    $current_user = wp_get_current_user();
    $uid = $current_user->ID;

    // Check allowed users
    $allowed = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_users WHERE id = %d", $uid));
    if (!$allowed) {
        return new WP_Error('forbidden', 'User not allowed to add modules', ['status' => 403]);
    }

    $name        = sanitize_text_field($request['name']);
    $description = sanitize_textarea_field($request['description']);
    $version     = intval($request['version']);
    $url         = esc_url_raw($request['url']);

    // Check if entry already exists
    $existing = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table_modules WHERE name = %s AND version = %d", $name, $version)
    );

    if ($existing) {
        if ((int)$existing->user_id !== $uid) {
            return new WP_Error('forbidden_update', 'You cannot update a module added by another user', ['status' => 403]);
        }
        // Update existing record
        $updated = $wpdb->update(
            $table_modules,
            ['url' => $url, 'description' => $description],
            ['id' => $existing->id],
            ['%s','%s'],
            ['%d']
        );

        if ($updated === false) {
            return new WP_Error('db_update_error', 'Failed to update module', ['status' => 500]);
        }

        return ['success' => true, 'action' => 'updated'];
    }

    // Insert new record
    $inserted = $wpdb->insert($table_modules, [
        'name'        => $name,
        'description' => $description,
        'version'     => $version,
        'url'         => $url,
        'user_id'     => $uid,
    ], ['%s', '%s', '%d', '%s', '%d']);

    if ($inserted === false) {
        return new WP_Error('db_insert_error', 'Failed to insert module', ['status' => 500]);
    }

    return ['success' => true, 'action' => 'inserted'];
}

function ag_repo_get_module(WP_REST_Request $request) {
    global $wpdb;
    $table_modules = $wpdb->prefix . 'ag_repo_modules';

    $name = sanitize_text_field($request['name']);
    $req_version = intval($request['version']);

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT url, version FROM $table_modules WHERE name = %s LIMIT 1", $name)
    );

    if (!$row) {
        return new WP_Error('not_found', 'Module not found', ['status' => 404]);
    }

    if ($row->version < $req_version) {
        return new WP_Error('outdated', 'Requested version is newer than ' . $row->version, ['status' => 404]);
    }
    wp_redirect($row->url, 302);
    exit;
}

// Shortcode for displaying modules
function ag_modules_shortcode() {
    global $wpdb;
    $table_modules = $wpdb->prefix . 'ag_repo_modules';
    $table_users = $wpdb->prefix . 'ag_repo_users';

    $rows = $wpdb->get_results("
        SELECT r.name, r.description, r.version, r.url, u.name AS author_name, u.email AS author_email
        FROM $table_modules r
        LEFT JOIN $table_users u ON r.user_id = u.id
        ORDER BY r.name, r.version DESC
    ");

    ob_start();
    ?>
    <div class="ag-modules">
        <h2>Argentum Modules</h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Version</th>
                    <th>Description</th>
                    <th>URL</th>
                    <th>Author</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row->name); ?></td>
                        <td><?php echo esc_html($row->version); ?></td>
                        <td><?php echo esc_html($row->description); ?></td>
                        <td><a href="<?php echo esc_url($row->url); ?>" target="_blank" style="word-break: break-all;overflow-wrap: break-word;"><?php echo esc_html($row->url); ?></a></td>
                        <td><?php echo esc_html($row->author_name . " (" . $row->author_email . ")"); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('ag_modules', 'ag_modules_shortcode');

add_action('admin_menu', function () {
    add_menu_page(
        'AgModules',
        'AgModules',
        'edit_posts',
        'ag-modules',
        null, // No callback for top-level menu
        'dashicons-randomize'
    );
    add_submenu_page(
        'repo-modules',
        'Manage Users',
        'Manage Users',
        'manage_options',
        'ag-modules',
        'ag_repo_users_page'
    );
    add_submenu_page(
        'repo-modules',
        'Manage modules',
        'Manage modules',
        'edit_posts',
        'ag-modules',
        'ag_repo_modules_manage_page'
    );
});
function ag_repo_users_page() {
    global $wpdb;
    $table_users = $wpdb->prefix . 'ag_repo_users';

    // Handle form submissions
    if (isset($_POST['ag_user_action']) && check_admin_referer('ag_user_action_nonce')) {
        if ($_POST['ag_user_action'] === 'add') {
            $user_id = intval($_POST['user_id']);
            $name = sanitize_text_field($_POST['name']);
            $email = sanitize_email($_POST['email']);
            if ($user_id && $name && $email) {
                $wpdb->insert($table_users, [
                    'id' => $user_id,
                    'name' => $name,
                    'email' => $email
                ], ['%d', '%s', '%s']);
            }
        } elseif ($_POST['ag_user_action'] === 'delete' && isset($_POST['user_id'])) {
            $user_id = intval($_POST['user_id']);
            $wpdb->delete($table_users, ['id' => $user_id], ['%d']);
        }
    }

    $users = $wpdb->get_results("SELECT * FROM $table_users ORDER BY name");
    $wp_users = get_users(['role__in' => ['administrator', 'editor', 'author', 'contributor']]);
    ?>
    <div class="wrap">
        <h1>Manage Repository Users</h1>
        
        <!-- Add User Form -->
        <h2>Add New User</h2>
        <form method="post" action="">
            <?php wp_nonce_field('ag_user_action_nonce'); ?>
            <input type="hidden" name="ag_user_action" value="add">
            <table class="form-table">
                <tr>
                    <th><label for="user_id">Select User</label></th>
                    <td>
                        <select name="user_id" id="user_id" required>
                            <option value="">Select a user</option>
                            <?php foreach ($wp_users as $user): ?>
                                <option value="<?php echo esc_attr($user->ID); ?>">
                                    <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="name">Name</label></th>
                    <td><input type="text" name="name" id="name" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="email">Email</label></th>
                    <td><input type="email" name="email" id="email" class="regular-text" required></td>
                </tr>
            </table>
            <?php submit_button('Add User'); ?>
        </form>

        <!-- Users Table -->
        <h2>Current Users</h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo esc_html($user->id); ?></td>
                        <td><?php echo esc_html($user->name); ?></td>
                        <td><?php echo esc_html($user->email); ?></td>
                        <td>
                            <form method="post" action="" style="display:inline;">
                                <?php wp_nonce_field('ag_user_action_nonce'); ?>
                                <input type="hidden" name="ag_user_action" value="delete">
                                <input type="hidden" name="user_id" value="<?php echo esc_attr($user->id); ?>">
                                <input type="submit" class="button button-link-delete" value="Delete" onclick="return confirm('Are you sure you want to delete this user?');">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
function ag_repo_modules_manage_page() {
    global $wpdb;
    $table_modules = $wpdb->prefix . 'ag_repo_modules';
    $table_users = $wpdb->prefix . 'ag_repo_users';
    $current_user = wp_get_current_user();
    $is_admin = current_user_can('manage_options');
    
    // Handle form submissions
    if (isset($_POST['ag_redirect_action']) && check_admin_referer('ag_redirect_action_nonce')) {
        $name = sanitize_text_field($_POST['name']);
        $description = sanitize_textarea_field($_POST['description']);
        $version = intval($_POST['version']);
        $url = esc_url_raw($_POST['url']);
        
        if ($_POST['ag_redirect_action'] === 'add') {
            $allowed = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_users WHERE id = %d", $current_user->ID));
            if (!$allowed && !$is_admin) {
                echo '<div class="error"><p>You are not allowed to add modules.</p></div>';
            } else {
                $wpdb->insert($table_modules, [
                    'name' => $name,
                    'description' => $description,
                    'version' => $version,
                    'url' => $url,
                    'user_id' => $current_user->ID
                ], ['%s', '%s', '%d', '%s', '%d']);
                echo '<div class="updated"><p>Module added successfully.</p></div>';
            }
        } elseif ($_POST['ag_redirect_action'] === 'update') {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_modules WHERE name = %s", $name));
            if ($existing && ($existing->user_id == $current_user->ID || $is_admin)) {
                $wpdb->update(
                    $table_modules,
                    ['description' => $description, 'url' => $url, 'version' => $version],
                    ['name' => $name],
                    ['%s', '%s', '%d'],
                    ['%s']
                );
                echo '<div class="updated"><p>Module updated successfully.</p></div>';
            } else {
                echo '<div class="error"><p>You are not allowed to update this module.</p></div>';
            }
        } elseif ($_POST['ag_redirect_action'] === 'delete' && isset($_POST['name'])) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_modules WHERE name = %s", $name));
            if ($existing && ($existing->user_id == $current_user->ID || $is_admin)) {
                $wpdb->delete($table_modules, ['name' => $name], ['%s']);
                echo '<div class="updated"><p>Module deleted successfully.</p></div>';
            } else {
                echo '<div class="error"><p>You are not allowed to delete this module.</p></div>';
            }
        }
    }

    $query = $is_admin 
        ? "SELECT r.*, u.name AS author_name, u.email AS author_email FROM $table_modules r LEFT JOIN $table_users u ON r.user_id = u.id ORDER BY r.name, r.version DESC"
        : $wpdb->prepare("SELECT r.*, u.name AS author_name, u.email AS author_email FROM $table_modules r LEFT JOIN $table_users u ON r.user_id = u.id WHERE r.user_id = %d ORDER BY r.name, r.version DESC", $current_user->ID);
    $modules = $wpdb->get_results($query);
    ?>
    <div class="wrap">
        <h1>Manage Modules</h1>
        
        <!-- Add Module Form -->
        <h2>Add New Module</h2>
        <form method="post" action="">
            <?php wp_nonce_field('ag_redirect_action_nonce'); ?>
            <input type="hidden" name="ag_redirect_action" value="add">
            <table class="form-table">
                <tr>
                    <th><label for="name">Name</label></th>
                    <td><input type="text" name="name" id="name" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="description">Description</label></th>
                    <td><textarea name="description" id="description" class="large-text" rows="4"></textarea></td>
                </tr>
                <tr>
                    <th><label for="version">Version</label></th>
                    <td><input type="number" name="version" id="version" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="url">URL</label></th>
                    <td><input type="url" name="url" id="url" class="regular-text" required></td>
                </tr>
            </table>
            <?php submit_button('Add Module'); ?>
        </form>

        <!-- Modules Table -->
        <h2>Current Modules</h2>
        <table class="widefat fixed striped" style="box-sizing: border-box; width: 100%;">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Version</th>
                    <th>Description</th>
                    <th>URL</th>
                    <th>Author</th>
                    <th>Update</th>
                    <th>Delete</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($modules as $module): ?>
                    <tr>
                        <form method="post" action="">
                            <?php wp_nonce_field('ag_redirect_action_nonce'); ?>
                            <input type="hidden" name="ag_redirect_action" value="update">
							<input type="hidden" name="name" value="<?php echo esc_attr($module->name); ?>">
                            <td>
                                <?php echo esc_html($module->name); ?>
                            </td>
                            <td>
                                <input style="width:100%;" type="number" name="version" value="<?php echo esc_attr($module->version); ?>" class="regular-text" required <?php echo ($module->user_id == $current_user->ID || $is_admin) ? '' : 'disabled'; ?>>
                            </td>
                            <td>
                                <textarea style="width:100%;" name="description" class="large-text" rows="1" <?php echo ($module->user_id == $current_user->ID || $is_admin) ? '' : 'disabled'; ?>><?php echo esc_textarea($module->description); ?></textarea>
                            </td>
                            <td>
                                <input style="width:100%;" type="url" name="url" value="<?php echo esc_attr($module->url); ?>" class="regular-text" required <?php echo ($module->user_id == $current_user->ID || $is_admin) ? '' : 'disabled'; ?>>
                            </td>
                            <td>
                                <?php echo esc_html($module->author_name . " (" . $module->author_email . ")"); ?>
                            </td>
                            <td>
                                <?php if ($module->user_id == $current_user->ID || $is_admin): ?>
                                    <input type="submit" class="button" value="Update">
                                <?php endif; ?>
                            </td>
                        </form>
                            <td>
                                <?php if ($module->user_id == $current_user->ID || $is_admin): ?>
									<form method="post" action="" style="display:inline;">
										<?php wp_nonce_field('ag_redirect_action_nonce'); ?>
										<input type="hidden" name="ag_redirect_action" value="delete">
										<input type="hidden" name="name" value="<?php echo esc_attr($module->name); ?>">
										<input type="submit" class="button button-link-delete" value="Delete" onclick="return confirm('Are you sure you want to delete this module?');">
									</form>
                                <?php endif; ?>
                            </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>
