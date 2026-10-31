<?php
declare(strict_types=1);
final class WorkReleaseService {
    public const STATUSES=['planned'=>'Planned','released'=>'Released','archived'=>'Archived'];
    public static function listing(int $project):array {
        WorkService::project($project);
        return Db::all("SELECT r.*,(SELECT COUNT(*) FROM work_issues i WHERE i.fix_version_id=r.id AND i.deleted_at IS NULL AND i.archived_at IS NULL AND i.intake_status='accepted') total,(SELECT COUNT(*) FROM work_issues i JOIN work_states s ON s.id=i.state_id WHERE i.fix_version_id=r.id AND i.deleted_at IS NULL AND i.archived_at IS NULL AND i.intake_status='accepted' AND s.state_group='completed') completed FROM work_releases r WHERE r.project_id=? ORDER BY r.status='archived',r.status='released',r.release_on IS NULL,r.release_on,r.id DESC",[$project]);
    }
    public static function save(int $project,array $data,?int $id=null):int {
        $p=WorkService::project($project);if($p['status']==='archived')throw new InvalidArgumentException('Restore this project before editing releases.');
        $name=WorkService::text($data['name']??'',160,true);$description=WorkService::text($data['description']??'',5000);
        $status=$data['status']??'planned';if(!is_string($status)||!isset(self::STATUSES[$status]))throw new InvalidArgumentException('Choose a valid release status.');
        $start=WorkService::date($data['starts_on']??'');$release=WorkService::date($data['release_on']??'');if($start&&$release&&$release<$start)throw new InvalidArgumentException('Release date must be on or after the start date.');
        $expected=$id?int_range($data['version']??'',1,PHP_INT_MAX):null;
        return Db::tx(function()use($project,$data,$id,$name,$description,$status,$start,$release,$expected){
            if(Db::one('SELECT id FROM work_releases WHERE project_id=? AND name=? AND id<>?',[$project,$name,$id??0]))throw new InvalidArgumentException('This project already has that release name.');
            if($id){$old=WorkService::scoped('work_releases',$project,$id);if((int)$old['version']!==$expected)throw new InvalidArgumentException('This release changed. Reload before saving.');Db::run('UPDATE work_releases SET name=?,description=?,starts_on=?,release_on=?,status=?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$name,$description,$start,$release,$status,$id]);}
            else $id=Db::run('INSERT INTO work_releases(project_id,name,description,starts_on,release_on,status) VALUES (?,?,?,?,?,?)',[$project,$name,$description,$start,$release,$status]);
            WorkService::log($project,null,'release.saved',$name);return $id;
        });
    }
}
