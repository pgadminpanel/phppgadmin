<?php
/**
 * Login screen
 *
 * @package PhpPgAdmin
 */
use PhpPgAdmin\Core\AppContainer;

require_once('./libraries/bootstrap.php');

$misc = AppContainer::getMisc();
$conf = AppContainer::getConf();
$lang = AppContainer::getLang();

$misc->printHeader($lang['strlogin']);
$misc->printBody();
$misc->printTrail('root');

include __DIR__ . '/login-form.php';

$misc->printFooter();