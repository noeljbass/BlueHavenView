(function () {
    const endpoint = '/contact.php';

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
        return match ? match.value.trim() : '';
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

    async function submitContactForm(event) {
        const form = event.target.closest('form[data-contact-form]');
        if (!form) return;

        event.preventDefault();
        event.stopImmediatePropagation();

        const submitButton = form.querySelector('button[type="submit"], button:not([type])');
        const originalButtonHtml = submitButton ? submitButton.innerHTML : '';
        const status = ensureStatus(form);
        const formData = new FormData(form);

        formData.set('source_page', window.location.href);
        formData.set('page_title', document.title);
        formData.set('form_name', form.getAttribute('data-contact-form') || 'Website Contact Form');
        setIfMissing(formData, 'first_name', findValue(form, /first/));
        setIfMissing(formData, 'last_name', findValue(form, /last/));
        setIfMissing(formData, 'email', findValue(form, /email/));
        setIfMissing(formData, 'phone', findValue(form, /phone|tel/));
        setIfMissing(formData, 'message', findValue(form, /project|details|message|timeline|location/));

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = 'Sending...';
        }

        try {
            const response = await fetch(form.getAttribute('action') || endpoint, {
                method: form.getAttribute('method') || 'POST',
                body: formData,
                headers: { 'Accept': 'application/json' }
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || result.success === false) {
                throw new Error(result.message || 'Please call (615) 987-0593 or try again.');
            }

            form.reset();
            showStatus(status, result.message || 'Thank you. Your request has been emailed to Stephen at Blue Haven Windows.', false);
            if (submitButton) {
                submitButton.innerHTML = form.matches('[data-contact-form*="Guide"]') ? 'Guide Request Sent' : 'Request Sent';
            }
        } catch (error) {
            showStatus(status, error.message || 'Please call (615) 987-0593 or try again.', true);
            if (submitButton) {
                submitButton.innerHTML = originalButtonHtml;
            }
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
            }
            if (window.lucide) {
                window.lucide.createIcons();
            }
        }
    }

    document.addEventListener('submit', submitContactForm, true);

    document.querySelectorAll('form[data-contact-form] input[name="source_page"]').forEach((input) => {
        input.value = window.location.href;
    });
}());
