/* Load synchronously before any Google initialization. No consent from browser cache. */
(function (w,d) {
  'use strict';
  if (w.blueHavenCookies) return;
  w.dataLayer=w.dataLayer || [];
  w.gtag=function(){ w.dataLayer.push(arguments); };
  const denied={ad_storage:'denied',analytics_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'};
  w.gtag('consent','default',denied);
  w.gtag('set','allow_ad_personalization_signals',false);
  w.gtag('set','ads_data_redaction',true);
  let permission='UNKNOWN', csrf='', loaded=false, box, status, busy=false, revision=0;
  let recorded=null, recordedNode, details;
  function showRecorded(){if(recordedNode) recordedNode.textContent=recorded ? 'Your preview record: '+recorded.choice+' | '+recorded.timestamp+' | '+recorded.page+(recorded.resetAt?' | reset to Unknown':'') : 'No choice recorded for this browser.';}
  const gpc=()=>w.navigator.globalPrivacyControl===true;
  function processing(accepted) {
    permission=accepted && !gpc() ? 'GRANTED' : 'UNKNOWN';
    w.gtag('consent','update',Object.assign({},denied,permission==='GRANTED'?{ad_storage:'granted',ad_user_data:'granted'}:{}));
  }
  async function activate() { /* Preview: no provider scripts exist or run. */ }
  async function request(choice) {
    const options={credentials:'same-origin',cache:'no-store'};
    if(choice) Object.assign(options,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({choice,csrf,page:w.location.pathname,gpc:gpc()})});
    const res=await w.fetch('/previews/sop225-cookie-20261026/cookie-consent.php',options);
    const body=await res.json(); if(!res.ok || !body.ok) throw Error('unavailable');
    csrf=body.csrf; recorded=body.recorded; showRecorded(); return body;
  }
  async function choose(choice) {
    if(busy) return; busy=true; revision++;
    // Denial takes effect locally before I/O, even if the network is unavailable.
    if(choice==='decline' || choice==='reset') processing(false);
    try {
      if(!csrf) await request();
      const result=await request(choice);
      processing(result.permission.adUserData==='GRANTED');
      if(result.withdrawalSynced===false) {
        status.textContent='Your withdrawal is saved and blocks future imports. The earlier-request audit update is still pending; you can retry Preferences.';
      } else { box.hidden=true; status.textContent=''; }
      if(permission==='GRANTED') await activate();
      // Unload already-running optional scripts after withdrawal. Re-entry stays denied.
      else if(loaded) w.location.reload();
    } catch (_) { status.textContent='Preferences could not be confirmed. You can still request an estimate. Please retry.'; }
    finally { busy=false; }
  }
  function reopen(){box.hidden=false; details.hidden=false; request().catch(()=>{});}
  w.blueHavenCookies={allowed:()=>permission==='GRANTED'&&!gpc(),reopen};
  function mount() {
    const style=d.createElement('style');
    style.textContent='#bh-cookie-banner{position:fixed;bottom:0;left:0;right:0;z-index:10000;background:#fff;color:#172d3a;border-top:3px solid #eabf38;padding:16px 24px;box-shadow:0 -2px 12px #0002;font:16px/1.45 Arial,sans-serif;max-height:45vh;overflow:auto}#bh-cookie-banner[hidden]{display:none}#bh-cookie-banner p{margin:0 0 10px}#bh-cookie-banner button,#bh-cookie-reopen{font:700 16px Arial,sans-serif;min-height:44px;padding:10px 18px;border:2px solid #173c52;border-radius:8px;background:white;color:#173c52;cursor:pointer;margin:4px}#bh-cookie-banner button:focus-visible,#bh-cookie-reopen:focus-visible{outline:3px solid #dba900;outline-offset:2px}#bh-cookie-reopen{position:fixed;bottom:8px;left:8px;z-index:9999}#bh-cookie-banner button[data-primary]{background:#173c52;color:#fff}#bh-cookie-banner a{color:#164966;text-decoration:underline}@media(max-width:480px){#bh-cookie-banner{padding:12px}#bh-cookie-banner button{padding:10px 12px}}';
    d.head.appendChild(style);
    box=d.createElement('section');box.id='bh-cookie-banner';box.setAttribute('aria-label','Cookie preferences');
    const copy=d.createElement('p');copy.textContent='Allow Google ad measurement cookies? With your permission, we share your Google ad click ID and whether your request becomes Qualified or Booked with Google to measure our ads. No name, email or phone is included in these offline reports. Personalized ads are off. Your estimate works either way.';box.appendChild(copy);
    [['Accept Cookies',()=>choose('accept')],['Decline',()=>choose('decline')],['Preferences',()=>{details.hidden=!details.hidden; request().catch(()=>{});}]].forEach(([label,fn])=>{
      const b=d.createElement('button');b.type='button';b.textContent=label;b.addEventListener('click',fn);if(label==='Accept Cookies') b.setAttribute('data-primary','');box.appendChild(b);
    });
    details=d.createElement('p');details.hidden=true;details.textContent='Accept allows Google Ads measurement only. Analytics in Google Tag Manager and Microsoft Clarity remain off. Decline withdraws permission for future offline imports from requests linked to this browser receipt. Previously accepted reports are not deleted. Global Privacy Control keeps measurement off.';box.appendChild(details);
    recordedNode=d.createElement('p'); recordedNode.setAttribute('role','status'); details.appendChild(recordedNode); showRecorded();
    [['Withdraw permission',()=>choose('decline')],['Reset preview',()=>choose('reset')]].forEach(([label,fn])=>{const b=d.createElement('button');b.type='button';b.textContent=label;b.addEventListener('click',fn);details.appendChild(b);});
    const previewNote=d.createElement('p'); previewNote.textContent='Preview only — test requests are not sent to Blue Haven or Google.'; box.prepend(previewNote);
    const privacy=d.createElement('a');privacy.href='/previews/sop225-cookie-20261026/privacy-policy.html#google-ad-measurement';privacy.textContent='Privacy policy';box.appendChild(privacy);
    status=d.createElement('p');status.setAttribute('role','status');box.appendChild(status);
    const reopenButton=d.createElement('button');reopenButton.type='button';reopenButton.id='bh-cookie-reopen';reopenButton.textContent='Cookie Preferences';reopenButton.addEventListener('click',reopen);d.body.appendChild(reopenButton);d.body.appendChild(box);
    // No modal/focus trap/focus movement. Add clearance so the form remains reachable.
    if(w.ResizeObserver) new w.ResizeObserver(()=>{d.body.style.paddingBottom=(box.hidden?64:box.getBoundingClientRect().height+16)+'px';}).observe(box);
    if(w.addEventListener) w.addEventListener('focus',()=>{if(gpc() && permission==='GRANTED') choose('decline');});
    d.querySelectorAll('form[data-contact-form],form[data-funnel-form]').forEach(form=>{
      if(form.querySelector('[data-bh-cookie-notice]')) return;
      const p=d.createElement('p');p.setAttribute('data-bh-cookie-notice','');
      p.textContent='If you accept Google ad measurement, we share your ad click ID and Qualified/Booked outcome with Google, without contact details or personalized ads. ';
      const a=d.createElement('button');a.type='button';a.textContent='Cookie Preferences';a.addEventListener('click',reopen);p.appendChild(a);form.appendChild(p);
    });
    const initialRevision=revision;
    request().then(async r=>{
      if(revision!==initialRevision) return;
      if(gpc() && r.permission.adUserData==='GRANTED') {await choose('decline');return;}
      processing(r.permission.adUserData==='GRANTED');
      box.hidden=r.permission.adUserData!=='UNKNOWN' && r.withdrawalSynced!==false;
      if(gpc()) status.textContent='Global Privacy Control is on. Google measurement remains off.';
      await activate();
    }).catch(()=>{status.textContent='Preferences are temporarily unavailable. Your estimate still works.';});
  }
  if(d.readyState==='loading') d.addEventListener('DOMContentLoaded',mount); else mount();
})(window,document);
