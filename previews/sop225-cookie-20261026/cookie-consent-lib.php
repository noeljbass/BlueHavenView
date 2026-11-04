<?php
declare(strict_types=1);
const BH_COOKIE_POLICY = 'bh-preview-measurement-20261026-v4';
const BH_COOKIE_NAME = '__Secure-bh-preview-20261026';
const BH_STORE_PREFIX = "<?php http_response_code(404); exit; ?>\n";
function bh_unknown(): array { return ['adUserData'=>'UNKNOWN','adPersonalization'=>'UNKNOWN','source'=>'','policyVersion'=>'','collectedAt'=>'']; }
function bh_now(): string { return gmdate('Y-m-d\TH:i:s') . '.000Z'; }
function bh_id(): string { return bin2hex(random_bytes(32)); }
function bh_store_dir(): string { return __DIR__ . '/.preview-store'; }
function bh_transaction(string $dir, callable $fn) {
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('store_unavailable');
    chmod($dir,0700);
    $lock = fopen($dir.'/lock.php','c+');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('lock_unavailable');
    try {
        if (fstat($lock)['size'] === 0) { fwrite($lock,BH_STORE_PREFIX); fflush($lock); }
        chmod($dir.'/lock.php',0600);
        return $fn();
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function bh_read(string $dir,string $id): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/D',$id)) return null;
    $path=$dir.'/'.$id.'.php';
    if (!is_file($path)) return null;
    $raw=file_get_contents($path);
    if (!str_starts_with($raw,BH_STORE_PREFIX)) throw new RuntimeException('store_corrupt');
    return json_decode(substr($raw,strlen(BH_STORE_PREFIX)),true,512,JSON_THROW_ON_ERROR);
}
function bh_save(string $dir,string $id,array $record): void {
    if (!preg_match('/^[a-f0-9]{64}$/D',$id)) throw new RuntimeException('invalid_key');
    $tmp=$dir.'/'.bh_id().'.php';
    $f=fopen($tmp,'x'); chmod($tmp,0600);
    $bytes=BH_STORE_PREFIX.json_encode($record,JSON_THROW_ON_ERROR);
    if (fwrite($f,$bytes)!==strlen($bytes) || !fflush($f) || !fsync($f)) { fclose($f); throw new RuntimeException('store_failed'); }
    fclose($f);
    if (!rename($tmp,$dir.'/'.$id.'.php')) throw new RuntimeException('store_failed');
}
function bh_valid_origin(array $server): bool {
    return ($server['HTTP_ORIGIN'] ?? '') === preview_origin() &&
        in_array($server['HTTP_SEC_FETCH_SITE'] ?? 'same-origin',['same-origin','none'],true);
}
function bh_csrf(string $id,string $token): string {
    if ($token==='') throw new RuntimeException('config_missing');
    return hash_hmac('sha256','bluehaven-preview-20261026/csrf/'.$id,$token);
}
function bh_current(?array $r): array {
    if (!$r || ($r['expires'] ?? 0)<=time()) return bh_unknown();
    $c=$r['receipts'][$r['current'] ?? ''] ?? null;
    if (!$c || ($c['permission']['policyVersion'] ?? '')!==BH_COOKIE_POLICY) return bh_unknown();
    return $c['permission'];
}
function bh_choice(array &$r,string $choice,string $page,bool $gpc): void {
    if (!in_array($choice,['accept','decline'],true)) throw new InvalidArgumentException('invalid_choice');
    if (!preg_match('~^/[a-zA-Z0-9/_ .%-]*$~D',$page) || strlen($page)>1000) throw new InvalidArgumentException('invalid_page');
    if ($gpc) $choice='decline';
    $now=bh_now();
    // Revoke every prior generation; re-accept never resurrects captured leads.
    if ($choice==='decline') foreach ($r['receipts'] ?? [] as $rid=>$receipt) {
        if (!isset($r['withdrawals'][$rid])) $r['withdrawals'][$rid]=['id'=>bh_id(),'at'=>$now,'ack'=>false];
        $r['receipts'][$rid]['permission']['withdrawnAt']=$now;
    }
    $rid=bh_id(); $r['current']=$rid; $r['expires']=time()+180*86400;
    $r['receipts'][$rid]=['permission'=>['adUserData'=>$choice==='accept'?'GRANTED':'DENIED',
        'adPersonalization'=>'UNKNOWN','source'=>'website_explicit_google_controls','policyVersion'=>BH_COOKIE_POLICY,
        'collectedAt'=>$now,'expiresAt'=>gmdate('Y-m-d\TH:i:s',$r['expires']).'.000Z',
        'receiptId'=>$rid,'clientPage'=>$page,'captureContract'=>'bh-cookie-v4'],
        'recordedAt'=>$now,'choice'=>$choice,'gpc'=>$gpc,'bindings'=>[]];
}

const PREVIEW_PREFIX = '/previews/sop225-cookie-20261026/';
function preview_origin(): string { return 'https://bluehavenview.com'; }
function preview_page($page): bool { return in_array($page, array_map(fn($p)=>PREVIEW_PREFIX.$p,['index.html','window-replacement.html','franklin-window-replacement-ads.html','privacy-policy.html']),true) || $page===PREVIEW_PREFIX; }
function preview_headers(): void { header('Content-Type: application/json'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow, noarchive'); }
function preview_body(): array {
 if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST' || !bh_valid_origin($_SERVER) || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')!==0) { http_response_code(403); exit('{"ok":false}'); }
 $raw=file_get_contents('php://input',false,null,0,8193);
 if(strlen($raw)>8192) throw new InvalidArgumentException('oversize');
 $p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 $config=require __DIR__.'/.preview-config.php';
 $id=$_COOKIE[BH_COOKIE_NAME] ?? '';
 if(!preg_match('/^[a-f0-9]{64}$/D',$id) || !is_string($p['csrf'] ?? null) || !hash_equals(bh_csrf($id,$config['secret']),$p['csrf']) || !preview_page($p['page'] ?? '')) { http_response_code(403); exit('{"ok":false}'); }
 return $p;
}
