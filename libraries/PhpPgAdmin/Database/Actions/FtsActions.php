<?php

namespace PhpPgAdmin\Database\Actions;



class FtsActions extends ActionsBase
{

    /**
     * Creates a new FTS configuration.
     */
    public function createFtsConfiguration($cfgname, $parser = '', $template = '', $comment = '')
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($cfgname);

        $sql = "CREATE TEXT SEARCH CONFIGURATION \"{$f_schema}\".\"{$cfgname}\" (";
        if ($parser != '') {
            $this->connection->fieldClean($parser['schema']);
            $this->connection->fieldClean($parser['parser']);
            $parser = "\"{$parser['schema']}\".\"{$parser['parser']}\"";
            $sql .= " PARSER = {$parser}";
        }
        if ($template != '') {
            $this->connection->fieldClean($template['schema']);
            $this->connection->fieldClean($template['name']);
            $sql .= " COPY = \"{$template['schema']}\".\"{$template['name']}\"";
        }
        $sql .= ")";

        if ($comment != '') {
            $status = $this->connection->beginTransaction();
            if ($status != 0) {
                return -1;
            }
        }

        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($comment != '') {
            $status = $this->connection->setComment('TEXT SEARCH CONFIGURATION', $cfgname, '', $comment);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -1;
            }

