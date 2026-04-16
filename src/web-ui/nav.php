<?php
/**
 * nav.php — shared top navigation bar.
 * Include from any <body> context — outputs <style> + <nav>.
 * Auto-detects active page from SCRIPT_FILENAME; override by setting $tp_nav_active.
 * Uses $is_admin / $is_trader if already set, falls back to $user_rights, defaults to true
 * for IP-only admin pages that do not run JWT auth.
 */
if (!isset($is_admin)) {
    $is_admin = isset($user_rights) ? str_in($user_rights, 'admin') : true;
}
if (!isset($is_trader)) {
    $is_trader = isset($user_rights) ? str_in($user_rights, 'trade') : $is_admin;
}
if (!isset($tp_nav_active)) {
    $tp_nav_active = preg_replace('/\.php$/i', '', basename($_SERVER['SCRIPT_FILENAME'] ?? ''));
}
?>
<style>
.tp-nav {
    display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
    padding: 8px 12px; margin-bottom: 14px;
    background: rgba(20,20,30,0.85); border: 1px solid #3a3a4a;
    border-radius: 8px; font-size: 13px;
}
.tp-nav a {
    padding: 5px 11px; border: 1px solid #4a4a66; border-radius: 5px;
    background: #1a1a2a; color: #b0b8e0; text-decoration: none; white-space: nowrap;
    transition: background 0.15s, border-color 0.15s;
}
.tp-nav a:hover  { background: #26263a; border-color: #7070aa; color: #dde; }
.tp-nav a.active { background: #2a2a50; border-color: #6868c0; color: #d0d0ff; font-weight: bold; }
.tp-nav .sep     { color: #444; user-select: none; }
</style>
<nav class="tp-nav">
    <a href="/index.php"<?php echo $tp_nav_active === 'index' ? ' class="active"' : ''; ?>>Home</a>
<?php if ($is_admin || $is_trader): ?>
    <span class="sep">|</span>
    <a href="/mm-config.php"<?php echo $tp_nav_active === 'mm-config' ? ' class="active"' : ''; ?>>MM Config</a>
<?php endif; ?>
<?php if ($is_admin): ?>
    <a href="/basic-admin.php"<?php echo $tp_nav_active === 'basic-admin' ? ' class="active"' : ''; ?>>Admin</a>
    <a href="/data-cfg.php"<?php echo $tp_nav_active === 'data-cfg' ? ' class="active"' : ''; ?>>Data Config</a>
    <a href="/sys-config.php"<?php echo $tp_nav_active === 'sys-config' ? ' class="active"' : ''; ?>>Platform Config</a>
<?php endif; ?>
</nav>
