<?php

$plugin_conf = [

    /**
     * Top Links
     **/
    'top_links' => [
        // 'sql' => true,
        // 'history' => true,
        // 'find' => true,
        // 'logout' => true,
	 ],

    /**
     * Tabs Links
     **/
     'tab_links' => [
        'root' => [
            // 'intro' => true,
            // 'servers' => true,
		  ],
        'server' => [
            // 'databases' => true,
            // 'roles' => true, /* postgresql 8.1+ */
            // 'users' => true, /* postgresql <8.1 */
            // 'groups' => true,
            // 'account' => true,
            // 'tablespaces' => true,
            // 'export' => true,
            // 'reports' => true,
		  ],
        'database' => [
            // 'schemas' => true,
            // 'sql' => true,
            // 'find' => true,
            // 'variables' => true,
            // 'processes' => true,
            // 'locks' => true,
            // 'admin' => true,
            // 'privileges' => true,
            // 'languages' => true,
            // 'casts' => true,
            // 'export' => true,
		  ],
        'schema' => [
            // 'tables' => true,
            // 'views' => true,
            // 'sequences' => true,
            // 'functions' => true,
            // 'fulltext' => true,
            // 'domains' => true,
            // 'aggregates' => true,
            // 'types' => true,
            // 'operators' => true,
            // 'opclasses' => true,
            // 'conversions' => true,
            // 'privileges' => true,
            // 'export' => true,
		  ],
        'table' => [
            // 'columns' => true,
            // 'indexes' => true,
            // 'constraints' => true,
            // 'triggers' => true,
            // 'rules' => true,
            // 'admin' => true,
            // 'info' => true,
            // 'privileges' => true,
            // 'import' => true,
            // 'export' => true,
		  ],
        'view' => [
            // 'columns' => true,
            // 'definition' => true,
            // 'rules' => true,
            // 'privileges' => true,
            // 'export' => true,
		  ],
        'function' => [
            // 'definition' => true,
            // 'privileges' => true,
		  ],
        'aggregate' => [
            // 'definition' => true,
		  ],
        'role' => [
            // 'definition' => true,
		  ],
        'popup' => [
            // 'sql' => true,
            // 'find' => true,
		  ],
        'column' => [
            // 'properties' => true,
            // 'privileges' => true,
		  ],
        'fulltext' => [
            // 'ftsconfigs' => true,
            // 'ftsdicts' => true,
            // 'ftsparsers' => true,
		  ],
	  ],

    /**
     * Trail Links
     **/
     // 'trail_links' => true, /* enable/disable the whole trail */

    /**
     * Navigation Links
     **/
     'navlinks' => [
        'aggregates-properties' => [
            // 'showall' => true,
            // 'alter' => true,
            // 'drop' => true,
		  ],
        'aggregates-aggregates' => [
            // 'create' => true,
		  ],
        'all_db-databases' => [
            // 'create' => true,
		  ],
        'colproperties-colproperties' => [
            // 'browse' => true,
            // 'alter' => true,
            // 'drop' => true,
		  ],
        'constraints-constraints' => [
            // 'addcheck' => true,
            // 'adduniq' => true,
            // 'addpk' => true,
            // 'addfk' => true,
		  ],
        'display-browse' => [
            // 'back' => true,
            // 'edit' => true,
            // 'collapse' => true,
            // 'createreport' => true,
            // 'createview' => true,
            // 'download' => true,
            // 'insert' => true,
            // 'refresh' => true,
		  ],
        'domains-properties' => [
            // 'drop' => true,
            // 'addcheck' => true,
            // 'alter' => true,
		  ],
        'domains-domains' => [
            // 'create' => true,
		  ],
        'fulltext-fulltext' => [
            // 'createconf' => true,
		  ],
        'fulltext-viewdicts' => [
            // 'createdict' => true,
		  ],
        'fulltext-viewconfig' => [
            // 'addmapping' => true,
		  ],
        'functions-properties' => [
            // 'showall' => true,
            // 'alter' => true,
            // 'drop' => true,
		  ],
        'functions-functions' => [
            // 'createpl' => true,
            // 'createinternal' => true,
            // 'createc' => true,
		  ],
        'groups-groups' => [
            // 'create' => true,
		  ],
        'groups-properties' => [
            // 'showall' => true,
		  ],
        'history-history' => [
            // 'refresh' => true,
            // 'download' => true,
            // 'clear' => true,
		  ],
        'indexes-indexes' => [
            // 'create' => true,
		  ],
        'operators-properties' => [
            // 'showall' => true,
		  ],
        'privileges-privileges' => [
            // 'grant' => true,
            // 'revoke' => true,
            // 'showalltables' => true,
            // 'showallcolumns' => true,
            // 'showallviews' => true,
            // 'showalldatabases' => true,
            // 'showallschemas' => true,
            // 'showallfunctions' => true,
            // 'showallsequences' => true,
            // 'showalltablespaces' => true,
		  ],
        'reports-properties' => [
            // 'showall' => true,
            // 'alter' => true,
            // 'exec' => true,
		  ],
        'reports-reports' => [
            // 'create' => true,
		  ],
        'roles-account' => [
            // 'changepassword' => true,
		  ],
        'roles-properties' => [
            // 'showall' => true,
            // 'alter' => true,
            // 'drop' => true,
		  ],
        'roles-roles' => [
            // 'create' => true,
		  ],
        'rules-rules' => [
            // 'create' => true,
		  ],
        'schemas-schemas' => [
            // 'create' => true,
		  ],
        'sequences-properties' => [
            // 'alter' => true,
            // 'nextval' => true,
            // 'restart' => true,
            // 'reset' => true,
		  ],
        'sequences-sequences' => [
            // 'create' => true,
		  ],
        'servers-servers' => [
            // 'showall' => true,
            /*we cannot filter the group names in navlinks presently*/
		  ],
        'sql-form' => [
            // 'back' => true,
            // 'alter' => true,
            // 'createreport' => true,
            // 'createview' => true,
            // 'download' => true,
		  ],
        'tables-tables' => [
            // 'create' => true,
            // 'createlike' => true,
		  ],
        'tablespaces-tablespaces' => [
            // 'create' => true,
		  ],
        'tblproperties-tblproperties' => [
            // 'browse' => true,
            // 'select' => true,
            // 'insert' => true,
	    'empty' => true,
            // 'drop' => true,
            // 'addcolumn' => true,
            // 'alter' => true,
		  ],
        'triggers-triggers' => [
            // 'create' => true,
		  ],
        'types-properties' => [
            // 'showall' => true,
		  ],
        'types-types' => [
            // 'create' => true,
            // 'createcomp' => true,
            // 'createenum' => true,
		  ],
        'users-account' => [
            // 'changepassword' => true,
		  ],
        'users-users' => [
            // 'create' => true,
		  ],
        'viewproperties-definition' => [
            // 'alter' => true,
		  ],
        'viewproperties-viewproperties' => [
            // 'browse' => true,
            // 'select' => true,
            // 'drop' => true,
            // 'alter' => true,
		  ],
        'views-views' => [
            // 'create' => true,
            // 'createwiz' => true,
		  ],
	  ],

     /**
      * action links
      **/

    'actionbuttons' => [
        'admin-admin' => [
            // 'edit' => true,
            // 'delete' => true,
		  ],
        'aggregates-aggregates' => [
            // 'alter' => true,
			// 'drop' => true,
		  ],
        'all_db-databases' => [
            // 'drop' => true,
            // 'privileges' => true,
            // 'alter' => true,
		  ],
        'casts-casts' => [
            // none
		  ],
        'colproperties-colproperties' => [
            // none
		  ],
        'constraints-constraints' => [
            // 'drop' => true,
		  ],
        'conversions-conversions' => [
            // none
		  ],
        'database-variables' => [
            // none
		  ],
        'database-processes-preparedxacts' => [
            // none
		  ],
        'database-processes' => [
            // 'cancel' => true,
            // 'kill' => true,
		  ],
        'database-locks' => [
            // none
		  ],
        'display-browse' => [
            // TODO
            // 'edit' => true,
            // 'delete' => true,
		  ],
        'domains-properties' => [
            // 'drop' => true,
		  ],
        'domains-domains' => [
            // 'alter' => true,
			// 'drop' => true,
		  ],
        'fulltext-fulltext' => [
            // 'drop' => true,
            // 'alter' => true,
		  ],
        'fulltext-viewparsers' => [
            // none
		  ],
        'fulltext-viewdicts' => [
            // 'drop' => true,
            // 'alter' => true,
		  ],
        'fulltext-viewconfig' => [
            // 'multiactions' => true,
            // 'drop' => true,
            // 'alter' => true,
		  ],
        'functions-functions' => [
            // 'multiactions' => true,
            // 'alter' => true,
            // 'drop' => true,
            // 'privileges' => true,
		  ],
        'groups-members' => [
            // 'drop' => true,
		  ],
        'groups-properties' => [
            // 'drop' => true,
		  ],
        'history-history' => [
            // 'run' => true,
            // 'remove' => true,
		  ],
        'indexes-indexes' => [
            // 'cluster' => true,
            // 'reindex' => true,
            // 'drop' => true,
		  ],
        'info-referrers' => [
            // 'properties' => true,
		  ],
        'info-parents' => [
            // 'properties' => true,
		  ],
        'info-children' => [
            // 'properties' => true,
		  ],
        'languages-languages' => [
            // none
		  ],
        'opclasses-opclasses' => [
            // none
		  ],
        'operators-operators' => [
             // 'drop' => true,
		  ],
        'reports-reports' => [
            // 'run' => true,
            // 'edit' => true,
            // 'drop' => true,
		  ],
        'roles-roles' => [
            // 'alter' => true,
            // 'drop' => true,
		  ],
        'rules-rules' => [
            // 'drop' => true,
		  ],
        'schemas-schemas' => [
            // 'multiactions' => true,
            // 'drop' => true,
            // 'privileges' => true,
            // 'alter' => true,
		  ],
        'sequences-sequences' => [
            // 'multiactions' => true,
            // 'drop' => true,
            // 'privileges' => true,
            // 'alter' => true,
		  ],
        'servers-servers' => [
            // 'logout' => true,
		  ],
        'tables-tables' => [
            // 'multiactions' => true,
            // 'browse' => true,
            // 'select' => true,
            // 'insert' => true,
            // 'empty' => true,
            // 'alter' => true,
            // 'drop' => true,
            // 'vacuum' => true,
            // 'analyze' => true,
            // 'reindex' => true,
		  ],
        'tablespaces-tablespaces' => [
            // 'drop' => true,
            // 'privileges' => true,
            // 'alter' => true,
		  ],
        'tblproperties-tblproperties' => [
            // 'browse' => true,
            // 'alter' => true,
            // 'privileges' => true,
            // 'drop' => true,
		  ],
        'triggers-triggers' => [
            // 'alter' => true,
            // 'drop' => true,
            // 'enable' => true,
            // 'disable' => true,
		  ],
        'types-properties' => [
            // none
		  ],
        'types-types' => [
            // 'drop' => true,
		  ],
        'users-users' => [
            // 'alter' => true,
            // 'drop' => true,
		  ],
        'viewproperties-viewproperties' => [
            // 'alter' => true,
		  ],
        'views-views' => [
            //'multiactions' => true,
            // 'browse' => true,
            // 'select' => true,
            //'alter' => true,
            //'drop' => true,
		  ],
	 ],
];


