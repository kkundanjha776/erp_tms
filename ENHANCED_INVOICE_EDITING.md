# Enhanced Invoice Editing - Complete Docket Management

## Overview
Invoice editing page has been enhanced to allow full docket management - add, remove, and modify dockets directly in the edit page without needing to cancel and recreate invoices.

## New Features Added

### 1. **Docket Selection Management**
**Location**: Edit Invoice Page (`edit_invoice.php`)

**Features**:
- **Current Dockets Section**: Shows all dockets currently in invoice
- **Remove Dockets**: Uncheck dockets to remove them from invoice
- **Available Dockets Section**: Shows available dockets for same billing party
- **Add Dockets**: Check available dockets to add them to invoice
- **Real-time Count**: Live docket count updates as you select/deselect

### 2. **Enhanced Backend Processing**
**Changes in Form Handling**:
- **Remove existing items**: Deletes all current invoice items
- **Add selected items**: Adds newly selected dockets
- **Recalculate amounts**: Auto-recalculates taxes and totals
- **Validate consistency**: Ensures all dockets belong to same billing party
- **Zero freight check**: Prevents adding dockets with zero freight

### 3. **Available Dockets Display**
**Features**:
- Shows dockets not yet billed for same billing party
- Displays docket details: number, date, route, freight
- Limited to 100 most recent dockets for performance
- Only shows dockets with freight > 0

### 4. **User Interface Improvements**
**Enhanced UI Elements**:
- **Checkbox selection**: Easy check/uncheck interface
- **Scrollable lists**: Both current and available dockets in scrollable areas
- **Color-coded sections**: Blue for current, green for available
- **Live count display**: Shows total selected dockets
- **Clear instructions**: Guidance on how to add/remove dockets

## How to Use Enhanced Invoice Editing

### Edit Invoice with Docket Changes

1. **Navigate to Invoice Management**
   - Go to Operations → Invoice Management
   - Find pending invoice to edit
   - Click ✏️ (Edit) button

2. **Modify Dockets**
   - **Remove Dockets**: Uncheck in "Current Dockets" section
   - **Add Dockets**: Check in "Available Dockets to Add" section
   - **Watch Count**: Live count shows total selected dockets

3. **Update Other Details**
   - **Invoice Date**: Change if needed
   - **GST Type**: Switch between CGST+SGST / IGST
   - **GST Rate**: Adjust tax rate
   - **Remarks**: Add or modify notes

4. **Save Changes**
   - Click "Update Invoice" button
   - System validates selections
   - Invoice recalculated automatically
   - Success message with docket count

### Use Cases

#### Use Case 1: Remove Some Dockets
1. Edit invoice with 10 dockets
2. Uncheck 3 dockets from current list
3. Click "Update Invoice"
4. Invoice now has 7 dockets with recalculated totals

#### Use Case 2: Add More Dockets
1. Edit invoice with 5 dockets
2. Check 3 new dockets from available list
3. Click "Update Invoice"
4. Invoice now has 8 dockets with recalculated totals

#### Use Case 3: Replace All Dockets
1. Edit invoice with old dockets
2. Uncheck all current dockets
3. Check all new dockets from available list
4. Click "Update Invoice"
5. Invoice completely replaced with new dockets

#### Use Case 4: Empty and Rebuild
1. Edit invoice
2. Uncheck all current dockets (0 selected)
3. Add new dockets from available list
4. Click "Update Invoice"
5. Invoice rebuilt with new dockets

## Technical Implementation

### Backend Changes

**Form Processing**:
```php
// Remove existing invoice items
DELETE FROM invoice_items WHERE invoice_id = ?

// Get selected dockets details
SELECT * FROM consignments WHERE id IN (selected_ids)

// Validate dockets
- Same billing party check
- Zero freight check
- Existence check

// Add new invoice items
INSERT INTO invoice_items (invoice_id, consignment_id, docket_no, booking_date, freight_amount)

// Recalculate amounts
- Sum freight from selected dockets
- Calculate taxes based on GST type/rate
- Update invoice totals
```

**Available Dockets Query**:
```php
SELECT c.id, c.consignment_note, c.booking_date, c.basic_freight, c.consignee_name,
       o.city_name as origin_city, d.city_name as destination_city
FROM consignments c
LEFT JOIN cities o ON c.origin_city_id = o.id
LEFT JOIN cities d ON c.destination_city_id = d.id
WHERE c.billing_party_name = ? 
AND c.basic_freight > 0
AND c.id NOT IN (SELECT consignment_id FROM invoice_items)
ORDER BY c.booking_date DESC, c.id DESC
LIMIT 100
```

### Frontend Changes

**JavaScript Function**:
```javascript
function updateDocketCount() {
    const checkboxes = document.querySelectorAll('input[name="docket_ids[]"]');
    const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
    document.getElementById('docketCount').textContent = checkedCount + ' dockets selected';
}
```

