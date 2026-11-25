using System.Text.Json;
using System.Text.RegularExpressions;

namespace SoundMatic.Diagnostics;

internal static class ErrorData
{
    private static readonly object Gate = new();
    internal static void Write(string root, string category, string message)
    {
        try
        {
            lock (Gate)
            {
                var directory = Path.Combine(root, "data error");
                Directory.CreateDirectory(directory);
                var file = Path.Combine(directory, $"error {category}.log");
                if (File.Exists(file) && new FileInfo(file).Length >= 5 * 1024 * 1024)
                {
                    for (var index = 3; index >= 1; index--)
                    {
                        var source = index == 1 ? file : file + "." + (index - 1);
                        var destination = file + "." + index;
                        if (File.Exists(destination)) File.Delete(destination);
                        if (File.Exists(source)) File.Move(source, destination);
                    }
                }
                message = Regex.Replace(message, @"(https?://[^\s?]+)\?[^\s]+", "$1?[REDACTED]", RegexOptions.IgnoreCase);
                message = Regex.Replace(message, @"\bBearer\s+[A-Za-z0-9._~+/\-]+", "Bearer [REDACTED]", RegexOptions.IgnoreCase);
                message = Regex.Replace(message, "([\"']?(?:password|passwd|secret|[\\w-]*token|cookie|authorization|session|app_key|hmac[\\w-]*)[\"']?\\s*[:=]\\s*)(\"[^\"\\r\\n]*\"|'[^'\\r\\n]*'|[^\\s,;]+)", "$1[REDACTED]", RegexOptions.IgnoreCase);
                var record = JsonSerializer.Serialize(new {
                    time = DateTimeOffset.Now.ToString("O"), source = "windows-" + category,
                    version = System.Reflection.Assembly.GetExecutingAssembly().GetName().Version?.ToString(3),
                    message = message.Length > 24000 ? message[..24000] : message,
                });
                File.AppendAllText(file, record + Environment.NewLine);
            }
        }
        catch { /* Diagnostics must never prevent startup or installation. */ }
    }
}
