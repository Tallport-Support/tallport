<?php

namespace Tests\Feature;

use App\Option;
use Tests\FeatureTestCase;

/**
 * App\Option: saving, defaults (from config/app.php and module configs),
 * reading several at once, and values kept in the old serialize() format.
 */
class OptionModelTest extends FeatureTestCase
{
    public function testSetIgnoresEmptyNameAndUnchangedValue()
    {
        $this->assertFalse(Option::set('  ', 'value'));
        $this->assertSame(0, Option::where('name', '')->count());

        Option::set('tallport_test_option', 'first');
        $this->assertFalse(Option::set('tallport_test_option', 'first'));

        Option::set('tallport_test_option', null);
        $this->assertSame('', Option::where('name', 'tallport_test_option')->value('value'));
    }

    /**
     * An object (with __toString()) is saved as its text, and the object passed isn't kept.
     */
    public function testSetObject()
    {
        $value = new class {
            public $text = 'object text';

            public function __toString()
            {
                return $this->text;
            }
        };

        Option::set('tallport_test_option', $value);
        $value->text = 'changed';

        $this->assertSame('object text', Option::get('tallport_test_option'));
    }

    public function testGetWithoutDecoding()
    {
        Option::set('tallport_test_option', ['a' => 1]);

        $this->assertSame('{"a":1}', Option::get('tallport_test_option', [], false));
        $this->assertSame(['a' => 1], Option::get('tallport_test_option', [], true, false));
    }

    /**
     * Without a default given, a module's option defaults to the one in the module's config.
     */
    public function testDefaultFromModuleConfig()
    {
        config(['tpmodule.options' => ['color' => ['default' => 'blue'], 'size' => []]]);

        $this->assertSame('blue', Option::get('tpmodule.color'));
        $this->assertFalse(Option::get('tpmodule.size'));
        $this->assertSame('given', Option::get('tpmodule.color', 'given'));
        $this->assertFalse(Option::get('nomodule'));
    }

    /**
     * Several options at once: saved values, then given defaults, then config defaults;
     * a second read comes from the in-memory cache.
     */
    public function testGetOptions()
    {
        config(['tpmodule.options' => ['color' => ['default' => 'blue']]]);
        Option::set('tallport_saved', ['a' => 1]);
        $names = ['tallport_saved', 'tallport_given', 'tpmodule.color'];
        $expected = ['tallport_saved' => ['a' => 1], 'tallport_given' => 'given', 'tpmodule.color' => 'blue'];

        $this->assertSame($expected, Option::getOptions($names, ['tallport_given' => 'given']));

        Option::where('name', 'tallport_saved')->delete();
        $this->assertSame($expected, Option::getOptions($names, ['tallport_given' => 'given']));

        // Not all of them cached: all are read again.
        $this->assertSame(
            ['tallport_saved' => false, 'tallport_other' => false],
            Option::getOptions(['tallport_saved', 'tallport_other'])
        );
    }

    /**
     * Values saved with serialize() by older versions are read, without objects.
     */
    public function testReadsSerializedValues()
    {
        Option::create(['name' => 'tallport_array', 'value' => serialize(['a' => 1, 'b' => 'two'])]);
        Option::create(['name' => 'tallport_null', 'value' => 'N;']);
        Option::create(['name' => 'tallport_object', 'value' => serialize(new \ArrayObject([1]))]);
        Option::create(['name' => 'tallport_broken', 'value' => 'a:2:{s:1:"a";}']);
        Option::create(['name' => 'tallport_json', 'value' => '{not json']);

        $this->assertSame(['a' => 1, 'b' => 'two'], Option::get('tallport_array'));
        $this->assertNull(Option::get('tallport_null', 'default'));
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Option::get('tallport_object'));
        $this->assertSame('a:2:{s:1:"a";}', Option::get('tallport_broken'));
        $this->assertSame('{not json', Option::get('tallport_json'));
    }

    public function testIsSerialized()
    {
        foreach (['N;', 'a:0:{}', 's:3:"abc";', 'b:1;', 'i:-12;', 'd:1.5E-3;', 'O:8:"stdClass":0:{}', ' i:1; '] as $data) {
            $this->assertTrue(Option::isSerialized($data), $data);
        }
        foreach ([1, null, ['a'], 'abc', 'i:1', 'i:12', 'ab:c;', 's:3:"abc;', 'x:1;', 'i:a;', 'a:x:{}'] as $data) {
            $this->assertFalse(Option::isSerialized($data), json_encode($data));
        }
    }
}
