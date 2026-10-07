<?php

namespace Tests\Feature;

use App\ActivityLog;
use App\Mailbox;
use Tests\FeatureTestCase;

/**
 * OAuth (Microsoft 365, Google Workspace) access tokens for mailboxes:
 * getting them with the authorization code, and refreshing an expired one
 * before sending or fetching.
 *
 * The token endpoints are reached with curl through config('app.proxy'),
 * here a local proxy that answers in place of Microsoft and Google.
 */
class MailHelperOauthTest extends FeatureTestCase
{
    protected $proxy;

    protected $proxy_pipes = [];

    protected $tmp_files = [];

    protected function tearDown(): void
    {
        if ($this->proxy) {
            proc_terminate($this->proxy);
            proc_close($this->proxy);
        }
        foreach ($this->tmp_files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * Start a proxy that answers each HTTPS request with the next response
     * ([status, body]), using a self-signed certificate for $host.
     */
    protected function startProxy($host, array $responses)
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $host], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1), $cert);
        openssl_pkey_export($key, $key_pem);
        $pem = tempnam(sys_get_temp_dir(), 'tallport-proxy-cert');
        file_put_contents($pem, $cert.$key_pem);
        $this->tmp_files[] = $pem;

        $code = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0');
echo explode(':', stream_socket_get_name($server, false))[1]."\n";
fflush(STDOUT);
foreach (json_decode($argv[2], true) as [$status, $body]) {
    $conn = stream_socket_accept($server, 30);
    $connect = '';
    while (($line = fgets($conn)) !== false && trim($line) !== '') {
        $connect .= $line;
    }
    fwrite($conn, "HTTP/1.1 200 Connection established\r\n\r\n");
    stream_context_set_option($conn, 'ssl', 'local_cert', $argv[1]);
    stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
    $head = '';
    while (($line = fgets($conn)) !== false && trim($line) !== '') {
        $head .= $line;
    }
    if (stripos($head, 'Expect: 100-continue') !== false) {
        fwrite($conn, "HTTP/1.1 100 Continue\r\n\r\n");
    }
    $length = preg_match('/content-length:\s*(\d+)/i', $head, $m) ? (int) $m[1] : 0;
    $request_body = '';
    while (strlen($request_body) < $length && !feof($conn)) {
        $request_body .= fread($conn, $length - strlen($request_body));
    }
    preg_match_all('/name="([^"]+)"\r\n\r\n(.*?)\r\n--/s', $request_body, $fields);
    echo json_encode([
        'connect' => strtok($connect, "\r\n"),
        'request' => strtok($head, "\r\n"),
        'fields'  => array_combine($fields[1], $fields[2]),
    ])."\n";
    fflush(STDOUT);
    fwrite($conn, "HTTP/1.1 $status X\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
    fclose($conn);
}
PHP;
        $this->proxy = proc_open([PHP_BINARY, '-r', $code, '--', $pem, json_encode($responses)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->proxy_pipes);
        $port = (int) fgets($this->proxy_pipes[1]);

        config(['app.proxy' => 'http://127.0.0.1:'.$port, 'app.curl_ssl_verifypeer' => false]);
    }

    /**
     * The next request the proxy received.
     */
    protected function proxyRequest()
    {
        return json_decode(fgets($this->proxy_pipes[1]), true);
    }

    protected function oauthMailbox($provider, $issued_on)
    {
        $mailbox = $this->createMailbox([], ['email' => 'support@example.org']);
        $mailbox->fill([
            'out_method'     => Mailbox::OUT_METHOD_SMTP,
            'out_server'     => $provider == \MailHelper::OAUTH_PROVIDER_GOOGLE ? \MailHelper::OAUTH_GOOGLE_SMTP : \MailHelper::OAUTH_MICROSOFT_SMTP,
            'out_port'       => 587,
            'out_username'   => 'support@example.org:out-client',
            'out_password'   => 'out-secret',
            'out_encryption' => Mailbox::OUT_ENCRYPTION_TLS,
            'in_protocol'    => Mailbox::IN_PROTOCOL_IMAP,
            'in_server'      => '127.0.0.1',
            'in_port'        => 1,
            'in_username'    => 'support@example.org:in-client',
            'in_password'    => 'in-secret',
            'in_encryption'  => Mailbox::IN_ENCRYPTION_NONE,
        ])->save();
        $mailbox->setMetaParam('oauth', [
            'provider'   => $provider,
            'a_token'    => 'old-access',
            'r_token'    => 'old-refresh',
            'issued_on'  => $issued_on,
            'expires_in' => 3600,
        ], true);

        return $mailbox;
    }

    public function testMicrosoftAccessTokenFromAuthorizationCode()
    {
        $this->startProxy('login.microsoftonline.com', [[200, json_encode(['access_token' => 'ms-access', 'refresh_token' => 'ms-refresh', 'expires_in' => 4514])]]);

        $token_data = \MailHelper::oauthGetAccessToken(\MailHelper::OAUTH_PROVIDER_MICROSOFT, ['client_id' => 'client-1', 'client_secret' => 'secret-1', 'code' => 'auth-code']);

        $request = $this->proxyRequest();
        $this->assertSame('CONNECT login.microsoftonline.com:443 HTTP/1.1', $request['connect']);
        $this->assertSame('POST /common/oauth2/v2.0/token HTTP/1.1', $request['request']);
        $this->assertEquals([
            'scope'         => 'offline_access https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send',
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => route('mailboxes.oauth_callback'),
            'client_id'     => 'client-1',
            'client_secret' => 'secret-1',
            'code'          => 'auth-code',
        ], $request['fields']);
        $this->assertSame([
            'provider'   => \MailHelper::OAUTH_PROVIDER_MICROSOFT,
            'a_token'    => 'ms-access',
            'r_token'    => 'ms-refresh',
            'issued_on'  => now()->toDateTimeString(),
            'expires_in' => 4514,
        ], $token_data);
    }

    public function testGoogleAccessTokenFromAuthorizationCode()
    {
        $this->startProxy('oauth2.googleapis.com', [[200, json_encode(['access_token' => 'g-access', 'refresh_token' => 'g-refresh', 'expires_in' => 3598])]]);

        $token_data = \MailHelper::oauthGetAccessToken(\MailHelper::OAUTH_PROVIDER_GOOGLE, ['client_id' => 'client-2', 'client_secret' => 'secret-2', 'code' => 'auth-code']);

        $request = $this->proxyRequest();
        $this->assertSame('CONNECT oauth2.googleapis.com:443 HTTP/1.1', $request['connect']);
        $this->assertSame('POST /token HTTP/1.1', $request['request']);
        $this->assertEquals([
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => route('mailboxes.oauth_callback'),
            'client_id'     => 'client-2',
            'client_secret' => 'secret-2',
            'code'          => 'auth-code',
        ], $request['fields']);
        $this->assertSame('g-access', $token_data['a_token']);
        $this->assertSame('g-refresh', $token_data['r_token']);
        $this->assertSame(\MailHelper::OAUTH_PROVIDER_GOOGLE, $token_data['provider']);
        $this->assertSame(3598, $token_data['expires_in']);
    }

    /**
     * Google returns no new refresh token on refresh: the mailbox keeps its own.
     */
    public function testExpiredGoogleTokenIsRefreshedBeforeSending()
    {
        $mailbox = $this->oauthMailbox(\MailHelper::OAUTH_PROVIDER_GOOGLE, now()->subHours(2)->toDateTimeString());
        $this->startProxy('oauth2.googleapis.com', [[200, json_encode(['access_token' => 'new-access', 'expires_in' => 3599])]]);

        \MailHelper::setMailDriver($mailbox);

        $request = $this->proxyRequest();
        $this->assertSame('refresh_token', $request['fields']['grant_type']);
        $this->assertSame('old-refresh', $request['fields']['refresh_token']);
        $this->assertSame('out-client', $request['fields']['client_id']);
        $this->assertSame('out-secret', $request['fields']['client_secret']);
        $mailbox = $mailbox->fresh();
        $this->assertSame('new-access', $mailbox->oauthGetParam('a_token'));
        $this->assertSame('old-refresh', $mailbox->oauthGetParam('r_token'));
        $this->assertSame('new-access', config('mail.password'));
    }

    public function testFailedRefreshBeforeSendingIsLogged()
    {
        $mailbox = $this->oauthMailbox(\MailHelper::OAUTH_PROVIDER_MICROSOFT, now()->subHours(2)->toDateTimeString());
        $this->startProxy('login.microsoftonline.com', [[400, '{"error":"invalid_grant"}']]);

        \MailHelper::setMailDriver($mailbox);

        $this->assertSame('refresh_token', $this->proxyRequest()['fields']['grant_type']);
        $log = ActivityLog::where('log_name', ActivityLog::NAME_EMAILS_SENDING)->latest('id')->first();
        $this->assertSame(ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_TO_CUSTOMER, $log->description);
        $this->assertSame('Error occurred refreshing oAuth Access Token: {"error":"invalid_grant"}', $log->properties['error']);
        $this->assertSame('old-access', $mailbox->fresh()->oauthGetParam('a_token'));
        $this->assertSame('old-access', config('mail.password'), 'Sending goes on with the token the mailbox has.');
    }

    public function testExpiredMicrosoftTokenIsRefreshedBeforeFetching()
    {
        $mailbox = $this->oauthMailbox(\MailHelper::OAUTH_PROVIDER_MICROSOFT, now()->subHours(2)->toDateTimeString());
        $this->startProxy('login.microsoftonline.com', [[200, json_encode(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 4000])]]);

        $client = \MailHelper::getMailboxClient($mailbox);

        $request = $this->proxyRequest();
        $this->assertSame('in-client', $request['fields']['client_id']);
        $this->assertSame('in-secret', $request['fields']['client_secret']);
        $this->assertSame('old-refresh', $request['fields']['refresh_token']);
        $this->assertSame('new-refresh', $mailbox->fresh()->oauthGetParam('r_token'));
        $this->assertSame('support@example.org', $client->client()->username);
        $this->assertSame('new-access', $client->client()->password);
        $this->assertSame('oauth', $client->client()->authentication);
    }

    public function testExpiredGoogleTokenIsRefreshedBeforeFetching()
    {
        $mailbox = $this->oauthMailbox(\MailHelper::OAUTH_PROVIDER_GOOGLE, now()->subHours(2)->toDateTimeString());
        $this->startProxy('oauth2.googleapis.com', [[200, json_encode(['access_token' => 'new-access', 'expires_in' => 3599])]]);

        $client = \MailHelper::getMailboxClient($mailbox);

        $this->assertSame('refresh_token', $this->proxyRequest()['fields']['grant_type']);
        $this->assertSame('old-refresh', $mailbox->fresh()->oauthGetParam('r_token'));
        $this->assertSame('new-access', $client->client()->password);
    }

    public function testFailedRefreshBeforeFetchingStopsTheFetch()
    {
        $mailbox = $this->oauthMailbox(\MailHelper::OAUTH_PROVIDER_GOOGLE, now()->subHours(2)->toDateTimeString());
        $this->startProxy('oauth2.googleapis.com', [[400, '{"error":"invalid_grant"}']]);

        try {
            \MailHelper::getMailboxClient($mailbox);
            $this->fail('No exception.');
        } catch (\Exception $e) {
            $this->assertSame('Error occurred refreshing oAuth Access Token: {"error":"invalid_grant"}', $e->getMessage());
        }

        $log = ActivityLog::where('log_name', ActivityLog::NAME_EMAILS_FETCHING)->latest('id')->first();
        $this->assertSame(ActivityLog::DESCRIPTION_EMAILS_FETCHING_ERROR, $log->description);
    }

    public function testValidTokenIsNotRefreshed()
    {
        $mailbox = $this->oauthMailbox(\MailHelper::OAUTH_PROVIDER_MICROSOFT, now()->toDateTimeString());

        $client = \MailHelper::getMailboxClient($mailbox);

        $this->assertSame('old-access', $client->client()->password);
    }

    /**
     * A token endpoint that answers with an empty body (or can't be reached)
     * should be reported, as the code intends ("Response code: ..."). It
     * returns no error, so a failed refresh goes unnoticed and the OAuth
     * callback redirects as if connected.
     */
    public function testEmptyTokenResponseIsAnError()
    {
        $this->knownBug('M11');

        $this->startProxy('login.microsoftonline.com', [[503, '']]);

        $token_data = \MailHelper::oauthGetAccessToken(\MailHelper::OAUTH_PROVIDER_MICROSOFT, ['client_id' => 'client-1', 'client_secret' => 'secret-1', 'code' => 'auth-code']);

        $this->assertSame('Response code: 503', $token_data['error'] ?? null);
    }
}
