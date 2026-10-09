<?php

namespace Tests\Unit;

use App\Ai\PartialJson;
use PHPUnit\Framework\TestCase;

/**
 * Streamed AI answers read while they're written (App\Ai\PartialJson).
 */
class PartialJsonTest extends TestCase
{
    public function testWhatIsWrittenSoFar()
    {
        $cases = [
            ''                                              => [],
            'Sure! '                                        => [],
            '{'                                             => [],
            '{"transl'                                      => [],
            '{"translation":'                               => [],
            '{"translation": "Hallo, wie'                   => ['translation' => 'Hallo, wie'],
            '{"translation": "Line\nNext \"quoted\" and \\' => ['translation' => "Line\nNext \"quoted\" and "],
            '{"translation": "Caf\u00'                      => ['translation' => 'Caf'],
            '{"translation": "Café", "same_language": tr' => ['translation' => 'Café'],
            '{"translation": "x", "same_language": false, "n": 12' => ['translation' => 'x', 'same_language' => false],
            '{"messages": [{"id": 5, "translation": "One"}, {"id": 6, "tra' => ['messages' => [['id' => 5, 'translation' => 'One'], ['id' => 6]]],
            '{"messages": [{"id": 5, "translation": "One"}, {"id": 6, "translation": "Tw' => ['messages' => [['id' => 5, 'translation' => 'One'], ['id' => 6, 'translation' => 'Tw']]],
            "```json\n{\"draft\": \"Hallo **Casey**"        => ['draft' => 'Hallo **Casey**'],
            '{"a": "done"} and more {"b": 1}'               => ['a' => 'done'],
        ];
        foreach ($cases as $text => $expected) {
            $this->assertSame($expected, PartialJson::decode($text), $text);
        }
        // A character cut in two.
        $this->assertSame(['t' => 'Caf'], PartialJson::decode('{"t": "Caf'.substr('é', 0, 1)));
    }

    public function testTheWholeAnswer()
    {
        $this->assertSame(['a' => 1], PartialJson::decodeComplete(' {"a": 1} '));
        $this->assertSame(['a' => 1], PartialJson::decodeComplete("```json\n{\"a\": 1}\n```"));
        $this->assertSame(['a' => 1], PartialJson::decodeComplete('Here it is: {"a": 1}. Done.'));
        $this->assertNull(PartialJson::decodeComplete('{"a": "unfinished'));
        $this->assertNull(PartialJson::decodeComplete('No JSON at all'));

        // A value encoded twice by the model: the text inside it, without quotes and \n escapes.
        $twice = json_encode(['translation' => json_encode("<div>Hello,</div>\n<div>Thanks</div>"), 'same_language' => false]);
        $this->assertSame(['translation' => "<div>Hello,</div>\n<div>Thanks</div>", 'same_language' => false], PartialJson::decodeComplete($twice));
        // Also when the model left an HTML attribute's quotes unescaped inside it.
        $loose = json_encode(['translation' => "\n\"\\n<p>I don't see it.</p>\\n<img src=\"https://example.org/a.png\">\\n\\\"Quoted\\\"\\n\"\n"]);
        $this->assertSame(['translation' => "\n<p>I don't see it.</p>\n<img src=\"https://example.org/a.png\">\n\"Quoted\"\n"], PartialJson::decodeComplete($loose));
        // Text that only happens to be quoted stays.
        $this->assertSame(['note' => '"Quoted" and more'], PartialJson::decodeComplete('{"note": "\\"Quoted\\" and more"}'));
    }
}
