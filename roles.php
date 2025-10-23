<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\RoleActions;

/**
 * Manage roles in a database cluster
 *
 * $Id: roles.php,v 1.13 2008/03/21 15:32:57 xzilla Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');


/**
 * Displays a screen for create a new role
 */
function doCreate($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);

	if (!isset($_POST['formRolename']))
		$_POST['formRolename'] = '';
	if (!isset($_POST['formPassword']))
		$_POST['formPassword'] = '';
	if (!isset($_POST['formConfirm']))
		$_POST['formConfirm'] = '';
	if (!isset($_POST['formConnLimit']))
		$_POST['formConnLimit'] = '';
	if (!isset($_POST['formExpires']))
		$_POST['formExpires'] = '';
	if (!isset($_POST['memberof']))
		$_POST['memberof'] = [];
	if (!isset($_POST['members']))
		$_POST['members'] = [];
	if (!isset($_POST['adminmembers']))
		$_POST['adminmembers'] = [];
	if (!isset($_POST['formComment']))
		$_POST['formComment'] = '';

	$misc->printTrail('role');
	$misc->printTitle($lang['strcreaterole'], 'pg.role.create');
	$misc->printMsg($msg);
	?>
	<form action="roles.php" method="post">
		<table>
			<tr>
				<th class="data left required" style="width: 130px"><?= $lang['strname'] ?></th>
				<td class="data1"><input size="15" maxlength="<?= $pg->_maxNameLen ?>" name="formRolename"
						value="<?= html_esc($_POST['formRolename']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strpassword'] ?></th>
				<td class="data1"><input size="15" type="password" name="formPassword"
						value="<?= html_esc($_POST['formPassword']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strconfirm'] ?></th>
				<td class="data1"><input size="15" type="password" name="formConfirm"
						value="<?= html_esc($_POST['formConfirm']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left"><label for="formSuper"><?= $lang['strsuper'] ?></label></th>
				<td class="data1"><input type="checkbox" id="formSuper" name="formSuper" <?= isset($_POST['formSuper']) ? 'checked="checked"' : '' ?> /></td>
			</tr>
			<tr>
				<th class="data left"><label for="formCreateDB"><?= $lang['strcreatedb'] ?></label></th>
				<td class="data1"><input type="checkbox" id="formCreateDB" name="formCreateDB"
						<?= isset($_POST['formCreateDB']) ? 'checked="checked"' : '' ?> /></td>
			</tr>
			<tr>
				<th class="data left"><label for="formCreateRole"><?= $lang['strcancreaterole'] ?></label></th>
				<td class="data1"><input type="checkbox" id="formCreateRole" name="formCreateRole"
						<?= isset($_POST['formCreateRole']) ? 'checked="checked"' : '' ?> /></td>
			</tr>
			<tr>
				<th class="data left"><label for="formInherits"><?= $lang['strinheritsprivs'] ?></label></th>
				<td class="data1"><input type="checkbox" id="formInherits" name="formInherits"
						<?= isset($_POST['formInherits']) ? 'checked="checked"' : '' ?> /></td>
			</tr>
			<tr>
				<th class="data left"><label for="formCanLogin"><?= $lang['strcanlogin'] ?></label></th>
				<td class="data1"><input type="checkbox" id="formCanLogin" name="formCanLogin"
						<?= isset($_POST['formCanLogin']) ? 'checked="checked"' : '' ?> /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strconnlimit'] ?></th>
				<td class="data1"><input size="4" name="formConnLimit" value="<?= html_esc($_POST['formConnLimit']) ?>" />
				</td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strexpires'] ?></th>
				<td class="data1"><input size="23" name="formExpires" value="<?= html_esc($_POST['formExpires']) ?>"
						data-type="timestamp" /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strcomment'] ?>
				</th>
				<td class="data1"><textarea size="15" name="formComment"><?= html_esc($_POST['formComment']) ?></textarea>
				</td>
			</tr>

			<?php
			$roles = $roleActions->getRoles();
			if ($roles->recordCount() > 0): ?>
				<tr>
					<th class="data left"><?= $lang['strmemberof'] ?></th>
					<td class="data">
						<select name="memberof[]" multiple="multiple" size="<?= min(20, $roles->recordCount()) ?>">
							<?php while (!$roles->EOF):
								$rolename = $roles->fields['rolname']; ?>
								<option value="<?= html_esc($rolename) ?>" <?= in_array($rolename, $_POST['memberof']) ? 'selected="selected"' : '' ?>><?= $misc->formatVal($rolename) ?></option>
								<?php $roles->moveNext(); endwhile; ?>
						</select>
					</td>
				</tr>

				<?php $roles->moveFirst(); ?>
				<tr>
					<th class="data left"><?= $lang['strmembers'] ?></th>
					<td class="data">
						<select name="members[]" multiple="multiple" size="<?= min(20, $roles->recordCount()) ?>">
							<?php while (!$roles->EOF):
								$rolename = $roles->fields['rolname']; ?>
								<option value="<?= html_esc($rolename) ?>" <?= in_array($rolename, $_POST['members']) ? 'selected="selected"' : '' ?>><?= $misc->formatVal($rolename) ?></option>
								<?php $roles->moveNext(); endwhile; ?>
						</select>
					</td>
				</tr>

				<?php $roles->moveFirst(); ?>
				<tr>
					<th class="data left"><?= $lang['stradminmembers'] ?></th>
					<td class="data">
						<select name="adminmembers[]" multiple="multiple" size="<?= min(20, $roles->recordCount()) ?>">
							<?php while (!$roles->EOF):
								$rolename = $roles->fields['rolname']; ?>
								<option value="<?= html_esc($rolename) ?>" <?= in_array($rolename, $_POST['adminmembers']) ? 'selected="selected"' : '' ?>><?= $misc->formatVal($rolename) ?></option>
								<?php $roles->moveNext(); endwhile; ?>
						</select>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<p>
			<input type="hidden" name="action" value="save_create" />
			<?= $misc->form ?>
			<input type="submit" name="create" value="<?= $lang['strcreate'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</p>
	</form>
	<?php
}

