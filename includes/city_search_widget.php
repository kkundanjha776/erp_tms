<?php

require_once __DIR__ . '/city_data.php';

function renderCitySearchField(array $options): void
{
    $fieldId = (string) ($options['field_id'] ?? 'city_id');
    $fieldName = (string) ($options['name'] ?? $fieldId);
    $label = (string) ($options['label'] ?? 'City');
    $required = !empty($options['required']);
    $hideLabel = !empty($options['hide_label']);
    $stateFieldId = (string) ($options['state_field_id'] ?? '');
    $pinFieldId = (string) ($options['pin_field_id'] ?? '');
    $placeholder = (string) ($options['placeholder'] ?? 'Search PIN, area, or district');
    $selectedCity = $options['selected_city'] ?? null;
    $selectedValue = isset($options['selected_value']) ? (int) $options['selected_value'] : (is_array($selectedCity) ? (int) ($selectedCity['id'] ?? 0) : 0);
    $selectedLabel = '';
    if (is_array($selectedCity)) {
        $selectedLabel = htmlspecialchars($selectedCity['display_label'] ?? formatCityLabel($selectedCity), ENT_QUOTES, 'UTF-8');
    }
    ?>
    <div class="form-group city-search-wrap"
         data-city-field="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"
         <?= $stateFieldId !== '' ? 'data-state-field="' . htmlspecialchars($stateFieldId, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
         <?= $pinFieldId !== '' ? 'data-pin-field="' . htmlspecialchars($pinFieldId, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
        <?php if (!$hideLabel): ?>
        <label><?= htmlspecialchars($label) ?><?= $required ? ' <span class="req">*</span>' : '' ?></label>
        <?php endif; ?>
        <input type="text"
               class="form-control city-search-input"
               value="<?= $selectedLabel ?>"
               placeholder="<?= htmlspecialchars($placeholder) ?>"
               autocomplete="off"
               <?= $required ? 'data-required="1"' : '' ?>>
        <input type="hidden"
               id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"
               name="<?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?>"
               value="<?= $selectedValue > 0 ? $selectedValue : '' ?>">
        <div class="city-search-dropdown"></div>
        <div class="invalid-feedback"></div>
    </div>
    <?php
}

function findCityInMaster(array $cities, ?int $cityId): ?array
{
    if ($cityId === null || $cityId <= 0) {
        return null;
    }

    foreach ($cities as $city) {
        if ((int) ($city['id'] ?? 0) === $cityId) {
            return $city;
        }
    }

    return null;
}
