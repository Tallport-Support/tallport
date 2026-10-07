<?php

namespace Tests\Unit;

use App\Misc\Helper;
use Tests\TestCase;

/**
 * \Helper's functions that need neither the database nor a request:
 * images, JSON, encryption, dates, URLs, CSP and the browser check, the
 * installer's subdirectory, and the remote file helpers' refusals.
 */
class HelperTest extends TestCase
{
    protected $server;

    protected $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        Helper::$is_console = null;
        Helper::$memory_cache = [];
        foreach ($this->files as $file) {
            if (is_dir($file)) {
                @chmod($file, 0755);
                @rmdir($file);
            } else {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    /**
     * Mark a test of intended behaviour as blocked by a bug in KNOWN_BUGS.md.
     */
    protected function knownBug($id)
    {
        $this->markTestIncomplete("Known bug $id, see KNOWN_BUGS.md");
    }

    protected function tempFile($contents = '')
    {
        $file = tempnam(sys_get_temp_dir(), 'tallport-helper-');
        file_put_contents($file, $contents);
        $this->files[] = $file;

        return $file;
    }

    /**
     * An image file: the left half red, the right half blue, a green top left
     * pixel (resizeImage() fills from there; see testResizeImageKeepsBackgrounds).
     */
    protected function image($width, $height, $type, $marked_corner = true)
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 0, 255));
        if ($marked_corner) {
            imagesetpixel($image, 0, 0, imagecolorallocate($image, 0, 255, 0));
        }
        $file = $this->tempFile();
        $save = 'image'.$type;
        $save($image, $file);

