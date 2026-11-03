<?php
declare(strict_types=1);

final class KnowledgeService {
    public const TEMPLATES=[
        'blank'=>['title'=>'','body'=>''],
        'meeting'=>['title'=>'Meeting notes','body'=>"## Agenda\n- \n\n## Decisions\n- \n\n## Next actions\n- "],
        'project'=>['title'=>'Project brief','body'=>"## Goal\n\n## Scope\n\n## Milestones\n- [ ] \n\n## Risks\n"],
        'learning'=>['title'=>'Learning note','body'=>"## Key idea\n\n## What I learned\n\n## Questions\n- \n\n## Next review\n"],
    ];
    private static function value(mixed $value,int $max,string $label,bool $required=false): string {
        if (!is_string($value) || str_contains($value,"\0")) throw new InvalidArgumentException('Enter a valid '.$label.'.');
        $value=trim($value);
        if (mb_strlen($value)>$max || ($required && $value==='')) throw new InvalidArgumentException('Enter a '.($required?'non-empty ':'').$label.' of up to '.$max.' characters.');
        return $value;
    }
    public static function note(int $id): array {
        $row=Db::one('SELECT * FROM knowledge_notes WHERE id=?',[$id]);
        if (!$row) throw new InvalidArgumentException('Note not found.');
        return $row;
    }
    public static function save(?int $id,array $data): int {
        $title=self::value($data['title']??'',200,'title',true);
        $body=self::value($data['body']??'',50000,'note');
        $notebook=self::value($data['notebook']??'General',80,'notebook',true);
        $tags=self::tags($data['tags']??'');
        if ($id!==null) {
            $note=self::note($id); if ($note['deleted_at']!==null) throw new InvalidArgumentException('Restore this note before editing.');
            Db::run('UPDATE knowledge_notes SET title=?,body=?,notebook=?,tags=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$title,$body,$notebook,$tags,$id]);
            return $id;
        }
        return Db::run('INSERT INTO knowledge_notes(title,body,notebook,tags) VALUES (?,?,?,?)',[$title,$body,$notebook,$tags]);
    }
    public static function tags(mixed $input): string {
        $raw=self::value($input,400,'tags'); $out=[];
        foreach (explode(',',$raw) as $tag) {
            $tag=trim($tag); if ($tag==='') continue;
            if (mb_strlen($tag)>40 || str_contains($tag,'[') || str_contains($tag,']')) throw new InvalidArgumentException('Each tag must be under 40 characters.');
            $out[mb_strtolower($tag)]??=$tag;
        }
        if (count($out)>10) throw new InvalidArgumentException('Use at most 10 tags.');
        return implode(', ',array_values($out));
    }
    public static function listing(array $filters): array {
        $q=self::value($filters['q']??'',200,'search');
        $notebook=self::value($filters['notebook']??'',80,'notebook');
        $tag=self::value($filters['tag']??'',40,'tag');
        $state=$filters['state']??'active'; $sort=$filters['sort']??'recent';
        if (!is_string($state) || !in_array($state,['active','archived','trash'],true) || !is_string($sort) || !in_array($sort,['recent','title'],true)) throw new InvalidArgumentException('Choose a valid note view.');
        $where=[$state==='trash'?'deleted_at IS NOT NULL':'deleted_at IS NULL AND archived='.($state==='archived'?'1':'0')]; $args=[];
        if ($q!=='') { $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%'; $where[]="(title LIKE ? ESCAPE '!' OR body LIKE ? ESCAPE '!')"; array_push($args,$like,$like); }
        if ($notebook!=='') { $where[]='notebook=?'; $args[]=$notebook; }
        if ($tag!=='') { $where[]="(',' || lower(replace(tags, ', ', ',')) || ',') LIKE ? ESCAPE '!'"; $args[]='%,' . str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($tag)) . ',%'; }
        $order=$sort==='title'?'title COLLATE NOCASE,id DESC':'pinned DESC,updated_at DESC,id DESC';
        return ['rows'=>Db::all('SELECT * FROM knowledge_notes WHERE '.implode(' AND ',$where).' ORDER BY '.$order.' LIMIT 200',$args),
            'total'=>(int)Db::one('SELECT COUNT(*) n FROM knowledge_notes WHERE '.implode(' AND ',$where),$args)['n'],
            'q'=>$q,'notebook'=>$notebook,'tag'=>$tag,'state'=>$state,'sort'=>$sort];
    }
    public static function notebooks(): array { return array_column(Db::all('SELECT DISTINCT notebook FROM knowledge_notes WHERE deleted_at IS NULL ORDER BY notebook COLLATE NOCASE LIMIT 100'),'notebook'); }
    public static function allTags(): array {
        $tags=[]; foreach (Db::all('SELECT tags FROM knowledge_notes WHERE deleted_at IS NULL') as $row) foreach (explode(', ',$row['tags']) as $tag) if ($tag!=='') $tags[mb_strtolower($tag)]=$tag;
        natcasesort($tags); return array_values($tags);
    }
    public static function setFlag(int $id,string $flag,bool $value): void {
        if (!in_array($flag,['pinned','archived'],true)) throw new InvalidArgumentException('Invalid note action.');
        $note=self::note($id); if ($note['deleted_at']!==null) throw new InvalidArgumentException('Restore this note first.');
        Db::run('UPDATE knowledge_notes SET '.$flag.'=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$value?1:0,$id]);
    }
    public static function trash(int $id,bool $restore=false): void {
        self::note($id); Db::run('UPDATE knowledge_notes SET deleted_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$restore?null:date('Y-m-d H:i:s'),$id]);
    }
    public static function duplicate(int $id): int {
        $note=self::note($id); if ($note['deleted_at']!==null) throw new InvalidArgumentException('Restore this note first.');
        return self::save(null,['title'=>mb_substr($note['title'].' (copy)',0,200),'body'=>$note['body'],'notebook'=>$note['notebook'],'tags'=>$note['tags']]);
    }
    public static function daily(): ?int {
        $title=date('Y-m-d'); $row=Db::one("SELECT id FROM knowledge_notes WHERE notebook='Daily' AND title=? AND deleted_at IS NULL ORDER BY id LIMIT 1",[$title]);
        return $row?(int)$row['id']:null;
    }
    public static function import(string $path,string $filename): int {
        if (!is_file($path) || filesize($path)>50000 || !preg_match('/\.md$/i',$filename)) throw new InvalidArgumentException('Choose a Markdown file up to 50 KB.');
        $body=file_get_contents($path); if ($body===false || !mb_check_encoding($body,'UTF-8')) throw new InvalidArgumentException('Choose a UTF-8 Markdown file.');
        return self::save(null,['title'=>mb_substr(pathinfo($filename,PATHINFO_FILENAME),0,200),'body'=>$body,'notebook'=>'Imported','tags'=>'']);
    }
    public static function backlinks(array $note): array {
        $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],'[['.$note['title'].']]').'%';
        return Db::all("SELECT id,title FROM knowledge_notes WHERE id<>? AND deleted_at IS NULL AND body LIKE ? ESCAPE '!' ORDER BY updated_at DESC LIMIT 30",[$note['id'],$term]);
    }
    public static function linkedBody(string $body): string {
        $titles=[]; foreach (Db::all('SELECT id,title FROM knowledge_notes WHERE deleted_at IS NULL ORDER BY updated_at DESC,id DESC LIMIT 1000') as $row) $titles[mb_strtolower($row['title'])]??=(int)$row['id'];
        $parts=preg_split('/(\[\[[^\]\n]{1,200}\]\])/u',$body,-1,PREG_SPLIT_DELIM_CAPTURE);
        if ($parts===false) return e($body);
        $html=''; foreach ($parts as $part) {
            if (str_starts_with($part,'[[') && str_ends_with($part,']]')) {
                $title=substr($part,2,-2); $id=$titles[mb_strtolower($title)]??null;
                $html.=$id?'<a href="/knowledge/'.$id.'">'.e($title).'</a>':e($part);
            } else $html.=e($part);
        }
        return $html;
    }
    public static function words(string $body): int { return count(preg_split('/\s+/u',trim($body),-1,PREG_SPLIT_NO_EMPTY) ?: []); }
}
