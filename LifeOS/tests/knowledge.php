<?php
declare(strict_types=1);
define('ROOT',dirname(__DIR__));
require ROOT.'/app/Core/Db.php'; require ROOT.'/app/Core/Migrator.php'; require ROOT.'/app/Core/helpers.php';
require ROOT.'/app/Modules/Knowledge/src/KnowledgeService.php';
function checkKnowledge(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejectKnowledge(callable $action): void { try { $action(); } catch (InvalidArgumentException) { return; } throw new RuntimeException('Expected invalid note input to be rejected.'); }
Db::init(':memory:'); Migrator::run([ROOT.'/app/Modules/Knowledge/migrations']);
$id=KnowledgeService::save(null,['title'=>'Roadmap','body'=>'Ship a safe local-first app. [[Meeting]] <script>alert(1)</script>','notebook'=>'Projects','tags'=>'Work, ideas, work']);
checkKnowledge($id>0 && KnowledgeService::note($id)['tags']==='Work, ideas','Save and normalize tags');
$meeting=KnowledgeService::save(null,['title'=>'Meeting','body'=>'See [[Roadmap]]','notebook'=>'Projects','tags'=>'work']);
checkKnowledge(count(KnowledgeService::backlinks(KnowledgeService::note($id)))===1,'Find backlinks');
$body=KnowledgeService::linkedBody(KnowledgeService::note($id)['body']);
checkKnowledge(str_contains($body,'href="/knowledge/'.$meeting.'"') && !str_contains($body,'<script>'),'Link only existing notes and escape HTML');
checkKnowledge(KnowledgeService::listing(['q'=>'local-first'])['total']===1,'Search note bodies');
checkKnowledge(KnowledgeService::listing(['notebook'=>'Projects','tag'=>'work'])['total']===2,'Filter notebook and exact tag');
checkKnowledge(KnowledgeService::listing(['q'=>'%'])['total']===0,'Treat wildcard search literally');
KnowledgeService::save($id,['title'=>'Roadmap','body'=>'Updated','notebook'=>'Projects','tags'=>'work']);
checkKnowledge(KnowledgeService::note($id)['body']==='Updated','Edit note');
KnowledgeService::setFlag($id,'pinned',true);
checkKnowledge((int)KnowledgeService::listing([])['rows'][0]['id']===$id,'Pinned notes sort first');
KnowledgeService::setFlag($id,'archived',true);
checkKnowledge(KnowledgeService::listing(['state'=>'archived'])['total']===1 && KnowledgeService::listing([])['total']===1,'Archive separates notes');
$copy=KnowledgeService::duplicate($meeting);
checkKnowledge(KnowledgeService::note($copy)['title']==='Meeting (copy)','Duplicate note');
checkKnowledge(KnowledgeService::daily()===null,'Daily note opens an unsaved draft');
$daily=KnowledgeService::save(null,['title'=>date('Y-m-d'),'body'=>'Today','notebook'=>'Daily','tags'=>'journal']);
checkKnowledge($daily===KnowledgeService::daily(),'Daily note is reused');
KnowledgeService::trash($meeting);
checkKnowledge(KnowledgeService::listing(['state'=>'trash'])['total']===1,'Trash keeps note');
rejectKnowledge(fn()=>KnowledgeService::save($meeting,['title'=>'Edited','body'=>'','notebook'=>'General']));
KnowledgeService::trash($meeting,true);
checkKnowledge(KnowledgeService::note($meeting)['deleted_at']===null,'Restore note');
$file=tempnam(sys_get_temp_dir(),'lifeos-note-'); file_put_contents($file,"# Imported\n\nContent");
try { $imported=KnowledgeService::import($file,'imported.md'); checkKnowledge(KnowledgeService::note($imported)['notebook']==='Imported','Import Markdown'); rejectKnowledge(fn()=>KnowledgeService::import($file,'bad.exe')); } finally { unlink($file); }
checkKnowledge(KnowledgeService::words('one two  three')===3,'Count words');
rejectKnowledge(fn()=>KnowledgeService::tags(implode(',',range(1,11))));
rejectKnowledge(fn()=>KnowledgeService::listing(['state'=>'invalid']));
checkKnowledge(KnowledgeService::listing(['sort'=>'title'])['total']>=4,'Sort by title');
echo "Knowledge Hub service checks passed.\n";
