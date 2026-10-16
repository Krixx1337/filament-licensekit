var login = await LicenseKitClient.Call("login", new() { ["login"] = email, ["password"] = password, ["device"] = fingerprint });
var token = (string)login["token"]!;
if (!(bool)login["access"]!) await LicenseKitClient.Call("redeem", new() { ["token"] = token, ["key"] = customerKey });
var beat = await LicenseKitClient.Call("heartbeat", new() { ["token"] = token, ["device"] = fingerprint }); // every 60 seconds
bool allowed = (bool)beat["access"]!; // only run your software while this is true
