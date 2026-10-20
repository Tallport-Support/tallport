# Signs a visitor in to Tallport's website chat (Python). Run it on your server for a signed-in
# user, with the secret from Mailbox » Chat » Website Chat » Signed-In Visitors, and put the token
# in the page (see page.html). Only sub is required; data is what your team sees, such as the plan.
import base64
import hashlib
import hmac
import json
import time


def tallport_token(user: dict, secret: str) -> str:
    def b64(data: bytes) -> str:
        return base64.urlsafe_b64encode(data).rstrip(b"=").decode()

    header = b64(json.dumps({"alg": "HS256", "typ": "JWT"}).encode())
    # Valid for an hour (at most a day); a new one with every page is fine.
    payload = b64(json.dumps({**user, "exp": int(time.time()) + 3600}).encode())
    signature = b64(hmac.new(secret.encode(), f"{header}.{payload}".encode(), hashlib.sha256).digest())
    return f"{header}.{payload}.{signature}"


# For example:
# token = tallport_token(
#     {"sub": str(user.id), "email": user.email, "name": user.name, "data": {"plan": user.plan}},
#     os.environ["TALLPORT_SECRET"],
# )
