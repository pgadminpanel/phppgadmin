<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\TablespaceActions;

/**
 * Dumper for PostgreSQL tablespaces.
 */
class TablespaceDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $tablespaceActions = new TablespaceActions($this->connection);
        $tablespaces = $tablespaceActions->getTablespaces();

        $this->write("\n-- Tablespaces\n");

        while ($tablespaces && !$tablespaces->EOF) {
            $spcname = $tablespaces->fields['spcname'];

            // Skip default tablespaces
            if ($spcname === 'pg_default' || $spcname === 'pg_global') {
                $tablespaces->moveNext();
                continue;
            }

            $this->writeDrop('TABLESPACE', $spcname, $options);

            $this->write("CREATE TABLESPACE \"" . addslashes($spcname) . "\"");
            if (!empty($tablespaces->fields['spclocation'])) {
                $location = $tablespaces->fields['spclocation'];
                $this->connection->clean($location);
                $this->write(" LOCATION '{$location}'");
            }
            $this->write(";\n");

            // Add comment if present and requested
            if ($this->shouldIncludeComments($options)) {
                $c_spcname = $spcname;
                $this->connection->clean($c_spcname);
                $commentSql = "SELECT pg_catalog.obj_description(oid, 'pg_tablespace') AS comment FROM pg_catalog.pg_tablespace WHERE spcname = '{$c_spcname}'";
                $commentRs = $this->connection->selectSet($commentSql);
                if ($commentRs && !$commentRs->EOF && !empty($commentRs->fields['comment'])) {
                    $this->connection->clean($commentRs->fields['comment']);
                    $this->write("COMMENT ON TABLESPACE \"" . addslashes($spcname) . "\" IS '{$commentRs->fields['comment']}';\\n");
                }
            }

            $spcnameQuoted = $this->connection->quoteIdentifier($spcname);
            $this->writeOwner(
                $spcnameQuoted,
                'TABLESPACE',
                $tablespaces->fields['spcowner']
            );
            $this->writePrivileges(
                $spcname,
                'tablespace',
                $tablespaces->fields['spcowner'],
                $tablespaces->fields['spcacl']
            );

            $tablespaces->moveNext();
        }
    }
}
