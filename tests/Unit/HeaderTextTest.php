<?php

namespace Tests\Unit;

use App\Incoming\HeaderText;
use Tests\TestCase;

/**
 * Tallport's decoding of header text (RFC 2047 encoded words), including
 * the broken encodings FreeScout collected in its MailHelper::decodeSubject().
 */
class HeaderTextTest extends TestCase
{
    /**
     * @dataProvider encodedValues
     */
    public function testDecode($encoded, $decoded)
    {
        $this->assertSame($decoded, HeaderText::decode($encoded));
    }

    public function encodedValues()
    {
        return [
            'plain'                     => ['Question about my order', 'Question about my order'],
            'base64 utf-8'              => ['=?UTF-8?B?R3LDvMOfZSBhdXMgTcO8bmNoZW4=?=', 'Grüße aus München'],
            'quoted-printable'          => ['=?UTF-8?Q?Caf=C3=A9_order?=', 'Café order'],
            'lowercase b and q'         => ['=?utf-8?b?R3LDvMOfZQ==?= =?utf-8?q?_Caf=C3=A9?=', 'Grüße Café'],
            'split over parts'          => ['=?UTF-8?B?R3LDvMOf?= =?UTF-8?B?ZQ==?=', 'Grüße'],
            'folded'                    => ["=?UTF-8?Q?Caf=C3=A9?=\r\n =?UTF-8?Q?_order?=", 'Café order'],
            'mixed with plain'          => ['Re: =?UTF-8?Q?Caf=C3=A9?= order', 'Re: Café order'],
            'plain between words'       => ["=?utf-8?Q?Gesch=C3=A4ftskonto?= erstellen =?utf-8?Q?f=C3=BCr?=\r\n 249143", 'Geschäftskonto erstellen für 249143'],
            'iso-8859-1'                => ['=?ISO-8859-1?Q?Gr=FC=DFe?=', 'Grüße'],
            'character split over words' => ['=?UTF-8?B?0KLRgNCw0LvQsNC70LXQu9C+INCi0YDQsNC70LDQu9CwINCi0YDQsNC70LA=?= =?UTF-8?B?0LvQtdC70L7QstC90LA=?=', 'Тралалело Тралала Тралалеловна'],
            'utf-8 byte split'          => ['=?UTF-8?Q?Gr=C3?= =?UTF-8?Q?=BC=C3=9Fe?=', 'Grüße'],
            'base64 text split'         => ['=?UTF-8?B?R3LDvM?= =?UTF-8?B?OfZQ==?=', 'Grüße'],
            'iso-2022-jp'               => ['=?ISO-2022-JP?B?GyRCJDRDbUo4PiZJSiRyS1xGfCQqRk8kMSQ3JF4kORsoQg==?=', 'ご注文商品を本日お届けします'],
            'iso-2022-jp base64 split'  => ['=?iso-2022-jp?B?IBskQiFaSEcyPDpuQ?= =?iso-2022-jp?B?C4wTU1qIVs3Mkp2JSIlLyU3JSItahsoQg==?=', ' 【版下作成依頼】群峰アクシア㈱'],
            'iso-2022-jp with ascii runs' => ['=?iso-2022-jp?B?GyRCIXlCaBsoQjEzMhskQjlmISEhViUsITwlRyVzGyhCJhskQiUoJS8lOSVGJWolIiFXQGxMZ0U5JE4kPyRhJE4jURsoQiYbJEIjQSU1JW0lcyEhIVo3bjQpJSglLyU5JUYlaiUiISYlbyE8JS8hWxsoQg==?=', '☆第132号　「ガーデン&エクステリア」専門店のためのＱ&Ａサロン　【月刊エクステリア・ワーク】'],
            'ks_c_5601-1987'            => ['=?ks_c_5601-1987?B?vsiz58fPvLy/5A==?=', '안녕하세요'],
            'different charsets'        => ['=?ISO-8859-1?Q?Gr=FC=DFe?= =?UTF-8?Q?Caf=C3=A9?=', 'GrüßeCafé'],
            'spaces and ? in q text'    => ['=?ISO-8859-1?Q?Vorgang 538336029: M=F6chten Sie Ihre E-Mail-Adresse =E4ndern??=', 'Vorgang 538336029: Möchten Sie Ihre E-Mail-Adresse ändern?'],
            'unknown charset'           => ['=?X-IAS-German?B?U3VibWl0IHlvdXIgdGF4IHJlZnVuZA==?=', 'Submit your tax refund'],
            'charset only iconv knows'  => ['=?MACINTOSH?Q?Caf=8E?=', 'Café'],
            'rfc 2231 language'         => ['=?UTF-8*en?Q?Caf=C3=A9?=', 'Café'],
            'raw utf-8'                 => ['Grüße', 'Grüße'],
            'raw windows-1252'          => ["Gr\xFC\xDFe", 'Grüße'],
            'control characters'        => ["=?UTF-8?Q?a=00b=1Bc?=", 'abc'],
            'decomposed is composed'    => ["=?UTF-8?Q?Cafe=CC=81?=", 'Café'],
        ];
    }

    public function testAll()
    {
        $headers = "Message-ID: <a@example.org>\r\nThread-Index: AQHZ\r\n abc\r\nX-Empty:\r\nmessage-id: <second@example.org>";

        $this->assertSame([
            'message_id'   => '<a@example.org>',
            'thread_index' => 'AQHZ abc',
            'x_empty'      => '',
        ], HeaderText::all($headers));
        $this->assertSame('a@example.org', \MailHelper::getHeader($headers, 'Message-ID'));
        $this->assertSame('AQHZ abc', \MailHelper::getHeader($headers, 'thread_index'));
    }

    public function testValue()
    {
        $headers = "From: a@example.org\r\nsubject: Hello\r\n world\r\nX-Subject: other\r\nSubject: second";

        $this->assertSame('Hello world', HeaderText::value($headers, 'Subject'));
        $this->assertSame('a@example.org', HeaderText::value($headers, 'from'));
        $this->assertNull(HeaderText::value($headers, 'Cc'));
    }
}
