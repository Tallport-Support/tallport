<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Nostr\NostrMailbox;
use App\Nostr\Diagnostics;
use App\Nostr\Keys;

class NostrDiagnose extends Command
{
    protected $signature = 'tallport:nostr-diagnose {--mailbox= : Only this mailbox id}';

    protected $description = 'Ask the relays what they hold for the Nostr mailboxes (pending messages, announcements)';

    public function handle()
    {
        $query = NostrMailbox::whereNotNull('pubkey')->whereNotNull('private_key');
        if ($this->option('mailbox')) {
            $query->where('mailbox_id', (int) $this->option('mailbox'));
        }

        foreach ($query->get() as $cfg) {
            $this->line('Mailbox '.$cfg->mailbox_id.' '.$cfg->getNpub().($cfg->enabled ? '' : ' (disabled)'));
            $result = (new Diagnostics())->run($cfg);
            $rows = [];
            foreach ($result['relays'] as $url => $row) {
                $rows[] = [
                    $url.($row['inbox'] ? ' *' : ''),
                    $row['ok'] ? 'ok' : 'failed: '.$row['error'],
                    $row['wraps'].($row['unseen_wraps'] ? ' ('.$row['unseen_wraps'].' new)' : ''),
                    $row['legacy'],
                    $row['profile'] ? 'yes' : 'no',
                    $row['dm_relays'] === null ? 'no' : implode(' ', $row['dm_relays']),
                ];
            }
            $this->table(['Relay (* inbox)', 'Connection', 'Gift wraps (3 days)', 'Legacy NIP-04', 'Profile', 'DM relay list'], $rows);
            foreach ($result['senders'] as $pubkey => $counts) {
                if ($counts['legacy']) {
                    $this->line('  legacy NIP-04 sender: '.Keys::npub($pubkey).' ('.$counts['legacy'].')');
                }
            }
        }

        return 0;
    }
}
