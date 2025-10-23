<?php

/**
 * PHPPgAdmin v6.0+
 * @package PhpPgAdmin\Database\Actions
 */

namespace PhpPgAdmin\Database\Actions;

use ADORecordSet;


/**
 * Role action class - handles role, user, and group management
 */
class RoleActions extends ActionsBase
{

	/**
	 * Returns all roles, excluding the given role if specified
	 * @param string $rolename (optional) Exclude this role
	 * @return ADORecordSet A recordset
	 */
	public function getRoles($rolename = '')
	{
		$this->connection->clean($rolename);

		$clause = '';
		if ($this->conf()['show_system'] ?? false) {
			$clause .= 'true';
		} else {
			$clause .= "r.rolname NOT LIKE 'pg_%'";
		}
		if (!empty($rolename)) {
			$clause .= " AND r.rolname != '{$rolename}'";
		}

		$sql = <<<SQL
		SELECT r.rolname, r.rolsuper, r.rolinherit, r.rolcreaterole,
				r.rolcreatedb, r.rolcanlogin, r.rolconnlimit, r.rolvaliduntil,
				r.rolconfig,
				shobj_description(r.oid, 'pg_authid') AS rolcomment
			FROM pg_catalog.pg_roles r
			WHERE {$clause}
			ORDER BY
				(r.rolname LIKE 'pg_%') ASC,
				r.rolname ASC
		SQL;

		return $this->connection->selectSet($sql);
	}

	/**
	 * Returns information for a specific role
	 * @param string $rolename The role name
	 * @return ADORecordSet A recordset
	 */
	public function getRole($rolename)
	{
		$this->connection->clean($rolename);

		$sql = <<<SQL
		SELECT r.rolname, r.rolsuper, r.rolinherit, r.rolcreaterole, r.rolcreatedb, 
			   r.rolcanlogin, r.rolconnlimit, r.rolvaliduntil, r.rolconfig,
			   shobj_description(r.oid, 'pg_authid') AS rolcomment
		FROM pg_catalog.pg_roles r
		WHERE r.rolname = '{$rolename}'
		SQL;

		return $this->connection->selectSet($sql);
	}

	/**
	 * Grants a role to a user or role
	 * @param string $role The role to grant
	 * @param string $rolename The role/user to grant to
	 * @param int $admin (optional) ADMIN OPTION flag (0 or 1)
	 * @return int 0 success
	 */
	public function grantRole($role, $rolename, $admin = 0)
	{
		$this->connection->fieldClean($role);
		$this->connection->fieldClean($rolename);

		$sql = "GRANT \"{$role}\" TO \"{$rolename}\"";

		if ($admin) {
			$sql .= " WITH ADMIN OPTION";
		}

		return $this->connection->execute($sql);
	}

	/**
	 * Revokes a role from a user or role
	 * @param string $role The role to revoke
	 * @param string $rolename The role/user to revoke from
	 * @param int $admin (optional) Only revoke ADMIN OPTION (0 or 1)
	 * @param string $type (optional) RESTRICT or CASCADE
	 * @return int 0 success
	 */
	public function revokeRole($role, $rolename, $admin = 0, $type = 'RESTRICT')
	{
		$this->connection->fieldClean($role);
		$this->connection->fieldClean($rolename);

		$sql = "REVOKE ";

		if ($admin) {
			$sql .= "ADMIN OPTION FOR ";
		}

		$sql .= "\"{$role}\" FROM \"{$rolename}\"";

		if ($type == 'CASCADE') {
			$sql .= " CASCADE";
		} else {
			$sql .= " RESTRICT";
		}

		return $this->connection->execute($sql);
	}

	/**
	 * Returns all users (legacy API - users are now roles)
	 * @return ADORecordSet A recordset
	 */
	public function getUsers()
	{
		$sql = "SELECT rolname AS usename,
			rolsuper AS usesuper,
			rolcreatedb AS usecreatedb,
			rolvaliduntil AS valuntil,
			rolconfig AS useconfig,
			rolcanlogin AS canlogin
        FROM pg_catalog.pg_roles
        ORDER BY
            (rolname LIKE 'pg_%') ASC,
            rolname ASC";

		return $this->connection->selectSet($sql);
	}

