<?php
/**
 * sys-config.php — Docker Compose override web configurator.
 * Allows viewing and editing the working copy of docker-compose.override.yml
 * through a browser-based table form.  Apply via update_restart.sh/.cmd.
 */

require_once('lib/common.php');
require_once('lib/db_config.php');
require_once('lib/db_tools.php');
require_once('lib/auth_check.php');

if (!str_in($user_rights, 'admin')) {
    http_response_code(403);
    die('<h3>Access denied. Admin rights required.</h3>');
}

// ─── Paths ────────────────────────────────────────────────────────────────────
const SC_DIR      = '/app/var/data/sys-config';
const SC_OVERRIDE = SC_DIR . '/docker-compose.override.yml';
const SC_BACKUP   = SC_DIR . '/backups';
const SC_SOURCE   = '/app/config/docker-compose.override.yml'; // read-only mount

// ─── ComposeYaml — minimal parser/writer for docker-compose YAML ─────────────
class ComposeYaml {
    /** Parse docker-compose YAML text into a PHP array. */
    public static function parse(string $text): array {
        $lines  = explode("\n", str_replace("\r\n", "\n", $text));
        $result = [];
        $stack  = [['indent' => -1, 'node' => &$result, 'key' => null]];

        foreach ($lines as $raw) {
            $stripped = rtrim($raw);
            if ($stripped === '' || ltrim($stripped)[0] === '#') {
                // preserve comment lines in _raw bucket at top level only
                continue;
            }
            $indent = strlen($stripped) - strlen(ltrim($stripped, ' '));
            $content = ltrim($stripped, ' ');

            // Pop stack to current indent level
            while (count($stack) > 1 && $stack[count($stack) - 1]['indent'] >= $indent) {
                array_pop($stack);
            }

            $top = &$stack[count($stack) - 1];

            if (str_starts_with($content, '- ')) {
                // Sequence item — detect dict-form: "- key: value"
                $value = substr($content, 2);
                $colon_pos = strpos($value, ':');
                $pot_key   = $colon_pos > 0 ? substr($value, 0, $colon_pos) : '';
                if ($colon_pos > 0 && $pot_key !== '' && !str_contains($pot_key, ' ') && !str_contains($pot_key, '/')) {
                    // Dict-form sequence item: create a new mapping node
                    $new_idx = count($top['node']);
                    $top['node'][$new_idx] = [];
                    $ref = &$top['node'][$new_idx];
                    $dict_k = $pot_key;
                    $dict_v = trim(substr($value, $colon_pos + 1));
                    if ($dict_v !== '') $ref[$dict_k] = self::unquote($dict_v);
                    $stack[] = ['indent' => $indent, 'node' => &$ref, 'key' => null];
                    unset($ref);
                } else {
                    $top['node'][] = self::unquote($value);
                }
            } elseif (str_contains($content, ':')) {
                $colon = strpos($content, ':');
                $key   = substr($content, 0, $colon);
                $raw_value = substr($content, $colon + 1);
                $value = trim($raw_value);

                // Skip yaml anchors/aliases for simplicity
                if (str_starts_with($key, '*') || str_starts_with($key, '&')) {
                    continue;
                }

                if ($value === '' || $value === '{}' || $value === '[]') {
                    // nested mapping or explicit empty
                    $top['node'][$key] = ($value === '[]') ? [] : [];
                    $new_node = &$top['node'][$key];
                    $stack[] = ['indent' => $indent, 'node' => &$new_node, 'key' => $key];
                } else {
                    $top['node'][$key] = self::unquote($value);
                }
            }
        }

        return $result;
    }

    private static function unquote(string $v): string {
        $v = trim($v);
        if (strlen($v) >= 2 && (
            ($v[0] === '"' && $v[-1] === '"') ||
            ($v[0] === "'" && $v[-1] === "'")
        )) {
            return substr($v, 1, -1);
        }
        return $v;
    }

