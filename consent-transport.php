<?php
declare(strict_types=1);
// Public deployment pin, not a credential. Parent must set to the exact ORIGINAL
// .bluehaven-crm-config.php endpoint after comparing in its authorized environment.
// Empty deliberately blocks delivery: public source copies contain no endpoint.
const BH_ORIGINAL_GAS_ENDPOINT = 'https://script.google.com/macros/s/AKfycbzSBTHmexiFAsc1aaNDg3Wol17_Zmn1BsPjMJeW7lVbazyJSp5Zi9kvrAJEAAh-SgiD/exec';
function bh_gas_endpoint(string $endpoint, string $pin): bool {
    return $pin !== '' && hash_equals($pin,$endpoint)
        && preg_match('~^https://script\.google\.com/macros/s/[A-Za-z0-9_-]+/exec$~D',$endpoint)===1;
}
function bh_gas_result_url(string $url): bool {
    $u=parse_url($url);
    if (!is_array($u) || ($u['scheme']??'')!=='https' || ($u['host']??'')!=='script.googleusercontent.com'
        || ($u['path']??'')!=='/macros/echo' || isset($u['user']) || isset($u['pass']) || isset($u['port']) || isset($u['fragment'])) return false;
    parse_str($u['query']??'', $q);
    return count($q)===2 && isset($q['user_content_key'],$q['lib'])
        && is_string($q['user_content_key']) && is_string($q['lib'])
        && preg_match('/^[A-Za-z0-9_-]+$/D',$q['user_content_key'])===1
        && preg_match('/^[A-Za-z0-9_-]+$/D',$q['lib'])===1;
}
function bh_gas_deliver(array $event, array $config, int $budget, callable $request, string $pin=BH_ORIGINAL_GAS_ENDPOINT): bool {
    $endpoint=$config['endpoint']??''; $token=$config['token']??'';
    if (!is_string($endpoint) || !bh_gas_endpoint($endpoint,$pin) || !is_string($token) || $token==='' || $budget<=0) return false;
    $deadline=microtime(true)+$budget/1000;
    $event['token']=$token;
    $r=$request($endpoint,'POST',json_encode($event,JSON_THROW_ON_ERROR),$budget);
    if (in_array($r['status'],[302,303],true)) {
        $url=$r['location']??''; $left=(int)(($deadline-microtime(true))*1000);
        if (!bh_gas_result_url($url) || $left<=0) return false;
        // One admitted content response only; no body, token, cookies, or Authorization.
        $r=$request($url,'GET',null,$left);
    }
    if ($r['status']<200 || $r['status']>=300) return false;
    $body=json_decode($r['body']??'',true);
    return is_array($body) && ($body['ok']??false)===true;
}
function bh_gas_http(string $url,string $method,?string $body,int $budget): array {
    if (!function_exists('curl_init')) return ['status'=>0];
    $ch=curl_init($url); $location=''; $response='';
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_CONNECTTIMEOUT_MS=>min(300,$budget),CURLOPT_TIMEOUT_MS=>$budget,
        CURLOPT_HTTPHEADER=>$method==='POST'?['Content-Type: application/json','Accept: application/json']:['Accept: application/json'],
        CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$location){if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);},
        CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$response){if(strlen($response)+strlen($chunk)>65536)return 0;$response.=$chunk;return strlen($chunk);}]);
    if ($method==='POST') curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    $ok=curl_exec($ch);$status=$ok===false?0:curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    return ['status'=>$status,'body'=>$response,'location'=>$location];
}
