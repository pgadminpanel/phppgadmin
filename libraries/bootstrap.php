<?php

/**
 * Function library read in upon startup
 *
 * $Id: bootstrap.php,v 1.123 2008/04/06 01:10:35 xzilla Exp $
 */

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Misc;
use PhpPgAdmin\PluginManager;
use PhpPgAdmin\Security\CredentialEncryption;

require_once __DIR__ . '/decorator.php';
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../lang/translations.php';
require_once __DIR__ . '/../vendor/autoload.php';

const ROOT_PATH = __DIR__ . '/..';

// Set error reporting level to max
error_reporting(E_ALL);

// Application name
AppContainer::setAppName($appName = 'phpPgAdmin');

// Application version
AppContainer::setAppVersion($appVersion = '8.0.4');

// PostgreSQL minimum version
AppContainer::setPgServerMinVersion($postgresqlMinVer = '9.0');

// Set internal encoding for multibyte string functions
mb_internal_encoding('UTF-8');

/*
// Check the version of PHP
if (version_compare(phpversion(), $phpMinVer, '<'))
	exit(sprintf('Version of PHP not supported. Please upgrade to version %s or later.', $phpMinVer));
*/

// Check to see if the configuration file exists, if not, explain
$configFile = __DIR__ . '/../conf/config.inc.php';
if (file_exists($configFile)) {
	$conf = require $configFile;
	if (empty($conf['theme'])) {
		$conf['theme'] = 'bootstrap';
	}
	// Set conf by reference
	AppContainer::setConf($conf);
} else {
	die('Configuration error: Copy conf/config-dist.inc.php to conf/config.inc.php and edit appropriately.');
}

// Setup session storage configuration
if (!empty($conf['session_path'])) {

	$sessionPath = $conf['session_path'];

	// Ensure directory exists
	if (!is_dir($sessionPath)) {
		@mkdir($sessionPath, 0755, true);
	}

	// Validate path is writable
	if (!is_dir($sessionPath) || !is_writable($sessionPath)) {
		die("Configuration error: Session path '$sessionPath' does not exist or is not writable. " .
			"Please create the directory and ensure write permissions, or update \$conf['session_path'] in config.inc.php");
	}

	ini_set('session.save_path', $sessionPath);
	ini_set('session.save_handler', 'files');
}

// Set session timeout duration
if (!empty($conf['session_timeout'])) {
	$sessionLifetime = (int) $conf['session_timeout'];
	ini_set('session.cookie_lifetime', $sessionLifetime);
	ini_set('session.gc_maxlifetime', $sessionLifetime);
}


// Session start: if extra_session_security is on, make sure cookie_samesite
// If extra_session_security is on, force cookie_samesite=Strict (CSRF protection)
$our_session_name = 'PPA_ID';
if (($conf['extra_session_security'] ?? true) === true) {
    if (version_compare(phpversion(), '7.4', '<')) {
        exit('phpPgAdmin cannot be fully secured while running under PHP versions before 7.4. Please upgrade PHP if possible. If you cannot upgrade, and you\'re willing to assume the risk of CSRF attacks, you can change the value of "extra_session_security" to false in your config.inc.php file.');
    }

    if (ini_get('session.auto_start')) {
        // If session.auto_start is on, and the session doesn't have
        // session.cookie_samesite set, destroy and re-create the session
        if (session_name() !== $our_session_name) {
            $setting = strtolower(ini_get('session.cookie_samesite'));
            if ($setting !== 'lax' && $setting !== 'strict') {
                session_destroy();
                session_name($our_session_name);
                ini_set('session.cookie_samesite', 'Strict');
                session_start();
            }
        }
    } else {
        session_name($our_session_name);
        ini_set('session.cookie_samesite', 'Strict');
        session_start();
    }
} else {
    if (!ini_get('session.auto_start')) {
        session_name($our_session_name);
        session_start();
    }
}

