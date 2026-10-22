<?php
/*
 * PHP2Shell Fixer - a guided helper for CVE-2026-109618 and CVE-2026-109619 ("PHP2Shell").
 *
 * Quick run (no download):
 *     curl -fsSL https://wasdthedebugger.github.io/php2shell/fix | php
 *
 * Usage (from your PHP project folder, or anywhere):
 *     php php2shell.php            guided wizard
 *     php php2shell.php --check    look only, change nothing
 *     php php2shell.php --undo     restore a previous backup
 *     php php2shell.php --help
 *
 * Needs nothing but PHP itself (5.6 or newer). Works on Linux, macOS and Windows.
 * Docs: see the docs/ folder next to this file.
 *
 * Updating PHP is the only complete fix. The settings this tool changes only reduce the risk.
 */

if (PHP_SAPI !== 'cli') {
    // Never do anything when opened through a web server.
    http_response_code(404);
    exit;
}

define('P2S_VERSION', '1.0.0');
define('P2S_DROPIN', '99-php2shell.ini');
// Short link that serves this file (used in the "curl ... | php" instructions).
define('P2S_SHORT_URL', 'https://wasdthedebugger.github.io/php2shell/fix');
// True when run as "curl ... | php": the script itself arrived on standard input.
define('P2S_PIPED', !is_file(__FILE__));
// Where the docs live online. Set to '' to point at the local docs/ folder instead.
define('P2S_DOCS_URL', 'https://wasdthedebugger.github.io/php2shell/');

$P2S_FIXED = array('8.5' => '8.5.12', '8.4' => '8.4.27', '8.3' => '8.3.36', '8.2' => '8.2.35');
$P2S_FIXED_PRE = array('8.6' => '8.6.0RC4');
$P2S_ADVISORY = 'https://www.php.net/archive/2026.php#2026-10-21-5';

$P2S_COLOR = p2s_color_supported();
$P2S_IN = null; // opened on the first question
$P2S_OPTS = p2s_parse_args($argv);

// ------------------------------------------------------------------ small helpers

function arr_get($arr, $key, $default = null)
{
    return (is_array($arr) && array_key_exists($key, $arr)) ? $arr[$key] : $default;
}

function p2s_parse_args($argv)
{
    $o = array('mode' => 'wizard');
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--check') $o['mode'] = 'check';
        elseif ($a === '--undo') $o['mode'] = 'undo';
        elseif ($a === '--help' || $a === '-h') $o['mode'] = 'help';
        elseif ($a === '--no-color') $o['no_color'] = true;
        elseif (strpos($a, '--ini=') === 0) $o['ini'] = substr($a, 6);
        elseif (strpos($a, '--scan-dir=') === 0) $o['scan_dir'] = substr($a, 11);
        elseif (strpos($a, '--web-version=') === 0) $o['web_version'] = substr($a, 14);
        elseif (strpos($a, '--backup-dir=') === 0) $o['backup_dir'] = substr($a, 13);
        else {
            fwrite(STDERR, "Unknown option: $a (try --help)\n");
            exit(2);
        }
    }
    return $o;
}

/** Where answers come from. When piped, standard input was the script, so read the terminal directly. */
function p2s_input()
{
    if (!P2S_PIPED) return STDIN;
    $tty = DIRECTORY_SEPARATOR === '\\' ? @fopen('CON', 'r') : @fopen('/dev/tty', 'r');
    if ($tty) return $tty;
    fwrite(STDERR, "No terminal to ask questions on. Download the script and run it instead:\n"
        . "  curl -fsSLo php2shell.php " . P2S_SHORT_URL . "\n  php php2shell.php\n");
    exit(3);
}

/** How to run this script again with $args, matching how it was started. */
function run_cmd($args = '', $sudo = false)
{
    $php = $sudo ? 'sudo php' : 'php';
    $cmd = P2S_PIPED ? 'curl -fsSL ' . P2S_SHORT_URL . " | $php" . ($args !== '' ? " -- $args" : '')
        : "$php " . basename(__FILE__) . ($args !== '' ? " $args" : '');
    return $cmd;
}

function p2s_color_supported()
{
    if (getenv('NO_COLOR') !== false) return false;
    if (DIRECTORY_SEPARATOR === '\\') {
        return function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT, true);
    }
    if (function_exists('stream_isatty')) return stream_isatty(STDOUT);
    if (function_exists('posix_isatty')) return posix_isatty(STDOUT);
    return true;
}

function c($text, $style)
{
    global $P2S_COLOR, $P2S_OPTS;
    if (!$P2S_COLOR || arr_get($P2S_OPTS, 'no_color')) return $text;
    $codes = array('bold' => '1', 'dim' => '2', 'red' => '31', 'green' => '32', 'yellow' => '33',
        'blue' => '34', 'cyan' => '36', 'title' => '1;36');
    return "\033[" . $codes[$style] . 'm' . $text . "\033[0m";
}

function say($text = '')
{
    echo $text . PHP_EOL;
}

