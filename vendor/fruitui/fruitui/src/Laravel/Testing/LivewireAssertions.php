<?php

namespace FruitUI\Testing;

use Livewire\Features\SupportTesting\Testable;
use PHPUnit\Framework\Assert;

/**
 * Assertions for FruitUI\Fruit feedback on Livewire::test():
 *
 *     Livewire::test(Inbox::class)->call('archive')->assertToasted('Archived.')->assertDialogClosed('confirm');
 */
final class LivewireAssertions
{
    public static function register(): void
    {
        /** A toast was dispatched in this request or flashed for the next page; with a message, that exact text. */
        Testable::macro('assertToasted', function (?string $message = null): Testable {
            /** @var Testable $this */
            $flashed = session('fruit-toast');
            if ($flashed !== null && ($message === null || $flashed === $message)) {
                Assert::assertTrue(true);

                return $this;
            }

            return $message === null
                ? $this->assertDispatched('fruit-toast')
                : $this->assertDispatched('fruit-toast', message: $message);
        });

        Testable::macro('assertNotToasted', function (): Testable {
            /** @var Testable $this */
            Assert::assertNull(session('fruit-toast'), 'A toast was flashed for the next page.');

            return $this->assertNotDispatched('fruit-toast');
        });

        Testable::macro('assertDialogOpened', function (string $name): Testable {
            /** @var Testable $this */
            return $this->assertDispatched('fruit-dialog-open', name: $name);
        });

        Testable::macro('assertDialogClosed', function (string $name): Testable {
            /** @var Testable $this */
            return $this->assertDispatched('fruit-dialog-close', name: $name);
        });
    }
}
