<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The "PHP mail()" send method (App\Misc\PhpMailTransport) when mail() fails
 * and with an envelope sender that can't go on sendmail's command line.
 * Like PhpMailTransportTest, each send runs in a PHP process whose
 * sendmail_path is a script, as mail() can't be redirected at run time.
 */
class PhpMailTransportEdgeCasesTest extends TestCase
{
    /**
     * Send an email from $from with sendmail exiting with $exit_code.
     *
     * @return array [output of the sending process, what sendmail got or null]
     */
    protected function send($from, $exit_code)
    {
        $capture = tempnam(sys_get_temp_dir(), 'tallport-sendmail');
        $script = tempnam(sys_get_temp_dir(), 'tallport-sendmail-script');
        file_put_contents($script, '<?php file_put_contents(getenv("TALLPORT_SENDMAIL_CAPTURE"), json_encode(["argv" => array_slice($argv, 1), "message" => stream_get_contents(STDIN)])); exit('.(int) $exit_code.');');
        $send_code = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'
            .'$email = (new Symfony\Component\Mime\Email())->from('.var_export($from, true).')->to("casey@customer.example.org")->subject("Hello")->text("Body");'
            .'try { (new App\Misc\PhpMailTransport())->send($email); echo "sent"; } catch (Exception $e) { echo get_class($e).": ".$e->getMessage(); }';
        $sendmail = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);
        $command = escapeshellarg(PHP_BINARY).' -d '.escapeshellarg('sendmail_path='.$sendmail).' -r '.escapeshellarg($send_code);

        try {
            exec('TALLPORT_SENDMAIL_CAPTURE='.escapeshellarg($capture).' '.$command.' 2>/dev/null', $output);
            $sent = json_decode(file_get_contents($capture), true);
        } finally {
            @unlink($capture);
            @unlink($script);
        }

        return [implode("\n", $output), $sent];
    }

    public function testFailedMailCallIsATransportError()
    {
        [$output] = $this->send('support@example.org', 1);

        $this->assertSame('Symfony\Component\Mailer\Exception\TransportException: Unable to send an email: mail() returned false.', $output);
    }

    /**
     * A quoted local part could break the command line: no -f then.
     */
    public function testUnsafeEnvelopeSenderIsNotPassed()
    {
        [$output, $sent] = $this->send('"support desk"@example.org', 0);

        $this->assertSame('sent', $output);
        $this->assertEmpty(preg_grep('/^-f/', $sent['argv']));
        $this->assertStringContainsString("To: casey@customer.example.org\r\n", $sent['message']);
    }
}
