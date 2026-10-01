<?php

namespace App\Incoming;

/**
 * An attachment of an incoming message.
 *
 * Modules receive attachments in the fetch_emails.data_to_save hook. While
 * messages come from App\LegacyImap, anything this class doesn't have is
 * passed on to the original webklex attachment, so modules keep working.
 */
class Attachment
{
    /**
     * Content-ID (for inline images referenced as cid:...), or null.
     *
     * @var string|null
     */
    public $id;

    /**
     * Content-Type header value.
     *
     * @var string|null
     */
    public $content_type;

    /**
     * Decoded content.
     *
     * @var string|null
     */
    public $content;

    protected $name;

    protected $type;

    protected $source;

    /**
     * Create an attachment.
     *
     * @param  string|null  $name
     * @param  string|null  $type  Main type, e.g. "image" or "message".
     * @param  string|null  $content_type
     * @param  string|null  $content
     * @param  string|null  $id
     * @param  object|null  $source  The library's attachment, if any.
     */
    public function __construct($name, $type, $content_type, $content, $id = null, $source = null)
    {
        $this->name = $name;
        $this->type = $type;
        $this->content_type = $content_type;
        $this->content = $content;
        $this->id = $id;
        $this->source = $source;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getContent()
    {
        return $this->content;
    }

    public function getMimeType()
    {
        return (new \finfo())->buffer((string) $this->content, FILEINFO_MIME_TYPE);
    }

    public function __call($method, $arguments)
    {
        if ($this->source) {
            return $this->source->$method(...$arguments);
        }

        throw new \BadMethodCallException('Method '.static::class.'::'.$method.'() does not exist.');
    }

    public function __get($name)
    {
        return $this->source ? $this->source->$name : null;
    }
}