/**
 * Actually creates the new role in the database
 */
function doSaveCreate()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$conf = AppContainer::getConf();
	$roleActions = new RoleActions($pg);

	if (!isset($_POST['memberof']))
		$_POST['memberof'] = [];
	if (!isset($_POST['members']))
		$_POST['members'] = [];
	if (!isset($_POST['adminmembers']))
		$_POST['adminmembers'] = [];

	// Check data
	$rolename = trim($_POST['formRolename'] ?? '');
	if ($rolename == '')
		doCreate($lang['strroleneedsname']);
	else if (!$conf['show_system'] && str_starts_with($rolename, 'pg_'))
		doCreate($lang['strroleinvalidname']);
	else if ($_POST['formPassword'] != $_POST['formConfirm'])
		doCreate($lang['strpasswordconfirm']);
	else {
		$status = $roleActions->createRole(
			$rolename,
			$_POST['formPassword'],
			isset($_POST['formSuper']),
			isset($_POST['formCreateDB']),
			isset($_POST['formCreateRole']),
			isset($_POST['formInherits']),
			isset($_POST['formCanLogin']),
			$_POST['formConnLimit'],
			$_POST['formExpires'],
			$_POST['memberof'],
			$_POST['members'],
			$_POST['adminmembers'],
			$_POST['formComment']
		);
		if ($status == 0)
			doDefault($lang['strrolecreated']);
		else
			doCreate($lang['strrolecreatedbad']);
	}
}

/**
 * Function to allow alter a role
 */
