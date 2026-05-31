<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}noodled_attachments" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}noodled_permissions" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}noodled_users" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}noodled_notes" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}noodled_notebooks" );

delete_option( 'noodled_settings' );
delete_option( 'noodled_db_version' );
