<?php
declare(strict_types=1);
require_once __DIR__.'/cookie-consent-lib.php'; preview_headers();
try {
 $p=preview_body(); $id=$_COOKIE[BH_COOKIE_NAME];
 $result=bh_transaction(bh_store_dir(),function() use($p,$id) {
  $r=bh_read(bh_store_dir(),$id); $permission=bh_current($r);
  if(($_SERVER['HTTP_SEC_GPC'] ?? '')==='1') $permission=bh_unknown();
  $presence=[]; foreach(['first_name','last_name','phone','email','message'] as $field) $presence[$field]=($p['presence'][$field] ?? false)===true;
  $qa=bh_id();
  $record=['qaReceipt'=>$qa,'recordedAt'=>bh_now(),'page'=>$p['page'],'fieldPresence'=>$presence,'previewPermission'=>$permission['adUserData'],'consentReceipt'=>$permission['receiptId'] ?? null,'browser'=>$id,'dummyGclid'=>'PREVIEW-QA-NOT-A-REAL-CLICK','transport'=>'isolated-no-delivery'];
  bh_save(bh_store_dir(),$qa,$record);
  return ['ok'=>true,'qaReceipt'=>$qa,'permission'=>$record['previewPermission'],'message'=>'Preview only - nothing was sent'];
 });
 echo json_encode($result,JSON_THROW_ON_ERROR);
} catch(Throwable $e) { http_response_code(503); echo '{"ok":false}'; }
