<?php
// ADOdb must be loaded before autoload because phpPgAdmin's Connector
// references ADODB_FETCH_ASSOC at class-definition time.
require_once __DIR__ . '/../../libraries/adodb/adodb.inc.php';
require_once __DIR__ . '/../../vendor/autoload.php';
