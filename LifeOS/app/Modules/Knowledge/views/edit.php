<div class="knowledge-page"><header class="page-header"><div><h1><?= $note?'Edit note':'Capture a note' ?></h1><p class="muted">Plain text and Markdown stay readable. Type [[another note title]] to connect notes.</p></div><a href="<?= $note?'/knowledge/'.e($note['id']):'/knowledge' ?>">Cancel</a></header><?php require ROOT.'/app/Core/views/notices.php'; ?>
<form class="card knowledge-editor" action="<?= $note?'/knowledge/'.e($note['id']).'/edit':'/knowledge' ?>" method="post">
<?= csrf_field() ?>
<label>Title<input name="title" maxlength="200" value="<?= e($note['title']??$template['title']) ?>" required autofocus></label>
<div class="knowledge-editor-row">
<label>Notebook<input name="notebook" maxlength="80" list="knowledge-notebooks" value="<?= e($note['notebook']??$template['notebook']??'General') ?>" required></label>
<datalist id="knowledge-notebooks"><?php foreach ($notebooks as $name): ?><option value="<?= e($name) ?>"><?php endforeach; ?></datalist>
<label>Tags <small>comma separated, up to 10</small><input name="tags" maxlength="400" value="<?= e($note['tags']??'') ?>" placeholder="ideas, work"></label>
</div>
<label>Note<textarea name="body" rows="18" maxlength="50000" placeholder="Write a thought, plan or checklist…"><?= e($note['body']??$template['body']) ?></textarea></label>
<div class="knowledge-editor-footer"><small>Use - [ ] for a checklist. Notes are saved when you press Save.</small><button>Save note</button></div>
</form></div>
