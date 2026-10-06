<?php

namespace App\Misc;

/**
 * The keys that send what's being written, in one place: the Keyboard Shortcuts
 * sheet (partials/keyboard_shortcuts) shows them and the composers follow them
 * (tallportSendKey() in public/js/main.js, through the body's data-send-keys).
 */
class KeyboardShortcuts
{
    /**
     * In a chat (Team Chat, a conversation's chat view) and in any other message
     * (replies, notes, new conversations). The first is the one the sheet shows.
     * Mod: ⌘ on a Mac, Ctrl elsewhere.
     */
    const SEND = [
        'chat'    => ['Enter', 'Mod+Enter'],
        'message' => ['Mod+Enter'],
    ];

    /**
     * A new line in a chat message (the browser's own).
     */
    const CHAT_NEW_LINE = 'Shift+Enter';

    /**
     * What Enter does in FruitUI's editor for a kind of message: sends ("submit") or breaks the line.
     */
    public static function editorEnter($kind)
    {
        return in_array('Enter', self::SEND[$kind]) ? 'submit' : 'newline';
    }

    /**
     * A key combination as the user's keyboard labels it: "⌘ + Return", "Ctrl + Enter".
     */
    public static function label($keys, $mac)
    {
        $names = $mac ? ['Mod' => '⌘', 'Enter' => 'Return', 'Shift' => '⇧'] : ['Mod' => 'Ctrl'];

        return implode(' + ', array_map(fn ($key) => $names[$key] ?? $key, explode('+', $keys)));
    }
}