function doAlter($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);

	$misc->printTrail('role');
	//$misc->printTabs('server', 'roles');
	$misc->printTitle($lang['stralter'], 'pg.role.alter');
	$misc->printMsg($msg);

	$roledata = $roleActions->getRole($_REQUEST['rolename']);

	if ($roledata->recordCount() == 0) {
		?>
		<p class="empty"><?= $lang['strnodata'] ?></p>
		<?php
		return;
	}

	$server_info = $misc->getServerInfo();
	$roledata->fields['rolsuper'] = $pg->phpBool($roledata->fields['rolsuper']);
	$roledata->fields['rolcreatedb'] = $pg->phpBool($roledata->fields['rolcreatedb']);
	$roledata->fields['rolcreaterole'] = $pg->phpBool($roledata->fields['rolcreaterole']);
	$roledata->fields['rolinherit'] = $pg->phpBool($roledata->fields['rolinherit']);
	$roledata->fields['rolcanlogin'] = $pg->phpBool($roledata->fields['rolcanlogin']);
	if (!isset($_POST['formExpires'])) {

		$_POST['formNewRoleName'] = $roledata->fields['rolname'];
		if ($roledata->fields['rolsuper'])
			$_POST['formSuper'] = '';
		if ($roledata->fields['rolcreatedb'])
			$_POST['formCreateDB'] = '';
		if ($roledata->fields['rolcreaterole'])
			$_POST['formCreateRole'] = '';
		if ($roledata->fields['rolinherit'])
			$_POST['formInherits'] = '';
		if ($roledata->fields['rolcanlogin'])
			$_POST['formCanLogin'] = '';
		$_POST['formConnLimit'] = $roledata->fields['rolconnlimit'] == '-1' ? '' : $roledata->fields['rolconnlimit'];
		$_POST['formExpires'] = $roledata->fields['rolvaliduntil'] == 'infinity' ? '' : $roledata->fields['rolvaliduntil'];
		$_POST['formComment'] = $roledata->fields['rolcomment'];
		$_POST['formPassword'] = '';
	}

	?>
	<form action="roles.php" method="post">
		<table>
			<tr>
				<th class="data left" style="width: 130px"><?= $lang['strname'] ?></th>
				<td class="data1">
					<input name="formNewRoleName" size="15" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_POST['formNewRoleName']) ?>" />
				</td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strpassword'] ?></th>
				<td class="data1"><input type="password" size="15" name="formPassword"
						value="<?= html_esc($_POST['formPassword']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strconfirm'] ?></th>
				<td class="data1"><input type="password" size="15" name="formConfirm" value="" /></td>
			</tr>
			<tr>
				<th class="data left"><label for="formSuper"><?= $lang['strsuper'] ?></label></th>
				<td class="data1"><input type="checkbox" id="formSuper" name="formSuper" <?= isset($_POST['formSuper']) ? 'checked="checked"' : '' ?> /></td>
			</tr>
			<tr>
				<th class="data left"><label for="formCreateDB"><?= $lang['strcreatedb'] ?></label></th>
				<td class="data1"><input type="checkbox" id="formCreateDB" name="formCreateDB"
						<?= isset($_POST['formCreateDB']) ? 'checked="checked"' : '' ?> /></td>
			</tr>
			<tr>
				<th class="data left"><label for="formCreateRole"><?= $lang['strcancreaterole'] ?></label></th>
				<td class="data1"><input type="checkbox" id="formCreateRole" name="formCreateRole"
						<?= isset($_POST['formCreateRole']) ? 'checked="checked"' : '' ?> /></td>
			</tr>
			<tr>
				<th class="data left"><label for="formInherits"><?= $lang['strinheritsprivs'] ?></label></th>
				<td class="data1"><input type="checkbox" id="formInherits" name="formInherits"
						<?= isset($_POST['formInherits']) ? 'checked="checked"' : '' ?> /></td>
			</tr>
			<tr>
				<th class="data left"><label for="formCanLogin"><?= $lang['strcanlogin'] ?></label></th>
				<td class="data1"><input type="checkbox" id="formCanLogin" name="formCanLogin"
						<?= isset($_POST['formCanLogin']) ? 'checked="checked"' : '' ?> /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strconnlimit'] ?></th>
				<td class="data1"><input size="4" name="formConnLimit" value="<?= html_esc($_POST['formConnLimit']) ?>" />
				</td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strexpires'] ?></th>
				<td class="data1"><input size="23" name="formExpires" value="<?= html_esc($_POST['formExpires']) ?>"
						data-type="timestamp" /></td>
			</tr>
			<tr>
				<th class="data left">
					<?= $lang['strcomment'] ?>
				</th>
				<td class="data1"><textarea size="15" name="formComment"><?= html_esc($_POST['formComment']) ?></textarea>
				</td>
			</tr>

			<?php
			if (!isset($_POST['memberof'])) {
				$memberof = $roleActions->getMemberOf($_REQUEST['rolename']);
				if ($memberof->recordCount() > 0) {
					$i = 0;
					while (!$memberof->EOF) {
						$_POST['memberof'][$i++] = $memberof->fields['rolname'];
						$memberof->moveNext();
					}
				} else
					$_POST['memberof'] = [];
				$memberofold = implode(',', $_POST['memberof']);
			}
			if (!isset($_POST['members'])) {
				$members = $roleActions->getMembers($_REQUEST['rolename']);
				if ($members->recordCount() > 0) {
					$i = 0;
					while (!$members->EOF) {
						$_POST['members'][$i++] = $members->fields['rolname'];
						$members->moveNext();
					}
				} else
					$_POST['members'] = [];
				$membersold = implode(',', $_POST['members']);
			}
			if (!isset($_POST['adminmembers'])) {
				$adminmembers = $roleActions->getMembers($_REQUEST['rolename'], 't');
				if ($adminmembers->recordCount() > 0) {
					$i = 0;
					while (!$adminmembers->EOF) {
						$_POST['adminmembers'][$i++] = $adminmembers->fields['rolname'];
						$adminmembers->moveNext();
					}
				} else
					$_POST['adminmembers'] = [];
				$adminmembersold = implode(',', $_POST['adminmembers']);
			}

			$roles = $roleActions->getRoles($_REQUEST['rolename']);
			if ($roles->recordCount() > 0): ?>
				<tr>
					<th class="data left"><?= $lang['strmemberof'] ?></th>
					<td class="data">
						<select name="memberof[]" multiple="multiple" size="<?= min(20, $roles->recordCount()) ?>">
							<?php while (!$roles->EOF):
								$rolename = $roles->fields['rolname']; ?>
								<option value="<?= html_esc($rolename) ?>" <?= in_array($rolename, $_POST['memberof']) ? 'selected="selected"' : '' ?>><?= $misc->formatVal($rolename) ?></option>
								<?php $roles->moveNext(); endwhile; ?>
						</select>
					</td>
				</tr>

				<?php $roles->moveFirst(); ?>
				<tr>
					<th class="data left"><?= $lang['strmembers'] ?></th>
					<td class="data">
						<select name="members[]" multiple="multiple" size="<?= min(20, $roles->recordCount()) ?>">
							<?php while (!$roles->EOF):
								$rolename = $roles->fields['rolname']; ?>
								<option value="<?= html_esc($rolename) ?>" <?= in_array($rolename, $_POST['members']) ? 'selected="selected"' : '' ?>><?= $misc->formatVal($rolename) ?></option>
								<?php $roles->moveNext(); endwhile; ?>
						</select>
					</td>
				</tr>

				<?php $roles->moveFirst(); ?>
				<tr>
					<th class="data left"><?= $lang['stradminmembers'] ?></th>
					<td class="data">
						<select name="adminmembers[]" multiple="multiple" size="<?= min(20, $roles->recordCount()) ?>">
							<?php while (!$roles->EOF):
								$rolename = $roles->fields['rolname']; ?>
								<option value="<?= html_esc($rolename) ?>" <?= in_array($rolename, $_POST['adminmembers']) ? 'selected="selected"' : '' ?>><?= $misc->formatVal($rolename) ?></option>
								<?php $roles->moveNext(); endwhile; ?>
						</select>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<p>
			<input type="hidden" name="action" value="save_alter" />
			<input type="hidden" name="rolename" value="<?= html_esc($_REQUEST['rolename']) ?>" />
			<input type="hidden" name="memberofold" value="<?= $_POST['memberofold'] ?? html_esc($memberofold) ?>" />
			<input type="hidden" name="membersold" value="<?= $_POST['membersold'] ?? html_esc($membersold) ?>" />
			<input type="hidden" name="adminmembersold"
				value="<?= $_POST['adminmembersold'] ?? html_esc($adminmembersold) ?>" />
			<?= $misc->form ?>
			<input type="submit" name="alter" value="<?= $lang['stralter'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</p>
	</form>
	<?php
}

