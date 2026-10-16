// dotnet add package NSec.Cryptography
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text.Json.Nodes;
using NSec.Cryptography;

static class LicenseKitClient
{
    static readonly HttpClient Http = new() { Timeout = TimeSpan.FromSeconds(15) };
    static readonly PublicKey Key = PublicKey.Import(SignatureAlgorithm.Ed25519,
        Convert.FromBase64String("__PUBLIC_KEY__"), KeyBlobFormat.RawPublicKey);

    public static async Task<JsonObject> Call(string endpoint, JsonObject fields)
    {
        var nonce = Convert.ToHexString(RandomNumberGenerator.GetBytes(32)).ToLowerInvariant();
        fields["nonce"] = nonce;
        using var response = await Http.PostAsJsonAsync("__BASE__/" + endpoint, fields);
        var body = await response.Content.ReadAsByteArrayAsync();
        var signature = Convert.FromBase64String(response.Headers.GetValues("X-LicenseKit-Signature").Single());
        if (!SignatureAlgorithm.Ed25519.Verify(Key, body, signature)) throw new Exception("Invalid signature");
        var data = JsonNode.Parse(body)!.AsObject();
        if ((string?)data["nonce"] != nonce || Math.Abs(DateTimeOffset.UtcNow.ToUnixTimeSeconds() - (long)data["server_time"]!) > 120)
            throw new Exception("Stale or mismatched response");
        if (!response.IsSuccessStatusCode || data.ContainsKey("error")) throw new Exception((string?)data["message"]);
        return data;
    }
}
// Call login, redeem, heartbeat or move-device with the fields shown above.
// Keep the returned token. Only allow software access when signed data["access"] is true.