    /** Serialize a scalar (string/bool/int/null) to YAML value string. */
    private static function scalarToYaml(mixed $value): string {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_null($value)) return 'null';
        return self::quoteValue((string)$value);
    }

    /** Generate docker-compose YAML text from a PHP array. */
    public static function dump(array $data, int $depth = 0): string {
        $indent = str_repeat('  ', $depth);
        $out    = '';
        foreach ($data as $key => $value) {
            if (is_int($key)) {
                // sequence item
                if (is_array($value)) {
                    // Dict-form sequence item: emit each key inline after the dash,
                    // first key on the dash line, rest indented
                    $sub_keys = array_keys($value);
                    $first    = true;
                    foreach ($sub_keys as $sk) {
                        $sv = $value[$sk];
                        $sv_str = is_array($sv) ? "\n" . self::dump($sv, $depth + 2) : (' ' . self::scalarToYaml($sv) . "\n");
                        if ($first) {
                            $out  .= $indent . '- ' . $sk . ':' . $sv_str;
                            $first = false;
                        } else {
                            $out .= $indent . '  ' . $sk . ':' . $sv_str;
                        }
                    }
                } else {
                    $out .= $indent . '- ' . self::scalarToYaml($value) . "\n";
                }
            } else {
                if (is_array($value) && !empty($value)) {
                    // Check if it's a pure sequence (all int keys)
                    if (array_keys($value) === range(0, count($value) - 1)) {
                        $out .= $indent . $key . ":\n";
                        foreach ($value as $item) {
                            if (is_array($item)) {
                                $sub_keys = array_keys($item); $first = true;
                                foreach ($sub_keys as $sk) {
                                    $sv = $item[$sk];
                                    $sv_str = is_array($sv) ? "\n" . self::dump($sv, $depth + 2) : (' ' . self::scalarToYaml($sv) . "\n");
                                    if ($first) {
                                        $out  .= $indent . '  - ' . $sk . ':' . $sv_str;
                                        $first = false;
                                    } else {
                                        $out .= $indent . '    ' . $sk . ':' . $sv_str;
                                    }
                                }
                            } else {
                                $out .= $indent . '  - ' . self::scalarToYaml($item) . "\n";
                            }
                        }
                    } else {
                        $out .= $indent . $key . ":\n";
                        $out .= self::dump($value, $depth + 1);
                    }
                } elseif (is_array($value) && empty($value)) {
                    $out .= $indent . $key . ": {}\n";
                } else {
                    $out .= $indent . $key . ': ' . self::scalarToYaml($value) . "\n";
                }
            }
        }
        return $out;
    }

    private static function quoteValue(string $v): string {
        if ($v === '') return '""';
        // Quote if contains special chars or starts with special YAML char
        $special = [':', '#', '{', '}', '[', ']', ',', '&', '*', '?', '|', '-', '<', '>', '=', '!', '%', '@', '`'];
        $needs_quote = false;
        foreach ($special as $ch) {
            if (str_contains($v, $ch)) {
                $needs_quote = true;
                break;
            }
        }
        if (str_starts_with($v, ' ') || str_ends_with($v, ' ')) $needs_quote = true;
        if (in_array(strtolower($v), ['true', 'false', 'null', 'yes', 'no', 'on', 'off'])) return $v;
        return $needs_quote ? '"' . addcslashes($v, '"\\') . '"' : $v;
    }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function sc_working_copy_exists(): bool {
    return file_exists(SC_OVERRIDE);
}

function sc_read_working_copy(): string {
    if (!sc_working_copy_exists()) return '';
    return (string) file_get_contents(SC_OVERRIDE);
}

function sc_write_working_copy(string $content): bool {
    if (!is_dir(SC_DIR)) mkdir(SC_DIR, 0750, true);
    $tmp = SC_OVERRIDE . '.tmp';
    if (file_put_contents($tmp, $content) === false) return false;
    return rename($tmp, SC_OVERRIDE);
}

function sc_backup(): ?string {
    if (!sc_working_copy_exists()) return null;
    if (!is_dir(SC_BACKUP)) mkdir(SC_BACKUP, 0750, true);
    $stamp  = date('Y-m-d_H-i-s');
    $target = SC_BACKUP . '/docker-compose.override.' . $stamp . '.yml';
    copy(SC_OVERRIDE, $target);
    return $target;
}

function sc_source_info(): array {
    $mounted = file_exists(SC_SOURCE);
    $mtime   = $mounted ? date('Y-m-d H:i:s', filemtime(SC_SOURCE)) : null;
    return ['mounted' => $mounted, 'mtime' => $mtime, 'path' => SC_SOURCE];
}

function sc_working_info(): array {
    $exists = sc_working_copy_exists();
    $mtime  = $exists ? date('Y-m-d H:i:s', filemtime(SC_OVERRIDE)) : null;
    return ['exists' => $exists, 'mtime' => $mtime, 'path' => SC_OVERRIDE];
}