/** 
 * Function to save after editing a role
 */
function doSaveAlter()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$conf = AppContainer::getConf();
	$roleActions = new RoleActions($pg);

	if (!isset($_POST['memberof']))
		$_POST['memberof'] = [];
	if (!isset($_POST['members']))
		$_POST['members'] = [];
	if (!isset($_POST['adminmembers']))
		$_POST['adminmembers'] = [];

	// Check name and password
	$rolename = trim($_POST['formNewRoleName'] ?? '');
	if ($rolename == '')
		doAlter($lang['strroleneedsname']);
	else if (!$conf['show_system'] && str_starts_with($rolename, 'pg_'))
		doAlter($lang['strroleinvalidname']);
	else if ($_POST['formPassword'] != $_POST['formConfirm'])
		doAlter($lang['strpasswordconfirm']);
	else {
		$status = $roleActions->setRenameRole(
			$_POST['rolename'],
			$rolename,
			$_POST['formPassword'],
			isset($_POST['formSuper']),
			isset($_POST['formCreateDB']),
			isset($_POST['formCreateRole']),
			isset($_POST['formInherits']),
			isset($_POST['formCanLogin']),
			$_POST['formConnLimit'],
			$_POST['formExpires'],
			$_POST['memberof'],
			$_POST['members'],
			$_POST['adminmembers'],
			$_POST['memberofold'],
			$_POST['membersold'],
			$_POST['adminmembersold'],
			$_POST['formComment']
		);
		if ($status == 0)
			doDefault($lang['strrolealtered']);
		else
			doAlter($lang['strrolealteredbad']);
	}
}

