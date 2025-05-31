<?php
/**
 * Uninstall Bunny Auto Uploader
 *
 * Deletes all plugin data when the plugin is uninstalled.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete all plugin options
delete_option('bunny_auto_uploader_api_key');
delete_option('bunny_auto_uploader_storage_zone');
delete_option('bunny_auto_uploader_pull_zone_url');
delete_option('bunny_auto_uploader_ftp_host');
delete_option('bunny_auto_uploader_ftp_username');
delete_option('bunny_auto_uploader_ftp_password');
delete_option('bunny_auto_uploader_use_ftp');
delete_option('bunny_auto_uploader_errors');

// Delete all attachment meta
global $wpdb;
$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key = '_bunny_cdn_url'");
$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key = '_bunny_cdn_upload_failed'");
$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key = '_bunny_cdn_upload_time'");
$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key = '_bunny_cdn_upload_attempt_time'");
$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key = '_bunny_upload_error'"); 