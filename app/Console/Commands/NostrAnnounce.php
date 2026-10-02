<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Nostr\NostrMailbox;
use App\Nostr\Announcer;

class NostrAnnounce extends Command
{
    protected $signature = 'tallport:nostr-announce {--mailbox= : Only this mailbox id}';

    protected $description = 'Publish the profile, DM relay list and relay list of the Nostr enabled mailboxes';

    public function handle()
    {
        $logger = function ($message) {
            $this->line($message);
        };
        $announcer = new Announcer($logger);

        $query = NostrMailbox::whereNotNull('pubkey')->whereNotNull('private_key');
        if ($this->option('mailbox')) {
            $query->where('mailbox_id', (int) $this->option('mailbox'));
        } else {
            $query->where('enabled', true);
        }

        foreach ($query->get() as $cfg) {
            $this->line('Mailbox '.$cfg->mailbox_id.' ('.$cfg->getNpub().')');
            $results = $announcer->announce($cfg);
            if (!$results) {
                $this->line('  nothing published (no relays configured)');
                continue;
            }
            foreach ($results as $kind => $relays) {
                $ok = count(array_filter($relays, function ($r) {
                    return !empty($r['ok']);
                }));
                $this->line('  kind '.$kind.': accepted by '.$ok.' of '.count($relays).' relay(s)');
            }
        }

        return 0;
    }
}
