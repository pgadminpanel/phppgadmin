<?php

/**
 * Central phpPgAdmin configuration.  As a user you may modify the
 * settings here for your particular configuration.
 *
 * $Id: config.inc.php-dist,v 1.55 2008/02/18 21:10:31 xzilla Exp $
 */

// Encryption key for securing credentials
// (optional but required for 'config' auth_type)
// Generate a new key by running:
//     php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
// Or use: php bin/encrypt-password.php --generate-key
// Store in environment variable PHPPGADMIN_ENCRYPTION_KEY (recommended)
// or here:
// $conf['encryption_key'] = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
// IMPORTANT: Keep this key secret! Anyone with this key can decrypt
// stored passwords. If this key changes, all sessions will be invalidated
// and users must re-authenticate.

// An example server.  Create as many of these as you wish,
// indexed from zero upwards.

// Display name for the server on the login screen
$conf['servers'][0]['desc'] = 'PostgreSQL';

// Hostname or IP address for server.  Use '' for UNIX domain socket.
// use 'localhost' for TCP/IP connection on this computer
$conf['servers'][0]['host'] = 'localhost';

// Database port on server (5432 is the PostgreSQL default)
$conf['servers'][0]['port'] = 5432;

// Database SSL mode
// Possible options: disable, allow, prefer, require
// To require SSL on older servers use option: legacy
// To ignore the SSL mode, use option: unspecified
$conf['servers'][0]['sslmode'] = 'allow';

// Default database to connect to.
// Modern PostgreSQL uses 'postgres'; older setups may need 'template1'.
$conf['servers'][0]['defaultdb'] = 'postgres';

// Specify the path to the database dump utilities for this server.
// Leave these blank to have phpPgAdmin attempt to auto-detect them.
// $conf['servers'][0]['pg_dump_path'] = '/usr/bin/pg_dump';
// $conf['servers'][0]['pg_dumpall_path'] = '/usr/bin/pg_dumpall';

// Authentication type for this server (optional, default: 'form')
// 'form'   - Standard login form (default behavior)
// 'http'   - HTTP Basic Authentication
// 'config' - Credentials stored in config (encrypted, requires encryption_key)
// $conf['servers'][0]['auth_type'] = 'form';

// For 'http' auth: Credentials will be taken from HTTP Basic Auth headers
// No username/password needed in config

// For 'config' auth: Store encrypted credentials in config
// Generate encrypted password using:
//     php bin/encrypt-password.php --password "your_password"
// $conf['servers'][0]['auth_type'] = 'config';
// $conf['servers'][0]['username'] = 'user';
// $conf['servers'][0]['password'] = 'ENCRYPTED:base64encodedencryptedpassword';

// Example for a second server (PostgreSQL for Windows)
//$conf['servers'][1]['desc'] = 'Test Server';
//$conf['servers'][1]['host'] = '127.0.0.1';
//$conf['servers'][1]['port'] = 5432;
//$conf['servers'][1]['sslmode'] = 'allow';
//$conf['servers'][1]['defaultdb'] = 'template1';
//$conf['servers'][1]['pg_dump_path'] = 'C:\\Program Files\\PostgreSQL\\18.1\\bin\\pg_dump.exe';
//$conf['servers'][1]['pg_dumpall_path'] = 'C:\\Program Files\\PostgreSQL\\18.1\\bin\\pg_dumpall.exe';

// Example: HTTP Basic Authentication for server 1
//$conf['servers'][1]['auth_type'] = 'http';

// Example: Config-based authentication (requires encryption_key below)
//$conf['servers'][2]['desc'] = 'Auto Login Server';
//$conf['servers'][2]['host'] = 'localhost';
//$conf['servers'][2]['port'] = 5432;
//$conf['servers'][2]['sslmode'] = 'allow';
//$conf['servers'][2]['defaultdb'] = 'postgres';
//$conf['servers'][2]['auth_type'] = 'config';
//$conf['servers'][2]['username'] = 'myuser';
//$conf['servers'][2]['password'] = 'ENCRYPTED:base64encodedencryptedpassword';


/* Groups definition */
/* Groups allow administrators to logicaly group servers together under
 * group nodes in the left browser tree
 *
 * The group '0' description
 */
//$conf['srv_groups'][0]['desc'] = 'group one';

/* Add here servers indexes belonging to the group '0' separated by comma */
//$conf['srv_groups'][0]['servers'] = '0,1,2';

/* A server can belong to multi groups. Here server 1 is referenced in both
 * 'group one' and 'group two'*/
