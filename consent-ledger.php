<?php
declare(strict_types=1);
// Server receipt helpers. No credentials are read and no transport runs on include.
const BH_CHOICE_POLICY = '2026-10-26';
function bh_choice_page($value): string {
    if (!is_string($value) || strlen($value) > 500) return '';
    $u = parse_url($value);
    return is_array($u) && ($u['scheme'] ?? '') === 'https' && ($u['host'] ?? '') === 'bluehavenview.com'
        && !isset($u['user']) && !isset($u['pass']) && !isset($u['port']) && !isset($u['query']) && !isset($u['fragment'])
        ? 'https://bluehavenview.com' . ($u['path'] ?? '/') : '';
}
function bh_choice_iso(int $ms): string { return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000); }
function bh_choice_unknown(): array {
    return ['adUserData'=>'UNKNOWN','adPersonalization'=>'UNKNOWN','source'=>'website_existing_advertising_choice',
        'policyVersion'=>'','collectedAt'=>'','page'=>'','receipt'=>null];
}
function bh_choice_transition(array $old, array $input, string $subject, int $now, bool $gpc): array {
    $c = $input['choice'] ?? null;
    $valid = is_array($c) && ($c['policy'] ?? '') === BH_CHOICE_POLICY && ($c['version'] ?? null) === 3
        && is_bool($c['advertising'] ?? null) && is_int($c['timestamp'] ?? null) && is_int($c['expires'] ?? null)
        && $c['timestamp'] > 0 && $c['timestamp'] <= $now && $c['expires'] > $now
        && $c['expires'] > $c['timestamp'] && $c['expires'] - $c['timestamp'] <= 180*86400000
        && bh_choice_page($c['page'] ?? '') !== '';
    $fresh = ($input['action'] ?? '') === 'choice' && $valid && $now - $c['timestamp'] <= 300000
        && ($input['page'] ?? '') === $c['page'];
    $same = $valid && ($old['choice'] ?? null) === $c && ($old['status'] ?? '') === 'GRANTED';
    $grant = !$gpc && $valid && $c['advertising'] && ($fresh || $same);
    $status = $grant ? 'GRANTED' : (($gpc || (is_array($c) && ($c['advertising'] ?? null) === false)) ? 'DENIED' : 'UNKNOWN');
    // A restriction is latched for the subject: a later grant cannot rehabilitate old captures.
    $restricted = ($old['restricted'] ?? false) || ($old && $status !== 'GRANTED');
    $choice = $valid ? $c : null;
    if ($old && ($old['status'] ?? '') === $status && ($old['choice'] ?? null) === $choice
        && ($old['gpc'] ?? false) === $gpc) return $old;
    return ['subject'=>$subject,'revision'=>($old['revision'] ?? 0)+1,'status'=>$status,'choice'=>$choice,
        'gpc'=>$gpc,'restricted'=>$restricted,'receivedAt'=>bh_choice_iso($now),'acknowledged'=>false];
}
function bh_choice_event(array $state): array {
    return ['operation'=>'consent_state','subject'=>$state['subject'],'revision'=>$state['revision'],
        'status'=>$state['status'],'restricted'=>$state['restricted'],'gpc'=>$state['gpc'],
        'receivedAt'=>$state['receivedAt'],'choice'=>$state['choice']];
}
function bh_choice_file(string $directory, string $subject): string {
    if (preg_match('/^[a-f0-9]{64}$/D', $subject) !== 1) throw new RuntimeException('invalid_subject');
    return $directory . '/' . $subject . '.json';
}
// The lock inode is stable: data is replaced atomically, never locked by its inode.
function bh_choice_directory(string $directory): void {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('receipt_store_unavailable');
    if (is_link($directory) || (fileperms($directory) & 0077) !== 0) throw new RuntimeException('receipt_directory_permissions');
}
function bh_choice_atomic(string $path, array $value): void {
    $tmp = $path . '.' . bin2hex(random_bytes(12)) . '.tmp';
    $mask = umask(0077); $f = fopen($tmp, 'xb'); umask($mask);
    if (!$f) throw new RuntimeException('receipt_store_unavailable');
    try {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        if (fwrite($f, $json) !== strlen($json) || !fflush($f) || !fsync($f)) throw new RuntimeException('receipt_fsync_failed');
        if (!rename($tmp, $path)) throw new RuntimeException('receipt_replace_failed');
        $d = fopen(dirname($path), 'r');
        if (!$d) throw new RuntimeException('receipt_directory_sync_failed');
        try { if (!fsync($d)) throw new RuntimeException('receipt_directory_sync_failed'); } finally { fclose($d); }
    } finally { fclose($f); if (file_exists($tmp)) unlink($tmp); }
}
function bh_choice_restrict(string $directory, string $subject): void {
    bh_choice_directory($directory);
    // Monotone deny marker is independent of the history lock. Never deleted by grants/acks.
    $marker=bh_choice_file($directory,$subject).'.restricted';
    // An atomic monotone directory entry also remains a deny signal if fsync fails.
    if (!is_dir($marker) && !mkdir($marker,0700) && !is_dir($marker)) throw new RuntimeException('restriction_unavailable');
    $d=fopen($directory,'r');
    if (!$d) throw new RuntimeException('restriction_sync_failed');
    try { if (!fsync($d)) throw new RuntimeException('restriction_sync_failed'); } finally { fclose($d); }
}
function bh_choice_locked(string $directory, string $subject, int $mode, callable $work) {
    $path = bh_choice_file($directory,$subject);
    if (is_link($path) || is_link($path.'.lock') || (is_file($path) && (fileperms($path)&0077)!==0) || (is_file($path.'.lock') && (fileperms($path.'.lock')&0077)!==0)) throw new RuntimeException('receipt_file_permissions');
    $mask=umask(0077); $lock=fopen($path.'.lock','c+b'); umask($mask);
    if (!$lock) throw new RuntimeException('receipt_lock_unavailable');
    try {
        if (!flock($lock,$mode|LOCK_NB)) throw new RuntimeException('receipt_busy');
        $raw=is_file($path) ? file_get_contents($path) : '';
        $history=$raw === '' ? [] : json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($history)) throw new RuntimeException('receipt_corrupt');
        return $work($history,$path);
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function bh_choice_store(string $directory, string $subject, array $input, int $now, bool $gpc, callable $deliver): array {
    bh_choice_directory($directory);
    if ($gpc || (($input['choice']['advertising'] ?? null) === false)) bh_choice_restrict($directory,$subject);
    $history=bh_choice_locked($directory,$subject,LOCK_EX,function($history,$path) use($input,$subject,$now,$gpc) {
        $old=$history ? $history[count($history)-1] : [];
        $next=bh_choice_transition($old,$input,$subject,$now,$gpc);
        if (file_exists($path.'.restricted') && !($next['restricted']??false)) {
            if ($next === $old) {
                $next['revision']=$old['revision']+1;
                $next['receivedAt']=bh_choice_iso($now);
                $next['acknowledged']=false;
            }
            $next['restricted']=true;
        }
        if ($next !== $old) {
            if (count($history)>=256) {
                bh_choice_restrict(dirname($path),$subject);
                if ($old['restricted']) return $history;
                $next['restricted']=true; $next['status']='UNKNOWN'; $next['choice']=null;
            }
            $history[]=$next;
            bh_choice_atomic($path,$history);
        }
        return $history;
    });
    // Bounded replay outside the stable history lock. Concurrent sync is coalesced.
    $syncPath=bh_choice_file($directory,$subject).'.sync';
    if (is_link($syncPath) || (is_file($syncPath) && (fileperms($syncPath)&0077)!==0)) return $history[count($history)-1];
    $mask=umask(0077); $sync=fopen($syncPath,'c+b'); umask($mask);
    if (!$sync) return $history[count($history)-1];
    try {
        if (!flock($sync,LOCK_EX|LOCK_NB)) return $history[count($history)-1];
        $deadline=microtime(true)+8.0; $count=0;
        foreach ($history as $event) {
            if ($event['acknowledged']) continue;
            $remaining=(int)(($deadline-microtime(true))*1000);
            if ($remaining<=0 || ++$count>2 || !$deliver(bh_choice_event($event),$remaining)) break;
            try {
                $history=bh_choice_locked($directory,$subject,LOCK_EX,function($current,$path) use($event) {
                    $i=$event['revision']-1;
                    if (!isset($current[$i]) || bh_choice_event($current[$i]) !== bh_choice_event($event)) throw new RuntimeException('receipt_ack_revision_conflict');
                    $current[$i]['acknowledged']=true;
                    bh_choice_atomic($path,$current); return $current;
                });
            } catch (Throwable $e) { break; } // Remote acceptance is safely replayable by revision.
        }
    } finally { flock($sync,LOCK_UN); fclose($sync); }
    return $history[count($history)-1];
}
function bh_choice_for_lead(string $directory, string $subject, bool $gpc, int $now): array {
    $unknown=bh_choice_unknown();
    if (preg_match('/^[a-f0-9]{64}$/D',$subject)!==1) return $unknown;
    try {
        if ($gpc) { bh_choice_restrict($directory,$subject); return $unknown; }
        $path=bh_choice_file($directory,$subject);
        if (!is_dir($directory) || !is_writable($directory) || (fileperms($directory)&0077)!==0 || !is_file($path) || is_link($path)
            || (fileperms($path)&0077)!==0 || file_exists($path.'.restricted')) return $unknown;
        return bh_choice_locked($directory,$subject,LOCK_SH,function($history,$path) use($unknown,$now) {
            $s=$history ? $history[count($history)-1] : []; $c=$s['choice']??null;
            if (!$c || !$s['acknowledged'] || $s['restricted'] || $s['gpc'] || $s['status']!=='GRANTED'
                || $c['expires']<=$now || file_exists($path.'.restricted')) return $unknown;
            return ['adUserData'=>'GRANTED','adPersonalization'=>'GRANTED','source'=>'website_existing_advertising_choice',
                'policyVersion'=>$c['policy'],'collectedAt'=>bh_choice_iso($c['timestamp']),
                'expiresAt'=>bh_choice_iso($c['expires']),'page'=>$c['page'],
                'receipt'=>['subject'=>$s['subject'],'revision'=>$s['revision'],'receivedAt'=>$s['receivedAt']]];
        });
    } catch (Throwable $e) { return $unknown; }
}
