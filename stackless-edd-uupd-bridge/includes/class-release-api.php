<?php
/** Secure CLI release publishing into native Stackless EDD software metadata. */
defined('ABSPATH') || exit;
final class Stackless_EDD_UUPD_Release_API {
    const P = '_stackless_edd_sl_';
    public static function boot() {add_action('rest_api_init',[__CLASS__,'routes']);}
    public static function routes() {register_rest_route('uupd/v1','/products/(?P<slug>[a-z0-9_.-]+)/release',['methods'=>'POST','callback'=>[__CLASS__,'release'],'permission_callback'=>[__CLASS__,'permit']]);}
    private static function product($r) {
        $slug=strtolower((string)$r['slug']);$id=absint($r->get_param('download_id'));
        if ($id) {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'download') return 0;
            // The WordPress permalink slug and the Stackless EDD licensing slug
            // are separate. Allow a first CLI release when the licensing slug
            // is unset, provided the permalink slug matches the requested slug.
            // Never silently overwrite an existing, different licensing slug.
            $configured = strtolower(trim((string) get_post_meta($id, self::P . 'slug', true)));
            if ($configured !== '' && $configured !== $slug) {
                return new WP_Error('edd_release_slug_mismatch',
                    sprintf('Download %d has Stackless EDD software slug "%s", but the deployment requests "%s". Update the Software Licensing slug or EDD_SOFTWARE_SLUG.', $id, $configured, $slug),
                    ['status' => 409]);
            }
            if ($configured === '' && $post->post_name !== $slug) {
                return new WP_Error('edd_release_slug_unset',
                    sprintf('Download %d has no Stackless EDD software slug. Set the Software Licensing slug to "%s" (WordPress permalink slug is "%s").', $id, $slug, $post->post_name),
                    ['status' => 409]);
            }
            return $id;
        }
        if(!class_exists('StacklessEDD\Modules\SoftwareLicensing\ProductConfig'))return 0;
        return \StacklessEDD\Modules\SoftwareLicensing\ProductConfig::downloadIdForSlug($slug);
    }
    public static function permit($r) {
        if(!is_user_logged_in())return new WP_Error('edd_release_auth','Use a WordPress Application Password.', ['status'=>401]);
        if(!class_exists('StacklessEDD\Modules\SoftwareLicensing\ProductConfig'))return new WP_Error('edd_release_missing','Stackless EDD licensing must be enabled.', ['status'=>503]);
        $id=self::product($r);
        if(is_wp_error($id))return $id;
        if(!$id)return new WP_Error('edd_release_unknown','Unknown EDD software download. Check EDD_DOWNLOAD_ID and the Stackless EDD Software Licensing slug.', ['status'=>404]);
        if(!current_user_can('edit_post',$id)||!current_user_can('manage_options'))return new WP_Error('edd_release_forbidden','Insufficient permissions.', ['status'=>403]);
        if($r->get_param('publish')&&!current_user_can('publish_post',$id))return new WP_Error('edd_release_publish_forbidden','Cannot publish download.', ['status'=>403]);
        return true;
    }
    public static function release($r) {
        $id=self::product($r);if(is_wp_error($id))return $id;if(!$id)return new WP_Error('edd_release_unknown','Unknown download.', ['status'=>404]);
        $config=\StacklessEDD\Modules\SoftwareLicensing\ProductConfig::read($id);
        if(!$config['enabled'])return new WP_Error('edd_release_disabled','Enable Software Licensing for this EDD Download (Download Details), then save it before deploying.', ['status'=>400]);
        $version=preg_replace('/^v/i','',trim((string)$r->get_param('version')));
        if(!$version||strlen($version)>64||!preg_match('/^[0-9][A-Za-z0-9._+\-]*$/',$version))return new WP_Error('edd_release_version','Invalid version.', ['status'=>400]);
        $mode=sanitize_key((string)($r->get_param('download_mode')?:'append'));
        if(!in_array($mode,['append','replace'],true))return new WP_Error('edd_release_mode','download_mode must be append or replace.', ['status'=>400]);
        foreach(['requires_wp','tested_wp','requires_php'] as $key)if($r->has_param($key)&&!preg_match('/^[0-9.]*$/',(string)$r->get_param($key)))return new WP_Error('edd_release_field','Invalid '.$key,['status'=>400]);
        $upload=$r->get_file_params()['package']??null;$external=trim((string)$r->get_param('download_url'));
        if($upload&&$external)return new WP_Error('edd_release_package','Use package OR download_url.', ['status'=>400]);
        if($external&&(!wp_http_validate_url($external)||stripos($external,'https://')!==0||!preg_match('/\.zip(?:\?.*)?$/i',$external)))return new WP_Error('edd_release_url','Use a valid HTTPS ZIP URL.', ['status'=>400]);
        if(!$upload&&!$external&&!\StacklessEDD\Modules\SoftwareLicensing\ProductConfig::package($id,null))return new WP_Error('edd_release_package','First release requires a ZIP.', ['status'=>400]);
        foreach(['icon_1x','icon_2x','banner_low','banner_high'] as $art) if($r->has_param($art)) { $value=trim((string)$r->get_param($art)); if($value!==''&&(!wp_http_validate_url($value)||stripos($value,'https://')!==0))return new WP_Error('edd_release_artwork','Artwork must be HTTPS.', ['status'=>400]); }
        $slug=strtolower((string)$r['slug']);$url=$external;$path='';
        if($upload){
            if(!is_array($upload)||($upload['error']??-1)!==UPLOAD_ERR_OK||!is_uploaded_file($upload['tmp_name']??''))return new WP_Error('edd_release_upload','Invalid upload.', ['status'=>400]);
            if(strtolower(pathinfo($upload['name']??'',PATHINFO_EXTENSION))!=='zip'||!class_exists('ZipArchive'))return new WP_Error('edd_release_zip','A ZIP and PHP ZipArchive are required.', ['status'=>400]);
            $zip=new ZipArchive();if($zip->open($upload['tmp_name'],ZipArchive::CHECKCONS)!==true)return new WP_Error('edd_release_zip','Corrupt ZIP.', ['status'=>400]);
            $found=false;$slug=strtolower((string)$r['slug']);
            for($i=0;$i<$zip->numFiles;$i++){$member=$zip->getNameIndex($i);if(strpos($member,'../')!==false||strpos($member,'..\\')!==false||substr($member,0,1)==='/'||preg_match('/^[A-Za-z]:/',$member)){$zip->close();return new WP_Error('edd_release_zip','Unsafe ZIP.', ['status'=>400]);}if(strpos($member,$slug.'/')===0&&substr($member,-1)!=='/')$found=true;}
            $zip->close();if(!$found)return new WP_Error('edd_release_layout','ZIP must contain the software slug directory.', ['status'=>400]);
            $uploads=wp_upload_dir();if(!empty($uploads['error']))return new WP_Error('edd_release_storage','Uploads unavailable.', ['status'=>500]);
            $dir=trailingslashit($uploads['basedir']).'stackless-edd-uupd-releases/';
            if(!wp_mkdir_p($dir))return new WP_Error('edd_release_storage','Cannot create storage.', ['status'=>500]);
            // Files are server-private. .htaccess works on Apache; nginx/IIS must deny this path.
            $deny="Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
            if(!is_file($dir.'.htaccess')&&file_put_contents($dir.'.htaccess',$deny)===false)return new WP_Error('edd_release_storage','Cannot protect storage.', ['status'=>500]);
            if(!is_file($dir.'index.html'))file_put_contents($dir.'index.html','');
            $name=wp_unique_filename($dir,$slug.'-'.$version.'-'.wp_generate_password(8,false).'.zip');$path=$dir.$name;
            if(!move_uploaded_file($upload['tmp_name'],$path))return new WP_Error('edd_release_storage','Cannot move ZIP.', ['status'=>500]);
            @chmod($path,0640);
            $url=trailingslashit($uploads['baseurl']).'stackless-edd-uupd-releases/'.rawurlencode($name);
        }
        // EDD stores file rows under edd_download_files. Preserve old files in append mode;
        // in replace mode, keep the old package as an archive as well to avoid breaking old license links.
        if($url){
            $rows=get_post_meta($id,'edd_download_files',true);$rows=is_array($rows)?$rows:[];
            $oldKey=\StacklessEDD\Modules\SoftwareLicensing\ProductConfig::packageFileKey($id,null);
            $tags=\StacklessEDD\Modules\SoftwareLicensing\ProductConfig::fileVersions($id);
            if($oldKey!==''&&$config['version']!==''&&isset($rows[$oldKey]))$tags[$oldKey]=$config['version'];
            $key='stackless_uupd_'.str_replace('-','_',wp_generate_uuid4());
            $entry=['name'=>$slug.' '.$version,'file'=>$url,'condition'=>'all','attachment_id'=>0];
            // Put the current update file first. Never remove historical files needed by prior licenses.
            $rows=[$key=>$entry]+$rows;
            update_post_meta($id,'edd_download_files',$rows);
            update_post_meta($id,\StacklessEDD\Modules\SoftwareLicensing\ProductConfig::FILE_VERSIONS_META,$tags);
            update_post_meta($id,self::P.'package_source','download');update_post_meta($id,self::P.'download_id',$key);update_post_meta($id,self::P.'package_url','');
        }
        foreach(['requires_wp','tested_wp','requires_php','upgrade_notice','author','author_url','homepage','details_description','installation','faq','requires_plugins','software_type'] as $field)if($r->has_param($field)){
            $val=(string)$r->get_param($field);$val=in_array($field,['details_description','installation','faq','upgrade_notice'],true)?wp_kses_post($val):sanitize_text_field($val);
            if(in_array($field,['homepage','author_url'],true))$val=esc_url_raw($val);
            update_post_meta($id,self::P.$field,$val);
        }
        if($r->has_param('changelog')){$entry=wp_kses_post((string)$r->get_param('changelog'));if($entry!=='')update_post_meta($id,self::P.'changelog','<h4>'.esc_html($version).'</h4>'.$entry."\n".$config['changelog']);}
        foreach(['icon_1x','icon_2x','banner_low','banner_high'] as $art)if($r->has_param($art)){
            $value=trim((string)$r->get_param($art));if($value!==''&&(!wp_http_validate_url($value)||stripos($value,'https://')!==0))return new WP_Error('edd_release_artwork','Artwork must be HTTPS.', ['status'=>400]);
            update_post_meta($id,Stackless_EDD_UUPD_Bridge::META_PREFIX.$art,esc_url_raw($value));
        }
        // Initialize the licensing slug on a first deployment only.
        // An explicitly configured, conflicting slug is rejected in product().
        if (trim((string) get_post_meta($id, self::P . 'slug', true)) === '') {
            update_post_meta($id, self::P . 'slug', $slug);
        }
        update_post_meta($id,self::P.'version',$version);
        if($r->get_param('publish'))wp_update_post(['ID'=>$id,'post_status'=>'publish']);
        clean_post_cache($id);
        $result=new WP_REST_Response(['success'=>true,'id'=>$id,'download_id'=>$id,'slug'=>(string)$r['slug'],'version'=>$version,'download_mode'=>$mode,'download_count'=>count((array)get_post_meta($id,'edd_download_files',true)),'package'=>!!($url||\StacklessEDD\Modules\SoftwareLicensing\ProductConfig::package($id,null)),'last_updated'=>current_time('mysql')],200);
        $result->header('Cache-Control','no-store');return $result;
    }
}
