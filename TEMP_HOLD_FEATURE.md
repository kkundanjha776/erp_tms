# Temp Hold Invoice Feature - Complete Guide

## Overview
Temp Hold feature allows you to save invoices with 0 dockets for later editing. This addresses the requirement to remove all dockets and mark invoice as temporary hold.

## New Features Added

### 1. **Temp Hold Status**
**New Status**: Added "Temp Hold" to invoice submission status enum
- **Status Options**: Pending, Temp Hold, Submitted, Cancelled
- **Database Schema**: Updated `submission_status` ENUM to include 'Temp Hold'
- **Auto Migration**: Existing installations automatically updated

### 2. **Temp Hold Checkbox in Edit Invoice**
**Location**: Edit Invoice Page (`edit_invoice.php`)

**Features**:
- **Checkbox**: "Mark as Temp Hold (0 docket invoice)" option
- **Auto-deselect**: When Temp Hold checked, all dockets automatically unchecked
- **Status Update**: Invoice status changes to "Temp Hold"
- **Zero Amounts**: Taxable amount and totals set to 0
- **Later Editing**: Temp Hold invoices can be edited to add dockets

### 3. **Enhanced Validation**
**Validation Rules**:
- **No docket check**: Removed requirement for minimum 1 docket
- **Temp Hold exception**: Allows 0 dockets when Temp Hold is checked
- **Error message**: "At least one docket must be selected for the invoice, or mark as Temp Hold."

### 4. **UI Improvements**
**Enhanced UI Elements**:
- **Temp Hold checkbox**: Clear checkbox with explanation
- **Warning message**: Explains Temp Hold functionality
- **Auto-deselect behavior**: JavaScript automatically unchecks dockets
- **Live count update**: Shows 0 dockets when Temp Hold selected

### 5. **Invoice Management Updates**
**Management Page Updates**:
- **Status Filter**: Added "Temp Hold" to status dropdown
- **Statistics**: Added Temp Hold count card
- **Status Display**: Yellow badge for Temp Hold invoices
- **Edit Access**: Temp Hold invoices can be edited
- **Submission info**: Shows "0 Dockets - Temp Hold" for Temp Hold invoices

## How to Use Temp Hold

### Create Temp Hold Invoice

#### Method 1: From Existing Invoice
1. Go to Invoice Management
2. Find pending invoice to convert to Temp Hold
3. Click ✏️ (Edit) button
4. Uncheck all current dockets
5. Check "Mark as Temp Hold" checkbox
6. Click "Update Invoice"
7. Invoice marked as Temp Hold with 0 dockets

#### Method 2: From Edit Invoice
1. Edit any pending invoice
2. Remove all dockets (uncheck all)
3. Check "Mark as Temp Hold" checkbox
4. Update invoice
5. Invoice saved as Temp Hold

### Edit Temp Hold Invoice

1. Go to Invoice Management
2. Find Temp Hold invoice
3. Click ✏️ (Edit) button
4. Uncheck "Mark as Temp Hold" checkbox
5. Select desired dockets from available list
6. Update invoice
7. Invoice converted back to Pending with selected dockets

### Filter Temp Hold Invoices

1. Go to Invoice Management
2. In Status dropdown, select "Temp Hold"
3. Click "Filter"
4. View all Temp Hold invoices
5. Edit them to add dockets

## Technical Implementation

### Database Schema Changes

**Updated ENUM**:
```sql
submission_status ENUM('Pending','Temp Hold','Submitted','Cancelled')
```

**Migration Logic**:
```php
// Check if Temp Hold exists in ENUM
if (strpos($enumType, 'Temp Hold') === false) {
    $conn->query("ALTER TABLE invoices MODIFY COLUMN submission_status ENUM('Pending','Temp Hold','Submitted','Cancelled')");
}
```

### Form Processing Changes

**Temp Hold Handling**:
```php
$tempHold = isset($_POST['temp_hold']) ? 1 : 0;
$selectedDockets = $_POST['docket_ids'] ?? [];

if (empty($selectedDockets) && !$tempHold) {
    // Error: At least one docket OR Temp Hold required
}

if ($tempHold) {
    $statusValue = 'Temp Hold';
    $totalFreight = 0;
    $taxableAmount = 0;
    $grandTotal = 0;
} else {
    $statusValue = 'Pending';
    // Normal docket processing
}
```

### JavaScript Enhancements

**Auto-deselect Behavior**:
```javascript
const tempHoldCheckbox = document.getElementById('tempHold');
if(tempHoldCheckbox) {
    tempHoldCheckbox.addEventListener('change', function() {
        if(this.checked) {
            // Disable all docket checkboxes when temp hold is checked
            checkboxes.forEach(cb => cb.checked = false);
            updateDocketCount();
        }
    });
}
```

