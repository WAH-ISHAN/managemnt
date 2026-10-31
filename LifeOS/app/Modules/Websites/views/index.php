<?php
$websiteSessions=[]; $signedIn=0; $needsSetup=0; $businessGroups=[];
foreach($profiles as $item) {
    $websiteSessions[$item['id']]=WebsiteService::session((int)$item['id']);
    if($websiteSessions[$item['id']]) $signedIn++;
    if($item['connector_url']==='') $needsSetup++;
    if($item['business_name']!=='') $businessGroups['business:'.mb_strtolower($item['business_name'])]=$item['business_name'];
}
natcasesort($businessGroups);
?>
<div class="website-dashboard" data-website-dashboard>
<div class="topline"><span>WEB ADMIN</span><span>One workspace. Every website.</span></div>
<header class="page-header website-header">
    <div><span class="website-kicker">YOUR BUSINESS WORKSPACE</span><h1>All your websites. One LifeOS.</h1><p class="muted">Manage every business and website from your own workspace.</p></div>
    <a class="btn" href="#new-website" data-website-add>+ Add website</a>
</header>
<?php require ROOT.'/app/Core/views/notices.php'; ?>
<details class="card website-gateway-login" id="provider-login"><summary><strong>Sign in with your website provider</strong></summary><p class="muted">One provider account connects all websites assigned to you. Use the gateway address and client account supplied by your provider.</p><form class="form-grid" method="post" action="/websites/gateway/login"><?= csrf_field() ?><label class="full">Gateway address<input name="gateway_url" type="url" placeholder="https://manage.example.com/index.php" maxlength="2048" required></label><label>Client username<input name="username" maxlength="120" autocomplete="username" required></label><label>Client password<input name="password" type="password" maxlength="4096" autocomplete="current-password" required></label><div class="full"><button>Connect my websites</button></div></form><p class="footnote">Your provider controls which websites and actions your account can access. Your password is used to sign in and is not saved.</p></details>
<section class="card website-overview" aria-label="Website overview">
    <div class="website-overview-intro"><span class="website-overview-icon"><?= icon('grid') ?></span><div><h2>Your businesses, together.</h2><p class="muted">Pick a website and continue where you left off.</p></div></div>
    <dl class="website-stats"><div><dt>Businesses</dt><dd><?= count($businessGroups) ?></dd></div><div><dt>Total websites</dt><dd><?= count($profiles) ?></dd></div><div><dt>Signed in</dt><dd data-website-signed-count><?= $signedIn ?></dd></div><div><dt>Need setup</dt><dd><?= $needsSetup ?></dd></div></dl>
</section>
<?php if(($costSnapshot['count'] ?? 0)>0): ?><div class="stats website-cost-overview"><section class="card"><p>All website costs</p><div class="big-number"><?= money($costSnapshot['total']) ?></div><small>Across all business websites</small></section><section class="card"><p>This month</p><div class="big-number"><?= money($costSnapshot['month_total']) ?></div><small><?= e(date('F Y')) ?></small></section><section class="card"><p>Cost records</p><div class="big-number"><?= e($costSnapshot['count']) ?></div><small>Hosting, domains, maintenance and more</small></section></div><?php endif; ?>
<?php if($profiles): ?>
<div class="website-controls" data-website-controls hidden>
    <label class="website-search"><span>Find a website</span><span class="website-search-field"><?= icon('search') ?><input type="search" name="website_search" data-website-search maxlength="200" placeholder="Search business, website or domain" autocomplete="off" aria-controls="website-grid"></span></label>
    <label class="website-business"><span>Business</span><select data-website-business aria-controls="website-grid"><option value="">All businesses</option><?php foreach($businessGroups as $key=>$business): ?><option value="<?= e($key) ?>"><?= e($business) ?></option><?php endforeach; ?><option value="ungrouped">Without a business group</option></select></label>
    <label class="website-sort"><span>Sort by</span><select data-website-sort aria-controls="website-grid"><option value="name">Website name</option><option value="recent">Recently connected</option><option value="setup">Needs setup first</option></select></label>
    <div class="website-filters" role="group" aria-label="Filter websites">
        <?php foreach(['all'=>'All websites','signed'=>'Signed in','signin'=>'Need sign in','setup'=>'Need setup'] as $value=>$label): ?><button type="button" data-website-filter="<?= e($value) ?>" aria-pressed="<?= $value==='all' ? 'true' : 'false' ?>"><?= e($label) ?></button><?php endforeach; ?>
    </div>
    <p class="website-results" data-website-results role="status" aria-live="polite" aria-atomic="true"><?= count($profiles) ?> websites</p>
</div>
<div class="website-grid" id="website-grid">
<?php foreach($profiles as $index=>$site):
    $siteConnection=$websiteSessions[$site['id']]; $status=$siteConnection ? 'signed' : ($site['connector_url']==='' ? 'setup' : 'signin');
    $hostname=parse_url($site['base_url'],PHP_URL_HOST) ?: $site['base_url'];
