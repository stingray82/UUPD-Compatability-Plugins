<?php
/**
 * Plugin Name: Stackless EDD → UUPD Bridge
 * Description: UUPD compatibility, per-download loader generation and authenticated release deployment for Stackless EDD.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
final class Stackless_EDD_UUPD_Bridge {
    const META_PREFIX = '_stackless_edd_uupd_';
    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'routes'], 20);
        add_filter('edd_download_details_sections', [__CLASS__, 'register_edd_section'], 30);
        add_action('add_meta_boxes_download', [__CLASS__, 'fallback_metabox']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_media']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_order_key_copy']);
        add_action('save_post_download', [__CLASS__, 'save'], 30, 2);
        add_action('wp_ajax_stackless_edd_uupd_preview', [__CLASS__, 'preview']);
        add_filter('stackless_edd_software_licensing_info_payload', [__CLASS__, 'artwork'], 10, 2);
        add_filter('stackless_edd_software_licensing_info_payload', [__CLASS__, 'detail_sections'], 20, 2);
        add_filter('stackless_edd_software_licensing_version_payload', [__CLASS__, 'version_artwork'], 10, 3);
        require_once __DIR__ . '/includes/class-release-api.php';
        Stackless_EDD_UUPD_Release_API::boot();
    }
    public static function routes() {
        register_rest_route('uupd/v1', '/license/(?P<action>activate|deactivate|check)', ['methods'=>'POST','callback'=>[__CLASS__,'license'],'permission_callback'=>'__return_true']);
        register_rest_route('uupd/v1', '/metadata', ['methods'=>'GET','callback'=>[__CLASS__,'metadata'],'permission_callback'=>'__return_true']);
    }
    private static function params($r) {
        $site = (string)($r->get_param('domain') ?: $r->get_param('site_url') ?: '');
        if ($site && !preg_match('~^https?://~i',$site)) $site='https://'.$site;
        $key=$r->get_header('x-uupd-license-key') ?: $r->get_param('license_key') ?: $r->get_param('key');
        $id=absint($r->get_param('download_id') ?: $r->get_param('item_id') ?: $r->get_param('product_id'));
        $p=['license_key'=>is_scalar($key)?trim((string)$key):'', 'slug'=>sanitize_text_field((string)$r->get_param('slug')), 'site_url'=>esc_url_raw($site)];
        if ($id) $p['download_id']=$id;
        if ($r->get_param('installed_version') ?: $r->get_param('version')) $p['version']=sanitize_text_field((string)($r->get_param('installed_version') ?: $r->get_param('version')));
        return $p;
    }
    private static function dispatch($action,$params,$method='POST') {
        if (!class_exists('StacklessEDD\\Modules\\SoftwareLicensing\\RestApi')) return new WP_Error('edd_licensing_missing','Enable Stackless EDD Software Licensing.', ['status'=>503]);
        $r=new WP_REST_Request($method,'/stackless-edd/v1/licenses/'.$action);
        if ($method==='GET') $r->set_query_params($params); else $r->set_body_params($params);
        return rest_do_request($r);
    }
    public static function license($r) {
        $p=self::params($r); $action=sanitize_key((string)$r['action']);
        if (!$p['license_key'] || !$p['slug'] || !$p['site_url']) return new WP_Error('missing_parameters','license_key, slug and domain are required.', ['status'=>400]);
        $response=self::dispatch($action,$p);
        if (is_wp_error($response)) return $response;
        if ($action==='deactivate' && $response->get_status()===404 && ($response->get_data()['code']??'')==='site_not_active') $response=new WP_REST_Response(['success'=>true,'status'=>'inactive','code'=>'already_deactivated'],200);
        $d=(array)$response->get_data(); if (isset($d['license']) && is_array($d['license'])) $d+=$d['license'];
        $status=(string)($d['status']??'inactive');$success=!empty($d['success']);
        if ($action==='check') $success=!empty($d['valid']) && (!array_key_exists('site_active',$d)||!empty($d['site_active']));
        if ($action==='deactivate' && $success) $status='inactive';
        $d['success']=$success;$d['status']=$status;$d['license_status']=$status;$d['license']=$success && $status==='active'?'valid':'invalid';
        if(isset($d['expires_at'])) {$d['date_expires']=$d['expires_at'];$d['expiry']=$d['expires_at'];}
        if(isset($d['activation_limit'])) $d['max_activations']=$d['activation_limit'];
        if(isset($d['activations_used'])) $d['activations']=$d['activations_used'];
        $response->set_data($d);$response->header('Cache-Control','private, no-store');return $response;
    }
    /** Keep View Details sections in sync with the native product fields.
     * Description: Stackless override, then EDD product body; absent if both blank.
     * Installation: Stackless field only; absent when blank.
     */
    public static function detail_sections($data, $download_id) {
        if (!is_array($data) || !($download_id = absint($download_id))) return $data;
        $stackless = trim((string) get_post_meta($download_id, '_stackless_edd_sl_details_description', true));
        $edd = (string) get_post_field('post_content', $download_id);
        $description = self::has_section_content($stackless) ? $stackless : $edd;
        $installation = (string) get_post_meta($download_id, '_stackless_edd_sl_installation', true);
        $sections = isset($data['sections']) && is_array($data['sections']) ? $data['sections'] : [];
        unset($sections['description'], $sections['installation']);
        if (self::has_section_content($description)) $sections['description'] = wp_kses_post(wpautop($description));
        if (self::has_section_content($installation)) $sections['installation'] = wp_kses_post(wpautop($installation));
        foreach ($sections as $key => $value) {
            if (!is_string($value) || !self::has_section_content($value)) unset($sections[$key]);
        }
        $data['sections'] = $sections;
        return $data;
    }
    private static function has_section_content($content) {
        $plain = html_entity_decode(wp_strip_all_tags((string)$content), ENT_QUOTES, 'UTF-8');
        return trim(str_replace("\xc2\xa0", ' ', $plain)) !== '';
    }
    public static function metadata($r) {
        $p=self::params($r); if(!$p['slug']) return new WP_Error('missing_slug','slug is required.', ['status'=>400]);
        $info=self::dispatch('info',$p,'GET');$ver=$p['license_key']!==''?self::dispatch('version',$p,'GET'):null;
        $details=$info instanceof WP_REST_Response && $info->get_status()===200?(array)$info->get_data():[];
        $updates=$ver instanceof WP_REST_Response && $ver->get_status()===200?(array)$ver->get_data():[];
        if (!$details && !$updates) return $info;
        // /licenses/version has update-specific fields but its sections contain only
        // the changelog. /licenses/info is authoritative for View Details tabs.
        $d = array_merge($details, $updates);
        $info_sections = isset($details['sections']) && is_array($details['sections']) ? $details['sections'] : [];
        $version_sections = isset($updates['sections']) && is_array($updates['sections']) ? $updates['sections'] : [];
        // Preserve the full info response; use version sections only as a fallback.
        $d['sections'] = array_merge($version_sections, $info_sections);
        $v=(string)($d['new_version']??$d['version']??'');$package=(string)($updates['package']??$updates['download_link']??'');
        $d['success']=true;$d['version']=$v;$d['new_version']=$v;$d['download_url']=$package;$d['download_link']=$package;$d['package']=$package;
        $d['license_required']=true;$d['license_status']=(string)($updates['license']['status']??'inactive');
        foreach(['sections','icons','banners'] as $field) $d[$field]=(array)($d[$field]??[]);
        foreach ($d['sections'] as $key => $value) {
            if (!is_string($value) || !self::has_section_content($value)) unset($d['sections'][$key]);
        }
        $response=new WP_REST_Response($d,200);$response->header('Cache-Control','private, no-store');return $response;
    }
    public static function enqueue_media($hook) {
        $screen = get_current_screen();
        if ($screen && $screen->post_type === 'download' && in_array($hook, ['post.php','post-new.php'], true)) {
            wp_enqueue_media();
        }
    }
    /** Add a per-key copy action only to the native EDD order details screen. */
    public static function enqueue_order_key_copy($hook) {
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $view = isset($_GET['view']) && is_string($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';
        $post_type = isset($_GET['post_type']) && is_string($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
        if ($page !== 'edd-payment-history' || $view !== 'view-order-details' || $post_type !== 'download') return;
        if (!current_user_can('edit_shop_payments') && !current_user_can('manage_shop_settings') && !current_user_can('manage_options')) return;
        wp_enqueue_script('stackless-edd-uupd-order-copy', plugins_url('assets/order-key-copy.js', __FILE__), [], '1.0.0', true);
        wp_add_inline_style('wp-admin', '.stackless-sl-order-key-copy{margin-left:8px!important;vertical-align:middle}.stackless-sl-order-key-copy-status{margin-left:6px;display:inline-block;vertical-align:middle}');
    }
    /** Native EDD 3.3.6+ Download Details vertical tab. */
    public static function register_edd_section($sections) {
        if (!class_exists('EDD\Admin\Downloads\Editor\Section')) return $sections;
        require_once __DIR__ . '/includes/class-edd-download-section.php';
        $sections['stackless-uupd'] = Stackless_EDD_UUPD_Download_Section::class;
        return $sections;
    }
    /** Older EDD versions without Download Details tabs retain the editor metabox. */
    public static function fallback_metabox() {
        if (class_exists('EDD\Admin\Downloads\Editor\Section')) return;
        add_meta_box('stackless-edd-uupd','UUPD Loader & Release Artwork',[__CLASS__,'panel'],'download','normal','default');
    }
    public static function metabox() { self::fallback_metabox(); }
    private static function fields() {return ['vendor','software_slug','type','mode','menu_placement','menu_parent','menu_slug','menu_title','page_title','updater_path','ui_path','icon_1x','icon_2x','banner_low','banner_high'];}
    private static function parent_options() {
        return [
            'options-general.php'=>'Settings', 'tools.php'=>'Tools', 'plugins.php'=>'Plugins',
            'themes.php'=>'Appearance', 'index.php'=>'Dashboard', 'upload.php'=>'Media',
            'edit.php'=>'Posts', 'users.php'=>'Users', 'edit.php?post_type=download'=>'Downloads (EDD)',
        ];
    }
    /** Never propagate the native licensing form's 'set-software-slug' placeholder. */
    private static function resolved_slug($id) {
        $native = sanitize_title((string) get_post_meta($id, '_stackless_edd_sl_slug', true));
        if ($native !== '' && $native !== 'set-software-slug') return $native;
        $post = get_post($id);
        return $post ? sanitize_title($post->post_name ?: $post->post_title) : '';
    }
    private static function default_values($post) {
        $name = $post && $post->post_title ? $post->post_title : 'My Software';
        $slug = $post ? sanitize_title($post->post_title) : 'my-software';
        return ['vendor'=>'myvendor','type'=>'plugin','mode'=>'both','menu_placement'=>'submenu',
            'menu_parent'=>'options-general.php','menu_slug'=>$slug.'-license',
            'menu_title'=>$name.' License','page_title'=>$name.' License Settings',
            'updater_path'=>'inc/updater.php','ui_path'=>'inc/class-uupd-license-ui.php',
            'software_slug'=>self::resolved_slug($post ? $post->ID : 0)];
    }
    public static function panel($post) {
        if (!current_user_can('edit_post',$post->ID)) return;
        wp_nonce_field('stackless_edd_uupd_save','stackless_edd_uupd_nonce');
        $defaults=self::default_values($post);
        $labels=['vendor'=>'Vendor / namespace','software_slug'=>'UUPD software slug (must match EDD licensing)','type'=>'Software type','mode'=>'Licence UI display','menu_placement'=>'Licence page placement','menu_parent'=>'Parent admin menu','menu_slug'=>'Settings page slug','menu_title'=>'Admin menu title','page_title'=>'Page heading','updater_path'=>'Updater PHP include path','ui_path'=>'Licence UI PHP include path','icon_1x'=>'Plugin icon (128 × 128) URL','icon_2x'=>'Plugin icon (256 × 256) URL','banner_low'=>'Plugin banner (772 × 250) URL','banner_high'=>'Plugin banner (1544 × 500) URL'];
        $choices=['type'=>['plugin'=>'WordPress plugin','theme'=>'WordPress theme'],
            'mode'=>['both'=>'Both: admin page + inline box','menu'=>'Dedicated admin page only','inline'=>'Inline box only (existing page)'],
            'menu_placement'=>['submenu'=>'Create a submenu','top'=>'Create a top-level menu','existing'=>'Use an existing page (no new menu)']];
        echo '<p>Configure this download’s UUPD updater and licence UI. Selections control the generated code. The title and slug suggestions are based on the download title; changes you make are kept.</p>';
        echo '<div class="stackless-edd-uupd-fields" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:2px 22px">';
        foreach(self::fields() as $field) {
            $saved=get_post_meta($post->ID,self::META_PREFIX.$field,true);
            $val=($saved==='' && isset($defaults[$field])) ? $defaults[$field] : (string)$saved;
            $is_url=strpos($field,'icon_')===0 || strpos($field,'banner_')===0;
            if ($field==='software_slug' && ($val==='' || $val==='set-software-slug')) $val=self::resolved_slug($post->ID);
            echo '<p class="stackless-edd-field" data-field="'.esc_attr($field).'"><label for="stackless_edd_uupd_'.esc_attr($field).'"><strong>'.esc_html($labels[$field]).'</strong></label><br>';
            if(isset($choices[$field])) {
                echo '<select style="width:100%" id="stackless_edd_uupd_'.esc_attr($field).'" name="'.esc_attr(self::META_PREFIX.$field).'">';
                foreach($choices[$field] as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($val,$key,false).'>'.esc_html($label).'</option>';
                echo '</select>';
            } elseif ($field==='menu_parent') {
                echo '<select style="width:100%" id="stackless_edd_uupd_menu_parent" name="'.esc_attr(self::META_PREFIX.$field).'">';
                $parents=self::parent_options();
                if($val!=='' && !isset($parents[$val]))$parents[$val]='Custom: '.$val;
                foreach($parents as $key=>$label)echo '<option value="'.esc_attr($key).'" '.selected($val,$key,false).'>'.esc_html($label).'</option>';
                echo '</select><small>Choose a standard WordPress parent, or use the custom slug below.</small>';
                echo '<input type="text" id="stackless_edd_uupd_custom_parent" placeholder="Optional custom plugin parent slug" style="width:100%;margin-top:5px" value="'.(isset(self::parent_options()[$val])?'':esc_attr($val)).'">';
            } else {
                echo '<input style="width:100%" id="stackless_edd_uupd_'.esc_attr($field).'" name="'.esc_attr(self::META_PREFIX.$field).'" value="'.esc_attr($val).'" type="'.($is_url?'url':'text').'" '.($is_url?'placeholder="https://example.com/image.png"':'').'>';
                if($is_url) {
                    echo ' <button type="button" class="button stackless-edd-media-picker" data-target="stackless_edd_uupd_'.esc_attr($field).'">Select from Media Library</button>';
                    echo ' <button type="button" class="button stackless-edd-media-clear" data-target="stackless_edd_uupd_'.esc_attr($field).'">Clear</button>';
                    echo '<br><small>Choose an image from WordPress Media Library or paste a public HTTPS image URL. Leave empty to omit artwork.</small>';
                }
            }
            if ($field==='software_slug') echo '<br><small>Must match the product software slug used by deploy.cfg. Saved here for code generation; it does not change the native EDD licensing slug.</small>';
            echo '</p>';
        }
        echo '</div><p><button type="button" class="button" id="stackless_edd_uupd_suggest">Regenerate suggested menu titles and slug</button> <small>Overrides the three title/slug fields with defaults based on the current download title.</small></p>';
        echo '<p id="stackless_edd_uupd_menu_tip" style="color:#646970"></p>';
        foreach(['constants'=>'Part 1 — Constants','bootloader'=>'Part 2 — Bootloader','inline'=>'Part 3 — Inline licence box (existing settings callback)'] as $k=>$label) {
            echo '<div class="stackless-edd-generated-part" style="margin:16px 0">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:6px"><strong>'.esc_html($label).'</strong>';
            echo '<span><button type="button" class="button stackless-edd-copy" data-target="stackless_edd_uupd_'.esc_attr($k).'" aria-label="Copy '.esc_attr($label).'">Copy</button> <span class="stackless-edd-copy-status" role="status" aria-live="polite" style="margin-left:6px"></span></span></div>';
            echo '<textarea readonly id="stackless_edd_uupd_'.esc_attr($k).'" onclick="this.select()" style="width:100%;height:'.($k==='bootloader'?'350':'165').'px;font-family:monospace"></textarea></div>';
        }
        $nonce=wp_create_nonce('stackless_edd_uupd_preview');$id=(int)$post->ID;
        $suggest=['menu_slug'=>$defaults['menu_slug'],'menu_title'=>$defaults['menu_title'],'page_title'=>$defaults['page_title']];
        ?>
        <script>
        jQuery(function($){
            const fields=<?php echo wp_json_encode(self::fields()); ?>;
            const suggestions=<?php echo wp_json_encode($suggest); ?>;
            let timer, sequence=0, pending=false;
            const field=k=>$('#stackless_edd_uupd_'+k);
            function layout(){
                const mode=field('mode').val(), place=field('menu_placement').val();
                const existing=mode==='inline'||place==='existing';
                $('.stackless-edd-field[data-field="menu_placement"]').toggle(mode!=='inline');
                $('.stackless-edd-field[data-field="menu_parent"]').toggle(place==='submenu' && mode!=='inline');
                $('.stackless-edd-field[data-field="menu_title"],.stackless-edd-field[data-field="page_title"]').toggle(!existing);
                $('#stackless_edd_uupd_menu_tip').text(existing ? 'Existing-page mode creates no menu. Enter the existing page slug; insert Part 3 in its callback.' : place==='top' ? 'A new top-level licence menu will be registered.' : 'A new submenu will appear beneath the selected parent.');
            }
            function refresh(){
                const seq=++sequence;pending=true;$('.stackless-edd-copy').prop('disabled',true);$('.stackless-edd-copy-status').text('Refreshing…');
                let data={action:'stackless_edd_uupd_preview',nonce:<?php echo wp_json_encode($nonce); ?>,download_id:<?php echo $id; ?>};
                fields.forEach(k=>data[k]=field(k).val());
                $.post(ajaxurl,data,function(r){if(seq!==sequence)return; if(r.success){['constants','bootloader','inline'].forEach(k=>field(k).val(r.data[k]||'')); $('.stackless-edd-copy-status').text('');}else{$('.stackless-edd-copy-status').text('Preview failed; do not copy stale code');}}).fail(function(){if(seq===sequence)$('.stackless-edd-copy-status').text('Preview failed; do not copy stale code');}).always(function(){if(seq===sequence){pending=false;$('.stackless-edd-copy').prop('disabled',false);}});
            }
            function update(){layout();clearTimeout(timer);timer=setTimeout(refresh,220);}
            fields.forEach(k=>field(k).on('input change',update));
            $('#stackless_edd_uupd_custom_parent').on('input',function(){
                const v=$(this).val().trim();
                if(v){let s=field('menu_parent');if(!s.find('option[data-custom="1"]').length)s.append($('<option data-custom="1">Custom parent</option>'));s.find('option[data-custom="1"]').val(v).text('Custom: '+v);s.val(v);}
                else {field('menu_parent').find('option[data-custom="1"]').remove();field('menu_parent').val('options-general.php');}
                update();
            });
            $('#stackless_edd_uupd_suggest').on('click',function(){Object.keys(suggestions).forEach(k=>field(k).val(suggestions[k]));update();});
            $('.stackless-edd-copy').on('click',async function(e){
                e.preventDefault();
                const button=$(this), target=document.getElementById(button.attr('data-target'));
                if(!target||pending)return;
                const content=target.value, status=button.siblings('.stackless-edd-copy-status');
                if(!content){status.text('Nothing to copy');return;}
                try {
                    if(navigator.clipboard && window.isSecureContext){
                        await navigator.clipboard.writeText(content);
                    } else {
                        target.focus();target.select();
                        if(!document.execCommand('copy'))throw new Error('Copy failed');
                    }
                    button.text('Copied!');status.text('Copied to clipboard');
                    window.setTimeout(function(){button.text('Copy');status.text('');},1800);
                } catch(error){
                    target.focus();target.select();
                    status.text('Copy unavailable — press Ctrl+C (or Cmd+C)');
                }
            });
            $('.stackless-edd-media-picker').on('click',function(e){
                e.preventDefault();
                const target=$('#'+$(this).attr('data-target'));
                if(typeof wp==='undefined'||!wp.media){window.alert('WordPress Media Library is unavailable on this screen.');return;}
                const frame=wp.media({title:'Choose UUPD artwork',button:{text:'Use this image'},library:{type:'image'},multiple:false});
                frame.on('select',function(){
                    const attachment=frame.state().get('selection').first();
                    if(!attachment)return;
                    const data=attachment.toJSON();
                    if(data.url){target.val(data.url).trigger('change');}
                });
                frame.open();
            });
            $('.stackless-edd-media-clear').on('click',function(e){
                e.preventDefault();$('#'+$(this).attr('data-target')).val('').trigger('change');
            });
            layout();refresh();
        });
        </script>
        <?php
    }
    public static function save($id,$post) {
        if(wp_is_post_revision($id)||!isset($_POST['stackless_edd_uupd_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['stackless_edd_uupd_nonce'])),'stackless_edd_uupd_save')||!current_user_can('edit_post',$id))return;
        foreach(self::fields() as $field){$key=self::META_PREFIX.$field;if(!isset($_POST[$key])||!is_scalar($_POST[$key]))continue;$v=sanitize_text_field(wp_unslash($_POST[$key]));if(strpos($field,'icon_')===0||strpos($field,'banner_')===0)$v=esc_url_raw($v,['https']);if($field==='software_slug')$v=sanitize_title($v);if($field==='vendor')$v=sanitize_key(str_replace('-','_',$v));if($field==='menu_slug')$v=sanitize_key($v);if($field==='menu_parent')$v=self::safe_menu_parent($v);if(in_array($field,['updater_path','ui_path'],true))$v=self::safe_path($v,$field==='updater_path'?'inc/updater.php':'inc/class-uupd-license-ui.php');update_post_meta($id,$key,$v);}
    }
    public static function preview() {
        check_ajax_referer('stackless_edd_uupd_preview','nonce');$id=absint($_POST['download_id']??0);
        if(!$id||get_post_type($id)!=='download'||!current_user_can('edit_post',$id))wp_send_json_error('Not permitted',403);
        $p=[];foreach(self::fields() as $field)$p[$field]=sanitize_text_field(wp_unslash($_POST[$field]??''));
        wp_send_json_success(self::loader_parts($id,$p['vendor'],$p['software_slug'],$p['type'],$p['mode'],$p['menu_parent'],$p['updater_path'],$p['ui_path'],$p['menu_placement'],$p['menu_slug'],$p['menu_title'],$p['page_title']));
    }
    public static function version_artwork($data,$license,$download_id){return self::artwork($data,$download_id);}
    public static function artwork($data,$download_id){
        if(!is_array($data)||!is_numeric($download_id))return $data;
        foreach(['icon_1x'=>['icons','1x'],'icon_2x'=>['icons','2x'],'banner_low'=>['banners','low'],'banner_high'=>['banners','high']] as $key=>$dest){$url=get_post_meta((int)$download_id,self::META_PREFIX.$key,true);if($url){if(!isset($data[$dest[0]])||!is_array($data[$dest[0]]))$data[$dest[0]]=[];$data[$dest[0]][$dest[1]]=esc_url_raw($url);}}
        return $data;
    }
    private static function loader_parts($product_id, $vendor, $software_slug, $type, $mode, $menu_parent, $updater_path = 'inc/updater.php', $ui_path = 'inc/class-uupd-license-ui.php', $placement = 'submenu', $target_slug = '', $menu_title = '', $page_title = '') {
        $product = get_post($product_id);
        $name = $product ? $product->post_title : 'My Software';
        $slug = sanitize_title((string)$software_slug);
        if ($slug === '' || $slug === 'set-software-slug') $slug = self::resolved_slug($product_id);
        $vendor = sanitize_key(str_replace('-', '_', (string)$vendor));
        if ($vendor === '') { $vendor = 'myvendor'; }
        $type = $type === 'theme' ? 'theme' : 'plugin';
        $mode = in_array($mode, ['inline','menu','both'], true) ? $mode : 'both';
        $placement = in_array($placement, ['existing','top','submenu'], true) ? $placement : ($mode === 'inline' ? 'existing' : ($menu_parent === 'top_level' ? 'top' : 'submenu'));
        // Inline-only never creates a menu. Existing-page mode also never creates a menu.
        if ($mode === 'inline') { $placement = 'existing'; }
        $menu_parent = self::safe_menu_parent($menu_parent);
        $target_slug = sanitize_key($target_slug);
        $menu_title = sanitize_text_field($menu_title);
        $page_title = sanitize_text_field($page_title);
        $resolved_parent = $placement === 'top' ? 'top_level' : $menu_parent;
        $creates_menu = $placement !== 'existing';
        $base = 'UUPD_STACKLESS_' . strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $vendor . '_' . $slug . '_' . $product_id));
        $base = preg_replace('/_+/', '_', $base);
        $q = static function($x) { return var_export($x, true); };
        $site = untrailingslashit(home_url('/'));
        $const = "// Configuration: paste into the main " . $type . " PHP file. No opening PHP tag.\n";
        foreach (['VENDOR'=>$vendor,'VERSION'=>(string)get_post_meta($product_id, '_stackless_edd_sl_version', true) ?: '1.0.0','ITEM_NUMBER'=>(int)$product_id,'FRIENDLY_NAME'=>$name,'SLUG'=>$slug,'SERVER_BASE'=>$site] as $key=>$value) {
            $const .= 'define( ' . $q($base . '_' . $key) . ', ' . $q($value) . " );\n";
        }
        $const .= "define( '" . $base . "_MAIN_FILE', __FILE__ ); // Plugin main file, or theme functions.php\n";
        if (!$creates_menu && $target_slug === '') { $const .= '// ERROR: Set Target / new page slug in the Bridge before using this loader.\n'; }
        $const .= 'define( ' . $q($base . '_MENU_SLUG') . ', ' . ($target_slug !== '' ? $q($target_slug) : ($creates_menu ? $base . "_VENDOR . '-' . " . $base . '_SLUG' : $q('REPLACE-WITH-EXISTING-PAGE-SLUG'))) . " );\n";
        $C = static function($key) use ($base) { return $base . '_' . $key; };
        $root = $type === 'theme' ? 'get_stylesheet_directory()' : '__DIR__';
        $hook = $type === 'theme' ? 'after_setup_theme' : 'plugins_loaded';
        $metadata = $C('SERVER_BASE') . " . '/wp-json/uupd/v1/metadata'";
        $license_scope = $vendor . '/' . $slug;
        $boot = "// Initialization: paste below the constants in the same PHP file.\n";
        $boot .= "// Guard against missing constants when Part 1 was not copied or was regenerated.\n";
        foreach (['VENDOR'=>$vendor,'VERSION'=>(string)get_post_meta($product_id, '_stackless_edd_sl_version', true) ?: '1.0.0','ITEM_NUMBER'=>(int)$product_id,'FRIENDLY_NAME'=>$name,'SLUG'=>$slug,'SERVER_BASE'=>$site] as $key=>$value) {
            $constant = $C($key);
            $boot .= 'if ( ! defined( ' . $q($constant) . ' ) ) { define( ' . $q($constant) . ', ' . $q($value) . " ); }\n";
        }
        $boot .= 'if ( ! defined( ' . $q($C('MAIN_FILE')) . ' ) ) { define( ' . $q($C('MAIN_FILE')) . ', __FILE__ ); }' . "\n";
        $boot .= 'if ( ! defined( ' . $q($C('MENU_SLUG')) . ' ) ) { define( ' . $q($C('MENU_SLUG')) . ', ' . $q($target_slug ?: $slug . '-license') . ' ); }' . "\n";

        $boot .= "add_action( '" . $hook . "', static function () {\n";
        $boot .= "    require_once " . $root . " . " . $q("/" . self::safe_path($updater_path, "inc/updater.php")) . ";\n";
        $boot .= "    require_once " . $root . " . " . $q("/" . self::safe_path($ui_path, "inc/class-uupd-license-ui.php")) . ";\n\n";
        $boot .= "    \\UUPD\\UI\\License\\V1\\UUPD_License_UI::register( [\n";
        foreach (['vendor'=>'VENDOR','slug'=>'SLUG','item_id'=>'ITEM_NUMBER','plugin_name'=>'FRIENDLY_NAME','license_server'=>'SERVER_BASE'] as $key=>$ck) {
            $boot .= "        '" . $key . "' => " . $C($ck) . ",\n";
        }
        $boot .= "        'metadata_base' => " . $metadata . ",\n";
        $boot .= "        'menu_parent' => " . ($creates_menu ? $q($resolved_parent) : 'false') . ",\n";
        $boot .= "        'menu_slug' => " . $C('MENU_SLUG') . ",\n";
        $boot .= "        'page_title' => " . ($page_title !== '' ? $q($page_title) : $C('FRIENDLY_NAME') . " . ' License'") . ",\n";
        $boot .= "        'menu_title' => " . ($menu_title !== '' ? $q($menu_title) : $C('FRIENDLY_NAME') . " . ' License'") . ",\n";
        $boot .= "        'capability' => 'manage_options',\n";
        $boot .= "    ] );\n\n";
        if (!$creates_menu && !in_array($menu_parent, ['options-general.php', ''], true)) {
            // License UI defaults inline links to options-general.php. For a custom
            // parent, suppress only this product's built-in notice and replace it.
            $scope_filter = 'uupd/license_ui/show_notice/' . $vendor . '/' . $slug;
            $boot .= "    add_filter( " . $q($scope_filter) . ", static function ( \$show, \$license ) {\n";
            $boot .= "        if ( ! \$show || ( \$license['status'] ?? '' ) === 'active' ) { return \$show; }\n";
            $boot .= "        add_action( 'admin_notices', static function () {\n";
            $boot .= "            if ( ! current_user_can( 'manage_options' ) ) { return; }\n";
            $boot .= "            \$url = add_query_arg( 'page', " . $C('MENU_SLUG') . ", admin_url( " . $q(in_array($menu_parent, ['tools.php','themes.php','plugins.php','users.php','index.php','upload.php','edit.php','edit.php?post_type=download'], true) ? $menu_parent : 'admin.php') . " ) );\n";
            $boot .= "            echo '<div class=\"notice notice-warning\"><p>' . esc_html( sprintf( 'Please activate your %s license to enable updates and support.', " . $C('FRIENDLY_NAME') . " ) ) . ' <a class=\"button button-primary\" href=\"' . esc_url( \$url ) . '\">Activate License</a></p></div>';\n";
            $boot .= "        } );\n";
            $boot .= "        return false;\n    }, 10, 2 );\n\n";
        }
        $boot .= "    \\UUPD\\V2\\UUPD_Updater_V2::register( [\n";
        $boot .= "        'vendor' => " . $C('VENDOR') . ",\n";
        $boot .= "        'slug' => " . $C('SLUG') . ",\n";
        $boot .= "        'name' => " . $C('FRIENDLY_NAME') . ",\n";
        $boot .= "        'version' => " . $C('VERSION') . ",\n";
        $boot .= "        'mode' => 'json',\n";
        $boot .= "        'server' => " . $metadata . ",\n";
        $boot .= $type === 'theme' ? "        'real_slug' => " . $C('SLUG') . ",\n" : "        'plugin_file' => plugin_basename( " . $C('MAIN_FILE') . " ),\n";
        $boot .= "    ] );\n";
        $boot .= "}, 1 );\n\n";
        // Remove sensitive query key while preserving it for the UUPD request header.
        $boot .= "add_filter( 'uupd/request_params/" . $license_scope . "', static function ( \$params ) {\n";
        $boot .= "    unset( \$params['key'] );\n    \$params['domain'] = home_url( '/' );\n    \$params['item_id'] = " . $C('ITEM_NUMBER') . ";\n    return \$params;\n} );\n";
        $boot .= "add_filter( 'uupd/request_headers/" . $license_scope . "', static function ( \$headers ) {\n";
        $boot .= "    \$option = get_option( 'uupd_license_' . sanitize_key( " . $C('VENDOR') . " . '__' . str_replace( '-', '_', " . $C('SLUG') . " ) ), [] );\n";
        $boot .= "    \$constant_name = 'UUPD_' . strtoupper( str_replace( '-', '_', " . $C('VENDOR') . " ) ) . '_' . strtoupper( str_replace( '-', '_', " . $C('SLUG') . " ) ) . '_LICENSE_KEY';\n";
        $boot .= "    \$key = defined( \$constant_name ) ? constant( \$constant_name ) : ( \$option['license_key'] ?? '' );\n";
        $boot .= "    if ( is_string( \$key ) && \$key !== '' && ( \$option['status'] ?? '' ) === 'active' ) { \$headers['X-UUPD-License-Key'] = \$key; }\n";
        $boot .= "    return \$headers;\n} );\n";
        $inline = "\\UUPD\\UI\\License\\V1\\UUPD_License_UI::render_box_for( " . $C('SLUG') . ", " . $C('VENDOR') . " );\n";
        return ['constants'=>$const, 'bootloader'=>$boot, 'inline'=>$inline];
    }

    /** Validate WP core menu parents and custom plugin parent slugs. */
    private static function safe_menu_parent($parent) {
        $parent = trim((string) $parent);
        if ($parent === 'top_level') { return 'options-general.php'; } // Legacy setting.
        if (in_array($parent, ['options-general.php','tools.php','themes.php','plugins.php','users.php','index.php','upload.php','edit.php','admin.php'], true)) { return $parent; }
        if ($parent === 'edit.php?post_type=download') { return $parent; }
        if (preg_match('~^[a-zA-Z0-9_-]+(?:\.php)?$~', $parent)) { return $parent; }
        return 'options-general.php';
    }

    /** Reject absolute paths, traversal and non-PHP includes. */
    private static function safe_path($path, $default) {
        $path = str_replace('\\', '/', trim((string) $path));
        $path = ltrim($path, './');
        if ($path === '' || strpos($path, '..') !== false || strpos($path, ':') !== false || !preg_match('~^[a-zA-Z0-9_./-]+\.php$~', $path)) { return $default; }
        return $path;
    }

}
add_action('plugins_loaded',['Stackless_EDD_UUPD_Bridge','boot']);
