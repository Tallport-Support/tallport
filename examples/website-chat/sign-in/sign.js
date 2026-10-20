// Signs a visitor in to Tallport's website chat (Node.js). Run it on your server for a signed-in
// user, with the secret from Mailbox » Chat » Website Chat » Signed-In Visitors, and put the token
// in the page (see page.html). Only sub is required; data is what your team sees, such as the plan.
import { createHmac } from 'node:crypto';

export function tallportToken(user, secret) {
  const base64 = (data) => Buffer.from(data).toString('base64url');
  const header = base64(JSON.stringify({ alg: 'HS256', typ: 'JWT' }));
  // Valid for an hour (at most a day); a new one with every page is fine.
  const payload = base64(JSON.stringify({ ...user, exp: Math.floor(Date.now() / 1000) + 3600 }));
  const signature = createHmac('sha256', secret).update(`${header}.${payload}`).digest('base64url');
  return `${header}.${payload}.${signature}`;
}

// For example:
// const token = tallportToken(
//   { sub: String(user.id), email: user.email, name: user.name, data: { plan: user.plan } },
//   process.env.TALLPORT_SECRET,
// );
