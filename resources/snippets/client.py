# pip install requests pynacl
import base64, secrets, time
import requests
from nacl.signing import VerifyKey

BASE = "__BASE__"
PUBLIC_KEY = VerifyKey(base64.b64decode("__PUBLIC_KEY__"))

def call(endpoint, **fields):
    nonce = secrets.token_hex(32)
    response = requests.post(BASE + "/" + endpoint,
                             json=dict(fields, nonce=nonce), timeout=15)
    signature = base64.b64decode(response.headers["X-LicenseKit-Signature"])
    PUBLIC_KEY.verify(response.content, signature)
    data = response.json()
    if data["nonce"] != nonce or abs(time.time() - data["server_time"]) > 120:
        raise ValueError("Stale or mismatched response")
    if response.status_code != 200 or "error" in data:
        raise ValueError(data.get("message", "Request failed"))
    return data

# First redemption works even when login returns access=False.
# result = call("login", login="customer@example.com", password="...", device="YOUR_FINGERPRINT")
# result = call("redeem", token=result["token"], key="CUSTOMER_KEY")
# heartbeat = call("heartbeat", token=result["token"], device="YOUR_FINGERPRINT")
# Only run your software while heartbeat["access"] is True.
