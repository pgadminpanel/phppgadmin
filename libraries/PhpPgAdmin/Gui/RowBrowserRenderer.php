<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Database\Actions\TypeActions;
use PhpPgAdmin\Database\Actions\ViewActions;
use PhpPgAdmin\Database\Import\SqlParser;
use PhpPgAdmin\Database\Postgres;
use PhpPgAdmin\Misc;
use PHPSQLParser\PHPSQLParser;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Core\AppContext;
use PhpPgAdmin\Database\Actions\RowActions;
use PhpPgAdmin\Database\ByteaQueryModifier;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\QueryResultMetadataProbe;
use PhpPgAdmin\Database\Actions\ConstraintActions;

class RowBrowserRenderer extends AppContext
{

    /** @var \PhpPgAdmin\Database\Postgres|null */
    private $pg = null;

    /** @var array|null */
    private $conf = null;

    /** @var Misc|null */
    private $misc = null;

    /** @var array|null */
    private $lang = null;

    /** @var \PhpPgAdmin\Database\Actions\RowActions|null */
    private $rowActions = null;

    /** @var \PhpPgAdmin\Database\Actions\TypeActions|null */
    private $typeActions = null;

    /** @var \PhpPgAdmin\Database\Actions\ConstraintActions|null */
    private $constraintActions = null;

    /** @var \PhpPgAdmin\Database\Actions\SchemaActions|null */
    private $schemaActions = null;

    /** @var \PHPSQLParser\PHPSQLParser|null */
    private $sqlParser = null;

    /** @var array<string, array> */
    private $typesMetaCache = [];

    /** @var array<int, int> */
    private $fieldCountCache = [];

    /** @var array<int, array<string, list<int>>> */
    private $fieldNameIndexMapCache = [];

    /** @var \ADORecordSet|null */
    private $currentRecordSet = null;

    /** @var array<string, list<int>> */
    private $currentNameIndexMap = [];

    /** @var array */
    private $currentFkInformation = [];

    /** @var bool */
    private $currentWithOid = false;

    /** @var array|false */
    private $currentHeaderArgs = false;

    /** @var bool */
    private $currentEditable = false;

    /** @var array */
    private $currentByteaCols = [];

    private $byteaColumns = null;


    private function getPg(): ?Postgres
    {
        if ($this->pg === null) {
            $this->pg = AppContainer::getPostgres();
        }
        return $this->pg;
    }

    private function getConf(): array
    {
        if ($this->conf === null) {
            $this->conf = AppContainer::getConf();
        }
        return $this->conf;
    }

    private function getMisc(): ?Misc
    {
        if ($this->misc === null) {
            $this->misc = AppContainer::getMisc();
        }
        return $this->misc;
    }

    private function getLang(): array
    {
        if ($this->lang === null) {
            $this->lang = AppContainer::getLang();
        }
        return $this->lang;
    }

    private function getRowActions(): RowActions
    {
        if ($this->rowActions === null) {
            $this->rowActions = new RowActions($this->getPg());
        }
        return $this->rowActions;
    }

    private function getTypeActions(): TypeActions
    {
        if ($this->typeActions === null) {
            $this->typeActions = new TypeActions($this->getPg());
        }
        return $this->typeActions;
    }

    private function getConstraintActions(): ConstraintActions
    {
        if ($this->constraintActions === null) {
            $this->constraintActions = new ConstraintActions($this->getPg());
        }
        return $this->constraintActions;
    }

    private function getSchemaActions(): SchemaActions
    {
        if ($this->schemaActions === null) {
            $this->schemaActions = new SchemaActions($this->getPg());
        }
        return $this->schemaActions;
    }

    private function getSqlParser(): PHPSQLParser
    {
        if ($this->sqlParser === null) {
            $this->sqlParser = new PHPSQLParser();
        }
        return $this->sqlParser;
    }


    private function setCurrentBrowseContext($rs, array $nameIndexMap, array $fkeyInformation, bool $withOid, $headerArgs): void
    {
        $this->currentRecordSet = $rs;
        $this->currentNameIndexMap = $nameIndexMap;
        $this->currentFkInformation = $fkeyInformation;
        $this->currentWithOid = $withOid;
        $this->currentHeaderArgs = $headerArgs;
        $this->currentByteaCols = $this->byteaColumns ?? [];
    }

    private function setCurrentRowEditable(bool $editable): void
    {
        $this->currentEditable = $editable;
    }

    private function printCurrentHeaderCells(): void
    {
        if ($this->currentRecordSet === null) {
            return;
        }

        $this->printTableHeaderCells($this->currentRecordSet, $this->currentHeaderArgs, $this->currentWithOid);
    }

    private function printCurrentRowCells(): void
    {
        if ($this->currentRecordSet === null) {
            return;
        }

        $this->printTableRowCells($this->currentRecordSet, $this->currentFkInformation, $this->currentWithOid, $this->currentEditable);
    }

