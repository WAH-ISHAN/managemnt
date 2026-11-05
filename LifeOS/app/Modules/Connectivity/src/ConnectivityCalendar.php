<?php
declare(strict_types=1);
final class ConnectivityCalendar {
    private static function hash(array $a): string {
        $fields=array_intersect_key($a,array_flip(['title','starts_at','ends_at','location','notes','status']));
        // Preserve hashes from the timed-only release when upgrading existing links.
        if (!empty($a['all_day'])) $fields['all_day']=1;
        return hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR));
    }
    private static function body(array $a): array { return ['title'=>mb_substr($a['title'],0,200),'starts_at'=>$a['starts_at'],'ends_at'=>$a['ends_at'],'location'=>mb_substr($a['location'] ?? '',0,200),'notes'=>mb_substr($a['notes'] ?? '',0,2000),'status'=>!empty($a['cancelled']) ? 'cancelled' : ($a['status'] ?? 'scheduled'),'all_day'=>!empty($a['all_day']) ? 1 : 0]; }
    public static function sync(): array {
        $c=ConnectivityService::config(); if (!$c['calendar_enabled'] || !module_enabled('Planner')) throw new InvalidArgumentException(t('Enable calendar sync and Planner first.'));
        $token=ConnectivitySecrets::get('calendar_access_token');
        $refresh=ConnectivitySecrets::get('calendar_refresh_token');
        if ($refresh!=='' && (int)Db::setting('connection_calendar_token_expiry','0')<time()+120) {
            if ($c['calendar_client_id']==='') throw new InvalidArgumentException(t('Add the OAuth client ID to refresh calendar authorization.'));
            $tokens=ConnectivityService::worker('oauth-refresh',['provider'=>$c['calendar_provider'],'tenant'=>$c['calendar_tenant'],'client_id'=>$c['calendar_client_id'],'client_secret'=>ConnectivitySecrets::get('calendar_client_secret'),'refresh_token'=>$refresh]);
            $token=$tokens['access_token'] ?? ''; if (!is_string($token) || $token==='') throw new RuntimeException(t('Calendar authorization could not be refreshed. Reauthorize the provider.'));
            ConnectivitySecrets::save('calendar_access_token',$token);
            if (!empty($tokens['refresh_token'])) ConnectivitySecrets::save('calendar_refresh_token',$tokens['refresh_token']);
            Db::set('connection_calendar_token_expiry',(string)(time()+max(60,(int)($tokens['expires_in'] ?? 3600))));
        } if ($token==='') throw new InvalidArgumentException(t('Add a provider calendar access token with calendar read/write permissions.'));
        $base=['provider'=>$c['calendar_provider'],'calendar'=>$c['calendar_id'],'token'=>$token];
        $data=ConnectivityService::worker('calendar-fetch',$base);
        $key=$c['calendar_provider'].':'.$c['calendar_id'];
        $result=self::reconcile($key,$data['events'] ?? [],$c['calendar_push'],fn($event,$link)=>ConnectivityService::worker('calendar-write',$base+['event'=>$event,'remote_id'=>$link['remote_id'] ?? null,'version'=>$link['remote_version'] ?? null,'identity'=>Db::setting('connection_calendar_identity').':'.$event['id']]),$c['calendar_delete']);
        ConnectivityService::log('calendar',$result['conflicts'] ? 'attention' : 'complete',json_encode($result)); return $result;
    }
    /** External calls are injected so conflict and idempotency semantics can be verified offline. */
    public static function reconcile(string $provider,array $events,bool $push,callable $writer,bool $allowDelete=false): array {
        $result=['imported'=>0,'updated'=>0,'exported'=>0,'deleted'=>0,'conflicts'=>0]; $seen=[];
        foreach ($events as $event) {
            $remote=PlannerService::title($event['remote_id'] ?? '',1000); $version=(string)($event['version'] ?? '');
            $link=Db::one('SELECT * FROM connectivity_events WHERE provider=? AND remote_id=?',[$provider,$remote]);
            if ($link) $seen[(int)$link['id']]=true;
            $local=$link && $link['appointment_id'] ? Db::one('SELECT * FROM planner_appointments WHERE id=?',[$link['appointment_id']]) : null;
            if (!$link) {
                if (!empty($event['cancelled'])) continue;
                $body=self::body($event); $id=PlannerService::saveAppointment(null,$body);
                Db::run('INSERT INTO connectivity_events(provider,remote_id,appointment_id,remote_version,local_hash) VALUES (?,?,?,?,?)',[$provider,$remote,$id,$version,self::hash($body)]); $result['imported']++; continue;
            }
            if (!$local) {
                if (!empty($event['cancelled'])) { Db::run('UPDATE connectivity_events SET remote_deleted=1 WHERE id=?',[$link['id']]); continue; }
                if ($link['remote_deleted']) continue;
                if (!$link['local_deleted'] || !$push || !$allowDelete || $version!==$link['remote_version']) { $result['conflicts']++; continue; }
                self::sendDeletion($link,null,$writer,$result); continue;
            }
            $localBody=self::body($local); $changed=!hash_equals($link['local_hash'],self::hash($localBody));
            if ($version!==$link['remote_version'] || !empty($event['cancelled'])) {
                if ($changed) { $result['conflicts']++; continue; }
                $body=!empty($event['cancelled']) ? array_replace($localBody,['status'=>'cancelled']) : self::body($event);
                PlannerService::saveAppointment((int)$local['id'],$body);
                Db::run('UPDATE connectivity_events SET remote_version=?,local_hash=?,remote_deleted=? WHERE id=?',[$version,self::hash($body),!empty($event['cancelled']) ? 1 : 0,$link['id']]); $result['updated']++;
            } elseif ($changed && $push && $local['status']==='cancelled') {
                if ($allowDelete) self::sendDeletion($link,$local,$writer,$result);
            } elseif ($changed && $push && $link['remote_deleted']) {
                $result['conflicts']++;
            } elseif ($changed && $push && $local['status']==='scheduled') {
                $written=$writer($local,$link);
                if (!empty($written['conflict'])) { $result['conflicts']++; continue; }
                Db::run('UPDATE connectivity_events SET remote_version=?,local_hash=? WHERE id=?',[$written['version'] ?? '',self::hash($localBody),$link['id']]); $result['exported']++;
            }
        }
        // Tombstones also survive events falling outside the provider's bounded view.
        if ($push && $allowDelete) foreach (Db::all("SELECT e.* FROM connectivity_events e LEFT JOIN planner_appointments a ON a.id=e.appointment_id WHERE e.provider=? AND e.remote_deleted=0 AND (e.local_deleted=1 OR a.status='cancelled') ORDER BY e.id LIMIT 100",[$provider]) as $link) {
            if (isset($seen[(int)$link['id']])) continue;
            $local=$link['appointment_id'] ? Db::one('SELECT * FROM planner_appointments WHERE id=?',[$link['appointment_id']]) : null;
            self::sendDeletion($link,$local,$writer,$result);
        }
        if ($push) foreach (Db::all("SELECT a.* FROM planner_appointments a LEFT JOIN connectivity_events e ON e.appointment_id=a.id AND e.provider=? WHERE e.id IS NULL AND a.status='scheduled' AND a.starts_at>=? AND a.starts_at<? ORDER BY a.starts_at LIMIT 100",[$provider,date('Y-m-d',strtotime('-30 days')),date('Y-m-d',strtotime('+365 days'))]) as $local) {
            $written=$writer($local,null); $body=self::body($local);
            Db::run('INSERT INTO connectivity_events(provider,remote_id,appointment_id,remote_version,local_hash) VALUES (?,?,?,?,?)',[$provider,$written['remote_id'],$local['id'],$written['version'] ?? '',self::hash($body)]); $result['exported']++;
        }
        return $result;
    }
    private static function sendDeletion(array $link,?array $local,callable $writer,array &$result): void {
        if ($link['remote_version']==='') { $result['conflicts']++; return; }
        $written=$writer($local ?? ['id'=>$link['id'],'status'=>'cancelled'],$link);
        if (empty($written['deleted']) || !empty($written['conflict'])) { $result['conflicts']++; return; }
        Db::run('UPDATE connectivity_events SET remote_deleted=1,local_hash=? WHERE id=?',[$local ? self::hash(self::body($local)) : $link['local_hash'],$link['id']]); $result['deleted']++;
    }
}