	/**
	 * Creates a new role
	 * @param string $rolename The role name
	 * @param string $password The password (optional)
	 * @param int $superuser 1 if superuser, 0 otherwise
	 * @param int $createdb 1 if can create databases, 0 otherwise
	 * @param int $createrole 1 if can create roles, 0 otherwise
	 * @param int $inherits 1 if inherits, 0 otherwise
	 * @param int $login 1 if can login, 0 otherwise
	 * @param int $connlimit Connection limit (-1 for unlimited)
	 * @param string $expiry Role expiry date (optional)
	 * @param array $memberof Array of roles to add this role to (optional)
	 * @param array $members Array of roles/users to add to this role (optional)
	 * @param array $adminmembers Array of roles/users to add as admin members (optional)
	 * @param mixed $comment
	 * @return int 0 success
	 */
	public function createRole(
		$rolename,
		$password = '',
		$superuser = 0,
		$createdb = 0,
		$createrole = 0,
		$inherits = 1,
		$login = 1,
		$connlimit = -1,
		$expiry = '',
		$memberof = [],
		$members = [],
		$adminmembers = [],
		$comment = ''
	)
	{
		$this->connection->fieldClean($rolename);

		$sql = "CREATE ROLE \"{$rolename}\"";

		// Set password if provided
		if ($password != '') {
			$this->connection->clean($password);
			$sql .= " WITH PASSWORD '{$password}'";
		}

		// Set role attributes
		if ($superuser) {
			$sql .= " SUPERUSER";
		} else {
			$sql .= " NOSUPERUSER";
		}

		if ($createdb) {
			$sql .= " CREATEDB";
		} else {
			$sql .= " NOCREATEDB";
		}

		if ($createrole) {
			$sql .= " CREATEROLE";
		} else {
			$sql .= " NOCREATEROLE";
		}

		if ($inherits) {
			$sql .= " INHERIT";
		} else {
			$sql .= " NOINHERIT";
		}

		if ($login) {
			$sql .= " LOGIN";
		} else {
			$sql .= " NOLOGIN";
		}

		if (!empty($connlimit) && $connlimit != -1) {
			$sql .= " CONNECTION LIMIT {$connlimit}";
		}

		if ($expiry != '') {
			$this->connection->clean($expiry);
			$sql .= " VALID UNTIL '{$expiry}'";
		}

		// Handle role memberships
		if (is_array($memberof) && count($memberof) > 0) {
			$this->connection->fieldArrayClean($memberof);
			$sql .= ' IN ROLE "' . implode('", "', $memberof) . '"';
		}

		if (is_array($members) && count($members) > 0) {
			$this->connection->fieldArrayClean($members);
			$sql .= ' ROLE "' . implode('", "', $members) . '"';
		}

		if (is_array($adminmembers) && count($adminmembers) > 0) {
			$this->connection->fieldArrayClean($adminmembers);
			$sql .= ' ADMIN "' . implode('", "', $adminmembers) . '"';
		}

		$status = $this->connection->beginTransaction();
		if ($status != 0) {
			return -1;
		}

		$status = $this->connection->execute($sql);
		if ($status != 0) {
			$this->connection->rollbackTransaction();
			return -2;
		}

		if ($comment != '') {
			$status = $this->connection->setComment(
				'ROLE',
				$rolename,
				null,
				$comment
			);
			if ($status != 0) {
				$this->connection->rollbackTransaction();
				return -3;
			}
		}
		return $this->connection->commitTransaction();
	}

