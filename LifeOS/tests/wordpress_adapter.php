<?php
declare(strict_types=1);
// Contract fixture, not a substitute for a live WordPress staging installation.
define('ABSPATH',__DIR__.'/');
$hooks=[]; $transients=[]; $options=[]; $meta=[]; $posts=[]; $ssl=true; $canEdit=true; $appRevoked=false; $currentUser=0;
class WP_User { public int $ID=1; public string $user_login='owner'; public string $user_pass='private-password-hash'; }
class WP_Error {
    public function __construct(private string $code,private string $message,private array $data) {}
    public function get_error_data(): array { return $this->data; }
    public function get_error_message(): string { return $this->message; }
}
class WP_REST_Request {
    private array $params=[];
    public function __construct(public string $method='POST',private array $input=[],private string $auth='') {}
    public function get_body(): string { return json_encode($this->input,JSON_THROW_ON_ERROR); }
    public function get_json_params(): array { return $this->input; }
    public function get_header(string $name): string { return $name==='authorization' ? $this->auth : ''; }
    public function set_param(string $key,mixed $value): void { $this->params[$key]=$value; }
    public function values(): array { return $this->params; }
}
class WP_REST_Response {
    private array $headers=[];
    public function __construct(private mixed $data,public int $status=200) {}
    public function get_data(): mixed { return $this->data; }
    public function get_headers(): array { return $this->headers; }
    public function header(string $key,string $value): void { $this->headers[$key]=$value; }
}
class WP_Application_Passwords { public static function get_user_application_passwords(int $id): array { global $appRevoked; return $appRevoked ? [] : [['uuid'=>'application-test-id']]; } }
function add_action(string $name,callable $fn,int $priority=10,int $args=1): void { global $hooks; $hooks[$name][]=$fn; }
function remove_action(string $name,callable $fn,int $priority=10): void { global $hooks; $hooks[$name]=array_filter($hooks[$name],fn($item)=>$item!==$fn); }
function register_rest_route(...$args): void {}
function is_ssl(): bool { global $ssl; return $ssl; }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function get_transient(string $key): mixed { global $transients; return $transients[$key] ?? false; }
function set_transient(string $key,mixed $value,int $ttl): void { global $transients; $transients[$key]=$value; }
function delete_transient(string $key): void { global $transients; unset($transients[$key]); }
function get_userdata(int $id): WP_User { return new WP_User(); }
function wp_set_current_user(int $id): void { global $currentUser; $currentUser=$id; }
function get_current_user_id(): int { global $currentUser; return $currentUser; }
function current_user_can(string $cap,...$args): bool { global $canEdit; return $canEdit; }
function wp_authenticate_application_password($unused,string $username,string $password): ?WP_User {
    global $hooks;
    if ($username!=='owner' || $password!=='application-password') return null;
    $user=new WP_User();
    foreach ($hooks['application_password_did_authenticate'] ?? [] as $fn) $fn($user,['uuid'=>'application-test-id']);
    return $user;
}
function get_post_type_object(string $type): object { return (object)['cap'=>(object)array_fill_keys(['edit_posts','create_posts','delete_posts','edit_others_posts'],'edit_'.$type)]; }
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
function get_bloginfo(string $key): string { return 'WordPress fixture'; }
function wp_is_post_revision(int $id): bool { return false; }
function wp_is_post_autosave(int $id): bool { return false; }
function wp_cache_delete(...$args): void {}
function add_post_meta(int $id,string $key,mixed $value,bool $unique): void { global $meta; $meta[$id] ??= $value; }
function get_post_meta(int $id,string $key,bool $single): mixed { global $meta; return $meta[$id] ?? null; }
function get_post_type(int $id): ?string { global $posts; return $posts[$id]['type'] ?? null; }
function add_option(string $key,mixed $value,...$unused): bool { global $options; if (isset($options[$key])) return false; $options[$key]=$value; return true; }
function delete_option(string $key): void { global $options; unset($options[$key]); }
class WpDbFixture {
    public string $postmeta='postmeta'; private array $params=[];
    public function prepare(string $sql,...$params): string { $this->params=$params; return $sql; }
    public function query(string $sql): void { global $meta; $meta[$this->params[0]]++; }
}
$wpdb=new WpDbFixture();
class WP_REST_Posts_Controller {
    public function __construct(private string $type) {}
    public function get_items_permissions_check($r): bool { return current_user_can('edit_posts'); }
    public function create_item_permissions_check($r): bool { return current_user_can('edit_posts'); }
    public function update_item_permissions_check($r): bool { return current_user_can('edit_posts'); }
    public function delete_item_permissions_check($r): bool { return current_user_can('edit_posts'); }
    public function get_items($r): WP_REST_Response { global $posts; $rows=array_values(array_filter($posts,fn($row)=>$row['type']===$this->type && $row['status']!=='trash')); $response=new WP_REST_Response($rows); $response->header('X-WP-Total',(string)count($rows)); return $response; }
    public function create_item($r): WP_REST_Response {
        global $posts; $id=count($posts)+1; $p=$r->values();
        $posts[$id]=['id'=>$id,'type'=>$this->type,'title'=>['raw'=>$p['title']],'content'=>['raw'=>$p['content']],'slug'=>$p['slug'],'status'=>$p['status'],'plugin_metadata'=>'preserve me'];
        LifeOSWordPressConnector::changed($id); return new WP_REST_Response($posts[$id]);
    }
    public function update_item($r): WP_REST_Response { global $posts; $p=$r->values(); $id=$p['id']; $posts[$id]['title']['raw']=$p['title'];$posts[$id]['content']['raw']=$p['content'];$posts[$id]['slug']=$p['slug'];$posts[$id]['status']=$p['status'];LifeOSWordPressConnector::changed($id);return new WP_REST_Response($posts[$id]); }
    public function delete_item($r): WP_REST_Response { global $posts; $posts[$r->values()['id']]['status']='trash'; return new WP_REST_Response(['deleted'=>true]); }
}
require dirname(__DIR__).'/integrations/website-adapters/wordpress/lifeos-wordpress.php';
$checks=0;
function wp_check(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message);$checks++; }
function wp_call(string $action,array $values=[],string $token=''): WP_REST_Response { return LifeOSWordPressConnector::serve(new WP_REST_Request('POST',['protocol'=>'lifeos-admin-v1','action'=>$action]+$values,$token!=='' ? 'Bearer '.$token : '')); }
wp_check(wp_call('manifest')->status===401,'Missing WordPress session rejected');
$ssl=false; wp_check(wp_call('login',['username'=>'owner','password'=>'application-password'])->status===403,'WordPress requires HTTPS'); $ssl=true;
wp_check(wp_call('login',['username'=>'owner','password'=>'wrong'])->status===401,'Wrong application password rejected');
$login=wp_call('login',['username'=>'owner','password'=>'application-password']); $token=$login->get_data()['token'];
wp_check($login->status===200 && strlen($token)===64,'WordPress login returns a short-lived opaque token');
wp_check(!str_contains(serialize($transients),$token) && !str_contains(serialize($transients),'application-password'),'WordPress retains only token digests, not supplied secrets');
$response=wp_call('manifest',[],$token); wp_check($response->status===200 && array_column($response->get_data()['collections'],'id')===['pages','posts'],'WordPress advertises native page/post collections');
$data=['title'=>'Test page','slug'=>'test-page','body'=>'<!-- wp:paragraph --><p>Original block</p><!-- /wp:paragraph -->','status'=>'draft'];
$response=wp_call('create',['collection'=>'pages','data'=>$data],$token); $record=$response->get_data()['record'];
wp_check($response->status===200 && $posts[1]['content']['raw']===$data['body'],'WordPress body/block markup reaches native controller');
$response=wp_call('update',['collection'=>'pages','id'=>$record['id'],'version'=>$record['version'],'data'=>array_merge($data,['title'=>'Updated'])],$token);
wp_check($response->status===200 && $response->get_data()['record']['version']>$record['version'],'Native save hooks advance the revision');
wp_check($posts[1]['plugin_metadata']==='preserve me','Partial native updates preserve unknown plugin metadata');
wp_check(wp_call('update',['collection'=>'pages','id'=>$record['id'],'version'=>$record['version'],'data'=>$data],$token)->status===409,'Stale WordPress edits rejected');
wp_check(wp_call('list',['collection'=>'posts','offset'=>0,'limit'=>50],$token)->get_data()['total']===0,'Pages are scoped separately from posts');
wp_check(wp_call('list',['collection'=>'pages','offset'=>0,'limit'=>51],$token)->status===422,'WordPress pages are bounded');
wp_check(wp_call('create',['collection'=>'pages','data'=>$data+['unknown'=>'no']],$token)->status===422,'Unknown fields rejected before native writes');
$canEdit=false;wp_check(wp_call('update',['collection'=>'pages','id'=>'1','version'=>3,'data'=>$data],$token)->status===403,'Permissions are rechecked after a role change');$canEdit=true;
wp_check(wp_call('delete',['collection'=>'pages','id'=>'1','version'=>3],$token)->status===200 && $posts[1]['status']==='trash','Delete uses recoverable WordPress Trash');
wp_check(wp_call('logout',[],$token)->get_data()['logged_out']===true && wp_call('manifest',[],$token)->status===401,'Logout revokes the bearer session');
$token=wp_call('login',['username'=>'owner','password'=>'application-password'])->get_data()['token'];$appRevoked=true;
wp_check(wp_call('manifest',[],$token)->status===401,'Revoked application passwords invalidate active sessions');
echo $checks." WordPress adapter contract checks passed.\n";
