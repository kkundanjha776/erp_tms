/**
 * Searchable city picker — PIN, area, district.
 */
(function () {
    'use strict';

    const API_BASE = 'api/';
    let searchTimer = null;

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getStatesMap() {
        const map = {};
        JSON.parse(document.getElementById('statesData')?.textContent || '[]').forEach(state => {
            map[state.id] = state.state_code;
        });
        return map;
    }

    function cityLabel(city) {
        if (!city) return '';
        if (city.display_label) return city.display_label;
        let label = city.city_name || '';
        if (city.pincode) label += ' (' + city.pincode + ')';
        if (city.district && label.toLowerCase().indexOf(String(city.district).toLowerCase()) === -1) {
            label += ' - ' + city.district;
        }
        return label;
    }

    function hideDropdown(dropdown) {
        if (dropdown) dropdown.classList.remove('show');
    }

    function renderResults(dropdown, cities, onSelect) {
        if (!dropdown) return;
        if (!cities.length) {
            dropdown.innerHTML = '<div class="city-search-empty">No matching locations</div>';
            dropdown.classList.add('show');
            return;
        }

        dropdown.innerHTML = cities.map(city => {
            const meta = [city.district, city.zone_region].filter(Boolean).join(' · ');
            const area = city.area ? '<div class="city-search-area">' + escapeHtml(city.area) + '</div>' : '';
            return '<button type="button" class="city-search-item" data-id="' + city.id + '">' +
                '<div class="city-search-title">' + escapeHtml(cityLabel(city)) + '</div>' +
                (meta ? '<div class="city-search-meta">' + escapeHtml(meta) + '</div>' : '') +
                area +
                '</button>';
        }).join('');
        dropdown.classList.add('show');

        dropdown.querySelectorAll('.city-search-item').forEach(item => {
            item.addEventListener('mousedown', function (event) {
                event.preventDefault();
                const selected = cities.find(city => String(city.id) === String(this.dataset.id));
                if (selected) onSelect(selected);
            });
        });
    }

    function fetchCityById(cityId) {
        const cached = JSON.parse(document.getElementById('citiesData')?.textContent || '[]')
            .find(city => String(city.id) === String(cityId));
        if (cached) {
            return Promise.resolve(cached);
        }
        return fetch(API_BASE + 'get_cities.php?q=' + encodeURIComponent(String(cityId)) + '&limit=1')
            .then(r => r.json())
            .then(data => (data.cities && data.cities[0]) ? data.cities[0] : null)
            .catch(() => null);
    }

    function initWrap(wrap) {
        const input = wrap.querySelector('.city-search-input');
        const hidden = wrap.querySelector('input[type="hidden"]');
        const dropdown = wrap.querySelector('.city-search-dropdown');
        const stateFieldId = wrap.dataset.stateField || '';
        const pinFieldId = wrap.dataset.pinField || '';
        const statesMap = getStatesMap();

        if (!input || !hidden || !dropdown) return;

        function getStateCode() {
            if (!stateFieldId) return '';
            const stateSelect = document.getElementById(stateFieldId);
            return statesMap[stateSelect?.value || ''] || '';
        }

        function applySelection(city) {
            hidden.value = city.id;
            input.value = cityLabel(city);
            hideDropdown(dropdown);
            input.classList.remove('is-invalid');

            if (pinFieldId) {
                const pinInput = document.getElementById(pinFieldId);
                if (pinInput && city.pincode) {
                    pinInput.value = city.pincode;
                }
            }

            hidden.dispatchEvent(new Event('change', { bubbles: true }));
            wrap.dispatchEvent(new CustomEvent('city:selected', { detail: city, bubbles: true }));
        }

        function runSearch() {
            const query = input.value.trim();
            if (query.length < 1) {
                hideDropdown(dropdown);
                return;
            }

            const params = new URLSearchParams({ q: query, limit: '20' });
            const stateCode = getStateCode();
            if (stateCode) params.set('state_code', stateCode);

            fetch(API_BASE + 'search_cities.php?' + params.toString())
                .then(r => r.json())
                .then(data => renderResults(dropdown, data.cities || [], applySelection))
                .catch(() => {
                    dropdown.innerHTML = '<div class="city-search-empty">Unable to search cities</div>';
                    dropdown.classList.add('show');
                });
        }

        input.addEventListener('input', function () {
            if (hidden.value && input.value !== cityLabel({ id: hidden.value })) {
                hidden.value = '';
            }
            clearTimeout(searchTimer);
            searchTimer = setTimeout(runSearch, 220);
        });

        input.addEventListener('focus', function () {
            if (input.value.trim().length >= 1) runSearch();
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') hideDropdown(dropdown);
        });

        if (stateFieldId) {
            const stateSelect = document.getElementById(stateFieldId);
            stateSelect?.addEventListener('change', function () {
                hidden.value = '';
                input.value = '';
                hideDropdown(dropdown);
                if (pinFieldId) {
                    const pinInput = document.getElementById(pinFieldId);
                    if (pinInput) pinInput.value = '';
                }
                hidden.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        document.addEventListener('click', function (event) {
            if (!wrap.contains(event.target)) hideDropdown(dropdown);
        });

        wrap._citySearch = {
            setCityId(cityId) {
                if (!cityId) {
                    hidden.value = '';
                    input.value = '';
                    return Promise.resolve();
                }
                return fetchCityById(cityId).then(city => {
                    if (city) applySelection(city);
                });
            },
            clear() {
                hidden.value = '';
                input.value = '';
                hideDropdown(dropdown);
            },
            getCityId() {
                return hidden.value || '';
            }
        };

        if (hidden.value) {
            fetchCityById(hidden.value).then(city => {
                if (city) input.value = cityLabel(city);
            });
        }
    }

    function initAll(root) {
        (root || document).querySelectorAll('.city-search-wrap').forEach(initWrap);
    }

    window.CitySearch = {
        initAll,
        initWrap,
        cityLabel
    };

    document.addEventListener('DOMContentLoaded', function () {
        initAll(document);
    });
})();
