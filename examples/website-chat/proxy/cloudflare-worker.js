// Tallport's website chat through your website's domain: a Cloudflare Worker on the route
// example.com/t/*, with the proxy secret in its TALLPORT_PROXY variable. Replace help.example.com
// with your Tallport address.
export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const headers = new Headers(request.headers);
    headers.delete('Cookie');
    headers.set('X-Forwarded-For', request.headers.get('CF-Connecting-IP'));
    headers.set('X-Tallport-Proxy', env.TALLPORT_PROXY);
    return fetch('https://help.example.com' + url.pathname + url.search, {
      method: request.method,
      headers,
      body: request.body,
    });
  },
};
