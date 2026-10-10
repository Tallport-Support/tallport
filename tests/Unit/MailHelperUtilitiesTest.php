<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * MailHelper utilities not covered by MailParsingHelpersTest: imap_utf8(),
 * headers, address validation, and email files read for modules.
 */
class MailHelperUtilitiesTest extends TestCase
{
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
