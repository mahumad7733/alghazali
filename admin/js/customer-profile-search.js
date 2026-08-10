(function () {
    'use strict';

    const fields = {
        full_name: ['full_name', 'traveler_name', 'owner_name', 'sender_full_name', 'ocr_name'],
        full_name_en: ['full_name_en', 'ocr_name_en'],
        phone: ['phone_number', 'mobile_number', 'phone_no', 'sender_phone'],
        passport_number: ['passport_number'],
        date_of_birth: ['date_of_birth'],
        gender: ['gender'],
        nationality: ['nationality'],
        id_type: ['id_type'],
        id_number: ['id_number', 'owner_id_no'],
        id_issue_place: ['id_issue_place'],
        id_issue_date: ['id_issue_date'],
        passport_issue_date: ['passport_issue_date'],
        passport_expiry_date: ['passport_expiry_date'],
    };

    const phoneFieldNames = ['phone_number', 'mobile_number', 'phone_no', 'sender_phone', 'recipient_phone'];
    let dialCodesPromise;

    function loadDialCodes() {
        if (!dialCodesPromise) {
            dialCodesPromise = fetch('ajax/country_dial_codes.php', { headers: { Accept: 'application/json' } })
                .then((response) => response.json())
                .then((payload) => payload.countries || [])
                .catch(() => []);
        }
        return dialCodesPromise;
    }

    function normalizeDialCode(value) {
        const code = String(value || '').trim();
        return code ? (code.startsWith('+') ? code : '+' + code) : '';
    }

    const alpha3ToAlpha2 = {
        ARE: 'AE', BHR: 'BH', EGY: 'EG', IND: 'IN', JOR: 'JO', KWT: 'KW',
        LBN: 'LB', OMN: 'OM', PAK: 'PK', QAT: 'QA', SAU: 'SA', SDN: 'SD',
        SYR: 'SY', TUR: 'TR', UAE: 'AE', USA: 'US', YEM: 'YE'
    };

    function countryFlag(countryCode) {
        const code = String(countryCode || '').trim().toUpperCase();
        const alpha2 = code.length === 2 ? code : alpha3ToAlpha2[code];
        if (!alpha2 || !/^[A-Z]{2}$/.test(alpha2)) return '🌐';
        return [...alpha2].map((letter) => String.fromCodePoint(127397 + letter.charCodeAt(0))).join('');
    }

    function attachPhoneField(form, input, countries) {
        if (!input || input.dataset.phoneEnhanced === '1') return;
        input.dataset.phoneEnhanced = '1';
        const parent = input.parentElement;
        const group = document.createElement('div');
        group.className = 'input-group';
        parent.insertBefore(group, input);
        group.appendChild(input);
        const select = document.createElement('select');
        select.className = 'form-select customer-country-dial';
        select.name = input.name + '_country_code';
        select.style.maxWidth = '115px';
        select.style.fontSize = '12px';
        select.setAttribute('aria-label', 'مفتاح الدولة');
        select.innerHTML = '<option value="">الدولة</option>' + countries.map((country) => {
            const dial = normalizeDialCode(country.dial_code);
            const flag = countryFlag(country.country_code);
            const name = String(country.country_name || '').replace(/"/g, '&quot;');
            return '<option value="' + dial + '" data-country-id="' + country.id + '" data-country-name="' + name + '" data-country-flag="' + flag + '">' + flag + ' ' + dial + '</option>';
        }).join('');
        group.insertBefore(select, input);

        const original = input.value.trim();
        const matching = countries.find((country) => {
            const dial = normalizeDialCode(country.dial_code);
            return dial && original.startsWith(dial);
        });
        if (matching) {
            select.value = normalizeDialCode(matching.dial_code);
            input.value = original.slice(normalizeDialCode(matching.dial_code).length).trim();
        }
        select.addEventListener('change', () => {
            const dial = normalizeDialCode(select.value);
            const current = input.value.trim();
            countries.forEach((country) => {
                const oldDial = normalizeDialCode(country.dial_code);
                if (oldDial && current.startsWith(oldDial)) input.value = current.slice(oldDial.length).trim();
            });
            if (dial && input.value.trim() === '') input.focus();
        });
        const nationality = form.elements.nationality || form.elements.nationality_id;
        const syncNationalityDial = () => {
            if (!nationality) return;
            const value = String(nationality.value || '').trim().toLowerCase();
            const option = nationality.options?.[nationality.selectedIndex];
            const country = countries.find((item) => String(item.id) === value
                || String(item.country_name || '').trim().toLowerCase() === value
                || String(item.country_code || '').trim().toLowerCase() === value
                || String(option?.textContent || '').trim().toLowerCase() === String(item.country_name || '').trim().toLowerCase());
            if (country) select.value = normalizeDialCode(country.dial_code);
        };
        nationality?.addEventListener('change', syncNationalityDial);
        syncNationalityDial();
        form.addEventListener('submit', () => {
            const dial = normalizeDialCode(select.value);
            const number = input.value.trim();
            if (dial && number && !number.startsWith('+')) input.value = dial + number.replace(/^0+/, '');
        });
    }

    function attachDateDefaults(form) {
        const issue = form.elements.passport_issue_date;
        const expiry = form.elements.passport_expiry_date;
        if (!issue || !expiry || issue.dataset.expiryLinked === '1') return;
        issue.dataset.expiryLinked = '1';
        const updateExpiry = () => {
            if (!issue.value || (expiry.value && expiry.dataset.autoGenerated !== '1')) return;
            const date = new Date(issue.value + 'T00:00:00');
            if (Number.isNaN(date.getTime())) return;
            date.setFullYear(date.getFullYear() + 5);
            expiry.value = date.toISOString().slice(0, 10);
            expiry.dataset.autoGenerated = '1';
        };
        expiry.addEventListener('input', () => { expiry.dataset.autoGenerated = '0'; });
        issue.addEventListener('change', updateExpiry);
        updateExpiry();
    }

    const findField = (form, names) => names.map((name) => form.elements[name]).find(Boolean);

    function fillForm(form, customer) {
        const values = {
            full_name: customer.full_name,
            full_name_en: customer.full_name_en,
            phone: customer.phone_number || customer.mobile_number,
            passport_number: customer.passport_number,
            date_of_birth: customer.date_of_birth,
            gender: customer.gender,
            nationality: customer.nationality,
            id_type: customer.id_type,
            id_number: customer.id_number,
            id_issue_place: customer.id_issue_place,
            id_issue_date: customer.id_issue_date,
            passport_issue_date: customer.passport_issue_date,
            passport_expiry_date: customer.passport_expiry_date,
        };
        Object.keys(values).forEach((key) => {
            const input = findField(form, fields[key]);
            if (input && values[key] !== null && values[key] !== undefined) {
                if (input.tagName === 'SELECT' && String(values[key]).trim() !== '') {
                    const matchByText = Array.from(input.options).find((opt) => opt.textContent.trim() === String(values[key]).trim());
                    const matchByCode = Array.from(input.options).find((opt) => String(opt.dataset.code || '').toUpperCase() === String(values[key]).trim().toUpperCase());
                    const matchByValue = Array.from(input.options).find((opt) => opt.value === String(values[key]).trim());
                    const target = matchByValue || matchByCode || matchByText;
                    if (target) {
                        input.value = target.value;
                    } else {
                        input.value = values[key];
                    }
                } else {
                    input.value = values[key];
                }
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        let hidden = form.elements.passport_id;
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'passport_id';
            form.appendChild(hidden);
        }
        hidden.value = customer.id;
    }

    function initForm(form) {
        if (form.dataset.customerProfileReady === '1') return;
        form.dataset.customerProfileReady = '1';
        const anchor = findField(form, fields.full_name) || findField(form, fields.phone);
        if (!anchor) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'customer-profile-search mb-3 p-3 border rounded-3 bg-light';
        wrapper.innerHTML = '<small class="text-muted">اكتب الاسم أو رقم الجواز لعرض العملاء السابقين، ثم اختر العميل لتعبئة البيانات.</small>';
        anchor.closest('.mb-3, .form-group, .col-md-6, .col-lg-6')?.before(wrapper) || form.prepend(wrapper);
        let timer;
        let latestRequest = 0;

        const getDropdown = (input) => {
            let dropdown = input.parentElement.querySelector('.customer-profile-dropdown');
            if (!dropdown) {
                input.parentElement.style.position = 'relative';
                dropdown = document.createElement('div');
                dropdown.className = 'customer-profile-dropdown list-group';
                dropdown.style.cssText = 'position:absolute;top:100%;left:0;right:0;z-index:1080;max-height:260px;overflow-y:auto;box-shadow:0 6px 18px rgba(0,0,0,.15);';
                input.parentElement.appendChild(dropdown);
            }
            return dropdown;
        };

        const renderResults = (dropdown, customers) => {
            dropdown.innerHTML = '';
            customers.forEach((customer) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'list-group-item list-group-item-action text-end';
                button.textContent = [customer.full_name, customer.passport_number, customer.phone_number || customer.mobile_number].filter(Boolean).join(' - ');
                button.addEventListener('click', () => {
                    fillForm(form, customer);
                    dropdown.innerHTML = '';
                });
                dropdown.appendChild(button);
            });
            if (!customers.length) dropdown.innerHTML = '<div class="list-group-item list-group-item-warning py-2">لا يوجد عميل مطابق؛ أكمل البيانات يدويًا.</div>';
        };

        const searchCustomers = (input) => {
            clearTimeout(timer);
            const value = input.value.trim();
            const dropdown = getDropdown(input);
            if (value.length < 2) { dropdown.innerHTML = ''; return; }
            timer = setTimeout(async () => {
                const requestId = ++latestRequest;
                try {
                    const response = await fetch('ajax/customer_search.php?q=' + encodeURIComponent(value), { headers: { Accept: 'application/json' } });
                    const payload = await response.json();
                    if (requestId === latestRequest) renderResults(dropdown, payload.customers || []);
                } catch (error) {
                    dropdown.innerHTML = '<div class="list-group-item list-group-item-danger py-2">تعذر البحث عن العميل.</div>';
                }
            }, 250);
        };

        [anchor, findField(form, fields.passport_number)].filter(Boolean).forEach((input) => {
            input.addEventListener('input', () => searchCustomers(input));
            input.addEventListener('focus', () => {
                if (input.value.trim().length >= 2) searchCustomers(input);
            });
        });
        loadDialCodes().then((countries) => {
            phoneFieldNames.forEach((name) => attachPhoneField(form, form.elements[name], countries));
        });
        attachDateDefaults(form);
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('form[data-customer-profile-form="1"]').forEach(initForm);
    });
})();
