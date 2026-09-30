<?php

namespace Tests\Support;

use Symfony\Component\Mailer\SentMessage;

/**
 * A captured outgoing email with the SwiftMailer-style accessors the tests
 * were written against (Laravel 9 sends Symfony Mime messages).
 */
class CapturedEmail
{
    /**
     * @var \Symfony\Component\Mime\Email
     */
    public $message;

    public function __construct($sent)
    {
        $this->message = $sent instanceof SentMessage ? $sent->getOriginalMessage() : $sent;
    }

    /**
     * Message-ID without angle brackets.
     */
    public function getId()
    {
        $header = $this->message->getHeaders()->get('Message-ID');

        return $header ? $header->getId() : null;
    }

    public function getSubject()
    {
        return $this->message->getSubject();
    }

    /**
     * The main body: HTML if there is any, else plain text.
     */
    public function getBody()
    {
        return $this->message->getHtmlBody() ?? $this->message->getTextBody();
    }

    public function getFrom()
    {
        return $this->addresses($this->message->getFrom());
    }

    public function getTo()
    {
        return $this->addresses($this->message->getTo());
    }

    public function getCc()
    {
        return $this->addresses($this->message->getCc());
    }

    public function getBcc()
    {
        return $this->addresses($this->message->getBcc());
    }

    public function getHeaders()
    {
        return new class($this->message->getHeaders()) {
            private $headers;

            public function __construct($headers)
            {
                $this->headers = $headers;
            }

            public function get($name)
            {
                $header = $this->headers->get($name);
                if (!$header) {
                    return null;
                }

                return new class($header) {
                    private $header;

                    public function __construct($header)
                    {
                        $this->header = $header;
                    }

                    public function getFieldBody()
                    {
                        return $this->header->getBodyAsString();
                    }
                };
            }
        };
    }

    /**
     * Attachments.
     *
     * @return CapturedAttachment[]
     */
    public function getChildren()
    {
        return array_map(function ($part) {
            return new CapturedAttachment($part);
        }, $this->message->getAttachments());
    }

    public function toString()
    {
        return $this->message->toString();
    }

    /**
     * [email => name or null], as SwiftMailer returned addresses.
     */
    protected function addresses(array $addresses)
    {
        $result = [];
        foreach ($addresses as $address) {
            $result[$address->getAddress()] = $address->getName() !== '' ? $address->getName() : null;
        }

        return $result;
    }
}
