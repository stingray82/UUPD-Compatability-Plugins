<?php
/** Shared, backwards-compatible licence menu generator settings. */
if (!defined('ABSPATH')) { exit; }
if (!function_exists('uupd_wsl_gen_menu_settings')) {
function uupd_wsl_gen_menu_settings($key) {
    $s = get_option('uupd_gen_menu_' . sanitize_key($key), []);
    return wp_parse_args(is_array($s) ? $s : [], [
        'placement'=>'top_level', 'parent'=>'options-general.php', 'menu_slug'=>'', 'return_path'=>'', 'menu_title'=>'', 'page_title'=>''
    ]);
}
function uupd_wsl_gen_menu_clean_path($raw) {
    $raw = trim((string)$raw);
    // A local WP admin path is required; absolute URLs and traversal are rejected.
    if (preg_match('~^(admin|options-general|tools|themes|plugins|users|index|upload|edit)\\.php\\?page=[a-zA-Z0-9_-]+(?:\\#[a-zA-Z0-9_-]+)?$~D', $raw)) return $raw;
    return '';
}
function uupd_wsl_gen_menu_clean_parent($raw) {
    $raw = trim((string)$raw);
    if (preg_match('~^admin\.php\?page=([a-zA-Z0-9_-]+)$~D', $raw, $m)) return $m[1];
    if (preg_match('~^(?:[a-zA-Z0-9_-]+|[a-zA-Z0-9_-]+\.php|edit\.php\?post_type=[a-zA-Z0-9_-]+)$~D', $raw)) return $raw;
    return '';
}
function uupd_wsl_gen_menu_render($key) {
    if (!current_user_can('manage_options')) return;
    $id = sanitize_key($key);
    if (isset($_POST['uupd_gen_menu_save']) && isset($_POST['uupd_gen_menu_key']) && sanitize_key(wp_unslash($_POST['uupd_gen_menu_key'])) === $id) {
        check_admin_referer('uupd_gen_menu_' . $id);
        $placement = isset($_POST['uupd_gen_placement']) ? sanitize_key(wp_unslash($_POST['uupd_gen_placement'])) : 'top_level';
        if (!in_array($placement, ['top_level','settings','custom_parent','embedded'], true)) $placement='top_level';
        $parent = isset($_POST['uupd_gen_parent']) ? sanitize_text_field(wp_unslash($_POST['uupd_gen_parent'])) : '';
        $parent = uupd_wsl_gen_menu_clean_parent($parent);
        $slug = isset($_POST['uupd_gen_slug']) ? sanitize_key(wp_unslash($_POST['uupd_gen_slug'])) : '';
        $menu_title = isset($_POST['uupd_gen_menu_title']) ? sanitize_text_field(wp_unslash($_POST['uupd_gen_menu_title'])) : '';
        $page_title = isset($_POST['uupd_gen_page_title']) ? sanitize_text_field(wp_unslash($_POST['uupd_gen_page_title'])) : '';
        $path = isset($_POST['uupd_gen_path']) ? uupd_wsl_gen_menu_clean_path(wp_unslash($_POST['uupd_gen_path'])) : '';
        if ($placement === 'custom_parent' && (!$parent || ($slug && $slug === $parent))) {
            echo '<div class="notice notice-error"><p>Enter a valid parent menu slug and choose a different child menu slug. No changes saved.</p></div>';
        } elseif ($placement === 'embedded' && !$path) {
            echo '<div class="notice notice-error"><p>Enter a valid registered admin return path for embedded mode. No changes saved.</p></div>';
        } else {
            update_option('uupd_gen_menu_' . $id, ['placement'=>$placement,'parent'=>$parent,'menu_slug'=>$slug,'return_path'=>$path,'menu_title'=>$menu_title,'page_title'=>$page_title], false);
            echo '<div class="notice notice-success"><p>Licence menu settings saved.</p></div>';
        }
    }
    $s=uupd_wsl_gen_menu_settings($id);
    ?>
    <div class="postbox" style="padding:16px 20px;max-width:900px;margin-top:18px">
      <h2 style="margin:0 0 10px">Generated licence menu placement</h2>
      <p>These options affect generated bootloaders, not this generator's own admin menu. Choose an existing page only when the destination is registered by the target plugin.</p>
      <form method="post">
        <?php wp_nonce_field('uupd_gen_menu_' . $id); ?>
        <input type="hidden" name="uupd_gen_menu_key" value="<?php echo esc_attr($id); ?>">
        <table class="form-table"><tr><th><label for="uupd-gen-placement-<?php echo esc_attr($id); ?>">Licence UI location</label></th><td>
        <select name="uupd_gen_placement" id="uupd-gen-placement-<?php echo esc_attr($id); ?>" class="uupd-gen-placement">
        <?php foreach (['top_level'=>'Top-level menu','settings'=>'WordPress Settings submenu','custom_parent'=>'Submenu under another plugin','embedded'=>'Existing page / inline-only (no menu)'] as $v=>$label) : ?>
          <option value="<?php echo esc_attr($v); ?>" <?php selected($s['placement'],$v); ?>><?php echo esc_html($label); ?></option>
        <?php endforeach; ?></select></td></tr>
        <tr class="uupd-gen-row-parent"><th><label>Existing parent menu slug</label></th><td><input class="regular-text" name="uupd_gen_parent" value="<?php echo esc_attr($s['parent']); ?>" placeholder="esp-example-setting-page"><p class="description">Enter the registered parent menu slug, e.g. esp-example-setting-page, tools.php, or edit.php?post_type=product. Not an admin URL.</p></td></tr>
        <tr class="uupd-gen-row-slug"><th><label>Custom licence menu slug</label></th><td><input class="regular-text" name="uupd_gen_slug" value="<?php echo esc_attr($s['menu_slug']); ?>" placeholder="Leave blank for vendor-plugin"><p class="description">Only for UUPD-created menus.</p></td></tr>
        <tr class="uupd-gen-row-title"><th><label>Menu title (optional)</label></th><td><input class="regular-text" name="uupd_gen_menu_title" value="<?php echo esc_attr($s['menu_title']); ?>" placeholder="Automatically use plugin name + License"></td></tr>
        <tr class="uupd-gen-row-title"><th><label>Page title (optional)</label></th><td><input class="regular-text" name="uupd_gen_page_title" value="<?php echo esc_attr($s['page_title']); ?>" placeholder="Automatically use plugin name + License"></td></tr>
        <tr class="uupd-gen-row-return"><th><label>Existing admin return path</label></th><td><input class="regular-text uupd-gen-return" name="uupd_gen_path" value="<?php echo esc_attr($s['return_path']); ?>" placeholder="admin.php?page=my-plugin-settings#licence"><p class="description">Required for inline-only: an existing registered page, relative to wp-admin.</p></td></tr>
        </table><button type="submit" class="button button-primary" name="uupd_gen_menu_save" value="1">Save licence menu settings</button>
      </form>
    </div>
    <script>(function(){var p=document.getElementById('uupd-gen-placement-<?php echo esc_js($id); ?>');if(!p)return;var root=p.closest('.postbox');function sync(){var v=p.value;root.querySelector('.uupd-gen-row-parent').style.display=v==='custom_parent'?'':'none';root.querySelector('.uupd-gen-row-slug').style.display=v==='embedded'?'none':'';root.querySelectorAll('.uupd-gen-row-title').forEach(function(r){r.style.display=v==='embedded'?'none':'';});root.querySelector('.uupd-gen-row-return').style.display=v==='embedded'?'':'none';root.querySelector('.uupd-gen-return').required=v==='embedded';}p.addEventListener('change',sync);sync();})();</script>
    <?php
}
/** PHP source lines to insert into generated UUPD_License_UI::register(). */
function uupd_wsl_gen_menu_lines($key, $constant_name, $product_name_constant = null) {
    $s=uupd_wsl_gen_menu_settings($key);
    $mode=$s['placement'];
    $parent=($mode==='embedded') ? false : (($mode==='settings') ? 'options-general.php' : (($mode==='custom_parent') ? $s['parent'] : 'top_level'));
    $lines="        'menu_parent'    => " . var_export($parent,true) . ",\\n";
    if ($mode!=='embedded' && $s['menu_slug']!=='') {
        $lines.="        'menu_slug'      => " . var_export($s['menu_slug'],true) . ",\\n";
    } else {
        $lines.="        'menu_slug'      => " . $constant_name . ",\\n";
    }
    if ($mode !== 'embedded') {
        $default_title = $product_name_constant ? $product_name_constant . " . ' License'" : "'License'";
        $lines .= "        'page_title'     => " . ($s['page_title'] !== '' ? var_export($s['page_title'], true) : $default_title) . ",\n";
        $lines .= "        'menu_title'     => " . ($s['menu_title'] !== '' ? var_export($s['menu_title'], true) : $default_title) . ",\n";
    }
    if($mode==='embedded') {
        $path=uupd_wsl_gen_menu_clean_path($s['return_path']);
        if (!$path) return null;
        $lines.="        'return_url'     => admin_url( " . var_export($path,true) . " ),\\n";
    }
    return str_replace('\\n', "\n", $lines);
}
}