            return $this->connection->endTransaction();
        }

        return 0;
    }

    /**
     * Returns available FTS configurations.
     */
    public function getFtsConfigurations($all = true)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $sql =
            "SELECT
                n.nspname as schema,
                c.cfgname as name,
                pg_catalog.pg_get_userbyid(c.cfgowner) AS cfgowner,
                pg_catalog.obj_description(c.oid, 'pg_ts_config') as comment
            FROM
                pg_catalog.pg_ts_config c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.cfgnamespace
            WHERE
                pg_catalog.pg_ts_config_is_visible(c.oid)";

        if (!$all) {
            $sql .= " AND  n.nspname='{$c_schema}'\n";
        }

        $sql .= "ORDER BY name";

        return $this->connection->selectSet($sql);
    }

    /**
     * Return all information related to a FTS configuration.
     */
    public function getFtsConfigurationByName($ftscfg)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($ftscfg);
        $sql =
            "SELECT
                n.nspname as schema,
                c.cfgname as name,
                p.prsname as parser,
                c.cfgparser as parser_id,
                pg_catalog.obj_description(c.oid, 'pg_ts_config') as comment
            FROM pg_catalog.pg_ts_config c
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.cfgnamespace
                LEFT JOIN pg_catalog.pg_ts_parser p ON p.oid = c.cfgparser
            WHERE pg_catalog.pg_ts_config_is_visible(c.oid)
                AND c.cfgname = '{$ftscfg}'
                AND n.nspname='{$c_schema}'";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns the map of FTS configuration given.
     */
    public function getFtsConfigurationMap($ftscfg)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->fieldClean($ftscfg);

        $oidSet = $this->connection->selectSet("SELECT c.oid
            FROM pg_catalog.pg_ts_config AS c
                LEFT JOIN pg_catalog.pg_namespace n ON (n.oid = c.cfgnamespace)
            WHERE c.cfgname = '{$ftscfg}'
                AND n.nspname='{$c_schema}'");

        $oid = $oidSet->fields['oid'];

        $sql = "
            SELECT
                (SELECT t.alias FROM pg_catalog.ts_token_type(c.cfgparser) AS t WHERE t.tokid = m.maptokentype) AS name,
                (SELECT t.description FROM pg_catalog.ts_token_type(c.cfgparser) AS t WHERE t.tokid = m.maptokentype) AS description,
                c.cfgname AS cfgname, n.nspname ||'.'|| d.dictname as dictionaries
            FROM
                pg_catalog.pg_ts_config AS c, pg_catalog.pg_ts_config_map AS m, pg_catalog.pg_ts_dict d,
                pg_catalog.pg_namespace n
            WHERE
                c.oid = {$oid}
                AND m.mapcfg = c.oid
                AND m.mapdict = d.oid
                AND d.dictnamespace = n.oid
            ORDER BY name
            ";
        return $this->connection->selectSet($sql);
    }

    /**
     * Returns FTS parsers available.
     */
    public function getFtsParsers($all = true)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $sql =
            "SELECT
               n.nspname as schema,
               p.prsname as name,
               pg_catalog.obj_description(p.oid, 'pg_ts_parser') as comment
            FROM pg_catalog.pg_ts_parser p
                LEFT JOIN pg_catalog.pg_namespace n ON (n.oid = p.prsnamespace)
            WHERE pg_catalog.pg_ts_parser_is_visible(p.oid)";

        if (!$all) {
            $sql .= " AND n.nspname='{$c_schema}'\n";
        }

        $sql .= "ORDER BY name";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns FTS dictionaries available.
     */
    public function getFtsDictionaries($all = true)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $sql =
            "SELECT
                n.nspname as schema, d.dictname as name,
                pg_catalog.pg_get_userbyid(d.dictowner) AS dictowner,
                pg_catalog.obj_description(d.oid, 'pg_ts_dict') as comment
            FROM pg_catalog.pg_ts_dict d
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = d.dictnamespace
            WHERE pg_catalog.pg_ts_dict_is_visible(d.oid)";

        if (!$all) {
            $sql .= " AND n.nspname='{$c_schema}'\n";
        }

        $sql .= "ORDER BY name;";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns all FTS dictionary templates available.
     */
    public function getFtsDictionaryTemplates()
    {
        $sql =
            "SELECT
                n.nspname as schema,
                t.tmplname as name,
                ( SELECT COALESCE(np.nspname, '(null)')::pg_catalog.text || '.' || p.proname
                    FROM pg_catalog.pg_proc p
                    LEFT JOIN pg_catalog.pg_namespace np ON np.oid = p.pronamespace
                    WHERE t.tmplinit = p.oid ) AS  init,
                ( SELECT COALESCE(np.nspname, '(null)')::pg_catalog.text || '.' || p.proname
                    FROM pg_catalog.pg_proc p
                    LEFT JOIN pg_catalog.pg_namespace np ON np.oid = p.pronamespace
                    WHERE t.tmpllexize = p.oid ) AS  lexize,
                pg_catalog.obj_description(t.oid, 'pg_ts_template') as comment
            FROM pg_catalog.pg_ts_template t
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = t.tmplnamespace
            WHERE pg_catalog.pg_ts_template_is_visible(t.oid)
            ORDER BY name;";

        return $this->connection->selectSet($sql);
    }

    /**
     * Drops FTS configuration.
     */
    public function dropFtsConfiguration($ftscfg, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($ftscfg);

        $sql = "DROP TEXT SEARCH CONFIGURATION \"{$f_schema}\".\"{$ftscfg}\"";
        if ($cascade) {
            $sql .= ' CASCADE';
        }

        return $this->connection->execute($sql);
    }

    /**
     * Drops FTS dictionary.
     */
    public function dropFtsDictionary($ftsdict, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($ftsdict);

        $sql = "DROP TEXT SEARCH DICTIONARY";
        $sql .= " \"{$f_schema}\".\"{$ftsdict}\"";
        if ($cascade) {
            $sql .= ' CASCADE';
        }

        return $this->connection->execute($sql);
    }

    /**
     * Alters FTS configuration.
     */
    public function updateFtsConfiguration($cfgname, $comment, $name)
    {
        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        $this->connection->fieldClean($cfgname);

        $status = $this->connection->setComment('TEXT SEARCH CONFIGURATION', $cfgname, '', $comment);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($name != $cfgname) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $this->connection->fieldClean($name);

            $sql = "ALTER TEXT SEARCH CONFIGURATION \"{$f_schema}\".\"{$cfgname}\" RENAME TO \"{$name}\"";
            $status = $this->connection->execute($sql);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        return $this->connection->endTransaction();
    }

    /**
     * Creates a new FTS dictionary or FTS dictionary template.
     */
    public function createFtsDictionary(
        $dictname,
        $isTemplate = false,
        $template = '',
        $lexize = '',
        $init = '',
        $option = '',
        $comment = ''
    ) {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($dictname);
        $this->connection->fieldClean($template);
        $this->connection->fieldClean($lexize);
        $this->connection->fieldClean($init);
        $this->connection->fieldClean($option);

        $sql = "CREATE TEXT SEARCH";
        if ($isTemplate) {
            $sql .= " TEMPLATE \"{$f_schema}\".\"{$dictname}\" (";
            if ($lexize != '') {
                $sql .= " LEXIZE = {$lexize}";
            }
            if ($init != '') {
                $sql .= ", INIT = {$init}";
            }
            $sql .= ")";
            $whatToComment = 'TEXT SEARCH TEMPLATE';
        } else {
            $sql .= " DICTIONARY \"{$f_schema}\".\"{$dictname}\" (";
            if ($template != '') {
                $this->connection->fieldClean($template['schema']);
                $this->connection->fieldClean($template['name']);
                $template = "\"{$template['schema']}\".\"{$template['name']}\"";

                $sql .= " TEMPLATE = {$template}";
            }
            if ($option != '') {
                $sql .= ", {$option}";
            }
            $sql .= ")";
            $whatToComment = 'TEXT SEARCH DICTIONARY';
        }

        if ($comment != '') {
            $status = $this->connection->beginTransaction();
            if ($status != 0) {
                return -1;
            }
        }

        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($comment != '') {
            $status = $this->connection->setComment($whatToComment, $dictname, '', $comment);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        return $this->connection->endTransaction();
    }

    /**
     * Alters FTS dictionary or dictionary template.
     */
    public function updateFtsDictionary($dictname, $comment, $name)
    {
        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        $this->connection->fieldClean($dictname);
        $status = $this->connection->setComment('TEXT SEARCH DICTIONARY', $dictname, '', $comment);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($name != $dictname) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $this->connection->fieldClean($name);

            $sql = "ALTER TEXT SEARCH DICTIONARY \"{$f_schema}\".\"{$dictname}\" RENAME TO \"{$name}\"";
            $status = $this->connection->execute($sql);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        return $this->connection->endTransaction();
    }

    /**
     * Return all information relating to a FTS dictionary.
     */
    public function getFtsDictionaryByName($ftsdict)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($ftsdict);

        $sql = "SELECT
               n.nspname as schema,
               d.dictname as name,
               ( SELECT COALESCE(nt.nspname, '(null)')::pg_catalog.text || '.' || t.tmplname FROM
                 pg_catalog.pg_ts_template t
                                  LEFT JOIN pg_catalog.pg_namespace nt ON nt.oid = t.tmplnamespace
                                  WHERE d.dicttemplate = t.oid ) AS  template,
               d.dictinitoption as init,
               pg_catalog.obj_description(d.oid, 'pg_ts_dict') as comment
            FROM pg_catalog.pg_ts_dict d
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = d.dictnamespace
            WHERE d.dictname = '{$ftsdict}'
               AND pg_catalog.pg_ts_dict_is_visible(d.oid)
               AND n.nspname='{$c_schema}'
            ORDER BY name";

        return $this->connection->selectSet($sql);
    }

    /**
     * Creates/updates/deletes FTS mapping.
     */
    public function changeFtsMapping($ftscfg, $mapping, $action, $dictname = null)
    {
        if (count($mapping) > 0) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $this->connection->fieldClean($ftscfg);
            $this->connection->fieldClean($dictname);
            $this->connection->arrayClean($mapping);

            switch ($action) {
                case 'alter':
                    $whatToDo = "ALTER";
                    break;
                case 'drop':
                    $whatToDo = "DROP";
                    break;
                default:
                    $whatToDo = "ADD";
                    break;
            }

            $sql = "ALTER TEXT SEARCH CONFIGURATION \"{$f_schema}\".\"{$ftscfg}\" {$whatToDo} MAPPING FOR ";
            $sql .= implode(",", $mapping);
            if ($action != 'drop' && !empty($dictname)) {
                $sql .= " WITH {$dictname}";
            }

            return $this->connection->execute($sql);
        } else {
            return -1;
        }
    }

    /**
     * Return all information related to a given FTS configuration's mapping.
     */
    public function getFtsMappingByName($ftscfg, $mapping)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($ftscfg);
        $this->connection->clean($mapping);

        $oidSet = $this->connection->selectSet("SELECT c.oid, cfgparser
            FROM pg_catalog.pg_ts_config AS c
                LEFT JOIN pg_catalog.pg_namespace AS n ON n.oid = c.cfgnamespace
            WHERE c.cfgname = '{$ftscfg}'
                AND n.nspname='{$c_schema}'");

        $oid = $oidSet->fields['oid'];
        $cfgparser = $oidSet->fields['cfgparser'];

        $tokenIdSet = $this->connection->selectSet("SELECT tokid
            FROM pg_catalog.ts_token_type({$cfgparser})
            WHERE alias = '{$mapping}'");

        $tokid = $tokenIdSet->fields['tokid'];

        $sql = "SELECT
                (SELECT t.alias FROM pg_catalog.ts_token_type(c.cfgparser) AS t WHERE t.tokid = m.maptokentype) AS name,
                d.dictname as dictionaries
            FROM pg_catalog.pg_ts_config AS c, pg_catalog.pg_ts_config_map AS m, pg_catalog.pg_ts_dict d
            WHERE c.oid = {$oid} AND m.mapcfg = c.oid AND m.maptokentype = {$tokid} AND m.mapdict = d.oid
            LIMIT 1;";

        return $this->connection->selectSet($sql);
    }

    /**
     * Return list of FTS mappings possible for given parser.
     */
    public function getFtsMappings($ftscfg)
    {
        $cfg = $this->getFtsConfigurationByName($ftscfg);

        $sql = "SELECT alias AS name, description
            FROM pg_catalog.ts_token_type({$cfg->fields['parser_id']})
            ORDER BY name";

        return $this->connection->selectSet($sql);
    }
}