/**
 * Show confirmation of drop a role and perform actual drop
 */
function doDrop($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);

	if ($confirm) {
		$misc->printTrail('role');
		$misc->printTitle($lang['strdroprole'], 'pg.role.drop');

		?>
		<p><?= sprintf($lang['strconfdroprole'], $misc->formatVal($_REQUEST['rolename'])) ?></p>

		<form action="roles.php" method="post">
			<p>
				<input type="hidden" name="action" value="drop" />
				<input type="hidden" name="rolename" value="<?= html_esc($_REQUEST['rolename']) ?>" />
				<?= $misc->form ?>
				<input type="submit" name="drop" value="<?= $lang['strdrop'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</p>
		</form>
		<?php
	} else {
		$status = $roleActions->dropRole($_REQUEST['rolename']);
		if ($status == 0)
			doDefault($lang['strroledropped']);
		else
			doDefault($lang['strroledroppedbad']);
	}
}

/**
 * Show the properties of a role
 */
function doProperties($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);

	$misc->printTrail('role');
	//$misc->printTabs('server', 'roles');
	$misc->printTitle($lang['strproperties'], 'pg.role');
	$misc->printMsg($msg);

	$roledata = $roleActions->getRole($_REQUEST['rolename']);
	if ($roledata->recordCount() > 0) {
		$roledata->fields['rolsuper'] = $pg->phpBool($roledata->fields['rolsuper']);
		$roledata->fields['rolcreatedb'] = $pg->phpBool($roledata->fields['rolcreatedb']);
		$roledata->fields['rolcreaterole'] = $pg->phpBool($roledata->fields['rolcreaterole']);
		$roledata->fields['rolinherit'] = $pg->phpBool($roledata->fields['rolinherit']);
		$roledata->fields['rolcanlogin'] = $pg->phpBool($roledata->fields['rolcanlogin']);
		?>
		<table>
			<tr>
				<th class="data" style="width: 130px">Description</th>
				<th class="data" style="width: 120px">Value</th>
			</tr>
			<tr>
				<td class="data1"><?= $lang['strname'] ?></td>
				<td class="data1"><?= html_esc($_REQUEST['rolename']) ?></td>
			</tr>
			<tr>
				<td class="data2"><?= $lang['strsuper'] ?></td>
				<td class="data2"><?= ($roledata->fields['rolsuper'] ? $lang['stryes'] : $lang['strno']) ?></td>
			</tr>
			<tr>
				<td class="data1"><?= $lang['strcreatedb'] ?></td>
				<td class="data1"><?= ($roledata->fields['rolcreatedb'] ? $lang['stryes'] : $lang['strno']) ?></td>
			</tr>
			<tr>
				<td class="data2"><?= $lang['strcancreaterole'] ?></td>
				<td class="data2"><?= ($roledata->fields['rolcreaterole'] ? $lang['stryes'] : $lang['strno']) ?></td>
			</tr>
			<tr>
				<td class="data1"><?= $lang['strinheritsprivs'] ?></td>
				<td class="data1"><?= ($roledata->fields['rolinherit'] ? $lang['stryes'] : $lang['strno']) ?></td>
			</tr>
			<tr>
				<td class="data2"><?= $lang['strcanlogin'] ?></td>
				<td class="data2"><?= ($roledata->fields['rolcanlogin'] ? $lang['stryes'] : $lang['strno']) ?></td>
			</tr>
			<tr>
				<td class="data1"><?= $lang['strconnlimit'] ?></td>
				<td class="data1">
					<?= ($roledata->fields['rolconnlimit'] === '-1' ? $lang['strnolimit'] : $misc->formatVal($roledata->fields['rolconnlimit'])) ?>
				</td>
			</tr>
			<tr>
				<td class="data2"><?= $lang['strexpires'] ?></td>
				<td class="data2">
					<?= ($roledata->fields['rolvaliduntil'] == 'infinity' || is_null($roledata->fields['rolvaliduntil']) ? $lang['strnever'] : $misc->formatVal($roledata->fields['rolvaliduntil'])) ?>
				</td>
			</tr>
			<tr>
				<td class="data1"><?= $lang['strsessiondefaults'] ?></td>
				<td class="data1"><?= $misc->formatVal($roledata->fields['rolconfig']) ?></td>
			</tr>
			<tr>
				<td class="data2"><?= $lang['strmemberof'] ?></td>
				<td class="data2">
					<?php
					$memberof = $roleActions->getMemberOf($_REQUEST['rolename']);
					if ($memberof->recordCount() > 0) {
						while (!$memberof->EOF) {
							echo $misc->formatVal($memberof->fields['rolname']), "<br />\n";
							$memberof->moveNext();
						}
					}
					?>
				</td>
			</tr>
			<tr>
				<td class="data1"><?= $lang['strmembers'] ?></td>
				<td class="data1">
					<?php
					$members = $roleActions->getMembers($_REQUEST['rolename']);
					if ($members->recordCount() > 0) {
						while (!$members->EOF) {
							echo $misc->formatVal($members->fields['rolname']), "<br />\n";
							$members->moveNext();
						}
					}
					?>
				</td>
			</tr>
			<tr>
				<td class="data2"><?= $lang['stradminmembers'] ?></td>
				<td class="data2">
					<?php
					$adminmembers = $roleActions->getMembers($_REQUEST['rolename'], 't');
					if ($adminmembers->recordCount() > 0) {
						while (!$adminmembers->EOF) {
							echo $misc->formatVal($adminmembers->fields['rolname']), "<br />\n";
							$adminmembers->moveNext();
						}
					}
					?>
				</td>
			</tr>
		</table>
		<?php
	} else {
		?>
		<p class="empty"><?= $lang['strnodata'] ?></p>
		<?php
	}

	$navlinks = [
		'showall' => [
			'attr' => [
				'href' => [
					'url' => 'roles.php',
					'urlvars' => [
						'server' => $_REQUEST['server']
					]
				]
			],
			'icon' => $misc->icon('Roles'),
			'content' => $lang['strshowallroles']
		],
		'alter' => [
			'attr' => [
				'href' => [
					'url' => 'roles.php',
					'urlvars' => [
						'action' => 'alter',
						'server' => $_REQUEST['server'],
						'rolename' => $_REQUEST['rolename']
					]
				]
			],
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stredit']
		],
		'drop' => [
			'attr' => [
				'href' => [
					'url' => 'roles.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'server' => $_REQUEST['server'],
						'rolename' => $_REQUEST['rolename']
					]
				]
			],
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop']
		]
	];

	$misc->printNavLinks($navlinks, 'roles-properties', get_defined_vars());
}

