<?php

namespace Tests\Unit;

use App\AutoReply\LanguageDetector;
use PHPUnit\Framework\TestCase;

/**
 * Recognising Chinese, Japanese and Korean from the characters used, for
 * auto replies (\App\AutoReply\LanguageDetector).
 */
class AutoReplyLanguageDetectorTest extends TestCase
{
    protected function detector(array $options = [])
    {
        return new LanguageDetector($options);
    }

    public function testEmptyAndEnglishTextFallBackToEnglish()
    {
        $detector = $this->detector();

        $this->assertSame('en', $detector->detect(''));
        $this->assertSame('en', $detector->detect("   \n  "));
        $this->assertSame('en', $detector->detect('Hi, my order #12345 has not arrived yet. Can you check? Thanks, John'));
        $this->assertSame('en', $detector->detect('12345 !!! ???'));
    }

    public function testOtherAlphabeticLanguagesFallBackToEnglish()
    {
        $detector = $this->detector();

        $this->assertSame('en', $detector->detect('Hallo, meine Bestellung ist noch nicht angekommen. Können Sie das prüfen?'));
        $this->assertSame('en', $detector->detect('Здравствуйте, мой заказ ещё не пришёл. Можете проверить?'));
        $this->assertSame('en', $detector->detect('مرحبا، لم يصل طلبي بعد. هل يمكنكم التحقق؟'));
    }

    public function testChinese()
    {
        $detector = $this->detector();

        // Simplified.
        $this->assertSame('zh', $detector->detect('您好，我的订单还没有收到，请帮忙查询一下。谢谢！'));
        // Traditional.
        $this->assertSame('zh', $detector->detect('您好，我的訂單還沒有收到，請幫忙查詢一下。謝謝！'));
        // Very short.
        $this->assertSame('zh', $detector->detect('退款'));
    }

    public function testJapanese()
    {
        $detector = $this->detector();

        $this->assertSame('ja', $detector->detect('お世話になっております。注文した商品がまだ届いていません。確認をお願いいたします。'));
        // Kanji-heavy business Japanese.
        $this->assertSame('ja', $detector->detect('御社製品の不具合について至急確認をお願い致します。'));
        // Katakana only.
        $this->assertSame('ja', $detector->detect('パスワードリセットをお願いします'));
    }

    public function testKorean()
    {
        $detector = $this->detector();

        $this->assertSame('ko', $detector->detect('안녕하세요, 주문한 상품이 아직 도착하지 않았습니다. 확인 부탁드립니다.'));
        // With a few hanja.
        $this->assertSame('ko', $detector->detect('안녕하세요, 注文 관련하여 문의드립니다. 確認 부탁드립니다.'));
    }

    public function testEnglishMessageWithCjkSignatureStaysEnglish()
    {
        $detector = $this->detector();

        $text = "Hello support team,\n\nI bought your product last week and the battery drains very fast. Is there a firmware update available? I have already tried resetting the device twice.\n\nBest regards,\n山田太郎\n株式会社サンプル";
        $this->assertSame('en', $detector->detect($text));

        $text = "Hello, I cannot log in to my account since yesterday. Please help.\n\n감사합니다\n김민준";
        $this->assertSame('en', $detector->detect($text));
    }

    public function testCjkMessageWithEnglishProductNamesUrlsAndSignature()
    {
        $detector = $this->detector();

        $text = "こんにちは。\niPhone 15 Pro Max用のケースを注文しましたが、まだ届いていません。\n注文番号: ORDER-2024-0001\nhttps://example.com/orders/2024-0001\nよろしくお願いします。\n\nBest regards,\nTaro Yamada\nSample Corporation";
        $this->assertSame('ja', $detector->detect($text));

        $text = "您好，我在 Amazon 上购买的 Kindle Paperwhite 无法开机。\n订单号 123-4567890\n请联系 support@example.com 处理。";
        $this->assertSame('zh', $detector->detect($text));
    }

    public function testChineseWithKatakanaProductNameIsNotJapanese()
    {
        $detector = $this->detector();

        $text = '您好，我购买的ポケモン卡片套装缺少了两张卡，请问可以补发吗？谢谢您的帮助。';
        $this->assertSame('zh', $detector->detect($text));

        // Short Chinese message with a katakana brand name.
        $this->assertSame('zh', $detector->detect('ポケモン卡片套装缺少了两张卡'));

        // Katakana-only Japanese is still Japanese.
        $this->assertSame('ja', $detector->detect('パスワードリセット'));
        $this->assertSame('ja', $detector->detect('キャンセル希望'));
    }

