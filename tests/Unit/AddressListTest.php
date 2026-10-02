<?php

namespace Tests\Unit;

use App\Incoming\Address;
use Tests\TestCase;

/**
 * Tallport's parsing of address headers (From, To, Cc, ...).
 */
class AddressListTest extends TestCase
{
    /**
     * @dataProvider addressHeaders
     */
    public function testParseList($value, $expected)
    {
        $addresses = array_map(function (Address $address) {
            return [$address->mail, $address->personal];
        }, Address::parseList($value));

        $this->assertSame($expected, $addresses);
    }

    public function addressHeaders()
    {
        return [
            'bare'                 => ['a@example.org', [['a@example.org', '']]],
            'angle'                => ['<a@example.org>', [['a@example.org', '']]],
            'name'                 => ['Ann Smith <a@example.org>', [['a@example.org', 'Ann Smith']]],
            'quoted name'          => ['"Smith, Ann" <a@example.org>', [['a@example.org', 'Smith, Ann']]],
            'escaped quotes'       => ['"This one: is \"right\"" <a@example.org>', [['a@example.org', 'This one: is "right"']]],
            'single quotes'        => ["'Ann' <a@example.org>", [['a@example.org', 'Ann']]],
            'list'                 => ['a@example.org, "B, b" <b@example.org>,c@example.org', [['a@example.org', ''], ['b@example.org', 'B, b'], ['c@example.org', '']]],
            'encoded name'         => ['=?UTF-8?Q?Caf=C3=A9?= <a@example.org>', [['a@example.org', 'Café']]],
            'encoded name folded'  => ["=?UTF-8?B?0KLRgNCw0LvQsNC70LXQu9C+INCi0YDQsNC70LDQu9CwINCi0YDQsNC70LA=?=\r\n =?UTF-8?B?0LvQtdC70L7QstC90LA=?= <a@example.org>", [['a@example.org', 'Тралалело Тралала Тралалеловна']]],
            'quoted encoded name'  => ['"=?UTF-8?Q?Caf=C3=A9?=" <a@example.org>', [['a@example.org', 'Café']]],
            'comment as name'      => ['a@example.org (Ann Smith)', [['a@example.org', 'Ann Smith']]],
            'comment ignored'      => ['Ann <a@example.org> (work)', [['a@example.org', 'Ann']]],
            'group'                => ['Team: a@example.org, b@example.org;', [['a@example.org', ''], ['b@example.org', '']]],
            'empty group'          => ['Undisclosed recipients:;', []],
            'empty address'        => ['"Undisclosed Recipients" <>', []],
            'no host'              => ['no_host', [['no_host@unknown', '']]],
            'stray words'          => ['"postmaster@" <sending_domain.tld postmaster@sending_domain.tld>', [['postmaster@sending_domain.tld', 'postmaster@']]],
            'missing bracket'      => ['Ann <a@example.org', [['a@example.org', 'Ann']]],
            'empty'                => ['', []],
        ];
    }
}
