auto login = call("login", {{"login", email}, {"password", password}, {"device", fingerprint}});
std::string token = login.at("token");
if (!login.at("access").get<bool>()) call("redeem", {{"token", token}, {"key", customer_key}});
auto beat = call("heartbeat", {{"token", token}, {"device", fingerprint}}); // every 60 seconds
bool allowed = beat.at("access").get<bool>(); // only run your software while this is true
