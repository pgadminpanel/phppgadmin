<?php

namespace PhpPgAdmin\Database\Actions;



class LanguageActions extends ActionsBase
{
    // Base constructor inherited from Actions

    /**
     * Gets all languages.
     */
    public function getLanguages($all = false)
    {
        $conf = $this->conf();

        if ($conf['show_system'] || $all) {
            $where = '';
        } else {
            $where = 'WHERE lanispl';
        }

        $sql = "
            SELECT
                lanname, lanpltrusted,
                lanplcallfoid::pg_catalog.regproc AS lanplcallf
            FROM
                pg_catalog.pg_language
            {$where}
            ORDER BY lanname
        ";

        return $this->connection->selectSet($sql);
    }
}
