<div data-ai-account>
<p class="eyebrow">LifeOS · Your account</p><h1>Connect your AI account</h1>
<p class="muted">Use your own OpenCode account for LifeOS AI. Sign in on the official website using the account you want to connect.</p>
<p class="notice" data-account-status role="status" aria-live="polite">Checking your connection…</p>
<div data-account-authorization hidden><p>Continue on the secure sign-in page. If asked, enter this code:</p><p><strong data-account-instructions></strong></p><button type="button" data-account-open>Open secure sign-in</button><p class="muted">Only approve the LifeOS sign-in you just started. Return here when you finish.</p></div>
<div class="form-actions"><button type="button" data-account-connect>Connect my account</button><button type="button" class="secondary" data-account-check>Check connection</button><button type="button" class="secondary" data-account-cancel hidden>Cancel sign-in</button><button type="button" class="secondary" data-account-disconnect hidden>Disconnect</button></div>
<p class="muted">Your AI connection is kept on this computer, separately for this LifeOS profile. No other customer's credentials are used. Disconnecting removes the saved connection from LifeOS; you can also revoke access on the service's website.</p>
<p class="muted">Your LifeOS login and your AI service login are separate. Signing in with Google on one service does not sign you into the other. Service quotas still apply; LifeOS selects only verified zero-cost models.</p>
<p><a href="/assistant" data-account-chat>Open LifeOS AI</a> · <a href="/account">Account & activation</a> · <a href="/settings">Settings</a></p>
<?= csrf_field() ?>
</div><script src="/assets/ai-account.js" defer></script>
