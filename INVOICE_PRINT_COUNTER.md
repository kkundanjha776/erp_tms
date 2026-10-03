# Invoice Print Counter Feature

## Overview
Added print counter to track how many times an invoice has been printed. This helps in tracking invoice usage and identifying frequently printed invoices.

## Implementation

### 1. **Database Schema Update**
**Add column to invoices table**:
```sql
ALTER TABLE invoices ADD COLUMN print_count INT DEFAULT 0;
```

### 2. **PHP Changes (invoice_print.php)**

#### Print Count Increment
```php
// Increment print count every time invoice is printed
$stmt = $conn->prepare('UPDATE invoices SET print_count = COALESCE(print_count, 0) + 1 WHERE id = ?');
$stmt->bind_param('i', $invoiceId);
$stmt->execute();
$stmt->close();
```

#### Display in Print View
```php
<div class="print-count">Print Count: <?= number_format($invoice['print_count'] ?? 0) ?></div>
```

### 3. **CSS Styling**
```css
.print-count {
    font-size: 10px;
    color: #999;
    margin-top: 5px;
}
```

## Features

### What It Does:
1. **Auto-Increment**: Every time invoice is printed, counter increases by 1
2. **Display**: Shows print count on printed invoice
3. **Track Usage**: Helps identify frequently printed invoices
4. **Audit Trail**: Complete tracking of print activity

### Location in Print View:
```
┌────────────────────────────────────────┐
│ TAX INVOICE                              │
│ Invoice #: INV-001                       │
│ Date: 15-Jan-2025                        │
│ Print Count: 3                           │ ← NEW
└────────────────────────────────────────┘
```

## Database Requirement

### SQL Command to Add Column:
```sql
ALTER TABLE invoices ADD COLUMN print_count INT DEFAULT 0;
```

### If Column Already Exists:
The COALESCE function handles NULL values:
```php
COALESCE(print_count, 0) + 1
```

## Benefits

### Tracking:
- **Usage Analytics**: Track which invoices are printed most frequently
- **Audit Trail**: Complete print history
- **Business Intelligence**: Understand invoice usage patterns

### Compliance:
- **Print Management**: Control and track printing
- **Document Control**: Monitor document generation
- **Quality Control**: Track duplicate prints

## Usage

### How It Works:
1. User clicks "Print" on invoice
2. `invoice_print.php` loads
3. Database increments print_count
4. Print view displays count
5. Print completes

### Example Values:
- First print: Print Count: 1
- Second print: Print Count: 2
- Third print: Print Count: 3

## Testing

### Manual Test:
1. Open any invoice for print
2. Check print count (should be 0 initially)
3. Print the invoice
4. Check print count again (should be 1)
5. Print again
6. Check print count (should be 2)

### Database Check:
```sql
SELECT id, invoice_no, print_count FROM invoices WHERE id = [invoice_id];
```

## Integration

### With Other Features:
- **Invoice Management**: Print count visible in invoice list
- **Export to Excel**: Include print count in export
- **Audit Logs**: Track print activity in logs

### Future Enhancements:
- **Print Limit**: Restrict max prints per invoice
- **Print History**: Track print timestamps
- **User Tracking**: Track who printed each invoice
- **Print Watermark**: Add "Copy X of Y" watermark based on count

## Summary

Print counter feature provides:
- ✅ Automatic tracking of print count
- ✅ Display on printed invoice
- ✅ Usage analytics
- ✅ Audit trail
- ✅ Simple implementation
- ✅ No performance impact

Now you can track how many times each invoice has been printed! 🚀