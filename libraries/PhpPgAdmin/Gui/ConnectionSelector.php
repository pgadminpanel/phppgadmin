<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContext;
use PhpPgAdmin\Database\Actions\DatabaseActions;
use PhpPgAdmin\Misc;

class ConnectionSelector extends AppContext
{

    public function printConnection($onchange)
    {
        $pg = $this->postgres();
        $lang = $this->lang();
        $servers = $this->misc()->getServers();
        $databases = (new DatabaseActions($pg))->getDatabases();
        ?>
        <table style="width: 100%">
            <tr>
                <td>
                    <div class="flex-row">
                        <label for="connection-server">
                            <?php $this->misc()->printHelp($lang['strserver'], 'pg.server'); ?>:&nbsp;
                        </label>
                        <div class="flex-1">
                            <select id="connection-server" data-use-in-url="1" name="server" <?= $onchange ?>>
                                <?php foreach ($servers as $info):
                                    if (empty($info['username'])) {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?= htmlspecialchars($info['id']) ?>" <?= (isset($_REQUEST['server']) && $info['id'] == $_REQUEST['server']) ? ' selected="selected"' : '' ?>>
                                        <?= htmlspecialchars("{$info['desc']} ({$info['id']})") ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="flex-row justify-content-end">
                        <?php if ($databases->recordCount() > 0): ?>
                            <label for="connection-database">&nbsp;&nbsp;&nbsp;
                                <?php $this->misc()->printHelp($lang['strdatabasename'], 'pg.database'); ?>:&nbsp;
                            </label>
                            <div class="flex-1">
                                <select id="connection-database" data-use-in-url="1" name="database" <?= $onchange ?>>
                                    <?php if (!isset($_REQUEST['database'])): ?>
                                        <option value="">--</option>
                                    <?php endif; ?>

                                    <?php while (!$databases->EOF):
                                        $dbname = $databases->fields['datname'];
                                        ?>
                                        <option value="<?= htmlspecialchars($dbname) ?>" <?= (isset($_REQUEST['database']) && $dbname == $_REQUEST['database']) ? ' selected="selected"' : '' ?>>
                                            <?= htmlspecialchars($dbname) ?>
                                        </option>
                                        <?php $databases->moveNext(); endwhile; ?>
                                </select>
                            </div>
                        <?php else:
                            $server_info = $this->misc()->getServerInfo();
                            ?>
                            <input type="hidden" name="database" value="<?= htmlspecialchars($server_info['defaultdb']) ?>" />
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>
        <?php
    }
}
