# Website chat examples

Small files to copy for Tallport's website chat (Mailbox » Chat » Website Chat).
Replace `help.example.com` with your Tallport address.

## Signed-in visitors: `sign-in/`

Your server signs who the visitor is with the widget's secret, and the page
passes the token on with `tallport('identify', token)`.

- `sign.php`, `sign.js` (Node.js) or `sign.py`: make the token.
- `page.html`: the snippet with the token, and `tallport('logout')`.

## Through your website's domain: `proxy/`

Your website serves the chat's folder, `/t/`, so visitors' browsers only talk
to your website. Replace `SECRET` with the widget's proxy secret.

- `nginx.conf`, `Caddyfile` or `apache.conf`: for your web server.
- `cloudflare-worker.js`: a Cloudflare Worker.
- `netlify/_redirects` or `vercel/vercel.json`: these can't send the secret,
  so their visitors count together for Tallport's limits per IP address.
- `php/`: for a website without a proxy of its own.

Then change the snippet's script to your website's own `/t/t.js`. Once
proxied, the chat window is on your website's origin, so your website's own
scripts can read it.
