(function () {
    const endpoint = '/contact.php';
    const configEndpoint = '/contact-config.php?v=20260906';
    const trackingCallbackTimeoutMs = 1800;
    const adsConversionTimeoutMs = 2000;
    const redirectSettleMs = 6500;
    const finalRedirectTimeoutMs = 10000;
    const ga4MeasurementId = 'G-WTEM34PS2J';
    const googleAdsSendTo = 'AW-18254638708/ki5xCK-Lw9scEPTcvoBE';
    const formSelector = 'form[data-contact-form], form[data-funnel-form]';

    const ga4StartedForms = new WeakSet();
    const formStates = new WeakMap();
    let securityConfigPromise = null;
    let turnstileScriptPromise = null;
    let cachedSecurityConfig = null;

    function initializeQaContext(href) {
        const disabled = { enabled: false, runId: '' };
        try {
            const params = new URL(href, window.location.origin).searchParams;
            const sources = params.getAll('utm_source');
            const media = params.getAll('utm_medium');
            const runIds = params.getAll('utm_content');
            const runId = runIds.length === 1 ? runIds[0] : '';
            if (
                sources.length === 1 && sources[0] === 'qa'
                && media.length === 1 && media[0] === 'internal'
                && /^BHQA-\d{8}-[A-Z0-9]{6}$/.test(runId)
            ) {
                return { enabled: true, runId };
            }
        } catch (error) {
            return disabled;
        }
        return disabled;
    }

    window.__BLUEHAVEN_QA__ = initializeQaContext(window.blueHavenConsent ? window.blueHavenConsent.inquiryUrl() : window.location.href);

    function sanitizePageLocation(href) {
        try {
            const url = new URL(href, window.location.origin);
            return url.origin + url.pathname;
        } catch (error) {
            return window.location.origin + window.location.pathname;
        }
    }

    function thankYouDestination(form) {
        return (form.getAttribute('data-thank-you-url') || '').trim();
    }

    function fieldText(control) {
        const label = control.id ? document.querySelector(`label[for="${control.id}"]`) : null;
        const wrappingLabel = control.closest('label');
        return [
            control.name,
            control.placeholder,
            control.getAttribute('aria-label'),
            label ? label.textContent : '',
            wrappingLabel ? wrappingLabel.textContent : ''
        ].join(' ').toLowerCase();
    }

    function findValue(form, pattern) {
        const controls = Array.from(form.querySelectorAll('input, textarea, select'));
        const match = controls.find((control) => {
            if (control.type === 'hidden' || control.name === 'website') return false;
            return pattern.test(fieldText(control));
        });
        return match ? String(match.value || '').trim() : '';
    }

    function splitName(fullName) {
        const cleaned = String(fullName || '').trim().replace(/\s+/g, ' ');
        if (!cleaned) {
            return { firstName: '', lastName: '' };
        }

        const parts = cleaned.split(' ');
        return {
            firstName: parts.shift() || '',
            lastName: parts.join(' ')
        };
    }

    function setIfMissing(formData, key, value) {
        if (!formData.get(key) && value) {
            formData.set(key, value);
        }
    }

    function ensureStatus(form) {
        const existing = form.querySelector('.service-form-success') || document.getElementById('form-success') || form.querySelector('[data-form-status]');
        if (existing) return existing;

        const status = document.createElement('div');
        status.setAttribute('data-form-status', '');
        status.className = 'hidden mt-5 bg-green-50 border border-green-200 rounded-2xl p-4 text-center text-green-800 text-sm font-bold';
        form.appendChild(status);
        return status;
    }

    function showStatus(status, message, isError) {
        status.classList.remove('hidden');
        status.classList.toggle('bg-red-50', isError);
        status.classList.toggle('border-red-200', isError);
        status.classList.toggle('text-red-800', isError);
        if (status.hasAttribute('data-form-status')) {
            status.textContent = message;
        } else {
            const paragraph = status.querySelector('p');
            if (paragraph) {
                paragraph.textContent = message;
            }
        }
    }

    function addMailerLiteConsent(form) {
        if (form.querySelector('[data-mailerlite-consent]')) return;

        const submitButton = form.querySelector('button[type="submit"], button:not([type])');
        const wrapper = document.createElement('label');
        wrapper.setAttribute('data-mailerlite-consent', '');
        wrapper.className = 'flex items-start gap-3 mt-4 mb-4 text-sm leading-relaxed text-slate-600 cursor-pointer';
        wrapper.innerHTML = '<input type="hidden" name="marketing_consent" value="0"><input type="checkbox" name="marketing_consent" value="1" checked class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-700 focus:ring-blue-600"><span>Sign up for our list to get monthly promotional deals.</span>';

        if (submitButton) {
            submitButton.insertAdjacentElement('beforebegin', wrapper);
        } else {
            form.appendChild(wrapper);
        }
    }

    function initMailerLiteConsent() {
        document.querySelectorAll('form[data-contact-form]').forEach(addMailerLiteConsent);
    }

    function configureGa4Destination() {
        if (typeof gtag === 'function') {
            gtag('config', ga4MeasurementId, { send_page_view: false });
        }
    }

    function loadSecurityConfig() {
        if (cachedSecurityConfig) {
            return Promise.resolve(cachedSecurityConfig);
        }

        if (window.BLUEHAVEN_CONTACT_CONFIG && typeof window.BLUEHAVEN_CONTACT_CONFIG === 'object') {
            cachedSecurityConfig = window.BLUEHAVEN_CONTACT_CONFIG;
            return Promise.resolve(cachedSecurityConfig);
        }

        if (!securityConfigPromise) {
            securityConfigPromise = fetch(configEndpoint, { headers: { Accept: 'application/json' } })
                .then((response) => response.json())
                .catch(() => ({}))
                .then((config) => {
                    cachedSecurityConfig = config && typeof config === 'object' ? config : {};
                    return cachedSecurityConfig;
                });
        }

        return securityConfigPromise;
    }

    function ensureTurnstileScript() {
        if (window.turnstile) {
            return Promise.resolve(window.turnstile);
        }

        if (!turnstileScriptPromise) {
            turnstileScriptPromise = new Promise((resolve, reject) => {
                const existing = document.querySelector('script[data-bluehaven-turnstile]');
                if (existing && window.turnstile) {
                    resolve(window.turnstile);
                    return;
                }

                const script = document.createElement('script');
                script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
                script.async = true;
                script.defer = true;
                script.setAttribute('data-bluehaven-turnstile', 'true');
                script.onload = () => {
                    if (window.turnstile) {
                        resolve(window.turnstile);
                    } else {
                        reject(new Error('Security challenge failed to load.'));
                    }
                };
                script.onerror = () => reject(new Error('Security challenge failed to load.'));
                document.head.appendChild(script);
            });
        }

        return turnstileScriptPromise;
    }

    function ensureTurnstileContainer(form) {
        let container = form.querySelector('[data-bluehaven-turnstile-slot]');
        if (container) {
            return container;
        }

        container = document.createElement('div');
        container.setAttribute('data-bluehaven-turnstile-slot', '');
        container.style.width = '100%';
        container.style.margin = '12px 0';
        const submitButton = form.querySelector('button[type="submit"], button:not([type])');
        if (submitButton) {
            submitButton.insertAdjacentElement('beforebegin', container);
        } else {
            form.appendChild(container);
        }

        return container;
    }

    function formState(form) {
        if (!formStates.has(form)) {
            formStates.set(form, {
                widgetId: null,
                pendingPromise: null,
                pendingResolve: null,
                pendingReject: null,
                pendingTimeout: null
            });
        }

        return formStates.get(form);
    }

    async function requestTurnstileToken(form, config) {
        if (!config || !config.turnstile_sitekey) {
            throw new Error('Security challenge is not configured for this site. Please try again later.');
        }

        await ensureTurnstileScript();
        const state = formState(form);
        if (!state.widgetId) {
            const container = ensureTurnstileContainer(form);
            state.widgetId = window.turnstile.render(container, {
                sitekey: config.turnstile_sitekey,
                action: config.turnstile_action || 'contact_form',
                size: 'flexible',
                execution: 'execute',
                appearance: 'interaction-only',
                callback(token) {
                    if (state.pendingResolve) {
                        state.pendingResolve(token);
                    }
                },
                'error-callback'() {
                    if (state.pendingReject) {
                        state.pendingReject(new Error('The security check failed. Please retry.'));
                    }
                },
                'expired-callback'() {
                    if (state.pendingReject) {
                        state.pendingReject(new Error('The security check expired. Please retry.'));
                    }
                },
                'timeout-callback'() {
                    if (state.pendingReject) {
                        state.pendingReject(new Error('The security check timed out. Please retry.'));
                    }
                }
            });
        }

        if (state.pendingPromise) {
            return state.pendingPromise;
        }

        state.pendingPromise = new Promise((resolve, reject) => {
            state.pendingResolve = function (token) {
                if (state.pendingTimeout) {
                    window.clearTimeout(state.pendingTimeout);
                }
                state.pendingResolve = null;
                state.pendingReject = null;
                state.pendingTimeout = null;
                resolve(token);
            };
            state.pendingReject = function (error) {
                if (state.pendingTimeout) {
                    window.clearTimeout(state.pendingTimeout);
                }
                state.pendingResolve = null;
                state.pendingReject = null;
                state.pendingTimeout = null;
                reject(error instanceof Error ? error : new Error(String(error || 'Security check failed.')));
            };

            state.pendingTimeout = window.setTimeout(() => {
                if (state.pendingReject) {
                    state.pendingReject(new Error('The security check timed out. Please retry.'));
                }
            }, 120000);

            try {
                window.turnstile.execute(state.widgetId);
            } catch (error) {
                state.pendingReject(error);
            }
        }).finally(() => {
            state.pendingPromise = null;
            state.pendingResolve = null;
            state.pendingReject = null;
            if (state.pendingTimeout) {
                window.clearTimeout(state.pendingTimeout);
                state.pendingTimeout = null;
            }
        });

        return state.pendingPromise;
    }

    function buildSubmissionPayload(form, formData) {
        // Refresh permission at submission, including consent expiry/withdrawal.
        if (window.blueHavenRefreshAttribution) window.blueHavenRefreshAttribution();
        const measurementAllowed = window.blueHavenConsent && window.blueHavenConsent.allowed("advertising");
        if (!measurementAllowed) ['oppref','gclid','gbraid','wbraid','fbclid','dclid','msclkid'].forEach(key => formData.delete(key));
        const sourceUrl = new URL(window.location.href);
        if (!measurementAllowed) { sourceUrl.search = ''; sourceUrl.hash = ''; }
        formData.set('source_page', sourceUrl.href);
        formData.set('page_title', document.title);
        formData.set('form_name', form.getAttribute('data-contact-form') || form.getAttribute('data-funnel-form') || 'Website Contact Form');
        Object.entries(window.blueHavenAttribution || {}).forEach(([key, value]) => {
            if (measurementAllowed || /^utm_/.test(key)) formData.set(key, String(value).slice(0, 200));
        });

        const qaContext = window.__BLUEHAVEN_QA__ || {};
        if (qaContext.enabled === true) {
            formData.set('utm_source', 'qa');
            formData.set('utm_medium', 'internal');
            formData.set('qa_mode', 'developer');
            formData.set('qa_run_id', String(qaContext.runId));
            formData.set('qa_label', 'BLUE HAVEN QA TEST');
            formData.set('marketing_consent', '0');
        }

        const fullNameControl = form.querySelector('[name="full_name"]');
        if (fullNameControl) {
            const nameParts = splitName(fullNameControl.value);
            setIfMissing(formData, 'first_name', nameParts.firstName);
            setIfMissing(formData, 'last_name', nameParts.lastName);
        }

        const details = [];
        const address = form.querySelector('[name="address"]');
        const city = form.querySelector('[name="city"]');
        const timeline = form.querySelector('[name="timeline"]');
        const comments = form.querySelector('[name="comments"]');
        if (address) details.push('Address: ' + String(address.value || '').trim());
        if (city) details.push('City: ' + String(city.value || '').trim());
        if (timeline) details.push('Timeline: ' + String(timeline.value || '').trim());
        if (comments) details.push('Comments: ' + (String(comments.value || '').trim() || 'None provided'));
        if (details.length > 0) {
            setIfMissing(formData, 'message', details.join('\n'));
        }

        setIfMissing(formData, 'first_name', findValue(form, /first/));
        setIfMissing(formData, 'last_name', findValue(form, /last/));
        setIfMissing(formData, 'email', findValue(form, /email/));
        setIfMissing(formData, 'phone', findValue(form, /phone|tel/));
        setIfMissing(formData, 'message', findValue(form, /project|details|message|timeline|location/));

        return formData;
    }

    function fireGa4FormStart(event) {
        const form = event.target.closest(formSelector);
        if (!form || ga4StartedForms.has(form) || typeof gtag !== 'function') return;
        ga4StartedForms.add(form);
        gtag('event', 'form_start', {
            send_to: ga4MeasurementId,
            form_name: form.getAttribute('data-contact-form') || form.getAttribute('data-funnel-form') || 'Website Contact Form',
            qa_mode: 'production',
            qa_run_id: '',
            page_location: sanitizePageLocation(window.location.href),
            page_title: document.title
        });
    }

    function fireGa4FormEvents(parameters, onComplete) {
        let callbackCompleted = false;
        const complete = function () {
            if (callbackCompleted) return;
            callbackCompleted = true;
            if (typeof onComplete === 'function') onComplete();
        };

        if (typeof gtag !== 'function') {
            complete();
            return;
        }

        const routedParameters = Object.assign({}, parameters, { send_to: ga4MeasurementId });
        gtag('event', 'form_submit', routedParameters);
        gtag('event', 'generate_lead', Object.assign({}, routedParameters, {
            event_callback: complete,
            event_timeout: trackingCallbackTimeoutMs
        }));
    }

    function fireAdsConversion(onComplete) {
        if (typeof gtag === 'function') {
            gtag('event', 'conversion', {
                send_to: googleAdsSendTo,
                value: 1.0,
                currency: 'USD',
                event_callback: onComplete,
                event_timeout: adsConversionTimeoutMs
            });
            return;
        }

        if (typeof onComplete === 'function') {
            onComplete();
        }
    }

    async function submitContactForm(event) {
        const form = event.target.closest(formSelector);
        if (!form) return;

        event.preventDefault();
        event.stopImmediatePropagation();

        const submitButton = form.querySelector('button[type="submit"], button:not([type])');
        const originalButtonHtml = submitButton ? submitButton.innerHTML : '';
        const status = ensureStatus(form);
        const formData = buildSubmissionPayload(form, new FormData(form));
        const config = await loadSecurityConfig();

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = 'Sending...';
        }

        try {
            const turnstileToken = await requestTurnstileToken(form, config);
            // An optional/stale consent asset can never trap the inquiry behind its promise.
            let receiptReady = false, receiptTimer;
            if (window.blueHavenConsent && window.blueHavenConsent.receipt) {
                try {
                    receiptReady = await Promise.race([
                        Promise.resolve().then(() => window.blueHavenConsent.receipt()),
                        new Promise(resolve => { receiptTimer = setTimeout(() => resolve(false), 600); })
                    ]);
                } catch (_) { receiptReady = false; }
                finally { clearTimeout(receiptTimer); }
            }
            formData.set('measurement_receipt_unresolved', receiptReady === true ? '0' : '1');
            // Re-evaluate after asynchronous challenge/receipt, so withdrawal cannot leave stale click fields.
            buildSubmissionPayload(form, formData);
            formData.set('turnstile_token', turnstileToken);
            formData.set('cf-turnstile-response', turnstileToken);

            const response = await fetch(form.getAttribute('action') || endpoint, {
                method: form.getAttribute('method') || 'POST',
                body: formData,
                headers: { Accept: 'application/json' }
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok || result.success !== true || !result.lead_id) {
                throw new Error(result.message || 'Please call (615) 987-0593 or try again.');
            }

            const formName = form.getAttribute('data-contact-form') || form.getAttribute('data-funnel-form') || 'Website Contact Form';
            const qaAuthorized = result.qa_authorized === true;
            const redirectUrl = thankYouDestination(form);

            form.reset();
            showStatus(status, result.message || 'Thank you. Your request has been received.', false);

            if (qaAuthorized && result.qa_delivery_verified === true) {
                window.dataLayer = window.dataLayer || [];
                (window.blueHavenConsent ? window.blueHavenConsent.track : function () {})({event: 'qa_delivery_test', qa_mode: 'developer', qa_run_id: result.qa_run_id || '', lead_id: result.lead_id});
                if (submitButton) submitButton.innerHTML = 'Internal QA Recorded';
                if (window.lucide) window.lucide.createIcons();
                return;
            }

            if (qaAuthorized) {
                window.dataLayer = window.dataLayer || [];
                (window.blueHavenConsent ? window.blueHavenConsent.track : function () {})({event: 'qa_heartbeat', qa_mode: 'developer', qa_run_id: (window.__BLUEHAVEN_QA__ || {}).runId || '', lead_id: result.lead_id});
                if (submitButton) {
                    submitButton.innerHTML = 'QA Recorded';
                }
                if (window.lucide) {
                    window.lucide.createIcons();
                }
                return;
            }

            // Only backend-confirmed estimates are eligible; analytics failure must not affect delivery.
            try {
                if (window.blueHavenOpenAI) window.blueHavenOpenAI.lead(result);
            } catch (_) {}

            const leadParameters = {
                value: 1.0,
                currency: 'USD',
                form_name: formName,
                lead_id: result.lead_id,
                lead_source: 'website_form',
                qa_mode: 'production',
                qa_run_id: '',
                page_location: sanitizePageLocation(window.location.href),
                page_title: document.title
            };
            window.dataLayer = window.dataLayer || [];
            (window.blueHavenConsent ? window.blueHavenConsent.track : function () {})(Object.assign({ event: 'form_submit' }, leadParameters));

            if (redirectUrl !== '') {
                let redirectStarted = false;
                let adsConversionStarted = false;
                const navigateToThankYou = function () {
                    if (redirectStarted) return;
                    redirectStarted = true;
                    window.location.href = redirectUrl;
                };
                const scheduleThankYouNavigation = function () {
                    window.setTimeout(navigateToThankYou, redirectSettleMs);
                };
                const dispatchTrackedSuccess = function () {
                    if (adsConversionStarted) return;
                    adsConversionStarted = true;
                    fireAdsConversion(scheduleThankYouNavigation);
                };

                (window.blueHavenConsent ? window.blueHavenConsent.track : function () {})(Object.assign({ event: 'generate_lead' }, leadParameters));
                fireGa4FormEvents(leadParameters, dispatchTrackedSuccess);

                window.setTimeout(dispatchTrackedSuccess, adsConversionTimeoutMs);
                window.setTimeout(navigateToThankYou, finalRedirectTimeoutMs);
            } else {
                (window.blueHavenConsent ? window.blueHavenConsent.track : function () {})(Object.assign({ event: 'generate_lead' }, leadParameters));
                fireGa4FormEvents(leadParameters);
                fireAdsConversion();
            }

            if (submitButton) {
                submitButton.innerHTML = String(form.getAttribute('data-contact-form') || form.getAttribute('data-funnel-form') || '').includes('Guide')
                    ? 'Guide Request Sent'
                    : 'Request Sent';
            }
        } catch (error) {
            showStatus(status, error.message || 'Please call (615) 987-0593 or try again.', true);
            if (submitButton) {
                submitButton.innerHTML = originalButtonHtml;
            }
        } finally {
            const state = formState(form);
            if (state.widgetId !== null && window.turnstile) {
                window.turnstile.reset(state.widgetId);
            }
            if (submitButton) {
                submitButton.disabled = false;
            }
            if (window.lucide) {
                window.lucide.createIcons();
            }
        }
    }

    configureGa4Destination();
    initMailerLiteConsent();
    document.addEventListener('DOMContentLoaded', initMailerLiteConsent);
    document.addEventListener('focusin', fireGa4FormStart, true);
    document.addEventListener('submit', submitContactForm, true);

    document.querySelectorAll('form[data-contact-form] input[name="source_page"], form[data-funnel-form] input[name="source_page"]').forEach((input) => {
        input.value = window.location.href;
    });

    window.BlueHavenContactForm = {
        loadSecurityConfig,
        requestTurnstileToken,
        buildSubmissionPayload,
        submitContactForm
    };
}());