function title($text)
{
    say();
    say(c('== ' . $text . ' ' . str_repeat('=', max(3, 66 - strlen($text))), 'title'));
    say();
}

function step($n, $total, $text)
{
    title("Step $n of $total: $text");
}

function ok($t) { say(c('  [OK]   ', 'green') . $t); }
function warn($t) { say(c('  [!]    ', 'yellow') . $t); }
function bad($t) { say(c('  [X]    ', 'red') . $t); }
function info($t) { say(c('  [i]    ', 'cyan') . $t); }

function ask($question, $default = '')
{
    $hint = $default !== '' ? c(" [$default]", 'dim') : '';
    echo c('  ? ', 'blue') . $question . $hint . ' ';
    global $P2S_IN;
    if ($P2S_IN === null) $P2S_IN = p2s_input();
    $line = fgets($P2S_IN);
    if ($line === false) {
        say();
        say(c('Input ended. Nothing more was changed.', 'yellow'));
        exit(3);
    }
    $line = trim($line);
    return $line === '' ? $default : $line;
}

function yesno($question, $default = true)
{
    while (true) {
        $a = strtolower(ask($question . ($default ? ' (Y/n)' : ' (y/N)'), ''));
        if ($a === '') return $default;
        if ($a === 'y' || $a === 'yes') return true;
        if ($a === 'n' || $a === 'no') return false;
        say('    Please type y or n.');
    }
}

function choose($question, $options, $default = null)
{
    say(c('  ? ', 'blue') . $question);
    $keys = array_keys($options);
    foreach ($keys as $i => $k) {
        say('      ' . c(($i + 1) . ')', 'bold') . ' ' . $options[$k]);
    }
    $def = $default === null ? '' : (string)(array_search($default, $keys) + 1);
    while (true) {
        $a = ask('Your choice', $def);
        if (ctype_digit($a) && (int)$a >= 1 && (int)$a <= count($keys)) return $keys[(int)$a - 1];
        say('    Please type a number from 1 to ' . count($keys) . '.');
    }
}

function pause_enter()
{
    ask(c('Press Enter to continue', 'dim'), '');
}

function doc($page)
{
    if (P2S_DOCS_URL !== '') return rtrim(P2S_DOCS_URL, '/') . '/' . $page;
    return __DIR__ . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $page);
}

function size_to_bytes($v)
{
    $v = trim((string)$v);
    if (!preg_match('/^(-?\d+)\s*([kKmMgG]?)$/', $v, $m)) return null;
    $n = (int)$m[1];
    $u = strtolower($m[2]);
    if ($u === 'k') $n *= 1024;
    if ($u === 'm') $n *= 1048576;
    if ($u === 'g') $n *= 1073741824;
    return $n;
}

function is_off($v)
{
    return $v !== null && in_array(strtolower(trim((string)$v)), array('0', 'off', 'false', 'no', 'none', ''), true);
}

function home_dir()
{
    $h = getenv('HOME');
    if (!$h) $h = getenv('USERPROFILE');
    if (!$h && getenv('HOMEDRIVE')) $h = getenv('HOMEDRIVE') . getenv('HOMEPATH');
    return $h ? $h : sys_get_temp_dir();
}

function backup_root()
{
    global $P2S_OPTS;
    $d = arr_get($P2S_OPTS, 'backup_dir');
    return $d ? $d : home_dir() . DIRECTORY_SEPARATOR . '.php2shell' . DIRECTORY_SEPARATOR . 'backups';
}

function os_name()
{
    if (DIRECTORY_SEPARATOR === '\\') return 'Windows';
    if (PHP_OS === 'Darwin') return 'macOS';
    return PHP_OS === 'Linux' ? 'Linux' : PHP_OS;
}

// ------------------------------------------------------------------ ini file reading / editing

/** Active "key = value" lines outside [PATH=]/[HOST=] sections. Returns list of [line_index, key, value]. */
function ini_entries($text)
{
    $out = array();
    $scoped = false;
    $lines = preg_split('/\r\n|\n/', $text);
    foreach ($lines as $i => $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === ';' || $t[0] === '#') continue;
        if (preg_match('/^\[(.*)\]/', $t, $m)) {
            $scoped = (bool)preg_match('/^(PATH|HOST)=/i', trim($m[1]));
            continue;
        }
        if ($scoped) continue;
        if (preg_match('/^([A-Za-z0-9_.]+)\s*=\s*(.*)$/', $t, $m)) {
            $val = trim($m[2]);
            if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
                $q = $val[0];
                $end = strpos($val, $q, 1);
                $val = $end === false ? substr($val, 1) : substr($val, 1, $end - 1);
            } else {
                $val = trim(preg_replace('/\s*;.*$/', '', $val));
            }
            $out[] = array($i, $m[1], $val);
        }
    }
    return $out;
}

function ini_get_from_text($text, $key)
{
    $val = null;
    foreach (ini_entries($text) as $e) {
        if ($e[1] === $key) $val = $e[2];
    }
    return $val;
}

