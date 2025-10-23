<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\RoleActions;

/**
 * Dumper for PostgreSQL roles.
 */
class RoleDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $roleActions = new RoleActions($this->connection);
        $roles = $roleActions->getRoles();

        $this->write("\n-- Roles\n");

        while ($roles && !$roles->EOF) {
            $rolename = $roles->fields['rolname'];

            $this->write("-- Role: {$rolename}\n");

            // Skip system roles if requested
            if (empty($options['all_roles']) && ($rolename === 'postgres' || strpos($rolename, 'pg_') === 0)) {
                $roles->moveNext();
                continue;
            }

            $this->writeDrop('ROLE', $rolename, $options);

            $this->write("CREATE ROLE \"" . addslashes($rolename) . "\"");

            $attrs = [];
            if ($this->connection->phpBool($roles->fields['rolsuper']))
                $attrs[] = "SUPERUSER";
            else
                $attrs[] = "NOSUPERUSER";
            if ($this->connection->phpBool($roles->fields['rolinherit']))
                $attrs[] = "INHERIT";
            else
                $attrs[] = "NOINHERIT";
            if ($this->connection->phpBool($roles->fields['rolcreaterole']))
                $attrs[] = "CREATEROLE";
            else
                $attrs[] = "NOCREATEROLE";
            if ($this->connection->phpBool($roles->fields['rolcreatedb']))
                $attrs[] = "CREATEDB";
            else
                $attrs[] = "NOCREATEDB";
            if ($this->connection->phpBool($roles->fields['rolcanlogin']))
                $attrs[] = "LOGIN";
            else
                $attrs[] = "NOLOGIN";
            if (isset($roles->fields['rolreplication']) && $this->connection->phpBool($roles->fields['rolreplication']))
                $attrs[] = "REPLICATION";
            if (isset($roles->fields['rolbypassrls']) && $this->connection->phpBool($roles->fields['rolbypassrls']))
                $attrs[] = "BYPASSRLS";

            if ($roles->fields['rolconnlimit'] != -1) {
                $attrs[] = "CONNECTION LIMIT " . $roles->fields['rolconnlimit'];
            }

            $this->write(" WITH " . implode(' ', $attrs) . ";\n");

            // Memberships
            $members = $roleActions->getMembers($rolename);
            if ($members) {
                while (!$members->EOF) {
                    $this->write("GRANT \"" . addslashes($rolename) . "\" TO \"" . addslashes($members->fields['rolname']) . "\"");
                    if ($this->connection->phpBool($members->fields['admin_option'])) {
                        $this->write(" WITH ADMIN OPTION");
                    }
                    $this->write(";\n");
                    $members->moveNext();
                }
            }

            $roles->moveNext();
        }
    }
}
