using System.Net.Http;

// Runs independently of WebView navigation/visibility. The dedicated key never
// enters a page, URL, cookie jar, diagnostic file or redirected request.
internal sealed class ResidentAutomationRunner : IDisposable
{
    private readonly HttpClient client;
    private readonly CancellationTokenSource stop;
    private readonly Task worker;
    private readonly Uri endpoint;
    private readonly string key;
    private int disposed;

    internal ResidentAutomationRunner(Uri home, string key, CancellationToken lifetime, TimeSpan? interval = null)
    {
        if (home.Scheme != Uri.UriSchemeHttp || home.Host != "127.0.0.1" || !string.IsNullOrEmpty(home.UserInfo))
            throw new ArgumentException("Automation requires the local LifeOS server.", nameof(home));
        if (key.Length != 64 || !key.All(Uri.IsHexDigit))
            throw new ArgumentException("Automation requires a dedicated process key.", nameof(key));
        var period = interval ?? TimeSpan.FromMinutes(1);
        if (period <= TimeSpan.Zero || period.TotalMilliseconds > uint.MaxValue - 1)
            throw new ArgumentOutOfRangeException(nameof(interval));
        this.key = key;
        endpoint = new Uri(home, "/internal/automation/tick");
        client = new HttpClient(new HttpClientHandler { UseProxy = false, UseCookies = false, AllowAutoRedirect = false })
        {
            Timeout = TimeSpan.FromSeconds(15)
        };
        stop = CancellationTokenSource.CreateLinkedTokenSource(lifetime);
        var token = stop.Token;
        worker = Task.Run(() => RunAsync(period, token));
    }

    private async Task RunAsync(TimeSpan period, CancellationToken token)
    {
        using var timer = new PeriodicTimer(period);
        try
        {
            // A single awaited loop prevents overlapping requests, including
            // after resume from sleep or a slow/busy SQLite operation.
            do
            {
                token.ThrowIfCancellationRequested();
                try
                {
                    using var request = new HttpRequestMessage(HttpMethod.Post, endpoint);
                    request.Headers.Add("X-LifeOS-Automation-Key", key);
                    using var response = await client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, token).ConfigureAwait(false);
                    // The server owns run history and retry/idempotency. Neither
                    // a rejected tick nor a temporarily busy server ends residency.
                }
                catch (HttpRequestException) { }
                catch (OperationCanceledException) when (!token.IsCancellationRequested) { }
            }
            while (await timer.WaitForNextTickAsync(token).ConfigureAwait(false));
        }
        catch (OperationCanceledException) when (token.IsCancellationRequested) { }
        catch (ObjectDisposedException) when (token.IsCancellationRequested) { }
    }

    public void Dispose()
    {
        if (Interlocked.Exchange(ref disposed, 1) != 0) return;
        stop.Cancel();
        client.Dispose();
        // Closing the window must not block on an HTTP timeout. Dispose the
        // linked source only after the pending request has observed cancellation.
        _ = worker.ContinueWith(_ => stop.Dispose(), CancellationToken.None,
            TaskContinuationOptions.ExecuteSynchronously, TaskScheduler.Default);
    }
}
