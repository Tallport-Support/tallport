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
            ->assertSee('id="keyboard-shortcuts-modal"', false)
            ->assertSee('data-target="#keyboard-shortcuts-modal"', false);

        $this->get(route('users.profile', ['id' => $user->id]))->assertSee('name="keyboard_shortcuts"', false);
        \Session::start();
        $this->post(route('users.profile.save', ['id' => $user->id]), [
            '_token' => csrf_token(), 'first_name' => $user->first_name, 'last_name' => $user->last_name, 'email' => $user->email,
            'timezone' => 'UTC', 'time_format' => \App\User::TIME_FORMAT_24, 'keyboard_shortcuts_shown' => 1,
        ])->assertRedirect();
        $this->assertFalse($user->fresh()->hasKeyboardShortcuts());

        $this->actingAs($user->fresh())->get(route('mailboxes.view', ['id' => $mailbox->id]))
            ->assertDontSee('data-keyboard-shortcuts', false)
            ->assertDontSee('keyboard-shortcuts-modal', false);
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
