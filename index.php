<?php
$_ENV["SKIP_DB_CONNECTION"] = '1';
include_once('./libraries/bootstrap.php');
if (true || isset($_SESSION['webdbLogin'])) {
	require 'intro.php';
	//header("Location: intro.php?$misc->href");
} else {
	//header("Location: servers.php");
	require 'servers.php';
}
