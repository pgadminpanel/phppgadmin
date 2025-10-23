<?php
$subject = $_REQUEST['subject'] ?? 'root';

if ($subject == 'root')
	$_ENV["SKIP_DB_CONNECTION"] = '1';

include_once('./libraries/bootstrap.php');

$url = $misc->getLastTabURL($subject);

// Load query vars into superglobal arrays
if (isset($url['urlvars'])) {
	$urlvars = [];

	foreach ($url['urlvars'] as $k => $urlvar) {
		$urlvars[$k] = value($urlvar, $_REQUEST);
	}

	$_REQUEST = array_merge($_REQUEST, $urlvars);
	$_GET = array_merge($_GET, $urlvars);
}

//var_dump($url);
//exit;
require $url['url'];
