<?php

namespace Tests\Support;

/**
 * An attachment of a captured email (see CapturedEmail).
 */
class CapturedAttachment
{
    /**
     * @var \Symfony\Component\Mime\Part\DataPart
     */
    public $part;

    public function __construct($part)
    {
        $this->part = $part;
    }

    public function getFilename()
    {
        return $this->part->getFilename();
    }

    public function getBody()
    {
        return $this->part->getBody();
    }
}