        return $file;
    }

    protected function rgb($image, $x, $y)
    {
        $color = imagecolorat($image, $x, $y);

        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    }

    /**
     * Within a few steps of the color (JPEG is lossy).
     */
    protected function assertColor($expected, $image, $x, $y)
    {
        foreach ($this->rgb($image, $x, $y) as $i => $value) {
            $this->assertEqualsWithDelta($expected[$i], $value, 8, "Pixel $x,$y");
        }
    }

    /**
     * Photos are cropped to the thumbnail's shape around the middle, whatever the format.
     *
     * @dataProvider imageTypes
     */
    public function testResizeImageCropsAroundTheMiddle($type, $mime_type)
    {
        // Wider than the thumbnail: the sides are cut off.
        $thumb = Helper::resizeImage($this->image(40, 20, $type), $mime_type, 10, 10);
        $this->assertSame([10, 10], [imagesx($thumb), imagesy($thumb)]);
        $this->assertColor([255, 0, 0], $thumb, 1, 5);
        $this->assertColor([0, 0, 255], $thumb, 8, 5);

        // Taller than the thumbnail: top and bottom are cut off.
        $thumb = Helper::resizeImage($this->image(20, 40, $type), $mime_type, 10, 10);
        $this->assertSame([10, 10], [imagesx($thumb), imagesy($thumb)]);
        $this->assertColor([255, 0, 0], $thumb, 2, 5);
        $this->assertColor([0, 0, 255], $thumb, 7, 5);
    }

    public function imageTypes()
    {
        return [
            'png'  => ['png', 'image/png'],
            'gif'  => ['gif', 'image/gif'],
            'bmp'  => ['bmp', 'image/bmp'],
            'jpeg' => ['jpeg', 'image/jpeg'],
        ];
    }

    /**
     * A transparent PNG gets a white background, unless transparency is kept.
     */
    public function testResizeImageTransparency()
    {
        $image = imagecreatetruecolor(10, 10);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $file = $this->tempFile();
        imagepng($image, $file);

        $thumb = Helper::resizeImage($file, 'image/png', 5, 5);
        $this->assertSame([255, 255, 255], $this->rgb($thumb, 2, 2));

        $thumb = Helper::resizeImage($file, 'image/png', 5, 5, true);
        $this->assertSame(127, (imagecolorat($thumb, 2, 2) >> 24) & 0x7F);
    }

    /**
     * Only transparency becomes white: an opaque PNG or GIF keeps its colors,
     * also where they reach the top left corner.
     */
    public function testResizeImageKeepsBackgrounds()
    {

        foreach (['png' => 'image/png', 'gif' => 'image/gif'] as $type => $mime_type) {
            $thumb = Helper::resizeImage($this->image(20, 20, $type, false), $mime_type, 10, 10);
            $this->assertColor([255, 0, 0], $thumb, 1, 5);
        }
    }

    /**
     * Not an image: false, so the photo isn't saved (Customer/User::savePhoto()).
     */
    public function testResizeImageRefusesWhatIsNotAnImage()
    {

        $this->assertFalse(Helper::resizeImage($this->tempFile('not an image'), 'image/png', 5, 5));
    }

    public function testJsonToArray()
    {
        $this->assertSame(['a', 'b'], Helper::jsonToArray('["a","b"]'));
        $this->assertSame([1 => 'b'], Helper::jsonToArray('["a","b"]', ['a']));
        $this->assertSame(['not json'], Helper::jsonToArray('not json'));
        $this->assertSame([], Helper::jsonToArray(''));
    }

    /**
     * Text that isn't valid UTF-8 is still encoded (the bad bytes replaced);
     * what can't be encoded at all throws.
     */
    public function testJsonEncodeSafe()
    {
        $this->assertSame('{"a":"b"}', Helper::jsonEncodeSafe(['a' => 'b']));
        $this->assertSame('{"name":"caf?","list":["ok"]}', Helper::jsonEncodeSafe(['name' => "caf\xE9", 'list' => ['ok']]));

        foreach ([
            'Maximum stack depth exceeded' => function () {
                Helper::jsonEncodeSafe([[1]], 0, 1);
            },
            'UTF8 encoding error' => function () {
                Helper::jsonEncodeSafe((object) ['name' => "caf\xE9"]);
            },
        ] as $message => $encode) {
            try {
                $encode();
                $this->fail('No exception for '.$message);
            } catch (\Exception $e) {
                $this->assertSame('Could not encode JSON: '.$message, $e->getMessage());
            }
        }
    }

    /**
     * Email addresses in plain text become links, with the link attributes asked for.
     */
    public function testLinkifyEmailAddressesWithAttributes()
    {
        $this->assertSame(
            'Write to <a  target="_blank" title="A &quot;mail&quot;" href="mailto:casey@customer.example.org">casey@customer.example.org</a>.',
            Helper::linkify('Write to casey@customer.example.org.', ['mail'], ['target' => '_blank', 'title' => 'A "mail"'])
        );
    }

    /**
     * Links in plain text for other protocols (ftp).
     */
    public function testLinkifyOtherProtocols()
    {

        $this->assertSame(
            'Get <a  target="_blank" href="ftp://files.example.org/a.txt">ftp://files.example.org/a.txt</a>, then write.',
            Helper::linkify('Get ftp://files.example.org/a.txt, then write.', ['ftp'], ['target' => '_blank'])
        );
    }

    /**
     * Values encrypted with a password are read back only with it;
     * decryptSoft() gives back what it can't decrypt.
     */
    public function testEncryptWithPassword()
    {
        $encrypted = Helper::encrypt('secret', 'password');
        $this->assertNotSame('secret', $encrypted);
        $this->assertSame('secret', Helper::decrypt($encrypted, 'password'));
        $this->assertSame('', Helper::decrypt($encrypted, 'wrong'));
        $this->assertSame($encrypted, Helper::decryptSoft($encrypted, 'wrong'));
        $this->assertSame('plain', Helper::decryptSoft('plain'));

        $this->assertSame('secret', Helper::decrypt(Helper::encrypt('secret')));
        $this->assertSame('', Helper::decrypt('not encrypted'));
    }

    public function testIsFolderWritable()
    {
        $dir = sys_get_temp_dir().'/tallport-writable-'.uniqid();
        mkdir($dir);
        $this->files[] = $dir;

        $this->assertTrue(Helper::isFolderWritable($dir.'/'));
        $this->assertFileDoesNotExist($dir.'/.writable_test', 'The test file is removed.');
        $this->assertFalse(Helper::isFolderWritable($dir.'/missing'));

        chmod($dir, 0555);
        if (is_writable($dir)) {
            $this->markTestSkipped('Running as a user who can write anywhere.');
        }
        $this->assertFalse(Helper::isFolderWritable($dir));
    }

    public function testGetLocaleData()
    {
        $this->assertSame(['name' => 'Deutsch', 'name_en' => 'German'], Helper::getLocaleData('de'));
        $this->assertSame('German', Helper::getLocaleData('de', 'name_en'));
        $this->assertNull(Helper::getLocaleData('xx'));
        $this->assertNull(Helper::getLocaleData(null, 'name'));
    }

    /**
     * Before installation (APP_URL is the default) the subdirectory comes
     * from the script's path, as the web server reports it.
     *
     * @dataProvider installerServers
     */
    public function testSubdirectoryBeforeInstallation($server)
    {
        config(['app.url' => 'http://localhost']);
        unset($_SERVER['ORIG_SCRIPT_NAME']);
        $_SERVER = array_merge($_SERVER, $server);

        $this->assertSame('helpdesk', Helper::getSubdirectory());
        $this->assertSame('/helpdesk/', Helper::getSubdirectory(true, true));
    }

    public function installerServers()
    {
        return [
            'script name' => [
                ['SCRIPT_FILENAME' => '/var/www/helpdesk/index.php', 'SCRIPT_NAME' => '/helpdesk/index.php', 'PHP_SELF' => '/helpdesk/index.php'],
            ],
            'php self' => [
                ['SCRIPT_FILENAME' => '/var/www/helpdesk/index.php', 'SCRIPT_NAME' => '/cgi/php', 'PHP_SELF' => '/helpdesk/index.php'],
            ],
            'orig script name (1and1)' => [
                ['SCRIPT_FILENAME' => '/var/www/helpdesk/index.php', 'SCRIPT_NAME' => '/cgi/php', 'PHP_SELF' => '/helpdesk/index.php/install', 'ORIG_SCRIPT_NAME' => '/helpdesk/index.php'],
            ],
            'backtracking the file path' => [
                ['SCRIPT_FILENAME' => '/var/www/helpdesk/public/index.php', 'SCRIPT_NAME' => '/cgi/php', 'PHP_SELF' => '/helpdesk/public/index.php/install'],
            ],
        ];
    }

    public function testSubdirectoryFromAppUrl()
    {
        config(['app.url' => 'https://support.example.org/helpdesk/']);

        $this->assertSame('helpdesk', Helper::getSubdirectory());
        $this->assertSame('helpdesk/', Helper::getSubdirectory(true));
    }

    public function testSubstrUnicode()
    {
        $this->assertSame('rüß', Helper::substrUnicode('Grüße', 1, 3));
        $this->assertSame('ße', Helper::substrUnicode('Grüße', 3));
    }

    /**
     * Links follow APP_URL's protocol.
     */
    public function testFixProtocol()
    {
        config(['app.url' => 'http://support.example.org']);
        $this->assertSame('http://support.example.org/a', Helper::fixProtocol('https://support.example.org/a'));
        $this->assertSame('http://support.example.org/a', Helper::fixProtocol('http://support.example.org/a'));

        config(['app.url' => 'https://support.example.org']);
        $this->assertSame('https://support.example.org/a', Helper::fixProtocol('http://support.example.org/a'));
        $this->assertSame('https://support.example.org/a', Helper::fixProtocol('https://support.example.org/a'));
    }

    /**
     * Whether this request came over HTTPS: directly, or through a proxy or Cloudflare.
     *
     * @dataProvider httpsServers
     */
    public function testIsCurrentUrlHttps($server, $https)
    {
        foreach (['X_FORWARDED_PROTO', 'HTTPS', 'HTTP_X_FORWARDED_PROTO', 'HTTP_CF_VISITOR'] as $key) {
            unset($_SERVER[$key]);
        }
        $_SERVER = array_merge($_SERVER, $server);

        $this->assertSame($https, Helper::isCurrentUrlHttps());
    }

    public function httpsServers()
    {
        return [
            'plain'          => [[], false],
            'https'          => [['HTTPS' => 'ON'], true],
            'https off'      => [['HTTPS' => 'off'], false],
            'proxy'          => [['HTTP_X_FORWARDED_PROTO' => 'https'], true],
            'proxy variable' => [['X_FORWARDED_PROTO' => 'ssl'], true],
            'cloudflare'     => [['HTTP_CF_VISITOR' => '{"scheme":"https"}'], true],
            'cloudflare http' => [['HTTP_CF_VISITOR' => '{"scheme":"http"}'], false],
        ];
    }

    /**
     * In the installer, HTTPS is how the page was opened, as APP_URL isn't set yet.
     */
    public function testIsHttpsInTheInstaller()
    {
        config(['app.url' => 'http://localhost']);
        $_SERVER['HTTPS'] = 'on';

        $_SERVER['REQUEST_URI'] = '/install/environment?x=1';
        $this->assertTrue(Helper::isHttps());
        $_SERVER['REQUEST_URI'] = '/install';
        $this->assertTrue(Helper::isHttps());

        $_SERVER['REQUEST_URI'] = '/settings';
        $this->assertFalse(Helper::isHttps());
    }

    /**
     * Dates of incoming email that Carbon can't read: "UT" and a time zone
     * comment after the offset.
     */
    public function testParseDateToCarbon()
    {
        $this->assertSame('2008-06-03 11:05:30', Helper::parseDateToCarbon('Tue, 3 Jun 2008 11:05:30 UT')->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertSame('2016-11-21 12:31:06', Helper::parseDateToCarbon('Mon, 21 Nov 2016 13:31:06 +0100 (Romance Standard Time)')->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertSame('2016-11-21 08:01:06', Helper::parseDateToCarbon('<Mon, 21 Nov 2016 13:31:06 +0580>')->setTimezone('UTC')->format('Y-m-d H:i:s'), 'A mistyped +0530.');

        $this->assertNull(Helper::parseDateToCarbon('garbage date', false));
        $this->assertEqualsWithDelta(time(), Helper::parseDateToCarbon('garbage date')->getTimestamp(), 5, 'Now, when asked for.');
    }

    /**
     * Right-to-left languages get the RTL stylesheet last.
     */
    public function testLayoutStylesheetsForRightToLeftLanguages()
    {
        app()->setLocale('en');
        $this->assertNotContains('/css/style-rtl.css', Helper::layoutStylesheets());

        app()->setLocale('he');
        $styles = Helper::layoutStylesheets();
        $this->assertSame('/css/style-rtl.css', end($styles));
        app()->setLocale('en');
    }

    /**
     * Script domains from APP_CSP_SCRIPT_SRC are allowed by the policy, without
     * anything that would weaken or break it.
     */
    public function testCspAllowsConfiguredScriptDomains()
    {
        config([
            'app.csp_script_src' => 'cdn.example.org https://js.example.net/lib.js \'unsafe-inline\'',
            'app.csp_custom'     => ' connect-src https://api.example.org;',
        ]);

        $csp = Helper::getCspValue();

        $this->assertStringContainsString("default-src 'self'  cdn.example.org js.example.net ;", $csp);
        $this->assertStringContainsString("'nonce-".Helper::cspNonce()."'", $csp);
        $this->assertStringContainsString('https://js.example.net/lib.js', $csp);
        $this->assertStringContainsString('connect-src https://api.example.org;', $csp);
        $this->assertStringNotContainsString('unsafe-inline\'  cdn', $csp);
        $this->assertSame(1, substr_count($csp, 'unsafe-inline'), 'Only for styles.');
        $this->assertStringStartsWith('<meta http-equiv="Content-Security-Policy" content="base-uri', Helper::cspMetaTag());
    }

    /**
     * Browsers that support Content Security Policy, by version.
     *
     * @dataProvider userAgents
     */
    public function testIsCspSupported($user_agent, $supported)
    {
        $this->assertSame($supported, Helper::isCspSupported($user_agent));
    }

    public function userAgents()
    {
        return [
            'internet explorer' => ['Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko', false],
            'old msie'          => ['Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1)', false],
            'chrome'            => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', true],
            'old chrome'        => ['Mozilla/5.0 AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.64 Safari/537.11', false],
            'firefox'           => ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', true],
            'old firefox'       => ['Mozilla/5.0 (Windows NT 6.1; rv:20.0) Gecko/20100101 Firefox/20.0', false],
            'safari'            => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15', true],
            'old safari'        => ['Mozilla/5.0 (Macintosh) AppleWebKit/536.26 (KHTML, like Gecko) Version/6.0 Safari/536.26', false],
            'old edge'          => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Edge/11.10240', false],
            'other'             => ['curl/8.5.0', true],
        ];
    }

    public function testIsCspSupportedReadsTheRequestsUserAgent()
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1)';
        $this->assertFalse(Helper::isCspSupported());
    }

    /**
     * Browsers without CSP are turned away with an explanation, unless their
     * user agent is allowed (APP_ALLOWED_USER_AGENTS).
     */
    public function testCheckBrowser()
    {
        $user_agent = 'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1)';
        $request = \Illuminate\Http\Request::create('https://support.example.org/conversation/1', 'GET', [], [], [], ['HTTP_USER_AGENT' => $user_agent]);

        $result = Helper::checkBrowser($request);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('https://support.example.org/conversation/1', $result['msg']);
        $this->assertStringContainsString($user_agent, $result['msg']);

        config(['app.allowed_user_agents' => 'Some Kiosk|'.$user_agent]);
        $this->assertSame(['status' => 'success', 'msg' => ''], Helper::checkBrowser($request));
    }

    /**
     * A header that can't be decoded is kept as it is.
     */
    public function testIconvMimeDecode()
    {
        $this->assertSame('café', Helper::iconvMimeDecode('=?utf-8?Q?caf=C3=A9?='));
        $this->assertSame('=?X-UNKNOWN?Q?a?=', Helper::iconvMimeDecode('=?X-UNKNOWN?Q?a?=', 0));
    }

    public function testIsCarbon()
    {
        $this->assertTrue(Helper::isCarbon(\Carbon\Carbon::now()));
        $this->assertFalse(Helper::isCarbon('2024-01-01 00:00:00'));
        $this->assertFalse(Helper::isCarbon(new \DateTime()));
    }

    /**
     * Carbon dates from Laravel (Illuminate\Support\Carbon, what now() gives) are Carbon dates too.
     */
    public function testIsCarbonForLaravelDates()
    {
        $this->assertTrue(Helper::isCarbon(now()));
    }

    /**
     * The per-request memory cache (used on web requests, not in the console), with dot keys.
     */
    public function testMemoryCache()
    {
        Helper::$is_console = true;
        $this->assertFalse(Helper::memoryCachePut('mailbox_access.1_2', true));
        $this->assertNull(Helper::memoryCacheGet('mailbox_access.1_2'));

        Helper::$is_console = false;
        Helper::memoryCachePut('mailbox_access.1_2', true);
        Helper::memoryCachePut('mailbox_access.1_3', false);
        $this->assertTrue(Helper::memoryCacheGet('mailbox_access.1_2'));
        $this->assertFalse(Helper::memoryCacheGet('mailbox_access.1_3'));
        $this->assertSame(['1_2' => true, '1_3' => false], Helper::memoryCacheGet('mailbox_access'));
        $this->assertNull(Helper::memoryCacheGet('mailbox_access.9_9'));
    }

    public function testPhpIniSizeToBytes()
    {
        $this->assertSame(512 * 1024, Helper::phpIniSizeToBytes('512k'));
        $this->assertSame(128 * 1024 * 1024, Helper::phpIniSizeToBytes('128M'));
        $this->assertSame(1024 * 1024 * 1024, Helper::phpIniSizeToBytes(' 1G '));
        $this->assertSame(1000, Helper::phpIniSizeToBytes('1000'));
    }

    public function testIsLocalStorage()
    {
        $this->assertTrue(Helper::isLocalStorage('local'));

        config(['filesystems.default' => 'local']);
        $this->assertTrue(Helper::isLocalStorage());
        config(['filesystems.default' => 's3']);
        $this->assertFalse(Helper::isLocalStorage());
    }

    /**
     * Remote files are never fetched from this server or its network
     * (nothing is requested: the address is refused first).
     */
    public function testRemoteFilesFromLocalAddressesAreRefused()
    {
        $destination = sys_get_temp_dir().'/tallport-download-'.uniqid();
        $this->files[] = $destination;

        $this->assertFalse(Helper::downloadRemoteFile('http://127.0.0.1/secret', $destination));
        $this->assertFileDoesNotExist($destination);
        $this->assertFalse(Helper::downloadRemoteFileAsTmp('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse(Helper::getRemoteFileContents('http://[::1]/'));
        $this->assertFalse(Helper::getRemoteFileContents('file:///etc/passwd'));
        $this->assertSame('', Helper::sanitizeRemoteUrl('http://localhost/'));
    }

    /**
     * Checking a mail server's port refuses this server and its network.
     */
    public function testCheckPortRefusesLocalHosts()
    {
        $this->expectExceptionCode(Helper::EXCEPTION_UNSAFE_URL);

        Helper::checkPort('127.0.0.1', 25);
    }

    /**
     * Only http(s) URLs with a host are checked further.
     */
    public function testCheckUrlIpAndHostNeedsSchemeAndHost()
    {
        $this->assertSame('', Helper::checkUrlIpAndHost('ftp://example.org/'));
        $this->assertSame('', Helper::checkUrlIpAndHost('//example.org/'));
        $this->assertSame('', Helper::checkUrlIpAndHost('http:///path'));
        $this->assertSame('', Helper::checkUrlIpAndHost('http:?query'));
        $this->assertSame('', Helper::checkUrlIpAndHost(null));
    }

    /**
     * An IPv6 address without brackets gets them; host:port and user:password@host don't.
     */
    public function testNormalizeIPv6InUrl()
    {
        $this->assertSame('http://[2001:db8::1]/x', Helper::normalizeIPv6InUrl('http://2001:db8::1/x'));
        $this->assertSame('https://[::1]', Helper::normalizeIPv6InUrl('https://::1'));
        $this->assertSame('http://[::1]:8080/x', Helper::normalizeIPv6InUrl('http://[::1]:8080/x'));
        $this->assertSame('http://127.0.0.1:8080/x', Helper::normalizeIPv6InUrl('http://127.0.0.1:8080/x'));
        $this->assertSame('http://user:secret@example.org/x', Helper::normalizeIPv6InUrl('http://user:secret@example.org/x'));
    }

    public function testHexToIp()
    {
        $this->assertSame('127.0.0.1', Helper::hexToIp('0x7f000001'));
        $this->assertSame('10.0.0.1', Helper::hexToIp('a000001'), 'An odd number of digits.');
        $this->assertFalse(Helper::hexToIp('0xzz'));
        $this->assertFalse(Helper::hexToIp('7f00'), 'Neither IPv4 nor IPv6 long.');
    }

    /**
     * Unsafe tags a caller allows (e.g. embed) still may not load files from
     * this server: relative and same-host addresses are removed.
     */
    public function testAllowedUnsafeTagsCannotPointToThisServer()
    {
        config(['app.url' => 'https://support.example.org']);

        $html = Helper::stripDangerousTags(
            '<embed src="/storage/uploads/a.swf"><embed src="//support.example.org/x.swf"><embed src="https://SUPPORT.example.org/y.swf">'
            .'<embed src="https://media.example.net/video.swf"><object data="https://media.example.net/a.html"></object>',
            ['embed', 'object']
        );

        $this->assertSame('<embed src="https://media.example.net/video.swf"><object data="https://media.example.net/a.html"></object>', $html);
    }

    /**
     * Archives that can't be read, a missing destination, and entries that
     * would be written through a symlink are refused.
     */
    public function testUnzipRefusals()
    {
        $dir = sys_get_temp_dir().'/tallport-unzip-'.uniqid();
        mkdir($dir.'/out', 0777, true);
        $archive = $dir.'/module.zip';
        $zip = new \ZipArchive();
        $zip->open($archive, \ZipArchive::CREATE);
        $zip->addFromString('Module/module.json', '{}');
        $zip->close();
        mkdir($dir.'/out/Module');
        symlink($dir.'/elsewhere.json', $dir.'/out/Module/module.json');

        try {
            foreach ([
                [$this->tempFile('not a zip'), $dir.'/out', 'Could not open archive'],
                [$archive, $dir.'/missing', 'Folder not found'],
                [$archive, $dir.'/out', 'Archive entry would be written through a symlink: Module/module.json'],
            ] as [$file, $to, $message]) {
                try {
                    Helper::unzip($file, $to);
                    $this->fail('Not refused: '.$message);
                } catch (\Exception $e) {
                    $this->assertStringStartsWith($message, $e->getMessage());
                }
            }
            $this->assertFileDoesNotExist($dir.'/elsewhere.json');
        } finally {
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($dir);
        }
    }

    /**
     * The process IDs of running commands matching a text (queue workers,
     * the Nostr listener), leaving out the search itself.
     */
    public function testGetRunningProcesses()
    {
        $marker = 'tallport-process-'.uniqid();
        $process = proc_open(['php', '-r', 'sleep(10);', $marker], [], $pipes);
        $pid = proc_get_status($process)['pid'];

        try {
            $this->assertSame([(string) $pid], Helper::getRunningProcesses($marker));
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    /**
     * An internal IPv4 address inside a NAT64 local-use IPv6 address (64:ff9b:1::/48) is refused.
     */
    public function testInternalAddressInNat64LocalUseIsRefused()
    {
        $this->assertContains('127.0.0.1', Helper::extractEmbeddedIPv4('64:ff9b:1::7f00:1'));
        $this->assertSame('', Helper::checkUrlIpAndHost('http://[64:ff9b:1::7f00:1]/'));
    }

    /**
     * PDFs with JavaScript are renamed so browsers don't open them as PDFs;
     * the contents are read from the uploaded file when not given.
     */
    public function testPdfWithJavascriptIsRenamed()
    {
        $script = "%PDF-1.4\n1 0 obj << /Type /Action /S /JavaScript /JS (app.alert(1)) >>\n";

        $this->assertSame('terms.pdf_', Helper::sanitizeUploadedFileName('terms.pdf', null, $script));
        $this->assertSame('terms.pdf', Helper::sanitizeUploadedFileName('terms.pdf', null, "%PDF-1.4\n"));
        $this->assertSame('terms_', Helper::sanitizeUploadedFileName('terms', null, $script, 'application/pdf'));

        $file = new \Illuminate\Http\UploadedFile($this->tempFile($script), 'terms.pdf', 'application/pdf', null, true);
        $this->assertSame('terms.pdf_', Helper::sanitizeUploadedFileName('terms.pdf', $file));
    }

    /**
     * Requests that came through Cloudflare (System Status mentions it).
     *
     * @dataProvider cloudflareHeaders
     */
    public function testDetectCloudFlare($server, $detected)
    {
        foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_CF_CONNECTING_IP', 'HTTP_CF_VISITOR', 'HTTP_CF_RAY', 'HTTP_CDN_LOOP'] as $key) {
            unset($_SERVER[$key]);
        }
        $_SERVER = array_merge($_SERVER, $server);

        $this->assertSame($detected, Helper::detectCloudFlare());
    }

    public function cloudflareHeaders()
    {
        return [
            'none'     => [[], false],
            'ray'      => [['HTTP_CF_RAY' => '8c1f2a3b4c5d6e7f-AMS'], true],
            'country'  => [['HTTP_CF_IPCOUNTRY' => 'NL'], true],
            'cdn loop' => [['HTTP_CDN_LOOP' => 'cloudflare'], true],
            'other cdn' => [['HTTP_CDN_LOOP' => 'akamai'], false],
        ];
    }
}