    /**
     * Displays requested data
     */
    function doBrowse($msg = '')
    {
        $pg = $this->getPg();
        $conf = $this->getConf();
        $misc = $this->getMisc();
        $lang = $this->getLang();

        //$pg->setDebug(true);

        if (
            !$this->prepareBrowseRequest(
                $subject,
                $table_name,
                $query,
                $parsed,
                $key_fields_early
            )
        ) {
            return;
        }

        $isCatalogSchema = $misc->isCatalogSchema();

        $misc->printTrail($subject ?? 'database');
        $misc->printTabs($subject, 'browse');

        $misc->printMsg($msg);

        $this->initBrowseRequestDefaults($conf);

        $key_fields = $this->resolveKeyFields($table_name ?? null, $key_fields_early);

        $this->applyOrderByFromRequest($query, $parsed, $orderBySet);

        [$displayQuery, $execQuery] = $this->prepareExecQuery($query);

        $query = $displayQuery;
        $_REQUEST['query'] = $displayQuery;
        $_SESSION['sqlquery'] = $displayQuery;

        // Retrieve page from query.  $max_pages is returned by reference.
        $rowActions = $this->getRowActions();
        $rs = $rowActions->browseQuery(
            'SELECT',
            $table_name ?? null,
            $execQuery,
            $orderBySet ? [] : $_REQUEST['orderby'],
            $_REQUEST['page'],
            $_REQUEST['max_rows'],
            $max_pages
        );

        // Generate status line
        $status_line = format_string($lang['strbrowsestatistics'], [
            'count' => \is_object($rs) ? $rs->rowCount() : 0,
            'first' => \is_object($rs) && $rs->rowCount() > 0 ? $rowActions->lastQueryOffset + 1 : 0,
            'last' => min($rowActions->totalRowsFound, $rowActions->lastQueryOffset + $rowActions->lastQueryLimit),
            'total' => $rowActions->totalRowsFound,
            'duration' => round($pg->lastQueryTime, 5),
        ]);

        // Get foreign key information for the current table
        $fkey_information = $this->getFKInfo();

        // Build strings for GETs in array
        $_gets = $this->buildBrowseGets($subject, $table_name, $conf);

        if (!empty($key_fields) && $subject === 'view') {
            // Find out if the view is updatable
            $viewQuoted = $pg->quoteIdentifier($pg->_schema)
                . "." . $pg->quoteIdentifier($table_name);
            $column = reset($key_fields);
            AppContainer::set('quiet_sql_error_handling', true);
            $error = $pg->execute(
                "EXPLAIN UPDATE $viewQuoted
                SET $column = $column
                WHERE false;"
            );
            AppContainer::set('quiet_sql_error_handling', false);
            if ($error !== 0) {
                // Clear FK info for views
                $key_fields = [];
            }
        }

        $_sub_params = $_gets;
        unset($_sub_params['query']);
        unset($_sub_params['orderby']);
        unset($_sub_params['orderby_clear']);

        $this->renderQueryForm($query, $_sub_params, $lang);

        echo '<div class="query-result-line">', htmlspecialchars($status_line), '</div>', "\n";

        if (strlen($query) > $conf['max_get_query_length']) {
            // Use query from session if too long for GET
            unset($_gets['query']);
        }

        if (is_object($rs) && $rs->recordCount() > 0) {
            // Show page navigation
            $misc->printPageNavigation($_REQUEST['page'], $max_pages, $_gets, 'display.php');

            $nameIndexMap = $this->buildFieldNameIndexMap($rs);

            // Store browse context on the instance to avoid parameter threading
            $this->setCurrentBrowseContext($rs, $nameIndexMap, $fkey_information, isset($table_name), $_gets);

            $key_fields = $this->filterKeyFieldsToThoseInResult(
                $key_fields,
                $nameIndexMap
            );

            [$actions, $edit_params, $delete_params, $colspan] = $this->prepareBrowseActionButtons($_gets);

            if ($isCatalogSchema) {
                // Disable edit/delete buttons in catalog schema
                $colspan = 0;
                unset($actions['actionbuttons']);
            }

            $table_data = "";
            if (!empty($key_fields)) {
                $table_data .= " data-schema=\"" . htmlspecialchars($_gets['schema']) . "\"";
                $table_data .= " data-table=\"" . htmlspecialchars($_gets['table'] ?? $_gets['view']) . "\"";
            }

            echo "<table id=\"data\" class=\"query-result\"{$table_data}>\n";
            echo "<thead class=\"sticky-thead\">\n";
            echo "<tr data-orderby-desc=\"", htmlspecialchars($lang['strorderbyhelp']), "\">\n";

            //var_dump($key_fields);
            if ($colspan > 0 and count($key_fields) > 0) {
                $collapsed = $_REQUEST['strings'] === 'collapsed';
                echo "<th colspan=\"{$colspan}\" class=\"data\">";
                //echo $lang['stractions'];
                $link = [
                    'attr' => [
                        'href' => [
                            'url' => 'display.php',
                            'urlvars' => array_merge(
                                $_gets,
                                [
                                    'strings' => $collapsed ? 'expanded' : 'collapsed',
                                    'page' => $_REQUEST['page']
                                ]
                            )
                        ]
                    ],
                    'icon' => $misc->icon($collapsed ? 'TextExpand' : 'TextShrink'),
                    'content' => $collapsed ? $lang['strexpand'] : $lang['strcollapse'],
                ];
                $misc->printLink($link);
                echo "</th>\n";
            }

            /* we show OIDs only if we are in TABLE or SELECT type browsing */
            $this->printCurrentHeaderCells();

            echo "</tr>\n";
            echo "</thead>\n";
            echo "<tbody>\n";

            $i = 0;
            reset($rs->fields);
            while (!$rs->EOF) {
                $id = (($i & 1) == 0 ? '1' : '2');
                $editable = $this->printRowStartWithActions(
                    $rs,
                    $nameIndexMap,
                    $key_fields,
                    $colspan,
                    $actions,
                    $edit_params,
                    $delete_params,
                    $id,
                    $misc
                );

                $this->setCurrentRowEditable($editable);
                $this->printCurrentRowCells();

                echo "</tr>\n";
                $rs->moveNext();
                $i++;
            }
            echo "</tbody>\n";
            echo "</table>\n";
            //echo "</div>\n";

            //echo "<p>", $rs->recordCount(), " {$lang['strrows']}</p>\n";
            // Show page navigation
            $misc->printPageNavigation($_REQUEST['page'], $max_pages, $_gets, 'display.php');
        } else {
            echo "<p class=\"nodata\">{$lang['strnodata']}</p>\n";
        }

        $fields = [
            'server' => $_REQUEST['server'],
            'database' => $_REQUEST['database'],
        ];

        if (isset($_REQUEST['schema'])) {
            $fields['schema'] = $_REQUEST['schema'];
        }

        $navlinks = $this->buildBrowseNavLinks($table_name ?? null, $subject ?? null, $_gets, $rs, $fields, $lang);

        if ($isCatalogSchema) {
            // Disable edit/delete buttons in catalog schema
            unset($navlinks['insert']);
        }

        $misc->printNavLinks($navlinks, 'display-browse', get_defined_vars());

        $this->printAutoCompleteData();
        $this->printScripts();
    }

