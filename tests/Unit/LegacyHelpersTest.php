<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The global helpers FreeScout modules use: Laravel 5.5's str_* and array_*
 * helpers (removed from Laravel, kept in app/Misc/LegacyHelpers.php) and
 * FreeScout's own (app/Misc/Functions.php).
 */
class LegacyHelpersTest extends TestCase
{
    /**
     * Mark a test of intended behaviour as blocked by a bug in KNOWN_BUGS.md.
     */
    protected function knownBug($id)
    {
        $this->markTestIncomplete("Known bug $id, see KNOWN_BUGS.md");
    }

    public function testArrayHelpers()
    {
        $this->assertSame(['a' => 1, 'b' => 2], array_add(['a' => 1], 'b', 2));
        $this->assertSame(['a' => 1], array_add(['a' => 1], 'a', 2), 'Only when missing.');
        $this->assertSame([1, 2, 3], array_collapse([[1], [2, 3]]));
        $this->assertSame([['a', 'b'], [1, 2]], array_divide(['a' => 1, 'b' => 2]));
        $this->assertSame(['user.name' => 'Casey', 'user.id' => 7], array_dot(['user' => ['name' => 'Casey', 'id' => 7]]));
        $this->assertSame(['x.user.id' => 7], array_dot(['user' => ['id' => 7]], 'x.'));
        $this->assertSame(['b' => 2], array_except(['a' => 1, 'b' => 2], ['a']));
        $this->assertSame([1, 2, 3], array_flatten([1, [2, [3]]]));
        $this->assertSame([1, 2, [3]], array_flatten([1, [2, [3]]], 1));

        $array = ['user' => ['name' => 'Casey', 'id' => 7]];
        array_forget($array, 'user.id');
        $this->assertSame(['user' => ['name' => 'Casey']], $array);

        $this->assertSame('Casey', array_get($array, 'user.name'));
        $this->assertSame('none', array_get($array, 'user.email', 'none'));
        $this->assertTrue(array_has($array, 'user.name'));
        $this->assertFalse(array_has($array, ['user.name', 'user.email']));
        $this->assertSame(['a' => 1], array_only(['a' => 1, 'b' => 2], ['a']));
        $this->assertSame(['Casey', 'Sam'], array_pluck([['name' => 'Casey'], ['name' => 'Sam']], 'name'));
        $this->assertSame([7 => 'Casey'], array_pluck([['id' => 7, 'name' => 'Casey']], 'name', 'id'));
        $this->assertSame(['z' => 0, 'a' => 1], array_prepend(['a' => 1], 0, 'z'));

        $array = ['a' => 1, 'b' => 2];
        $this->assertSame(1, array_pull($array, 'a'));
        $this->assertSame('none', array_pull($array, 'a', 'none'));
        $this->assertSame(['b' => 2], $array);

        $this->assertContains(array_random([1, 2, 3]), [1, 2, 3]);
        $this->assertCount(2, array_random([1, 2, 3], 2));

        $array = [];
        array_set($array, 'user.name', 'Casey');
        $this->assertSame(['user' => ['name' => 'Casey']], $array);

        $this->assertSame([1 => 'a', 0 => 'b'], array_sort(['b', 'a']));
        $this->assertSame([1 => ['n' => 1], 0 => ['n' => 2]], array_sort([['n' => 2], ['n' => 1]], function ($item) {
            return $item['n'];
        }));
        $this->assertSame(['a' => [1, 2], 'b' => 1], array_sort_recursive(['b' => 1, 'a' => [2, 1]]));
        $this->assertSame([1 => 2, 3 => 4], array_where([1, 2, 3, 4], function ($value) {
            return $value % 2 == 0;
        }));
        $this->assertSame(['a'], array_wrap('a'));
        $this->assertSame([], array_wrap(null));
        $this->assertSame(['a'], array_wrap(['a']));
        $this->assertSame(3, array_first_cond([1, 3, 4], function ($value) {
            return $value > 2;
        }));
        $this->assertSame('none', array_first_cond([], null, 'none'));
    }

