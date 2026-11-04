/* Blue Haven scoped consent gate. Must execute synchronously before other scripts. */
(function (w, d) {
  'use strict';
  const KEY = 'bh_consent_v3', POLICY = '2026-10-26', LIFE = 180 * 86400000;
  const names = ['analytics', 'advertising', 'functional'];
  const off = () => ({analytics:false, advertising:false, functional:false});
  let storage = true, record = null, state = off(), mounted = false, stopping = false;
  const loaded = new Set();
  const landing = new URL(w.location.href);
  const adKeys = ['gclid','gbraid','wbraid','oppref','fbclid','dclid','msclkid'];
  const disclosedAdKeys = ['gclid','gbraid','wbraid','dclid'];
  const signal = () => navigator.globalPrivacyControl === true;
  function valid(x) {
    return x && !Array.isArray(x) && ['advertising,analytics,expires,functional,policy,timestamp,version','advertising,analytics,expires,functional,page,policy,timestamp,version'].includes(Object.keys(x).sort().join(',')) &&
      x.version === 3 && x.policy === POLICY && names.every(k => typeof x[k] === 'boolean') &&
      Number.isFinite(x.timestamp) && Number.isFinite(x.expires) && x.timestamp <= Date.now() &&
      x.timestamp > 0 && x.expires > Date.now() && x.expires > x.timestamp && x.expires - x.timestamp <= LIFE;
  }
  function read() {
    let rawValue;
    try { rawValue = w.localStorage.getItem(KEY); } catch (_) { storage=false; return null; }
    try { const x=JSON.parse(rawValue); return valid(x)?x:null; } catch (_) { return null; }
  }
  function effective(x) {
    const s = off(); names.forEach(k => s[k] = !!(x && x[k]));
    if (signal()) s.analytics = s.advertising = false;
    return s;
  }
  function allowed(k) { return !stopping && state[k] === true && (!record || valid(record)) && !(signal() && k !== 'functional'); }
  function cleanUrl(value) { if(!value)return ''; try { const u = new URL(value, location.origin); return u.origin + u.pathname; } catch (_) { return ''; } }
  // Referrer and location must be safe even for providers which read them directly.
  const safeReferrer = cleanUrl(d.referrer);
  try { Object.defineProperty(d, 'referrer', {get: () => safeReferrer}); } catch (_) {}
  function safeLocation() {
    const u = new URL(w.location.href); u.search = '';
    if (!/^#[a-z0-9_-]+$/i.test(u.hash)) u.hash = '';
    // Retain only bounded campaign identifiers when advertising is permitted.
    if (allowed('advertising')) landing.searchParams.forEach((v,k) => {
      if ((disclosedAdKeys.includes(k) || /^utm_(source|medium|campaign|term|content)$/.test(k)) && /^[a-z0-9 _./-]{1,200}$/i.test(v)) u.searchParams.set(k,v);
    });
    if (u.href !== location.href) history.replaceState(history.state, '', u.href);
  }
  function clean(value, key) {
    if (typeof value === 'function') return value;
    if (typeof value === 'string' && /location|referrer|(?:^|_)url$/i.test(key || '')) return cleanUrl(value);
    if (!value || typeof value !== 'object' || value instanceof Date) return value;
    const out = Array.isArray(value) ? [] : {};
    Object.keys(value).forEach(k => {
      if (/email|phone_number|first_name|last_name|user_data|address|query|search/i.test(k)) return;
      if (adKeys.includes(k) && !allowed('advertising')) return;
      out[k] = clean(value[k], k);
    }); return out;
  }
  w.dataLayer = w.dataLayer || [];
  // Accessor keeps the gate outside GTM's own push wrapper without recursion.
  function wrapPush(downstream) {
    return function () {
      const accepted=[];
      Array.from(arguments).forEach(item => {
        if(item && item.event && !/^gtm\./.test(item.event)) {
          if(!allowed(item.event==='conversion'?'advertising':'analytics')) {callback(item);return;}
          item=clean(item);
        }
        accepted.push(item);
      });
      return accepted.length ? downstream.apply(this,accepted) : this.length;
    };
  }
  let guardedPush=wrapPush(w.dataLayer.push);
  Object.defineProperty(w.dataLayer,'push',{configurable:false,get:()=>guardedPush,set:fn=>{guardedPush=wrapPush(fn);}});
  function raw() { w.dataLayer.push(arguments); }
  function callback(p) { if (p && typeof p.event_callback === 'function') setTimeout(p.event_callback, 0); if(p && typeof p.eventCallback === 'function') setTimeout(p.eventCallback,0); }
  function gtag(command, target, params) {
    if (command === 'event') {
      const cat = target === 'conversion' ? 'advertising' : 'analytics';
      if (!allowed(cat)) { callback(params); return; }
      const p = clean(params || {});
      p.send_to = cat === 'analytics' ? 'G-WTEM34PS2J' : (p.send_to || 'AW-18254638708');
      raw(command, target, p); return;
    }
    if (command === 'config') {
      if (!allowed(/^AW-/.test(target) ? 'advertising' : 'analytics')) return;
      raw(command, target, Object.assign({}, params || {}, {page_location:cleanUrl(location.href), page_referrer:safeReferrer, allow_ad_personalization_signals:allowed('advertising')})); return;
    }
    if (command === 'js' && !names.some(allowed)) return;
    // Consent control belongs exclusively to this gate.
    if (command !== 'consent') raw(command, target, params);
  }
  Object.defineProperty(w, 'gtag', {configurable:false, get:() => gtag, set:() => {}});
  w.clarity = w.clarity || function () { (w.clarity.q = w.clarity.q || []).push(arguments); };
  function consent(mode, s) {
    const yes = k => s[k] ? 'granted' : 'denied';
    raw('consent',mode,{ad_storage:yes('advertising'),analytics_storage:yes('analytics'),ad_user_data:yes('advertising'),ad_personalization:yes('advertising'),functionality_storage:yes('functional'),personalization_storage:yes('functional'),security_storage:'granted'});
    raw('set',{allow_ad_personalization_signals:s.advertising,ads_data_redaction:!s.advertising,url_passthrough:false,page_location:cleanUrl(location.href),page_referrer:safeReferrer});
    w.clarity('consentv2',{ad_Storage:yes('advertising'),analytics_Storage:yes('analytics')});
  }
  function track(payload) {
    if (!allowed('analytics')) { callback(payload); return; }
    w.dataLayer.push(clean(payload));
  }
  function clearScoped() {
    const cookiePattern = /^(?:_ga(?:_|$)|_gid$|_gat(?:_|$)|_gcl_|_gac_|_clck$|_clsk$|__oppref$|__obref$)/;
    const paths = ['/']; let path = '';
    location.pathname.split('/').filter(Boolean).forEach(part => {path += '/' + part; paths.push(path, path + '/');});
    d.cookie.split(';').forEach(item => {
      const name = item.split('=')[0].trim(); if (!cookiePattern.test(name)) return;
      if (state.analytics && /^(?:_ga(?:_|$)|_gid$|_gat(?:_|$)|_clck$|_clsk$)/.test(name)) return;
      if (state.advertising && /^(?:_gcl_|_gac_|__oppref|__obref)/.test(name)) return;
      paths.forEach(p => ['',location.hostname,'.'+location.hostname,'.bluehavenview.com'].forEach(domain => {
        d.cookie = name+'=; Max-Age=0; Path='+p+(domain?'; Domain='+domain:'')+'; SameSite=Lax';
      }));
    });
    ['localStorage','sessionStorage'].forEach(kind => { try {
      const st=w[kind]; Object.keys(st).forEach(k => {
        const analytic=/^(?:_clck$|_clsk$|_cltk$|_ga(?:_|$)|_gid$)/.test(k);
        const ad=/^(?:bluehaven_attribution$|bh_oai_lead_|bh_openai_consent_v1$|_gcl_|gclid$|gbraid$|wbraid$|oppref$)/.test(k);
        if ((!state.analytics && analytic) || (!state.advertising && ad)) st.removeItem(k);
      });
    } catch (_) {} });
    d.querySelectorAll('input[type="hidden"]').forEach(el => {if(!state.advertising && adKeys.includes(el.name)) el.value='';});
  }
  // Defense in depth for provider-generated requests and teardown beacons.
  function networkAllowed(value) {
    let u; try { u = new URL(String(value), location.href); } catch (_) { return true; }
    const h = u.hostname, p = u.pathname;
    if (/(^|\.)clarity\.ms$/.test(h)) return allowed('analytics') && (!/^c\./.test(h) || allowed('advertising'));
    if (/(^|\.)bing\.com$/.test(h)) return allowed('analytics') && allowed('advertising');
    if (/(^|\.)google-analytics\.com$/.test(h)) return allowed('analytics');
    if (/(^|\.)google\.com$/.test(h) && /^\/g\/collect/.test(p)) return allowed('analytics');
    if (/(^|\.)googletagmanager\.com$/.test(h)) return allowed((u.searchParams.get('id') || '').startsWith('AW-') ? 'advertising' : 'analytics');
    if (/doubleclick\.net$|googleadservices\.com$/.test(h) || (/google\.[a-z.]+$/.test(h) && /pagead|ccm|ads|wcm|rmkt/.test(p))) return allowed('advertising');
    if (/(^|\.)bastiontech\.org$/.test(h)) return allowed('functional');
    if (/openai\.com$/.test(h)) return false;
    return true;
  }
  const fetchOriginal = w.fetch;
  if (fetchOriginal) w.fetch = function(input, init) {if(!networkAllowed(input && input.url || input)) return Promise.reject(new TypeError('Optional service disabled')); return fetchOriginal.call(this,input,init);};
  const beaconOriginal = navigator.sendBeacon;
  if(beaconOriginal) navigator.sendBeacon = function(url,data) {return networkAllowed(url) ? beaconOriginal.call(this,url,data) : false;};
  const xhrOpen = XMLHttpRequest.prototype.open, xhrSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function(method,url) {this.__bhUrl=url;this.__bhBlocked=!networkAllowed(url); return xhrOpen.apply(this,arguments);};
  XMLHttpRequest.prototype.send = function() {if(this.__bhBlocked || !networkAllowed(this.__bhUrl)) {this.abort();return;} return xhrSend.apply(this,arguments);};
  [HTMLImageElement,HTMLScriptElement,HTMLIFrameElement].forEach(cls => {
    const descriptor=Object.getOwnPropertyDescriptor(cls.prototype,'src');
    if(descriptor && descriptor.set) Object.defineProperty(cls.prototype,'src',Object.assign({},descriptor,{set:function(v){if(networkAllowed(v)) descriptor.set.call(this,v);}}));
  });
  if (w.Element) {
    const setAttributeOriginal=w.Element.prototype.setAttribute;
    w.Element.prototype.setAttribute=function(name,value) {
      if(String(name).toLowerCase()==='src' && /^(SCRIPT|IMG|IFRAME)$/.test(this.tagName) && !networkAllowed(value)) return;
      return setAttributeOriginal.call(this,name,value);
    };
  }
  function activate() {
    if(!mounted || stopping) return;
    safeLocation();
    d.querySelectorAll('script[data-bh-category]').forEach(el => {
      if (!allowed(el.dataset.bhCategory) || loaded.has(el)) return;
      loaded.add(el);
      const script=d.createElement('script');
      Array.from(el.attributes).forEach(a => {if(!['type','data-bh-category','data-bh-src','src'].includes(a.name)) script.setAttribute(a.name,a.value);});
      if(el.dataset.bhSrc) {script.async=false; script.src=el.dataset.bhSrc;}
      else {
        // Keep the original body intact on disk; confine its legacy function declaration.
        // Route its local gtag through the common gate, so no declaration can replace it.
        script.textContent='(function(){\n'+el.textContent.replace(/function gtag\(\)\{dataLayer\.push\(arguments\);\}/g,'function gtag(){window.gtag.apply(window,arguments);}')+'\n}).call(window);';
      }
      el.after(script);
    });
  }
  function apply(next, save) {
    const before=state; record=next; state=effective(record);
    if(save) {try {w.localStorage.setItem(KEY,JSON.stringify(record));} catch (_) {storage=false;}}
    if(save) receipt('choice');
    else if(mounted) receipt('observe');
    const reduction=names.some(k=>before[k]&&!state[k]);
    consent('update',state);
    if (reduction) {
      stopping=true; clearScoped(); safeLocation();
      // Synchronous navigation unloads timers, listeners, widgets and in-flight providers.
      receiptPending.finally(()=>w.location.reload()); return;
    }
    if(!state.advertising || !state.analytics) clearScoped();
    if(w.blueHavenRefreshAttribution) w.blueHavenRefreshAttribution();
    activate(); paint();
  }
  // Receipt responses never grant browser categories. Browser cache is observation, not server proof.
  const RECEIPT_BUDGET_MS = 600;
  let receiptPending = Promise.resolve(false), receiptFlight = null;
  function receipt(action) {
    const input = {action:action || 'observe', choice:record, page:cleanUrl(location.href), gpc:signal()};
    const key = JSON.stringify(input), signature = JSON.stringify([input.choice,input.page,input.gpc]);
    // Repeated observations share a single TOTAL deadline; there is no queue.
    if (receiptFlight && (action !== 'choice') && receiptFlight.signature === signature) return receiptPending;
    // Changed choice/privacy supersedes the old observation immediately.
    if (receiptFlight) receiptFlight.cancel();
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    let finish, timer;
    const flight = {key, signature, cancel:()=>{ if(controller) controller.abort(); finish(false); }};
    receiptFlight = flight;
    receiptPending = new Promise(resolve => {
      let settled = false;
      finish = value => {
        if (settled) return; settled = true;
        if (timer !== undefined) clearTimeout(timer);
        if (controller && receiptFlight === flight) receiptFlight = null;
        resolve(value);
      };
      timer = setTimeout(()=>flight.cancel(), RECEIPT_BUDGET_MS);
      Promise.resolve().then(()=>w.fetch('/consent-receipt.php', {method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'}, body:key, keepalive:true, signal:controller ? controller.signal : undefined}))
        .then(response=>response.json().then(result=>response.ok && result.ok === true))
        .then(value=>{if(receiptFlight===flight)receiptFlight=null;finish(value);},()=>{if(receiptFlight===flight)receiptFlight=null;finish(false);});
    });
    return receiptPending;
  }
  function save(choice) {
    const now=Date.now(); const s=effective(choice);
    apply(Object.assign({version:3,policy:POLICY,timestamp:now,expires:now+LIFE,page:cleanUrl(location.href)},s),true);
  }
  function sync() {const next=storage?read():record; apply(valid(next)?next:null,false);}
  w.blueHavenConsent = Object.freeze({receipt:()=>receipt('observe'),allowed,track,cleanUrl,networkAllowed,open:open, getState:()=>Object.assign({},state),
    inquiryUrl:() => {const u=new URL(location.origin+location.pathname); landing.searchParams.forEach((v,k)=>{if(/^utm_(source|medium|campaign|term|content)$/.test(k)&&/^[a-z0-9 _./-]{1,200}$/i.test(v))u.searchParams.set(k,v);});return u.href;},
    attribution:() => {const out={};landing.searchParams.forEach((v,k)=>{if((/^utm_(source|medium|campaign|term|content)$/.test(k)||allowed('advertising')&&disclosedAdKeys.includes(k))&& /^[a-z0-9 _./-]{1,200}$/i.test(v))out[k]=v;});return out;}});
  consent('default',off());
  record=read();
  try { const probe=KEY+'_probe'; w.localStorage.setItem(probe,'1');w.localStorage.removeItem(probe); } catch (_) {storage=false;record=null;}
  state=effective(record); receipt('observe'); safeLocation();
  if(!state.advertising || !state.analytics) clearScoped();
  consent('update',state);
  w.addEventListener('storage',e=>{if(e.key===KEY||e.key===null)sync();});
  w.addEventListener('focus',sync);
  d.addEventListener('visibilitychange',()=>{if(!d.hidden)sync();});
  setInterval(()=>{if(record&&!valid(record)||signal()&&(state.analytics||state.advertising))sync();},1000);
  function $(id) {return d.getElementById('bhc-'+id);}
  function paint() {
    if(!mounted) return;
    $('notice').hidden=!!record; $('launch').hidden=!record; $('settings').hidden=true;
    d.querySelectorAll('.bhc-gpc').forEach(el=>el.hidden=!signal());
    d.querySelectorAll('.bhc-storage').forEach(el=>el.hidden=storage);
  }
  function open() {
    if(!mounted) return;
    names.forEach(k=>{$(k).checked=allowed(k);$(k).disabled=signal()&&k!=='functional';});
    $('notice').hidden=$('launch').hidden=true; $('settings').hidden=false;
    d.querySelectorAll('.bhc-gpc').forEach(el=>el.hidden=!signal());
    // Nonmodal region: opening never moves or traps focus.
  }
  function close() {paint();(record?$('reopen'):$('customize')).focus();}
  function clearance() {
    if(!mounted) return;
    let bottom=20;
    d.querySelectorAll('body > div, body > nav, body > aside, #mobile-sticky-cta').forEach(el=>{
      if(el.id==='bhc-root')return;
      const r=el.getBoundingClientRect(),s=getComputedStyle(el);
      if(s.position==='fixed'&&s.display!=='none'&&r.width>innerWidth*.65&&r.height>0&&r.bottom>=innerHeight-2&&r.height<innerHeight*.4)bottom=Math.max(bottom,Math.ceil(r.height+12));
    });
    $('root').style.setProperty('--bhc-bottom',bottom+'px');
  }
  function mount() {
    if(mounted)return;
    const holder=d.createElement('div'); holder.innerHTML="<div id=\"bhc-root\">\n<section id=\"bhc-notice\" class=\"bhc-card\" role=\"region\" aria-labelledby=\"bhc-notice-title\"><div class=\"bhc-inner\">\n<h2 id=\"bhc-notice-title\" class=\"bhc-title\">Cookies &amp; privacy</h2>\n<p class=\"bhc-copy\">With your permission, we and our partners use optional cookies and related technologies for analytics, ad measurement, personalized ads and extra features.</p>\n\n<p class=\"bhc-hint bhc-gpc\" hidden>Your browser\u2019s privacy signal keeps analytics and advertising off.</p>\n<div class=\"bhc-actions bhc-notice-actions\"><button type=\"button\" id=\"bhc-accept-all\" class=\"bhc-btn bhc-primary\">Accept All</button><button type=\"button\" id=\"bhc-customize\" class=\"bhc-btn bhc-secondary\">Customize Settings</button></div>\n<p class=\"bhc-hint\"><a href=\"/privacy-policy.html#cookies\" target=\"_blank\" rel=\"noopener noreferrer\">Privacy Policy</a></p>\n\n</div></section>\n<section id=\"bhc-settings\" class=\"bhc-card\" role=\"dialog\" aria-modal=\"false\" aria-labelledby=\"bhc-settings-title\" hidden>\n<div class=\"bhc-panel-head\"><div class=\"bhc-headrow\"><h2 id=\"bhc-settings-title\" class=\"bhc-title\" tabindex=\"-1\">Customize Settings</h2><button type=\"button\" id=\"bhc-close\" class=\"bhc-close\" aria-label=\"Close settings without saving\">\u00d7</button></div><p class=\"bhc-copy\">Choose optional features. Essential services stay on, and you can request an estimate without enabling the others.</p></div>\n<div class=\"bhc-panel-body\"><p class=\"bhc-hint bhc-gpc\" hidden>Your browser\u2019s privacy signal keeps analytics and advertising off.</p>\n<div class=\"bhc-category\"><div class=\"bhc-row\"><button type=\"button\" class=\"bhc-expand\" aria-expanded=\"false\" aria-controls=\"bhc-essential-desc\"><span class=\"bhc-plus\" aria-hidden=\"true\">+</span><span>Essential website services</span></button><span class=\"bhc-always\">Always Active</span></div><p id=\"bhc-essential-desc\" class=\"bhc-desc\" hidden>Supports core site operation, security, estimate requests and remembering your privacy choices. You can still call or request an estimate when optional categories are off.</p></div>\n<div class=\"bhc-category\"><div class=\"bhc-row\"><button type=\"button\" class=\"bhc-expand\" aria-expanded=\"false\" aria-controls=\"bhc-analytics-desc\"><span class=\"bhc-plus\" aria-hidden=\"true\">+</span><span>Analytics &amp; site improvement</span></button><input class=\"bhc-switch\" role=\"switch\" type=\"checkbox\" id=\"bhc-analytics\" aria-label=\"Analytics and site improvement\" aria-describedby=\"bhc-analytics-desc\"/></div><p id=\"bhc-analytics-desc\" class=\"bhc-desc\" hidden>Helps us understand visits and how people use the site. Google Analytics 4 and Microsoft Clarity, including clicks, scrolling, heatmaps and session replay.</p></div>\n<div class=\"bhc-category\"><div class=\"bhc-row\"><button type=\"button\" class=\"bhc-expand\" aria-expanded=\"false\" aria-controls=\"bhc-advertising-desc\"><span class=\"bhc-plus\" aria-hidden=\"true\">+</span><span>Advertising measurement &amp; targeting</span></button><input class=\"bhc-switch\" role=\"switch\" type=\"checkbox\" id=\"bhc-advertising\" aria-label=\"Advertising measurement and targeting\" aria-describedby=\"bhc-advertising-desc\"/></div><p id=\"bhc-advertising-desc\" class=\"bhc-desc\" hidden>Helps measure advertising results and support relevant ads. Google Ads cookies, advertising click identifiers, measurement, personalized ads and ad-related call attribution.</p></div>\n<div class=\"bhc-category\"><div class=\"bhc-row\"><button type=\"button\" class=\"bhc-expand\" aria-expanded=\"false\" aria-controls=\"bhc-functional-desc\"><span class=\"bhc-plus\" aria-hidden=\"true\">+</span><span>Optional chat &amp; convenience</span></button><input class=\"bhc-switch\" role=\"switch\" type=\"checkbox\" id=\"bhc-functional\" aria-label=\"Optional chat and convenience\" aria-describedby=\"bhc-functional-desc\"/></div><p id=\"bhc-functional-desc\" class=\"bhc-desc\" hidden>Controls the BastionTech third-party contact widget, including data you provide within the widget. The main phone links and estimate form remain available when this is off.</p></div>\n</div><div class=\"bhc-panel-foot\"><div class=\"bhc-actions\"><button type=\"button\" id=\"bhc-decline\" class=\"bhc-btn bhc-secondary\">Reject All</button><button type=\"button\" id=\"bhc-confirm\" class=\"bhc-btn bhc-primary\">Confirm My Choices</button></div></div>\n</section>\n<div id=\"bhc-launch\" class=\"bhc-launch\" hidden><button type=\"button\" id=\"bhc-reopen\" class=\"bhc-linkbtn\">Cookie settings</button></div>\n<p class=\"bhc-hint bhc-storage\" hidden>Browser storage is unavailable. Your choices apply only to this page session and cannot be remembered.</p>\n<span id=\"bhc-status\" class=\"bhc-sr\" role=\"status\" aria-live=\"polite\"></span>\n</div>\n";
    d.body.appendChild(holder.firstElementChild); mounted=true;
    $('accept-all').onclick=()=>save({analytics:true,advertising:true,functional:true});
    $('customize').onclick=$('reopen').onclick=open; $('close').onclick=close;
    $('decline').onclick=()=>save(off());
    $('confirm').onclick=()=>save(Object.fromEntries(names.map(k=>[k,$(k).checked])));
    $('root').addEventListener('keydown',e=>{if(e.key==='Escape'&&!$('settings').hidden){e.preventDefault();close();}});
    d.querySelectorAll('#bhc-root .bhc-expand').forEach(b=>b.onclick=()=>{const on=b.getAttribute('aria-expanded')!=='true';b.setAttribute('aria-expanded',String(on));d.getElementById(b.getAttribute('aria-controls')).hidden=!on;b.querySelector('.bhc-plus').textContent=on?'−':'+';});
    paint();clearance();activate();
    w.addEventListener('resize',clearance);w.addEventListener('load',clearance);
    if(w.ResizeObserver) new ResizeObserver(clearance).observe(d.body);
    setTimeout(clearance,500);
  }
  if(d.readyState==='loading') d.addEventListener('DOMContentLoaded',mount); else mount();
})(window, document);
