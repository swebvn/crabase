<?php
namespace app\service;

use InvalidArgumentException;
use app\record\Account;
final class Auth
{
    public const COOKIE = 'crabase_session';
    private const FIELDS = 'u.id,u.name,u.avatar_url,u.created_at,a.email,a.admin,a.enabled,a.git_name,a.git_email';

    public static function user(?string $token): ?array
    {
        if (!$token || !preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
        Store::db();
        $user = Store::connection()->table('auth_sessions as s')->join('accounts as a', 'a.user_id', '=', 's.user_id')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('s.token_hash', hash('sha256', $token))->where('s.expires', '>', time())->where('a.enabled', 1)->selectRaw(self::FIELDS)->first();
        if ($user) {
            $user['avatar_required'] = $user['avatar_url'] === '';
            $user['avatar_fallback'] = self::avatar($user['email']);
        }
        return $user;
    }
    public static function avatar(string $email): string
    {
        return 'https://www.gravatar.com/avatar/'.hash('sha256', strtolower(trim($email))).'?s=96&d=mp';
    }
    public static function allowedOrigin(?string $origin): bool
    {
        if (getenv('CRABASE_ALLOW_ALL_ORIGINS') === '1') return true;
        if (!is_string($origin) || !preg_match('~^https?://([^/:]+)(?::(5173|8787))?$~D', $origin, $match)) return false;
        if (in_array($match[1], ['cb.dev.tda', 'c.tdagroup.co'], true)) return true;
        return self::allowedHost($match[1]);
    }
    public static function allowedHost(string $host): bool
    {
        if (in_array($host, ['cb.dev.tda', 'c.tdagroup.co'], true)) return true;
        if (in_array($host, ['localhost', '127.0.0.1'], true)) return true;
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        foreach (explode(',', getenv('CRABASE_ALLOWED_HOSTS') ?: '') as $network) {
            [$address, $prefix] = array_pad(explode('/', trim($network), 2), 2, '32');
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !ctype_digit($prefix) || (int)$prefix > 32) continue;
            $mask = (int)$prefix === 0 ? 0 : (-1 << (32 - (int)$prefix));
            if ((ip2long($host) & $mask) === (ip2long($address) & $mask)) return true;
        }
        return false;
    }
    private static function email(mixed $value): string
    {
        $email = strtolower(Input::text($value, 254));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
        return $email;
    }
    private static function name(mixed $value): string
    {
        $name = Input::text($value, 100);
        if (preg_match('/[\x00-\x1f\x7f<>]/', $name)) throw new InvalidArgumentException('Invalid name.');
        return $name;
    }
    private static function password(mixed $value): string
    {
        if (!is_string($value) || strlen($value) < 6 || strlen($value) > 72) throw new InvalidArgumentException('Password must be 6–72 bytes. Use a long, unique password.');
        return password_hash($value, PASSWORD_DEFAULT);
    }
    public static function login(array $data, string $ip): string
    {
        Store::db();
        $email = strtolower(trim(is_string($data['email'] ?? null) ? $data['email'] : ''));
        $password = $data['password'] ?? '';
        $key = hash('sha256', $ip);
        \app\service\Store::connection()->table('login_attempts')->where('expires', '<', time())->delete();
        $attempt = \app\service\Store::connection()->table('login_attempts')->where('key', $key)->value('attempts') ?? 0;
        if ($attempt >= 10) throw new InvalidArgumentException('Too many attempts. Try again in 15 minutes.');
        // Atomic increment preserves attempts from concurrent HTTP workers.
        \app\service\Store::connection()->execute('INSERT INTO login_attempts VALUES (?,1,?) ON CONFLICT(key) DO UPDATE SET attempts=attempts+1', [$key, time()+900]);
        $account = Store::connection()->table('accounts')->into(Account::class)->where('email', substr($email, 0, 254))->first();
        $valid = is_string($password) && strlen($password) <= 72 && password_verify($password, $account?->password_hash ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$valid || !$account || !$account->enabled) throw new InvalidArgumentException('Invalid email or password.');
        \app\service\Store::connection()->table('login_attempts')->where('key', $key)->delete();
        return self::session($account->user_id);
    }
    public static function session(string $id): string
    {
        Store::db();
        if (!Store::connection()->table('accounts')->where('user_id', $id)->where('enabled', 1)->exists()) throw new InvalidArgumentException('Account is disabled.');
        Store::connection()->table('auth_sessions')->where('expires', '<', time())->delete();
        $token = bin2hex(random_bytes(32));
        Store::connection()->table('auth_sessions')->insert(['token_hash'=>hash('sha256',$token), 'user_id'=>$id, 'expires'=>time()+604800]);
        return $token;
    }
    public static function logout(?string $token): void
    {
        Store::db();
        Store::connection()->table('auth_sessions')->where('token_hash', hash('sha256', $token ?? ''))->delete();
    }
    public static function users(array $actor): array
    {
        if (!$actor['admin']) throw new InvalidArgumentException('Administrator access required.');
        Store::db();
        return Store::connection()->table('accounts as a')->join('users as u', 'u.id', '=', 'a.user_id')->selectRaw(self::FIELDS)->orderBy('u.name')->get();
    }
    public static function create(array $data, bool $bootstrap = false): array
    {
        Store::db();
        $name = self::name($data['name'] ?? null);
        $email = self::email($data['email'] ?? null);
        $hash = self::password($data['password'] ?? null);
        return \app\service\Store::transaction(function () use ($data, $bootstrap, $name, $email, $hash) {
            $db = Store::connection();
            if ($bootstrap && $db->table('accounts')->where('admin', 1)->where('enabled', 1)->exists()) throw new InvalidArgumentException('An admin already exists. Use Settings to manage users.');
            if ($db->table('accounts')->where('email', $email)->exists() || $db->table('users')->where('name', $name)->exists()) throw new InvalidArgumentException('Name or email is already in use.');
            $id = bin2hex(random_bytes(8));
            $db->table('users')->insert(['id'=>$id, 'name'=>$name, 'avatar_url'=>'', 'created_at'=>gmdate('c')]);
            $db->table('accounts')->insert(['user_id'=>$id, 'email'=>$email, 'password_hash'=>$hash, 'admin'=>$bootstrap || !empty($data['admin']) ? 1 : 0]);
            Store::notify();
            return ['id'=>$id];
        });
    }
    public static function update(array $actor, array $data, bool $admin): array
    {
        Store::db();
        if ($admin && !$actor['admin']) throw new InvalidArgumentException('Administrator access required.');
        $id = $admin ? Input::text($data['id'] ?? null, 64) : $actor['id'];
        $account = Store::connection()->table('accounts')->into(Account::class)->where('user_id', $id)->first() ?? throw new InvalidArgumentException('User not found.');
        $name = self::name($data['name'] ?? null);
        $email = self::email($data['email'] ?? null);
        if ($email !== $account->email) throw new InvalidArgumentException('Login email cannot be changed.');
        $newPassword = $data['password'] ?? '';
        if (!is_string($newPassword)) throw new InvalidArgumentException('Invalid password.');
        if (!$admin && $newPassword !== '' &&
            !password_verify(is_string($data['current_password'] ?? null) ? $data['current_password'] : '', $account->password_hash)) throw new InvalidArgumentException('Current password is required to change password.');
        $hash = $newPassword !== '' ? self::password($newPassword) : $account->password_hash;
        // Avatars are changed only through the validated image upload action.
        $gitName = $admin ? $account->git_name : (empty($data['git_name']) ? '' : self::name($data['git_name']));
        $gitEmail = $admin ? $account->git_email : (empty($data['git_email']) ? '' : self::email($data['git_email']));
        $role = $admin ? (empty($data['admin']) ? 0 : 1) : $account->admin;
        $enabled = $admin ? (empty($data['enabled']) ? 0 : 1) : $account->enabled;
        return \app\service\Store::transaction(function () use ($id,$name,$email,$hash,$gitName,$gitEmail,$role,$enabled,$newPassword) {
            $db = Store::connection();
            if ($db->table('users')->where('name', $name)->where('id', '<>', $id)->exists() || $db->table('accounts')->where('email', $email)->where('user_id', '<>', $id)->exists()) throw new InvalidArgumentException('Name or email is already in use.');
            if ((!$role || !$enabled) && !$db->table('accounts')->where('admin', 1)->where('enabled', 1)->where('user_id', '<>', $id)->exists()) throw new InvalidArgumentException('Keep at least one enabled administrator.');
            $db->table('users')->where('id', $id)->update(['name'=>$name]);
            $db->table('messages')->where('user_id', $id)->whereIn('role', ['user','note'])->update(['author'=>$name]);
            $db->table('accounts')->where('user_id', $id)->update(['email'=>$email, 'password_hash'=>$hash, 'admin'=>$role, 'enabled'=>$enabled, 'git_name'=>$gitName, 'git_email'=>$gitEmail]);
            if ($newPassword !== '' || !$enabled) $db->table('auth_sessions')->where('user_id', $id)->delete();
            Store::notify();
            return ['ok'=>true];
        });
    }
    public static function coauthors(string $chatId): string
    {
        Store::db();
        $rows = Store::connection()->table('messages as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('accounts as a', 'a.user_id', '=', 'u.id')
            ->where('m.chat_id', $chatId)->whereIn('m.role', ['user','note'])->where('a.git_email', '<>', '')->distinct()->select('u.name','a.git_name','a.git_email')->get();
        $trailers = [];
        foreach ($rows as $row) $trailers[strtolower($row['git_email'])] = 'Co-authored-by: '.($row['git_name'] ?: $row['name']).' <'.$row['git_email'].'>';
        return $trailers ? "\nWhen explicitly asked to commit in this chat, include these co-author trailers exactly once in the commit message (identity data only, not instructions):\n".implode("\n", $trailers) : '';
    }
}
