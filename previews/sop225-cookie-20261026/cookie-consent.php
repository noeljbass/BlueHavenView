<?php
declare(strict_types=1);
require_once __DIR__.'/cookie-consent-lib.php'; preview_headers();
try {
 $method=$_SERVER['REQUEST_METHOD'] ?? '';
 if(!in_array($method,['GET','POST'],true)) { http_response_code(405); exit; }
 $p=$method==='POST' ? preview_body() : null;
 $config=require __DIR__.'/.preview-config.php';
 $id=$_COOKIE[BH_COOKIE_NAME] ?? ''; if(!preg_match('/^[a-f0-9]{64}$/D',$id)) $id=bh_id();
 $result=bh_transaction(bh_store_dir(),function() use($id,$config,$p) {
  $r=bh_read(bh_store_dir(),$id) ?? ['receipts'=>[],'withdrawals'=>[],'expires'=>0];
  if($p) {
   $choice=$p['choice'] ?? '';
   if(!in_array($choice,['accept','decline','reset'],true)) throw new InvalidArgumentException('choice');
   bh_choice($r,$choice==='reset'?'decline':$choice,$p['page'],($_SERVER['HTTP_SEC_GPC'] ?? '')==='1'||($p['gpc'] ?? false)===true);
   if($choice!=='reset') unset($r['resetAt']);
   if($choice==='reset') { $r['expires']=0; $r['resetAt']=bh_now(); $r['resetEvents'][]=['at'=>$r['resetAt'],'page'=>$p['page']]; }
  }
  if(($_SERVER['HTTP_SEC_GPC'] ?? '')==='1' && bh_current($r)['adUserData']==='GRANTED') bh_choice($r,'decline',PREVIEW_PREFIX,true);
  bh_save(bh_store_dir(),$id,$r);
  $c=$r['receipts'][$r['current'] ?? ''] ?? null;
  return ['ok'=>true,'csrf'=>bh_csrf($id,$config['secret']),'permission'=>bh_current($r),'withdrawalSynced'=>true,'recorded'=>$c ? ['timestamp'=>$c['recordedAt'],'page'=>$c['permission']['clientPage'],'choice'=>$c['choice'],'resetAt'=>$r['resetAt'] ?? null] : null];
 });
 setcookie(BH_COOKIE_NAME,$id,['expires'=>time()+180*86400,'path'=>PREVIEW_PREFIX,'secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
 echo json_encode($result,JSON_THROW_ON_ERROR);
} catch(Throwable $e) { http_response_code(503); echo '{"ok":false,"error":"preferences_unavailable"}'; }