// Validate encryption key version - invalidate session if key changed
try {
	$currentKeyHash = CredentialEncryption::getKeyHash($conf);

	if (isset($_SESSION['encryption_version'])) {
		// Key hash exists in session - check if it matches current key
		if ($_SESSION['encryption_version'] !== $currentKeyHash) {
			// Key changed - invalidate session to force re-authentication
			session_destroy();
			unset($_SESSION);
			session_start();
		}
	}

	// Store current key hash in session
	$_SESSION['encryption_version'] = $currentKeyHash;
} catch (Exception $e) {
	// Encryption key is invalid - clear session and show error
	die('Encryption key error: ' . htmlspecialchars($e->getMessage()));
}

// Always include english.php, since it's the master language file
if (!isset($conf['default_lang']))
	$conf['default_lang'] = 'english';
$lang = [];
require_once __DIR__ . '/../lang/english.php';

// Validate that all required config keys are present
if (!isset($_SESSION['config_verified'])) {
	$required = [
		'default_lang' => null,
		'autocomplete' => null,
		'extra_login_security' => null,
		'owned_only' => null,
		'show_comments' => null,
		'show_advanced' => null,
		'show_system' => null,
		'min_password_length' => null,
		'left_width' => null,
		//'theme' => null,
		'show_oids' => null,
		'max_rows' => null,
		'max_chars' => null,
		//'session_timeout' => null,
		//'session_path' => null,
		'help_base' => null,
		'ajax_refresh' => null,
		'max_get_query_length' => null,
		'plugins' => null,
		'servers' => null,
	];
	$missing = array_keys(array_diff_key($required, $conf));
	if (!empty($missing)) {
		die("Missing keys in \$conf: " . implode(', ', $missing));
	}

	$required = [
		'desc' => null,
		'host' => null,
		'port' => null,
		'sslmode' => null,
		'defaultdb' => null,
		//'pg_dump_path' => null,
		//'pg_dumpall_path' => null
	];
	foreach ($conf['servers'] as $i => $server) {
		$missing = array_keys(array_diff_key($required, $server));
		if (!empty($missing)) {
			die("Missing keys in \$conf['servers'][$i]: " . implode(', ', $missing));
		}
	}
	$_SESSION['config_verified'] = true;
}

// Determine language file to import

// 1. Check for the language from a request var
if (isset($_REQUEST['language']) && isset($appLangFiles[$_REQUEST['language']])) {
	/* save the selected language in cookie for a year */
	setcookie('webdbLanguage', $_REQUEST['language'], time()+31536000);
	$_language = $_REQUEST['language'];
}

// 2. Check for language session var
if (!isset($_language) && isset($_SESSION['webdbLanguage']) && isset($appLangFiles[$_SESSION['webdbLanguage']])) {
	$_language = $_SESSION['webdbLanguage'];
}

// 3. Check for language in cookie var
if (!isset($_language) && isset($_COOKIE['webdbLanguage']) && isset($appLangFiles[$_COOKIE['webdbLanguage']])) {
	$_language = $_COOKIE['webdbLanguage'];
}

