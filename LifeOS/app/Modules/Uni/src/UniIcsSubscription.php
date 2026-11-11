<?php
declare(strict_types=1);

/** One private subscribed ICS feed per local LifeOS profile. */
final class UniIcsSubscription {
    private const SECRET='uni_ics_url';
    private const MAX_BYTES=2097152;
    public static function status(): array {
        return ['connected'=>Db::setting('uni_ics_connected')==='1',
            'host'=>Db::setting('uni_ics_host'), 'lastSync'=>Db::setting('uni_ics_last_sync'),
            'feedFirst'=>Db::setting('uni_ics_feed_first'),'feedLast'=>Db::setting('uni_ics_feed_last'),
            'lastError'=>Db::setting('uni_ics_error'), 'automatic'=>Db::setting('uni_ics_automatic','1')==='1'];
    }
    private static function endpoint(string $url): array {
        if (strlen($url)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) throw new InvalidArgumentException(t('Enter a valid HTTPS calendar URL.'));
        $parts=parse_url($url);
        if (!$parts || strtolower($parts['scheme'] ?? '')!=='https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port']!==443)) throw new InvalidArgumentException(t('Enter a valid HTTPS calendar URL.'));
        $host=strtolower($parts['host']);
        if (!preg_match('/^(?=.{4,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])$/D',$host) || !str_contains($host,'.') || filter_var($host,FILTER_VALIDATE_IP)) throw new InvalidArgumentException(t('Enter a public HTTPS calendar URL.'));
        $records=dns_get_record($host,DNS_A|DNS_AAAA);
        if (!$records) throw new RuntimeException(t('Calendar host could not be reached. Saved lectures are unchanged.'));
        $ips=[];
        foreach ($records as $record) {
            $ip=$record['ip'] ?? $record['ipv6'] ?? '';
            if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new InvalidArgumentException(t('Calendar URL must use a public host.'));
            $ips[]=$ip;
        }
        if (!$ips) throw new InvalidArgumentException(t('Calendar URL must use a public host.'));
        usort($ips,static fn(string $a,string $b): int => (int)(str_contains($a,':')) <=> (int)(str_contains($b,':')));
        return [$host,$ips[0]];
    }
    private static function fetch(string $url,bool $conditional): array {
        [$host,$ip]=self::endpoint($url);
        $body=''; $headers=[];
        $curl=curl_init($url);
        if (!$curl) throw new RuntimeException(t('Calendar could not be downloaded. Saved lectures are unchanged.'));
        $requestHeaders=['Accept: text/calendar, text/plain;q=0.8'];
        if ($conditional && Db::setting('uni_ics_etag')!=='') $requestHeaders[]='If-None-Match: '.Db::setting('uni_ics_etag');
        if ($conditional && Db::setting('uni_ics_modified')!=='') $requestHeaders[]='If-Modified-Since: '.Db::setting('uni_ics_modified');
        curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>$requestHeaders,CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_CAINFO=>ROOT.'/app/Core/cacert.crt',
            CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>[$host.':443:'.(str_contains($ip,':')?'['.$ip.']':$ip)],
            CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use (&$headers): int {
                $pair=explode(':',trim($line),2);
                if (count($pair)===2 && in_array(strtolower($pair[0]),['etag','last-modified'],true)) $headers[strtolower($pair[0])]=trim($pair[1]);
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body): int {
                if (strlen($body)+strlen($chunk)>self::MAX_BYTES) return 0;
                $body.=$chunk; return strlen($chunk);
            }]);
        $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
        if ($ok===false) throw new RuntimeException(t('Calendar could not be downloaded. Saved lectures are unchanged.'));
        if ($status===304 && $conditional) return ['unchanged'=>true];
        if ($status===401 || $status===403 || $status===410) throw new RuntimeException(t('Calendar access expired. Reconnect the feed URL. Saved lectures are unchanged.'));
        if ($status!==200) throw new RuntimeException(t('Calendar could not be downloaded. Saved lectures are unchanged.'));
        if ($body==='' || strlen($body)>self::MAX_BYTES) throw new InvalidArgumentException(t('Calendar feed is empty or too large.'));
        return ['unchanged'=>false,'body'=>$body,'etag'=>mb_substr($headers['etag'] ?? '',0,256),'modified'=>mb_substr($headers['last-modified'] ?? '',0,128)];
    }
    public static function connect(string $url): array {
        $url=trim($url);
        $result=self::fetch($url,false);
        $events=self::parse($result['body']);
        return Db::tx(function() use ($url,$events,$result): array {
            ConnectivitySecrets::save(self::SECRET,$url);
            Db::set('uni_ics_connected','1'); Db::set('uni_ics_host',parse_url($url,PHP_URL_HOST));
            return self::apply($events,$result,false);
        });
    }
    public static function sync(): array {
        if (Db::setting('uni_ics_connected')!=='1') throw new InvalidArgumentException(t('Connect a calendar first.'));
        try {
            $result=self::fetch(ConnectivitySecrets::get(self::SECRET),true);
            if ($result['unchanged']) { Db::set('uni_ics_last_sync',date('Y-m-d H:i:s')); Db::set('uni_ics_error',''); return ['unchanged'=>true]; }
            return self::apply(self::parse($result['body']),$result);
        } catch (Throwable $e) {
            // Never persist a network exception: it can contain the private feed URL.
            Db::set('uni_ics_error','Calendar sync failed. Saved lectures are unchanged.');
            throw $e;
        }
    }
    private static function parse(string $ics): array {
        $from=(new DateTimeImmutable('first day of January last year'))->format('Y-m-d');
        $until=(new DateTimeImmutable('last day of December next year'))->format('Y-m-d');
        return ConnectivityIcs::preview($ics,$from,$until,5000);
    }
    private static function apply(array $events,array $response,bool $transaction=true): array {
        $now=date('Y-m-d H:i:s'); $marker=bin2hex(random_bytes(8));
        $work=function() use ($events,$response,$now,$marker): array {
            $count=['added'=>0,'changed'=>0,'cancelled'=>0,'unchanged'=>0]; $seen=[]; $changes=[];
            foreach ($events as $event) {
                $uid=$event['uid']; $seen[$uid]=true;
                $hash=hash('sha256',json_encode($event,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
                $old=Db::one('SELECT * FROM uni_ics_events WHERE uid=?',[$uid]);
                $linked=$old && $old['appointment_id'] ? Db::one('SELECT id,status FROM planner_appointments WHERE id=?',[$old['appointment_id']]) : null;
                if ($old && $old['details_hash']===$hash && $linked && $linked['status']===$event['status']) { Db::run('UPDATE uni_ics_events SET last_seen_at=? WHERE id=?',[$marker,$old['id']]); $count['unchanged']++; continue; }
                $appointment=PlannerService::saveAppointment($linked['id'] ?? null,$event);
                Db::run('INSERT INTO uni_ics_events(uid,appointment_id,details_hash,last_seen_at,source_status) VALUES (?,?,?,?,?) ON CONFLICT(uid) DO UPDATE SET appointment_id=excluded.appointment_id,details_hash=excluded.details_hash,last_seen_at=excluded.last_seen_at,source_status=excluded.source_status',[$uid,$appointment,$hash,$marker,$event['status']]);
                $type=$event['status']==='cancelled'?'cancelled':($old?'changed':'added'); $count[$type]++; $changes[]=['type'=>$type,'uid'=>$uid,'title'=>$event['title'],'starts_at'=>$event['starts_at'],'appointment_id'=>$appointment];
            }
            // Only a complete successful feed may mark missing events cancelled.
            $dates=array_column($events,'starts_at');
            $windowStart=$dates ? min($dates) : null; $windowEnd=$dates ? max($dates) : null;
            // A rolling or temporarily empty feed does not prove events outside its visible range were removed.
            foreach ($windowStart!==null ? Db::all("SELECT u.*,p.title,p.starts_at FROM uni_ics_events u JOIN planner_appointments p ON p.id=u.appointment_id WHERE u.source_status='scheduled' AND u.last_seen_at<>? AND p.starts_at>=? AND p.starts_at<=?",[$marker,$windowStart,$windowEnd]) : [] as $old) {
                if (isset($seen[$old['uid']])) continue;
                if ($old['appointment_id']) Db::run("UPDATE planner_appointments SET status='cancelled' WHERE id=?",[$old['appointment_id']]);
                Db::run("UPDATE uni_ics_events SET source_status='cancelled',last_seen_at=? WHERE id=?",[$marker,$old['id']]);
                $count['cancelled']++; $changes[]=['type'=>'cancelled','uid'=>$old['uid'],'title'=>$old['title'],'starts_at'=>$old['starts_at'],'appointment_id'=>$old['appointment_id']];
            }
            Db::set('uni_ics_etag',$response['etag'] ?? ''); Db::set('uni_ics_modified',$response['modified'] ?? '');
            if ($windowStart!==null) { Db::set('uni_ics_feed_first',substr($windowStart,0,10)); Db::set('uni_ics_feed_last',substr($windowEnd,0,10)); }
            Db::set('uni_ics_last_sync',$now); Db::set('uni_ics_error','');
            if ($changes) {
                $parts=[]; foreach (['added'=>'new','changed'=>'updated','cancelled'=>'cancelled'] as $key=>$label) if ($count[$key]) $parts[]=$count[$key].' '.$label;
                Db::run('INSERT INTO uni_calendar_notifications(batch_id,title,body,created_at,changes_json) VALUES (?,?,?,?,?)',['ics','University calendar updated',implode(', ',$parts).'.',$now,json_encode($changes,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
            }
            return $count;
        };
        return $transaction ? Db::tx($work) : $work();
    }
    public static function disconnect(): void {
        ConnectivitySecrets::forget(self::SECRET);
        foreach (['uni_ics_connected','uni_ics_host','uni_ics_etag','uni_ics_modified','uni_ics_error','uni_ics_feed_first','uni_ics_feed_last'] as $key) Db::set($key,'');
    }
    public static function events(string $from,string $until): array {
        return Db::all('SELECT p.*,u.uid FROM uni_ics_events u JOIN planner_appointments p ON p.id=u.appointment_id WHERE p.starts_at<? AND p.ends_at>? ORDER BY p.starts_at,p.id LIMIT 5000',[$until.' 23:59',$from.' 00:00']);
    }
}
