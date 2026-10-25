<?php
/** Optional variation distribution, release history and historical order grants. */
defined('ABSPATH') || exit;
final class Stackless_UUPD_Variation_Releases {
    const HISTORY = '_stackless_uupd_release_history';
    const GROUP = 'stackless-uupd-release-grants';
    public static function boot() {
        add_action('add_meta_boxes_product', [__CLASS__, 'register_box']);
        add_action('admin_post_stackless_uupd_manual_release', [__CLASS__, 'manual']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_notices', [__CLASS__, 'notice']);
        add_action('stackless_uupd_grant_batch', [__CLASS__, 'grant_batch'], 10, 3);
    }
    public static function enabled($product) { return $product && $product->get_meta('_stackless_uupd_bulk_enabled', true) === 'yes'; }
    public static function scope($product, $requested = '') {
        $scope = $requested ?: (string)$product->get_meta('_stackless_uupd_release_scope', true);
        return in_array($scope, ['product','all_variations'], true) ? $scope : 'product';
    }
    public static function register_box() { add_meta_box('stackless_uupd_release_box', 'Stackless Manual Release', [__CLASS__,'box'], 'product', 'side', 'default'); }
    public static function box($post) {
        $product = wc_get_product($post->ID);
        if (!$product || !$product->is_type('variable')) { echo '<p>Available for variable products.</p>'; return; }
        if (!self::enabled($product)) { echo '<p>Enable <strong>Optional release management</strong> in the UUPD Loader tab and save first.</p>'; return; }
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_post',$post->ID) || !current_user_can('upload_files')) { return; }
        $scope = self::scope($product);
        echo '<p>Deploy a Media Library ZIP as the first download. Older downloads are kept in append mode.</p>';
        // The product edit page already has a form; use a button linked to a hidden standalone form rendered in the footer.
        echo '<p><label>Release name<br><input style="width:100%" id="suupd_release_name" placeholder="Version 2.0.0"></label></p>';
        echo '<p><input id="suupd_release_file" type="hidden"><input id="suupd_release_filename" style="width:100%" readonly placeholder="Choose a ZIP"></p>';
        echo '<p><button type="button" class="button" id="suupd_choose">Upload / choose ZIP</button></p>';
        echo '<p><label>Apply to<br><select id="suupd_release_scope" style="width:100%"><option value="product" '.selected($scope,'product',false).'>Product only</option><option value="all_variations" '.selected($scope,'all_variations',false).'>Product + all variations</option></select></label></p>';
        echo '<p><label>Download mode<br><select id="suupd_release_mode"><option value="append">New file first, keep previous files</option><option value="replace">Replace existing files</option></select></label></p>';
        echo '<p><label><input type="checkbox" id="suupd_grant"> Grant new download to previous purchasers (background)</label></p>';
        echo '<p><button type="button" class="button button-primary" id="suupd_release_submit">Release download</button></p>';
        $history = get_post_meta($post->ID, self::HISTORY, true);
        echo '<hr><strong>Release history</strong>';
        if (!is_array($history) || !$history) { echo '<p>No recorded releases yet.</p>'; }
        else { echo '<ol style="padding-left:20px">'; foreach (array_slice($history,0,15) as $h) { echo '<li><strong>'.esc_html($h['name'] ?? '').'</strong><br><small>'.esc_html($h['date'] ?? '').' · '.esc_html($h['scope'] ?? 'product').' · '.intval($h['updated'] ?? 0).' variations</small><br><small>Past purchases: '.esc_html($h['grant_status'] ?? 'not requested').' ('.intval($h['granted'] ?? 0).')</small></li>'; } echo '</ol>'; }
        $action = esc_url(admin_url('admin-post.php'));
        $nonce = wp_create_nonce('stackless_uupd_manual_'.$post->ID);
        $pid = (int)$post->ID;
        add_action('admin_footer', static function() use ($action,$nonce,$pid) {
            echo '<form id="suupd_release_form" method="post" action="'.$action.'" style="display:none"><input name="action" value="stackless_uupd_manual_release"><input name="product_id" value="'.$pid.'"><input name="_wpnonce" value="'.esc_attr($nonce).'"><input name="attachment_id"><input name="release_name"><input name="release_scope"><input name="download_mode"><input name="grant_existing"></form>';
        });
    }
    public static function assets($hook) {
        if (!in_array($hook,['post.php','post-new.php'],true) || get_post_type() !== 'product') return;
        wp_enqueue_media(); wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', <<<'JS'
jQuery(function($){
 $('#suupd_choose').on('click',function(e){ e.preventDefault(); var frame=wp.media({title:'Choose release ZIP',button:{text:'Use ZIP'},multiple:false});frame.on('select',function(){var f=frame.state().get('selection').first().toJSON();$('#suupd_release_file').val(f.id);$('#suupd_release_filename').val(f.filename||f.title);});frame.open();});
 $('#suupd_release_submit').on('click',function(){var id=$('#suupd_release_file').val(), name=$('#suupd_release_name').val();if(!id||!name){alert('Choose a ZIP and enter a release name.');return;}if(!confirm('Publish this download to the selected scope?'))return;var f=$('#suupd_release_form');f.find('[name=attachment_id]').val(id);f.find('[name=release_name]').val(name);f.find('[name=release_scope]').val($('#suupd_release_scope').val());f.find('[name=download_mode]').val($('#suupd_release_mode').val());f.find('[name=grant_existing]').val($('#suupd_grant').is(':checked')?'1':'0');f.trigger('submit');});
});
JS
        );
    }
    public static function manual() {
        $id = absint($_POST['product_id'] ?? 0);
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_post',$id) || !current_user_can('upload_files')) wp_die('Forbidden', '', ['response'=>403]);
        check_admin_referer('stackless_uupd_manual_'.$id);
        $product=wc_get_product($id);
        if (!$product || !$product->is_type('variable') || !self::enabled($product)) wp_die('Variation releases are not enabled for this variable product.', '', ['response'=>400]);
        $attachment=absint($_POST['attachment_id'] ?? 0);
        $path=get_attached_file($attachment);
        if (get_post_type($attachment)!=='attachment' || !$path || !is_file($path) || strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='zip') wp_die('Select a valid local ZIP attachment.', '', ['response'=>400]);
        // Copy from Media Library to protected WooCommerce downloads so the download is not a public media URL.
        $dir=wp_upload_dir(); $private=trailingslashit($dir['basedir']).'woocommerce_uploads/stackless-uupd-releases/';
        if (!wp_mkdir_p($private)) wp_die('Cannot create release storage.', '', ['response'=>500]);
        $deny="Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
        foreach ([trailingslashit($dir['basedir']).'woocommerce_uploads/',$private] as $d) { if (!is_file($d.'.htaccess')) file_put_contents($d.'.htaccess',$deny); if (!is_file($d.'index.html')) file_put_contents($d.'index.html',''); }
        $target=$private.wp_unique_filename($private, 'manual-'.wp_generate_password(12,false).'.zip');
        if (!copy($path,$target)) wp_die('Cannot save release file.', '', ['response'=>500]);
        chmod($target,0640);
        $name=sanitize_text_field(wp_unslash($_POST['release_name'] ?? ''));
        $scope=sanitize_key(wp_unslash($_POST['release_scope'] ?? 'product'));
        $mode=sanitize_key(wp_unslash($_POST['download_mode'] ?? 'append'));
        if (!$name || !in_array($scope,['product','all_variations'],true) || !in_array($mode,['replace','append'],true)) wp_die('Invalid release options.', '', ['response'=>400]);
        $result=self::apply($product,$target,$name,$mode,$scope,!empty($_POST['grant_existing']));
        $flag=is_wp_error($result)?'error':(empty($result['failed'])?'success':'partial');
        wp_safe_redirect(add_query_arg(['post'=>$id,'action'=>'edit','suupd_release'=>$flag],admin_url('post.php')));exit;
    }
    public static function apply($product,$file,$name,$mode,$scope,$grant=false,$existing_id='') {
        if (!($product instanceof WC_Product) || !in_array($mode,['append','replace'],true) || !in_array($scope,['product','all_variations'],true)) return new WP_Error('release_invalid','Invalid release options.');
        $file = (string)$file;
        if (!$file) return new WP_Error('release_file','No release file provided.');
        // Local protected filesystem paths and previously validated HTTPS URLs are supported.
        $download_id=$existing_id ?: wp_generate_uuid4(); $download=new WC_Product_Download();
        $download->set_id($download_id); $download->set_name(sanitize_text_field($name)); $download->set_file($file);
        $targets=$existing_id ? [] : [$product->get_id()];
        if ($scope==='all_variations') {
            if (!$product->is_type('variable')) return new WP_Error('release_not_variable','All variations requires a variable product.');
            $targets=array_merge($targets,array_map('intval',$product->get_children()));
        }
        $updated=[];$failed=[];$variation_ids=[];
        foreach ($targets as $id) {
            try {
                $p=wc_get_product($id); if (!$p) throw new RuntimeException('Product not found.');
                $existing=$mode==='append'?$p->get_downloads():[];
                // New release takes first position; old releases remain when appending.
                $downloads=[$download_id=>$download];
                foreach ($existing as $key=>$old) { if ($old->get_file()!==$file) $downloads[$key]=$old; }
                $p->set_downloadable(true);$p->set_downloads($downloads);$p->save();
                $updated[]=$id;if ($id!=$product->get_id())$variation_ids[]=$id;
            } catch (Throwable $e) {$failed[$id]=$e->getMessage();}
        }
        if (!$updated && !$existing_id) return new WP_Error('release_failed','No products updated.');
        $release_id=wp_generate_uuid4(); $history=get_post_meta($product->get_id(),self::HISTORY,true);if(!is_array($history))$history=[];
        array_unshift($history,['release_id'=>$release_id,'date'=>current_time('mysql'),'name'=>$name,'scope'=>$scope,'mode'=>$mode,'download_id'=>$download_id,'variation_ids'=>$variation_ids,'updated'=>count($variation_ids),'failed'=>$failed,'grant_status'=>$grant?'queued':'not requested','granted'=>0]);
        update_post_meta($product->get_id(),self::HISTORY,array_slice($history,0,30));
        if ($grant) {
            if (!function_exists('as_enqueue_async_action')) self::history_update($product->get_id(),$release_id,['grant_status'=>'Action Scheduler unavailable']);
            elseif (!as_enqueue_async_action('stackless_uupd_grant_batch',[$product->get_id(),$release_id,1],self::GROUP)) self::history_update($product->get_id(),$release_id,['grant_status'=>'queue failed']);
        }
        return ['release_id'=>$release_id,'scope'=>$scope,'updated_variations'=>count($variation_ids),'download_id'=>$download_id,'failed'=>$failed,'grant_existing_requested'=>(bool)$grant];
    }
    private static function history_update($pid,$rid,$changes) {
        $history=get_post_meta($pid,self::HISTORY,true);if(!is_array($history))return;
        foreach($history as &$row){if(($row['release_id']??'')===$rid){$row=array_merge($row,$changes);break;}}unset($row);
        update_post_meta($pid,self::HISTORY,$history);
    }
    public static function grant_batch($pid,$rid,$page) {
        $history=get_post_meta($pid,self::HISTORY,true);$release=null;
        if(is_array($history))foreach($history as $row)if(($row['release_id']??'')===$rid){$release=$row;break;}
        if(!$release || !in_array($release['grant_status'],['queued','running'],true) || !function_exists('wc_get_orders'))return;
        $statuses=['completed'];if(get_option('woocommerce_downloads_grant_access_after_payment')==='yes')$statuses[]='processing';
        $batch=wc_get_orders(['status'=>$statuses,'limit'=>40,'paged'=>max(1,(int)$page),'orderby'=>'ID','order'=>'ASC','paginate'=>true]);
        $ids=array_map('intval',$release['variation_ids']);$count=0;$errors=0;
        foreach($batch->orders as $order) {
            if(!$order->is_download_permitted())continue;
            foreach($order->get_items('line_item') as $item) {
                $id=(int)$item->get_variation_id();
                // Parent-only releases can be purchased as simple products; variations are granted by child ID.
                if(!$id && $release['scope']==='product')$id=(int)$item->get_product_id();
                if(!in_array($id,$ids,true) && !($release['scope']==='product' && $id==(int)$pid))continue;
                $p=wc_get_product($id);if(!$p)continue;
                try {
                    // Check existing order permissions before granting again.
                    $store=WC_Data_Store::load('customer-download');
                    $existing=$store->get_downloads(['order_id'=>$order->get_id(),'product_id'=>$id,'download_id'=>$release['download_id'],'return'=>'ids','limit'=>1]);
                    if($existing)continue;
                    $ok=wc_downloadable_file_permission($release['download_id'],$p,$order,max(1,(int)$item->get_quantity()),$item);
                    if($ok)++$count;else ++$errors;
                } catch(Throwable $e) {++$errors;}
            }
        }
        $total=(int)($release['granted']??0)+$count;$err=(int)($release['errors']??0)+$errors;
        $done=(int)$page >= (int)$batch->max_num_pages;
        self::history_update($pid,$rid,['grant_status'=>$done?($err?'completed with errors':'completed'):'running','granted'=>$total,'errors'=>$err]);
        if(!$done && function_exists('as_enqueue_async_action'))if(!as_enqueue_async_action('stackless_uupd_grant_batch',[$pid,$rid,$page+1],self::GROUP))self::history_update($pid,$rid,['grant_status'=>'queue failed']);
    }
    public static function notice() {
        if(!isset($_GET['suupd_release']))return;$status=sanitize_key(wp_unslash($_GET['suupd_release']));
        if(!in_array($status,['success','partial','error'],true))return;
        echo '<div class="notice notice-'.($status==='success'?'success':'warning').' is-dismissible"><p>'.esc_html($status==='success'?'Release saved successfully.':($status==='partial'?'Some variations failed. Check release history.':'Release failed.')).'</p></div>';
    }
}