**Form Structure**:
- **Current Dockets**: Checkboxes with `checked` attribute
- **Available Dockets**: Checkboxes without `checked` attribute
- **Both sections**: Same `name="docket_ids[]"` for unified processing
- **Change events**: Trigger count update on any checkbox change

## Validation & Safety

### Server-Side Validation
1. **At least one docket**: Must select minimum 1 docket
2. **Same billing party**: All dockets must belong to same party
3. **Positive freight**: Dockets must have freight > 0
4. **Transaction safety**: Database transaction for atomic updates
5. **Error rollback**: Automatic rollback on any failure

### Error Messages
- **No dockets selected**: "At least one docket must be selected for the invoice."
- **Different billing party**: "All dockets must belong to the same billing party."
- **Zero freight**: "Docket {number} has zero freight."
- **Update failed**: Specific error from database operation

## User Experience Improvements

### Before Enhancement
- Could only edit: date, GST type/rate, remarks
- To change dockets: Cancel invoice → Create new invoice
- No visibility of available dockets
- Manual calculation needed for totals

### After Enhancement
- Can edit: date, GST type/rate, remarks, dockets
- Add/remove dockets directly in edit page
- See available dockets for same client
- Automatic recalculation of totals
- Live docket count display
- Clear visual feedback

## Benefits

### Flexibility
- **Add dockets**: Expand invoice with more dockets
- **Remove dockets**: Remove incorrect or unwanted dockets
- **Replace dockets**: Completely change invoice composition
- **Empty & rebuild**: Start fresh with new dockets

### Efficiency
- **No recreating invoices**: Edit existing instead of cancel/create
- **Automatic calculations**: No manual math needed
- **One-page operation**: All changes in single interface
- **Quick updates**: Fast docket management

### Accuracy
- **Party consistency**: Ensures all dockets belong to same party
- **Freight validation**: Prevents zero-freight dockets
- **Automatic totals**: Accurate tax and grand total calculation
- **Error prevention**: Database transactions prevent partial updates

## Restrictions

### Invoice Status
- **Only pending invoices**: Can only edit pending invoices
- **Submitted invoices**: Cannot edit submitted invoices
- **Cancelled invoices**: Cannot edit cancelled invoices

### Docket Availability
- **Same billing party**: Only shows dockets for same party
- **Not already billed**: Excludes dockets already in other invoices
- **Positive freight**: Only shows dockets with freight > 0
- **Recent dockets**: Limited to 100 most recent for performance

## Workflow Examples

### Scenario 1: Fix Wrong Dockets
1. Created invoice with wrong dockets
2. Edit invoice
3. Uncheck wrong dockets
4. Check correct dockets from available list
5. Update invoice
6. Invoice corrected without recreation

### Scenario 2: Add Missing Dockets
1. Created invoice missing some dockets
2. Edit invoice
3. Keep current dockets checked
4. Check missing dockets from available list
5. Update invoice
6. Invoice now complete

### Scenario 3: Adjust Invoice Composition
1. Client wants different dockets in invoice
2. Edit invoice
3. Uncheck all current dockets
4. Check desired dockets from available list
5. Update invoice
6. Invoice composition changed as requested

## Technical Notes

### Database Operations
- **DELETE + INSERT pattern**: Remove all, add selected
- **Transaction safety**: All operations in single transaction
- **Constraint integrity**: Foreign keys maintained
- **Cascade protection**: Invoice items properly linked

### Performance Considerations
- **Available dockets limit**: 100 dockets maximum
- **Indexed queries**: Uses proper indexes for speed
- **Batch operations**: Single DELETE + multiple INSERT
- **Efficient joins**: Optimized city name joins

## Future Enhancements

Potential improvements:
- **Bulk selection**: Select all/none buttons
- **Search in available**: Search dockets by number/route
- **Sort options**: Sort available dockets by date/freight
- **Docket preview**: Show docket details on hover
- **Drag-drop interface**: Visual docket management
- **Save as draft**: Save partial edits as draft
- **Change history**: Track docket changes over time

## File Changes

**Modified Files**:
- `edit_invoice.php`: Enhanced with docket management

**Key Changes**:
- Enhanced form processing with docket management
- Added available dockets query
- Improved UI with checkbox selection
- Added JavaScript for live count updates
- Enhanced validation and error handling

## Summary

The enhanced invoice editing functionality provides complete docket management capabilities:
- **Add dockets**: Expand invoice with additional dockets
- **Remove dockets**: Remove unwanted dockets from invoice
- **Replace dockets**: Completely change invoice composition
- **Empty & rebuild**: Start fresh with new dockets
- **Automatic calculations**: No manual math needed
- **Safety validations**: Prevents data inconsistencies

Users can now modify invoice composition directly without canceling and recreating invoices, making the billing process much more flexible and efficient.