function sc_list_backups(): array {
    if (!is_dir(SC_BACKUP)) return [];
    $files = glob(SC_BACKUP . '/*.yml');
    if (!is_array($files)) return [];
    rsort($files); // newest first
    return $files;
}

/** Merge POST-submitted services data back into the parsed compose array. */
function sc_apply_form(array $compose, array $form_services): array {
    foreach ($form_services as $svc => $svc_data) {
        if (!isset($compose['services'][$svc])) {
            $compose['services'][$svc] = [];
        }

        // ── ENV vars ──────────────────────────────────────────────────────────
        $new_env = [];
        $keys    = $svc_data['keys'] ?? [];
        $values  = $svc_data['values'] ?? [];
        $deletes = $svc_data['delete'] ?? [];
        foreach ($keys as $idx => $k) {
            $k = trim($k);
            if ($k === '' || isset($deletes[$idx])) continue;
            $new_env[$k] = trim($values[$idx] ?? '');
        }
        if (!empty($new_env)) {
            $compose['services'][$svc]['environment'] = $new_env;
        } elseif (isset($compose['services'][$svc]['environment'])) {
            // Remove empty environment block
            unset($compose['services'][$svc]['environment']);
        }

        // ── env_file list ─────────────────────────────────────────────────────
        $ef_lines  = $svc_data['env_file'] ?? '';
        $ef_parsed = array_filter(array_map('trim', explode("\n", $ef_lines)));
        if (!empty($ef_parsed)) {
            $ef_items = [];
            foreach ($ef_parsed as $ef_line) {
                // Support "path: ...\nrequired: ..." pairs separated by |
                if (str_starts_with($ef_line, '{')) {
                    $ef_items[] = json_decode($ef_line, true) ?? ['path' => $ef_line];
                } else {
                    $ef_items[] = ['path' => $ef_line, 'required' => false];
                }
            }
            $compose['services'][$svc]['env_file'] = $ef_items;
        } elseif (isset($compose['services'][$svc]['env_file'])) {
            unset($compose['services'][$svc]['env_file']);
        }

        // ── Service toggle (disable via profiles) ─────────────────────────────
        $disabled = !empty($svc_data['disabled']);
        if ($disabled) {
            $compose['services'][$svc]['profiles'] = ['disabled'];
        } else {
            // Remove profiles key only if it was set to 'disabled' by us
            $profiles = $compose['services'][$svc]['profiles'] ?? [];
            if (is_array($profiles) && $profiles === ['disabled']) {
                unset($compose['services'][$svc]['profiles']);
            }
        }

        // Remove service entry entirely if it became empty
        if (empty($compose['services'][$svc])) {
            unset($compose['services'][$svc]);
        }
    }
    return $compose;
}

// ─── POST handlers ────────────────────────────────────────────────────────────
$message     = null;
$message_cls = 'ok';
$action      = trim((string)($_POST['action'] ?? ''));

if ($action === 'reset_from_source') {
    $src = sc_source_info();
    if ($src['mounted']) {
        $content = (string)file_get_contents(SC_SOURCE);
        if (sc_write_working_copy($content)) {
            $message = 'Working copy reset from mounted source.';
        } else {
            $message = 'Failed to write working copy.';
            $message_cls = 'err';
        }
    } else {
        $message = 'Source not mounted — cannot reset.';
        $message_cls = 'warn';
    }
}

if ($action === 'save_raw') {
    $raw  = str_replace("\r\n", "\n", (string)($_POST['raw_yaml'] ?? ''));
    $test = ComposeYaml::parse($raw);
    if (empty($test)) {
        $message = 'YAML appears empty or invalid — not saved.';
        $message_cls = 'warn';
    } else {
        sc_backup();
        if (sc_write_working_copy($raw)) {
            $message = 'Working copy saved (raw).';
        } else {
            $message = 'Write failed.';
            $message_cls = 'err';
        }
    }
}