// 4. Check for acceptable languages in HTTP_ACCEPT_LANGUAGE var
if (!isset($_language) && $conf['default_lang'] == 'auto' && isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
	// extract acceptable language tags
	// (http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.4)
	preg_match_all('/\s*([a-z]{1,8}(?:-[a-z]{1,8})*)(?:;q=([01](?:.[0-9]{0,3})?))?\s*(?:,|$)/', strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']), $_m, PREG_SET_ORDER);
	foreach($_m as $_l) {  // $_l[1] = language tag, [2] = quality
		if (!isset($_l[2])) $_l[2] = 1;  // Default quality to 1
		if ($_l[2] > 0 && $_l[2] <= 1 && isset($availableLanguages[$_l[1]])) {
			// Build up array of (quality => language_file)
			$_acceptLang[$_l[2]] = $availableLanguages[$_l[1]];
		}
	}
	unset($_m);
	unset($_l);
	if (isset($_acceptLang)) {
		// Sort acceptable languages by quality
		krsort($_acceptLang, SORT_NUMERIC);
		$_language = reset($_acceptLang);
		unset($_acceptLang);
	}
}

// 5. Otherwise resort to the default set in the config file
if (!isset($_language) && $conf['default_lang'] != 'auto' && isset($appLangFiles[$conf['default_lang']])) {
	$_language = $conf['default_lang'];
}

// 6. Otherwise, default to english.
if (!isset($_language))
	$_language = 'english';


// Import the language file
if (isset($_language)) {
	include_once __DIR__ . "/../lang/{$_language}.php";
	$_SESSION['webdbLanguage'] = $_language;
}

AppContainer::setLang($lang);

// Check php libraries
$php_libraries_requirements = [
	// required_function => name_of_the_php_library
	'pg_connect' => 'pgsql',
	'mb_strlen' => 'mbstring',
	'sodium_crypto_secretbox' => 'sodium',
];
$missing_libraries = [];
foreach ($php_libraries_requirements as $func_name => $lib) {
	if (!function_exists($func_name)) {
		$missing_libraries[] = $lib;
	}
}
if (!empty($missing_libraries)) {
	if (count($missing_libraries) == 1) {
		printf($lang['strlibnotfound'], implode(', ', $missing_libraries));
	} else {
		printf($lang['strlibnotfound_plural'], implode(', ', $missing_libraries));
	}
	exit;
}

// Create Misc class references
$misc = new Misc();
AppContainer::setMisc($misc);


// This has to be deferred until after stripVar above
$misc->setHREF();
$misc->setForm();

//if (isset($_POST['action'])) {
//	$_POST[$_POST['action']] = $_POST['action'];
//}

// Enforce PHP environment
ini_set('arg_separator.output', '&amp;');

// If login action is set, then set session variables
if (
	isset($_POST['loginServer']) && isset($_POST['loginUsername']) &&
	isset($_POST['loginPassword_' . md5($_POST['loginServer'])])
) {

	$_server_info = $misc->getServerInfo($_POST['loginServer']);

	$_server_info['username'] = $_POST['loginUsername'];
	$_server_info['password'] = $_POST['loginPassword_' . md5($_POST['loginServer'])];

	$misc->setServerInfo(null, $_server_info, $_POST['loginServer']);

	// Check for shared credentials
	if (isset($_POST['loginShared'])) {
		$_SESSION['sharedUsername'] = $_POST['loginUsername'];
		$_SESSION['sharedPassword'] = $_POST['loginPassword_' . md5($_POST['loginServer'])];
	}

	AppContainer::setShouldReloadTree(true);
}

/* select the theme */
unset($_theme);
if (!isset($conf['theme']))
	$conf['theme'] = 'default';

// 1. Check for the theme from a request var
if (isset($_REQUEST['theme']) && is_file("./themes/{$_REQUEST['theme']}/global.css")) {
	/* save the selected theme in cookie for a year */
	setcookie('ppaTheme', $_REQUEST['theme'], time() + 31536000);
	$_theme = $_SESSION['ppaTheme'] = $conf['theme'] = $_REQUEST['theme'];
}

// 2. Check for theme session var
if (!isset($_theme) && isset($_SESSION['ppaTheme']) && is_file("./themes/{$_SESSION['ppaTheme']}/global.css")) {
	$conf['theme'] = $_SESSION['ppaTheme'];
}

// 3. Check for theme in cookie var
if (!isset($_theme) && isset($_COOKIE['ppaTheme']) && is_file("./themes/{$_COOKIE['ppaTheme']}/global.css")) {
	$conf['theme'] = $_COOKIE['ppaTheme'];
}


// 4. Check for theme by server/db/user
$info = $misc->getServerInfo();

if (!empty($info)) {
	$_theme = '';

	if (
		(isset($info['theme']['default']))
		and is_file("./themes/{$info['theme']['default']}/global.css")
	)
		$_theme = $info['theme']['default'];

	if (
		isset($_REQUEST['database'])
		and isset($info['theme']['db'][$_REQUEST['database']])
		and is_file("./themes/{$info['theme']['db'][$_REQUEST['database']]}/global.css")
	)
		$_theme = $info['theme']['db'][$_REQUEST['database']];

	if (
		isset($info['username'])
		and isset($info['theme']['user'][$info['username']])
		and is_file("./themes/{$info['theme']['user'][$info['username']]}/global.css")
	)
		$_theme = $info['theme']['user'][$info['username']];

	if ($_theme !== '') {
		setcookie('ppaTheme', $_theme, time() + 31536000);
		$conf['theme'] = $_theme;
	}
}


$plugin_manager = new PluginManager($_language);
AppContainer::setPluginManager($plugin_manager);


// Create data accessor object, if necessary
if (empty($_ENV["SKIP_DB_CONNECTION"] ?? '')) {

	/**
	 * Register custom ADODB driver loader
	 *
	 * This callback intercepts ADONewConnection() calls and loads the enhanced
	 * PostgreSQL driver which caches field metadata (table OID, attribute number)
	 * from query result sets.
	 *
	 * The enhanced driver provides methods like FieldTableOID($i) and FieldAttnum($i)
	 * on result sets for direct field source metadata inspection.
	 */
	/*
	$ADODB_NEWCONNECTION = function ($dbType) {
		if ($dbType !== 'postgres9_enhanced') {
			// Not our driver, let ADODB handle it with default loading
			return false;
		}

		// Load the custom enhanced driver
		$customDriverFile = __DIR__ . '/adodb-custom/adodb-postgres9-enhanced.inc.php';
		if (!file_exists($customDriverFile)) {
			die("failed to load driver: custom driver file not found at " . $customDriverFile);
		}

		try {
			require_once $customDriverFile;

			// Instantiate the enhanced driver class
			if (!class_exists('ADODB_postgres9_enhanced')) {
				die("failed to load driver: ADODB_postgres9_enhanced class not found after loading " . $customDriverFile);
			}

			$driver = new ADODB_postgres9_enhanced();
			if (!$driver) {
				die("failed to load driver: could not instantiate ADODB_postgres9_enhanced");
			}

			return $driver;
		} catch (Exception $e) {
			die("failed to load driver: " . $e->getMessage());
		}
	};
	*/

	if (!isset($_REQUEST['server'])) {
		printFatalError($lang['strinvalidserverparam']);
	}

	$_server_info = $misc->getServerInfo();

	/* starting with PostgreSQL 9.0, we can set the application name */
	putenv("PGAPPNAME={$appName}_{$appVersion}");

	// Handle different authentication types
	$auth_type = $_server_info['auth_type'] ?? 'form';

	// HTTP Basic Authentication
	if ($auth_type === 'http') {
		if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
			// No credentials provided - send authentication challenge
			header('WWW-Authenticate: Basic realm="' . addslashes($_server_info['desc']) . '"');
			header('HTTP/1.0 401 Unauthorized');
			echo $lang['strloginfailed'];
			exit;
		}

		// Store HTTP Basic Auth credentials in session
		if (!isset($_server_info['username'])) {
			$_server_info['username'] = $_SERVER['PHP_AUTH_USER'];
			$_server_info['password'] = $_SERVER['PHP_AUTH_PW'];
			$misc->setServerInfo(null, $_server_info, $_REQUEST['server']);
			AppContainer::setShouldReloadTree(true);
		}
	}

	// Config-based authentication with encrypted credentials
	if ($auth_type === 'config') {
		if (!isset($_server_info['username']) || !isset($_server_info['password'])) {
			die('Configuration error: auth_type=config requires username and password in server configuration');
		}

		// Check if password is encrypted (starts with ENCRYPTED: prefix)
		$password = $_server_info['password'];
		if (strpos($password, 'ENCRYPTED:') === 0) {
			try {
				// Decrypt password
				$encrypted = substr($password, 10); // Remove "ENCRYPTED:" prefix
				$password = CredentialEncryption::decrypt($encrypted, $conf);

				// Store decrypted password in session if not already stored
				if (!isset($_SESSION['webdbLogin'][$_REQUEST['server']]['password'])) {
					$_server_info['password'] = $password;
					$misc->setServerInfo(null, $_server_info, $_REQUEST['server']);
				}
			} catch (Exception $e) {
				die('Configuration error: Failed to decrypt password: ' . htmlspecialchars($e->getMessage()));
			}
		} else {
			// Plain text password in config (not recommended but allowed)
			if (!isset($_SESSION['webdbLogin'][$_REQUEST['server']]['password'])) {
				$misc->setServerInfo(null, $_server_info, $_REQUEST['server']);
			}
		}

		// Reload server info to get the decrypted/stored credentials
		$_server_info = $misc->getServerInfo();
	}

	// Redirect to the login form if not logged in (form authentication only)
	if (!isset($_server_info['username'])) {
		include('./login.php');
		exit;
	}

	// Connect to the current database, or if one is not specified
	// then connect to the default database.
	$_curr_db = $_REQUEST['database'] ?? $_server_info['defaultdb'];

	require __DIR__ . '/errorhandler.inc.php';
	require __DIR__ . '/adodb/adodb.inc.php';

	// Connect to database and set the global $data variable
	$pg = $misc->getDatabaseAccessor($_curr_db);
	AppContainer::setPostgres($pg);

	// If schema is defined and database supports schemas, then set the
	// schema explicitly.
	if (isset($_REQUEST['database']) && isset($_REQUEST['schema'])) {
		$status = (new SchemaActions())->setSchema($_REQUEST['schema']);
		//$status = $data->setSchema($_REQUEST['schema']);
		if ($status != 0) {
			echo $lang['strbadschema'];
			exit;
		}
	}
}

