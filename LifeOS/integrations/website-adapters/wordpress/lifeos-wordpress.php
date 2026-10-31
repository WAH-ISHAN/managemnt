<?php
/**
 * Plugin Name: LifeOS Website Connector
 * Description: Manage permitted WordPress pages and posts from your LifeOS workspace.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

final class LifeOSWordPressConnector {
    private const PROTOCOL='lifeos-admin-v1';
    private const REVISION='_lifeos_revision';
    public static function register(): void {
        register_rest_route('lifeos/v1','/admin',['methods'=>'POST','callback'=>[self::class,'serve'],'permission_callback'=>[self::class,'permission']]);
    }
    private static function error(int $status,string $message): WP_Error { return new WP_Error('lifeos_error',$message,['status'=>$status]); }
    public static function permission(WP_REST_Request $request): bool|WP_Error {
        if (!is_ssl()) return self::error(403,'HTTPS is required.');
        if (strlen($request->get_body())>262144) return self::error(413,'Request too large.');
        $input=$request->get_json_params();
        if (!is_array($input) || ($input['protocol'] ?? '')!==self::PROTOCOL) return self::error(422,'Unsupported protocol.');
        if (($input['action'] ?? '')==='login') return true;
        $header=$request->get_header('authorization');
        if (!is_string($header) || !preg_match('/^Bearer ([a-f0-9]{64})$/D',$header,$match)) return self::error(401,'Sign in to WordPress.');
        $session=get_transient('lifeos_session_'.hash('sha256',$match[1]));
        if (!is_array($session) || ($session['expires_at'] ?? 0)<=time()) return self::error(401,'WordPress session expired.');
        $user=get_userdata($session['user_id']);
        if (!$user || !hash_equals($session['password_fingerprint'],hash('sha256',$user->user_pass))) return self::error(401,'WordPress account changed.');
        $valid=false;
        foreach (WP_Application_Passwords::get_user_application_passwords($user->ID) as $password) {
            if ($password['uuid']===$session['application_uuid']) $valid=true;
        }
        if (!$valid) return self::error(401,'WordPress application password was revoked.');
        wp_set_current_user($user->ID);
        return true;
    }
    private static function login(array $input): array|WP_Error {
        if (array_diff(array_keys($input),['protocol','action','username','password']) || !is_string($input['username'] ?? null) || !is_string($input['password'] ?? null) || strlen($input['username'])>120 || strlen($input['password'])>4096) return self::error(422,'Invalid login request.');
        $ip=hash('sha256',(string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $buckets=['lifeos_ip_'.$ip=>20,'lifeos_user_'.hash('sha256',$input['username'])=>5];
        foreach ($buckets as $key=>$limit) {
            $attempts=(int)get_transient($key);
            if ($attempts>=$limit) return self::error(429,'Too many login attempts. Try again in 15 minutes.');
            set_transient($key,$attempts+1,900);
        }
        $uuid=null;
        $capture=static function($user,$item) use (&$uuid) { $uuid=$item['uuid']; };
        add_action('application_password_did_authenticate',$capture,10,2);
        try { $user=wp_authenticate_application_password(null,$input['username'],$input['password']); }
        finally { remove_action('application_password_did_authenticate',$capture,10); }
        if (!$user instanceof WP_User || !is_string($uuid)) return self::error(401,'Use your WordPress username and an Application Password.');
        wp_set_current_user($user->ID);
        if (!current_user_can('edit_posts') && !current_user_can('edit_pages')) return self::error(403,'This account cannot edit website content.');
        delete_transient('lifeos_user_'.hash('sha256',$input['username']));
        $token=bin2hex(random_bytes(32)); $expires=time()+1800;
        set_transient('lifeos_session_'.hash('sha256',$token),['user_id'=>$user->ID,'application_uuid'=>$uuid,'password_fingerprint'=>hash('sha256',$user->user_pass),'expires_at'=>$expires],1800);
        return ['protocol'=>self::PROTOCOL,'token'=>$token,'expires_at'=>$expires,'username'=>$user->user_login];
    }
    public static function revision(int $postId): int {
        add_post_meta($postId,self::REVISION,1,true);
        return max(1,(int)get_post_meta($postId,self::REVISION,true));
    }
    public static function changed(int $postId): void {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) return;
        self::revision($postId);
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=CAST(meta_value AS UNSIGNED)+1 WHERE post_id=%d AND meta_key=%s",$postId,self::REVISION));
        wp_cache_delete($postId,'post_meta');
    }
    private static function collections(): array {
        $result=[];
        foreach (['pages'=>'page','posts'=>'post'] as $id=>$type) {
            $caps=get_post_type_object($type)->cap;
            if (!current_user_can($caps->edit_posts)) continue;
            $actions=['list','update'];
            if (current_user_can($caps->create_posts)) $actions[]='create';
            if (current_user_can($caps->delete_posts)) $actions[]='delete';
            $field=static fn($name,$label,$max,$required=false,$type='text')=>compact('name','label','type','required')+['max_length'=>$max];
            $result[]=['id'=>$id,'label'=>ucfirst($id),'capabilities'=>$actions,'fields'=>[$field('title','Title',200),$field('slug','URL slug',200),$field('body','WordPress content / block markup',20000,false,'textarea'),$field('status','Status: draft, publish, pending, private or future',20,true)]];
        }
        return $result;
    }
    private static function record(array $row): array|WP_Error {
        $body=$row['content']['raw'] ?? '';
        if (mb_strlen($body)>20000) return self::error(422,'This content exceeds the connector limit. Edit it in WordPress.');
        return ['id'=>(string)$row['id'],'version'=>self::revision((int)$row['id']),'data'=>['title'=>$row['title']['raw'] ?? '', 'slug'=>$row['slug'] ?? '', 'body'=>$body,'status'=>$row['status']]];
    }
    private static function invoke(string $type,string $action,array $params): WP_REST_Response|WP_Error {
        $controller=new WP_REST_Posts_Controller($type);
        $method=match($action) {'list'=>'get_items','create'=>'create_item','update'=>'update_item','delete'=>'delete_item',default=>'get_item'};
        $request=new WP_REST_Request(match($action) {'list','read'=>'GET','delete'=>'DELETE',default=>'POST'});
        foreach ($params+['context'=>'edit'] as $key=>$value) $request->set_param($key,$value);
        $allowed=$controller->{$method.'_permissions_check'}($request);
        if (is_wp_error($allowed)) return $allowed;
        if (!$allowed) return self::error(403,'WordPress denied this operation.');
        return $controller->$method($request);
    }
    public static function serve(WP_REST_Request $request): WP_REST_Response {
        try { $result=self::dispatch($request); }
        catch (Throwable) { $result=self::error(503,'WordPress could not complete this operation.'); }
        if (is_wp_error($result)) {
            $status=(int)($result->get_error_data()['status'] ?? 503);
            $result=new WP_REST_Response(['protocol'=>self::PROTOCOL,'error'=>$result->get_error_message()],$status);
        } elseif (!$result instanceof WP_REST_Response) $result=new WP_REST_Response($result);
        $result->header('Cache-Control','no-store'); $result->header('X-Content-Type-Options','nosniff');
        return $result;
    }
    private static function dispatch(WP_REST_Request $request): array|WP_Error {
        // Permission is also checked here to keep direct calls fail-closed.
        $allowed=self::permission($request); if (is_wp_error($allowed)) return $allowed;
        $input=$request->get_json_params(); $action=$input['action'] ?? '';
        if ($action==='login') return self::login($input);
        $fields=match($action) {'manifest','logout'=>['protocol','action'],'list'=>['protocol','action','collection','offset','limit'],'create'=>['protocol','action','collection','data'],'update'=>['protocol','action','collection','id','version','data'],'delete'=>['protocol','action','collection','id','version'],default=>[]};
        if (!$fields || array_diff(array_keys($input),$fields)) return self::error(422,'Unsupported request.');
        if ($action==='logout') { delete_transient('lifeos_session_'.hash('sha256',substr($request->get_header('authorization'),7))); return ['protocol'=>self::PROTOCOL,'logged_out'=>true]; }
        $collections=self::collections();
        if ($action==='manifest') return ['protocol'=>self::PROTOCOL,'site'=>['name'=>mb_substr(wp_strip_all_tags(get_bloginfo('name')),0,120)],'collections'=>$collections];
        $collection=null;
        foreach ($collections as $candidate) if ($candidate['id']===($input['collection'] ?? null)) $collection=$candidate;
        if (!$collection || !in_array($action,$collection['capabilities'],true)) return self::error(403,'This collection or action is not allowed.');
        $type=$collection['id']==='pages' ? 'page' : 'post';
        if ($action==='list') {
            $offset=$input['offset'] ?? 0; $limit=$input['limit'] ?? 50;
            if (!is_int($offset) || $offset<0 || $offset>1000000 || !is_int($limit) || $limit<1 || $limit>50) return self::error(422,'Invalid page.');
            $params=['offset'=>$offset,'per_page'=>$limit,'status'=>['publish','draft','pending','private','future'],'orderby'=>'id','order'=>'asc'];
            if (!current_user_can(get_post_type_object($type)->cap->edit_others_posts)) $params['author']=get_current_user_id();
            $response=self::invoke($type,'list',$params); if (is_wp_error($response)) return $response;
            $records=[];
            foreach ($response->get_data() as $row) {
                if (!current_user_can('edit_post',$row['id'])) return self::error(403,'WordPress denied access to a record.');
                $record=self::record($row); if (is_wp_error($record)) return $record; $records[]=$record;
            }
            return ['protocol'=>self::PROTOCOL,'records'=>$records,'total'=>(int)($response->get_headers()['X-WP-Total'] ?? count($records)),'offset'=>$offset,'limit'=>$limit];
        }
        $id=0; $lock=null;
        if ($action!=='create') {
            if (!is_string($input['id'] ?? null) || !ctype_digit($input['id']) || !is_int($input['version'] ?? null)) return self::error(422,'Invalid record version.');
            $id=(int)$input['id'];
            if (get_post_type($id)!==$type || !current_user_can($action==='delete' ? 'delete_post' : 'edit_post',$id)) return self::error(403,'WordPress denied access to this record.');
            $lock='lifeos_edit_'.$id;
            if (!add_option($lock,time(),'',false)) return self::error(409,'Another LifeOS edit is active. Refresh before trying again.');
        }
        try {
            if ($id && (self::revision($id)!==$input['version'] || (function_exists('wp_check_post_lock') && wp_check_post_lock($id)))) return self::error(409,'The WordPress record changed or is being edited. Refresh first.');
            $params=$id ? ['id'=>$id] : [];
            if ($action==='delete') $params['force']=false; // Use WordPress Trash for recovery.
            else {
                $data=$input['data'] ?? null;
                if (!is_array($data) || array_diff(array_keys($data),['title','slug','body','status'])) return self::error(422,'Invalid content fields.');
                foreach ($collection['fields'] as $field) {
                    $value=$data[$field['name']] ?? '';
                    if (!is_string($value) || str_contains($value,"\0") || mb_strlen($value)>$field['max_length']) return self::error(422,'Invalid content length.');
                }
                if (!in_array($data['status'] ?? '',['draft','publish','pending','private','future'],true)) return self::error(422,'Choose a supported WordPress status.');
                $params+=['title'=>$data['title'] ?? '', 'slug'=>$data['slug'] ?? '', 'content'=>$data['body'] ?? '', 'status'=>$data['status']];
            }
            $response=self::invoke($type,$action,$params); if (is_wp_error($response)) return $response;
            if ($action==='delete') return ['protocol'=>self::PROTOCOL,'deleted'=>true];
            $record=self::record($response->get_data()); if (is_wp_error($record)) return $record;
            return ['protocol'=>self::PROTOCOL,'record'=>$record];
        } finally { if ($lock!==null) delete_option($lock); }
    }
}
add_action('rest_api_init',[LifeOSWordPressConnector::class,'register']);
add_action('save_post',[LifeOSWordPressConnector::class,'changed'],10,1);
