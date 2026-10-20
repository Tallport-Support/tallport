/*
 * The website chat's proof of work (App\WebChat\Spam), off the page's thread: the first nonce whose
 * SHA-256 of "salt:nonce" starts with the challenge's number of zero bits. Served as /t/x.
 */
function zeroBits(bytes) {
    var bits = 0;
    for (var i = 0; i < bytes.length; i++) {
        if (bytes[i] === 0) {
            bits += 8;
            continue;
        }
        for (var mask = 0x80; mask && !(bytes[i] & mask); mask >>= 1) bits++;
        break;
    }
    return bits;
}

self.onmessage = async function (event) {
    var salt = event.data.challenge.split('.')[1];
    var difficulty = event.data.difficulty;
    var encoder = new TextEncoder();
    for (var nonce = 0; ; nonce++) {
        var hash = new Uint8Array(await crypto.subtle.digest('SHA-256', encoder.encode(salt + ':' + nonce)));
        if (zeroBits(hash) >= difficulty) {
            self.postMessage({ proof: event.data.challenge + ':' + nonce });
            return;
        }
    }
};
