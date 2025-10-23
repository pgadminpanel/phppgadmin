<?php
/**
 * sync.php
 *
 * Synchronize a language file with the master english.php.
 * - Inserts missing keys in the same position as english.php
 * - Marks inserted keys with a comment that translation is needed
 * - Removes obsolete keys that do not exist in english.php
 * - Makes a timestamped backup of the original target file
 *
 * Usage:
 *   php sync.php german
 *
 * Notes:
 * - The script preserves the structure and non-$lang lines of english.php
 * - It expects $lang[...] = '...'; style assignments. Multiline values
 *   (values spanning several lines) are supported as long as they end with
 *   a terminating semicolon on their final line.
 */

if ($argc < 2) {
    echo "Usage: php sync.php <target-language>\n";
    exit(1);
}

$masterPath = __DIR__ . '/english.php';
$targetPath = __DIR__ . '/' . $argv[1] . '.php';

if (!is_readable($masterPath)) {
    fwrite(STDERR, "Master file not found or not readable: $masterPath\n");
    exit(2);
}
if (!is_readable($targetPath)) {
    fwrite(STDERR, "Target file not found or not readable: $targetPath\n");
    exit(3);
}

// Read a file and return tokens: sequence of ['type'=>'text','text'=>string]
// or ['type'=>'key','key'=>name,'text'=>assignment_text]
function tokenize_by_lang_entries($path)
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $tokens = [];
    $acc = [];
    $i = 0;
    $n = count($lines);

    while ($i < $n) {
        $line = $lines[$i];
        // Detect start of $lang[...]
        if (preg_match('/^\s*\$lang\s*\[\s*("|\')([^\"\']+)("|\')\s*\]\s*=\s*/', $line, $m)) {
            // flush accumulator as text token
            if (!empty($acc)) {
                $tokens[] = ['type' => 'text', 'text' => implode("\n", $acc) . "\n"];
                $acc = [];
            }
            // accumulate assignment block until a line that ends with semicolon (not inside quotes handling but good enough for typical files)
            $assignLines = [$line];
            // if line already contains semicolon after the first occurrence of '=' then it's complete
            if (!preg_match('/;\s*$/', $line)) {
                $j = $i + 1;
                while ($j < $n) {
                    $assignLines[] = $lines[$j];
                    if (preg_match('/;\s*$/', $lines[$j])) {
                        break;
                    }
                    $j++;
                }
                $i = $j; // advance outer pointer to last line of assignment
            }
            $assignText = implode("\n", $assignLines) . "\n";
            // extract key name again (from first line)
            if (preg_match('/^\s*\$lang\s*\[\s*(?:"|\')([^\"\']+)(?:"|\')\s*\]\s*=/', $assignLines[0], $mk)) {
                $key = $mk[1];
            } else {
                $key = null;
            }
            $tokens[] = ['type' => 'key', 'key' => $key, 'text' => $assignText];
        } else {
            $acc[] = $line;
        }
        $i++;
    }
    if (!empty($acc)) {
        $tokens[] = ['type' => 'text', 'text' => implode("\n", $acc) . "\n"];
    }
    return $tokens;
}

// Read target file's keys->assignment mapping
function parse_key_map($path)
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $map = [];
    $i = 0;
    $n = count($lines);
    while ($i < $n) {
        $line = $lines[$i];
        if (preg_match('/^\s*\$lang\s*\[\s*(?:"|\')([^\"\']+)(?:"|\')\s*\]\s*=\s*/', $line, $m)) {
            $assignLines = [$line];
            if (!preg_match('/;\s*$/', $line)) {
                $j = $i + 1;
                while ($j < $n) {
                    $assignLines[] = $lines[$j];
                    if (preg_match('/;\s*$/', $lines[$j]))
                        break;
                    $j++;
                }
                $i = $j;
            }
            $assignText = implode("\n", $assignLines) . "\n";
            $key = $m[1];
            $map[$key] = $assignText;
        }
        $i++;
    }
    return $map;
}

$masterTokens = tokenize_by_lang_entries($masterPath);
$targetMap = parse_key_map($targetPath);

// backup target
$timestamp = date('Ymd_His');
$backupPath = $targetPath . ".bak.$timestamp";
if (!copy($targetPath, $backupPath)) {
    fwrite(STDERR, "Warning: could not create backup at $backupPath\n");
} else {
    echo "Backup created: $backupPath\n";
}

// Build new file content based on master tokens, substituting values from target when available
$added = [];
$kept = [];
$final = '';
foreach ($masterTokens as $token) {
    if ($token['type'] === 'text') {
        $final .= $token['text'];
        continue;
    }
    if ($token['type'] === 'key') {
        $k = $token['key'];
        if ($k !== null && array_key_exists($k, $targetMap)) {
            // use target's assignment text (preserve target formatting)
            $final .= $targetMap[$k];
            $kept[] = $k;
        } else {
            // missing in target: emit a comment marker then the english assignment but change right-hand value to english and add comment for translation
            $final .= "/* TRANSLATION MISSING: please translate this string */\n";
            $final .= $token['text'];
            $added[] = $k;
        }
    }
}

// Determine obsolete keys present in target but not in master
$obsolete = [];
foreach ($targetMap as $k => $v) {
    if (!in_array($k, $kept, true) && !in_array($k, $added, true)) {
        $obsolete[] = $k;
    }
}

// Write final back to targetPath (overwrite)
if (file_put_contents($targetPath, $final) === false) {
    fwrite(STDERR, "Error: could not write synchronized file to $targetPath\n");
    exit(4);
}

// Print summary
echo "Synchronized: $targetPath\n";
if (!empty($added)) {
    echo "Added (need translation):\n";
    foreach ($added as $k)
        echo "  - $k\n";
} else {
    echo "Added: none\n";
}
if (!empty($obsolete)) {
    echo "Removed obsolete entries:\n";
    foreach ($obsolete as $k)
        echo "  - $k\n";
} else {
    echo "Removed: none\n";
}

echo "Done.\n";

exit(0);