    private function printRowStartWithActions(
        $rs,
        array $nameIndexMap,
        array $keyFields,
        int $colspan,
        array $actions,
        array $editParams,
        array $deleteParams,
        string $id,
        $misc
    ): bool {
        $editable = $colspan > 0 && !empty($keyFields);
        if (!$editable) {
            echo "<tr class=\"data{$id} data-row\">\n";
            return false;
        }

        $keysArray = [];
        $keysHash = [];
        $keysComplete = true;
        foreach ($keyFields as $fieldName) {
            $keyVal = $this->getFieldValueByName($rs, $nameIndexMap, (string) $fieldName);
            if ($keyVal === null) {
                $keysComplete = false;
                $editable = false;
                break;
            }
            $keysArray["key[{$fieldName}]"] = $keyVal;
            $keysHash[$fieldName] = $keyVal;
        }

        $trData = '';
        $rowButtons = $actions['actionbuttons'] ?? [];

        if ($keysComplete) {
            if (isset($rowButtons['edit'])) {
                $rowButtons['edit'] = $editParams;
                $rowButtons['edit']['attr']['href']['urlvars'] = array_merge(
                    $rowButtons['edit']['attr']['href']['urlvars'],
                    $keysArray
                );
            } else {
                $editable = false;
            }

            if (isset($rowButtons['delete'])) {
                $rowButtons['delete'] = $deleteParams;
                $rowButtons['delete']['attr']['href']['urlvars'] = array_merge(
                    $rowButtons['delete']['attr']['href']['urlvars'],
                    $keysArray
                );
            }

            if ($editable) {
                $trData .= " data-keys='" . htmlspecialchars(json_encode($keysHash)) . "'";
            }
        }

        echo "<tr class=\"data{$id} data-row\"{$trData}>\n";

        if ($keysComplete) {
            echo "<td class=\"action-buttons\">";
            foreach ($rowButtons as $action) {
                echo "<span class=\"opbutton{$id} op-button\">";
                $misc->printLink($action);
                echo "</span>\n";
            }
            echo "</td>\n";
        } else {
            echo "<td colspan=\"{$colspan}\">&nbsp;</td>\n";
        }

        return $editable;
    }

    private function getFieldCount($rs): int
    {
        $cacheKey = is_object($rs) ? spl_object_id($rs) : null;
        if ($cacheKey !== null && isset($this->fieldCountCache[$cacheKey])) {
            return $this->fieldCountCache[$cacheKey];
        }

        $count = is_array($rs->fields) ? count($rs->fields) : 0;
        if ($cacheKey !== null) {
            $this->fieldCountCache[$cacheKey] = $count;
        }

        return $count;
    }

    private function shouldSkipOidColumn($finfo, bool $withOid): bool
    {
        $pg = $this->getPg();
        $conf = $this->getConf();

        return ($finfo->name === $pg->id) && (!($withOid && $conf['show_oids']));
    }

    private function renderForeignKeyLinks(string $fieldName): bool
    {
        $misc = $this->getMisc();

        $rs = $this->currentRecordSet;
        if ($rs === null) {
            return false;
        }

        $nameIndexMap = $this->currentNameIndexMap;
        $fkey_information = $this->currentFkInformation;

        if (!isset($fkey_information['byfield'][$fieldName])) {
            return false;
        }

        $renderedAny = false;
        foreach ($fkey_information['byfield'][$fieldName] as $conid) {
            $query_params = $fkey_information['byconstr'][$conid]['url_data'];

            $fkValuesComplete = true;
            foreach ($fkey_information['byconstr'][$conid]['fkeys'] as $p_field => $f_field) {
                $pVal = $this->getFieldValueByName($rs, $nameIndexMap, (string) $p_field);
                if ($pVal === null) {
                    $fkValuesComplete = false;
                    break;
                }
                $query_params .= '&amp;' . urlencode("fkey[{$f_field}]") . '=' . urlencode((string) $pVal);
            }

            if (!$fkValuesComplete) {
                continue;
            }

            /* $fkey_information['common_url'] is already urlencoded */
            $query_params .= '&amp;' . $fkey_information['common_url'];
            echo "<div style=\"display:inline-block;\">";
            echo "<a class=\"fk fk_" . htmlentities($conid, ENT_QUOTES, 'UTF-8') . "\" href=\"#\" data-href=\"display.php?{$query_params}\">";
            echo "<img src=\"" . $misc->icon('ForeignKey') . "\" style=\"vertical-align:middle;\" alt=\"[fk]\" title=\""
                . htmlentities($fkey_information['byconstr'][$conid]['consrc'], ENT_QUOTES, 'UTF-8')
                . "\" />";
            echo "</a>";
            echo "</div>";

            $renderedAny = true;
        }

        return $renderedAny;
    }

    private function renderByteaCellValue($finfo, $value, array $valParams): bool
    {
        $rs = $this->currentRecordSet;
        if ($rs === null) {
            return false;
        }

        $nameIndexMap = $this->currentNameIndexMap;
        $byteaCols = $this->currentByteaCols;

        if (empty($byteaCols) || !isset($byteaCols[$finfo->name]) || !is_array($byteaCols[$finfo->name])) {
            return false;
        }

        $pg = $this->getPg();
        $misc = $this->getMisc();
        $lang = $this->getLang();

        $meta = $byteaCols[$finfo->name];
        $schema = $meta['schema'] ?? ($_REQUEST['schema'] ?? $pg->_schema);
        $table = $meta['table'] ?? ($_REQUEST['table'] ?? '');
        $column = $meta['column'] ?? $finfo->name;
        $keyFields = $meta['key_fields'] ?? [];

        $canLink = !empty($schema) && !empty($table) && !empty($column) && !empty($keyFields);
        $keyValues = [];
        if ($canLink) {
            foreach ($keyFields as $keyField) {
                $keyVal = $this->getFieldValueByName($rs, $nameIndexMap, (string) $keyField);
                if ($keyVal === null) {
                    $canLink = false;
                    break;
                }
                $keyValues[$keyField] = $keyVal;
            }
        }

        $sizeText = $misc->formatVal($value, 'prettysize', $valParams);
        echo $sizeText;
        if ($canLink && $value !== null) {
            $params = [
                'action' => 'downloadbytea',
                'server' => $_REQUEST['server'],
                'database' => $_REQUEST['database'],
                'schema' => $schema,
                'table' => $table,
                'column' => $column,
                'key' => $keyValues,
                'output' => 'download', // for frameset.js to detect
            ];
            $url = 'display.php?' . http_build_query($params);
            echo ' <a class="ui-btn" href="' . $url . '">' . htmlspecialchars($lang['strdownload']) . '</a>';
        }

        return true;
    }

    /**
     * Build a map of column name => list of numeric indexes in the recordset.
     * Works with ADO fetch mode set to numeric.
     */
    protected function buildFieldNameIndexMap($rs): array
    {
        $cacheKey = is_object($rs) ? spl_object_id($rs) : null;
        if ($cacheKey !== null && isset($this->fieldNameIndexMapCache[$cacheKey])) {
            return $this->fieldNameIndexMapCache[$cacheKey];
        }

        $map = [];
        $fieldCount = $this->getFieldCount($rs);
        for ($i = 0; $i < $fieldCount; $i++) {
            $finfo = $rs->fetchField($i);
            if (!$finfo || !isset($finfo->name)) {
                continue;
            }
            $name = (string) $finfo->name;
            if (!isset($map[$name])) {
                $map[$name] = [];
            }
            $map[$name][] = $i;
        }

        if ($cacheKey !== null) {
            $this->fieldNameIndexMapCache[$cacheKey] = $map;
        }

        return $map;
    }