    public function testQuotedMessagesAreIgnored()
    {
        $detector = $this->detector();

        // Chinese reply on top of a quoted English message.
        $text = "您好，我的订单还没有收到。\n\nOn Mon, Jan 1, 2024 at 10:00 AM Support <support@example.com> wrote:\nThank you for contacting us. We have shipped your order and it should arrive within five business days. Please let us know if you have any other questions.";
        $this->assertSame('zh', $detector->detect($text));

        // English reply on top of a quoted Japanese message using > prefixes.
        $text = "Thanks, that solved it!\n\n> お世話になっております。\n> 注文した商品がまだ届いていません。確認をお願いいたします。\n> よろしくお願いいたします。";
        $this->assertSame('en', $detector->detect($text));

        // English reply on top of an Outlook style forwarded Korean message.
        $text = "Please see the message below.\n\nFrom: 홍길동\nSent: Monday\n안녕하세요, 주문한 상품이 아직 도착하지 않았습니다. 확인 부탁드립니다. 감사합니다.";
        $this->assertSame('en', $detector->detect($text));

        // Japanese reply on top of a Gmail quote header in Japanese.
        $text = "ありがとうございます。確認しました。\n\n2024年1月1日(月) 15:00 Support <support@example.com>:\nThank you for contacting us. We have shipped your order and it should arrive within five business days.";
        $this->assertSame('ja', $detector->detect($text));
    }

    public function testQuoteOnlyMessageFallsBackToWholeText()
    {
        $detector = $this->detector();

        $text = "> 您好，我的订单还没有收到，请帮忙查询一下。\n> 谢谢！";
        $this->assertSame('zh', $detector->detect($text));
    }

    public function testSignatureIsIgnored()
    {
        $detector = $this->detector();

        $text = "Hi, I need an invoice for my last order.\n-- \n株式会社サンプル 営業部 山田太郎 東京都港区芝公園";
        $this->assertSame('en', $detector->detect($text));

        $text = "Hi, I need an invoice for my last order.\n\niPhoneから送信\n株式会社サンプル 営業部 山田太郎 東京都港区芝公園";
        $this->assertSame('en', $detector->detect($text));
    }

    public function testAnalyzeReturnsCountsAndRatios()
    {
        $analysis = $this->detector()->analyze('Hello 世界');

        $this->assertSame(5, $analysis['counts']['latin']);
        $this->assertSame(2, $analysis['counts']['han']);
        $this->assertSame(0, $analysis['counts']['kana']);
        $this->assertSame(0, $analysis['counts']['hiragana']);
        $this->assertSame(0, $analysis['counts']['katakana']);
        $this->assertSame(0, $analysis['counts']['hangul']);
        $this->assertEqualsWithDelta(2 / 3, $analysis['cjk_ratio'], 0.0001);
        $this->assertSame('zh', $analysis['language']);
    }

    public function testOptionsAreConfigurable()
    {
        // Require almost all content to be CJK.
        $strict = $this->detector(['min_cjk_ratio' => 0.95]);
        $this->assertSame('en', $strict->detect('Hello 世界'));
        $this->assertSame('zh', $strict->detect('您好世界'));

        // A different default language.
        $french = $this->detector(['default_language' => 'fr']);
        $this->assertSame('fr', $french->detect('Hello'));
        $this->assertSame('ja', $french->detect('こんにちは'));
    }

    public function testInvalidUtf8DoesNotBreakDetection()
    {
        $this->assertSame('en', $this->detector()->detect("Hello \xB1\x31 world"));
    }

    public function testSimplifiedAndTraditionalChinese()
    {
        $detector = $this->detector();

        $this->assertSame('zh-Hans', $detector->chineseVariant('你好，我的软件无法连接，请帮忙检查一下。'));
        $this->assertSame('zh-Hant', $detector->chineseVariant('你好，我的軟體無法連線，請幫忙檢查一下。'));
        // Characters both use: Simplified.
        $this->assertSame('zh-Hans', $detector->chineseVariant('中文'));
    }

    public function testCountingInvalidUtf8CountsNothing()
    {
        $counts = $this->detector()->countScripts("\xB1\x31 \xFF");

        $this->assertSame(0, $counts['letters']);
        $this->assertSame(0, $counts['hangul']);
        $this->assertSame(0, $counts['kana']);
    }

    /**
     * Neither clearly Korean nor clearly Japanese, and no Chinese characters: the
     * script with more characters.
     */
    public function testKanaAndHangulWithoutAClearWinner()
    {
        $undecided = $this->detector(['min_hangul_ratio' => 0.99, 'katakana_only_ratio' => 0.99]);

        $this->assertSame('ja', $undecided->detect('アイウエ한한한'));
        $this->assertSame('ko', $undecided->detect('アイ한한한'));
    }
}