## Invoice Status Workflow

### Enhanced Workflow
```
Created → Pending → Temp Hold → Pending → Submitted
              ↓                              ↓
           Cancelled                    Cancelled
```

**Status Rules**:
- **Pending**: Can edit, submit, cancel, delete, mark as Temp Hold
- **Temp Hold**: Can edit, add dockets, submit, cancel, delete
- **Submitted**: Cannot edit, cancel, or delete
- **Cancelled**: Cannot edit, submit, or delete

## Use Cases

### Use Case 1: Hold Invoice for Later
1. Create invoice with initial dockets
2. Need to put on hold for client approval
3. Remove all dockets
4. Mark as Temp Hold
5. Later: Edit, add final dockets, submit

### Use Case 2: Preliminary Invoice Creation
1. Client wants invoice number now
2. But dockets not finalized
3. Create invoice with 0 dockets
4. Mark as Temp Hold
5. Get invoice number to client
6. Later: Add actual dockets

### Use Case 3: Invoice Revision Process
1. Client sends invoice back for changes
2. Remove all dockets from current invoice
3. Mark as Temp Hold to preserve invoice number
4. Add revised dockets later
5. Submit final version

### Use Case 4: Batch Processing
1. Create multiple Temp Hold invoices for batch
2. Get invoice numbers for client
3. Add dockets to each as they become available
4. Submit individually when ready

## Benefits

### Flexibility
- **Preserve invoice numbers**: Keep invoice numbers even without dockets
- **Later editing**: Add dockets when ready
- **Client communication**: Provide invoice numbers to clients early
- **Workflow control**: Hold invoices pending approval

### Efficiency
- **No recreation**: Preserve invoice number, don't cancel/recreate
- **Quick hold**: One-click Temp Hold functionality
- **Easy resumption**: Edit and add dockets when ready
- **Status tracking**: Clear Temp Hold status in management

### Accuracy
- **Zero amounts**: Properly handles 0 freight and totals
- **Status clarity**: Clear distinction from regular pending invoices
- **Audit trail**: Temp Hold status tracked in system
- **Validation**: Prevents data inconsistencies

## File Changes

### Modified Files
1. **edit_invoice.php**: 
   - Added Temp Hold checkbox
   - Enhanced validation logic
   - JavaScript auto-deselect behavior
   - Updated edit permission for Temp Hold

2. **includes/master_data.php**:
   - Updated ENUM with 'Temp Hold'
   - Added migration logic for existing installations

3. **invoice_management.php**:
   - Added Temp Hold to status filter
   - Added Temp Hold statistics card
   - Updated status display
   - Enhanced edit permissions

## Error Messages

### New Error Messages
- **No docket without Temp Hold**: "At least one docket must be selected for the invoice, or mark as Temp Hold."
- **Delete restriction**: "Cannot delete submitted or temp hold invoices. Cancel or add dockets first."

### Success Messages
- **Temp Hold created**: "Invoice marked as Temp Hold with 0 dockets. You can add dockets later."
- **Regular update**: "Invoice updated successfully with X dockets."

## Troubleshooting

### Temp Hold Not Available
- **Issue**: Temp Hold checkbox not showing
- **Solution**: Check database migration completed, contact admin

### Status Not Updating
- **Issue**: Invoice still shows Pending after Temp Hold
- **Solution**: Refresh page, check database ENUM update

### Edit Not Working
- **Issue**: Cannot edit Temp Hold invoice
- **Solution**: Check user permissions, verify status is Temp Hold

## Best Practices

### When to Use Temp Hold
- **Client approval needed**: Hold invoice pending client approval
- **Dockets not ready**: Create invoice number but dockets pending
- **Invoice revision**: Remove dockets, hold, add revised dockets
- **Batch processing**: Create multiple Temp Hold invoices for efficiency

### When NOT to Use Temp Hold
- **Final invoice**: Directly submit when ready
- **Simple dockets**: Add dockets normally if available
- **Urgent submission**: Don't hold if submission needed immediately

## Future Enhancements

Potential improvements:
- **Auto-unhold**: Automatically unhold after X days
- **Hold expiry**: Set expiry date for Temp Hold
- **Hold reasons**: Add reason field for Temp Hold
- **Bulk actions**: Bulk convert to Temp Hold
- **Hold alerts**: Notifications for Temp Hold invoices

## Summary

The Temp Hold feature provides invoice flexibility:
- **0 Docket Invoices**: Save invoices without dockets
- **Preserve Numbers**: Keep invoice numbers for later use
- **Easy Resumption**: Edit and add dockets when ready
- **Status Tracking**: Clear Temp Hold status management
- **Validation**: Proper validation for safety

Users can now remove all dockets and mark invoices as Temp Hold, addressing the requirement to create placeholder invoices that can be completed later.