    /**
     * Best-effort value lookup by column name (first match if duplicated).
     */
    protected function getFieldValueByName($rs, array $nameIndexMap, string $name)
    {
        if (!isset($nameIndexMap[$name][0])) {
            return null;
        }
        $idx = $nameIndexMap[$name][0];
        return $rs->fields[$idx] ?? null;
    }

    protected function getTypesMetaForRecordSet($rs): array
    {
        $typeNames = [];
        $fieldCount = $this->getFieldCount($rs);
        for ($i = 0; $i < $fieldCount; $i++) {
            $finfo = $rs->fetchField($i);
            if ($finfo && isset($finfo->type)) {
                $typeNames[] = $finfo->type;
            }
        }

        $cacheKey = implode(":", $typeNames);
        if (isset($this->typesMetaCache[$cacheKey])) {
            return $this->typesMetaCache[$cacheKey];
        }

        $metas = $this->getTypeActions()->getTypeMetasByNames($typeNames);
        $this->typesMetaCache[$cacheKey] = $metas;

        return $metas;
    }

    /* build & return the FK information data structure
     * used when deciding if a field should have a FK link or not*/
    function getFKInfo()
    {
        $misc = $this->getMisc();
        $constraintActions = $this->getConstraintActions();

        // Get the foreign key(s) information from the current table
        $fkey_information = ['byconstr' => [], 'byfield' => []];

        if (!isset($_REQUEST['table'])) {
            return $fkey_information;
        }

        $constraints = $constraintActions->getConstraintsWithFields($_REQUEST['table']);
        if ($constraints->recordCount() <= 0) {
            return $fkey_information;
        }

        $fkey_information['common_url'] = $misc->getHREF('schema') . '&amp;subject=table';

        /* build the FK constraints data structure */
        while (!$constraints->EOF) {
            $constr = $constraints->fields;
            if ($constr['contype'] != 'f') {
                $constraints->moveNext();
                continue;
            }

            if (!isset($fkey_information['byconstr'][$constr['conid']])) {
                $fkey_information['byconstr'][$constr['conid']] = [
                    'url_data' => 'table=' . urlencode($constr['f_table']) . '&amp;schema=' . urlencode($constr['f_schema']),
                    'fkeys' => [],
                    'consrc' => $constr['consrc']
                ];
            }

            $fkey_information['byconstr'][$constr['conid']]['fkeys'][$constr['p_field']] = $constr['f_field'];

            if (!isset($fkey_information['byfield'][$constr['p_field']]))
                $fkey_information['byfield'][$constr['p_field']] = [];

            $fkey_information['byfield'][$constr['p_field']][] = $constr['conid'];

            $constraints->moveNext();
        }

        return $fkey_information;
    }


    /**
     *  Print table header cells
     * @param \ADORecordSet $rs
     * @param $args - associative array for sort link parameters
     **/
    function printTableHeaderCells($rs, $args, $withOid)
    {
        $misc = $this->getMisc();
        $typeActions = $this->getTypeActions();
        $metas = $this->getTypesMetaForRecordSet($rs);
        $keys = array_keys($_REQUEST['orderby'] ?? []);

        $fieldCount = $this->getFieldCount($rs);

        for ($j = 0; $j < $fieldCount; $j++) {

            $finfo = $rs->fetchField($j);

            if ($this->shouldSkipOidColumn($finfo, (bool) $withOid)) {
                continue;
            }

            if ($args === false) {
                echo "<th class=\"data\"><span>", htmlspecialchars($finfo->name), "</span></th>\n";
                continue;
            }

            $args['page'] = $_REQUEST['page'];
            $sortLink = http_build_query($args);
            $class = 'data';
            if ($typeActions->isLargeTypeMeta($metas[$finfo->type])) {
                $class .= ' large_type';
            }

            echo "<th class=\"$class\">\n";

            if (!isset(TypeActions::NON_SORTABLE_TYPES[$finfo->type])) {
                echo "<span><a class=\"orderby\" data-col=\"", htmlspecialchars($finfo->name), "\" data-type=\"", htmlspecialchars($finfo->type), "\" href=\"display.php?{$sortLink}\"><span>", htmlspecialchars($finfo->name), "</span>";

                if (isset($_REQUEST['orderby'][$finfo->name])) {
                    if ($_REQUEST['orderby'][$finfo->name] === 'desc')
                        echo '<img src="' . $misc->icon('LowerArgument') . '" alt="desc">';
                    else
                        echo '<img src="' . $misc->icon('RaiseArgument') . '" alt="asc">';
                    echo "<span class='small'>", array_search($finfo->name, $keys) + 1, "</span>";
                }

                echo "</a></span>\n";
            } else {
                echo "<span>", htmlspecialchars($finfo->name), "</span>\n";
            }

            echo "</th>\n";
        }

        reset($rs->fields);
    }

    /**
     * Print data-row cells
     * @param \ADORecordSet $rs
     * @param array $fkey_information
     * @param bool $withOid
     * @param bool $editable
     */
    function printTableRowCells($rs, $fkey_information, $withOid, $editable = false)
    {
        $misc = $this->getMisc();
        $conf = $this->getConf();
        $lang = $this->getLang();
        $j = 0;

        $nameIndexMap = $this->buildFieldNameIndexMap($rs);

        // Prime instance state for helper methods (FK links, bytea download links)
        $this->setCurrentBrowseContext($rs, $nameIndexMap, is_array($fkey_information) ? $fkey_information : [], (bool) $withOid, $this->currentHeaderArgs);
        $this->setCurrentRowEditable((bool) $editable);

        $collapsed = ($_REQUEST['strings'] ?? 'collapsed') === 'collapsed';
        $byteaCols = $this->currentByteaCols;

        $editClass = $this->currentEditable ? "editable" : "";

        $fieldCount = $this->getFieldCount($rs);
        for ($j = 0; $j < $fieldCount; $j++) {
            $finfo = $rs->fetchField($j);
            $v = $rs->fields[$j] ?? null;

            if ($this->shouldSkipOidColumn($finfo, (bool) $withOid))
                continue;

            $type = $finfo->type;
            if (isset($byteaCols[$finfo->name])) {
                // Bytea types are not editable at the moment
                $class = "";
                $type = 'bytea';
            } else {
                $class = $editClass;
                if ($type == 'tsvector') {
                    // tsvector columns are not editable at the moment (too complex to edit in-place, and no edit page yet)
                    $class = "";
                }
            }
            if (!$collapsed) {
                $isArray = substr_compare($finfo->type, '_', 0, 1) === 0;
                $array = $isArray ? "array" : "no-array";
                $hasLineBreak = isset($v) && str_contains($v, "\n");
                $lineBreak = $hasLineBreak ? "line-break" : "no-line-break";
                $class .= " auto-wrap $array $lineBreak";
                // 2130 -> 750px , 210 -> 100px
                if (!$hasLineBreak && is_string($v)) {
                    if (strlen($v) > 2100) {
                        $class .= " full-width";
                    } elseif (strlen($v) > 1050) {
                        $class .= " large-width";
                    } elseif (strlen($v) > 500) {
                        $class .= " medium-width";
                    } elseif (strlen($v) > 100) {
                        $class .= " small-width";
                    }
                }
            }

            echo "<td class=\"$class\" data-type=\"$type\" data-name=\"" . htmlspecialchars($finfo->name) . "\">\n";
            $valParams = [
                'null' => true,
                'clip' => $collapsed,
            ];
            if ($v !== null) {
                $is_fk = $this->renderForeignKeyLinks((string) $finfo->name);
                if ($is_fk) {
                    $valParams['class'] = 'fk_value';
                }
            }

            // If this is a modified bytea column, show size + download link
            $is_bytea = $this->renderByteaCellValue($finfo, $v, $valParams);
            if (!$is_bytea) {
                echo "<div class=\"wrapper d-inline-block\">";
                echo $misc->formatVal($v, $finfo->type, $valParams);
                echo "</div>";
            }
            echo "</td>\n";
        }
    }