	/**
	 * Updates role attributes
	 * @param string $rolename The role name
	 * @param string $password The new password (optional)
	 * @param int $superuser 1 if superuser, 0 otherwise
	 * @param int $createdb 1 if can create databases, 0 otherwise
	 * @param int $createrole 1 if can create roles, 0 otherwise
	 * @param int $inherits 1 if inherits, 0 otherwise
	 * @param int $login 1 if can login, 0 otherwise
	 * @param int $connlimit Connection limit (-1 for unlimited)
	 * @param string $expiry Role expiry date (optional)
	 * @param array $memberof Array of roles to add this role to (optional)
	 * @param array $members Array of roles/users to add to this role (optional)
	 * @param array $adminmembers Array of roles/users to add as admin members (optional)
	 * @param array $memberofold Old memberof array for change detection (optional)
	 * @param array $membersold Old members array for change detection (optional)
	 * @param array $adminmembersold Old adminmembers array for change detection (optional)
	 * @return 0 success
	 */
	protected function setRole(
		$rolename,
		$password = '',
		$superuser = 0,
		$createdb = 0,
		$createrole = 0,
		$inherits = 1,
		$login = 1,
		$connlimit = -1,
		$expiry = '',
		$memberof = [],
		$members = [],
		$adminmembers = [],
		$memberofold = [],
		$membersold = [],
		$adminmembersold = []
	)
	{
		$this->connection->fieldClean($rolename);

		$sql = "ALTER ROLE \"{$rolename}\"";

		// Set password if provided
		if ($password != '') {
			$this->connection->clean($password);
			$sql .= " WITH PASSWORD '{$password}'";
		}

		// Set role attributes
		if ($superuser) {
			$sql .= " SUPERUSER";
		} else {
			$sql .= " NOSUPERUSER";
		}

		if ($createdb) {
			$sql .= " CREATEDB";
		} else {
			$sql .= " NOCREATEDB";
		}

		if ($createrole) {
			$sql .= " CREATEROLE";
		} else {
			$sql .= " NOCREATEROLE";
		}

		if ($inherits) {
			$sql .= " INHERIT";
		} else {
			$sql .= " NOINHERIT";
		}

		if ($login) {
			$sql .= " LOGIN";
		} else {
			$sql .= " NOLOGIN";
		}

		if (!empty($connlimit) && $connlimit != -1) {
			$sql .= " CONNECTION LIMIT {$connlimit}";
		}

		if ($expiry != '') {
			$this->connection->clean($expiry);
			$sql .= " VALID UNTIL '{$expiry}'";
		}

		$status = $this->connection->execute($sql);
		if ($status != 0) {
			return $status;
		}

		// Handle memberof changes
		if (is_array($memberofold) && is_array($memberof)) {
			$revoke = array_diff($memberofold, $memberof);
			$grant = array_diff($memberof, $memberofold);

			foreach ($revoke as $role) {
				$status = $this->revokeRole($role, $rolename);
				if ($status != 0) {
					return $status;
				}
			}

			foreach ($grant as $role) {
				$status = $this->grantRole($role, $rolename);
				if ($status != 0) {
					return $status;
				}
			}
		}

		// Handle members changes
		if (is_array($membersold) && is_array($members)) {
			$revoke = array_diff($membersold, $members);
			$grant = array_diff($members, $membersold);

			foreach ($revoke as $member) {
				$status = $this->revokeRole($rolename, $member);
				if ($status != 0) {
					return $status;
				}
			}

			foreach ($grant as $member) {
				$status = $this->grantRole($rolename, $member);
				if ($status != 0) {
					return $status;
				}
			}
		}

		// Handle admin members changes
		if (is_array($adminmembersold) && is_array($adminmembers)) {
			$revoke = array_diff($adminmembersold, $adminmembers);
			$grant = array_diff($adminmembers, $adminmembersold);

			foreach ($revoke as $member) {
				$status = $this->revokeRole($rolename, $member, 1);
				if ($status != 0) {
					return $status;
				}
			}

			foreach ($grant as $member) {
				$status = $this->grantRole($rolename, $member, 1);
				if ($status != 0) {
					return $status;
				}
			}
		}

		return 0;
	}

	/**
	 * Renames a role
	 * @param string $rolename The current role name
	 * @param string $newrolename The new role name
	 * @return 0 success
	 */
	public function renameRole($rolename, $newrolename)
	{
		$this->connection->fieldClean($rolename);
		$this->connection->fieldClean($newrolename);

		$sql = "ALTER ROLE \"{$rolename}\" RENAME TO \"{$newrolename}\"";

		return $this->connection->execute($sql);
	}

