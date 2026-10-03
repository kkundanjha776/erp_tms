# Edit Invoice - Exact Clone of Billing Page

## Overview
Edit invoice page has been completely rebuilt to be an exact clone of the billing page (new invoice creation) with identical search, docket table, and all features. The only difference is the ability to add/remove dockets from the existing invoice.

## Exact Clone Features

### 1. **Same Hero Section**
- **Billing Page**: "Invoice desk, made for fast dispatch billing"
- **Edit Page**: "Edit Invoice: [Number] - Modify invoice details, search for additional dockets, add/remove dockets, then save changes."
- **KPI Cards**: Dockets count and Taxable amount (same style)
- **Gradient Background**: Identical linear gradient

### 2. **Same Search Filters**
**Exact Copy of Billing Page Search**:
- **Booking from**: Date picker
- **Booking to**: Date picker
- **Client / billing party**: Dropdown with client code
- **POD uploaded only**: Checkbox filter
- **Search dockets**: Button
- **Same Grid Layout**: 4-column filter grid

### 3. **Current Dockets Section**
**New Feature - Shows Existing Invoice Dockets**:
- Green background box (#f0faf8)
- Scrollable table (max-height: 200px)
- Same table columns as billing page:
  - Checkbox (checked by default)
  - Docket / Booking
  - Client
  - Consignee
  - POD status
  - Freight
- All dockets pre-checked (currently in invoice)
- Uncheck to remove dockets

### 4. **Same Form Layout**
**Identical to Billing Page**:
- **4-column grid**: Same spacing and sizing
- **Invoice Number**: Disabled (read-only)
- **Invoice Date**: Editable
- **Billing Party**: Disabled (read-only)
- **Billing Party GST**: Disabled (read-only)
- **GST Treatment**: CGST+SGST / IGST buttons
- **GST Rate %**: Number input
- **Remarks**: Text input
- **Temp Hold**: Checkbox (extra feature)

### 5. **Same Available Dockets Table**
**Exact Copy of Billing Page Docket Table**:
- **Search Results**: Shows dockets from search
- **Same Columns**:
  - Checkbox (for adding)
  - Docket / Booking
  - Client
  - Billing party
  - Consignee
  - POD status
  - Freight
  - Action (Edit docket button)
- **Select All**: Checkbox to select all shown
- **Same Styling**: Identical table design

### 6. **Same Sticky Summary**
**Exact Copy of Billing Page Summary**:
- **Fixed Position**: Sticks to bottom
- **Same Sections**:
  - Selected dockets count
  - Taxable freight
  - GST amount (CGST+SGST or IGST)
  - Invoice total
- **Button**: "Update Invoice →" (instead of "Create invoice →")
- **Same Styling**: Dark background, white text

### 7. **Same Calculations**
**Identical to Billing Page**:
- **Live Updates**: Real-time calculation on any change
- **GST Logic**: Same CGST+SGST / IGST calculations
- **Total Logic**: freight + tax = grand total
- **KPI Updates**: Hero section updates live

### 8. **Same JavaScript**
**Copy of Billing Page JS**:
- **check.onchange**: Triggers calculation
- **selectAll.onchange**: Selects all checkboxes
- **gstRate.oninput**: Updates calculations
- **calc() function**: Same calculation logic
- **money() function**: Same formatting

## Key Differences from Billing Page

### 1. **Current Dockets Section**
- **Billing Page**: None (new invoice)
- **Edit Page**: Shows existing dockets in invoice
- **Purpose**: See and remove current dockets

### 2. **Invoice Number**
- **Billing Page**: Auto-generated or manual input
- **Edit Page**: Disabled, shows existing number

### 3. **Billing Party**
- **Billing Page**: Dropdown selection
- **Edit Page**: Disabled, shows existing party

### 4. **Temp Hold Checkbox**
- **Billing Page**: None
- **Edit Page**: Extra checkbox for 0-docket invoices

### 5. **Submit Button**
- **Billing Page**: "Create invoice →"
- **Edit Page**: "Update Invoice →"

### 6. **Search Exclusion**
- **Billing Page**: Shows all unbilled dockets
- **Edit Page**: Excludes current invoice dockets from search

## User Flow

### Edit Invoice Flow:
1. **View Current Dockets**: See all dockets currently in invoice (pre-checked)
2. **Remove Dockets**: Uncheck dockets you want to remove
3. **Search for More**: Use same search filters as billing page
4. **Add Dockets**: Check dockets from search results
5. **Modify Details**: Change date, GST, remarks as needed
6. **Temp Hold**: Optional - mark as 0-docket invoice
7. **Update**: Click "Update Invoice →" to save

### Comparison with New Invoice Flow:
- **New Invoice**: Search → Select dockets → Set details → Create
- **Edit Invoice**: View current → Remove/Add → Modify details → Update

## Technical Implementation

### Search Query
```php
// Same as billing.php but excludes current invoice dockets
$where = ["c.basic_freight > 0", "ii.id IS NULL"];
// ... filter conditions ...

// Exclude current invoice dockets
if (!empty($currentDocketIds)) {
    $placeholders = implode(',', array_fill(0, count($currentDocketIds), '?'));
    $where[] = "c.id NOT IN ($placeholders)";
    $types .= str_repeat('i', count($currentDocketIds));
    $params = array_merge($params, $currentDocketIds);
}
```

### Current Dockets Display
```php
// Fetch current invoice dockets
$currentStmt = $conn->prepare("SELECT c.id,c.consignment_note,c.booking_date,c.billing_party_name,c.consignee_name,c.basic_freight,EXISTS(SELECT 1 FROM pod_files p WHERE p.consignment_id=c.id) pod_ready,cm.client_code,cm.client_name FROM consignments c LEFT JOIN client_masters cm ON c.client_master_id=cm.id WHERE c.id IN ($placeholders)");
```

### Form Processing
```php
// Combine current and newly selected dockets
$selectedDockets = $_POST['docket_ids'] ?? [];

// Remove all existing items
DELETE FROM invoice_items WHERE invoice_id = ?

// Add selected dockets
INSERT INTO invoice_items (invoice_id, consignment_id, docket_no, booking_date, freight_amount)

// Recalculate and update
UPDATE invoices SET invoice_date=?, gst_type=?, gst_rate=?, taxable_amount=?, cgst_amount=?, sgst_amount=?, igst_amount=?, grand_total=?, remarks=?, submission_status=?
```

## Visual Comparison

### Billing Page Elements:
- Hero section with KPI
- Search filters (4-column grid)
- Docket table with checkboxes
- Form details (4-column grid)
- Sticky summary bar
- "Create invoice →" button

### Edit Page Elements:
- Hero section with KPI (same)
- Search filters (same 4-column grid)
- **Current dockets section** (NEW)
- Docket table with checkboxes (same)
- Form details (same 4-column grid)
- **Temp Hold checkbox** (NEW)
- Sticky summary bar (same)
- "Update Invoice →" button

## Benefits

### Familiarity
- **Same Interface**: Users familiar with billing page feel at home
- **Same Workflow**: Identical search and selection process
- **Same Look**: Exact visual design
- **Same Feel**: Same interactions and animations

### Flexibility
- **Add Dockets**: Search and add new dockets
- **Remove Dockets**: Uncheck current dockets
- **Modify Details**: Change date, GST, remarks
- **Temp Hold**: Save as 0-docket invoice

### Efficiency
- **Live Calculations**: Real-time totals
- **Quick Search**: Same powerful search
- **Bulk Operations**: Select all functionality
- **One-Click**: Easy additions/removals

## File Changes

### Modified Files
- **edit_invoice.php**: Complete rewrite to match billing.php

### Key Changes
1. Copied billing.php CSS styles
2. Implemented identical search filters
3. Added current dockets section
4. Copied docket table design
5. Implemented same form layout
6. Copied JavaScript calculations
7. Added Temp Hold checkbox
8. Excluded current dockets from search
9. Same sticky summary bar
10. Same visual design

## Testing Checklist

### Functionality
- [ ] Current dockets display correctly
- [ ] Search filters work (same as billing)
- [ ] Add dockets from search results
- [ ] Remove dockets (uncheck current)
- [ ] Select all works
- [ ] Live calculations update
- [ ] GST type switching works
- [ ] Temp Hold checkbox works
- [ ] Form submits correctly
- [ ] Invoice updates successfully

### Visual
- [ ] Hero section matches billing page
- [ ] Search filters match billing page
- [ ] Current dockets section displays
- [ ] Docket table matches billing page
- [ ] Form layout matches billing page
- [ ] Summary bar matches billing page
- [ ] Colors and spacing match
- [ ] Responsive design works

### Integration
- [ ] Excludes current dockets from search
- [ ] Adds newly selected dockets
- [ ] Removes unchecked dockets
- [ ] Recalculates totals correctly
- [ ] Updates invoice status
- [ ] Handles Temp Hold

## Summary

The edit invoice page is now an exact clone of the billing page with:
- **Same Search**: Identical filter and search functionality
- **Same Table**: Same docket table design and columns
- **Same Layout**: Identical form layout and styling
- **Same Calculations**: Same live calculation logic
- **Same Feel**: Identical user experience
- **Extra Feature**: Current dockets section for removal
- **Extra Feature**: Temp Hold checkbox
- **Extra Feature**: Excludes current dockets from search

Users now have the exact same experience as creating a new invoice, with the added ability to manage existing dockets in the invoice.