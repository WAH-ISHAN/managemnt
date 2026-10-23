<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !preg_match('/^127\.0\.0\.1:\d+$/', (string)($_SERVER['HTTP_HOST'] ?? ''))) {
    http_response_code(400);
    exit('Invalid host.');
}
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'");
session_name('crab_session');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

function db(): PDO
{
    static $connection;
    if ($connection instanceof PDO) return $connection;
    $directory = getenv('CRAB_DATA_DIR') ?: dirname(__DIR__, 2) . '/.crab-local';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Cannot create data folder.');
    $connection = new PDO('sqlite:' . $directory . '/crab.sqlite');
    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $connection->exec('PRAGMA foreign_keys = ON');
    $connection->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $columns = $connection->query('PRAGMA table_info(users)')->fetchAll();
    if (!in_array('role', array_column($columns, 'name'), true)) $connection->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
    if ((int)$connection->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() === 0) $connection->exec("UPDATE users SET role = 'admin' WHERE id = (SELECT MIN(id) FROM users)");
    $connection->exec("CREATE TABLE IF NOT EXISTS grants (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, module TEXT NOT NULL CHECK(module IN ('calendar','exams','targets','tasks','salary','recovery')), PRIMARY KEY(user_id,module))");
    $connection->exec('CREATE TABLE IF NOT EXISTS login_attempts (email TEXT PRIMARY KEY, attempts INTEGER NOT NULL, last_attempt INTEGER NOT NULL)');
    $connection->exec("CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, kind TEXT NOT NULL CHECK(kind IN ('task','exam','target','event')), title TEXT NOT NULL, details TEXT NOT NULL DEFAULT '', due_at TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','done','failed')), progress INTEGER NOT NULL DEFAULT 0, points INTEGER NOT NULL DEFAULT 10, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $connection->exec("CREATE TABLE IF NOT EXISTS money (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, kind TEXT NOT NULL CHECK(kind IN ('salary','expense')), label TEXT NOT NULL, amount REAL NOT NULL CHECK(amount > 0), entry_date TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $connection->exec('CREATE INDEX IF NOT EXISTS items_user_due ON items(user_id, due_at)');
    $connection->exec('CREATE INDEX IF NOT EXISTS money_user_date ON money(user_id, entry_date)');
    return $connection;
}

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    $query = db()->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
    $query->execute([(int)$_SESSION['user_id']]);
    return $query->fetch() ?: null;
}
function redirect(string $page = 'overview'): never { header('Location: /?page=' . rawurlencode($page)); exit; }
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'] = ['message' => $message, 'type' => $type]; }
function validDate(string $value): bool { $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $date !== false && $date->format('Y-m-d') === $value; }
function rows(string $sql, array $params = []): array { $query = db()->prepare($sql); $query->execute($params); return $query->fetchAll(); }
function allowed(array $user, string $module): bool
{
    if ($user['role'] === 'admin' || $module === 'overview') return true;
    if ($module === 'recovery') return count(rows("SELECT 1 FROM grants WHERE user_id = ? AND module IN ('tasks','exams','targets','calendar') LIMIT 1", [(int)$user['id']])) > 0;
    return count(rows('SELECT 1 FROM grants WHERE user_id = ? AND module = ? LIMIT 1', [(int)$user['id'], $module])) > 0;
}

function handlePost(): void
{
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Invalid form token. Refresh the page and try again.'); }
    $action = (string)($_POST['action'] ?? '');
    $page = (string)($_POST['return_page'] ?? 'overview');
    if (!in_array($page, ['overview','calendar','exams','targets','tasks','salary','recovery','admin'], true)) $page = 'overview';
    try {
        if ($action === 'register') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) throw new DomainException('Enter a name, valid email, and a password of at least 8 characters.');
            db()->beginTransaction();
            try {
                $count = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
                $query = db()->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)');
                $query->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $count === 0 ? 'admin' : 'user']);
                db()->commit();
            } catch (Throwable $error) { db()->rollBack(); throw $error; }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)db()->lastInsertId();
            flash('Welcome to Crab, ' . $name . '.');
            redirect();
        }
        if ($action === 'login') {
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $attempt = rows('SELECT attempts,last_attempt FROM login_attempts WHERE email = ?', [$email])[0] ?? null;
            if ($attempt && (int)$attempt['attempts'] >= 5 && (int)$attempt['last_attempt'] > time() - 300) throw new DomainException('Too many sign-in attempts. Try again in five minutes.');
            $user = rows('SELECT id,password_hash FROM users WHERE email = ?', [$email])[0] ?? null;
            if (!$user || !password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
                db()->prepare('INSERT INTO login_attempts (email,attempts,last_attempt) VALUES (?,1,?) ON CONFLICT(email) DO UPDATE SET attempts = CASE WHEN last_attempt < ? THEN 1 ELSE attempts + 1 END, last_attempt = excluded.last_attempt')->execute([$email,time(),time()-300]);
                throw new DomainException('Email or password is incorrect.');
            }
            db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            redirect();
        }
        if ($action === 'logout') { $_SESSION = []; session_regenerate_id(true); redirect(); }
        $user = currentUser();
        if (!$user) { http_response_code(401); exit('Please sign in.'); }
        $userId = (int)$user['id'];
        if ($action === 'grant') {
            if ($user['role'] !== 'admin') { http_response_code(403); exit('Admin access required.'); }
            $targetId = (int)($_POST['user_id'] ?? 0);
            $module = (string)($_POST['module'] ?? '');
            $enabled = (string)($_POST['enabled'] ?? '');
            if (!in_array($module, ['calendar','exams','targets','tasks','salary'], true) || !in_array($enabled, ['0','1'], true)) throw new DomainException('Invalid access change.');
            $target = rows("SELECT id FROM users WHERE id = ? AND role = 'user'", [$targetId])[0] ?? null;
            if (!$target) throw new DomainException('User was not found.');
            if ($enabled === '1') db()->prepare('INSERT OR IGNORE INTO grants (user_id,module) VALUES (?,?)')->execute([$targetId,$module]);
            else db()->prepare('DELETE FROM grants WHERE user_id = ? AND module = ?')->execute([$targetId,$module]);
            flash('Access updated.'); redirect('admin');
        }
        if ($action === 'add_item') {
            $kind = (string)($_POST['kind'] ?? '');
            $title = trim((string)($_POST['title'] ?? ''));
            $details = trim((string)($_POST['details'] ?? ''));
            $due = (string)($_POST['due_at'] ?? '');
            $points = (int)($_POST['points'] ?? 10);
            $module = ['task'=>'tasks','exam'=>'exams','target'=>'targets','event'=>'calendar'][$kind] ?? '';
            if (!allowed($user, $module)) { http_response_code(403); exit('This module is locked.'); }
            if (!in_array($kind, ['task','exam','target','event'], true) || $title === '' || mb_strlen($title) > 150 || mb_strlen($details) > 1000 || !validDate($due) || $points < 0 || $points > 1000) throw new DomainException('Check the title, date, and points.');
            $query = db()->prepare('INSERT INTO items (user_id,kind,title,details,due_at,points) VALUES (?,?,?,?,?,?)');
            $query->execute([$userId, $kind, $title, $details, $due, $points]);
            flash('Added successfully.');
        } elseif ($action === 'update_item') {
            $id = (int)($_POST['id'] ?? 0);
            $operation = (string)($_POST['operation'] ?? '');
            $item = rows('SELECT id,kind FROM items WHERE id = ? AND user_id = ?', [$id, $userId])[0] ?? null;
            if (!$item) throw new DomainException('Item was not found.');
            if (!allowed($user, ['task'=>'tasks','exam'=>'exams','target'=>'targets','event'=>'calendar'][$item['kind']])) { http_response_code(403); exit('This module is locked.'); }
            if ($operation === 'done' || $operation === 'failed' || $operation === 'reopen') {
                $status = $operation === 'reopen' ? 'open' : $operation;
                db()->prepare("UPDATE items SET status = ?, progress = CASE WHEN ? = 'done' THEN 100 WHEN ? = 'open' AND kind = 'target' AND progress = 100 THEN 99 ELSE progress END WHERE id = ? AND user_id = ?")->execute([$status, $status, $status, $id, $userId]);
            } elseif ($operation === 'reschedule') {
                $due = (string)($_POST['due_at'] ?? '');
                if (!validDate($due)) throw new DomainException('Choose a valid new date.');
                db()->prepare("UPDATE items SET due_at = ?, status = 'open' WHERE id = ? AND user_id = ?")->execute([$due, $id, $userId]);
            } elseif ($operation === 'progress' && $item['kind'] === 'target') {
                $progress = (int)($_POST['progress'] ?? -1);
                if ($progress < 0 || $progress > 100) throw new DomainException('Progress must be between 0 and 100.');
                db()->prepare("UPDATE items SET progress = ?, status = CASE WHEN ? = 100 THEN 'done' ELSE 'open' END WHERE id = ? AND user_id = ?")->execute([$progress, $progress, $id, $userId]);
            } elseif ($operation === 'edit') {
                $title = trim((string)($_POST['title'] ?? ''));
                $details = trim((string)($_POST['details'] ?? ''));
                $due = (string)($_POST['due_at'] ?? '');
                $points = filter_var($_POST['points'] ?? null, FILTER_VALIDATE_INT);
                if ($title === '' || mb_strlen($title) > 150 || mb_strlen($details) > 1000 || !validDate($due) || $points === false || $points < 0 || $points > 1000) throw new DomainException('Check the title, date, notes, and points.');
                db()->prepare('UPDATE items SET title = ?, details = ?, due_at = ?, points = ? WHERE id = ? AND user_id = ?')->execute([$title,$details,$due,$points,$id,$userId]);
            } elseif ($operation === 'delete') {
                db()->prepare('DELETE FROM items WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            } else throw new DomainException('Unknown action.');
            flash('Updated successfully.');
        } elseif ($action === 'add_money') {
            if (!allowed($user, 'salary')) { http_response_code(403); exit('This module is locked.'); }
            $kind = (string)($_POST['kind'] ?? '');
            $label = trim((string)($_POST['label'] ?? ''));
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            $date = (string)($_POST['entry_date'] ?? '');
            if (!in_array($kind, ['salary','expense'], true) || $label === '' || mb_strlen($label) > 150 || $amount === false || $amount <= 0 || $amount > 1000000000 || !validDate($date)) throw new DomainException('Check the description, amount, and date.');
            db()->prepare('INSERT INTO money (user_id,kind,label,amount,entry_date) VALUES (?,?,?,?,?)')->execute([$userId, $kind, $label, $amount, $date]);
            flash('Money entry added.');
        } elseif ($action === 'delete_money') {
            if (!allowed($user, 'salary')) { http_response_code(403); exit('This module is locked.'); }
            db()->prepare('DELETE FROM money WHERE id = ? AND user_id = ?')->execute([(int)($_POST['id'] ?? 0), $userId]);
            flash('Money entry deleted.');
        } elseif ($action === 'edit_money') {
            if (!allowed($user, 'salary')) { http_response_code(403); exit('This module is locked.'); }
            $id = (int)($_POST['id'] ?? 0);
            $kind = (string)($_POST['kind'] ?? '');
            $label = trim((string)($_POST['label'] ?? ''));
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            $date = (string)($_POST['entry_date'] ?? '');
            if (!in_array($kind, ['salary','expense'], true) || $label === '' || mb_strlen($label) > 150 || $amount === false || $amount <= 0 || $amount > 1000000000 || !validDate($date)) throw new DomainException('Check the description, amount, and date.');
            db()->prepare('UPDATE money SET kind = ?, label = ?, amount = ?, entry_date = ? WHERE id = ? AND user_id = ?')->execute([$kind,$label,$amount,$date,$id,$userId]);
            flash('Money entry updated.');
        } else throw new DomainException('Unknown action.');
    } catch (PDOException $error) {
        flash($error->getCode() === '23000' ? 'This email is already registered.' : 'Could not save your changes.', 'error');
    } catch (DomainException $error) { flash($error->getMessage(), 'error'); }
    redirect($page);
}
