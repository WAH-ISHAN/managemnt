<?php
declare(strict_types=1);

/** Website-local accounts. Passwords and bearer secrets never enter the content API. */
final class LifeOSConnectorAccounts {
    private PDO $db;
    public function __construct(string $path) {
        $this->db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
        $this->db->exec('CREATE TABLE IF NOT EXISTS lifeos_users(username TEXT PRIMARY KEY,password_hash TEXT NOT NULL,permissions TEXT NOT NULL,enabled INTEGER NOT NULL DEFAULT 1);
            CREATE TABLE IF NOT EXISTS lifeos_sessions(token_hash TEXT PRIMARY KEY,username TEXT NOT NULL REFERENCES lifeos_users(username) ON DELETE CASCADE,expires_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS lifeos_login_limits(bucket TEXT PRIMARY KEY,attempts INTEGER NOT NULL,expires_at INTEGER NOT NULL);');
        if (PHP_OS_FAMILY!=='Windows') @chmod($path,0600);
    }
    public function provision(string $username,string $role,array $collections): string {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.@-]{0,119}$/D',$username)) throw new InvalidArgumentException('Use a username of up to 120 letters, numbers, dots, @, hyphens or underscores.');
        $actions=match($role) {'viewer'=>['list'],'editor'=>['list','create','update'],'admin'=>['list','create','update','delete'],default=>throw new InvalidArgumentException('Choose viewer, editor or admin.')};
        if (!$collections || array_diff($collections,['pages','products'])) throw new InvalidArgumentException('Choose pages and/or products.');
        $permissions=array_fill_keys(array_unique($collections),$actions);
        $password=bin2hex(random_bytes(16));
        $this->db->beginTransaction();
        try {
            $query=$this->db->prepare('INSERT INTO lifeos_users(username,password_hash,permissions,enabled) VALUES (?,?,?,1) ON CONFLICT(username) DO UPDATE SET password_hash=excluded.password_hash,permissions=excluded.permissions,enabled=1');
            $query->execute([$username,password_hash($password,PASSWORD_DEFAULT),json_encode($permissions,JSON_THROW_ON_ERROR)]);
            $this->revoke($username); $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        return $password;
    }
    private function revoke(string $username): void {
        $query=$this->db->prepare('DELETE FROM lifeos_sessions WHERE username=?'); $query->execute([$username]);
    }
    public function disable(string $username): void {
        $this->db->beginTransaction();
        try {
            $query=$this->db->prepare('UPDATE lifeos_users SET enabled=0 WHERE username=?'); $query->execute([$username]);
            $this->revoke($username); $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    public function login(array $request,string $ip): array {
        if (($request['protocol'] ?? null)!=='lifeos-admin-v1' || array_diff(array_keys($request),['protocol','action','username','password'])) throw new LifeOSConnectorError(422,'Invalid login request.');
        $username=$request['username'] ?? null; $password=$request['password'] ?? null;
        if (!is_string($username) || $username==='' || strlen($username)>120 || !is_string($password) || $password==='' || strlen($password)>4096) throw new LifeOSConnectorError(401,'Website username or password is incorrect.');
        $now=time();
        $this->db->exec('DELETE FROM lifeos_login_limits WHERE expires_at<'.(int)$now.'; DELETE FROM lifeos_sessions WHERE expires_at<'.(int)$now);
        $buckets=['ip:'.hash('sha256',$ip)=>20,'user:'.hash('sha256',$username)=>5];
        // Reserve attempts atomically so concurrent requests cannot bypass limits.
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            foreach ($buckets as $bucket=>$limit) {
                $query=$this->db->prepare('SELECT attempts FROM lifeos_login_limits WHERE bucket=?'); $query->execute([$bucket]);
                if ((int)$query->fetchColumn()>=$limit) throw new LifeOSConnectorError(429,'Too many login attempts. Try again in 15 minutes.');
                $query=$this->db->prepare('INSERT INTO lifeos_login_limits(bucket,attempts,expires_at) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET attempts=attempts+1'); $query->execute([$bucket,$now+900]);
            }
            $this->db->exec('COMMIT');
        } catch (Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
        $query=$this->db->prepare('SELECT * FROM lifeos_users WHERE username=?'); $query->execute([$username]); $user=$query->fetch();
        // Fixed valid dummy hash keeps unknown usernames on the password verification path.
        $hash=$user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if (!password_verify($password,$hash) || !$user || !(int)$user['enabled']) throw new LifeOSConnectorError(401,'Website username or password is incorrect.');
        $query=$this->db->prepare('DELETE FROM lifeos_login_limits WHERE bucket=?'); $query->execute(['user:'.hash('sha256',$username)]);
        $token=bin2hex(random_bytes(32)); $expires=$now+1800;
        $query=$this->db->prepare('INSERT INTO lifeos_sessions(token_hash,username,expires_at) VALUES (?,?,?)'); $query->execute([hash('sha256',$token),$username,$expires]);
        return ['protocol'=>'lifeos-admin-v1','token'=>$token,'expires_at'=>$expires,'username'=>$username];
    }
    public function authenticate(string $token): array {
        $query=$this->db->prepare('SELECT u.permissions FROM lifeos_sessions s JOIN lifeos_users u ON u.username=s.username WHERE s.token_hash=? AND s.expires_at>? AND u.enabled=1');
        $query->execute([hash('sha256',$token),time()]); $user=$query->fetch();
        if (!$user) throw new LifeOSConnectorError(401,'Website session expired. Sign in again.');
        return json_decode($user['permissions'],true,32,JSON_THROW_ON_ERROR);
    }
    public function logout(string $token): array {
        $query=$this->db->prepare('DELETE FROM lifeos_sessions WHERE token_hash=?'); $query->execute([hash('sha256',$token)]);
        return ['protocol'=>'lifeos-admin-v1','logged_out'=>true];
    }
}