/** Set $key = $value in php.ini text, keeping everything else. */
function ini_set_in_text($text, $key, $value)
{
    $nl = strpos($text, "\r\n") !== false ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\n/', $text);
    $hits = array();
    foreach (ini_entries($text) as $e) {
        if ($e[1] === $key) $hits[] = $e[0];
    }
    $new = array('; php2shell: temporary PHP2Shell mitigation (update PHP for the real fix)', "$key = $value");
    if ($hits) {
        foreach ($hits as $i) $lines[$i] = ';' . ltrim($lines[$i]) . '  ; disabled by php2shell';
        array_splice($lines, $hits[0] + 1, 0, $new);
    } else {
        // Insert before the first [PATH=]/[HOST=] section (those only apply to some folders), else at the end.
        $at = count($lines);
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*\[(PATH|HOST)=/i', $line)) {
                $at = $i;
                break;
            }
        }
        while ($at > 0 && trim($lines[$at - 1]) === '') $at--;
        array_splice($lines, $at, 0, array_merge(array(''), $new));
    }
    $out = implode($nl, $lines);
    if (substr($out, -strlen($nl)) !== $nl) $out .= $nl;
    return $out;
}

function ini_valid($text)
{
    $tmp = tempnam(sys_get_temp_dir(), 'p2s');
    file_put_contents($tmp, $text);
    $r = @parse_ini_file($tmp, true, INI_SCANNER_RAW);
    @unlink($tmp);
    return $r !== false;
}

function read_file($path)
{
    return is_file($path) ? (string)file_get_contents($path) : null;
}

/** Config files PHP loads, in order: php.ini, then the scan folder alphabetically. */
function config_files($ini, $scan_dir, $overlay = array())
{
    $files = array();
    if ($ini) $files[] = $ini;
    if ($scan_dir && is_dir($scan_dir)) {
        $names = array();
        foreach (scandir($scan_dir) as $n) {
            if (substr($n, -4) === '.ini') $names[$n] = true;
        }
        foreach (array_keys($overlay) as $p) {
            if (dirname($p) === rtrim($scan_dir, '/\\')) $names[basename($p)] = true;
        }
        $names = array_keys($names);
        sort($names, SORT_STRING);
        foreach ($names as $n) $files[] = rtrim($scan_dir, '/\\') . DIRECTORY_SEPARATOR . $n;
    }
    return $files;
}

/** Effective value of $key across the config files. Returns [value, file] (null value = PHP default). */
function effective($key, $ini, $scan_dir, $overlay = array())
{
    $val = null;
    $from = null;
    foreach (config_files($ini, $scan_dir, $overlay) as $f) {
        $text = array_key_exists($f, $overlay) ? $overlay[$f] : read_file($f);
        if ($text === null) continue;
        $v = ini_get_from_text($text, $key);
        if ($v !== null) {
            $val = $v;
            $from = $f;
        }
    }
    return array($val, $from);
}

// ------------------------------------------------------------------ the fixes

function fix_list()
{
    return array(
        'memory-limit' => array(
            'title' => 'Turn off the PHP memory limit (memory_limit = -1)',
            'cve' => 'CVE-2026-109618',
            'key' => 'memory_limit',
            'why' => 'The bug is triggered when PHP runs out of memory at its memory limit. With no limit, that trigger is gone.',
            'impact' => 'One request can now use all server memory. Put a memory limit on the container/service instead.',
        ),
        'post-max-size' => array(
            'title' => 'Lower the maximum request size (post_max_size)',
            'cve' => 'CVE-2026-109618',
            'key' => 'post_max_size',
            'why' => 'Smaller requests give an attacker less room to push PHP into running out of memory.',
            'impact' => 'Form posts and uploads larger than this are rejected.',
        ),
        'upload-max-filesize' => array(
            'title' => 'Keep the upload limit below the request limit (upload_max_filesize)',
            'cve' => 'CVE-2026-109618',
            'key' => 'upload_max_filesize',
            'why' => 'php.net says upload_max_filesize must stay below post_max_size.',
            'impact' => 'Files larger than this cannot be uploaded.',
        ),
        'upload-progress' => array(
            'title' => 'Turn off session upload progress',
            'cve' => 'CVE-2026-109619',
            'key' => 'session.upload_progress.enabled',
            'why' => 'This feature is one confirmed way to reach the bug.',
            'impact' => 'Upload progress bars that rely on $_SESSION stop working.',
        ),
        'fatal-backtraces' => array(
            'title' => 'Turn off fatal error backtraces (PHP 8.5 only)',
            'cve' => 'CVE-2026-109619',
            'key' => 'fatal_error_backtraces',
            'why' => 'On PHP 8.5 this feature is one confirmed way to reach the bug.',
            'impact' => 'Fatal error messages no longer include a backtrace.',
        ),
    );
}

/** Is the current value already good enough for the target? */
function satisfied($id, $current, $target)
{
    if ($id === 'memory-limit') return trim((string)$current) === '-1';
    if ($id === 'upload-progress' || $id === 'fatal-backtraces') return is_off($current);
    $c = size_to_bytes($current === null ? ($id === 'post-max-size' ? '8M' : '2M') : $current);
    $t = size_to_bytes($target);
    return $c !== null && $c > 0 && $c <= $t;
}