    /**
     * Without a key, array_prepend() adds the value as the first list item (Laravel 5.5).
     */
    public function testArrayPrependWithoutKey()
    {
        $this->knownBug('H5');

        $this->assertSame([0, 1, 2], array_prepend([1, 2], 0));
    }

    public function testStringHelpers()
    {
        $this->assertSame('fooBar', camel_case('foo_bar'));
        $this->assertTrue(ends_with('report.pdf', ['.doc', '.pdf']));
        $this->assertFalse(ends_with('report.pdf', '.doc'));
        $this->assertSame('foo-bar', kebab_case('fooBar'));
        $this->assertSame('foo_bar', snake_case('fooBar'));
        $this->assertSame('foo-bar', snake_case('fooBar', '-'));
        $this->assertTrue(starts_with('report.pdf', 'rep'));
        $this->assertFalse(starts_with('report.pdf', ['x', 'y']));
        $this->assertSame('example.org', str_after('casey@example.org', '@'));
        $this->assertSame('casey', str_before('casey@example.org', '@'));
        $this->assertSame('path/', str_finish('path', '/'));
        $this->assertSame('path/', str_finish('path/', '/'));
        $this->assertTrue(str_is('mailboxes.*', 'mailboxes.view'));
        $this->assertFalse(str_is('mailboxes.*', 'users.view'));
        $this->assertSame('Hello...', str_limit('Hello world', 5));
        $this->assertSame('Hello »', str_limit('Hello world', 5, ' »'));
        $this->assertSame('mailboxes', str_plural('mailbox'));
        $this->assertSame('mailbox', str_plural('mailbox', 1));
        $this->assertSame(16, strlen(str_random()));
        $this->assertSame(40, strlen(str_random(40)));
        $this->assertSame('1 and 2', str_replace_array('?', ['1', '2'], '? and ?'));
        $this->assertSame('b a a', str_replace_first('a', 'b', 'a a a'));
        $this->assertSame('a a b', str_replace_last('a', 'b', 'a a a'));
        $this->assertSame('mailbox', str_singular('mailboxes'));
        $this->assertSame('hello-world', str_slug('Hello World'));
        $this->assertSame('hello_world', str_slug('Hello World', '_'));
        $this->assertSame('/path', str_start('path', '/'));
        $this->assertSame('/path', str_start('/path', '/'));
        $this->assertSame('FooBar', studly_case('foo_bar'));
        $this->assertSame('Hello World', title_case('hello world'));
    }

    /**
     * __j(): a translation safe inside a quoted JavaScript string.
     */
    public function testTranslationForJavascript()
    {
        $this->assertSame('Casey&#039;s &quot;draft&quot;', __j(':name\'s "draft"', ['name' => 'Casey']));
    }

    /**
     * __h(): a translation with the text escaped for HTML.
     */
    public function testTranslationForHtml()
    {
        $this->assertSame('&lt;b&gt; <b>Casey</b>', __h('<b> :name', ['name' => '<b>Casey</b>']));
    }

    /**
     * __h() escapes the replacements too when asked.
     */
    public function testTranslationForHtmlWithEscapedReplacements()
    {
        $this->knownBug('H4');
        $this->assertSame('&lt;b&gt;Casey&lt;/b&gt; and Sam', __h(':name and :other', ['name' => '<b>Casey</b>', 'other' => 'Sam'], null, true));
    }

    /**
     * isActive(): the "active" class of the web installer's steps.
     */
    public function testIsActive()
    {
        $this->app['router']->get('/install-test/step', function () {
            return 'ok';
        })->name('install-test.step');
        $this->get('/install-test/step')->assertOk();

        $this->assertSame('active', isActive('install-test.step'));
        $this->assertSame('current', isActive('install-test.step', 'current'));
        $this->assertSame('active', isActive(['install-test.other', 'install-test.step']));
        $this->assertSame('', isActive(['install-test.other']));
        $this->assertSame('active', isActive('install-test/'), 'Part of the URL.');
        $this->assertEmpty(isActive('install-test.other'));
    }
}
