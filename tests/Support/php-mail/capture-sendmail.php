<?php
// Stand-in for sendmail in PhpMailTransportTest: records the arguments and
// the message PHP's mail() passes (sendmail_path is set to run this).
$out = getenv('TALLPORT_SENDMAIL_CAPTURE');
file_put_contents($out, json_encode(['argv' => array_slice($argv, 1), 'message' => stream_get_contents(STDIN)]));