function patch_status($version)
{
    global $P2S_FIXED, $P2S_FIXED_PRE;
    if (!preg_match('/^(\d+)\.(\d+)/', $version, $m)) return array('unknown', 'Could not read the version.');
    $branch = $m[1] . '.' . $m[2];
    if (isset($P2S_FIXED[$branch])) {
        $f = $P2S_FIXED[$branch];
        if (version_compare($version, $f, '>=')) return array('patched', "PHP $version includes the fix (fixed in $f).");
        return array('affected', "PHP $version is affected. Update to $f or newer.");
    }
    if (isset($P2S_FIXED_PRE[$branch])) {
        $f = $P2S_FIXED_PRE[$branch];
        if (version_compare($version, $f, '>=')) return array('patched', "Test version $version includes the fix. Don't use test versions in production.");
        return array('affected', "Test version $version is affected. Update to $f or newer.");
    }
    if (version_compare($branch, '8.2', '<')) {
        return array('affected', "PHP $branch gets no official fix. Upgrade to PHP 8.2 or newer, or ask your OS vendor for a backported package.");
    }
    return array('unknown', "PHP $branch is newer than this tool knows about. Check php.net.");
}

// ------------------------------------------------------------------ finding the website's PHP config

function guess_web_configs()
{
    $globs = array(
        '/etc/php/*/fpm/php.ini', '/etc/php/*/apache2/php.ini', '/etc/php/*/cgi/php.ini', '/etc/php.ini',
        '/etc/php*/php.ini', '/usr/local/etc/php/php.ini', '/usr/local/etc/php/*/php.ini', '/opt/homebrew/etc/php/*/php.ini',
        '/Applications/MAMP/bin/php/*/conf/php.ini', 'C:/php/php.ini', 'C:/xampp/php/php.ini', 'C:/wamp64/bin/php/*/php.ini',
        'C:/laragon/bin/php/*/php.ini', 'C:/Program Files/PHP/*/php.ini',
    );
    $found = array();
    foreach ($globs as $g) {
        foreach ((array)glob($g) as $f) {
            if ($f && is_file($f)) $found[realpath($f)] = true;
        }
    }
    return array_keys($found);
}

function scan_dir_for($ini)
{
    // Debian/Ubuntu style: php.ini next to a conf.d folder.
    $d = dirname($ini) . DIRECTORY_SEPARATOR . 'conf.d';
    if (is_dir($d)) return $d;
    if ($ini === '/etc/php.ini' && is_dir('/etc/php.d')) return '/etc/php.d';
    return null;
}

function cli_scan_dir()
{
    $env = getenv('PHP_INI_SCAN_DIR');
    if ($env) {
        $parts = explode(PATH_SEPARATOR, $env);
        foreach (array_reverse($parts) as $p) {
            if ($p !== '' && is_dir($p)) return $p;
        }
    }
    if (defined('PHP_CONFIG_FILE_SCAN_DIR') && PHP_CONFIG_FILE_SCAN_DIR !== '' && is_dir(PHP_CONFIG_FILE_SCAN_DIR)) {
        return PHP_CONFIG_FILE_SCAN_DIR;
    }
    return null;
}

/** Look in the project folder for settings that can undo or interfere with the fixes. */
function scan_project($dir)
{
    $keys = 'memory_limit|post_max_size|upload_max_filesize|session\.upload_progress\.enabled|fatal_error_backtraces';
    $found = array();
    $count = 0;
    $skip = array('vendor', 'node_modules', '.git', 'storage', 'cache');
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            function ($f) use ($skip) {
                return !($f->isDir() && in_array($f->getFilename(), $skip, true));
            }
        )
    );
    foreach ($it as $f) {
        if (++$count > 20000) break;
        $name = $f->getFilename();
        $isPhp = substr($name, -4) === '.php';
        if ($name !== '.htaccess' && $name !== '.user.ini' && !$isPhp) continue;
        if ($f->getSize() > 1048576) continue;
        $lines = @file($f->getPathname());
        if (!$lines) continue;
        foreach ($lines as $n => $line) {
            $hit = false;
            if ($name === '.htaccess' && preg_match('/^\s*php_(admin_)?(value|flag)\s+\S+/i', $line)) $hit = 'PHP setting in .htaccess';
            elseif ($name === '.user.ini' && preg_match("/^\s*($keys)\s*=/", $line)) $hit = '.user.ini setting';
            elseif ($isPhp && preg_match("/ini_set\s*\(\s*['\"]($keys)['\"]/", $line)) $hit = 'ini_set() in code';
            if ($hit) $found[] = array($f->getPathname(), $n + 1, trim($line), $hit);
        }
    }
    return $found;
}

// ------------------------------------------------------------------ backups / undo

function backup_session_dir()
{
    $d = backup_root() . DIRECTORY_SEPARATOR . date('Ymd-His');
    if (!is_dir($d) && !@mkdir($d, 0700, true)) return null;
    return $d;
}