	/**
	 * Renames a role and updates its attributes (transaction-wrapped)
	 * @param string $rolename The current role name
	 * @param string $newrolename The new role name
	 * @param string $password The new password (optional)
	 * @param int $superuser 1 if superuser, 0 otherwise
	 * @param int $createdb 1 if can create databases, 0 otherwise
	 * @param int $createrole 1 if can create roles, 0 otherwise
	 * @param int $inherits 1 if inherits, 0 otherwise
	 * @param int $login 1 if can login, 0 otherwise
	 * @param int $connlimit Connection limit (-1 for unlimited)
	 * @param string $expiry Role expiry date (optional)
	 * @param array $memberof Array of roles to add this role to (optional)
	 * @param array $members Array of roles/users to add to this role (optional)
	 * @param array $adminmembers Array of roles/users to add as admin members (optional)
	 * @param array $memberofold Old memberof array (optional)
	 * @param array $membersold Old members array (optional)
	 * @param array $adminmembersold Old adminmembers array (optional)
	 * @return 0 success
	 * @return -1 transaction error
	 * @return -2 set role attributes error
	 * @return -3 rename error
	 */
	public function setRenameRole(
		$rolename,
		$newrolename,
		$password = '',
		$superuser = 0,
		$createdb = 0,
		$createrole = 0,
		$inherits = 1,
		$login = 1,
		$connlimit = -1,
		$expiry = '',
		$memberof = [],
		$members = [],
		$adminmembers = [],
		$memberofold = [],
		$membersold = [],
		$adminmembersold = [],
		$comment = ''
	)
	{
		$status = $this->connection->beginTransaction();
		if ($status != 0) {
			return -1;
		}

		if ($rolename != $newrolename) {
			$status = $this->renameRole($rolename, $newrolename);
			if ($status != 0) {
				$this->connection->rollbackTransaction();
				return -3;
			}
			$rolename = $newrolename;
		}

		$status = $this->setRole(
			$rolename,
			$password,
			$superuser,
			$createdb,
			$createrole,
			$inherits,
			$login,
			$connlimit,
			$expiry,
			$memberof,
			$members,
			$adminmembers,
			$memberofold,
			$membersold,
			$adminmembersold
		);
		if ($status != 0) {
			$this->connection->rollbackTransaction();
			return -2;
		}

		if ($comment != '') {
			$status = $this->connection->setComment(
				'ROLE',
				$rolename,
				null,
				$comment
			);
			if ($status != 0) {
				$this->connection->rollbackTransaction();
				return -4;
			}
		}

		return $this->connection->endTransaction();
	}

	/**
	 * Removes a role
	 * @param string $rolename The role name
	 * @return 0 success
	 */
	public function dropRole($rolename)
	{
		$this->connection->fieldClean($rolename);

		$sql = "DROP ROLE \"{$rolename}\"";

		return $this->connection->execute($sql);
	}

	/**
	 * Changes a role's password
	 * @param string $rolename The role name
	 * @param string $password The new password
	 * @return int 0 success
	 */
	public function changePassword($rolename, $password)
	{
		$this->connection->fieldClean($rolename);
		$this->connection->clean($password);

		$sql = "ALTER ROLE \"{$rolename}\" WITH PASSWORD '{$password}'";

		return $this->connection->execute($sql);
	}

	/**
	 * Returns all role names which the specified role belongs to
	 * @param string $rolename The role name
	 * @return ADORecordSet A recordset
	 */
	public function getMemberOf($rolename)
	{
		$this->connection->clean($rolename);

		$sql = "
			SELECT rolname FROM pg_catalog.pg_roles R, pg_catalog.pg_auth_members M
			WHERE R.oid=M.roleid
				AND member IN (
					SELECT oid FROM pg_catalog.pg_roles
					WHERE rolname='{$rolename}')
			ORDER BY rolname";

		return $this->connection->selectSet($sql);
	}

	/**
	 * Returns all role names that are members of a role
	 * @param string $rolename The role name
	 * @param string $admin (optional) Find only admin members
	 * @return ADORecordSet A recordset
	 */
	public function getMembers($rolename, $admin = 'f')
	{
		$this->connection->clean($rolename);

		$sql = "
			SELECT rolname FROM pg_catalog.pg_roles R, pg_catalog.pg_auth_members M
			WHERE R.oid=M.member AND admin_option='{$admin}'
				AND roleid IN (SELECT oid FROM pg_catalog.pg_roles
					WHERE rolname='{$rolename}')
			ORDER BY rolname";

		return $this->connection->selectSet($sql);
	}

	/**
	 * Returns all groups in the database cluster
	 * @return ADORecordSet A recordset
	 */
	public function getGroups()
	{
		$sql = "SELECT groname FROM pg_group ORDER BY groname";

		return $this->connection->selectSet($sql);
	}

	/**
	 * Determines whether or not a user/role is a super user
	 * @param string $username The username/rolename
	 * @return bool True if is a super user, false otherwise
	 */
	public function isSuperUser($username = '')
	{
		$this->connection->clean($username);

		if (empty($username)) {
			// Try to get from connection parameter
			$val = pg_parameter_status($this->connection->conn->_connectionID, 'is_superuser');
			if ($val !== false) {
				return $val == 'on';
			}
		}

		$sql = "SELECT rolsuper FROM pg_catalog.pg_roles WHERE rolname='{$username}'";

		$rolsuper = $this->connection->selectField($sql, 'rolsuper');
		return $this->connection->phpBool($rolsuper);
	}

}