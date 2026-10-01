<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The email rule rejects line breaks, which could inject mail headers.
 */
class EmailValidationTest extends TestCase
{
    public function testEmailWithLineBreakIsInvalid()
    {
        $this->assertTrue(Validator::make(['email' => 'jane@example.com'], ['email' => 'email'])->passes());
        $this->assertTrue(Validator::make(['email' => "jane@example.com\r\nBcc: x@example.com"], ['email' => 'email'])->fails());
        $this->assertTrue(Validator::make(['email' => "jane@example.com\nBcc: x@example.com"], ['email' => 'email'])->fails());
    }
}
