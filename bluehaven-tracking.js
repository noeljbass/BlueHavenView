(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root && root.document) api.install(root, root.document);
}(typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  const INTERACTIVE_SELECTOR = 'a,button,summary,[role="button"],input[type="submit"],input[type="reset"],input[type="button"]';
  const SECTION_SELECTOR = '[data-tracking-section],section,article,aside,nav,header,footer,main,form,details';
  const SECTION_TAGS = new Set(['section', 'article', 'aside', 'nav', 'header', 'footer', 'main', 'form', 'details']);
  const SENSITIVE_ATTRIBUTES = new Set(['data-email', 'data-phone', 'data-name', 'value']);
  const ATTRIBUTION_KEYS = ['oppref', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
  function parseAttribution(search) {
    const params = new URLSearchParams(search || '');
    return ATTRIBUTION_KEYS.reduce(function (result, key) {
      const value = params.get(key);
      if (key === 'oppref') {
        if (value && /^[A-Za-z0-9_-]{1,200}$/.test(value)) result[key] = value;
      } else if (value) result[key] = String(value).trim().slice(0, 200);
      return result;
    }, {});
  }

  function isThankYouPath(pathname) {
    return /(?:^|\/)thank-you(?:[-/]|$)/i.test(String(pathname || ''));
  }

  function cleanText(value, limit) {
    return String(value || '').replace(/\s+/g, ' ').trim().slice(0, limit || 120);
  }

  function safeAttribute(element, name) {
    if (!element || SENSITIVE_ATTRIBUTES.has(name)) return '';
    return cleanText(element.getAttribute && element.getAttribute(name), 200);
  }

  function normalizePagePath(currentLocation) {
    try {
      const base = new URL(currentLocation || 'https://bluehavenview.com/');
      const path = base.pathname || '/';
      return path === '/' ? '/' : path.replace(/\/+$/, '') || '/';
    } catch (_) {
      return '/';
    }
  }

  function browserLocationForPagePath(pagePath, browserLocation) {
    if (browserLocation) return browserLocation;
    const normalized = normalizePagePath(pagePath || '/');
    if (normalized === '/') return 'https://bluehavenview.com/';
    return 'https://bluehavenview.com' + normalized;
  }

  function normalizePathString(path) {
    const value = String(path || '/');
    if (value === '/') return '/';
    return value.replace(/\/+$/, '') || '/';
  }

  function sanitizePageLocation(currentLocation) {
    try {
      const url = new URL(currentLocation || 'https://bluehavenview.com/');
      return url.origin + (url.pathname || '/');
    } catch (_) {
      return 'https://bluehavenview.com/';
    }
  }

  function sanitizeFragment(hash) {
    return String(hash || '').replace(/^#/, '').split('?')[0].replace(/[^a-z0-9_-]+/gi, '-').replace(/^-+|-+$/g, '').toLowerCase();
  }

  function slugify(value, fallback) {
    const cleaned = String(value || '')
      .toLowerCase()
      .replace(/&/g, ' and ')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .replace(/-{2,}/g, '-');
    return cleaned || (fallback || 'unknown');
  }

  const CUSTOM_PARAMETER_LIMIT = 100;

  function stableHash(value) {
    const text = String(value || '');
    let hash = 5381;
    for (let index = 0; index < text.length; index += 1) {
      hash = ((hash << 5) + hash) ^ text.charCodeAt(index);
    }
    return (hash >>> 0).toString(36);
  }

  function compactValue(value, limit) {
    const text = String(value || '');
    const maximum = limit || CUSTOM_PARAMETER_LIMIT;
    if (text.length <= maximum) return text;
    const suffix = '-' + stableHash(text);
    return text.slice(0, Math.max(1, maximum - suffix.length)) + suffix;
  }

  function isPdfPath(pathname) {
    return /\.pdf$/i.test(String(pathname || ''));
  }

  function elementTag(element) {
    return String(element && element.tagName || '').toLowerCase();
  }

  function elementType(element) {
    const tagName = elementTag(element);
    if (tagName === 'input') {
      return String(element.type || safeAttribute(element, 'type') || 'input').toLowerCase();
    }
    return tagName || 'unknown';
  }

  function opensNewWindow(element) {
    return safeAttribute(element, 'target') === '_blank';
  }

  function sectionSeed(element) {
    if (!element) return 'page';
    if (safeAttribute(element, 'data-tracking-section')) return slugify(safeAttribute(element, 'data-tracking-section'));
    if (safeAttribute(element, 'id')) return slugify(safeAttribute(element, 'id'));
    if (safeAttribute(element, 'name')) return slugify(safeAttribute(element, 'name'));
    if (safeAttribute(element, 'data-contact-form')) return slugify(safeAttribute(element, 'data-contact-form'));
    return SECTION_TAGS.has(elementTag(element)) ? slugify(elementTag(element)) : 'page';
  }

  function resolveSection(element) {
    if (!element) return 'page';
    const direct = safeAttribute(element, 'data-tracking-section');
    if (direct) return compactValue(slugify(direct));
    const seeds = [];
    let current = element.parentElement || null;
    while (current && seeds.length < 3) {
      const seed = sectionSeed(current);
      if (seed && seed !== 'page' && seeds.indexOf(seed) === -1) seeds.push(seed);
      current = current.parentElement || null;
    }
    if (!seeds.length) {
      const owner = element.closest ? element.closest(SECTION_SELECTOR) : null;
      if (!owner) return 'page';
      seeds.push(sectionSeed(owner));
    }
    return compactValue(seeds.join('/'));
  }

  function controlSeed(element) {
    if (!element) return '';
    if (safeAttribute(element, 'id')) return slugify(safeAttribute(element, 'id'));
    if (safeAttribute(element, 'name')) return slugify(safeAttribute(element, 'name'));
    return slugify(elementType(element), 'control');
  }

  function destinationParts(element, currentLocation) {
    const href = cleanText(element && (element.href || safeAttribute(element, 'href')), 1000);
    const pagePath = normalizePagePath(currentLocation);
    if (!href) {
      return {
        pagePath: pagePath,
        destination: pagePath,
        category: 'action',
        fileName: '',
        fileExtension: '',
        fileHost: '',
        isExternal: false
      };
    }
    if (/^tel:/i.test(href)) {
      return {
        pagePath: pagePath,
        destination: 'phone',
        category: 'phone',
        fileName: '',
        fileExtension: '',
        fileHost: '',
        isExternal: false
      };
    }
    if (/^mailto:/i.test(href)) {
      return {
        pagePath: pagePath,
        destination: 'email',
        category: 'email',
        fileName: '',
        fileExtension: '',
        fileHost: '',
        isExternal: false
      };
    }

    try {
      const baseUrl = new URL(browserLocationForPagePath(normalizePagePath(currentLocation), currentLocation));
      const target = new URL(href, baseUrl.href);
      const internal = target.origin === baseUrl.origin;
      const targetPath = target.pathname || '/';
      const targetHash = internal && target.hash ? '#' + sanitizeFragment(target.hash) : '';
      const destination = internal
        ? (targetPath || '/') + targetHash
        : target.hostname + targetPath;
      const pdf = isPdfPath(targetPath);
      const fileName = pdf ? targetPath.split('/').filter(Boolean).pop() || '' : '';
      return {
        pagePath: pagePath,
        destination: compactValue(destination),
        category: pdf ? 'pdf' : (internal ? 'navigation' : 'outbound'),
        fileName: compactValue(fileName),
        fileExtension: pdf ? 'pdf' : '',
        fileHost: pdf ? compactValue(target.hostname) : '',
        isExternal: pdf ? !internal : !internal
      };
    } catch (_) {
      return {
        pagePath: pagePath,
        destination: pagePath,
        category: 'action',
        fileName: '',
        fileExtension: '',
        fileHost: '',
        isExternal: false
      };
    }
  }

  function controlText(element) {
    const tagName = elementTag(element);
    if (tagName === 'input') {
      return cleanText(safeAttribute(element, 'value') || safeAttribute(element, 'aria-label') || safeAttribute(element, 'title') || safeAttribute(element, 'name'), CUSTOM_PARAMETER_LIMIT);
    }
    return cleanText(element && (element.textContent || safeAttribute(element, 'aria-label') || safeAttribute(element, 'title') || safeAttribute(element, 'name')), CUSTOM_PARAMETER_LIMIT);
  }

  function controlTitle(element) {
    return cleanText(safeAttribute(element, 'title') || safeAttribute(element, 'aria-label'), CUSTOM_PARAMETER_LIMIT);
  }

  function buildButtonId(element, currentLocation, parts, section) {
    const explicit = safeAttribute(element, 'data-tracking-key');
    if (explicit) return compactValue(slugify(explicit));
    const control = controlSeed(element);
    return compactValue(slugify([
      parts.pagePath,
      section,
      control,
      parts.category,
      parts.destination
    ].join('::')));
  }

  function buildUiClickPayload(element, currentLocation) {
    if (!element) return null;
    const tagName = elementTag(element);
    const parts = destinationParts(element, currentLocation);
    const section = resolveSection(element);
    const labelText = controlText(element);
    if (parts.category === 'navigation') {
      const haystack = (labelText + ' ' + parts.destination).toLowerCase();
      if (/estimate|quote|consultation|page-contact|contact/.test(haystack)) {
        parts.category = 'estimate';
      }
    }
    const payload = {
      event: 'ui_click',
      button_id: buildButtonId(element, currentLocation, parts, section),
      button_text: labelText || tagName || 'control',
      button_title: controlTitle(element),
      button_category: parts.category,
      button_destination: parts.destination,
      button_section: section,
      element_type: elementType(element),
      opens_new_window: opensNewWindow(element)
    };
    if (parts.category === 'pdf') {
      payload.file_name = parts.fileName;
      payload.file_extension = parts.fileExtension;
      payload.file_host = parts.fileHost;
      payload.is_external = parts.isExternal;
    }
    return payload;
  }

  function classifyInteraction(target, currentLocation) {
    if (!target) return null;
    const element = target.closest ? target.closest(INTERACTIVE_SELECTOR) : target;
    if (!element) return null;
    return buildUiClickPayload(element, currentLocation);
  }

  function buildClickEvents(target, currentLocation) {
    const base = classifyInteraction(target, currentLocation);
    if (!base) return [];
    const element = target && target.closest ? target.closest(INTERACTIVE_SELECTOR) : target;
    const faqCta = element && element.closest && element.closest('[data-faq-cta]');
    const faqBlock = faqCta && faqCta.closest ? faqCta.closest('[data-faq-block]') : null;
    if (base.button_category === 'phone') {
      return [base, Object.assign({}, base, {event: 'call_click'})];
    }
    if (base.button_category === 'email') {
      return [base, Object.assign({}, base, {event: 'email_click'})];
    }
    if (faqCta && faqBlock) {
      return [base, Object.assign({}, base, {
        event: 'faq_cta_click',
        faq_block_id: compactValue(slugify(safeAttribute(faqBlock, 'id') || safeAttribute(faqBlock, 'data-faq-block') || 'faq'))
      })];
    }
    return [base];
  }

  function isMeaningfulControl(element) {
    if (!element) return false;
    const tagName = elementTag(element);
    if (tagName === 'a') return !!cleanText(element.href || safeAttribute(element, 'href'), 1000);
    if (tagName === 'button' || tagName === 'summary') return true;
    if (safeAttribute(element, 'role').toLowerCase() === 'button') return true;
    if (tagName === 'input') {
      const type = String(element.type || safeAttribute(element, 'type')).toLowerCase();
      return type === 'submit' || type === 'reset' || type === 'button';
    }
    return false;
  }

  function ariaHidden(element) {
    return safeAttribute(element, 'aria-hidden').toLowerCase() === 'true';
  }

  function buildInteractionInventory(options) {
    const pagePath = normalizePagePath(options && options.pagePath || '/');
    const browserLocation = browserLocationForPagePath(pagePath, options && options.browserLocation);
    const controls = (options && options.controls || []).filter(isMeaningfulControl);
    const seen = new Map();
    const inventory = [];
    for (let index = 0; index < controls.length; index += 1) {
      const control = controls[index];
      const runtimeHidden = !!safeAttribute(control, 'hidden') || /\bhidden\b/.test(cleanText(control.className, 500)) || /display\s*:\s*none/i.test(safeAttribute(control, 'style')) || /opacity-0/.test(cleanText(control.className, 500)) || /pointer-events-none/.test(cleanText(control.className, 500));
      const clone = ariaHidden(control);
      if (clone) continue;
      const payload = buildUiClickPayload(control, browserLocation);
      if (!payload) continue;
      const existing = seen.get(payload.button_id);
      if (existing) {
        throw new Error('button_id collision for ' + payload.button_id);
      }
      seen.set(payload.button_id, true);
      inventory.push(Object.assign({}, payload, {
        page_path: pagePath,
        is_runtime_hidden: runtimeHidden,
        is_aria_hidden_clone: false
      }));
    }
    return {page_path: pagePath, controls: inventory};
  }

  function pushEvent(root, name, parameters) {
    if (!root.blueHavenConsent || !root.blueHavenConsent.allowed("analytics")) return null;
    root.dataLayer = root.dataLayer || [];
    const payload = Object.assign({}, parameters || {}, {
      event: name,
      page_location: sanitizePageLocation(root.location && root.location.href),
      page_path: root.location && root.location.pathname,
      page_title: root.document && root.document.title
    });
    root.dataLayer.push(payload);
    return payload;
  }

  function trackedForm(eventTarget) {
    return eventTarget && eventTarget.closest && eventTarget.closest('form[data-contact-form],form[data-funnel-form]');
  }

  function formTrackingName(form) {
    if (!form) return 'Website Contact Form';
    return cleanText(form.getAttribute('data-contact-form') || form.getAttribute('data-funnel-form') || form.id || 'Website Contact Form', 150);
  }

  function faqBlockId(element) {
    if (!element) return '';
    return compactValue(slugify(safeAttribute(element, 'id') || safeAttribute(element, 'data-faq-block') || 'faq'));
  }

  function faqSummaryKey(summary) {
    if (!summary) return 'faq-item';
    return compactValue(slugify(safeAttribute(summary, 'data-tracking-key') || safeAttribute(summary, 'id') || cleanText(summary.textContent, 150) || 'faq-item'));
  }

  function emitFormStart(win, form) {
    if (!form || !form.dataset || form.dataset.trackingStarted === 'true') return;
    form.dataset.trackingStarted = 'true';
    pushEvent(win, 'form_start', { form_name: formTrackingName(form) });
  }

  function emitFormStepOneComplete(win, form) {
    if (!form || !form.dataset || form.dataset.stepOneComplete === 'true') return;
    const fields = form.querySelectorAll ? form.querySelectorAll('[data-form-step-one-field]') : [];
    if (!fields || !fields.length) return;
    for (let index = 0; index < fields.length; index += 1) {
      const field = fields[index];
      if (!field || typeof field.checkValidity !== 'function' || field.checkValidity() !== true) return;
    }
    form.dataset.stepOneComplete = 'true';
    pushEvent(win, 'form_step_1_complete', { form_name: formTrackingName(form) });
  }

  function createSnapshotElement(snapshot, parentElement) {
    const attrs = Object.assign({}, snapshot && snapshot.attrs);
    const tagName = String(snapshot && snapshot.tag || 'a').toUpperCase();
    const element = {
      tagName: tagName,
      textContent: snapshot && snapshot.text || '',
      className: attrs.class || '',
      href: attrs.href || '',
      type: attrs.type || '',
      parentElement: parentElement || null,
      getAttribute: function getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null;
      }
    };
    return element;
  }

  function buildInventoryRecordFromSnapshot(snapshot) {
    const ancestors = (snapshot && snapshot.ancestors || []).reduce(function (parent, ancestor) {
      return createSnapshotElement(ancestor, parent);
    }, null);
    const element = createSnapshotElement(snapshot, ancestors);
    const payload = buildUiClickPayload(element, snapshot && snapshot.browser_location || browserLocationForPagePath(snapshot && snapshot.page_path || '/'));
    return {
      page_path: normalizePathString(snapshot && snapshot.page_path || '/'),
      button_id: payload.button_id,
      button_category: payload.button_category,
      button_destination: payload.button_destination,
      button_section: payload.button_section,
      element_type: payload.element_type
    };
  }

  function install(win, doc) {
    if (win.__blueHavenTrackingInstalled) return;
    win.__blueHavenTrackingInstalled = true;
    win.dataLayer = win.dataLayer || [];

    // Shared entrypoint: local resources only until the visitor grants consent.
    if (!win.blueHavenOpenAI && !win.__blueHavenOpenAILoading) {
      win.__blueHavenOpenAILoading = true;
      function adapter() {
        const script = doc.createElement('script'); script.src = '/bluehaven-openai.js?v=consent-20261026';
        doc.head.appendChild(script);
      }
      if (win.blueHavenOpenAIConfig) adapter();
      else {
        const script = doc.createElement('script'); script.src = '/bluehaven-openai-config.js?v=1611603d093fe365';
        script.onload = adapter; doc.head.appendChild(script);
      }
    }
    function measurementAllowed() { return !!(win.blueHavenConsent && win.blueHavenConsent.allowed("advertising")); }
    let attribution = {};
    function refreshAttribution() {
      const current = win.blueHavenConsent ? win.blueHavenConsent.attribution() : {};
      let stored = {};
      try { stored = parseAttribution(new URLSearchParams(JSON.parse(win.sessionStorage.getItem('bluehaven_attribution') || '{}')).toString()); } catch (_) {}
      attribution = Object.assign({}, stored, current);
      ['oppref','fbclid','msclkid'].forEach(key => delete attribution[key]);
      if (!measurementAllowed()) ['gclid','gbraid','wbraid','dclid'].forEach(key => delete attribution[key]);
      try { if (measurementAllowed()) win.sessionStorage.setItem('bluehaven_attribution', JSON.stringify(attribution)); else win.sessionStorage.removeItem('bluehaven_attribution'); } catch (_) {}
      win.blueHavenAttribution = attribution;
      fillAttribution();
    }
    win.blueHavenRefreshAttribution = refreshAttribution;
    function fillAttribution() {
      doc.querySelectorAll('form[data-contact-form], form[data-funnel-form]').forEach(function (form) {
        ['oppref','gclid','gbraid','wbraid','fbclid','dclid','msclkid'].forEach(key => { const input=form.querySelector('input[name="'+key+'"]'); if(input && !attribution[key]) input.value=''; });
        Object.keys(attribution).forEach(function (key) {
          let input = form.querySelector('input[name="' + key + '"]');
          if (!input) {
            input = doc.createElement('input');
            input.type = 'hidden';
            input.name = key;
            form.appendChild(input);
          }
          input.value = attribution[key];
        });
      });
    }
    refreshAttribution();
    doc.addEventListener('DOMContentLoaded', refreshAttribution);
    doc.addEventListener('submit', refreshAttribution, true);
    win.blueHavenTrack = function (eventName, payload) { return pushEvent(win, eventName, payload); };

    if (isThankYouPath(win.location.pathname)) {
      pushEvent(win, 'thankyou_view', { funnel_step: 'lead_confirmed' });
    }

    doc.addEventListener('click', function (event) {
      const payloads = buildClickEvents(event.target, win.location.href);
      if (!payloads.length) return;
      payloads.forEach(function (payload) {
        const name = payload.event;
        const copy = Object.assign({}, payload);
        delete copy.event;
        pushEvent(win, name, copy);
      });
    }, true);

    doc.addEventListener('focusin', function (event) {
      emitFormStart(win, trackedForm(event.target));
    }, true);

    function handleFormProgress(event) {
      const form = trackedForm(event.target);
      if (!form) return;
      emitFormStart(win, form);
      emitFormStepOneComplete(win, form);
    }

    doc.addEventListener('input', handleFormProgress, true);
    doc.addEventListener('change', handleFormProgress, true);

    if (typeof win.IntersectionObserver === 'function') {
      const seenFaqBlocks = {};
      const faqObserver = new win.IntersectionObserver(function (entries, observer) {
        entries.forEach(function (entry) {
          if (!entry || !entry.isIntersecting) return;
          const block = entry.target;
          const blockId = faqBlockId(block);
          if (!blockId || seenFaqBlocks[blockId]) return;
          seenFaqBlocks[blockId] = true;
          pushEvent(win, 'faq_impression', { faq_block_id: blockId });
          if (observer && typeof observer.unobserve === 'function') observer.unobserve(block);
        });
      });
      const faqBlocks = doc.querySelectorAll ? doc.querySelectorAll('[data-faq-block]') : [];
      for (let faqIndex = 0; faqIndex < faqBlocks.length; faqIndex += 1) {
        faqObserver.observe(faqBlocks[faqIndex]);
      }
    }

    doc.addEventListener('toggle', function (event) {
      const details = event.target;
      if (!details || String(details.tagName).toLowerCase() !== 'details' || !details.open) return;
      const summary = details.querySelector && details.querySelector('summary');
      const block = details.closest && details.closest('[data-faq-block]');
      if (block) {
        pushEvent(win, 'faq_expand', {
          faq_block_id: faqBlockId(block),
          faq_item_id: faqSummaryKey(summary),
          question_text: cleanText(summary && summary.textContent, 150)
        });
        return;
      }
      pushEvent(win, 'accordion_open', { item_text: cleanText(summary && summary.textContent, 150) });
    }, true);
  }

  return {
    INTERACTIVE_SELECTOR: INTERACTIVE_SELECTOR,
    buildClickEvents: buildClickEvents,
    buildInteractionInventory: buildInteractionInventory,
    buildInventoryRecordFromSnapshot: buildInventoryRecordFromSnapshot,
    classifyInteraction: classifyInteraction,
    browserLocationForPagePath: browserLocationForPagePath,
    isThankYouPath: isThankYouPath,
    install: install,
    parseAttribution: parseAttribution,
    pushEvent: pushEvent
  };
}));
