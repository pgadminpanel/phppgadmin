<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Postgres;

/**
 * Factory for creating dumper instances.
 */
class DumpFactory
{
    /**
     * Creates a dumper for the specified subject.
     * 
     * @param string $subject
     * @param Postgres $connection
     * @return ExportDumper
     * @throws \Exception If subject is not supported
     */
    public static function create($subject, Postgres $connection): ExportDumper
    {
        switch ($subject) {
            case 'aggregate':
                return new AggregateDumper($connection);
            case 'database':
                return new DatabaseDumper($connection);
            case 'domain':
                return new DomainDumper($connection);
            case 'function':
                return new FunctionDumper($connection);
            case 'materialized_view':
                return new MaterializedViewDumper($connection);
            case 'operator':
                return new OperatorDumper($connection);
            case 'partition':
                return new PartitionDumper($connection);
            case 'partitioned_table':
                return new PartitionedTableDumper($connection);
            case 'sub_partitioned_table':
                return new SubPartitionedTableDumper($connection);
            case 'role':
                return new RoleDumper($connection);
            case 'schema':
                return new SchemaDumper($connection);
            case 'sequence':
                return new SequenceDumper($connection);
            case 'server':
                return new ServerDumper($connection);
            case 'table':
                return new TableDumper($connection);
            case 'tablespace':
                return new TablespaceDumper($connection);
            case 'type':
                return new TypeDumper($connection);
            case 'view':
                return new ViewDumper($connection);
            default:
                throw new \Exception("Unsupported dump subject: {$subject}");
        }
    }
}