function write_file_safely($path, $text)
{
    $tmp = $path . '.php2shell-tmp';
    if (@file_put_contents($tmp, $text) === false) return false;
    if (is_file($path)) @chmod($tmp, fileperms($path) & 0777);
    if (DIRECTORY_SEPARATOR === '\\' && is_file($path)) @unlink($path);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function list_backups()
{
    $out = array();
    foreach ((array)glob(backup_root() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.json') as $m) {
        if (!$m) continue;
        $data = json_decode((string)file_get_contents($m), true);
        if (is_array($data)) {
            $data['dir'] = dirname($m);
            $out[] = $data;
        }
    }
    usort($out, function ($a, $b) {
        return strcmp($b['created'], $a['created']);
    });
    return $out;
}

function undo_wizard()
{
    title('Undo previous changes');
    $all = list_backups();
    $all = array_values(array_filter($all, function ($b) {
        return empty($b['undone']);
    }));
    if (!$all) {
        info('No backups to restore. (Backups live in ' . backup_root() . ')');
        return 0;
    }
    $opts = array();
    foreach ($all as $i => $b) {
        $files = array();
        foreach ($b['files'] as $f) $files[] = basename($f['path']);
        $opts[$i] = $b['created'] . '  -  ' . implode(', ', $files);
    }
    $opts['cancel'] = 'Cancel';
    $pick = choose('Which change do you want to undo? (newest first)', $opts, 0);
    if ($pick === 'cancel') return 0;
    $b = $all[$pick];
    say();
    $conflict = false;
    foreach ($b['files'] as $f) {
        $now = is_file($f['path']) ? hash_file('sha256', $f['path']) : null;
        $what = $f['existed'] ? 'restore the original' : 'delete the file this tool created';
        if ($now !== null && $now !== $f['new_hash']) {
            warn($f['path'] . ': it was changed again after this tool edited it. Undo will overwrite those later edits.');
            $conflict = true;
        } else {
            info($f['path'] . ": $what");
        }
    }
    if (!yesno($conflict ? 'Undo anyway?' : 'Go ahead?', !$conflict)) {
        info('Nothing changed.');
        return 0;
    }
    foreach ($b['files'] as $f) {
        if ($f['existed']) {
            $orig = file_get_contents($b['dir'] . DIRECTORY_SEPARATOR . $f['backup']);
            if (hash('sha256', $orig) !== $f['old_hash']) {
                bad('The backup of ' . $f['path'] . ' is damaged; skipped.');
                continue;
            }
            if (write_file_safely($f['path'], $orig)) ok('Restored ' . $f['path']);
            else bad('Could not write ' . $f['path'] . ' (permission?). Copy it back by hand from ' . $b['dir']);
        } elseif (is_file($f['path'])) {
            if (@unlink($f['path'])) ok('Removed ' . $f['path']);
            else bad('Could not remove ' . $f['path'] . ' (permission?)');
        }
    }
    $b2 = $b;
    unset($b2['dir']);
    $b2['undone'] = date('c');
    file_put_contents($b['dir'] . DIRECTORY_SEPARATOR . 'manifest.json', json_encode($b2, JSON_PRETTY_PRINT));
    say();
    warn('Restart PHP so the restored settings take effect. Backups are kept in ' . $b['dir']);
    return 0;
}

// ------------------------------------------------------------------ the wizard

function restart_hint($setup)
{
    switch ($setup) {
        case 'fpm': return 'sudo systemctl restart php*-fpm   (or: sudo service php8.x-fpm restart)';
        case 'apache': return 'sudo systemctl restart apache2   (or httpd; on Windows restart the Apache service)';
        case 'iis': return 'iisreset   (in an Administrator command prompt)';
        case 'docker': return 'rebuild/restart the container - and put the settings in your image so they survive';
        default: return 'restart your web server or PHP service';
    }
}

function wizard($check_only)
{
    global $P2S_OPTS, $P2S_ADVISORY;
    $total = $check_only ? 3 : 6;
    $fixes = fix_list();

    say();
    say(c('  PHP2Shell Fixer ' . P2S_VERSION, 'title'));
    say('  Helps with CVE-2026-109618 and CVE-2026-109619 ("PHP2Shell"),');
    say('  two critical PHP bugs that can let an attacker take over a web server.');
    say();
    say('  ' . c('The real fix is updating PHP.', 'bold') . ' This tool checks your version and can');
    say('  apply the temporary settings php.net recommends, with a backup you can undo.');
    if ($check_only) say('  ' . c('Check mode: nothing will be changed.', 'green'));
    say();
    if (!yesno('Ready to start?', true)) return 0;

    // ---------------------------------------------------------- step 1: which PHP
    step(1, $total, 'Which PHP runs your website?');
    info('This script is running on PHP ' . PHP_VERSION . ' (' . os_name() . ').');
    $cli_ini = php_ini_loaded_file();
    info('Its config file: ' . ($cli_ini ? $cli_ini : '(none loaded)'));
    say();
    say('  The PHP you type commands into is not always the same one that serves your');
    say('  website. They can be different versions, or use different config files.');
    say();

    $setup = choose('How does your website run PHP?', array(
        'fpm' => 'PHP-FPM (usually with Nginx, sometimes Apache)',
        'apache' => 'Apache with mod_php',
        'iis' => 'Windows IIS',
        'local' => 'Local dev tool (XAMPP, MAMP, Laragon, Valet, built-in server)',
        'docker' => 'Docker / container',
        'shared' => 'Shared hosting / cPanel / Plesk (I don\'t control the server)',
        'unsure' => 'Not sure',
    ), 'fpm');

    if ($setup === 'shared') return shared_hosting();

    $ini = arr_get($P2S_OPTS, 'ini');
    $scan = arr_get($P2S_OPTS, 'scan_dir');
    $web_version = arr_get($P2S_OPTS, 'web_version');
    if (!$ini) {
        $guesses = guess_web_configs();
        $cli_is_cli_config = $cli_ini && preg_match('#[/\\\\]cli[/\\\\]php\.ini$#', $cli_ini);
        $opts = array();
        foreach ($guesses as $g) {
            if ($g !== $cli_ini || !$cli_is_cli_config) $opts[$g] = $g . ($g === $cli_ini ? '  (the one this script uses)' : '');
        }
        if ($cli_ini && !isset($opts[$cli_ini]) && !$cli_is_cli_config) $opts[$cli_ini] = $cli_ini . '  (the one this script uses)';
        if ($cli_is_cli_config) {
            warn('This script uses the command-line config (' . $cli_ini . '). Your website uses a different file.');
        }
        $opts['type'] = 'Type the path myself';
        $opts['none'] = "I don't know / can't access it";
        say();
        $pick = choose('Which config file does your website use?', $opts, key($opts));
        if ($pick === 'none') {
            say();
            info('No problem. Tip: your hosting panel, or "php-fpm -i" / "php --ini" on the server, shows it.');
            info('You can still check your version and read the guides: ' . doc('index.html'));
            $ini = null;
        } elseif ($pick === 'type') {
            while (true) {
                $ini = ask('Full path to php.ini:');
                if (is_file($ini)) break;
                bad("Can't find that file. Try again.");
            }
        } else {
            $ini = $pick;
        }
    }
    if ($ini && !$scan) {
        $scan = ($ini === $cli_ini) ? cli_scan_dir() : scan_dir_for($ini);
    }
    if ($ini) {
        ok('Website config: ' . $ini);
        if ($scan && is_dir($scan)) ok('Extra config folder: ' . $scan);
    }

    // ---------------------------------------------------------- step 2: version / patch status
    step(2, $total, 'Is your PHP patched?');
    if (!$web_version) {
        $same = yesno('Does your website run the same PHP version as this script (' . PHP_VERSION . ')?', $ini === $cli_ini);
        if ($same) {
            $web_version = PHP_VERSION;
        } else {
            info('Find it in your hosting panel, or run "php-fpm -v" (or similar) on the server.');
            while (true) {
                $web_version = ask('Website PHP version (e.g. 8.3.12), or Enter if unknown:', '');
                if ($web_version === '' || preg_match('/^\d+\.\d+(\.\d+)?/', $web_version)) break;
                bad('That doesn\'t look like a version number.');
            }
        }
    }
    $status = 'unknown';
    if ($web_version === '') {
        warn('Unknown version, so the tool can\'t tell if you are patched. Treating it as not patched.');
    } else {
        list($status, $msg) = patch_status($web_version);
        if ($status === 'patched') ok($msg);
        elseif ($status === 'affected') bad($msg);
        else warn($msg);

        if ($status === 'affected' && os_name() === 'Linux' && is_file('/etc/os-release') && $setup !== 'docker') {
            say();
            info('Linux distributions (Ubuntu, Debian, Red Hat...) often patch PHP without changing');
            info('its version number, so ' . $web_version . ' might already be fixed.');
            $from_os = yesno('Did you install PHP from your OS packages (apt, dnf, yum)?', true);
            if ($from_os) {
                info('Check your vendor: Ubuntu https://ubuntu.com/security/CVE-2026-109618');
                info('                   Debian https://security-tracker.debian.org/tracker/CVE-2026-109618');
                info('                   Red Hat https://access.redhat.com/security/cve/CVE-2026-109618');
                if (yesno('Has your vendor confirmed your installed package is fixed?', false)) {
                    $status = 'patched';
                    ok('OK - treating it as patched (based on your answer).');
                } else {
                    warn('Then assume it is not patched: install your OS updates as soon as possible.');
                }
            }
        }
    }
    say();
    if ($status === 'patched') {
        ok(c('You are patched. The temporary settings are not needed.', 'green'));
        $old = list_backups();
        if ($old && !$check_only) {
            info('This tool changed your settings before. Undo those temporary changes now?');
            if (yesno('Undo?', true)) return undo_wizard();
        }
        say();
        info('Guide: ' . doc('fixes/update-php.html'));
        return 0;
    }
    say('  ' . c('Most important: update PHP.', 'bold') . ' Guide: ' . doc('fixes/update-php.html'));
    say('  Until then, the temporary settings below reduce the risk.');

    if (!$ini) {
        say();
        info('Without access to the config file the tool can\'t apply settings for you.');
        info('Each fix explains how to set it by hand: ' . doc('index.html#fixes'));
        return 0;
    }

    // ---------------------------------------------------------- step 3: what to change
    step(3, $total, 'What needs changing');
    $uploads = yesno('Does your website need to accept file uploads?', true);
    $max_upload = '2M';
    if ($uploads) {
        while (true) {
            $max_upload = strtoupper(ask('Largest single upload it must accept (must be below 32M, e.g. 10M):', '20M'));
            $b = size_to_bytes($max_upload);
            if ($b !== null && $b > 0 && $b < 32 * 1048576) break;
            bad('Please enter a size below 32M, like 10M or 20M.');
        }
    }
    $targets = array(
        'memory-limit' => '-1',
        'post-max-size' => $uploads ? '32M' : '8M',
        'upload-max-filesize' => $max_upload,
        'upload-progress' => '0',
        'fatal-backtraces' => '0',
    );
    $is85 = $web_version !== '' && preg_match('/^8\.5\./', $web_version);
    if (!$is85) unset($targets['fatal-backtraces']);

    $plan = array();
    say();
    foreach ($fixes as $id => $f) {
        if (!isset($targets[$id])) {
            say('  ' . c('-', 'dim') . ' ' . c($f['title'], 'dim') . c('  (not needed for your version)', 'dim'));
            continue;
        }
        list($cur, $from) = effective($f['key'], $ini, $scan);
        $shown = $cur === null ? 'PHP default' : $cur;
        if (satisfied($id, $cur, $targets[$id])) {
            ok($f['title'] . c("  already OK ($shown)", 'dim'));
        } else {
            $plan[$id] = $targets[$id];
            warn($f['title']);
            say('           now: ' . c($shown, 'yellow') . '   ->   change to: ' . c($targets[$id], 'green'));
        }
    }
    if (!$plan) {
        say();
        ok('All recommended settings are already in place. Remember: update PHP for the real fix.');
        return 0;
    }

    if ($check_only) {
        say();
        info('Run without --check to apply these with a backup.');
        info('About each fix: ' . doc('index.html#fixes'));
        return 0;
    }

    say();
    if (yesno('Want to learn more about a fix before deciding?', false)) {
        foreach ($plan as $id => $t) {
            $f = $fixes[$id];
            say();
            say('  ' . c($f['title'], 'bold') . c('  (' . $f['cve'] . ')', 'dim'));
            say('    Why:    ' . $f['why']);
            say('    Impact: ' . $f['impact']);
            say('    Guide:  ' . doc("fixes/$id.html"));
        }
        say();
    }
    foreach (array_keys($plan) as $id) {
        if (!yesno('Apply "' . $fixes[$id]['title'] . '"?', true)) unset($plan[$id]);
    }
    if (!$plan) {
        info('Nothing selected. Nothing changed.');
        return 0;
    }

    // ---------------------------------------------------------- step 4: preview
    step(4, $total, 'Preview');
    $use_dropin = $scan && is_dir($scan);
    $target = $use_dropin ? rtrim($scan, '/\\') . DIRECTORY_SEPARATOR . P2S_DROPIN : $ini;
    $before = read_file($target);
    $after = $before === null
        ? "; Added by php2shell.php - temporary PHP2Shell mitigations.\n; Updating PHP is the real fix. Undo with: " . run_cmd('--undo') . "\n"
        : $before;
    foreach ($plan as $id => $value) $after = ini_set_in_text($after, $fixes[$id]['key'], $value);

    info(($before === null ? 'Will create: ' : 'Will edit:   ') . $target);
    if ($use_dropin) info('(a separate small file, so your main php.ini is not touched)');
    say();
    foreach ($plan as $id => $value) say('      ' . c('+ ' . $fixes[$id]['key'] . ' = ' . $value, 'green'));
    say();

    // Warn if a later file would override our drop-in.
    if ($use_dropin) {
        foreach ($plan as $id => $value) {
            list($v, $from) = effective($fixes[$id]['key'], $ini, $scan, array($target => $after));
            if ($from !== $target) warn("$from also sets {$fixes[$id]['key']} and loads later, so it would win. Edit that file too.");
        }
    }
    if (!ini_valid($after)) {
        bad('The new file would not be valid. Nothing was changed. Please report this.');
        return 1;
    }
    if (!yesno('Make this change? (a backup is taken first)', true)) {
        info('Nothing changed.');
        return 0;
    }

    // ---------------------------------------------------------- step 5: backup + apply
    step(5, $total, 'Backup and apply');
    $dir_ok = is_file($target) ? is_writable($target) : is_writable(dirname($target));
    if (!$dir_ok) {
        bad("No permission to write $target.");
        if (DIRECTORY_SEPARATOR === '\\') info('Run your command prompt as Administrator and try again.');
        else info('Run it again with sudo:   ' . run_cmd('', true));
        info('Or add these lines to the file by hand:');
        foreach ($plan as $id => $value) say('      ' . $fixes[$id]['key'] . ' = ' . $value);
        return 3;
    }
    $bdir = backup_session_dir();
    if (!$bdir) {
        bad('Could not create the backup folder ' . backup_root() . '. Nothing changed.');
        return 1;
    }
    $manifest = array('created' => date('c'), 'tool' => P2S_VERSION, 'php_version' => $web_version,
        'fixes' => $plan, 'files' => array());
    $entry = array('path' => $target, 'existed' => $before !== null, 'backup' => null,
        'old_hash' => $before === null ? null : hash('sha256', $before), 'new_hash' => hash('sha256', $after));
    if ($before !== null) {
        $entry['backup'] = basename($target) . '.bak';
        file_put_contents($bdir . DIRECTORY_SEPARATOR . $entry['backup'], $before);
        ok('Backup saved: ' . $bdir . DIRECTORY_SEPARATOR . $entry['backup']);
    }
    $manifest['files'][] = $entry;
    file_put_contents($bdir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

    if (!write_file_safely($target, $after)) {
        bad("Could not write $target. Nothing changed.");
        return 1;
    }
    ok(($before === null ? 'Created ' : 'Updated ') . $target);

    // ---------------------------------------------------------- step 6: check + next steps
    step(6, $total, 'Check and next steps');
    foreach ($plan as $id => $value) {
        list($v) = effective($fixes[$id]['key'], $ini, $scan);
        if (satisfied($id, $v, $value)) ok($fixes[$id]['key'] . ' = ' . $v);
        else bad($fixes[$id]['key'] . " is $v (expected $value) - another setting overrides it.");
    }
    say();
    warn(c('Restart PHP now, or nothing changes:', 'bold'));
    say('      ' . restart_hint($setup));
    say();

    $cwd = getcwd();
    if (yesno("Scan this project folder ($cwd) for settings that could undo the fixes?", true)) {
        $hits = scan_project($cwd);
        if (!$hits) ok('Nothing found.');
        foreach ($hits as $h) warn($h[0] . ':' . $h[1] . '  ' . $h[2] . c('  (' . $h[3] . ')', 'dim'));
        if ($hits) info('What to do about these: ' . doc('fixes/per-request-settings.html'));
    }

    title('Done');
    ok('Temporary settings applied. ' . c('Your PHP is still not patched.', 'bold'));
    say();
    say('  Next:');
    say('   1. Update PHP .................... ' . doc('fixes/update-php.html'));
    say('   2. Limit memory outside PHP ...... ' . doc('fixes/memory-boundary.html'));
    say('   3. Limit request sizes ........... ' . doc('fixes/request-limits.html'));
    say('   4. After updating PHP, undo this:  ' . run_cmd('--undo'));
    say();
    say(c('  Source: ' . $P2S_ADVISORY, 'dim'));
    return 0;
}

function shared_hosting()
{
    global $P2S_ADVISORY;
    title('Shared hosting');
    say('  On shared hosting your provider controls PHP. Files like .htaccess or .user.ini');
    say('  are not a reliable fix for these bugs (php.net says not to rely on .user.ini).');
    say();
    say('  ' . c('What to do:', 'bold'));
    say('   1. In your hosting panel, switch to the newest PHP version offered (8.2.35, 8.3.36,');
    say('      8.4.27, 8.5.12 or newer).');
    say('   2. Ask your provider to confirm they are patched. You can send them this:');
    say();
    say(c('      "Is the PHP serving my site patched for CVE-2026-109618 and CVE-2026-109619', 'cyan'));
    say(c('       (PHP2Shell)? See ' . $P2S_ADVISORY . '"', 'cyan'));
    say();
    say('  Guide: ' . doc('setups.html#shared'));
    return 0;
}

function help()
{
    say('PHP2Shell Fixer ' . P2S_VERSION . ' - guided help for CVE-2026-109618 / CVE-2026-109619');
    say();
    say('  ' . run_cmd() . '            guided wizard (asks before changing anything)');
    say('  ' . run_cmd('--check') . '    check only, change nothing');
    say('  ' . run_cmd('--undo') . '     restore a backup');
    if (P2S_PIPED) {
        say();
        say('Or download it once:  curl -fsSLo php2shell.php ' . P2S_SHORT_URL);
    }
    say();
    say('Other options:');
    say('  --ini=PATH          the php.ini your website uses');
    say('  --scan-dir=PATH     its extra config folder (conf.d)');
    say('  --web-version=X     your website\'s PHP version');
    say('  --backup-dir=PATH   where backups go (default ~/.php2shell/backups)');
    say('  --no-color');
    say();
    say('Docs: ' . doc('index.html'));
    return 0;
}

// ------------------------------------------------------------------ main

switch ($P2S_OPTS['mode']) {
    case 'help':
        exit(help());
    case 'undo':
        exit(undo_wizard());
    case 'check':
        exit(wizard(true));
    default:
        exit(wizard(false));
}