    private function executeNonReadQuery($query, $save_history)
    {
        $pg = $this->getPg();
        $misc = $this->getMisc();
        $lang = $this->getLang();

        $query = trim($query);
        $succeded = $pg->execute($query) === 0;
        if ($save_history && $succeded) {
            $misc->saveSqlHistory($query, false);
        }

        echo "<div class=\"query-box mb-2\">\n";
        ?>
        <pre class="p-2 sql-viewer"><?= htmlspecialchars($query) ?></pre>
        <?php if (!$succeded): ?>
            <div class="error p-1">
                <?= htmlspecialchars($pg->conn->ErrorMsg()) ?>
            </div>
        <?php endif ?>
        <div class="footer">
            <a href="javascript:void(0)"
                onclick="setEditorValue('query-editor', <?= htmlspecialchars(json_encode($query)) ?>);">
                <span class="psm">✎</span>
                <?= htmlspecialchars($lang['stredit']) ?>
            </a>
            <script>
                function setEditorValue(id, content) {
                    const element = document.getElementById(id);
                    if (element) {
                        //console.log("Setting editor value for", id);
                        if (element.beginEdit) {
                            element.beginEdit(content + "\n");
                        }
                        else {
                            element.value = content + "\n";
                            element.focus();
                        }
                    }
                }
            </script>
            <?php if ($succeded): ?>
                <span class="query-stats ml-2">
                    <?= format_string(
                        $lang['strexecstats'],
                        [
                            'duration' => number_format($pg->lastQueryTime, 4),
                            'rows' => $pg->affectedRows()
                        ]
                    ); ?>
                </span>
            <?php endif; ?>
        </div>

        <?php
        echo "</div>\n";
    }

    private function initBrowseRequestDefaults(array $conf): void
    {
        // If current page is not set, default to first page
        if (!isset($_REQUEST['page'])) {
            $_REQUEST['page'] = 1;
        }

        if (!isset($_REQUEST['orderby'])) {
            $_REQUEST['orderby'] = [];
        }

        if (!isset($_REQUEST['strings'])) {
            $_REQUEST['strings'] = 'collapsed';
        }

        if (!isset($_REQUEST['max_rows'])) {
            $_REQUEST['max_rows'] = $conf['max_rows'];
        }
    }

    private function resolveKeyFields($table_name, array $key_fields_early): array
    {
        if (!empty($key_fields_early)) {
            return $key_fields_early;
        }
        if (isset($table_name)) {
            return $this->getRowActions()->getRowIdentifier($table_name);
        }
        return [];
    }

    private function filterKeyFieldsToThoseInResult(array $key_fields, array $nameIndexMap): array
    {
        // Check that the key is actually in the result set.  This can occur for select
        // operations where the key fields aren't part of the select.  XXX:  We should
        // be able to support this, somehow.
        foreach ($key_fields as $v) {
            // If a key column is not found in the record set, then we
            // can't use the key.
            if (!isset($nameIndexMap[$v])) {
                return [];
            }
        }
        return $key_fields;
    }

    private function prepareBrowseActionButtons(array $_gets): array
    {
        $misc = $this->getMisc();
        $lang = $this->getLang();
        $plugin_manager = AppContainer::getPluginManager();

        $buttons = [
            'edit' => [
                'icon' => $misc->icon('Edit'),
                'content' => $lang['stredit'],
                'attr' => [
                    'href' => [
                        'url' => 'display.php',
                        'urlvars' => array_merge([
                            'action' => 'confeditrow',
                            'strings' => $_REQUEST['strings'],
                            'page' => $_REQUEST['page'],
                        ], $_gets)
                    ]
                ]
            ],
            'delete' => [
                'icon' => $misc->icon('Delete'),
                'content' => $lang['strdelete'],
                'attr' => [
                    'href' => [
                        'url' => 'display.php',
                        'urlvars' => array_merge([
                            'action' => 'confdelrow',
                            'strings' => $_REQUEST['strings'],
                            'page' => $_REQUEST['page'],
                        ], $_gets)
                    ]
                ]
            ],
        ];

        $actions = [
            'actionbuttons' => $buttons,
            'place' => 'display-browse'
        ];
        $plugin_manager->do_hook('actionbuttons', $actions);

        foreach (array_keys($actions['actionbuttons']) as $action) {
            $actions['actionbuttons'][$action]['attr']['href']['urlvars'] = array_merge(
                $actions['actionbuttons'][$action]['attr']['href']['urlvars'],
                $_gets
            );
        }

        $edit_params = $actions['actionbuttons']['edit'] ?? [];
        $delete_params = $actions['actionbuttons']['delete'] ?? [];

        // Display edit and delete actions if we have a key
        $colspan = min(1, count($buttons));


        return [$actions, $edit_params, $delete_params, $colspan];
    }

