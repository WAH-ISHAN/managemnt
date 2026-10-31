<?php
declare(strict_types=1);
final class Auth {
    private static function instance(): string { return getenv('LIFEOS_INSTANCE') ?: 'development-'.getmypid(); }
    public static function guard(Router $r): void {
        $r->before(function (string $method, string $path) {
            if (!in_array($method,['GET','POST'],true)) { http_response_code(405); exit('Method not allowed'); }
            if ($method === 'POST') {
                $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
                if ($origin !== '' && $origin !== 'http://' . ($_SERVER['HTTP_HOST'] ?? '')) { http_response_code(403); exit('Invalid origin'); }
                $token = $_POST['_csrf'] ?? '';
                if (!is_string($token) || !hash_equals($_SESSION['csrf'],$token)) { http_response_code(419); exit('Session expired. Reload the page and try again.'); }
            }
            if ($path === '/unlock') return;
            $hash = Db::setting('pin_hash');
            if ($hash !== '' && (($_SESSION['auth_hash'] ?? '') !== $hash || ($_SESSION['auth_instance'] ?? '') !== self::instance())) { echo redirect('/unlock'); exit; }
        });
        $r->get('/unlock', fn() => View::render(ROOT . '/app/Core/views/unlock.php', ['title'=>'Unlock LifeOS'], 'lock'));
        $r->post('/unlock', function () {
            $until = (int)Db::setting('pin_blocked_until','0');
            if (time() < $until) throw new InvalidArgumentException('Too many attempts. Try again in a minute.');
            $hash = Db::setting('pin_hash'); $pin = (string)($_POST['pin'] ?? '');
            if ($hash !== '' && !password_verify($pin,$hash)) {
                $tries = (int)Db::setting('pin_attempts','0') + 1;
                Db::set('pin_attempts',(string)($tries >= 5 ? 0 : $tries));
                if ($tries >= 5) Db::set('pin_blocked_until',(string)(time()+60));
                throw new InvalidArgumentException('That PIN did not match. Please try again.');
            }
            Db::set('pin_attempts','0'); session_regenerate_id(true); $_SESSION['auth_hash'] = $hash; $_SESSION['auth_instance']=self::instance(); $_SESSION['csrf'] = bin2hex(random_bytes(32));
            return redirect('/');
        });
        $r->post('/lock', function () { unset($_SESSION['auth_hash']); if(class_exists('WebsiteService')) WebsiteService::disconnectAll(); session_regenerate_id(true); return redirect('/unlock'); });
    }
    public static function changePin(): void {
        $hash = Db::setting('pin_hash');
        if ($hash !== '' && !password_verify((string)($_POST['current_pin'] ?? ''),$hash)) throw new InvalidArgumentException('Current PIN is incorrect.');
        $pin = (string)($_POST['pin'] ?? '');
        if ($pin !== '' && !preg_match('/^\d{4,12}$/',$pin)) throw new InvalidArgumentException('Use a PIN with 4 to 12 digits, or leave it empty to remove the lock.');
        if ($pin !== (string)($_POST['pin_confirm'] ?? '')) throw new InvalidArgumentException('The new PINs must match.');
        $new = $pin === '' ? '' : password_hash($pin,PASSWORD_DEFAULT); Db::set('pin_hash',$new);
        if(class_exists('WebsiteService')) WebsiteService::disconnectAll();
        session_regenerate_id(true); $_SESSION['auth_hash'] = $new; $_SESSION['auth_instance']=self::instance();
    }
}
