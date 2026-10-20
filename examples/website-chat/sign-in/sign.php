<?php
// Signs a visitor in to Tallport's website chat. Run it on your server for a signed-in user, with
// the secret from Mailbox » Chat » Website Chat » Signed-In Visitors, and put the token in the page
// (see page.html). Only sub is required; data is what your team sees, such as the plan.

function tallport_token(array $user, string $secret): string
{
    $base64 = fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    $header = $base64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    // Valid for an hour (at most a day); a new one with every page is fine.
    $payload = $base64(json_encode($user + ['exp' => time() + 3600]));

    return $header.'.'.$payload.'.'.$base64(hash_hmac('sha256', $header.'.'.$payload, $secret, true));
}

// For example:
// $token = tallport_token([
//     'sub'   => (string) $user->id,
//     'email' => $user->email,
//     'name'  => $user->name,
//     'data'  => ['plan' => $user->plan],
// ], getenv('TALLPORT_SECRET'));