/**
 * If a role is not a superuser role, then we have an 'account management'
 * page for change his password, etc.  We don't prevent them from
 * messing with the URL to gain access to other role admin stuff, because
 * the PostgreSQL permissions will prevent them changing anything anyway.
 */
function doAccount($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);

	$server_info = $misc->getServerInfo();

	$roledata = $roleActions->getRole($server_info['username']);
	$_REQUEST['rolename'] = $server_info['username'];

	$misc->printTrail('role');
	$misc->printTabs('server', 'account');
	$misc->printMsg($msg);

	if ($roledata->recordCount() > 0) {
		$roledata->fields['rolsuper'] = $pg->phpBool($roledata->fields['rolsuper']);
		$roledata->fields['rolcreatedb'] = $pg->phpBool($roledata->fields['rolcreatedb']);
		$roledata->fields['rolcreaterole'] = $pg->phpBool($roledata->fields['rolcreaterole']);
		$roledata->fields['rolinherit'] = $pg->phpBool($roledata->fields['rolinherit']);
		?>
		<table>
			<tr>
				<th class="data"><?= $lang['strname'] ?></th>
				<th class="data"><?= $lang['strsuper'] ?></th>
				<th class="data"><?= $lang['strcreatedb'] ?></th>
				<th class="data"><?= $lang['strcancreaterole'] ?></th>
				<th class="data"><?= $lang['strinheritsprivs'] ?></th>
				<th class="data"><?= $lang['strconnlimit'] ?></th>
				<th class="data"><?= $lang['strexpires'] ?></th>
				<th class="data"><?= $lang['strsessiondefaults'] ?></th>
			</tr>
			<tr>
				<td class="data1"><?= $misc->formatVal($roledata->fields['rolname']) ?></td>
				<td class="data1"><?= $misc->formatVal($roledata->fields['rolsuper'], 'yesno') ?></td>
				<td class="data1"><?= $misc->formatVal($roledata->fields['rolcreatedb'], 'yesno') ?></td>
				<td class="data1"><?= $misc->formatVal($roledata->fields['rolcreaterole'], 'yesno') ?></td>
				<td class="data1"><?= $misc->formatVal($roledata->fields['rolinherit'], 'yesno') ?></td>
				<td class="data1">
					<?= ($roledata->fields['rolconnlimit'] == '-1' ? $lang['strnolimit'] : $misc->formatVal($roledata->fields['rolconnlimit'])) ?>
				</td>
				<td class="data1">
					<?= ($roledata->fields['rolvaliduntil'] == 'infinity' || is_null($roledata->fields['rolvaliduntil']) ? $lang['strnever'] : $misc->formatVal($roledata->fields['rolvaliduntil'])) ?>
				</td>
				<td class="data1"><?= $misc->formatVal($roledata->fields['rolconfig']) ?></td>
			</tr>
		</table>
		<?php
	} else {
		?>
		<p class="empty"><?= $lang['strnodata'] ?></p>
		<?php
	}

	$misc->printNavLinks([
		'changepassword' => [
			'attr' => [
				'href' => [
					'url' => 'roles.php',
					'urlvars' => [
						'action' => 'confchangepassword',
						'server' => $_REQUEST['server']
					]
				]
			],
			'content' => $lang['strchangepassword']
		]
	], 'roles-account', get_defined_vars());
}

