<?php
declare(strict_types=1);
final class Auth {
    private static function instance(): string { return getenv('LIFEOS_INSTANCE') ?: 'development-'.getmypid(); }
    public static function guard(Router $r): void {
        $r->before(function (string $method, string $path) {
            if (str_starts_with($path,'/api/v1/')) {
                header('Content-Type: application/json; charset=utf-8');
                if (!class_exists('ConnectivityService') || !module_enabled('Connectivity') || (class_exists('ProfileManager') && ProfileManager::isKid())) { http_response_code(403); exit('{"error":"API disabled for this profile"}'); }
                try { ConnectivityService::authorizeApi(); }
                catch (InvalidArgumentException $error) { http_response_code(401); echo json_encode(['error'=>$error->getMessage()],JSON_THROW_ON_ERROR); exit; }
                return;
            }
            if (!in_array($method,['GET','POST'],true)) { http_response_code(405); exit('Method not allowed'); }
            if ($method === 'POST') {
                $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
                if ($origin !== '' && $origin !== 'http://' . ($_SERVER['HTTP_HOST'] ?? '')) { http_response_code(403); exit('Invalid origin'); }
                $token = $_POST['_csrf'] ?? '';
                if (!is_string($token) || !hash_equals($_SESSION['csrf'],$token)) { http_response_code(419); exit('Session expired. Reload the page and try again.'); }
            }
            if (in_array($path,['/unlock','/unlock/hello','/profiles','/profiles/switch'],true)) return;
            $idle=(int)Db::setting('platform_idle_seconds','0');
            if($idle>0 && isset($_SESSION['platform_activity']) && time()-(int)$_SESSION['platform_activity']>=$idle){unset($_SESSION['auth_hash']);}
            $_SESSION['platform_activity']??=time();
            $hash = Db::setting('pin_hash');
            if ($hash !== '' && (($_SESSION['auth_hash'] ?? '') !== $hash || ($_SESSION['auth_instance'] ?? '') !== self::instance())) { echo redirect('/unlock'); exit; }
        });
        $r->get('/unlock', fn() => View::render(ROOT . '/app/Core/views/unlock.php', ['title'=>t('Unlock LifeOS')], 'lock'));
        $r->post('/unlock', function () {
            $until = (int)Db::setting('pin_blocked_until','0');
            if (time() < $until) throw new InvalidArgumentException(t('Too many attempts. Try again in a minute.'));
            $hash = Db::setting('pin_hash'); $pin = (string)($_POST['pin'] ?? '');
            if ($hash !== '' && !password_verify($pin,$hash)) {
                $tries = (int)Db::setting('pin_attempts','0') + 1;
                Db::set('pin_attempts',(string)($tries >= 5 ? 0 : $tries));
                if ($tries >= 5) Db::set('pin_blocked_until',(string)(time()+60));
                throw new InvalidArgumentException(t('That PIN did not match. Please try again.'));
            }
            Db::set('pin_attempts','0'); session_regenerate_id(true); $_SESSION['auth_hash'] = $hash; $_SESSION['auth_instance']=self::instance(); $_SESSION['csrf'] = bin2hex(random_bytes(32)); $_SESSION['platform_activity']=time();
            return redirect('/');
        });
        $r->post('/lock', function () { unset($_SESSION['auth_hash']); if(class_exists('WebsiteService')) WebsiteService::disconnectAll(); session_regenerate_id(true); return redirect('/unlock'); });
        $r->get('/idle-lock',function(){unset($_SESSION['auth_hash']);if(class_exists('WebsiteService'))WebsiteService::disconnectAll();session_regenerate_id(true);return redirect('/unlock');});
        $r->post('/session/activity',function(){$_SESSION['platform_activity']=time();return '';});
        $r->post('/unlock/hello',function(){
            $nonce=$_SESSION['platform_hello_challenge']??'';$expires=$_SESSION['platform_hello_expires']??0;unset($_SESSION['platform_hello_challenge'],$_SESSION['platform_hello_expires']);$key=getenv('LIFEOS_HELLO_KEY')?:'';$proof=$_POST['proof']??'';
            if(Db::setting('platform_hello','0')!=='1'||$expires<time()||strlen($nonce)!==64||!preg_match('/^[A-Fa-f0-9]{64}$/D',$key)||!is_string($proof)||!hash_equals(hash_hmac('sha256',ProfileManager::id().':'.$nonce,$key),$proof))throw new InvalidArgumentException(t('Windows Hello proof expired or failed. Use your PIN.'));
            session_regenerate_id(true);$_SESSION['auth_hash']=Db::setting('pin_hash');$_SESSION['auth_instance']=self::instance();$_SESSION['platform_activity']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));return redirect('/');
        });
    }
    public static function unlocked(): bool {
        $hash=Db::setting('pin_hash');
        return $hash==='' || (($_SESSION['auth_hash'] ?? '')===$hash && ($_SESSION['auth_instance'] ?? '')===self::instance());
    }
    public static function changePin(): void {
        $hash = Db::setting('pin_hash');
        if ($hash !== '' && !password_verify((string)($_POST['current_pin'] ?? ''),$hash)) throw new InvalidArgumentException(t('Current PIN is incorrect.'));
        $pin = (string)($_POST['pin'] ?? '');
        if ($pin==='' && class_exists('ProfileManager') && count(ProfileManager::profiles())>1) throw new InvalidArgumentException(t('Keep a PIN on every profile while multiple profiles exist.'));
        if ($pin !== '' && !preg_match('/^\d{4,12}$/',$pin)) throw new InvalidArgumentException(t('Use a PIN with 4 to 12 digits, or leave it empty to remove the lock.'));
        if ($pin !== (string)($_POST['pin_confirm'] ?? '')) throw new InvalidArgumentException(t('The new PINs must match.'));
        $new = $pin === '' ? '' : password_hash($pin,PASSWORD_DEFAULT); Db::set('pin_hash',$new);
        if(class_exists('WebsiteService')) WebsiteService::disconnectAll();
        session_regenerate_id(true); $_SESSION['auth_hash'] = $new; $_SESSION['auth_instance']=self::instance();
    }
}
