<?php

namespace PhpPgAdmin\Database\Actions;



class CastActions extends ActionsBase
{
    /**
     * Returns a list of all casts in the database,
     * including a flag whether the cast is user-defined.
     */
    public function getCasts()
    {
        $conf = $this->conf();

        if ($conf['show_system']) {
            // No restriction, show all CASTs
            $where = '';
        } else {
            // Only show CASTs with user-defined function:
            // - castfunc != 0 (there is a function)
            // - Function schema not pg_%
            $where = <<<'SQL'
            AND c.castfunc <> 0
            AND n3.nspname NOT LIKE $$pg_%$$
            SQL;
        }

        $sql = <<<"SQL"
        SELECT
            c.castsource AS castsourceoid,
            c.casttarget AS casttargetoid,
            c.castsource::pg_catalog.regtype AS castsource,
            c.casttarget::pg_catalog.regtype AS casttarget,
            CASE WHEN c.castfunc = 0 THEN NULL
                 ELSE c.castfunc::pg_catalog.regprocedure
            END AS castfunc,
            c.castcontext,
            obj_description(c.oid, 'pg_cast') AS castcomment,

            -- User cast, if function exists and is not in pg_catalog
            CASE
                WHEN c.castfunc = 0 THEN false
                WHEN n3.nspname NOT LIKE 'pg_%' THEN true
                ELSE false
            END AS is_user_cast

        FROM
            pg_catalog.pg_cast c
            LEFT JOIN pg_catalog.pg_proc p
                ON c.castfunc = p.oid
            LEFT JOIN pg_catalog.pg_namespace n3
                ON p.pronamespace = n3.oid,
            pg_catalog.pg_type t1,
            pg_catalog.pg_type t2,
            pg_catalog.pg_namespace n1,
            pg_catalog.pg_namespace n2
        WHERE
            c.castsource = t1.oid
            AND c.casttarget = t2.oid
            AND t1.typnamespace = n1.oid
            AND t2.typnamespace = n2.oid
            {$where}
        ORDER BY 1, 2
        SQL;

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns candidate functions for CREATE CAST ... WITH FUNCTION.
     *
     * We keep this intentionally strict/simple:
     * - single-argument functions only
     * - excludes polymorphic types (anyelement/anyarray/etc)
     * - optionally respects show_system by hiding pg_% schemas
     */
    public function getCastFunctionCandidates()
    {
        $conf = $this->conf();

        $where = [];
        $where[] = "array_length(p.proargtypes::oid[], 1) = 1";

        // Exclude polymorphic types
        $where[] = "argt.typtype IN ('b','d')";
        $where[] = "rett.typtype IN ('b','d')";

        if (!$conf['show_system']) {
            // Exclude only real system schemas, but allow user schemas
            $where[] = "n.nspname NOT LIKE 'pg_%'";
            $where[] = "n.nspname <> 'information_schema'";
        }

        $sql = "
        SELECT
            p.oid AS prooid,
            p.proname || ' (' || pg_catalog.oidvectortypes(p.proargtypes) || ')' AS proproto,
            (p.proargtypes)[0] AS proargtypeoid,
            p.prorettype AS prorettypeoid
        FROM pg_catalog.pg_proc p
            INNER JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
            INNER JOIN pg_catalog.pg_type argt ON argt.oid = (p.proargtypes)[0]
            INNER JOIN pg_catalog.pg_type rett ON rett.oid = p.prorettype
        WHERE " . implode(' AND ', $where) . "
        ORDER BY proproto
    ";

        return $this->connection->selectSet($sql);
    }


    /**
     * Creates a new cast.
     *
     * @param int $sourceTypeOid
     * @param int $targetTypeOid
     * @param string $method One of: with_function, without_function, with_inout
     * @param int|null $functionOid Required for with_function
     * @param string $context One of: e (explicit), a (assignment), i (implicit)
     * @param string $comment Optional
     */
    public function createCast($sourceTypeOid, $targetTypeOid, $method, $functionOid, $context, $comment = '')
    {
        $sourceTypeOid = (int) $sourceTypeOid;
        $targetTypeOid = (int) $targetTypeOid;
        $functionOid = $functionOid === null ? null : (int) $functionOid;

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        $srcRs = $this->connection->selectSet(
            "SELECT pg_catalog.format_type(oid, NULL) AS typname FROM pg_catalog.pg_type WHERE oid = {$sourceTypeOid}"
        );
        $tgtRs = $this->connection->selectSet(
            "SELECT pg_catalog.format_type(oid, NULL) AS typname FROM pg_catalog.pg_type WHERE oid = {$targetTypeOid}"
        );

        if ($srcRs->recordCount() !== 1 || $tgtRs->recordCount() !== 1) {
            $this->connection->rollbackTransaction();
            return -2;
        }

        $sourceType = $srcRs->fields['typname'];
        $targetType = $tgtRs->fields['typname'];

        $sql = "CREATE CAST ({$sourceType} AS {$targetType}) ";

        switch ($method) {
            case 'with_function':
                if ($functionOid === null) {
                    $this->connection->rollbackTransaction();
                    return -3;
                }

                $fnRs = $this->connection->selectSet(
                    "SELECT oid::pg_catalog.regprocedure AS regproc FROM pg_catalog.pg_proc WHERE oid = {$functionOid}"
                );
                if ($fnRs->recordCount() !== 1) {
                    $this->connection->rollbackTransaction();
                    return -4;
                }
                $sql .= "WITH FUNCTION " . $fnRs->fields['regproc'];
                break;
            case 'without_function':
                $sql .= 'WITHOUT FUNCTION';
                break;
            case 'with_inout':
                $sql .= 'WITH INOUT';
                break;
            default:
                $this->connection->rollbackTransaction();
                return -5;
        }

        if ($context === 'a') {
            $sql .= ' AS ASSIGNMENT';
        } elseif ($context === 'i') {
            $sql .= ' AS IMPLICIT';
        }

        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -6;
        }

        if ($comment !== '') {
            $status = $this->connection->setComment('CAST', $sourceType, $targetType, $comment);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -7;
            }
        }

        return $this->connection->endTransaction();
    }

    /**
     * Drops a cast.
     *
     * @param int $sourceTypeOid
     * @param int $targetTypeOid
     */
    public function dropCast($sourceTypeOid, $targetTypeOid, $cascade)
    {
        $sourceTypeOid = (int) $sourceTypeOid;
        $targetTypeOid = (int) $targetTypeOid;

        $srcRs = $this->connection->selectSet(
            "SELECT pg_catalog.format_type(oid, NULL) AS typname FROM pg_catalog.pg_type WHERE oid = {$sourceTypeOid}"
        );
        $tgtRs = $this->connection->selectSet(
            "SELECT pg_catalog.format_type(oid, NULL) AS typname FROM pg_catalog.pg_type WHERE oid = {$targetTypeOid}"
        );

        if ($srcRs->recordCount() !== 1 || $tgtRs->recordCount() !== 1) {
            return -1;
        }

        $sourceType = $srcRs->fields['typname'];
        $targetType = $tgtRs->fields['typname'];

        $sql = "DROP CAST ({$sourceType} AS {$targetType})";
        if ($cascade) {
            $sql .= ' CASCADE';
        }

        return $this->connection->execute($sql);
    }
}