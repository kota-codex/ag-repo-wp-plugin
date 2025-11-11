<?php
/**
 * Uninstall script for Argentum Package Manager Module Repository
 *
 * It removes the database tables and options created by the plugin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Define table names
$table_users = $wpdb->prefix . 'ag_repo_users';
$table_redirects = $wpdb->prefix . 'ag_repo_redirects';

// Drop tables
$wpdb->query("DROP TABLE IF EXISTS $table_users");
$wpdb->query("DROP TABLE IF EXISTS $table_redirects");

// Delete plugin options
delete_option('ag_repo_db_version');
?>