# Diagnostic request only. For production, verify signatures with a client above.
nonce=$(openssl rand -hex 32)
curl --fail-with-body -i '__BASE__/login' \
  -H 'Content-Type: application/json' \
  --data "{\"login\":\"customer@example.com\",\"password\":\"PASSWORD\",\"device\":\"YOUR_FINGERPRINT\",\"nonce\":\"$nonce\"}"
