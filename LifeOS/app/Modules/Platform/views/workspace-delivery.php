<?php $delivery=WorkspaceExchange::config(); $received=[]; $deliveryError=''; try { $received=WorkspaceExchange::incoming(); } catch(Throwable $e) { $deliveryError=$e->getMessage(); } ?>
<section class="card" id="workspace-delivery">
<h2><?= e(t('Continue on another computer')) ?></h2>
<p><?= e(t('Automatically deliver an encrypted workspace snapshot to a paired computer through a folder you already share. Incoming snapshots wait for your review. Applying one replaces this profile’s records and document files; it does not merge simultaneous edits. Your previous workspace is backed up first.')) ?></p>
<p><?= e(t('This shares financial records, private notes, school mood records and uploaded documents. Passwords, account connections, PINs, device relationships, trusted code and executable automations stay on this computer. Both computers must use the same LifeOS version and modules. Stop running timers before delivery or replacement.')) ?></p>
<p><?= e(t('LifeOS keeps the three most recent outgoing snapshots for this computer pair in the shared folder. Incoming snapshots are never applied automatically.')) ?></p>
<form class="form-grid" method="post" action="/platform/workspace/configure">
<?= csrf_field() ?>
<label><?= e(t('Paired computer')) ?><select name="peer"><option value=""><?= e(t('Choose…')) ?></option><?php foreach(Db::all('SELECT * FROM platform_peers WHERE revoked=0 ORDER BY name')as $peer): ?><option value="<?= e($peer['id']) ?>" <?= $delivery['peer']===$peer['id']?'selected':'' ?>><?= e($peer['name']) ?></option><?php endforeach; ?></select></label>
<label><?= e(t('Shared folder on this computer')) ?><input name="folder" value="<?= e($delivery['folder']) ?>" maxlength="1000"></label>
<label><?= e(t('Delivery interval in minutes')) ?><input type="number" name="minutes" min="15" max="1440" value="<?= e($delivery['minutes']) ?>"></label>
<label><?= e(t('Other computer fingerprint')) ?><input name="fingerprint" maxlength="64" autocomplete="off"><small><?= e(t('Compare the fingerprint on the other computer. Required when enabling sharing or changing its destination.')) ?></small></label>
<label class="full"><input type="checkbox" name="share_workspace" value="yes"> <?= e(t('I approve sharing this profile’s private records and documents with the selected computer.')) ?></label>
<label><input type="checkbox" name="enabled" value="1" <?= $delivery['enabled']?'checked':'' ?>> <?= e(t('Deliver changes automatically while LifeOS is running')) ?></label>
<button><?= e(t('Save workspace delivery')) ?></button>
</form>
<?php if($delivery['enabled']): ?><form method="post" action="/platform/workspace/send"><?= csrf_field() ?><button class="secondary"><?= e(t('Deliver a snapshot now')) ?></button></form><?php endif; ?>
<?php if($last=Db::setting('workspace_exchange_success')): ?><p><?= e(t('Last successful delivery:')) ?> <?= e($last) ?></p><?php endif; ?>
<?php if($error=($deliveryError?:Db::setting('workspace_exchange_error'))): ?><p class="error-text" role="status"><?= e($error) ?></p><?php endif; ?>
<h3><?= e(t('Received workspaces')) ?></h3>
<?php if(!$received): ?><p><?= e(t('No received snapshots in the selected shared folder.')) ?></p><?php endif; ?>
<?php foreach($received as $item): ?><article class="lesson-entry"><p><strong><?= e($item['peer']) ?></strong> · <?= e(number_format($item['bytes']/1048576,1)) ?> MiB</p><form method="post" action="/platform/workspace/preview" hx-boost="false"><?= csrf_field() ?><input type="hidden" name="file" value="<?= e($item['file']) ?>"><button class="secondary"><?= e(t('Review received workspace')) ?></button></form></article><?php endforeach; ?>
</section>
