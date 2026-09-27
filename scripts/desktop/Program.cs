using System.Diagnostics;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;
using System.Management;

namespace SoundMatic.Desktop;

internal static class Program
{
    private const int AppPort = 8001, WorkerPort = 3100;
    [STAThread]
    private static void Main(string[] args)
    {
        ApplicationConfiguration.Initialize();
        var root = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);
        if (args.Contains("--check")) { Environment.ExitCode = Required(root) ? 0 : 2; return; }
        using var instance = new Mutex(true, @"Local\SoundMatic.Desktop.Singleton", out var isFirstInstance);
        if (!isFirstInstance)
        {
            MessageBox.Show("SoundMatic sudah berjalan. Tutup jendela yang aktif sebelum membukanya kembali.", "SoundMatic", MessageBoxButtons.OK, MessageBoxIcon.Information);
            return;
        }
        Application.Run(new MainWindow(root));
    }

    private static string Php(string root) => Path.Combine(root, "runtime", "php", "php.exe");
    private static string Node(string root) => Path.Combine(root, "runtime", "node", "node.exe");
    private static bool Required(string root) => File.Exists(Path.Combine(root, ".env")) &&
        File.Exists(Path.Combine(root, "automation-worker", ".env")) && File.Exists(Path.Combine(root, "automation-worker", "dist", "server.js")) &&
        File.Exists(Php(root)) && File.Exists(Node(root));

    private sealed class MainWindow : Form
    {
        private readonly string root;
        private readonly Label status = new() { Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleCenter, Text = "Menjalankan SoundMatic...", Font = new Font("Segoe UI", 12) };
        private readonly WebView2 view = new() { Dock = DockStyle.Fill, Visible = false };
        private readonly System.Windows.Forms.Timer serviceMonitor = new() { Interval = 5000 };
        private string automationKey = "";
        private string browserExecutable = "";
        private bool recoveringServices;
        private bool shuttingDown;

        public MainWindow(string root)
        {
            this.root = root; Text = "SoundMatic v1.1.45"; Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); Width = 1440; Height = 900; MinimumSize = new Size(1000, 680); StartPosition = FormStartPosition.CenterScreen;
            Controls.Add(view); Controls.Add(status); Shown += async (_, _) => await StartAsync();
            serviceMonitor.Tick += async (_, _) => await RecoverServicesAsync();
            FormClosing += (_, _) => ShutdownServices();
        }

        private async Task StartAsync()
        {
            try
            {
                if (!Required(root)) throw new InvalidOperationException("Instalasi SoundMatic tidak lengkap. Jalankan ulang installer SoundMatic Setup.");
                Directory.CreateDirectory(Path.Combine(root, "storage", "logs"));
                automationKey = Env(Path.Combine(root, "automation-worker", ".env"), "AUTOMATION_HMAC_KEY");
                browserExecutable = FindBrowser();
                if (string.IsNullOrWhiteSpace(browserExecutable)) throw new InvalidOperationException("Google Chrome atau Microsoft Edge tidak ditemukan. Instal salah satunya lalu buka kembali SoundMatic.");
                if (string.IsNullOrWhiteSpace(automationKey)) automationKey = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(Env(Path.Combine(root, ".env"), "APP_KEY") + "|soundonmatic-automation-hmac"))).ToLowerInvariant();
                status.Text = "Membersihkan service lama...";
                StopOwnedServices();
                await WaitPortsClosed();
                if (shuttingDown) return;
                RecoverInterruptedAutomation();
                if (shuttingDown) return;
                StartAllServices();
                status.Text = "Menunggu service lokal..."; await WaitPort(AppPort); await WaitPort(WorkerPort); await WaitQueueWorker("release-automation"); await WaitQueueWorker("status-checks"); await WaitWebApplication();
                if (shuttingDown) return;
                var environment = await CoreWebView2Environment.CreateAsync(userDataFolder: Path.Combine(root, "storage", "app", "webview2"));
                await view.EnsureCoreWebView2Async(environment);
                view.CoreWebView2.Settings.AreDevToolsEnabled = false;
                view.CoreWebView2.NewWindowRequested += (_, e) => { e.Handled = true; view.CoreWebView2.Navigate(e.Uri); };
                view.Source = new Uri($"http://127.0.0.1:{AppPort}/login"); status.Visible = false; view.Visible = true;
                serviceMonitor.Start();
            }
            catch (Exception e)
            {
                status.Text = "SoundMatic gagal dijalankan.\n\n" + e.Message;
                File.WriteAllText(Path.Combine(root, "storage", "logs", "launcher-error.log"), e.ToString());
                MessageBox.Show(e.Message, "SoundMatic", MessageBoxButtons.OK, MessageBoxIcon.Error);
            }
        }

        private void StartAllServices()
        {
            Start("app", Php(root), $"artisan serve --host=127.0.0.1 --port={AppPort}", root, automationKey);
            StartQueueWorker("release-automation,default", "release-queue-worker.pid", "release-worker.log", "release-worker-error.log");
            StartQueueWorker("status-checks", "status-queue-worker.pid", "status-worker.log", "status-worker-error.log");
            Start("worker", Node(root), "--env-file=.env dist/server.js", Path.Combine(root, "automation-worker"), automationKey);
        }

        private void RecoverInterruptedAutomation()
        {
            var info = new ProcessStartInfo(Php(root), "artisan soundonmatic:recover-interrupted")
            {
                WorkingDirectory = root,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            };
            info.Environment["AUTOMATION_HMAC_KEY"] = automationKey;
            using var process = Process.Start(info) ?? throw new InvalidOperationException("Pemulihan antrean SoundMatic gagal dimulai.");
            process.WaitForExit(30_000);
            if (!process.HasExited)
            {
                process.Kill(true);
                throw new InvalidOperationException("Pemulihan antrean SoundMatic melewati batas waktu.");
            }
            if (process.ExitCode != 0)
            {
                var error = process.StandardError.ReadToEnd();
                throw new InvalidOperationException("Pemulihan antrean SoundMatic gagal. " + error);
            }
        }

        private async Task RecoverServicesAsync()
        {
            if (recoveringServices || shuttingDown) return;
            recoveringServices = true;
            try
            {
                if (!PortOpen(AppPort)) Start("app", Php(root), $"artisan serve --host=127.0.0.1 --port={AppPort}", root, automationKey);
                // Worker lifecycle belongs to the dashboard after startup.
                // Restarting workers every five seconds races with Stop and
                // pending collection reset, creating duplicate consumers.
                await Task.CompletedTask;
            }
            finally { recoveringServices = false; }
        }

        private void ShutdownServices()
        {
            if (shuttingDown) return;
            shuttingDown = true;
            serviceMonitor.Stop();
            StopChildProcesses();
            StopOwnedServices();
            for (var i = 0; i < 20 && (PortOpen(AppPort) || PortOpen(WorkerPort)); i++) Thread.Sleep(100);
        }

        private static void StopChildProcesses()
        {
            try
            {
                var currentId = Environment.ProcessId;
                using var query = new ManagementObjectSearcher($"SELECT ProcessId FROM Win32_Process WHERE ParentProcessId={currentId}");
                foreach (ManagementObject child in query.Get())
                {
                    try { Process.GetProcessById(Convert.ToInt32(child["ProcessId"])).Kill(true); } catch { }
                }
            }
            catch { }
        }

        private void StopOwnedServices()
        {
            try
            {
                using var query = new ManagementObjectSearcher("SELECT ProcessId, Name, CommandLine FROM Win32_Process WHERE Name='php.exe' OR Name='node.exe'");
                foreach (ManagementObject process in query.Get())
                {
                    var command = (string?)process["CommandLine"] ?? "";
                    var isApp = (command.Contains("artisan serve", StringComparison.OrdinalIgnoreCase) && command.Contains($"port={AppPort}", StringComparison.OrdinalIgnoreCase))
                        || (command.Contains($"127.0.0.1:{AppPort}", StringComparison.OrdinalIgnoreCase) && command.Contains("server.php", StringComparison.OrdinalIgnoreCase));
                    var isQueue = command.Contains("queue:work", StringComparison.OrdinalIgnoreCase)
                        && (command.Contains("release-automation", StringComparison.OrdinalIgnoreCase) || command.Contains("status-checks", StringComparison.OrdinalIgnoreCase));
                    var isWorker = command.Contains("--env-file=.env", StringComparison.OrdinalIgnoreCase) && command.Contains("dist/server.js", StringComparison.OrdinalIgnoreCase);
                    if (!isApp && !isQueue && !isWorker) continue;
                    try { Process.GetProcessById(Convert.ToInt32(process["ProcessId"])).Kill(true); } catch { }
                }
            }
            catch { }
        }

        private static async Task WaitPortsClosed()
        {
            for (var i = 0; i < 40 && (PortOpen(AppPort) || PortOpen(WorkerPort)); i++) await Task.Delay(250);
        }

        private bool QueueRunning(string queue)
        {
            var pidFile = Path.Combine(root, "storage", "app", queue == "status-checks" ? "status-queue-worker.pid" : "release-queue-worker.pid");
            if (File.Exists(pidFile) && int.TryParse(File.ReadAllText(pidFile).Trim(), out var pid))
            {
                try { return !Process.GetProcessById(pid).HasExited; } catch { try { File.Delete(pidFile); } catch { } }
            }
            try { using var q = new ManagementObjectSearcher("SELECT CommandLine FROM Win32_Process WHERE Name='php.exe'"); return q.Get().Cast<ManagementObject>().Any(p => { var command = (string?)p["CommandLine"] ?? ""; return command.Contains("queue:work", StringComparison.OrdinalIgnoreCase) && command.Contains(queue, StringComparison.OrdinalIgnoreCase); }); }
            catch { return false; }
        }
        private void StartQueueWorker(string queue, string pidFileName, string outputLog, string errorLog)
        {
            var pidFile = Path.Combine(root, "storage", "app", pidFileName);
            var wrapper = Path.Combine(root, "bootstrap", "app.php");
            var logs = Path.Combine(root, "storage", "logs");
            var launchId = DateTime.UtcNow.ToString("yyyyMMdd-HHmmss") + "-" + Guid.NewGuid().ToString("N");
            var launcher = Path.Combine(root, "storage", "app", Path.GetFileNameWithoutExtension(pidFileName) + "-" + launchId + ".cmd");
            outputLog = Path.GetFileNameWithoutExtension(outputLog) + "-" + launchId + ".log";
            errorLog = Path.GetFileNameWithoutExtension(errorLog) + "-" + launchId + ".log";
            File.WriteAllText(pidFile + ".logs.json", System.Text.Json.JsonSerializer.Serialize(new { stdout = Path.Combine(logs, outputLog), stderr = Path.Combine(logs, errorLog) }));
            Directory.CreateDirectory(Path.GetDirectoryName(launcher)!);
            var command = "start \"\" /B " + QuoteForCmd(Php(root)) + " " + QuoteForCmd(wrapper)
                + " --queue-runner " + QuoteForCmd(pidFile)
                + " queue:work database --queue=" + queue + " --sleep=2 --tries=3 --timeout=2700"
                + " > " + QuoteForCmd(Path.Combine(logs, outputLog))
                + " 2> " + QuoteForCmd(Path.Combine(logs, errorLog));
            File.WriteAllText(launcher, "@echo off\r\n" + command + "\r\n");
            var info = new ProcessStartInfo(Environment.GetEnvironmentVariable("ComSpec")!) { WorkingDirectory = root, UseShellExecute = false, CreateNoWindow = true, WindowStyle = ProcessWindowStyle.Hidden };
            info.ArgumentList.Add("/D"); info.ArgumentList.Add("/S"); info.ArgumentList.Add("/C"); info.ArgumentList.Add(launcher);
            info.Environment["AUTOMATION_HMAC_KEY"] = automationKey;
            info.Environment["PLAYWRIGHT_SERVICE_URL"] = $"http://127.0.0.1:{WorkerPort}";
            info.Environment["PLAYWRIGHT_CHROMIUM_EXECUTABLE"] = browserExecutable;
            info.Environment["PATH"] = Path.Combine(root, "runtime", "media") + Path.PathSeparator + (info.Environment["PATH"] ?? Environment.GetEnvironmentVariable("PATH") ?? "");
            Process.Start(info);
        }
        private static string QuoteForCmd(string value) => "\"" + value.Replace("\"", "\"\"") + "\"";
        private void Start(string name, string exe, string args, string cwd, string key)
        {
            var logs = Path.Combine(root, "storage", "logs");
            var command = $"\"\"{exe}\" {args} >> \"{Path.Combine(logs, $"launcher-{name}.log")}\" 2>> \"{Path.Combine(logs, $"launcher-{name}-error.log")}\"\"";
            var info = new ProcessStartInfo(Environment.GetEnvironmentVariable("ComSpec")!, "/d /s /c " + command) { WorkingDirectory = cwd, UseShellExecute = false, CreateNoWindow = true, WindowStyle = ProcessWindowStyle.Hidden };
            info.Environment["AUTOMATION_HMAC_KEY"] = key; info.Environment["PLAYWRIGHT_SERVICE_URL"] = $"http://127.0.0.1:{WorkerPort}";
            info.Environment["PLAYWRIGHT_CHROMIUM_EXECUTABLE"] = browserExecutable;
            info.Environment["PATH"] = Path.Combine(root, "runtime", "media") + Path.PathSeparator + (info.Environment["PATH"] ?? Environment.GetEnvironmentVariable("PATH") ?? "");
            Process.Start(info);
        }
        private static async Task WaitPort(int port) { for (var i = 0; i < 90; i++) { if (PortOpen(port)) return; await Task.Delay(500); } throw new InvalidOperationException($"Service port {port} tidak aktif."); }
        private async Task WaitQueueWorker(string queue) { for (var i = 0; i < 60; i++) { if (QueueRunning(queue)) return; await Task.Delay(500); } throw new InvalidOperationException($"Queue worker {queue} tidak aktif. Periksa file log worker di Pengaturan Platform."); }
        private static async Task WaitWebApplication()
        {
            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(2) };
            string lastFailure = "tidak memberikan respons";
            for (var i = 0; i < 90; i++)
            {
                try
                {
                    using var response = await client.GetAsync($"http://127.0.0.1:{AppPort}/login");
                    if ((int)response.StatusCode < 500) return;
                    lastFailure = $"HTTP {(int)response.StatusCode}";
                }
                catch (Exception error) { lastFailure = error.Message; }
                await Task.Delay(500);
            }
            throw new InvalidOperationException($"Server lokal aktif tetapi halaman aplikasi belum sehat ({lastFailure}). Periksa launcher-error.log.");
        }
        private static bool PortOpen(int port) { try { using var c = new TcpClient(); return c.ConnectAsync("127.0.0.1", port).Wait(TimeSpan.FromMilliseconds(250)); } catch { return false; } }
        private static string Env(string path, string key) { var prefix = key + "="; var line = File.ReadLines(path).FirstOrDefault(x => x.StartsWith(prefix, StringComparison.Ordinal)); return line is null ? "" : line[prefix.Length..].Trim().Trim('"'); }
        private static string FindBrowser()
        {
            var candidates = new[] {
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "Google", "Chrome", "Application", "chrome.exe"),
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), "Google", "Chrome", "Application", "chrome.exe"),
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), "Microsoft", "Edge", "Application", "msedge.exe"),
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "Microsoft", "Edge", "Application", "msedge.exe"),
            };
            return candidates.FirstOrDefault(File.Exists) ?? "";
        }
    }
}
