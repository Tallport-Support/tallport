<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Helpers that stand between untrusted input and the application: HTML
 * from emails, uploaded file names, and remote addresses (SSRF).
 */
class HelperSecurityTest extends TestCase
{
    /**
     * @dataProvider dangerousHtml
     */
    public function testStripDangerousTags($html, $must_not_contain)
    {
        $clean = \Helper::stripDangerousTags($html);

        foreach ((array)$must_not_contain as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $clean);
        }
    }

    public function dangerousHtml()
    {
        return [
            'script'              => ['<p>Hi</p><script>alert(1)</script>', '<script'],
            'script with attrs'   => ['<script type="text/javascript" src="/x.js"></script>', '<script'],
            'unclosed script'     => ['<p>Hi<script src="//evil.example/x.js">', '<script'],
            'nested to reassemble' => ['<scr<script>ipt>alert(1)</script>', '<script'],
            'iframe'              => ['<iframe src="/storage/attachment/1/1/1/a.html"></iframe>', '<iframe'],
            'object'              => ['<object data="/a.html" type="text/html"></object>', '<object'],
            'embed'               => ['<embed src="/a.swf">', '<embed'],
            'form'                => ['<form action="https://evil.example"><input name="password"></form>', '<form'],
            'meta refresh'        => ['<meta http-equiv="refresh" content="0;url=https://evil.example">', '<meta'],
            'link stylesheet'     => ['<link rel="stylesheet" href="https://evil.example/x.css">', '<link'],
            'style block'         => ['<style>body{background:url(https://evil.example/track)}</style>', '<style'],
            'upper case'          => ['<SCRIPT>alert(1)</SCRIPT>', '<script'],
        ];
    }

    public function testStripDangerousTagsKeepsNormalHtml()
    {
        $html = '<p>Hello <b>there</b>, see <a href="https://example.org">this</a>.</p><img src="https://example.org/a.png">';

        $this->assertSame($html, \Helper::stripDangerousTags($html));
    }

    public function testStripDangerousTagsAllowsExplicitExceptions()
    {
        $this->assertStringContainsString('<style>', \Helper::stripDangerousTags('<style>p{color:red}</style><p>x</p>', ['style']));
    }

    /**
     * @dataProvider uploadedFileNames
     */
    public function testSanitizeUploadedFileName($name, $expected)
    {
        $this->assertSame($expected, \Helper::sanitizeUploadedFileName($name));
    }

    public function uploadedFileNames()
    {
        return [
            'plain'                 => ['invoice.pdf', 'invoice.pdf'],
            'path traversal'        => ['../../etc/passwd', '.._.._etc_passwd_'],
            'windows path'          => ['C:\\Windows\\evil.txt', 'C__Windows_evil.txt'],
            'php renamed'           => ['shell.php', 'shell.php_'],
            'php variant renamed'   => ['shell.phtml', 'shell.phtml_'],
            'upper case php'        => ['SHELL.PHP', 'SHELL.PHP_'],
            'phar renamed'          => ['archive.phar', 'archive.phar_'],
            'control characters'    => ["na\x01me.txt", 'na_me.txt'],
            'newline replaced'      => ["na\nme.txt", 'na_me.txt'],
            'unicode kept'          => ['Überweisung ñ.pdf', 'Überweisung ñ.pdf'],
        ];
    }

    public function testDotFilesAreRenamed()
    {
        $this->assertNotSame('.htaccess', \Helper::sanitizeUploadedFileName('.htaccess'));
    }

    /**
     * IPv4 addresses hidden in IPv6 forms must be found, so internal
     * addresses can't be reached through them.
     *
     * @dataProvider embeddedIPv4
     */
    public function testExtractEmbeddedIPv4($ipv6, $ipv4)
    {
        $this->assertContains($ipv4, \Helper::extractEmbeddedIPv4($ipv6));
    }

    public function embeddedIPv4()
    {
        return [
            '6to4'       => ['2002:0a00:0001::1', '10.0.0.1'],
            'NAT64'      => ['64:ff9b::a9fe:a9fe', '169.254.169.254'],
            'teredo'     => ['2001:0000:c0a8:0101::', '192.168.1.1'],
            'bracketed'  => ['[2002:0a01:0203::]', '10.1.2.3'],
        ];
    }

    public function testNotIPv6HasNothingEmbedded()
    {
        $this->assertSame([], \Helper::extractEmbeddedIPv4('10.0.0.1'));
        $this->assertSame([], \Helper::extractEmbeddedIPv4('example.org'));
        $this->assertSame([], \Helper::extractEmbeddedIPv4('2001:db8::1'));
    }

    public function testHexToIPv4()
    {
        $this->assertSame('127.0.0.1', \Helper::hexToIPv4('7f000001'));
        $this->assertNull(\Helper::hexToIPv4('7f0001'));
        $this->assertNull(\Helper::hexToIPv4('zzzzzzzz'));
    }

    /**
     * @dataProvider internalUrls
     */
    public function testInternalAddressesAreRefusedForRemoteRequests($url)
    {
        $this->assertSame('', \Helper::checkUrlIpAndHost($url));
    }

    public function internalUrls()
    {
        return [
            'localhost'             => ['https://localhost/'],
            'loopback'              => ['http://127.0.0.1:8080/'],
            'private range'         => ['http://192.168.1.10/'],
            'cloud metadata'        => ['http://169.254.169.254/latest/meta-data/'],
            '6to4 loopback'         => ['http://[2002:7f00:0001::1]/'],
            'NAT64 metadata'        => ['http://[64:ff9b::a9fe:a9fe]/'],
            'decimal loopback'      => ['http://2130706433/'],
            // IPv4-mapped IPv6 is blocked by range rather than extracted.
            'mapped loopback'       => ['http://[::ffff:127.0.0.1]/'],
            'mapped hex metadata'   => ['http://[::ffff:a9fe:a9fe]/'],
        ];
    }
}