if ($action === 'save_structured') {
    $form_services = $_POST['svc'] ?? [];
    $yaml_text     = sc_read_working_copy();
    if ($yaml_text === '' && file_exists(SC_SOURCE)) {
        $yaml_text = (string)file_get_contents(SC_SOURCE);
    }
    $compose = ComposeYaml::parse($yaml_text);
    if (!isset($compose['services'])) $compose['services'] = [];
    $compose = sc_apply_form($compose, $form_services);

    // Rebuild header comment if not present
    $new_yaml = "# docker-compose.override.yml — managed by sys-config.php\n"
              . "# Edit here or via web UI, then run scripts/update_restart.sh to apply.\n\n"
              . ComposeYaml::dump($compose);
    sc_backup();
    if (sc_write_working_copy($new_yaml)) {
        $message = 'Working copy saved.';
    } else {
        $message = 'Write failed.';
        $message_cls = 'err';
    }
}

if ($action === 'restore_backup') {
    $backup_file = realpath(trim((string)($_POST['backup_file'] ?? '')));
    if ($backup_file && str_starts_with($backup_file, realpath(SC_BACKUP)) && file_exists($backup_file)) {
        $content = (string)file_get_contents($backup_file);
        sc_backup();
        if (sc_write_working_copy($content)) {
            $message = 'Restored from backup: ' . basename($backup_file);
        } else {
            $message = 'Write failed.';
            $message_cls = 'err';
        }
    } else {
        $message = 'Invalid backup file.';
        $message_cls = 'err';
    }
}

// ─── Read and parse current working copy ─────────────────────────────────────
$yaml_text = sc_read_working_copy();
if ($yaml_text === '' && file_exists(SC_SOURCE)) {
    $yaml_text = (string)file_get_contents(SC_SOURCE);
}
$compose     = ComposeYaml::parse($yaml_text);
$services    = is_array($compose['services'] ?? null) ? $compose['services'] : [];
$src_info    = sc_source_info();
$work_info   = sc_working_info();
$backups     = sc_list_backups();

// Known optional services (can be toggled)
$optional_services = ['phpmyadmin', 'gpg-agent'];

// Env vars are only really shown/edited for these services  
$configurable_services = ['web', 'bots-hive', 'datafeed', 'mariadb', 'phpmyadmin', 'gpg-agent'];
// Add any extra services from the current working copy
foreach (array_keys($services) as $sn) {
    if (!in_array($sn, $configurable_services, true)) $configurable_services[] = $sn;
}

