<?php
require_once('./libraries/bootstrap.php');

$plugin_manager->do_action($_REQUEST['plugin'], $_REQUEST['action']);

