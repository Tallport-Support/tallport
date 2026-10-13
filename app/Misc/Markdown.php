<?php

namespace App\Misc;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;

/**
 * Markdown written in the editor (FruitUI's mode="markdown"), rendered to HTML
 * where it is shown or sent. A single line break is a line break, raw HTML is
 * left out, and only the formats given are rendered (FruitUI's: bold, italic,
 * bulletList, orderedList, blockquote, link, image; null: all, also headings
 * and code); the rest shows as plain text.
 */
class Markdown
{
    const FORMATS = ['bold', 'italic', 'bulletList', 'orderedList', 'blockquote', 'link', 'image'];

    public static function toHtml($markdown, ?array $formats = null)
    {
        $markdown = (string) $markdown;
        if (trim($markdown) === '') {
            return '';
        }

        $environment = new Environment([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
            'renderer'           => ['soft_break' => "<br />\n"],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AutolinkExtension());
        if ($formats !== null) {
            $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event) use ($formats) {
                self::limit($event->getDocument(), $formats);
            });
        }

        return trim((string) (new MarkdownConverter($environment))->convert($markdown));
    }

    /**
     * Whether a text is edited in Markdown: it was written in Markdown, or there's
     * nothing yet. A text written in rich text before stays rich text.
     */
    public static function isMarkdown($markdown, $html)
    {
        return $markdown !== null || trim(str_replace('&nbsp;', ' ', strip_tags((string) $html, '<img>'))) === '';
    }

    /**
     * What an editor posted (x-editor) as [html, markdown]: in Markdown, its name
     * ends in _markdown and the HTML is rendered from it; in rich text, the HTML
     * as written and no Markdown. Null when the field wasn't posted.
     */
    public static function fromInput(array $input, $name, ?array $formats = null)
    {
        if (array_key_exists($name.'_markdown', $input)) {
            // Stored with plain line breaks (forms send \r\n).
            $markdown = str_replace(["\r\n", "\r"], "\n", (string) $input[$name.'_markdown']);

            return [self::toHtml($markdown, $formats), $markdown];
        }
        if (array_key_exists($name, $input)) {
            return [(string) $input[$name], null];
        }

        return null;
    }

    /**
     * Formats not allowed become their text.
     */
    protected static function limit(Node $document, array $formats)
    {
        $nodes = [];
        foreach ($document->iterator() as $node) {
            $nodes[] = $node;
        }
        // Innermost first, so a replaced node's children are already plain.
        foreach (array_reverse($nodes) as $node) {
            if (($node instanceof Strong && !in_array('bold', $formats))
                || ($node instanceof Emphasis && !in_array('italic', $formats))
                || ($node instanceof Link && !in_array('link', $formats))
                || ($node instanceof Image && !in_array('image', $formats))
                || ($node instanceof BlockQuote && !in_array('blockquote', $formats))
            ) {
                self::unwrap($node);
            } elseif ($node instanceof ListBlock) {
                $ordered = $node->getListData()->type == ListBlock::TYPE_ORDERED;
                if (!in_array($ordered ? 'orderedList' : 'bulletList', $formats)) {
                    $number = (int) ($node->getListData()->start ?? 1);
                    foreach ($node->children() as $item) {
                        if ($item instanceof ListItem && $item->firstChild()) {
                            $marker = $ordered ? ($number++).'. ' : '- ';
                            $first = $item->firstChild();
                            $first instanceof Paragraph ? $first->prependChild(new Text($marker)) : $item->prependChild(self::paragraph($marker));
                        }
                        self::unwrap($item);
                    }
                    self::unwrap($node);
                }
            } elseif ($node instanceof Heading) {
                $paragraph = new Paragraph();
                foreach ($node->children() as $child) {
                    $paragraph->appendChild($child);
                }
                $node->replaceWith($paragraph);
            } elseif ($node instanceof Code) {
                $node->replaceWith(new Text($node->getLiteral()));
            } elseif ($node instanceof FencedCode || $node instanceof IndentedCode) {
                $node->replaceWith(self::paragraph(rtrim($node->getLiteral(), "\n")));
            } elseif ($node instanceof ThematicBreak) {
                $node->detach();
            }
        }
    }

    protected static function unwrap(Node $node)
    {
        foreach ($node->children() as $child) {
            $node->insertBefore($child);
        }
        $node->detach();
    }

    /**
     * A paragraph of text, its lines kept.
     */
    protected static function paragraph($text)
    {
        $paragraph = new Paragraph();
        foreach (explode("\n", $text) as $i => $line) {
            if ($i) {
                $paragraph->appendChild(new Newline(Newline::SOFTBREAK));
            }
            $paragraph->appendChild(new Text($line));
        }

        return $paragraph;
    }
}