function he(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function sc_render_env_table(string $svc, array $svc_data): void {
    $env = $svc_data['environment'] ?? [];
    // Normalize: might be list-form (0=>'KEY=val') or dict-form ('KEY'=>'val')
    $parsed_env = [];
    if (is_array($env)) {
        foreach ($env as $k => $v) {
            if (is_int($k) && str_contains((string)$v, '=')) {
                [$ek, $ev] = explode('=', (string)$v, 2);
                $parsed_env[trim($ek)] = trim($ev);
            } else {
                $parsed_env[(string)$k] = (string)$v;
            }
        }
    }
    $idx = 0;
    echo "<table class='env-table'>\n<thead><tr><th>ENV Key</th><th>Value</th><th>Del</th></tr></thead>\n<tbody>\n";
    foreach ($parsed_env as $k => $v) {
        echo '<tr>';
        echo '<td><input name="svc[' . he($svc) . '][keys][' . $idx . ']" value="' . he($k) . '" class="env-key"></td>';
        echo '<td><input name="svc[' . he($svc) . '][values][' . $idx . ']" value="' . he($v) . '" class="env-val"></td>';
        echo '<td><input type="checkbox" name="svc[' . he($svc) . '][delete][' . $idx . ']" title="delete"></td>';
        echo "</tr>\n";
        $idx++;
    }
    // Extra blank rows for adding new vars
    for ($i = 0; $i < 3; $i++) {
        echo '<tr class="new-row">';
        echo '<td><input name="svc[' . he($svc) . '][keys][' . ($idx) . ']" value="" class="env-key" placeholder="KEY"></td>';
        echo '<td><input name="svc[' . he($svc) . '][values][' . ($idx) . ']" value="" class="env-val" placeholder="value"></td>';
        echo '<td></td>';
        echo "</tr>\n";
        $idx++;
    }
    echo "</tbody></table>\n";
}

function sc_render_env_file_block(string $svc, array $svc_data): void {
    $ef = $svc_data['env_file'] ?? [];
    $lines = [];
    if (is_array($ef)) {
        foreach ($ef as $item) {
            if (is_array($item)) {
                $path = trim($item['path'] ?? '');
                if ($path === '') continue;
                // Properly convert 'false'/'true' strings to bool
                $req_raw = $item['required'] ?? false;
                $req = is_bool($req_raw) ? $req_raw
                    : in_array(strtolower((string)$req_raw), ['1', 'true', 'yes', 'on'], true);
                $lines[] = $path . ($req ? '' : '  # required: false');
            } else {
                // Garbled parse fallback: skip 'required:' or plain 'false' lines
                $clean = trim((string)$item);
                if ($clean === '' || $clean === 'false' || $clean === 'true'
                    || str_starts_with($clean, 'required:')) continue;
                // Strip leftover "path:" prefix if parser ate the key
                if (str_starts_with($clean, 'path:')) {
                    $clean = trim(substr($clean, 5));
                }
                if ($clean !== '') $lines[] = $clean;
            }
        }
    }
    $val = implode("\n", $lines);
    echo '<label style="margin-top:10px;display:block;font-size:12px;color:#aaa;">env_file paths (one per line, commented lines ignored):</label>';
    echo '<textarea name="svc[' . he($svc) . '][env_file]" class="env-file-ta" rows="4">' . he($val) . "</textarea>\n";
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Platform Config — docker-compose.override</title>
    <link rel="stylesheet" href="dark-theme.css">
    <link rel="stylesheet" href="apply-theme.css">
    <link rel="stylesheet" href="colors.css">
    <style>
    body { margin: 0; padding: 20px; font-family: Arial, sans-serif; }
    h1 { margin-bottom: 4px; font-size: 22px; }
    .muted { color: #aaa; font-size: 12px; }
    .ok   { color: #84e184; }
    .warn { color: #ffcb6b; }
    .err  { color: #ff7b7b; }
    .card { border: 1px solid #4a4a4a; border-radius: 8px; background: rgba(20,20,20,0.92); padding: 16px; margin-bottom: 14px; }
    .tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
    .tab-btn { padding: 6px 12px; border: 1px solid #555; border-radius: 5px; background: #1e1e1e; color: #ddd; cursor: pointer; font-size: 13px; }
    .tab-btn.active { background: #2e4a7a; border-color: #5a88ca; color: #fff; }
    .tab-pane { display: none; }
    .tab-pane.active { display: block; }
    .env-table { border-collapse: collapse; width: 100%; margin-top: 8px; font-size: 13px; }
    .env-table th, .env-table td { padding: 4px 6px; border: 1px solid #444; }
    .env-table th { background: #2a2a2a; text-align: left; }
    .env-key  { width: 200px; background: #111; color: #f0f0f0; border: 1px solid #555; padding: 3px 5px; border-radius: 3px; }
    .env-val  { width: 340px; background: #111; color: #f0f0f0; border: 1px solid #555; padding: 3px 5px; border-radius: 3px; }
    .env-file-ta { width: 100%; box-sizing: border-box; background: #111; color: #ccc; border: 1px solid #555; padding: 6px; font-family: monospace; font-size: 12px; border-radius: 4px; }
    .svc-header { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
    .svc-title { font-size: 16px; font-weight: bold; }
    .disabled-badge { font-size: 11px; color: #ff9a9a; background: rgba(200,0,0,0.2); padding: 2px 6px; border-radius: 3px; border: 1px solid #a04040; }
    .toggle-label { font-size: 12px; color: #bbb; cursor: pointer; }
    textarea.raw-ta { width: 100%; box-sizing: border-box; height: 480px; font-family: monospace; font-size: 12px; background: #0e0e0e; color: #ccc; border: 1px solid #555; padding: 8px; border-radius: 4px; }
    button.primary { padding: 9px 16px; border: 1px solid #4a8a4a; border-radius: 6px; background: #1a3a1a; color: #84e184; cursor: pointer; font-size: 13px; }
    button.primary:hover { background: #264a26; }
    button.secondary { padding: 7px 12px; border: 1px solid #666; border-radius: 6px; background: #1f1f1f; color: #ccc; cursor: pointer; font-size: 12px; }
    .mono-pre { font-family: Consolas, monospace; font-size: 12px; white-space: pre-wrap; background: #0d0d0d; border: 1px solid #444; padding: 10px; border-radius: 4px; margin-top: 8px; }
    .status-row { display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px; margin-bottom: 8px; }
    .status-item { display: flex; gap: 6px; align-items: center; }
    select.backup-sel { background: #111; color: #ddd; border: 1px solid #555; padding: 5px; border-radius: 4px; font-size: 12px; }
    .msg-banner { padding: 10px 14px; border-radius: 5px; margin-bottom: 12px; font-size: 13px; }
    .msg-ok   { background: rgba(0,100,0,0.25); border: 1px solid #2d6b2d; color: #84e184; }
    .msg-warn { background: rgba(120,90,0,0.25); border: 1px solid #7a6a00; color: #ffcb6b; }
    .msg-err  { background: rgba(140,0,0,0.25); border: 1px solid #7a2020; color: #ff7b7b; }
    .tp-nav {
        display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
        padding: 8px 12px; margin-bottom: 14px;
        background: rgba(20,20,30,0.85); border: 1px solid #3a3a4a;
        border-radius: 8px; font-size: 13px;
    }
    .tp-nav a {
        padding: 5px 11px; border: 1px solid #4a4a66; border-radius: 5px;
        background: #1a1a2a; color: #b0b8e0; text-decoration: none; white-space: nowrap;
    }
    .tp-nav a:hover  { background: #26263a; border-color: #7070aa; color: #dde; }
    .tp-nav a.active { background: #2a2a50; border-color: #6868c0; color: #d0d0ff; font-weight: bold; }
    .tp-nav .sep { color: #444; user-select: none; }
    </style>
</head>
<body>
    <nav class="tp-nav">
        <a href="/index.php">Home</a>
        <span class="sep">|</span>
        <a href="/basic-admin.php">Admin</a>
        <a href="/sys-config.php" class="active">Platform Config</a>
    </nav>
    <h1>Platform Config</h1>
    <p class="muted">Web editor for <code>docker-compose.override.yml</code>.
    Changes are written to the <em>working copy</em> inside the container.
    Run <code>scripts/update_restart.sh</code> on the host to apply.</p>

<?php if ($message): ?>
    <div class="msg-banner msg-<?php echo he($message_cls); ?>"><?php echo he($message); ?></div>
<?php endif; ?>

    <!-- Status bar -->
    <div class="card">
        <div class="status-row">
            <div class="status-item">
                <span class="muted">Source mount:</span>
                <?php if ($src_info['mounted']): ?>
                    <span class="ok">✓ mounted</span>
                    <span class="muted">(<?php echo he($src_info['mtime']); ?>)</span>
                <?php else: ?>
                    <span class="warn">not mounted</span>
                <?php endif; ?>
            </div>
            <div class="status-item">
                <span class="muted">Working copy:</span>
                <?php if ($work_info['exists']): ?>
                    <span class="ok">✓ present</span>
                    <span class="muted">(<?php echo he($work_info['mtime']); ?>)</span>
                <?php else: ?>
                    <span class="warn">not initialized</span>
                <?php endif; ?>
            </div>
            <div class="status-item">
                <span class="muted">Services in working copy:</span>
                <span><?php echo count($services); ?></span>
            </div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
            <form method="post" style="display:inline">
                <input type="hidden" name="action" value="reset_from_source">
                <button type="submit" class="secondary"
                    onclick="return confirm('Overwrite working copy with source mount?')"
                    <?php echo $src_info['mounted'] ? '' : 'disabled'; ?>>
                    Reset from source mount
                </button>
            </form>
            <?php if (!empty($backups)): ?>
            <form method="post" style="display:inline;display:flex;gap:6px;align-items:center;">
                <input type="hidden" name="action" value="restore_backup">
                <select name="backup_file" class="backup-sel">
                    <?php foreach (array_slice($backups, 0, 12) as $bfile): ?>
                        <option value="<?php echo he($bfile); ?>"><?php echo he(basename($bfile)); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="secondary"
                    onclick="return confirm('Restore this backup?')">Restore backup</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tab nav -->
    <div class="tabs" id="tab-nav">
<?php
$tabs = $configurable_services;
array_push($tabs, '_raw');
foreach ($tabs as $i => $tab_id):
    $label = ($tab_id === '_raw') ? 'Raw YAML' : $tab_id;
    $active = ($i === 0) ? ' active' : '';
    $has_svc = isset($services[$tab_id]);
    $is_disabled = isset($services[$tab_id]['profiles']) &&
                   is_array($services[$tab_id]['profiles']) &&
                   in_array('disabled', $services[$tab_id]['profiles'], true);
    $badge = ($is_disabled ? ' 🚫' : '') . ($has_svc && $tab_id !== '_raw' ? ' ●' : '');
    echo "<button class=\"tab-btn{$active}\" onclick=\"showTab('" . he($tab_id) . "',this)\">{$label}{$badge}</button>\n";
endforeach;
?>
    </div>

    <!-- Structured services form -->
    <form id="structured-form" method="post">
        <input type="hidden" name="action" value="save_structured">
<?php
foreach ($configurable_services as $i => $svc):
    $svc_data    = $services[$svc] ?? [];
    $is_disabled = isset($svc_data['profiles']) &&
                   is_array($svc_data['profiles']) &&
                   in_array('disabled', $svc_data['profiles'], true);
    $is_optional = in_array($svc, $optional_services, true);
    $active_cls  = ($i === 0) ? ' active' : '';
?>
    <div class="card tab-pane<?php echo $active_cls; ?>" id="tab-<?php echo he($svc); ?>">
        <div class="svc-header">
            <span class="svc-title"><?php echo he($svc); ?></span>
            <?php if ($is_disabled): ?><span class="disabled-badge">DISABLED</span><?php endif; ?>
            <?php if ($is_optional): ?>
            <label class="toggle-label">
                <input type="checkbox" name="svc[<?php echo he($svc); ?>][disabled]"
                    value="1" <?php echo $is_disabled ? 'checked' : ''; ?>>
                Disable this service (adds profile: disabled)
            </label>
            <?php endif; ?>
        </div>
        <?php if (!empty($svc_data) || !$is_optional): ?>
            <strong style="font-size:12px;color:#aaa;">Environment variables:</strong>
            <?php sc_render_env_table($svc, $svc_data); ?>
            <?php if (!empty($svc_data['env_file']) || $svc === 'bots-hive'): ?>
                <?php sc_render_env_file_block($svc, $svc_data); ?>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">No current override entries for this service.
            Add ENV vars below to create an override.</p>
            <?php sc_render_env_table($svc, []); ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

        <div id="structured-save-bar" style="padding: 12px 0;">
            <button type="submit" class="primary">Save working copy (structured)</button>
            <span class="muted" style="margin-left:10px;">Saves ENV tables + service toggles.</span>
        </div>
    </form>

    <!-- Raw YAML form — shown only when Raw YAML tab is active -->
    <div class="card" id="tab-_raw" style="display:none">
        <p class="muted">Direct YAML editor — replaces working copy on save (structured form values are ignored).
        Use with care: invalid YAML will not be saved.</p>
        <form id="raw-form" method="post">
            <input type="hidden" name="action" value="save_raw">
            <textarea name="raw_yaml" class="raw-ta"><?php echo he($yaml_text); ?></textarea>
            <div style="margin-top:8px;">
                <button type="submit" class="primary">Save raw YAML</button>
            </div>
        </form>
    </div>

    <!-- Apply instructions -->
    <div class="card">
        <h2 style="font-size:16px;margin:0 0 8px 0;">Apply to host</h2>
        <p class="muted">After saving the working copy, run one of these on the Docker host machine:</p>
        <div class="mono-pre">
# Linux/Mac:
sh scripts/update_restart.sh

# Windows (from project directory):
scripts\update_restart.cmd</div>
        <p class="muted" style="margin-top:8px;">The script will:
            copy the working copy out of the container,
            back up the active override,
            then run <code>docker compose up -d</code>.</p>
    </div>

    <script>
    function showTab(id, btn) {
        var isRaw = (id === '_raw');
        // Hide all service tab-panes and the raw pane
        document.querySelectorAll('.tab-pane').forEach(function(p) { p.style.display = 'none'; });
        document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
        var rawPane = document.getElementById('tab-_raw');
        if (rawPane) rawPane.style.display = 'none';
        // Show the selected tab
        var pane = document.getElementById('tab-' + id);
        if (pane) pane.style.display = 'block';
        if (btn) btn.classList.add('active');
        // Show/hide structured save bar
        var saveBar = document.getElementById('structured-save-bar');
        if (saveBar) saveBar.style.display = isRaw ? 'none' : '';
    }
    document.addEventListener('DOMContentLoaded', function () {
        var first = document.querySelector('.tab-btn');
        if (first) first.click();
    });
    </script>
</body>
</html>