?>
<article class="card website-card" data-website-card data-site-id="<?= e($site['id']) ?>" data-site-name="<?= e($site['name']) ?>" data-business="<?= e($site['business_name']!=='' ? 'business:'.mb_strtolower($site['business_name']) : 'ungrouped') ?>" data-search="<?= e($site['business_name'].' '.$site['name'].' '.$site['base_url'].' '.$site['stack'].' '.$site['server_address']) ?>" data-status="<?= e($status) ?>" data-expires="<?= e($siteConnection['expires_at'] ?? 0) ?>" data-recent="<?= e($site['last_connected_at'] ?? '') ?>" data-order="<?= $index % 7 ?>">
    <div class="website-card-top"><span class="website-monogram" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($site['name'],0,1))) ?></span><span class="website-status" data-website-status><span class="website-status-dot" aria-hidden="true"></span><span data-website-status-label><?= ['signed'=>'Signed in','setup'=>'Needs setup','signin'=>'Sign in required'][$status] ?></span></span></div>
    <h2><a href="/websites/<?= e($site['id']) ?>"><?= e($site['name']) ?></a></h2><p class="website-domain"><?= e($hostname) ?></p>
    <div class="website-card-tags"><?php if($site['business_name']!==''): ?><span class="website-business-tag"><?= e($site['business_name']) ?></span><?php endif; ?><span><?= e($site['stack'] ?: 'Custom website') ?></span><?php if($site['server_address']): ?><span><?= e($site['server_address']) ?></span><?php endif; ?></div>
    <p class="website-context" data-website-context><?php if($siteConnection): ?><?= e(($siteConnection['username'] ?? '')!=='' ? $siteConnection['username'] : 'API connection') ?> &middot; <span data-website-expiry>Session active</span><?php elseif($status==='setup'): ?>Add a connection to get started.<?php else: ?>Ready when you are. Sign in to manage.<?php endif; ?></p>
    <div class="website-cost-card"><span>This month's costs</span><strong><?= money($costSnapshot['sites'][$site['id']]['month_total'] ?? 0) ?></strong><a href="/websites/<?= e($site['id']) ?>#maintenance-costs">Maintenance costs &rarr;</a></div>
    <a class="btn <?= $siteConnection ? '' : 'secondary' ?> website-open" href="/websites/<?= e($site['id']) ?>"><span data-website-open-label><?= $siteConnection ? 'Open admin panel' : ($status==='setup' ? 'Set up website' : 'Sign in to website') ?></span><span class="website-arrow" aria-hidden="true">&rarr;</span></a>
</article>
<?php endforeach; ?>
</div>
<section class="card website-no-results" data-website-no-results hidden><span class="website-empty-icon"><?= icon('search') ?></span><h2>No matching websites.</h2><p class="muted">Try another name, domain or filter.</p><button class="secondary" type="button" data-website-clear>Clear search &amp; filters</button></section>
<p class="website-session-note muted"><?= icon('clock') ?> Sign-in status reflects your current LifeOS session. Website availability is checked when you connect or load records.</p>
<?php else: ?>
<section class="card website-empty"><span class="website-empty-icon"><?= icon('grid') ?></span><h2>Your first website starts here.</h2><p class="muted">Bring all your websites into one workspace. Add a website below, then sign in to its admin tools.</p><a class="btn secondary" href="#new-website" data-website-add>Add your first website &rarr;</a></section>
<?php endif; ?>
<?php if($activity ?? []): ?><section class="card website-activity" aria-label="Activity across your websites"><div class="section-heading"><div><h2>Across your websites</h2><p class="muted">Recent actions from all your connected businesses.</p></div></div><div class="website-activity-list"><?php foreach($activity as $event): ?><div class="website-activity-row"><div><a href="/websites/<?= e($event['website_id']) ?>"><?= e($event['website_name']) ?></a><small><?= e($event['business_name'] ?: 'Website workspace') ?></small></div><span><?= e(ucfirst($event['action'])) ?><?= $event['collection']!=='' ? ' &middot; '.e($event['collection']) : '' ?></span><span class="website-activity-outcome"><?= e($event['outcome']) ?></span><time><?= e($event['created_at']) ?></time></div><?php endforeach; ?></div><p class="footnote">Actions recorded in this LifeOS workspace. Changes made outside LifeOS appear when you load records from that website.</p></section><?php endif; ?>
<details class="card website-add" id="new-website" <?= !$profiles ? 'open' : '' ?>><summary><span>Add another website</span><span class="muted">Domain, hosting &amp; connection</span></summary><form class="form-grid" method="post" action="/websites/profile"><?= csrf_field() ?><label>Business name (optional)<input name="business_name" maxlength="120" placeholder="Your business or brand"></label><label>Website name<input name="name" maxlength="120" required placeholder="My portfolio"></label><label>Website URL<input name="base_url" type="url" required placeholder="https://example.com"></label><label>Server address (optional)<input name="server_address" maxlength="255" placeholder="Hosting hostname or IP, for your reference"></label><label>Technology / hosting (optional)<input name="stack" maxlength="100" placeholder="PHP, WordPress, Node.js, custom..."></label><label class="full">Connector endpoint (optional until you connect)<input name="connector_url" type="url" placeholder="https://example.com/api/lifeos-admin.php"></label><label class="full">Notes<textarea name="notes" rows="2" maxlength="2000" placeholder="Hosting provider, deployment notes. Keep passwords in your password manager."></textarea></label><div class="full"><button>Save website</button></div></form></details>
<div class="website-help"><div><strong>One owner. Every business.</strong><p class="muted">Keep all your business websites together, with a separate connection for each site.</p></div><a href="/websites/setup">Connection guide &rarr;</a><a href="/websites/connector/download" hx-boost="false">Download connector</a></div>
</div>
