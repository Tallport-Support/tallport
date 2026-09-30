<?php
// Sends one email through App\Misc\PhpMailTransport (run by PhpMailTransportTest
// with sendmail_path pointing at capture-sendmail.php).
require __DIR__.'/../../../vendor/autoload.php';

$email = (new Symfony\Component\Mime\Email())
    ->from(new Symfony\Component\Mime\Address('support@example.org', 'Support Team'))
    ->to(new Symfony\Component\Mime\Address('casey@customer.example.org', 'Casey Customer'))
    ->bcc('audit@example.org')
    ->subject('Re: Ünïcode subject that is long enough to be folded by the mime encoder of symfony')
    ->text("Hello Casey,\n\nYour order ships tomorrow.")
    ->html('<p>Hello Casey,</p><p>Your order ships tomorrow.</p>');
$email->getHeaders()->addIdHeader('Message-ID', 'fs-reply-1-abc@example.org');

(new App\Misc\PhpMailTransport())->send($email);