    private function buildBrowseNavLinks($table_name, $subject, array $_gets, $rs, array $fields, array $lang): array
    {
        $misc = $this->getMisc();

        // Navigation links
        $navlinks = [];

        // Expand/Collapse
        if ($_REQUEST['strings'] == 'expanded') {
            $navlinks['collapse'] = [
                'attr' => [
                    'href' => [
                        'url' => 'display.php',
                        'urlvars' => array_merge(
                            $_gets,
                            [
                                'strings' => 'collapsed',
                                'page' => $_REQUEST['page']
                            ]
                        )
                    ]
                ],
                'icon' => $misc->icon('TextShrink'),
                'content' => $lang['strcollapse']
            ];
        } else {
            $navlinks['collapse'] = [
                'attr' => [
                    'href' => [
                        'url' => 'display.php',
                        'urlvars' => array_merge(
                            $_gets,
                            [
                                'strings' => 'expanded',
                                'page' => $_REQUEST['page']
                            ]
                        )
                    ]
                ],
                'icon' => $misc->icon('TextExpand'),
                'content' => $lang['strexpand']
            ];
        }

        // Return
        if (isset($_REQUEST['return'])) {
            $urlvars = $misc->getSubjectParams($_REQUEST['return']);

            $navlinks['back'] = [
                'attr' => [
                    'href' => [
                        'url' => $urlvars['url'],
                        'urlvars' => $urlvars['params']
                    ]
                ],
                'icon' => $misc->icon('Return'),
                'content' => $lang['strback']
            ];
        }

        // Edit SQL link
        $navlinks['edit'] = [
            'attr' => [
                'href' => [
                    'url' => 'database.php',
                    'urlvars' => array_merge($fields, [
                        'action' => 'sql',
                        'paginate' => 't',
                    ])
                ]
            ],
            'icon' => $misc->icon('Edit'),
            'content' => $lang['streditsql']
        ];


        // Create view and download
        if (isset($_REQUEST['query']) && is_object($rs) && $rs->recordCount() > 0) {

            // Report views don't set a schema, so we need to disable
            // create view in that case
            if (isset($_REQUEST['schema'])) {

                $navlinks['createview'] = [
                    'attr' => [
                        'href' => [
                            'url' => 'views.php',
                            'urlvars' => array_merge($fields, [
                                'action' => 'create',
                                'formDefinition' => $_REQUEST['query']
                            ])
                        ]
                    ],
                    'icon' => $misc->icon('CreateView'),
                    'content' => $lang['strcreateview']
                ];
            }

            $urlvars = [];
            if (isset($_REQUEST['search_path'])) {
                $urlvars['search_path'] = $_REQUEST['search_path'];
            }

            $navlinks['download'] = [
                'attr' => [
                    'href' => [
                        'url' => 'dataexport.php',
                        'urlvars' => array_merge($fields, $urlvars, ['query' => $_REQUEST['query']])
                    ]
                ],
                'icon' => $misc->icon('Download'),
                'content' => $lang['strdownload']
            ];
        }

        // Insert
        if (isset($table_name) && (isset($subject) && $subject == 'table')) {
            $navlinks['insert'] = [
                'attr' => [
                    'href' => [
                        'url' => 'display.php',
                        'urlvars' => array_merge($_gets, [
                            'action' => 'confinsertrow',
                        ])
                    ]
                ],
                'icon' => $misc->icon('Add'),
                'content' => $lang['strinsert']
            ];
        }

        // Refresh
        $navlinks['refresh'] = [
            'attr' => [
                'href' => [
                    'url' => 'display.php',
                    'urlvars' => array_merge(
                        $_gets,
                        [
                            'strings' => $_REQUEST['strings'],
                            'page' => $_REQUEST['page']
                        ]
                    )
                ]
            ],
            'icon' => $misc->icon('Refresh'),
            'content' => $lang['strrefresh']
        ];

        return $navlinks;
    }

    private function prepareBrowseRequest(
        &$subject,
        &$table_name,
        &$query,
        &$parsed,
        &$key_fields_early
    ): bool {
        $pg = $this->getPg();
        $misc = $this->getMisc();
        $lang = $this->getLang();
        $tableActions = new TableActions($pg);
        $rowActions = $this->getRowActions();
        $schemaActions = $this->getSchemaActions();
        $parser = $this->getSqlParser();

        if (!isset($_REQUEST['schema']))
            $_REQUEST['schema'] = $pg->_schema;

        if (isset($_REQUEST['fkey'])) {
            $ops = [];
            foreach ($_REQUEST['fkey'] as $x => $y) {
                $ops[$x] = '=';
            }
            $query = $pg->getSelectSQL($_REQUEST['table'], [], $_REQUEST['fkey'], $ops);
            $_REQUEST['query'] = $query;
        }

        if (isset($_REQUEST['search_path'])) {
            if (
                $schemaActions->setSearchPath(
                    array_map('trim', explode(',', $_REQUEST['search_path']))
                ) != 0
            ) {
                return false;
            }
        }

        $subject = $_REQUEST['subject'] ?? '';
        $table_name = $_REQUEST['table'] ?? $_REQUEST['view'] ?? null;

        $hasReadQuery = false;
        if (!empty($_REQUEST['query'])) {
            $query = trim($_REQUEST['query']);
            if (!str_ends_with($query, ';')) {
                $query .= ';';
            }
            $result = SqlParser::parseFromString($query);
            $statements = array_column(
                array_filter(
                    $result['items'],
                    function ($item) {
                        return $item['type'] === 'statement';
                    }
                ),
                'content'
            );
            foreach ($statements as $index => $stmt) {
                if (is_result_set_query($stmt)) {
                    // Stop at the first read query
                    $query = $stmt;
                    $hasReadQuery = true;
                    if (\count($statements) > $index + 1) {
                        // There are more statements after the read query
                        $misc->printMsg(
                            format_string(
                                $lang['strmultiplequeries'],
                                [
                                    'count' => $index + 1,
                                    'total' => \count($statements)
                                ]
                            )
                        );
                    }
                    break;
                } else {
                    // Execute non-read query
                    $this->executeNonReadQuery($stmt, false);
                }
            }
        } else {
            $hasReadQuery = isset($table_name) || !empty($_REQUEST['query']);
        }

        if (!$hasReadQuery) {
            // If there were no read queries, use last executed query from session
            $query = $_REQUEST['query'] = $_SESSION['sqlquery'] ?? '';
        }

        if (empty($_REQUEST['query']) && $table_name) {
            $parse_table = false;
            $query = "SELECT * FROM " .
                $pg->quoteIdentifier($_REQUEST['schema']) . "." . $pg->quoteIdentifier($table_name) . ";";
        } else {
            $parse_table = true;
        }

        // Save query to history if required
        if (!isset($_REQUEST['nohistory'])) {
            $misc->saveSqlHistory($query, true);
        }

        $parsed = $parser->parse($query);

        $parse_table = $parse_table && !empty($parsed['SELECT']) &&
            ($parsed['FROM'][0]['expr_type'] ?? '') == 'table';
        if ($parse_table) {
            $parts = $parsed['FROM'][0]['no_quotes']['parts'] ?? [];
            $changed = false;
            if (\count($parts) === 2) {
                [$schema, $table] = $parts;
                $changed = $_REQUEST['schema'] != $schema || $table_name != $table;
            } else {
                [$table] = $parts;
                $schema = $_REQUEST['schema'] ?? $pg->_schema;
                if (empty($schema)) {
                    $schema = $tableActions->findTableSchema($table) ?? '';
                    if (!empty($schema)) {
                        $misc->setCurrentSchema($schema);
                    }
                }
                $changed = $table_name != $table && !empty($schema);
            }
            if ($changed) {
                $misc->setCurrentSchema($schema);
                $_REQUEST['schema'] = $schema;
                $table_name = $table;
                unset($_REQUEST[$subject]);
                $subject = $tableActions->getTableType($schema, $table) ?? '';
                if (!empty($subject)) {
                    $_REQUEST['subject'] = $subject;
                    $_REQUEST[$subject] = $table;
                }
            }
        }

        $key_fields_early = [];
        if (isset($table_name)) {
            $key_fields_early = $rowActions->getRowIdentifier($table_name);
        }

        return true;
    }

