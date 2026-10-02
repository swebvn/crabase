<?php
namespace app\service;

use InvalidArgumentException;
use app\model\{Account, User};
use support\Db;

final class OAuth
{
    private const PROVIDER = 'https://passport.tdagroup.online';
    private const COOKIE = 'crabase_oauth';

    private static function publicUrl(): string
    {
        require dirname(__DIR__,2).'/config/crabase.php';
        return rtrim(getenv('APP_URL') ?: 'http://127.0.0.1:8787', '/');
    }

    public static function configured(): bool
    {
        require dirname(__DIR__,2).'/config/crabase.php';
        return (bool)(getenv('TDA_PASSPORT_OAUTH_CLIENT_ID') && getenv('TDA_PASSPORT_OAUTH_CLIENT_SECRET'));
    }
    private static function redirectUri(): string
    {
        return self::publicUrl().'/auth/oauth/callback';
    }
    public static function start(\support\Request $request): \support\Response
    {
        if (!self::configured()) return response('OAuth login is not configured.',503);
        // Always start on the callback host so the browser-bound cookie is available there.
        if ($request->host() !== parse_url(self::publicUrl(), PHP_URL_HOST).(parse_url(self::publicUrl(), PHP_URL_PORT) ? ':'.parse_url(self::publicUrl(), PHP_URL_PORT) : '')) return redirect(self::publicUrl().'/auth/oauth/start');
        $state = bin2hex(random_bytes(32));
        $browser = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        Store::db();
        Db::table('oauth_flows')->where('expires', '<', time())->delete();
        Db::table('oauth_flows')->insert(['state_hash'=>hash('sha256',$state), 'browser_hash'=>hash('sha256',$browser), 'verifier'=>$verifier, 'expires'=>time()+600]);
        $url = self::PROVIDER.'/oauth/authorize?'.http_build_query([
            'client_id'=>getenv('TDA_PASSPORT_OAUTH_CLIENT_ID'), 'redirect_uri'=>self::redirectUri(),
            'response_type'=>'code', 'state'=>$state,
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),
            'code_challenge_method'=>'S256',
        ]);
        return redirect($url)->withHeaders(['Cache-Control'=>'no-store','Referrer-Policy'=>'no-referrer'])
            ->cookie(self::COOKIE,$browser,600,'/auth/oauth','',false,true,'Lax');
    }
    public static function consume(string $state, string $browser): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$state) || !preg_match('/^[a-f0-9]{64}$/D',$browser)) throw new InvalidArgumentException('Invalid OAuth session. Please try again.');
        $key = hash('sha256',$state);
        Store::db();
        // One statement consumes state exactly once; separate model read/delete is replayable.
        $verifier = Db::selectOne('DELETE FROM oauth_flows WHERE state_hash=? AND browser_hash=? AND expires>? RETURNING verifier', [$key,hash('sha256',$browser),time()])?->verifier;
        if (!$verifier) throw new InvalidArgumentException('OAuth session expired or was already used. Please try again.');
        return $verifier;
    }
    private static function fetch(string $url, ?array $form = null, ?string $token = null): array
    {
        $curl = curl_init($url);
        $body = '';
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>array_filter(['Accept: application/json', $token ? 'Authorization: Bearer '.$token : null]),
            CURLOPT_WRITEFUNCTION=>static function ($handle,$chunk) use (&$body) {
                if (strlen($body)+strlen($chunk)>1000000) return 0;
                $body .= $chunk; return strlen($chunk);
            }]);
        if ($form !== null) curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        if ($ok === false || $status < 200 || $status >= 300) throw new InvalidArgumentException('OAuth provider request failed. Please try again.');
        $result = json_decode($body,true);
        if (!is_array($result)) throw new InvalidArgumentException('Invalid response from OAuth provider.');
        return $result;
    }
    public static function account(array $profile): string
    {
        Store::db();
        $subject = $profile['id'] ?? null;
        if ((!is_string($subject) && !is_int($subject)) || (string)$subject === '' || strlen((string)$subject)>200) throw new InvalidArgumentException('OAuth provider did not return a valid user ID.');
        $subject = (string)$subject;
        $linked = Account::query()->from('accounts as a')->join('oauth_identities as o', 'o.user_id', '=', 'a.user_id')
            ->where('o.provider', self::PROVIDER)->where('o.subject', $subject)->first(['a.user_id','a.enabled']);
        if ($linked) {
            if (!$linked['enabled']) throw new InvalidArgumentException('Your account is disabled. Contact your administrator.');
            return $linked['user_id'];
        }
        $email = $profile['email'] ?? null;
        if (!is_string($email) || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('A valid email is required to link your account. Use password login or contact your administrator.');
        return \support\Db::transaction(function () use ($email,$subject,$profile) {
            $account = Account::query()->where('email', strtolower(trim($email)))->first(['user_id','enabled']);
            if ($account && !$account->enabled) throw new InvalidArgumentException('Your account is disabled. Contact your administrator.');
            if (!$account) {
                $name = is_string($profile['name'] ?? null) ? $profile['name'] : '';
                $name = preg_match('//u', $name) ? trim(preg_replace('/[\x00-\x1f\x7f<>]/u', '', $name)) : '';
                $name = mb_strcut($name ?: explode('@', $email)[0], 0, 80, 'UTF-8');
                if (User::query()->where('name', $name)->exists()) $name .= '-'.bin2hex(random_bytes(4));
                // Passport users have no known local password; an admin can set one later.
                $created = Auth::create(['name'=>$name, 'email'=>$email, 'password'=>bin2hex(random_bytes(32)), 'admin'=>false]);
                $account = Account::query()->findOrFail($created['id']);
            }
            // Composite-key identity bindings are query-builder records, not mutable model instances.
            if (Db::table('oauth_identities')->where('provider', self::PROVIDER)->where('user_id', $account['user_id'])->exists()) throw new InvalidArgumentException('This account is already linked to another provider identity.');
            Db::table('oauth_identities')->insert(['provider'=>self::PROVIDER, 'subject'=>$subject, 'user_id'=>$account['user_id']]);
            return $account['user_id'];
        });
    }
    public static function callback(\support\Request $request): \support\Response
    {
        $headers = ['Cache-Control'=>'no-store','Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff'];
        $stage = 'validate';
        try {
            $public = parse_url(self::publicUrl());
            $host = $public['host'].(!empty($public['port']) ? ':'.$public['port'] : '');
            if (!self::configured() || $request->host() !== $host) throw new InvalidArgumentException('Invalid callback configuration.');
            $state = $request->get('state');
            if (!is_string($state)) throw new InvalidArgumentException('Missing OAuth state.');
            $stage = 'consume_state';
            $verifier = self::consume($state,$request->cookie(self::COOKIE) ?? '');
            if ($request->get('error')) throw new InvalidArgumentException('OAuth sign-in was cancelled or denied.');
            $code = $request->get('code');
            if (!is_string($code) || $code === '' || strlen($code)>4096) throw new InvalidArgumentException('Missing authorization code.');
            $stage = 'exchange_code';
            $result = self::fetch(self::PROVIDER.'/oauth/token',[
                'grant_type'=>'authorization_code','client_id'=>getenv('TDA_PASSPORT_OAUTH_CLIENT_ID'),
                'client_secret'=>getenv('TDA_PASSPORT_OAUTH_CLIENT_SECRET'),'redirect_uri'=>self::redirectUri(),
                'code'=>$code,'code_verifier'=>$verifier,
            ]);
            $accessToken = $result['access_token'] ?? null;
            if (!is_string($accessToken) || $accessToken === '' || strlen($accessToken)>16000 || preg_match('/[\x00-\x20\x7f]/',$accessToken)) throw new InvalidArgumentException('Invalid OAuth token response.');
            $stage = 'fetch_profile';
            $profile = self::fetch(self::PROVIDER.'/api/user',null,$accessToken);
            $stage = 'link_account';
            $id = self::account($profile);
            $stage = 'create_session';
            $session = Auth::session($id);
            Auth::logout($request->cookie(Auth::COOKIE));
            return redirect('/')->withHeaders($headers)
                ->cookie(self::COOKIE,'',-1,'/auth/oauth','',false,true,'Lax')
                ->cookie(Auth::COOKIE,$session,604800,'/','',false,true,'Strict');
        } catch (InvalidArgumentException $error) {
            return response(htmlspecialchars($error->getMessage(),ENT_QUOTES,'UTF-8').' <a href="/">Return to sign in</a>',400,$headers)
                ->cookie(self::COOKIE,'',-1,'/auth/oauth','',false,true,'Lax');
        } catch (\Throwable $error) {
            // Do not log provider codes, tokens, request bodies or client secrets.
            error_log(json_encode(['event'=>'oauth_callback_failed','stage'=>$stage,'exception'=>get_class($error),'message'=>$error->getMessage(),'file'=>$error->getFile(),'line'=>$error->getLine()], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            return response('Unable to complete sign-in. <a href="/">Try again</a>',503,$headers)
                ->cookie(self::COOKIE,'',-1,'/auth/oauth','',false,true,'Lax');
        }
    }
}
