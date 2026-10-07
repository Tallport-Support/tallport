<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * MailHelper utilities not covered by MailParsingHelpersTest: subjects only
 * the fallback decoders can read, address validation, and email files read
 * for modules.
 */
class MailHelperUtilitiesTest extends TestCase
{
    /**
     * Subjects iconv_mime_decode() alone can't read.
     *
     * @dataProvider hardSubjects
     */
    public function testDecodeSubjectFallbacks($encoded, $decoded)
    {
        $this->assertSame($decoded, \MailHelper::decodeSubject($encoded));
    }

    public function hardSubjects()
    {
        return [
            // Encoded, then split into parts that are not valid on their own.
            'iso-2022-jp split'      => ['=?iso-2022-jp?B?IBskQiFaSEcyPDpuQ?= =?iso-2022-jp?B?C4wTU1qIVs3Mkp2JSIlLyU3JSItahsoQg==?=', ' 【版下作成依頼】群峰アクシア(株)'],
            // iconv_mime_decode() can't decode it.
            'iso-2022-jp one part'   => ['=?iso-2022-jp?B?GyRCIXlCaBsoQjEzMhskQjlmISEhViUsITwlRyVzGyhCJhskQiUoJS8lOSVGJWolIiFXQGxMZ0U5JE4kPyRhJE4jURsoQiYbJEIjQSU1JW0lcyEhIVo3bjQpJSglLyU5JUYlaiUiISYlbyE8JS8hWxsoQg==?=', '☆第132号　「ガーデン&エクステリア」専門店のためのＱ&Ａサロン　【月刊エクステリア・ワーク】'],
            // Spaces and "?" inside the encoded word.
            'invalid quoted-printable' => ['=?ISO-8859-1?Q?Vorgang 538336029: M=F6chten Sie Ihre E-Mail-Adresse =E4ndern??=', 'Vorgang 538336029: Möchten Sie Ihre E-Mail-Adresse ändern?'],
            // Korean, through the cp949 substitution.
            'ks_c_5601-1987'         => ['=?ks_c_5601-1987?B?vsiz58fPvLy/5A==?=', '안녕하세요'],
        ];
    }

    /**
     * imap_utf8() decomposes umlauts (u + combining diaeresis).
     */
    public function testImapUtf8()
    {
        $this->assertSame('Grüße', \Normalizer::normalize(\MailHelper::imapUtf8('=?UTF-8?B?R3LDvMOfZQ==?=')));
    }

    /**
     * The name appearing in another header is no match.
     */
    public function testMissingHeaderIsEmpty()
    {
        $this->assertSame('', \MailHelper::getHeader("Subject: Where is the Message-ID?\r\nFrom: casey@customer.example.org\r\n", 'Message-ID'));
    }

    public function testValidateEmail()
    {
        $this->assertSame('casey@customer.example.org', \MailHelper::validateEmail('casey@customer.example.org'));
        $this->assertFalse(\MailHelper::validateEmail('casey@'));
        $this->assertFalse(\MailHelper::validateEmail('Casey <casey@customer.example.org>'));
    }

    /**
     * Line endings don't matter.
     */
    public function testParseEml()
    {
        $message = \MailHelper::parseEml("From: Casey <casey@customer.example.org>\nTo: support@example.org\nSubject: Order 42\nMessage-ID: <eml-1@customer.example.org>\n\nWhere is my order?\n");

        $this->assertSame('Order 42', $message->getSubject()->toString());
        $this->assertSame('eml-1@customer.example.org', (string) $message->getMessageId());
        $this->assertSame('casey@customer.example.org', $message->getFrom()->first()->mail);
        $this->assertStringContainsString('Where is my order?', $message->getTextBody());
    }
}