//$conf['srv_groups'][1]['desc'] = 'group two';
//$conf['srv_groups'][1]['servers'] = '3,1';

/* A group can be nested in one or more existing groups using the 'parents'
 * parameter. Here the group 'group three' contains only one server and will
 * appear as a subgroup in both 'group one' and 'group two':
 */
//$conf['srv_groups'][2]['desc'] = 'group three';
//$conf['srv_groups'][2]['servers'] = '4';
//$conf['srv_groups'][2]['parents'] = '0,1';

/* Warning: Only groups with no parents appears at the root of the tree. */

/* You can apply specific theme depending on servers, users and databases
 * The priority order is :
 *   * the theme defined for a server
 *   * the theme defined for a database apply over the server one
 *   * the theme defined for a user apply over the database one
 */
/* Example for servers */
//$conf['servers'][0]['theme']['default'] = 'default';
/* Example for users */
//$conf['servers'][0]['theme']['user']['specific_user'] = 'default';
/* Example for databases */
//$conf['servers'][0]['theme']['db']['specific_db'] = 'default';

// Default language. E.g.: 'english', 'polish', etc.  See lang/ directory
// for all possibilities. If you specify 'auto' (the default) it will use
// your browser preference.
$conf['default_lang'] = 'auto';

// AutoComplete uses AJAX interaction to list foreign key values
// on insert fields. It currently only works on single column
// foreign keys. You can choose one of the following values:
// 'default on' enables AutoComplete and turns it on by default.
// 'default off' enables AutoComplete but turns it off by default.
// 'disable' disables AutoComplete.
$conf['autocomplete'] = 'default on';

// If extra login security is true, then logins via phpPgAdmin with no
// password or certain usernames (pgsql, postgres, root, administrator)
// will be denied. Only set this false once you have read the FAQ and
// understand how to change PostgreSQL's pg_hba.conf to enable
// passworded local connections.
$conf['extra_login_security'] = true;

// Only show owned databases?
// Note: This will simply hide other databases in the list - this does
// not in any way prevent your users from seeing other database by
// other means. (e.g. Run 'SELECT * FROM pg_database' in the SQL area.)
$conf['owned_only'] = false;

// Display comments on objects?  Comments are a good way of documenting
// a database, but they do take up space in the interface.
$conf['show_comments'] = true;

// Display "advanced" objects? Setting this to true will show
// aggregates, types, operators, operator classes, conversions,
// languages and casts in phpPgAdmin. These objects are rarely
// administered and can clutter the interface.
$conf['show_advanced'] = true;

// Display "system" objects?
$conf['show_system'] = false;

// Minimum length users can set their password to.
$conf['min_password_length'] = 3;

// Width of the left frame in pixels (object browser)
$conf['left_width'] = 250;

// Which look & feel theme to use
$conf['theme'] = 'bootstrap';

// Show OIDs when browsing tables?
$conf['show_oids'] = false;

// Max rows to show on a page when browsing record sets
$conf['max_rows'] = 30;

// Max chars of each field to display by default in browse mode
$conf['max_chars'] = 50;

// Session timeout duration in seconds
// $conf['session_timeout'] = 3600;

// Session file storage path.
// Specify an explicit full path. Examples:
//   System temp directory:  sys_get_temp_dir() . '/phppgadmin_sessions'
//   Project directory:      __DIR__ . '/../sessions'
//   Absolute path:          '/var/lib/phppgadmin/sessions'
// The directory will be created if it doesn't exist, but must be writable by the web server.
// $conf['session_path'] = sys_get_temp_dir() . '/phppgadmin_sessions';

// Base URL for PostgreSQL documentation.
// '%s', if present, will be replaced with the PostgreSQL version
// (e.g. 9.5 )
$conf['help_base'] = 'http://www.postgresql.org/docs/%s/interactive/';

// Configuration for ajax scripts
// Time in seconds. If set to 0, refreshing data using ajax will be disabled (locks and activity pages)
$conf['ajax_refresh'] = 3;

// Maximum length of an SQL read query when sent via GET method
// Set it to zero to send all queries via POST method
$conf['max_get_query_length'] = 5000;

// Uncompressed chunk size in bytes (5MB default for good progress granularity)
$conf['import_upload_chunk_size'] = 5 * 1024 * 1024;

/** Plugins management
 * Add plugin names to the following array to activate them
 * Example:
 *   $conf['plugins'] = [
 *     'Example',
 *     'Slony'
 *   ];
 */
$conf['plugins'] = [];

return $conf;