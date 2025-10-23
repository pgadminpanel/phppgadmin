<?php
/**
 * Login form fragment
 *
 * Used by login.php (standalone) and intro.php (embedded).
 * Expects: $misc, $lang, $conf, $_REQUEST['server']
 *
 * @package PhpPgAdmin
 */

$server_info = $misc->getServerInfo($_REQUEST['server']);
$md5_server = md5($_REQUEST['server']);
?>

<h2><?php echo sprintf($lang['strlogintitle'], $server_info['desc']); ?></h2>

<?php if (isset($msg)) $misc->printMsg($msg); ?>

<form id="login_form" action="redirect.php" method="post" name="login_form">
    <?php
    if (!empty($_POST)) $vars = $_POST;
    else $vars = $_GET;
    foreach ($vars as $key => $val) {
        if (substr($key, 0, 5) == 'login') continue;
        if (is_array($val)) {
            foreach ($val as $sub_key => $sub_val) {
                echo "<input type=\"hidden\" name=\"", htmlspecialchars($key), '[', htmlspecialchars($sub_key), "]\" value=\"", html_esc($sub_val), "\" />\n";
            }
        } else {
            echo "<input type=\"hidden\" name=\"", htmlspecialchars($key), "\" value=\"", html_esc($val), "\" />\n";
        }
    }
    ?>
    <input type="hidden" name="loginServer" value="<?php echo html_esc($_REQUEST['server']); ?>" />
    <table class="login" cellpadding="5" cellspacing="3">
        <tr>
            <td><?php echo $lang['strusername']; ?></td>
            <td><input type="text" name="loginUsername" value="<?php if (isset($_POST['loginUsername'])) echo html_esc($_POST['loginUsername']); ?>" size="24" /></td>
        </tr>
        <tr>
            <td><?php echo $lang['strpassword']; ?></td>
            <td><input id="loginPassword" type="password" name="loginPassword_<?php echo $md5_server; ?>" size="24" /></td>
        </tr>
    </table>
    <?php if (sizeof($conf['servers']) > 1): ?>
        <p><input type="checkbox" id="loginShared" name="loginShared" <?php echo isset($_POST['loginShared']) ? 'checked="checked"' : '' ?> /> <label for="loginShared"><?php echo $lang['strtrycred'] ?></label></p>
    <?php endif; ?>
    <p><input type="submit" name="loginSubmit" value="<?php echo $lang['strlogin']; ?>" /></p>
</form>

<script type="text/javascript">
    var uname = document.login_form.loginUsername;
    var pword = document.login_form.loginPassword_<?php echo $md5_server; ?>;
    if (uname.value == "") uname.focus();
    else pword.focus();
</script>