/**
 * Show confirmation of change password and actually change password
 */
function doChangePassword($confirm, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$conf = AppContainer::getConf();
	$roleActions = new RoleActions($pg);

	$server_info = $misc->getServerInfo();

	if ($confirm) {
		$_REQUEST['rolename'] = $server_info['username'];
		$misc->printTrail('role');
		$misc->printTitle($lang['strchangepassword'], 'pg.role.alter');
		$misc->printMsg($msg);

		if (!isset($_POST['password']))
			$_POST['password'] = '';
		if (!isset($_POST['confirm']))
			$_POST['confirm'] = '';

		?>
		<form action="roles.php" method="post">
			<table>
				<tr>
					<th class="data left required"><?= $lang['strpassword'] ?></th>
					<td><input type="password" name="password" size="32" value="<?= html_esc($_POST['password']) ?>" /></td>
				</tr>
				<tr>
					<th class="data left required"><?= $lang['strconfirm'] ?></th>
					<td><input type="password" name="confirm" size="32" value="" /></td>
				</tr>
			</table>
			<p>
				<input type="hidden" name="action" value="changepassword" />
				<?= $misc->form ?>
				<input type="submit" name="ok" value="<?= $lang['strok'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</p>
		</form>
		<?php
	} else {
		// Check that password is minimum length
		if (strlen($_POST['password']) < $conf['min_password_length'])
			doChangePassword(true, $lang['strpasswordshort']);
		// Check that password matches confirmation password
		elseif ($_POST['password'] != $_POST['confirm'])
			doChangePassword(true, $lang['strpasswordconfirm']);
		else {
			$status = $roleActions->changePassword($server_info['username'], $_POST['password']);
			if ($status == 0)
				doAccount($lang['strpasswordchanged']);
			else
				doAccount($lang['strpasswordchangedbad']);
		}
	}
}


