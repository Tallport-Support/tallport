<?php
// Tallport's website chat through your website's domain, for a website without a proxy of its own:
// this file is /t/index.php, and the .htaccess beside it sends every request for /t/ here.
// Replace help.example.com with your Tallport address and SECRET with the proxy secret.
const TALLPORT = 'https://help.example.com';
const SECRET = 'SECRET';

$path = substr(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen('/t/'));
if (!preg_match('/^(t\.js|[wsjxpvmrfiec])$/', $path)) {
    http_response_code(404);
    exit;
}
$headers = ['X-Tallport-Proxy: '.SECRET, 'X-Forwarded-For: '.$_SERVER['REMOTE_ADDR']];
foreach (['HTTP_ACCEPT' => 'Accept', 'HTTP_ACCEPT_LANGUAGE' => 'Accept-Language', 'HTTP_AUTHORIZATION' => 'Authorization', 'HTTP_ORIGIN' => 'Origin'] as $key => $name) {
    if (isset($_SERVER[$key])) {
        $headers[] = $name.': '.$_SERVER[$key];
    }
}
$query = $_SERVER['QUERY_STRING'] ?? '';
$curl = curl_init(TALLPORT.'/t/'.$path.($query !== '' ? '?'.$query : ''));
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = $_POST;
    foreach ($_FILES['files']['tmp_name'] ?? [] as $i => $file) {
        $fields['files['.$i.']'] = new CURLFile($file, $_FILES['files']['type'][$i], $_FILES['files']['name'][$i]);
    }
    curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
}
$response = (string) curl_exec($curl);
$header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
http_response_code(curl_getinfo($curl, CURLINFO_RESPONSE_CODE) ?: 502);
foreach (explode("\r\n", substr($response, 0, $header_size)) as $line) {
    if (preg_match('/^(Content-Type|Content-Security-Policy|Content-Disposition|Cache-Control|Referrer-Policy):/i', $line)) {
        header($line);
    }
}
echo substr($response, $header_size);
