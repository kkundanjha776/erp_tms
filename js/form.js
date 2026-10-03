/**
 * Consignment Entry Form - AJAX & Calculations
 */
(function () {
    'use strict';

    const API_BASE = 'api/';
    let noteCheckTimer = null;
    let calcTimer = null;
    let contractCalcTimer = null;

    const $form = document.getElementById('consignmentForm');
    if (!$form) return;

    const csrfToken = document.getElementById('csrf_token')?.value || '';

    // Current user / role
    const currentUser = JSON.parse(document.getElementById('currentUser')?.textContent || '{"role":"Operator","is_admin":false}');
    const isAdminUser = Boolean(currentUser.is_admin);
    const currentUserRole = currentUser.role || 'Operator';

    function canEditStatus(status) {
        const s = (status || 'Draft').toString();
        if (s === 'Draft' || s === '') return true;
        if (s === 'Submitted') return isAdminUser;
        return false; // Approved or anything else
    }

    function statusLockReason(status) {
        const s = (status || 'Draft').toString();
        if (s === 'Approved') return 'Approved consignment cannot be edited';
        if (s === 'Submitted' && !isAdminUser) return 'Only Admin can edit Submitted consignments';
        return '';
    }

    // Chrome UX: when a number input is focused, mouse-wheel changes the value
    // and the page may appear "not scrolling". Blur focused number inputs on wheel.
    document.addEventListener('wheel', function () {
        const el = document.activeElement;
        if (el && el.tagName === 'INPUT' && el.type === 'number') {
            el.blur();
        }
    }, { passive: true });

    // City data cache
    const allCities = JSON.parse(document.getElementById('citiesData')?.textContent || '[]');
    const statesMap = {};
    JSON.parse(document.getElementById('statesData')?.textContent || '[]').forEach(s => {
        statesMap[s.id] = s.state_code;
    });

    // City select helpers
    function getCitySearchWrap(citySel) {
        if (!citySel) return null;
        return citySel.closest('.city-search-wrap');
    }

    function populateCitySelect(selectEl, stateId, selectedId) {
        const wrap = getCitySearchWrap(selectEl);
        if (wrap && window.CitySearch) {
            if (wrap._citySearch) {
                wrap._citySearch.setCityId(selectedId || '');
            }
            return;
        }

        if (!selectEl || selectEl.tagName !== 'SELECT') return;
        const stateCode = statesMap[stateId] || '';
        selectEl.innerHTML = '<option value="">-- Select City --</option>';
        allCities
            .filter(c => !stateCode || c.state_code === stateCode)
            .forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = (window.CitySearch && CitySearch.cityLabel) ? CitySearch.cityLabel(c) : (c.city_name + ' (' + c.state_code + ')');
                opt.dataset.stateCode = c.state_code;
                if (selectedId && c.id == selectedId) opt.selected = true;
                selectEl.appendChild(opt);
            });
    }

    function bindStateCityPair(stateSel, citySel) {
        if (!stateSel || !citySel) return;
        if (getCitySearchWrap(citySel)) return;
        stateSel.addEventListener('change', function () {
            populateCitySelect(citySel, this.value, null);
            if (stateSel.id.includes('billing') || stateSel.id.includes('consignee')) return;
            triggerTaxCalculation();
        });
    }

    // Bind state-city pairs only for legacy select widgets.
    bindStateCityPair(document.getElementById('consignor_state_id'), document.getElementById('consignor_city_id'));
    bindStateCityPair(document.getElementById('consignee_state_id'), document.getElementById('consignee_city_id'));

    document.getElementById('consignor_city_id')?.addEventListener('change', triggerTaxCalculation);
    document.getElementById('consignee_city_id')?.addEventListener('change', triggerTaxCalculation);

    // Origin/Destination change triggers tax calc
    ['origin_city_id', 'destination_city_id'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', function () { triggerTaxCalculation(); applyClientContract(); });
    });

    // Only active Client Master records can be selected for contract billing.
    const contractClients = JSON.parse(document.getElementById('contractClientsData')?.textContent || '[]')
        .filter(client => client.status === 'Active');

    function findClientByName(name) {
        const normalizedName = String(name || '').trim().toLocaleLowerCase();
        return normalizedName
            ? contractClients.find(client => String(client.client_name || '').trim().toLocaleLowerCase() === normalizedName)
            : null;
    }

    function setContractValue(id, value) {
        const input = document.getElementById(id);
        if (input) input.value = Number(value || 0).toFixed(2);
    }

    function markClientMasterValues() {
        [
            'billing_address_display', 'billing_state_display',
            'billing_city_display', 'billing_pin_display', 'billing_phone_display',
            'billing_gst_display', 'dkt_charge', 'oda_charge', 'fuel_charge',
            'basic_freight', 'risk_charge'
        ].forEach(id => document.getElementById(id)?.classList.add('client-master-value'));
    }

    function clearClientMasterHighlight() {
        document.querySelectorAll('.client-master-value').forEach(element => {
            element.classList.remove('client-master-value');
        });
    }

    function getContractQuantity(basis) {
        const quantityInput = document.getElementById('contract_quantity');
        if (!quantityInput) return 0;

        // KG and Pieces are derived from the consignment. KM is entered by the
        // operator because the form has no distance field to derive it from.
        if (basis === 'kg') {
            const quantity = Number(document.getElementById('charged_weight')?.value || 0);
            quantityInput.value = quantity || '';
            quantityInput.readOnly = true;
            return quantity;
        }
        if (basis === 'pieces') {
            const quantity = Number(document.getElementById('no_of_pieces')?.value || 0);
            quantityInput.value = quantity || '';
            quantityInput.readOnly = true;
            return quantity;
        }

        quantityInput.readOnly = false;
        return Number(quantityInput.value || 0);
    }

    function scheduleClientContractCalculation() {
        clearTimeout(contractCalcTimer);
        contractCalcTimer = setTimeout(applyClientContract, 250);
    }

    function getCurrentClientId() {
        // Resolve from the current Billing Party value. This avoids retaining a
        // previous client's ID after the user changes the party name.
        const partyName = document.getElementById('billing_party_name')?.value || '';
        const matchingClient = findClientByName(partyName);
        return matchingClient ? matchingClient.id : '';
    }

    function displayLaneRates() {
        const display = document.getElementById('laneRatesDisplay');
        if (!display) return;
        
        const clientId = getCurrentClientId();
        const origin = document.getElementById('origin_city_id')?.value || '';
        const destination = document.getElementById('destination_city_id')?.value || '';
        const basis = document.getElementById('contract_billing_basis')?.value || 'kg';
        
        if (!clientId || !origin || !destination) {
            display.innerHTML = '<div class="lane-no-rates">Select client, origin and destination to see applicable rates</div>';
            return;
        }
        
        const quantity = getContractQuantity(basis);
        const value = Number(document.querySelector('input[name="declared_value[]"]')?.value || 0);
        
        const params = new URLSearchParams({ client_id: clientId, origin_city_id: origin, destination_city_id: destination, billing_basis: basis, quantity: quantity, declared_value: value });
        
        fetch(API_BASE + 'get_client_contract.php?' + params.toString())
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.contract) {
                    display.innerHTML = '<div class="lane-no-rates">No lane rate found for this route. Please add lane rates in client master.</div>';
                    return;
                }
                
                const cities = JSON.parse(document.getElementById('citiesData')?.textContent || '[]');
                const originCity = cities.find(c => String(c.id) === String(origin));
                const destCity = cities.find(c => String(c.id) === String(destination));
                
                let rateDisplay = '';
                const kgRate = Number(data.contract.rate_per_kg || 0).toFixed(2);
                const pieceRate = Number(data.contract.rate_per_piece || 0).toFixed(2);
                const kmRate = Number(data.contract.rate_per_km || 0).toFixed(2);
                
                rateDisplay = `
                    <div class="lane-rate-item">
                        <div class="origin-dest">${originCity?.city_name || origin} → ${destCity?.city_name || destination}</div>
                        <div class="rates">
                            <div class="rate">KG: <span>₹${kgRate}</span></div>
                            <div class="rate">Piece: <span>₹${pieceRate}</span></div>
                            <div class="rate">KM: <span>₹${kmRate}</span></div>
                        </div>
                    </div>
                `;
                
                display.innerHTML = rateDisplay;
            })
            .catch(() => {
                display.innerHTML = '<div class="lane-no-rates">Unable to fetch lane rates</div>';
            });
    }

    function applyClientContract() {
        const clientId = getCurrentClientId();
        document.getElementById('client_master_id').value = clientId;
        if (!clientId) return;
        const client = contractClients.find(item => String(item.id) === String(clientId));
        if (client) {
            document.getElementById('billing_party_name').value = client.client_name || '';
            document.getElementById('billing_address').value = client.address || '';
            document.getElementById('billing_address_display').textContent = client.address || '—';
            document.getElementById('billing_state_id').value = client.state_id || '';
            document.getElementById('billing_pin').value = client.pin || '';
            document.getElementById('billing_pin_display').textContent = client.pin || '—';
            if (client.phone) document.getElementById('billing_phone').value = client.phone;
            document.getElementById('billing_phone_display').textContent = client.phone || '—';
            if (client.gst_no) document.getElementById('billing_gst_no').value = client.gst_no;
            document.getElementById('billing_gst_display').textContent = client.gst_no || '—';
            
            const cities = JSON.parse(document.getElementById('citiesData')?.textContent || '[]');
            const states = JSON.parse(document.getElementById('statesData')?.textContent || '[]');
            const city = cities.find(c => String(c.id) === String(client.city_id));
            const state = states.find(s => String(s.id) === String(client.state_id));
            document.getElementById('billing_city_id').value = client.city_id || '';
            document.getElementById('billing_city_display').textContent = city?.city_name || '—';
            document.getElementById('billing_state_display').textContent = state?.state_name || '—';
            
            setContractValue('dkt_charge', client.docket_charge);
            setContractValue('oda_charge', client.oda_charge);
            setContractValue('fuel_charge', client.fuel_charge);
            markClientMasterValues();
        }

        const origin = document.getElementById('origin_city_id')?.value || '';
        const destination = document.getElementById('destination_city_id')?.value || '';
        if (!origin || !destination) { triggerTaxCalculation(); displayLaneRates(); return; }
        const basis = document.getElementById('contract_billing_basis')?.value || 'kg';
        const quantity = getContractQuantity(basis);
        const value = Number(document.querySelector('input[name="declared_value[]"]')?.value || 0);
        const params = new URLSearchParams({ client_id: clientId, origin_city_id: origin, destination_city_id: destination, billing_basis: basis, quantity: quantity, declared_value: value });
        fetch(API_BASE + 'get_client_contract.php?' + params.toString())
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                // Do not wipe an already-entered freight when the new client has
                // no configured lane rate (the API returns 0 in that case).
                // The operator can still enter/adjust freight manually.
                const contractFreight = Number(data.contract.transport_charge || 0);
                if (contractFreight > 0) {
                    setContractValue('basic_freight', contractFreight);
                }
                setContractValue('risk_charge', data.contract.risk_charge);
                triggerTaxCalculation();
                
                // Display lane rate information
                displayLaneRates();
            })
            .catch(() => {
                triggerTaxCalculation();
                displayLaneRates();
            });
    }
    ['charged_weight', 'actual_weight', 'no_of_pieces'].forEach(id => {
        const element = document.getElementById(id);
        element?.addEventListener('input', scheduleClientContractCalculation);
        element?.addEventListener('change', applyClientContract);
    });
    document.getElementById('contract_billing_basis')?.addEventListener('change', function () { applyClientContract(); displayLaneRates(); });
    document.getElementById('contract_quantity')?.addEventListener('input', scheduleClientContractCalculation);
    document.getElementById('contract_quantity')?.addEventListener('change', function () { applyClientContract(); displayLaneRates(); });

    // Party master lookup - populate fields when selection is made
    const partyInputs = [
        document.getElementById('consignor_name'), 
        document.getElementById('billing_party_name'),
        document.getElementById('consignee_name')
    ];
    let partyLookupTimer = null;

    function populatePartyFieldsFromDatalist(input) {
        const datalistId = input.getAttribute('list');
        if (!datalistId) return;
        
        const datalist = document.getElementById(datalistId);
        if (!datalist) return;
        
        const value = input.value.trim();
        if (!value) return;

        // Billing must resolve Client Master first. A billing-party record can
        // have the same name, but it has no contract/lane information.
        if (input.id === 'billing_party_name') {
            const client = findClientByName(value);
            if (client) {
                document.getElementById('client_master_id').value = client.id;
                applyClientContract();
                return;
            }
            document.getElementById('client_master_id').value = '';
            clearClientMasterHighlight();
        }
        
        // Find the matching option in the datalist
        const option = Array.from(datalist.options).find(opt => opt.value === value);
        if (!option) {
            // A pasted/typed name can still be resolved from the JSON master
            // without requiring the operator to click a browser suggestion.
            populatePartyFields(input, value);
            return;
        }
        
        // Extract data attributes
        const data = {
            address: option.dataset.address || '',
            stateId: option.dataset.stateId || '',
            cityId: option.dataset.cityId || '',
            pin: option.dataset.pin || '',
            phone: option.dataset.phone || '',
            gst: option.dataset.gst || '',
            isClient: option.dataset.isClient === 'true',
            clientId: option.dataset.clientId || '',
            docketCharge: option.dataset.docketCharge || '',
            odaCharge: option.dataset.odaCharge || '',
            fuelCharge: option.dataset.fuelCharge || ''
        };
        
        // Determine which field type this is
        const prefix = input.id.replace('_name', '');
        
        if (prefix === 'billing_party') {
            document.getElementById('billing_address').value = data.address;
            document.getElementById('billing_address_display').textContent = data.address || '—';
            document.getElementById('billing_state_id').value = data.stateId;
            document.getElementById('billing_pin').value = data.pin;
            document.getElementById('billing_pin_display').textContent = data.pin || '—';
            document.getElementById('billing_phone').value = data.phone;
            document.getElementById('billing_phone_display').textContent = data.phone || '—';
            document.getElementById('billing_gst_no').value = data.gst;
            document.getElementById('billing_gst_display').textContent = data.gst || '—';
            
            const cities = JSON.parse(document.getElementById('citiesData')?.textContent || '[]');
            const states = JSON.parse(document.getElementById('statesData')?.textContent || '[]');
            const city = cities.find(c => String(c.id) === String(data.cityId));
            const state = states.find(s => String(s.id) === String(data.stateId));
            document.getElementById('billing_city_id').value = data.cityId;
            document.getElementById('billing_city_display').textContent = city?.city_name || '—';
            document.getElementById('billing_state_display').textContent = state?.state_name || '—';
            
            if (data.isClient && data.clientId) {
                document.getElementById('client_master_id').value = data.clientId;
                setContractValue('dkt_charge', data.docketCharge);
                setContractValue('oda_charge', data.odaCharge);
                setContractValue('fuel_charge', data.fuelCharge);
                applyClientContract();
            }
        } else {
            // Consignor or Consignee
            const addressEl = document.getElementById(prefix + '_address');
            const stateIdEl = document.getElementById(prefix + '_state_id');
            const cityIdEl = document.getElementById(prefix + '_city_id');
            const pinEl = document.getElementById(prefix + '_pin');
            const phoneEl = document.getElementById(prefix + '_phone');
            const gstEl = document.getElementById(prefix + '_gst_no');
            
            if (addressEl) addressEl.value = data.address;
            if (stateIdEl) stateIdEl.value = data.stateId;
            if (pinEl) pinEl.value = data.pin;
            if (phoneEl) phoneEl.value = data.phone;
            if (gstEl) gstEl.value = data.gst;
            
            if (cityIdEl && stateIdEl) {
                populateCitySelect(cityIdEl, stateIdEl.value, data.cityId);
            }
        }
    }

    function populatePartyFields(input, partyName) {
        if (!partyName) return;
        
        // Search in billing parties first
        const billingParties = JSON.parse(document.getElementById('partyMastersData')?.textContent || '[]');
        const party = billingParties.find(p => p.party_name.toLowerCase() === partyName.toLowerCase());
        
        if (party) {
            // Determine which field type this is
            const prefix = input.id.replace('_name', '');
            
            if (prefix === 'billing_party') {
                document.getElementById('billing_address').value = party.address || '';
                document.getElementById('billing_address_display').textContent = party.address || '—';
                document.getElementById('billing_state_id').value = party.state_id || '';
                document.getElementById('billing_pin').value = party.pin || '';
                document.getElementById('billing_pin_display').textContent = party.pin || '—';
                document.getElementById('billing_phone').value = party.phone || '';
                document.getElementById('billing_phone_display').textContent = party.phone || '—';
                document.getElementById('billing_gst_no').value = party.gst_no || '';
                document.getElementById('billing_gst_display').textContent = party.gst_no || '—';
                
                const cities = JSON.parse(document.getElementById('citiesData')?.textContent || '[]');
                const states = JSON.parse(document.getElementById('statesData')?.textContent || '[]');
                const city = cities.find(c => String(c.id) === String(party.city_id));
                const state = states.find(s => String(s.id) === String(party.state_id));
                document.getElementById('billing_city_id').value = party.city_id || '';
                document.getElementById('billing_city_display').textContent = city?.city_name || '—';
                document.getElementById('billing_state_display').textContent = state?.state_name || '—';
            } else {
                // Consignor or Consignee
                const addressEl = document.getElementById(prefix + '_address');
                const stateIdEl = document.getElementById(prefix + '_state_id');
                const cityIdEl = document.getElementById(prefix + '_city_id');
                const pinEl = document.getElementById(prefix + '_pin');
                const phoneEl = document.getElementById(prefix + '_phone');
                const gstEl = document.getElementById(prefix + '_gst_no');
                
                if (addressEl) addressEl.value = party.address || '';
                if (stateIdEl) stateIdEl.value = party.state_id || '';
                if (pinEl) pinEl.value = party.pin || '';
                if (phoneEl) phoneEl.value = party.phone || '';
                if (gstEl) gstEl.value = party.gst_no || '';
                
                if (cityIdEl && stateIdEl) {
                    populateCitySelect(cityIdEl, stateIdEl.value, party.city_id);
                }
            }
            return;
        }
        
        // If not found in billing parties, check client masters
        const client = contractClients.find(c => c.client_name.toLowerCase() === partyName.toLowerCase());
        if (client) {
            const prefix = input.id.replace('_name', '');
            
            if (prefix === 'billing_party') {
                document.getElementById('billing_address').value = client.address || '';
                document.getElementById('billing_address_display').textContent = client.address || '—';
                document.getElementById('billing_state_id').value = client.state_id || '';
                document.getElementById('billing_pin').value = client.pin || '';
                document.getElementById('billing_pin_display').textContent = client.pin || '—';
                document.getElementById('billing_phone').value = client.phone || '';
                document.getElementById('billing_phone_display').textContent = client.phone || '—';
                document.getElementById('billing_gst_no').value = client.gst_no || '';
                document.getElementById('billing_gst_display').textContent = client.gst_no || '';
                
                const cities = JSON.parse(document.getElementById('citiesData')?.textContent || '[]');
                const states = JSON.parse(document.getElementById('statesData')?.textContent || '[]');
                const city = cities.find(c => String(c.id) === String(client.city_id));
                const state = states.find(s => String(s.id) === String(client.state_id));
                document.getElementById('billing_city_id').value = client.city_id || '';
                document.getElementById('billing_city_display').textContent = city?.city_name || '—';
                document.getElementById('billing_state_display').textContent = state?.state_name || '—';
                
                setContractValue('dkt_charge', client.docket_charge);
                setContractValue('oda_charge', client.oda_charge);
                setContractValue('fuel_charge', client.fuel_charge);
                
                document.getElementById('client_master_id').value = client.id;
                applyClientContract();
            } else {
                // Consignor or Consignee from client master
                const addressEl = document.getElementById(prefix + '_address');
                const stateIdEl = document.getElementById(prefix + '_state_id');
                const cityIdEl = document.getElementById(prefix + '_city_id');
                const pinEl = document.getElementById(prefix + '_pin');
                const phoneEl = document.getElementById(prefix + '_phone');
                const gstEl = document.getElementById(prefix + '_gst_no');
                
                if (addressEl) addressEl.value = client.address || '';
                if (stateIdEl) stateIdEl.value = client.state_id || '';
                if (pinEl) pinEl.value = client.pin || '';
                if (phoneEl) phoneEl.value = client.phone || '';
                if (gstEl) gstEl.value = client.gst_no || '';
                
                if (cityIdEl && stateIdEl) {
                    populateCitySelect(cityIdEl, stateIdEl.value, client.city_id);
                }
            }
        }
    }

    partyInputs.forEach(input => {
        if (!input) return;
        input.addEventListener('change', function() {
            populatePartyFieldsFromDatalist(this);
        });
        input.addEventListener('input', function () {
            const field = this;
            clearTimeout(partyLookupTimer);
            partyLookupTimer = setTimeout(() => {
                const name = field.value.trim();
                if (name.length >= 2) populatePartyFields(field, name);
            }, 180);
        });
    });

    // Explicit Client Master picker: particularly useful when editing a docket
    // from the Billing popup, where a datalist selection is easy to miss.
    document.getElementById('billing_client_picker')?.addEventListener('change', function () {
        const client = contractClients.find(item => String(item.id) === String(this.value));
        if (!client) return;
        const billingInput = document.getElementById('billing_party_name');
        if (!billingInput) return;
        billingInput.value = client.client_name || '';
        document.getElementById('client_master_id').value = client.id;
        applyClientContract();
    });

    // Clear an old contract link as soon as a different Billing Party is typed.
    // The complete client profile is then loaded on datalist selection/change.
    document.getElementById('billing_party_name')?.addEventListener('input', function () {
        const selectedClient = findClientByName(this.value);
        const clientId = document.getElementById('client_master_id');
        if (!selectedClient) {
            if (clientId) clientId.value = '';
            clearClientMasterHighlight();
        }
    });

    function saveClientMaster(targetType, buttonEl) {
        const partyNameInput = targetType === 'consignee'
            ? document.getElementById('consignee_name')
            : document.getElementById('billing_party_name');

        if (!partyNameInput || !partyNameInput.value.trim()) {
            showToast('Party name is required / पार्टी का नाम आवश्यक है', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('party_type', targetType);
        formData.append('party_name', partyNameInput.value.trim());
        formData.append('address', targetType === 'consignee'
            ? document.getElementById('consignee_address')?.value || ''
            : document.getElementById('billing_address')?.value || '');
        formData.append('city_id', targetType === 'consignee'
            ? document.getElementById('consignee_city_id')?.value || 0
            : document.getElementById('billing_city_id')?.value || 0);
        formData.append('state_id', targetType === 'consignee'
            ? document.getElementById('consignee_state_id')?.value || 0
            : document.getElementById('billing_state_id')?.value || 0);
        formData.append('pin', targetType === 'consignee'
            ? document.getElementById('consignee_pin')?.value || ''
            : document.getElementById('billing_pin')?.value || '');
        formData.append('phone', targetType === 'consignee'
            ? document.getElementById('consignee_phone')?.value || ''
            : document.getElementById('billing_phone')?.value || '');
        formData.append('gst_no', targetType === 'consignee'
            ? document.getElementById('consignee_gst_no')?.value || ''
            : document.getElementById('billing_gst_no')?.value || '');

        if (buttonEl) buttonEl.disabled = true;

        fetch(API_BASE + 'save_client_master.php', {
            method: 'POST',
            body: formData
        })
            .then(r => r.json())
            .then(data => showToast(data.message, data.success ? 'success' : 'error'))
            .catch(() => showToast('Unable to save client / क्लाइंट सहेजने में असमर्थ', 'error'))
            .finally(() => {
                if (buttonEl) buttonEl.disabled = false;
            });
    }

    function applySelectedParty(option, sourceInput) {
        // This function is no longer used - replaced by populatePartyFields
    }

    function findMatchingOption(value, listEl) {
        // This function is no longer needed since we use JSON data directly
        return null;
    }

    // Removed old event listeners that used findMatchingOption

    document.getElementById('saveClientMasterBtn')?.addEventListener('click', function () {
        saveClientMaster('billing', this);
    });

    document.getElementById('saveconsignorMasterBtn')?.addEventListener('click', function () {
        const nameInput = document.getElementById('consignor_name');
        if (!nameInput || !nameInput.value.trim()) {
            showToast('Consignor name is required / कन्साइनर नाम आवश्यक है', 'error');
            return;
        }
        const formData = new FormData();
        formData.append('party_type', 'consignor');
        formData.append('party_name', nameInput.value.trim());
        formData.append('address', document.getElementById('consignor_address')?.value || '');
        formData.append('city_id', document.getElementById('consignor_city_id')?.value || 0);
        formData.append('state_id', document.getElementById('consignor_state_id')?.value || 0);
        formData.append('pin', document.getElementById('consignor_pin')?.value || '');
        formData.append('phone', document.getElementById('consignor_phone')?.value || '');
        formData.append('gst_no', document.getElementById('consignor_gst_no')?.value || '');

        const btn = this;
        btn.disabled = true;
        fetch(API_BASE + 'save_client_master.php', {
            method: 'POST',
            body: formData
        })
            .then(r => r.json())
            .then(data => showToast(data.message, data.success ? 'success' : 'error'))
            .catch(() => showToast('Unable to save consignor / कन्साइनर सहेजने में असमर्थ', 'error'))
            .finally(() => { btn.disabled = false; });
    });

    document.getElementById('saveConsigneeMasterBtn')?.addEventListener('click', function () {
        saveClientMaster('consignee', this);
    });

    // Consignment note uniqueness check
    const noteInput = document.getElementById('consignment_note');
    const noteStatus = document.getElementById('noteStatus');

    if (noteInput) {
        noteInput.addEventListener('input', function () {
            clearTimeout(noteCheckTimer);
            const note = this.value.trim();
            if (note.length < 2) {
                noteStatus.textContent = '';
                noteStatus.className = 'note-status';
                return;
            }
            noteStatus.textContent = 'Checking...';
            noteStatus.className = 'note-status checking';
            noteCheckTimer = setTimeout(() => checkConsignmentNote(note), 400);
        });
    }

    function checkConsignmentNote(note) {
        const excludeId = document.getElementById('consignment_id')?.value || 0;
        fetch(API_BASE + 'check_consignment_note.php?note=' + encodeURIComponent(note) + '&exclude_id=' + excludeId)
            .then(r => r.json())
            .then(data => {
                if (data.available) {
                    noteStatus.textContent = '✓ Available / उपलब्ध';
                    noteStatus.className = 'note-status available';
                    noteInput.classList.remove('is-invalid');
                } else {
                    noteStatus.textContent = '✗ Already exists / पहले से मौजूद';
                    noteStatus.className = 'note-status taken';
                    noteInput.classList.add('is-invalid');
                }
            })
            .catch(() => {
                noteStatus.textContent = '';
            });
    }

    // Charge fields - auto calculate
    const chargeFields = [
        'basic_freight', 'fuel_charge', 'dkt_charge', 'handling_charge',
        'oda_charge', 'detention', 'misc_charge', 'other_charge', 'risk_charge'
    ];

    chargeFields.forEach(field => {
        const el = document.getElementById(field);
        if (el) {
            el.addEventListener('input', triggerTaxCalculation);
            el.addEventListener('change', triggerTaxCalculation);
        }
    });

    function triggerTaxCalculation() {
        clearTimeout(calcTimer);
        calcTimer = setTimeout(calculateTaxes, 300);
    }

    function calculateTaxes() {
        const params = new URLSearchParams();
        chargeFields.forEach(f => {
            params.append(f, document.getElementById(f)?.value || 0);
        });
        params.append('origin_city_id', document.getElementById('origin_city_id')?.value || 0);
        params.append('destination_city_id', document.getElementById('destination_city_id')?.value || 0);
        params.append('gst_rate', document.getElementById('gst_rate')?.value || 18);

        fetch(API_BASE + 'calculate_taxes.php?' + params.toString())
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    setVal('sgst', data.sgst.toFixed(2));
                    setVal('cgst', data.cgst.toFixed(2));
                    setVal('igst', data.igst.toFixed(2));
                    document.getElementById('displayCgst').textContent = '₹ ' + formatNumber(data.cgst);
                    document.getElementById('displaySgst').textContent = '₹ ' + formatNumber(data.sgst);
                    document.getElementById('displayIgst').textContent = '₹ ' + formatNumber(data.igst);
                    setVal('grand_total', data.grand_total.toFixed(2));
                    document.getElementById('amount_words').value = data.amount_words || '';
                    document.getElementById('displayGrandTotal').textContent = '₹ ' + formatNumber(data.grand_total);
                    document.getElementById('displayAmountWords').textContent = data.amount_words || '';
                    document.getElementById('taxTypeLabel').textContent = data.is_intra_state
                        ? 'Intra-State (CGST + SGST ' + (data.gst_rate / 2) + '% each)'
                        : 'Inter-State (IGST ' + data.gst_rate + '%)';
                }
            });
    }

    function setVal(id, val) {
        const el = document.getElementById(id);
        if (el) el.value = val;
    }

    function formatNumber(n) {
        return parseFloat(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updateInvoiceSummary() {
        const rows = document.querySelectorAll('.invoice-row');
        const totalValue = Array.from(rows).reduce((sum, row) => {
            const value = parseFloat(row.querySelector('input[name="declared_value[]"]')?.value);
            return sum + (Number.isFinite(value) ? value : 0);
        }, 0);

        const totalEl = document.getElementById('invoiceTotalValue');
        if (totalEl) totalEl.textContent = '₹ ' + formatNumber(totalValue);

        const countEl = document.getElementById('invoiceRowCount');
        if (countEl) countEl.textContent = rows.length;
    }

    function isVolumetricWeightEnabled() {
        return document.getElementById('charge_weight_mode')?.value === 'Auto';
    }

    function updateChargedWeight(totalVolume) {
        if (!isVolumetricWeightEnabled()) return;
        const actualWeight = parseFloat(document.getElementById('actual_weight')?.value) || 0;
        const charged = document.getElementById('charged_weight');
        if (charged) charged.value = Math.max(actualWeight, totalVolume).toFixed(2);
    }

    function syncChargedWeightMode() {
        const charged = document.getElementById('charged_weight');
        if (!charged) return;
        charged.readOnly = isVolumetricWeightEnabled();
        calculateVolume();
    }

    function setWeightMode(mode) {
        const automatic = mode === 'auto';
        const modeInput = document.getElementById('charge_weight_mode');
        if (modeInput) modeInput.value = automatic ? 'Auto' : 'Manual';
        document.querySelectorAll('[data-weight-mode]').forEach(button => {
            const active = button.dataset.weightMode === mode;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        syncChargedWeightMode();
    }

    function restoreDimensionRows(value) {
        const rows = String(value || '').split('|').map(part => {
            const match = part.trim().match(/^(\d+(?:\.\d+)?)x(\d+(?:\.\d+)?)x(\d+(?:\.\d+)?)$/i);
            return match ? [match[1], match[2], match[3]] : null;
        }).filter(Boolean);
        if (!rows.length) return;

        const container = document.getElementById('dimensionRows');
        const template = container?.querySelector('.dimension-row');
        if (!container || !template) return;
        container.innerHTML = '';

        rows.forEach((values, index) => {
            const row = template.cloneNode(true);
            const inputs = row.querySelectorAll('.dimension-input');
            inputs.forEach((input, inputIndex) => input.value = values[inputIndex]);
            const button = row.querySelector('.add-dimension-row');
            if (index > 0) {
                button.textContent = '-';
                button.classList.replace('btn-outline-primary', 'btn-outline-danger');
            }
            container.appendChild(row);
        });
    }

    // Volume calculation
    function calculateVolume() {
        const rows = document.querySelectorAll('.dimension-row');
        let totalVolume = 0;

        rows.forEach(row => {
            const l = parseFloat(row.querySelector('.dimension-input:nth-of-type(1)')?.value) || 0;
            const w = parseFloat(row.querySelector('.dimension-input:nth-of-type(2)')?.value) || 0;
            const h = parseFloat(row.querySelector('.dimension-input:nth-of-type(3)')?.value) || 0;
            const rowVolume = (l > 0 && w > 0 && h > 0) ? l * w * h : 0;
            totalVolume += rowVolume;

            const display = row.querySelector('.dimension-volume-value');
            if (display) {
                display.value = rowVolume > 0 ? rowVolume.toFixed(2) : '';
            }
        });

        const volumeInput = document.getElementById('volume_lxwxh');
        if (volumeInput) volumeInput.value = totalVolume > 0 ? totalVolume.toFixed(2) : '';

        const totalEl = document.getElementById('dimensionTotalVolume');
        if (totalEl) totalEl.textContent = formatNumber(totalVolume);

        const countEl = document.getElementById('dimensionRowCount');
        if (countEl) countEl.textContent = rows.length;

        updateChargedWeight(totalVolume);
        scheduleClientContractCalculation();
    }

    document.addEventListener('input', function (event) {
        if (event.target.classList.contains('dimension-input')) {
            calculateVolume();
        }
        if (event.target.classList.contains('invoice-input')) {
            updateInvoiceSummary();
            // Risk charge is contract-based and depends on declared value.
            if (event.target.name === 'declared_value[]') applyClientContract();
        }
    });

    document.addEventListener('click', function (event) {
        if (event.target.classList.contains('add-dimension-row')) {
            const button = event.target.closest('.add-dimension-row');
            if (!button) return;

            if (button.textContent === '-') {
                const row = button.closest('.dimension-row');
                if (row && document.querySelectorAll('.dimension-row').length > 1) {
                    row.remove();
                    calculateVolume();
                }
                return;
            }

            const container = document.getElementById('dimensionRows');
            const row = button.closest('.dimension-row');
            const clone = row.cloneNode(true);
            clone.querySelectorAll('input').forEach(input => input.value = '');
            const newButton = clone.querySelector('.add-dimension-row');
            newButton.textContent = '-';
            newButton.classList.remove('btn-outline-primary');
            newButton.classList.add('btn-outline-danger');
            container.appendChild(clone);
            calculateVolume();
        }

        if (event.target.classList.contains('add-invoice-row')) {
            const button = event.target.closest('.add-invoice-row');
            if (!button) return;

            if (button.textContent === '-') {
                const row = button.closest('.invoice-row');
                if (row && document.querySelectorAll('.invoice-row').length > 1) {
                    row.remove();
                }
                return;
            }

            const container = document.getElementById('invoiceRows');
            const row = button.closest('.invoice-row');
            const clone = row.cloneNode(true);
            clone.querySelectorAll('input').forEach(input => input.value = '');
            const newButton = clone.querySelector('.add-invoice-row');
            newButton.textContent = '-';
            newButton.classList.remove('btn-outline-primary');
            newButton.classList.add('btn-outline-danger');
            container.appendChild(clone);
            updateInvoiceSummary();
        }
    });

    document.getElementById('actual_weight')?.addEventListener('input', calculateVolume);
    document.getElementById('gst_rate')?.addEventListener('change', calculateTaxes);
    document.getElementById('editGstMaster')?.addEventListener('click', function () {
        const rateSelect = document.getElementById('gst_rate');
        const selected = rateSelect?.selectedOptions[0];
        if (!rateSelect || !selected) return;
        const newRate = prompt('Edit GST rate (%)', rateSelect.value);
        if (newRate === null || newRate.trim() === '') return;
        const formData = new FormData();
        formData.append('id', selected.dataset.id || '0');
        formData.append('rate', newRate.trim());
        fetch(API_BASE + 'save_gst_master.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    showToast(data.message || 'Unable to update GST master', 'error');
                    return;
                }
                Array.from(rateSelect.options).forEach(option => {
                    if (option !== selected && option.value === String(data.rate)) option.remove();
                });
                selected.value = data.rate;
                selected.textContent = data.rate + '%';
                rateSelect.value = data.rate;
                calculateTaxes();
                showToast(data.message, 'success');
            })
            .catch(() => showToast('Unable to update GST master', 'error'));
    });
    document.querySelectorAll('[data-weight-mode]').forEach(button => {
        button.addEventListener('click', () => setWeightMode(button.dataset.weightMode));
    });

    // Form submission
    function submitForm(action) {
        clearErrors();
        document.getElementById('form_action').value = action;

        const formData = new FormData($form);
        formData.set('action', action);
        formData.set('status', action === 'submit' ? 'Submitted' : 'Draft');

        showLoading(true);

        fetch(API_BASE + 'save_consignment.php', {
            method: 'POST',
            body: formData
        })
            .then(r => r.json())
            .then(data => {
                showLoading(false);
                if (data.success) {
                    showToast(data.message, 'success');
                    if (data.consignment_id) {
                        document.getElementById('consignment_id').value = data.consignment_id;
                    }
                    if (action === 'submit') {
                        setReadOnly(true);
                    }
                } else {
                    showToast(data.message, 'error');
                    if (data.errors) {
                        highlightErrors(data.errors);
                        const firstError = Object.values(data.errors)[0];
                        if (firstError) showToast(String(firstError), 'error');
                    }
                }
            })
            .catch(err => {
                showLoading(false);
                showToast('Network error / नेटवर्क त्रुटि', 'error');
                console.error(err);
            });
    }

    document.getElementById('btnSaveDraft')?.addEventListener('click', () => submitForm('draft'));
    document.getElementById('btnSubmit')?.addEventListener('click', () => submitForm('submit'));

    document.getElementById('btnReset')?.addEventListener('click', function () {
        if (confirm('Reset form? / फॉर्म रीसेट करें?')) {
            $form.reset();
            clearErrors();
            setReadOnly(false);
            document.getElementById('consignment_id').value = '';
            document.getElementById('booking_date').value = new Date().toISOString().split('T')[0];
            setWeightMode('auto');
            calculateTaxes();
            noteStatus.textContent = '';
        }
    });

    document.getElementById('btnPrint')?.addEventListener('click', () => window.print());

    // Restore dimensions for records opened through the page's edit URL.
    restoreDimensionRows(document.getElementById('volume_lxwxh')?.value);
    setWeightMode(isVolumetricWeightEnabled() ? 'auto' : 'manual');
    updateInvoiceSummary();

    // Template autofill
    document.getElementById('templateSelect')?.addEventListener('change', function () {
        const id = this.value;
        if (!id) return;
        fetch('api/get_template.php?id=' + id)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.consignment) {
                    fillFormFromData(data.consignment);
                    showToast('Template loaded / टेम्पलेट लोड', 'info');
                }
            });
    });

    function fillFormFromData(d) {
        setWeightMode(d.charge_weight_mode === 'Manual' ? 'manual' : 'auto');
        const skip = ['id', 'consignment_note', 'status', 'created_at', 'updated_at', 'created_by'];
        Object.keys(d).forEach(key => {
            if (skip.includes(key)) return;
            const el = document.getElementById(key);
            if (el) el.value = d[key] ?? '';
        });

        restoreDimensionRows(d.volume_lxwxh);
        calculateVolume();

        const invoiceRows = [];
        if (d.party_invoice_no) {
            const parts = String(d.party_invoice_no).split('|').filter(Boolean);
            parts.forEach(part => {
                const [invoiceNo, amount = ''] = part.split(':');
                invoiceRows.push({ invoice_no: invoiceNo || '', declared_value: amount || '' });
            });
        }

        if (invoiceRows.length > 0) {
            renderInvoiceRows(invoiceRows);
        } else {
            renderInvoiceRows([{ invoice_no: '', declared_value: '' }]);
        }

        // Re-populate city dropdowns for consignee
        populateCitySelect(document.getElementById('consignee_city_id'), d.consignee_state_id, d.consignee_city_id);
        
        // Update billing display fields
        document.getElementById('billing_address').value = d.billing_address || '';
        document.getElementById('billing_address_display').textContent = d.billing_address || '—';
        document.getElementById('billing_state_id').value = d.billing_state_id || '';
        document.getElementById('billing_pin').value = d.billing_pin || '';
        document.getElementById('billing_pin_display').textContent = d.billing_pin || '—';
        document.getElementById('billing_phone').value = d.billing_phone || '';
        document.getElementById('billing_phone_display').textContent = d.billing_phone || '—';
        document.getElementById('billing_gst_no').value = d.billing_gst_no || '';
        document.getElementById('billing_gst_display').textContent = d.billing_gst_no || '—';
        document.getElementById('billing_city_id').value = d.billing_city_id || '';
        
        const cities = JSON.parse(document.getElementById('citiesData')?.textContent || '[]');
        const states = JSON.parse(document.getElementById('statesData')?.textContent || '[]');
        const city = cities.find(c => String(c.id) === String(d.billing_city_id));
        const state = states.find(s => String(s.id) === String(d.billing_state_id));
        document.getElementById('billing_city_display').textContent = city?.city_name || '—';
        document.getElementById('billing_state_display').textContent = state?.state_name || '—';
        
        calculateTaxes();
    }

    function renderInvoiceRows(rows) {
        const container = document.getElementById('invoiceRows');
        if (!container) return;

        container.innerHTML = '';
        const normalizedRows = rows && rows.length ? rows : [{ invoice_no: '', declared_value: '' }];

        normalizedRows.forEach((row, index) => {
            const rowEl = document.createElement('div');
            rowEl.className = 'invoice-row';
            rowEl.innerHTML = `
                <input type="text" name="party_invoice_no[]" class="form-control invoice-input" value="${(row.invoice_no || '').replace(/"/g, '&quot;')}">
                <input type="number" name="declared_value[]" class="form-control invoice-input" step="0.01" min="0" value="${(row.declared_value || '').toString().replace(/"/g, '&quot;')}">
                <button type="button" class="btn btn-outline-${index === 0 ? 'primary' : 'danger'} btn-sm add-invoice-row">${index === 0 ? '+' : '-'}</button>
            `;
            container.appendChild(rowEl);
        });

        updateInvoiceSummary();
    }

    function clearErrors() {
        $form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        $form.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
    }

    function highlightErrors(errors) {
        Object.keys(errors).forEach(field => {
            const el = document.getElementById(field);
            if (el) {
                el.classList.add('is-invalid');
                const fb = el.parentElement.querySelector('.invalid-feedback');
                if (fb) fb.textContent = errors[field];
            }
        });
    }

    function setReadOnly(readonly) {
        if (readonly) {
            $form.classList.add('readonly');
            document.getElementById('btnSubmit').disabled = true;
            document.getElementById('btnSaveDraft').disabled = true;
        } else {
            $form.classList.remove('readonly');
            document.getElementById('btnSubmit').disabled = false;
            document.getElementById('btnSaveDraft').disabled = false;
        }
        document.querySelectorAll('[data-weight-mode]').forEach(button => button.disabled = readonly);
    }

    function showToast(message, type) {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = 'toast-msg ' + type;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }

    function showLoading(show) {
        document.getElementById('btnSaveDraft').disabled = show;
        document.getElementById('btnSubmit').disabled = show;
    }

    // Section collapse toggle
    document.querySelectorAll('.section-title').forEach(title => {
        title.addEventListener('click', function () {
            const content = this.nextElementSibling;
            if (content) {
                content.style.display = content.style.display === 'none' ? '' : 'none';
            }
        });
    });

    // Init
    document.getElementById('booking_date').value = document.getElementById('booking_date').value
        || new Date().toISOString().split('T')[0];
    calculateTaxes();

    // If editing existing record, apply role-based read-only lock
    (function applyInitialLock() {
        if (document.getElementById('billing_locked')?.value === '1') {
            setReadOnly(true);
            showToast('This docket is already billed and locked for editing.', 'info');
            return;
        }
        const statusInput = document.getElementById('form_status');
        const status = statusInput?.value || '';
        if (!status) return;
        if (!canEditStatus(status)) {
            setReadOnly(true);
        }
    })();

    // ============================================================
    // SEARCH CONSIGNMENT
    // ============================================================
    const searchInput = document.getElementById('searchConsignmentInput');
    const searchBtn = document.getElementById('searchConsignmentBtn');
    const searchDropdown = document.getElementById('searchResultsDropdown');
    let searchTimer = null;

    if (searchInput && searchBtn && searchDropdown) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            const q = this.value.trim();
            if (q.length < 2) {
                hideSearchDropdown();
                return;
            }
            showSearchLoading();
            searchTimer = setTimeout(() => runSearch(q), 350);
        });

        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const q = this.value.trim();
                if (q.length > 0) {
                    showSearchLoading();
                    runSearch(q);
                }
            }
            if (e.key === 'Escape') {
                hideSearchDropdown();
            }
        });

        searchBtn.addEventListener('click', function () {
            const q = searchInput.value.trim();
            if (q.length === 0) {
                showToast('Enter consignment number to search / कन्साइनमेंट नंबर दर्ज करें', 'info');
                searchInput.focus();
                return;
            }
            showSearchLoading();
            runSearch(q);
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.search-box')) {
                hideSearchDropdown();
            }
        });
    }

    function showSearchLoading() {
        if (!searchDropdown) return;
        searchDropdown.innerHTML = '<div class="search-loading">Searching... / खोज रहा है...</div>';
        searchDropdown.classList.add('show');
    }

    function hideSearchDropdown() {
        if (searchDropdown) {
            searchDropdown.classList.remove('show');
        }
    }

    function runSearch(q) {
        fetch(API_BASE + 'search_consignment.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                renderSearchResults(data.results || []);
            })
            .catch(err => {
                console.error(err);
                if (searchDropdown) {
                    searchDropdown.innerHTML = '<div class="search-no-results">Error searching / खोज में त्रुटि</div>';
                    searchDropdown.classList.add('show');
                }
            });
    }

    function renderSearchResults(results) {
        if (!searchDropdown) return;

        if (!results || results.length === 0) {
            searchDropdown.innerHTML = '<div class="search-no-results">No consignments found / कोई कन्साइनमेंट नहीं मिला</div>';
            searchDropdown.classList.add('show');
            return;
        }

        let html = '';
        results.forEach(r => {
            const total = r.grand_total ? '₹ ' + parseFloat(r.grand_total).toLocaleString('en-IN', { minimumFractionDigits: 2 }) : '-';
            const date = r.booking_date ? new Date(r.booking_date).toLocaleDateString('en-IN') : '-';
            const canEdit = canEditStatus(r.status);
            const lockReason = statusLockReason(r.status);
            const statusLabel = htmlEscape(r.status || 'Draft');
            const btnLabel = canEdit ? '✏️ Edit' : '🔒 View';
            const btnVariant = canEdit ? 'primary' : (r.status === 'Approved' ? 'secondary' : 'warning');
            const btnDisabled = canEdit ? '' : 'disabled';
            const btnTitle = canEdit
                ? 'Edit / संपादन करें'
                : (lockReason + ' (Role: ' + htmlEscape(currentUserRole) + ')');
            html += `
                <div class="search-result-item" data-id="${r.id}" data-note="${(r.consignment_note || '').replace(/"/g, '&quot;')}" data-status="${statusLabel}">
                    <div class="search-result-row">
                        <div class="search-result-main">
                            <div class="search-result-header">
                                <span class="search-result-note">${htmlEscape(r.consignment_note)}</span>
                                <span class="search-result-status ${statusLabel}">${statusLabel}</span>
                            </div>
                            <div class="search-result-details">
                                <div><strong>Billing:</strong> ${htmlEscape(r.billing_party_name || '-')}</div>
                                <div><strong>Consignee:</strong> ${htmlEscape(r.consignee_name || '-')}</div>
                            </div>
                            <div class="search-result-meta">
                                <span>Date: ${date}</span>
                                <span>Total: ${total}</span>
                            </div>
                        </div>
                        <div class="search-result-actions">
                            <button type="button" class="btn btn-${btnVariant} btn-sm search-edit-btn" ${btnDisabled} title="${htmlEscape(btnTitle)}">
                                ${btnLabel}
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
        searchDropdown.innerHTML = html;
        searchDropdown.classList.add('show');

        searchDropdown.querySelectorAll('.search-edit-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (this.disabled) return;
                const item = this.closest('.search-result-item');
                if (!item) return;
                const id = parseInt(item.dataset.id, 10);
                const note = item.dataset.note || '';
                hideSearchDropdown();
                loadConsignmentForEdit(id, note);
            });
        });
    }

    function loadConsignmentForEdit(id, note) {
        const params = new URLSearchParams();
        if (id > 0) {
            params.append('id', id);
        } else if (note) {
            params.append('note', note);
        } else {
            showToast('Invalid consignment / अमान्य', 'error');
            return;
        }

        showToast('Loading... / लोड हो रहा है...', 'info');
        fetch(API_BASE + 'get_consignment.php?' + params.toString())
            .then(r => r.json())
            .then(data => {
                if (data.success && data.consignment) {
                    editFormFromData(data.consignment);
                    showToast('Consignment loaded for edit / संपादन के लिए लोड किया गया', 'success');
                } else {
                    showToast(data.message || 'Not found / नहीं मिला', 'error');
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Network error / नेटवर्क त्रुटि', 'error');
            });
    }

    function editFormFromData(d) {
        clearErrors();
        setReadOnly(false);
        setWeightMode(d.charge_weight_mode === 'Manual' ? 'manual' : 'auto');

        Object.keys(d).forEach(key => {
            if (key === 'created_at' || key === 'updated_at' || key === 'created_by') return;
            const el = document.getElementById(key);
            if (el && (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA')) {
                el.value = d[key] ?? '';
            }
        });

        document.getElementById('consignment_id').value = d.id ?? '';
        document.getElementById('form_status').value = d.status ?? '';

        const invoiceRows = [];
        if (d.party_invoice_no) {
            const parts = String(d.party_invoice_no).split('|').filter(Boolean);
            parts.forEach(part => {
                const [invoiceNo, amount = ''] = part.split(':');
                invoiceRows.push({ invoice_no: invoiceNo || '', declared_value: amount || '' });
            });
        }

        if (invoiceRows.length > 0) {
            renderInvoiceRows(invoiceRows);
        } else {
            renderInvoiceRows([{ invoice_no: '', declared_value: '' }]);
        }

        populateCitySelect(document.getElementById('consignor_city_id'), d.consignor_state_id, d.consignor_city_id);
        populateCitySelect(document.getElementById('consignee_city_id'), d.consignee_state_id, d.consignee_city_id);
        
        // Update billing display fields
        document.getElementById('billing_address_display').textContent = d.billing_address || '—';
        document.getElementById('billing_pin_display').textContent = d.billing_pin || '—';
        document.getElementById('billing_phone_display').textContent = d.billing_phone || '—';
        document.getElementById('billing_gst_display').textContent = d.billing_gst_no || '—';
        
        const cities = JSON.parse(document.getElementById('citiesData')?.textContent || '[]');
        const states = JSON.parse(document.getElementById('statesData')?.textContent || '[]');
        const city = cities.find(c => String(c.id) === String(d.billing_city_id));
        const state = states.find(s => String(s.id) === String(d.billing_state_id));
        document.getElementById('billing_city_display').textContent = city?.city_name || '—';
        document.getElementById('billing_state_display').textContent = state?.state_name || '—';

        restoreDimensionRows(d.volume_lxwxh);
        calculateVolume();
        calculateTaxes();

        if (!canEditStatus(d.status)) {
            setReadOnly(true);
            const reason = statusLockReason(d.status) || 'Locked';
            showToast(reason + ' (Role: ' + currentUserRole + ')', 'info');
        } else if ((d.status || 'Draft') !== 'Draft' && (d.status || 'Draft') !== '') {
            // Submitted and editable = Admin. Show a banner toast.
            showToast('Editing a ' + d.status + ' consignment (Admin override) / ' + d.status + ' कन्साइनमेंट संपादित कर रहे हैं', 'info');
        }
    }

    function htmlEscape(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
})();
