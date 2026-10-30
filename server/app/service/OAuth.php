<?php
namespace app\service;

use InvalidArgumentException;
use Crab\Contracts\Http\Request;
use Crab\Http\Cookie;
use Crab\Http\Response;
use Crab\HttpClient\Client;
use Crab\Contracts\Log\Logger;

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
    public static function start(Request $request): Response
    {
        if (!self::configured()) return Response::text('OAuth login is not configured.', 503);
        // Always start on the callback host so the browser-bound cookie is available there.
        if ($request->getHeaderLine('Host') !== parse_url(self::publicUrl(), PHP_URL_HOST).(parse_url(self::publicUrl(), PHP_URL_PORT) ? ':'.parse_url(self::publicUrl(), PHP_URL_PORT) : '')) return Response::redirect(self::publicUrl().'/auth/oauth/start');
        $state = bin2hex(random_bytes(32));
        $browser = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        Store::db();
        Store::connection()->table('oauth_flows')->where('expires', '<', time())->delete();
        Store::connection()->table('oauth_flows')->insert(['state_hash'=>hash('sha256',$state), 'browser_hash'=>hash('sha256',$browser), 'verifier'=>$verifier, 'expires'=>time()+600]);
        $url = self::PROVIDER.'/oauth/authorize?'.http_build_query([
            'client_id'=>getenv('TDA_PASSPORT_OAUTH_CLIENT_ID'), 'redirect_uri'=>self::redirectUri(),
            'response_type'=>'code', 'state'=>$state,
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),
            'code_challenge_method'=>'S256',
        ]);
        return Response::redirect($url)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer')->withCookie(new Cookie(self::COOKIE, $browser, 600, '/auth/oauth', '', false, true, 'Lax'));
    }
    public static function consume(string $state, string $browser): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$state) || !preg_match('/^[a-f0-9]{64}$/D',$browser)) throw new InvalidArgumentException('Invalid OAuth session. Please try again.');
        $key = hash('sha256',$state);
        Store::db();
        // One statement consumes state exactly once; separate model read/delete is replayable.
        $row = Store::connection()->first('DELETE FROM oauth_flows WHERE state_hash=? AND browser_hash=? AND expires>? RETURNING verifier', [$key,hash('sha256',$browser),time()]);
        $verifier = $row['verifier'] ?? null;
        if (!$verifier) throw new InvalidArgumentException('OAuth session expired or was already used. Please try again.');
        return $verifier;
    }
    private static function fetch(string $url, ?array $form = null, ?string $token = null): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) $headers['Authorization'] = 'Bearer '.$token;
        // OAuth requests carry secrets and authorization codes; never forward them to a redirected host.
        $client = new Client(timeout: 15.0, redirects: 0, maxBytes: 1_000_000);
        $response = $client->request($form === null ? 'GET' : 'POST', $url, headers: $headers, form: $form ?? []);
        if (!$response->ok()) throw new InvalidArgumentException('OAuth provider request failed. Please try again.');
        $result = $response->json();
        if (!is_array($result)) throw new InvalidArgumentException('Invalid response from OAuth provider.');
        return $result;
    }
    public static function account(array $profile): string
    {
        Store::db();
        $subject = $profile['id'] ?? null;
        if ((!is_string($subject) && !is_int($subject)) || (string)$subject === '' || strlen((string)$subject)>200) throw new InvalidArgumentException('OAuth provider did not return a valid user ID.');
        $subject = (string)$subject;
        $db = Store::connection();
        $linked = $db->table('accounts as a')->join('oauth_identities as o', 'o.user_id', '=', 'a.user_id')
            ->where('o.provider', self::PROVIDER)->where('o.subject', $subject)->select('a.user_id','a.enabled')->first();
        if ($linked) {
            if (!$linked['enabled']) throw new InvalidArgumentException('Your account is disabled. Contact your administrator.');
            return $linked['user_id'];
        }
        $email = $profile['email'] ?? null;
        if (!is_string($email) || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('A valid email is required to link your account. Use password login or contact your administrator.');
        return \app\service\Store::transaction(function () use ($email,$subject,$profile) {
            $db = Store::connection();
            $account = $db->table('accounts')->where('email', strtolower(trim($email)))->select('user_id','enabled')->first();
            if ($account && !$account['enabled']) throw new InvalidArgumentException('Your account is disabled. Contact your administrator.');
            if (!$account) {
                $name = is_string($profile['name'] ?? null) ? $profile['name'] : '';
                $name = preg_match('//u', $name) ? trim(preg_replace('/[\x00-\x1f\x7f<>]/u', '', $name)) : '';
                $name = mb_strcut($name ?: explode('@', $email)[0], 0, 80, 'UTF-8');
                if ($db->table('users')->where('name', $name)->exists()) $name .= '-'.bin2hex(random_bytes(4));
                // Passport users have no known local password; an admin can set one later.
                $created = Auth::create(['name'=>$name, 'email'=>$email, 'password'=>bin2hex(random_bytes(32)), 'admin'=>false]);
                $account = $db->table('accounts')->where('user_id', $created['id'])->first() ?? throw new InvalidArgumentException('Could not create the local account.');
            }
            // Composite-key identity bindings are query-builder records, not mutable model instances.
            if (Store::connection()->table('oauth_identities')->where('provider', self::PROVIDER)->where('user_id', $account['user_id'])->exists()) throw new InvalidArgumentException('This account is already linked to another provider identity.');
            Store::connection()->table('oauth_identities')->insert(['provider'=>self::PROVIDER, 'subject'=>$subject, 'user_id'=>$account['user_id']]);
            Store::notify();
            return $account['user_id'];
        });
    }
    public static function callback(Request $request, ?Logger $logger = null): Response
    {
        $headers = ['Cache-Control'=>'no-store','Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff'];
        $stage = 'validate';
        try {
            $public = parse_url(self::publicUrl());
            $host = $public['host'].(!empty($public['port']) ? ':'.$public['port'] : '');
            if (!self::configured() || $request->getHeaderLine('Host') !== $host) throw new InvalidArgumentException('Invalid callback configuration.');
            $state = $request->query('state');
            if (!is_string($state)) throw new InvalidArgumentException('Missing OAuth state.');
            $stage = 'consume_state';
            $verifier = self::consume($state,$request->cookie(self::COOKIE) ?? '');
            if ($request->query('error')) throw new InvalidArgumentException('OAuth sign-in was cancelled or denied.');
            $code = $request->query('code');
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
            return Response::redirect('/')->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer')->withHeader('X-Content-Type-Options', 'nosniff')->withCookie(Cookie::forget(self::COOKIE, '/auth/oauth'))->withCookie(new Cookie(Auth::COOKIE, $session, 604800, '/', '', false, true, 'Strict'));
        } catch (InvalidArgumentException $error) {
            return Response::html(htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8').' <a href="/">Return to sign in</a>', 400, $headers)->withCookie(Cookie::forget(self::COOKIE, '/auth/oauth'));
        } catch (\Throwable $error) {
            // Do not log provider codes, tokens, request bodies or client secrets.
            ($logger ?? new \Crab\Log\Logger())->error('OAuth callback failed.', [
                'event' => 'oauth_callback_failed',
                'stage' => $stage,
                'message' => $error->getMessage(),
                'exception' => $error,
            ]);
            return Response::html('Unable to complete sign-in. <a href="/">Try again</a>', 503, $headers)->withCookie(Cookie::forget(self::COOKIE, '/auth/oauth'));
        }
    }
}
