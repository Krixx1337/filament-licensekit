// C++17 dependencies: cpr, libsodium, nlohmann/json.
#include <cpr/cpr.h>
#include <sodium.h>
#include <nlohmann/json.hpp>
#include <ctime>
#include <cstring>
#include <cstdlib>
#include <stdexcept>

using json = nlohmann::json;
json call(const std::string& endpoint, json fields) {
    if (sodium_init() < 0) throw std::runtime_error("Sodium unavailable");
    unsigned char random[32], key[32], signature[64];
    char nonce[65];
    randombytes_buf(random, sizeof random);
    sodium_bin2hex(nonce, sizeof nonce, random, sizeof random);
    fields["nonce"] = nonce;
    auto response = cpr::Post(cpr::Url{"__BASE__/" + endpoint},
        cpr::Header{{"Content-Type", "application/json"}}, cpr::Body{fields.dump()}, cpr::Timeout{15000});
    auto encoded = response.header.at("X-LicenseKit-Signature");
    size_t decoded = 0;
    if (sodium_base642bin(key, sizeof key, "__PUBLIC_KEY__", std::strlen("__PUBLIC_KEY__"),
        nullptr, &decoded, nullptr, sodium_base64_VARIANT_ORIGINAL) != 0 || decoded != sizeof key)
        throw std::runtime_error("Invalid public key");
    if (sodium_base642bin(signature, sizeof signature, encoded.data(), encoded.size(),
        nullptr, &decoded, nullptr, sodium_base64_VARIANT_ORIGINAL) != 0 || decoded != sizeof signature ||
        crypto_sign_verify_detached(signature, reinterpret_cast<const unsigned char*>(response.text.data()), response.text.size(), key) != 0)
        throw std::runtime_error("Invalid signature");
    auto data = json::parse(response.text);
    if (data.at("nonce") != nonce || std::llabs(std::time(nullptr) - data.at("server_time").get<long long>()) > 120)
        throw std::runtime_error("Stale or mismatched response");
    if (response.status_code != 200 || data.contains("error")) throw std::runtime_error(data.value("message", "Request failed"));
    return data;
}
// Send login/password/device for login; token/key for redeem; token/device for heartbeat.
// Only allow access when the verified data["access"] is true.