    private function applyOrderByFromRequest(&$query, $parsed, &$orderBySet): void
    {
        $orderbyClearRequested = !empty($_REQUEST['orderby_clear']);
        if (!isset($_REQUEST['orderby']))
            $_REQUEST['orderby'] = [];

        $orderBySet = false;
        $orderbyIsNonEmpty = is_array($_REQUEST['orderby']) && !empty($_REQUEST['orderby']);
        if ($orderbyClearRequested) {
            $_REQUEST['orderby'] = [];
            $orderbyIsNonEmpty = false;
        }

        if ($orderbyIsNonEmpty || $orderbyClearRequested) {
            if (!empty($parsed['SELECT'])) {
                $newOrderBy = '';
                if ($orderbyIsNonEmpty) {
                    $newOrderBy = 'ORDER BY ';
                    $sep = "";
                    foreach ($_REQUEST['orderby'] as $field => $dir) {
                        $dir = strcasecmp($dir, 'desc') === 0 ? 'DESC' : 'ASC';
                        $newOrderBy .= $sep . pg_escape_id($field) . ' ' . $dir;
                        $sep = ", ";
                    }
                }

                if (!empty($parsed['ORDER'])) {
                    $pattern = '/\s*ORDER\s+BY[\s\S]*?(?=\sLIMIT|\sOFFSET|\sFETCH|\sFOR|\sUNION|\sINTERSECT|\sEXCEPT|\)|--|\/\*|;|\s*$)/i';
                    preg_match_all($pattern, $query, $matches);

                    if (!empty($matches[0])) {
                        $lastOrderBy = end($matches[0]);
                        $query = str_replace($lastOrderBy, $newOrderBy === '' ? '' : ' ' . $newOrderBy, $query);
                        $orderBySet = true;
                    }
                } elseif ($newOrderBy !== '') {
                    $query = rtrim($query, " \t\n\r\0\x0B;");

                    $pattern = '/\s*(?:'
                        . '(?:LIMIT|OFFSET|FETCH|FOR|UNION|INTERSECT|EXCEPT)\b[^;]*'
                        . '|'
                        . '\)'
                        . '|'
                        . '--[^\r\n]*'
                        . '|'
                        . '\/\*.*?\*\/'
                        . ')\s*$/is';

                    if (preg_match($pattern, $query, $matches, PREG_OFFSET_CAPTURE)) {
                        $endPos = $matches[0][1];
                        $query = substr($query, 0, $endPos) . ' ' . $newOrderBy . substr($query, $endPos);
                    } else {
                        $query .= ' ' . $newOrderBy;
                    }

                    $query .= ';';
                    $orderBySet = true;
                }
            }
        } else {
            if (!empty($parsed['ORDER'])) {
                $_REQUEST['orderby'] = [];
                foreach ($parsed['ORDER'] as $orderExpr) {
                    $field = trim($orderExpr['base_expr'], " \t\n\r\0\x0B;");
                    if (preg_match('/^"(?:[^"]|"")*"$/', $field)) {
                        $field = str_replace('""', '"', substr($field, 1, -1));
                    } elseif (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
                        continue;
                    }
                    $dir = strtolower($orderExpr['direction'] ?? '');
                    if ($dir !== 'desc') {
                        $dir = 'asc';
                    }
                    $_REQUEST['orderby'][$field] = $dir;
                }
                $orderBySet = true;
            }
        }
    }

    private function prepareExecQuery($query): array
    {
        $pg = $this->getPg();
        $rowActions = $this->getRowActions();
        $parser = $this->getSqlParser();

        $displayQuery = $query;
        $execQuery = $displayQuery;

        $execParsed = $parser->parse($execQuery);
        if (!empty($execParsed['SELECT']) && !empty($execParsed['FROM']) && is_array($execParsed['FROM'])) {
            $keyFieldsByAlias = [];
            $schemaActionsForKeys = $this->getSchemaActions();
            foreach ($execParsed['FROM'] as $from) {
                if (($from['expr_type'] ?? '') !== 'table') {
                    $keyFieldsByAlias = [];
                    break;
                }
                $parts = $from['no_quotes']['parts'] ?? [];
                if (empty($parts)) {
                    continue;
                }
                if (count($parts) === 2) {
                    $schemaName = $parts[0];
                    $tableName = $parts[1];
                } elseif (count($parts) === 1) {
                    $schemaName = $_REQUEST['schema'] ?? $pg->_schema;
                    $tableName = $parts[0];
                } else {
                    continue;
                }
                $alias = $from['alias']['name'] ?? $tableName;
                if (empty($alias) || empty($tableName)) {
                    continue;
                }

                $schemaActionsForKeys->setSchema($schemaName);
                $keys = $rowActions->getRowIdentifier($tableName);
                if (is_array($keys) && !empty($keys)) {
                    $keyFieldsByAlias[$alias] = $keys;
                }
            }

            if (!empty($keyFieldsByAlias)) {
                $byteaModifier = new ByteaQueryModifier();
                $modifierResult = $byteaModifier->modifyQuery($execParsed, $execQuery, $keyFieldsByAlias);
                $execQuery = $modifierResult['query'];

                if (!empty($modifierResult['bytea_columns'])) {
                    $this->byteaColumns = $modifierResult['bytea_columns'];
                }
            }
        }

        $normalizedForProbe = preg_replace('/^(\s*--.*\n|\s*\/\*.*?\*\/)*/s', '', $execQuery);
        $normalizedForProbe = ltrim($normalizedForProbe);
        $isSelectOrWith = preg_match('/^(SELECT|WITH)\b/i', $normalizedForProbe);
        if ($isSelectOrWith) {
            $alreadyHasMeta = !empty($this->byteaColumns);
            if (!$alreadyHasMeta) {
                $probe = new QueryResultMetadataProbe();
                $probeResult = $probe->probeResultFields($execQuery);
                if (!empty($probeResult['fields']) && empty($probeResult['has_duplicate_names'])) {
                    $hasBytea = false;
                    foreach ($probeResult['fields'] as $f) {
                        if (!empty($f['is_bytea'])) {
                            $hasBytea = true;
                            break;
                        }
                    }
                    if ($hasBytea) {
                        $execQuery = $probe->rewriteQueryReplaceByteaWithLength($execQuery, $probeResult['fields']);
                        $probeMeta = [];
                        foreach ($probeResult['fields'] as $f) {
                            if (!empty($f['is_bytea'])) {
                                $probeMeta[$f['name']] = [
                                    'schema' => null,
                                    'table' => null,
                                    'column' => $f['name'],
                                    'key_fields' => [],
                                ];
                            }
                        }
                        if (!empty($probeMeta)) {
                            $this->byteaColumns = $probeMeta;
                        }
                    }
                }
            }
        }

        return [$displayQuery, $execQuery];
    }

