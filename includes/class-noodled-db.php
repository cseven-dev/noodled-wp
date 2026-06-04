<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_DB {

	public static function install() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$sql = "
CREATE TABLE {$wpdb->prefix}noodled_notebooks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
  drop_to bigint(20) unsigned NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  color varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY owner_name (owner_id, name),
  KEY idx_owner (owner_id),
  KEY idx_drop (drop_to)
) $charset;

CREATE TABLE {$wpdb->prefix}noodled_notes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(255) NOT NULL,
  notebook_id bigint(20) unsigned NOT NULL,
  title varchar(500) NOT NULL,
  body longtext,
  source varchar(50) NOT NULL DEFAULT '',
  pinned tinyint(1) NOT NULL DEFAULT 0,
  sha varchar(40) NOT NULL DEFAULT '',
  meta_json text,
  created_at datetime NOT NULL,
  modified_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  deleted_from bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_notebook (notebook_id),
  KEY idx_deleted (deleted_at),
  KEY idx_modified (modified_at),
  KEY idx_deleted_from (deleted_from),
  KEY idx_list (notebook_id, deleted_at, pinned, modified_at),
  KEY idx_browse (deleted_at, pinned, modified_at),
  FULLTEXT KEY idx_search (title, body)
) ENGINE=InnoDB $charset;

CREATE TABLE {$wpdb->prefix}noodled_users (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(255) NOT NULL,
  display_name varchar(255) NOT NULL DEFAULT '',
  role enum('admin','member','pending') NOT NULL DEFAULT 'member',
  token varchar(64) DEFAULT NULL,
  token_expiry datetime DEFAULT NULL,
  session_token varchar(64) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login datetime DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY email (email)
) $charset;

CREATE TABLE {$wpdb->prefix}noodled_permissions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  notebook_id bigint(20) unsigned NOT NULL,
  can_read tinyint(1) NOT NULL DEFAULT 1,
  can_write tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY user_notebook (user_id, notebook_id)
) $charset;

CREATE TABLE {$wpdb->prefix}noodled_note_permissions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  note_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  can_write tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY note_user (note_id, user_id),
  KEY idx_user (user_id)
) $charset;

CREATE TABLE {$wpdb->prefix}noodled_attachments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  note_id bigint(20) unsigned NOT NULL,
  filename varchar(255) NOT NULL,
  file_path varchar(500) NOT NULL,
  mime_type varchar(100) NOT NULL DEFAULT '',
  file_size bigint(20) NOT NULL DEFAULT 0,
  sha varchar(40) NOT NULL DEFAULT '',
  exif longtext,
  alt varchar(500) NOT NULL DEFAULT '',
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_note (note_id)
) $charset;
";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// No shared default notebook: each user is seeded their own private
		// "My Notes" notebook on signup (see Noodled_Auth::seed_new_user).

		update_option( 'noodled_db_version', NOODLED_VERSION );
	}
}
