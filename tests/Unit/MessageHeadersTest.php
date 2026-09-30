<?php

namespace Tests\Unit;

use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Threading headers on outgoing mail (MailHelper::setMessageHeaders()).
 */
class MessageHeadersTest extends TestCase
{
    public function testThreadingAndCustomHeaders()
    {
        $message = new Email();
        \MailHelper::setMessageHeaders($message, [
            'Message-ID'            => 'fs-reply-7-abc@example.org',
            'In-Reply-To'           => '<first@customer.example.org>',
            'References'            => '<first@customer.example.org> <second@customer.example.org>',
            'X-FreeScout-Mail-Type' => 'customer.message',
        ]);
        $headers = $message->getHeaders();

        $this->assertSame('<fs-reply-7-abc@example.org>', $headers->get('Message-ID')->getBodyAsString());
        $this->assertSame('<first@customer.example.org>', $headers->get('In-Reply-To')->getBodyAsString());
        $this->assertSame('<first@customer.example.org> <second@customer.example.org>', $headers->get('References')->getBodyAsString());
        $this->assertSame('customer.message', $headers->get('X-FreeScout-Mail-Type')->getBodyAsString());
    }

    public function testMalformedIdsFromOtherClientsAreLeftOut()
    {
        $message = new Email();
        \MailHelper::setMessageHeaders($message, [
            'In-Reply-To' => '<not an id>',
            'References'  => '<not an id> <ok@customer.example.org>',
        ]);
        $headers = $message->getHeaders();

        $this->assertNull($headers->get('In-Reply-To'), 'Sending must not fail over a malformed ID.');
        $this->assertSame('<ok@customer.example.org>', $headers->get('References')->getBodyAsString());
    }
}
