(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root && root.document) api.install(root, root.document);
}(typeof window !== 'undefined' ? window : null, function () {
  'use strict';
  function accepted(c) {
    return c.enabled === true && typeof c.pixelId === 'string' && /^[A-Za-z0-9_-]{8,200}$/.test(c.pixelId) &&
      !/placeholder|your|test|example/i.test(c.pixelId);
  }
  function install(win, doc) {
    if (win.blueHavenOpenAI) return win.blueHavenOpenAI;
    const seen = new Set();
    let initialized = false;
    function config() { return win.blueHavenOpenAIConfig || {}; }
    const consentKey = 'bh_openai_consent_v1';
    const duration = 30 * 24 * 60 * 60 * 1000;
    let choice = null, panel, status, preferences;
    // Legacy acceptance is intentionally ignored by the production consent gate.
    function validChoice() {
      return choice && typeof choice.accepted === 'boolean' && Number.isFinite(choice.expires) &&
        choice.expires > Date.now() && choice.expires <= Date.now() + duration;
    }
    function hasConsent() { return false; } // Disabled, undisclosed provider: no SDK permission.
    function refresh() {
      if (win.blueHavenRefreshAttribution) win.blueHavenRefreshAttribution();
      if (panel) panel.hidden = !!validChoice();
      if (status) status.textContent = hasConsent() ? 'OpenAI advertising measurement is on.' : 'OpenAI advertising measurement is off.';
    }
    function setConsent() { if (win.blueHavenConsent) win.blueHavenConsent.open(); }
    function mountConsent() {
      return; // The common controller owns the only consent UI.
      const section = doc.createElement('section');
      section.setAttribute('aria-label', 'OpenAI advertising preferences');
      section.style.cssText = 'position:relative;padding:12px 16px;margin:12px auto;max-width:960px;background:#f5f7fa;color:#172c3e;font:14px/1.5 system-ui;';
      preferences = doc.createElement('button'); preferences.type = 'button';
      preferences.textContent = 'Advertising preferences';
      preferences.setAttribute('aria-controls', 'bh-openai-consent');
      preferences.setAttribute('aria-expanded', String(!validChoice()));
      panel = doc.createElement('div'); panel.id = 'bh-openai-consent';
      const disclosure = doc.createElement('p');
      disclosure.textContent = 'Allow Blue Haven to measure estimate requests and phone-link clicks from ChatGPT ads with OpenAI? If you accept, OpenAI uses advertising-measurement cookies, ad click references, and securely hashed contact details detected on forms to match these actions to ads. Raw contact details are not sent by automatic matching. This is optional, does not affect your estimate request, and is separate from mailing consent. You can withdraw here at any time. ';
      const privacy = doc.createElement('a'); privacy.href = 'https://openai.com/policies/privacy-policy/';
      privacy.textContent = 'OpenAI privacy policy'; disclosure.appendChild(privacy); panel.appendChild(disclosure);
      status = doc.createElement('p'); status.setAttribute('role', 'status');
      section.appendChild(preferences); section.appendChild(status); section.appendChild(panel);
      [['Accept', true], ['Not now / Withdraw', false]].forEach(function (item) {
        const button = doc.createElement('button'); button.type = 'button'; button.textContent = item[0];
        button.style.cssText = 'margin:4px 8px 4px 0;padding:8px 12px;text-decoration:underline;';
        button.addEventListener('click', function () { setConsent(item[1]); preferences.setAttribute('aria-expanded', 'false'); });
        panel.appendChild(button);
      });
      preferences.addEventListener('click', function () { panel.hidden = !panel.hidden; preferences.setAttribute('aria-expanded', String(!panel.hidden)); });
      // Normal document flow keeps mobile sticky CTAs and chat unobstructed.
      doc.body.insertBefore(section, doc.body.firstChild);
      refresh();
    }
    function ready() {
      const c = config();
      if (!accepted(c) || !hasConsent() || (win.__BLUEHAVEN_QA__ || {}).enabled) {
        if (initialized && typeof win.oaiq === 'function') win.oaiq('consent', false);
        else if (!hasConsent()) {
          // Clear documented first-party cookies on expired/denied reloads without loading the SDK.
          ['__oppref', '__obref'].forEach(function (name) {
            doc.cookie = name + '=; Max-Age=0; Path=/; SameSite=Lax';
          });
        }
        return false;
      }
      if (!initialized) {
        // Refuse to share an unknown queue whose matching/consent settings are unknown.
        if (win.oaiq) return false;
        const q = function () { q.q.push(arguments); };
        q.q = [];
        win.oaiq = q;
        q('consent', false);
        q('init', { pixelId: c.pixelId });
        const script = doc.createElement('script');
        script.async = true;
        script.src = 'https://bzrcdn.openai.com/sdk/oaiq.min.js';
        doc.head.appendChild(script);
        initialized = true;
      }
      win.oaiq('consent', true);
      return true;
    }
    function lead(result) {
      if (!result || result.success !== true || result.measurement_kind !== 'estimate' ||
          result.qa_authorized === true || !/^BH-\d{8}-[A-F0-9]{8}$/.test(result.lead_id || '')) return false;
      const id = result.lead_id;
      if (seen.has(id)) return false;
      try { if (win.sessionStorage.getItem('bh_oai_lead_' + id)) return false; } catch (_) {}
      if (!ready()) return false;
      seen.add(id);
      try { win.sessionStorage.setItem('bh_oai_lead_' + id, '1'); } catch (_) {}
      win.oaiq('measure', 'lead_created', { type: 'customer_action' }, { event_id: id });
      return true;
    }
    doc.addEventListener('click', function (event) {
      const target = event.target && (event.target.closest ? event.target : event.target.parentElement);
      const link = target && target.closest('a[href]');
      if (!link || !/^tel:/i.test(link.getAttribute('href') || '')) return;
      if ((win.__BLUEHAVEN_QA__ || {}).enabled && config().qaDisableDialing === true) {
        event.preventDefault();
        return;
      }
      if (!ready() || !win.crypto || typeof win.crypto.randomUUID !== 'function') return;
      win.oaiq('measure', 'custom', { type: 'custom' }, {
        custom_event_name: 'phone_click', event_id: 'phone-' + win.crypto.randomUUID()
      });
    }, true);
    win.blueHavenOpenAI = { lead: lead, syncConsent: ready, hasConsent: hasConsent, setConsent: setConsent };
    if (win.addEventListener) win.addEventListener('storage', function (event) {
      if (event.key !== consentKey) return;
      try { choice = JSON.parse(event.newValue); } catch (_) { choice = null; }
      ready(); refresh();
    });
    if (win.setInterval) win.setInterval(function () { ready(); refresh(); }, 60000);
    mountConsent();
    doc.addEventListener('DOMContentLoaded', mountConsent);
    // Capture eligible landing attribution early, once approved consent/config exist.
    ready(); refresh();
    return win.blueHavenOpenAI;
  }
  return { install: install, accepted: accepted };
}));
