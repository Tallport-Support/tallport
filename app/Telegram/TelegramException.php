<?php

namespace App\Telegram;

/**
 * An error from the Telegram Bot API (or reaching it).
 */
class TelegramException extends \Exception
{
    /**
     * Seconds Telegram asks to wait before trying again (too many requests).
     */
    public $retry_after;

    public function __construct($message, $code = 0, $retry_after = 0)
    {
        parent::__construct($message, $code);
        $this->retry_after = $retry_after;
    }

    /**
     * Whether sending again can't help: the bot was blocked, the chat is
     * gone, the token is wrong or the request is refused.
     */
    public function isPermanent()
    {
        return in_array($this->getCode(), [400, 401, 403, 404]);
    }
}
