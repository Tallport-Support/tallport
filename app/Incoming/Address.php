<?php

namespace App\Incoming;

/**
 * An email address from a header: "personal" is the display name.
 */
class Address
{
    /**
     * The email address.
     *
     * @var string
     */
    public $mail;

    /**
     * The display name, or ''.
     *
     * @var string
     */
    public $personal;

    public function __construct($mail, $personal = '')
    {
        $this->mail = (string) $mail;
        $this->personal = (string) $personal;
    }
}