/**
 * Show default list of roles in the database
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);

	$renderRoleConnLimit = function ($val) {
		$lang = AppContainer::getLang();
		return $val == '-1' ? $lang['strnolimit'] : html_esc($val);
	};

	$renderRoleExpires = function ($val) {
		$lang = AppContainer::getLang();
		if ($val == '' || $val == 'infinity')
			return $lang['strnever'];
		return html_esc($val);
	};

	$misc->printTrail('server');
	$misc->printTabs('server', 'roles');
	$misc->printMsg($msg);

	$roles = $roleActions->getRoles();

	$columns = [
		'role' => [
			'title' => $lang['strrole'],
			'field' => field('rolname'),
			'url' => "redirect.php?subject=role&amp;action=properties&amp;{$misc->href}&amp;",
			'vars' => ['rolename' => 'rolname'],
			'icon' => $misc->icon('Role'),
		],
		'superuser' => [
			'title' => $lang['strsuper'],
			'field' => field('rolsuper'),
			'type' => 'yesno',
		],
		'createdb' => [
			'title' => $lang['strcreatedb'],
			'field' => field('rolcreatedb'),
			'type' => 'yesno',
		],
		'createrole' => [
			'title' => $lang['strcancreaterole'],
			'field' => field('rolcreaterole'),
			'type' => 'yesno',
		],
		'inherits' => [
			'title' => $lang['strinheritsprivs'],
			'field' => field('rolinherit'),
			'type' => 'yesno',
		],
		'canloging' => [
			'title' => $lang['strcanlogin'],
			'field' => field('rolcanlogin'),
			'type' => 'yesno',
		],
		'connlimit' => [
			'title' => $lang['strconnlimit'],
			'field' => field('rolconnlimit'),
			'type' => 'callback',
			'params' => ['function' => $renderRoleConnLimit]
		],
		'expires' => [
			'title' => $lang['strexpires'],
			'field' => field('rolvaliduntil'),
			'type' => 'callback',
			'params' => ['function' => $renderRoleExpires, 'null' => $lang['strnever']],
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('rolcomment'),
		],
	];

	$footer = [
		'role' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['strroles']}",
			'colspan' => 2,
		],
		'createdb' => [
			'text' => $lang['strtotal'],
			'colspan' => 2,
		],
	];

	$actions = [
		'alter' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter'],
			'attr' => [
				'href' => [
					'url' => 'roles.php',
					'urlvars' => [
						'action' => 'alter',
						'rolename' => field('rolname')
					]
				]
			]
		],
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'roles.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'rolename' => field('rolname')
					]
				]
			]
		],
	];

	$misc->printTable(
		$roles,
		$columns,
		$actions,
		'roles-roles',
		$lang['strnoroles'],
		null,
		$footer
	);

	$navlinks = [
		'create' => [
			'attr' => [
				'href' => [
					'url' => 'roles.php',
					'urlvars' => [
						'action' => 'create',
						'server' => $_REQUEST['server']
					]
				]
			],
			'icon' => $misc->icon('CreateRole'),
			'content' => $lang['strcreaterole']
		]
	];
	$misc->printNavLinks($navlinks, 'roles-roles', get_defined_vars());
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';


$misc->printHeader($lang['strroles']);
$misc->printBody();

switch ($action) {
	case 'create':
		doCreate();
		break;
	case 'save_create':
		if (isset($_POST['create']))
			doSaveCreate();
		else
			doDefault();
		break;
	case 'alter':
		doAlter();
		break;
	case 'save_alter':
		if (isset($_POST['alter']))
			doSaveAlter();
		else
			doDefault();
		break;
	case 'confirm_drop':
		doDrop(true);
		break;
	case 'drop':
		if (isset($_POST['drop']))
			doDrop(false);
		else
			doDefault();
		break;
	case 'properties':
		doProperties();
		break;
	case 'confchangepassword':
		doChangePassword(true);
		break;
	case 'changepassword':
		if (isset($_REQUEST['ok']))
			doChangePassword(false);
		else
			doAccount();
		break;
	case 'account':
		doAccount();
		break;
	default:
		doDefault();
}

$misc->printFooter();
