result = call("login", login=email, password=password, device=fingerprint)
if not result["access"]:
    result = call("redeem", token=result["token"], key=customer_key)
heartbeat = call("heartbeat", token=result["token"], device=fingerprint)  # every 60 seconds
allowed = heartbeat["access"]  # only run your software while this is True
