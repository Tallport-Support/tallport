<?php

namespace Tests\Unit;

use App\Search\ConversationSearch;
use App\Search\SearchQuery;
use Tests\TestCase;

/**
 * Reading what is typed in the search box, and the excerpts of results.
 */
class SearchQueryTest extends TestCase
{
    public function testWordsPhrasesExclusionsAndOperators()
    {
        $query = SearchQuery::parse('refund  "winter jacket" -invoice from:"Robin Buyer" to:sales@example.com subject:zipper is:closed has:attachment after:2026-01-31 https://example.com/x 10:30');

        $this->assertSame([
            ['text' => 'refund', 'phrase' => false, 'exclude' => false, 'field' => null],
            ['text' => 'winter jacket', 'phrase' => true, 'exclude' => false, 'field' => null],
            ['text' => 'invoice', 'phrase' => false, 'exclude' => true, 'field' => null],
            ['text' => 'Robin Buyer', 'phrase' => true, 'exclude' => false, 'field' => 'people'],
            ['text' => 'sales@example.com', 'phrase' => false, 'exclude' => false, 'field' => 'recipients'],
            ['text' => 'zipper', 'phrase' => false, 'exclude' => false, 'field' => 'subject'],
            ['text' => 'https://example.com/x', 'phrase' => false, 'exclude' => false, 'field' => null],
            ['text' => '10:30', 'phrase' => false, 'exclude' => false, 'field' => null],
        ], $query->terms);
        $this->assertSame(['is' => ['closed'], 'has' => ['attachment'], 'after' => ['2026-01-31']], $query->conditions);
        $this->assertTrue($query->hasTerms());
        $this->assertFalse(SearchQuery::parse('-invoice is:open')->hasTerms());
    }

    public function testNumber()
    {
        $this->assertSame(123, SearchQuery::parse('#123')->number());
        $this->assertSame(123, SearchQuery::parse('123')->number());
        $this->assertNull(SearchQuery::parse('123 refund')->number());
        $this->assertNull(SearchQuery::parse('-123')->number());
    }

    public function testFullTextSyntax()
    {
        $term = function ($text, $phrase = false, $exclude = false) {
            return SearchQuery::booleanTerm(['text' => $text, 'phrase' => $phrase, 'exclude' => $exclude, 'field' => null]);
        };
        $this->assertSame('+refund*', $term('Refund'));
        $this->assertSame('-refund', $term('refund', false, true));
        $this->assertSame('+"winter jacket"', $term('winter jacket', true));
        $this->assertSame('+"robin buyer example"', $term('robin@buyer.example'), 'An address: its words in order.');
        $this->assertSame('+"don t"', $term('don\'t"+*('), 'Operators typed are not passed on.');

        $this->assertTrue(SearchQuery::isIndexable('refund'));
        $this->assertFalse(SearchQuery::isIndexable('qr'), 'Shorter than innodb_ft_min_token_size.');
        $this->assertFalse(SearchQuery::isIndexable('robin@x.example'));
        $this->assertFalse(SearchQuery::isIndexable('服务'), 'No spaces between words.');
    }

    /**
     * PostgreSQL's tsquery and SQLite's FTS5 syntax, with the same meaning as MariaDB's.
     */
    public function testFullTextSyntaxOfOtherDatabases()
    {
        $term = function ($text, $phrase = false, $exclude = false) {
            return ['text' => $text, 'phrase' => $phrase, 'exclude' => $exclude, 'field' => null];
        };
        $this->assertSame('refund:*', SearchQuery::tsqueryTerm($term('Refund')));
        $this->assertSame('refund:*A', SearchQuery::tsqueryTerm($term('refund'), 'A'));
        $this->assertSame('!(refund)', SearchQuery::tsqueryTerm($term('refund', false, true)));
        $this->assertSame('(winter:B <-> jacket:B)', SearchQuery::tsqueryTerm($term('winter jacket', true), 'B'));
        $this->assertSame('(robin <-> buyer <-> example)', SearchQuery::tsqueryTerm($term('robin@buyer.example')));
        $this->assertSame('(don <-> t)', SearchQuery::tsqueryTerm($term('don\'t"&|!:*(')), 'Operators typed are not passed on.');
        $this->assertSame(['foo', 'bar', '0'], SearchQuery::words('Foo_bar 0'));
        if (class_exists(\Normalizer::class)) {
            $this->assertSame(['creme', 'brulee'], SearchQuery::words('Crème Brûlée'), 'Without accents.');
        }

        $this->assertSame('"refund"*', SearchQuery::fts5Term($term('Refund')));
        $this->assertSame('subject : "winter jacket"', SearchQuery::fts5Term($term('winter jacket', true), 'subject'));
        $this->assertSame('("refund"* AND "don t") NOT "invoice" NOT "robin buyer"', SearchQuery::fts5Query([
            $term('refund'), $term('invoice', false, true), $term('don\'t"*'), $term('robin@buyer', false, true),
        ]));
        $this->assertSame('(people : "robin"*)', SearchQuery::fts5Query([$term('robin')], 'people'));
    }

    public function testSnippet()
    {
        $terms = SearchQuery::parse('jacket "winter coat"')->terms;
        $text = str_repeat('Lorem ipsum dolor sit amet. ', 5).'My <winter coat> and jackets & more. '.str_repeat('Consectetur adipiscing elit. ', 10);

        $snippet = ConversationSearch::snippet($text, $terms);

        $this->assertStringStartsWith('…', $snippet);
        $this->assertStringEndsWith('…', $snippet);
        $this->assertStringContainsString('My &lt;<mark>winter coat</mark>&gt; and <mark>jackets</mark> &amp; more.', $snippet);
        $this->assertNull(ConversationSearch::snippet('Nothing here', $terms));
        $this->assertNull(ConversationSearch::snippet('A blackjacket', $terms), 'Words from their start.');
        $this->assertSame('VPN<mark>服务</mark>费用', ConversationSearch::snippet('VPN服务费用', SearchQuery::parse('服务')->terms));
        $this->assertSame('<mark>VPN</mark>服务费用', ConversationSearch::snippet('VPN服务费用', SearchQuery::parse('vpn')->terms));
    }
}
