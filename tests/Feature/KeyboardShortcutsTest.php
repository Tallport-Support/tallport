<?php

namespace Tests\Feature;

use Tests\FeatureTestCase;

/**
 * Keyboard shortcuts: on unless a user turns them off; ? lists them.
 */
class KeyboardShortcutsTest extends FeatureTestCase
{
    public function testOnUnlessTurnedOff()
    {
        $user = $this->createUser();
        $mailbox = $this->createMailbox([$user]);

        $this->actingAs($user)->get(route('mailboxes.view', ['id' => $mailbox->id]))->assertOk()
            ->assertSee('data-keyboard-shortcuts="1"', false)
            ->assertSee('data-fruit-dialog="keyboard-shortcuts"', false)
            ->assertSee('[data-fruit-dialog=keyboard-shortcuts]', false);

        $this->get(route('users.preferences', ['id' => $user->id]))->assertSee('name="keyboard_shortcuts"', false);
        \Session::start();
        $this->post(route('users.preferences.save', ['id' => $user->id]), [
            '_token' => csrf_token(), 'keyboard_shortcuts_shown' => 1,
        ])->assertRedirect();
        $this->assertFalse($user->fresh()->hasKeyboardShortcuts());

        $this->actingAs($user->fresh())->get(route('mailboxes.view', ['id' => $mailbox->id]))
            ->assertDontSee('data-keyboard-shortcuts', false)
            ->assertDontSee('data-fruit-dialog="keyboard-shortcuts"', false);
    }

    public function testTheSheetShowsTheSendKeysTheComposersUse()
    {
        $user = $this->createUser();
        $mailbox = $this->createMailbox([$user]);

        // The composers' keys (tallportSendKey()) and the sheet: one definition.
        $this->actingAs($user)->get(route('mailboxes.view', ['id' => $mailbox->id]))->assertOk()
            ->assertSee('data-send-keys="'.e(json_encode(\App\Misc\KeyboardShortcuts::SEND)).'"', false)
            ->assertSeeInOrder(['Send a Chat Message', '<kbd>Enter</kbd>', 'New Line in a Chat Message', '<kbd>Shift</kbd><kbd>Enter</kbd>', 'Send a Reply or Note', '<kbd>Ctrl</kbd><kbd>Enter</kbd>'], false);
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0)')->get(route('mailboxes.view', ['id' => $mailbox->id]))
            ->assertSeeInOrder(['Send a Chat Message', '<kbd>Return</kbd>', '<kbd>⇧</kbd><kbd>Return</kbd>', '<kbd>⌘</kbd><kbd>Return</kbd>'], false);
    }

    /**
     * * stars or unstars the open conversation (public/js/shortcuts.js clicks the header's star).
     */
    public function testTheSheetShowsTheStarKey()
    {
        $user = $this->createUser();
        $mailbox = $this->createMailbox([$user]);

        $this->actingAs($user)->get(route('mailboxes.view', ['id' => $mailbox->id]))
            ->assertSeeInOrder(['Star or Unstar', '<kbd>*</kbd>'], false);
        $this->assertStringContainsString("key == '*'", file_get_contents(public_path('js/shortcuts.js')));
    }

    public function testNoreplyPatternsFromTheEnvironmentFile()
    {
        require_once base_path('database/migrations/2026_10_11_010101_add_keyboard_shortcuts_column_to_users_table.php');
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "APP_URL=https://x\nNOREPLY_EMAILS_CUSTOM='[\"alerts\",\"Bounces@shop.example\"]'\n");

        \AddKeyboardShortcutsColumnToUsersTable::importNoreplyPatterns($file);
        @unlink($file);

        $this->assertSame(['alerts', 'bounces@shop.example'], \App\Misc\Noreply::customPatterns());
    }
}
