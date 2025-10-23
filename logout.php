<?php
/**
 * Logs a user out of the app
 *
 * @package PhpPgAdmin
 */

if (!ini_get('session.auto_start')) {
    session_name('PPA_ID');
    session_start();
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

header('Location: index.php');
exit;