    private function buildBrowseGets($subject, $table_name, $conf): array
    {
        $_gets = [
            'server' => $_REQUEST['server'],
            'database' => $_REQUEST['database']
        ];

        if (isset($_REQUEST['schema']))
            $_gets['schema'] = $_REQUEST['schema'];
        if (isset($table_name))
            $_gets[$subject] = $table_name;
        if (isset($subject))
            $_gets['subject'] = $subject;
        if (isset($_REQUEST['query']) && mb_strlen($_REQUEST['query']) <= $conf['max_get_query_length'])
            $_gets['query'] = $_REQUEST['query'];
        if (isset($_REQUEST['count']))
            $_gets['count'] = $_REQUEST['count'];
        if (isset($_REQUEST['return']))
            $_gets['return'] = $_REQUEST['return'];
        if (isset($_REQUEST['search_path']))
            $_gets['search_path'] = $_REQUEST['search_path'];
        if (isset($_REQUEST['table']))
            $_gets['table'] = $_REQUEST['table'];
        if (isset($_REQUEST['orderby']))
            $_gets['orderby'] = $_REQUEST['orderby'];
        if (isset($_REQUEST['nohistory']))
            $_gets['nohistory'] = $_REQUEST['nohistory'];
        $_gets['strings'] = $_REQUEST['strings'];
        $_gets['max_rows'] = $_REQUEST['max_rows'];

        return $_gets;
    }

    private function renderQueryForm($query, $_sub_params, $lang): void
    {
        unset($_sub_params['query'], $_sub_params['strings']);
        ?>
        <form method="get" id="query-form" onsubmit="adjustQueryFormMethod(this)"
            action="display.php?<?= http_build_query($_sub_params) ?>">
            <div>
                <textarea name="query" id="query-editor" class="sql-editor frame resizable auto-expand _small" width="90%"
                    rows="5" cols="100" resizable="true"><?= html_esc($query) ?></textarea>
            </div>
            <div class="flex-row align-items-center">
                <input type="submit" value="<?= $lang['strquerysubmit'] ?>" />
                <div class="query-explain-frame flex-row align-items-center ml-2">
                    <label class="">
                        <input type="checkbox" id="explain-query" />
                        <?= $lang['strexplain'] ?>:
                    </label>
                    <label class="ml-1">
                        <input type="checkbox" id="explain-analyze" />
                        <?= $lang['stranalyze'] ?>
                    </label>
                    <label class="ml-1">
                        <input type="checkbox" id="explain-verbose" />
                        <?= $lang['strverbose'] ?>
                    </label>
                    <label class="ml-1">
                        <input type="checkbox" id="explain-buffers" />
                        <?= $lang['strbuffers'] ?>
                    </label>
                    <select id="explain-format" class="w-auto ml-1">
                        <option value=""><?= htmlspecialchars($lang['strformat']) ?></option>
                        <option value="TEXT">Text</option>
                        <option value="XML">Xml</option>
                        <option value="JSON">Json</option>
                        <option value="YAML">Yaml</option>
                    </select>
                </div>
                <label class="ml-2">
                    <input type="checkbox" id="expand-strings" name="strings" value="expanded" <?= ($_REQUEST['strings'] ?? '') === 'expanded' ? 'checked' : '' ?> />
                    <?= $lang['strexpand'] ?>
                </label>
            </div>
        </form>
        <?php
    }

    function printAutoCompleteData()
    {
        $pg = $this->getPg();
        $rs = (new SchemaActions($pg))->getSchemaTablesAndColumns(
            $_REQUEST['schema'] ?? $pg->_schema
        );
        $tables = [];
        while (!$rs->EOF) {
            $table = $rs->fields['table_name'];
            $column = $rs->fields['column_name'];
            if (!isset($tables[$table])) {
                $tables[$table] = [];
            }
            $tables[$table][] = $column;
            $rs->moveNext();
        }
        ?>
        <script type="text/javascript">
            window.autocompleteSchema = {
                tables: <?= json_encode($tables) ?>,
                tableList: <?= json_encode(array_keys($tables)) ?>
            };
            window.setTimeout(() => {
                if (window.SQLCompleter)
                    window.SQLCompleter.reload();
            }, 500);
        </script>
        <?php
    }

    function printScripts()
    {
        $lang = $this->getLang();
        $conf = $this->getConf();
        ?>
        <script src="js/display.js" defer type="text/javascript"></script>
        <script type="text/javascript">
            var Display = {
                errmsg: '<?= str_replace("'", "\'", $lang['strconnectionfail']) ?>'
            };
        </script>
        <script type="text/javascript">
            // Adjust form method based on whether the query is read-only and its length
            // is small enough for a GET request.
            function adjustQueryFormMethod(form) {
                const isValidReadQuery =
                    form.query.value.length <= <?= $conf['max_get_query_length'] ?> && extractSqlQueries(form.query.value).every(stmt => isReadOnlyQuery(stmt));
                if (isValidReadQuery) {
                    form.method = 'get';
                } else {
                    form.method = 'post';
                }
            }
        </script>
        <?php
    }

}