if (!function_exists('displaySchemaName')) {
    function displaySchemaName(string $name): string
    {
        $lang = AppContainer::getLang();
        $key = 'strnsp_' . str_replace('-', '_', $name);
        return $lang[$key] ?? $name;
    }
}

/**
* Safe unserializer wrapper
*
* It does not unserialize data containing objects
*
* Function from phpMyAdmin version 5.2.1
*
* @param string $data Data to unserialize
*
* @return mixed|null
*/
function safeUnserialize(string $data) {
    /* validate serialized data */
    $length = strlen($data);
    $depth = 0;
    for ($i = 0; $i < $length; $i++) {
        $value = $data[$i];

        switch ($value) {
            case '}':
                /* end of array */
                if ($depth <= 0) {
                    return null;
                }

                $depth--;
                break;
            case 's':
                /* string */
                // parse sting length
                $strlen = intval(substr($data, $i + 2));
                // string start
                $i = strpos($data, ':', $i + 2);
                if ($i === false) {
                    return null;
                }

                // skip string, quotes and ;
                $i += 2 + $strlen + 1;
                if ($data[$i] !== ';') {
                    return null;
                }

                break;

            case 'b':
            case 'i':
            case 'd':
                /* bool, integer or double */
                // skip value to separator
                $i = strpos($data, ';', $i);
                if ($i === false) {
                    return null;
                }

                break;
            case 'a':
                /* array */
                // find array start
                $i = strpos($data, '{', $i);
                if ($i === false) {
                    return null;
                }

                // remember nesting
                $depth++;
                break;
            case 'N':
                /* null */
                // skip to end
                $i = strpos($data, ';', $i);
                if ($i === false) {
                    return null;
                }

                break;
            default:
                /* any other elements are not wanted */
                return null;
        }
    }

    // check unterminated arrays
    if ($depth > 0) {
        return null;
    }

    return unserialize($data);
}