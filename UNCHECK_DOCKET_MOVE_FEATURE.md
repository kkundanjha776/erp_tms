# Uncheck Docket - Move to Available Section Feature

## Overview
When you uncheck a docket from the "Current Dockets" section, it automatically moves to the "Available Dockets" section below, allowing you to re-add it later or see it with the Edit docket button.

## How It Works

### 1. **Uncheck Current Docket**
- Go to "Current Dockets in Invoice" section
- Uncheck any docket
- Page automatically reloads
- Docket moves from Current section to Available section

### 2. **Visual Indicators**
**Removed Dockets in Available Section**:
- Yellow background (#fef3c7)
- "Removed" badge (orange)
- Checkbox unchecked by default
- Edit docket button available
- Same details as other available dockets

### 3. **Re-adding Removed Dockets**
- Find the removed docket in Available section
- Check the checkbox
- Update invoice
- Docket moves back to Current section

### 4. **URL Parameter Tracking**
- URL includes `unchecked` parameter
- Format: `edit_invoice.php?id=1&unchecked=123,456,789`
- Tracks which dockets have been unchecked
- Persists across page reloads

## Technical Implementation

### 1. **PHP Logic**
```php
// Track unchecked dockets from URL
$uncheckedDocketIds = [];
if (isset($_GET['unchecked'])) {
    $uncheckedDocketIds = array_map('intval', explode(',', $_GET['unchecked']));
}

// Build query - exclude checked current dockets, include unchecked ones
$excludedDocketIds = array_diff($currentDocketIds, $uncheckedDocketIds);
if (!empty($excludedDocketIds)) {
    $where[] = "c.id NOT IN ($placeholders)";
}

// Add unchecked dockets to search results
if (!empty($uncheckedDocketIds)) {
    $uncheckedStmt = $conn->prepare("SELECT ... WHERE c.id IN ($placeholders)");
    $dockets = array_merge($dockets, $uncheckedDockets);
}
```

### 2. **Current Dockets Display**
```php
// Only show checked dockets in current section
$checkedDocketIds = array_diff($currentDocketIds, $uncheckedDocketIds);
// Display checked dockets only
```

### 3. **Available Dockets Display**
```php
// Display with visual indicator for removed dockets
foreach($dockets as $d): ?>
    <tr <?= in_array($d['id'], $uncheckedDocketIds) ? 'style="background:#fef3c7"' : '' ?>>
        <td><input class="docket-check" type="checkbox" 
            <?= in_array($d['id'], $uncheckedDocketIds) ? 'checked' : '' ?>></td>
        <td>
            <b><?= $d['consignment_note'] ?></b>
            <?php if(in_array($d['id'], $uncheckedDocketIds)): ?>
                <span style="background:#f59e0b">Removed</span>
            <?php endif; ?>
        </td>
        // ... other columns
    </tr>
<?php endforeach; ?>
```

### 4. **JavaScript Handling**
```javascript
// Track unchecked dockets
const currentDocketChecks = [...document.querySelectorAll('.docket-check.current-docket')];
const uncheckedCurrentDockets = new Set();

// Handle checkbox changes
currentDocketChecks.forEach(cb => {
    cb.addEventListener('change', function() {
        if (!this.checked) {
            uncheckedCurrentDockets.add(this.value);
        } else {
            uncheckedCurrentDockets.delete(this.value);
        }
        // Reload with updated unchecked list
        const url = new URL(window.location);
        if(uncheckedCurrentDockets.size > 0) {
            url.searchParams.set('unchecked', Array.from(uncheckedCurrentDockets).join(','));
        } else {
            url.searchParams.delete('unchecked');
        }
        window.location.href = url.toString();
    });
});
```

## User Flow

### Remove Docket from Invoice:
1. Go to Current Dockets section
2. Uncheck the docket you want to remove
3. Page reloads automatically
4. Docket moves to Available section with yellow background
5. "Removed" badge shows on the docket
6. Edit docket button is available

### Re-add Removed Docket:
1. Find the removed docket in Available section (yellow background)
2. Check the checkbox
3. Click "Update Invoice →"
4. Docket moves back to Current section
5. URL parameter `unchecked` is updated

### Clear All Removed Dockets:
1. Check all removed dockets in Available section
2. Click "Update Invoice →"
3. All dockets move back to Current section
4. URL parameter `unchecked` is removed

## Visual Design

### Current Dockets Section
- **Green background** (#f0faf8)
- Shows only checked dockets
- Count excludes unchecked dockets
- Same table columns as before

### Available Dockets Section
- **Normal dockets**: White background
- **Removed dockets**: Yellow background (#fef3c7)
- **Removed badge**: Orange (#f59e0b)
- **Checkbox**: Unchecked for removed, unchecked for new
- **Edit button**: Available for all

### KPI Updates
- **Hero Count**: Shows checked dockets only
- **Selected Count**: Shows checked dockets only
- Updates automatically when dockets unchecked

## Benefits

### Flexibility
- **Easy Removal**: One-click to remove dockets
- **Visual Tracking**: Yellow background shows removed dockets
- **Re-add Capability**: Easy to add back removed dockets
- **Edit Access**: Can edit removed dockets before re-adding

### User Experience
- **Clear Indication**: Removed dockets clearly marked
- **No Confusion**: Easy to distinguish removed vs new
- **Quick Actions**: One-click to remove/re-add
- **Persistent State**: URL parameter tracks state

### Data Integrity
- **No Data Loss**: Dockets not deleted, just moved
- **Reversible**: Can always add back
- **Tracking**: Complete audit via URL
- **Safe**: Original invoice not affected until update

## Edge Cases

### All Dockets Unchecked
- Current section shows "No dockets in invoice"
- All dockets in Available section (yellow)
- Can re-add any or all
- URL parameter includes all IDs

### Re-check Removed Docket
- Docket moves back to Current section
- URL parameter updated (removes that ID)
- Yellow background removed
- Counts updated

### Mixed State
- Some dockets in Current (checked)
- Some dockets in Available (yellow - removed)
- Some dockets in Available (white - new)
- All can be managed independently

## URL Examples

### No Dockets Removed
```
edit_invoice.php?id=1
```

### One Docket Removed
```
edit_invoice.php?id=1&unchecked=123
```

### Multiple Dockets Removed
```
edit_invoice.php?id=1&unchecked=123,456,789
```

### After Re-adding
```
edit_invoice.php?id=1&unchecked=456,789
```

## Summary

This feature provides:
- **One-click removal**: Uncheck to remove from invoice
- **Visual tracking**: Yellow background for removed dockets
- **Easy re-adding**: Check to add back
- **Edit access**: Edit removed dockets anytime
- **Persistent state**: URL parameter tracks removals
- **Clear UI**: Distinguish removed vs new dockets

Users can now easily remove dockets from current invoice and see them in the available section with clear visual indicators, making it simple to manage invoice composition.