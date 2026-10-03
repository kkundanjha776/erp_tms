# Invoice Management System - Complete Guide

## Overview
Complete invoice management system with submission tracking, proof upload, cancellation, and Excel import capabilities.

## Features Added

### 1. Invoice Management Page (`invoice_management.php`)
**Location**: Operations Menu → Invoice Management

**Features**:
- **Invoice List**: View all invoices with filtering options
- **Status Tracking**: Pending, Submitted, Cancelled status
- **Statistics Dashboard**: Total invoices, pending, submitted, total amount
- **Advanced Filtering**: Filter by status, client, date range
- **Bulk Actions**: Multiple operations on invoices

### 2. Invoice Submission with Proof Upload
**Features**:
- Submit invoices with submission date
- Upload submission proof (PDF, Image, Document)
- View uploaded proofs
- Track submission status

**Supported File Types**:
- Images: JPG, JPEG, PNG
- Documents: PDF, DOC, DOCX

### 3. Invoice Cancellation
**Features**:
- Cancel pending invoices with reason
- Track cancellation details
- Record who cancelled and when
- Cancellation reason mandatory

### 4. Invoice Deletion
**Features**:
- Delete pending invoices only
- Safety check for submitted invoices
- Cascade delete of invoice items
- Confirmation modal

### 5. Invoice Editing
**Location**: Edit button on pending invoices → `edit_invoice.php`

**Editable Fields**:
- Invoice date
- GST type (CGST+SGST / IGST)
- GST rate
- Remarks

**Restrictions**:
- Only pending invoices can be edited
- Submitted/cancelled invoices cannot be modified
- Docket modifications require invoice cancellation

### 6. Excel Import
**Features**:
- Bulk import invoices from CSV
- Template download
- Validation and error reporting
- Automatic client and company mapping

**CSV Format**:
```csv
invoice_no,invoice_date,client_name,gst_type,gst_rate,amount
INV-001,2024-01-15,ABC Company,CGST_SGST,18,5000
INV-002,2024-01-16,XYZ Company,IGST,18,7500
```

### 7. Enhanced Database Schema
**New Columns in invoices table**:
- `submission_status`: ENUM('Pending','Submitted','Cancelled')
- `submission_date`: DATE
- `submission_proof_path`: VARCHAR(255)
- `cancellation_reason`: TEXT
- `cancelled_by`: INT
- `cancelled_at`: TIMESTAMP
- `updated_at`: TIMESTAMP

## How to Use

### View Invoice List
1. Go to **Operations → Invoice Management**
2. Use filters to narrow down results:
   - Status: All/Pending/Submitted/Cancelled
   - Client: Select specific client
   - Date Range: Set from/to dates
3. Click "Filter" to apply filters
4. View statistics in dashboard cards

### Submit Invoice
1. Find pending invoice in list
2. Click ✓ (Submit) button
3. Enter submission date
4. Optionally upload proof document
5. Click "Submit Invoice"
6. Status changes to "Submitted"

### Cancel Invoice
1. Find pending invoice in list
2. Click ✕ (Cancel) button
3. Enter cancellation reason (mandatory)
4. Click "Cancel Invoice"
5. Status changes to "Cancelled"

### Delete Invoice
1. Find pending invoice in list
2. Click 🗑️ (Delete) button
3. Confirm deletion in modal
4. Invoice permanently deleted
5. Note: Cannot delete submitted invoices

### Edit Invoice
1. Find pending invoice in list
2. Click ✏️ (Edit) button
3. Modify editable fields:
   - Invoice date
   - GST type/rate
   - Remarks
4. Click "Update Invoice"
5. Changes saved automatically

### Print Invoice
1. Click 🖨️ (Print) button on any invoice
2. Opens professional A4 invoice in new tab
3. Use browser print to save as PDF

### Export to Excel
1. Click 📊 (Excel) button on any invoice
2. CSV file automatically downloads
3. Contains complete invoice details

### Import Invoices from Excel
1. Click "📥 Import Excel" button
2. Download CSV template if needed
3. Fill template with invoice data
4. Upload CSV file
5. System validates and imports
6. View import results with errors

## Invoice Status Workflow

```
Created → Pending → Submitted
              ↓
           Cancelled
```

**Status Rules**:
- **Pending**: Can edit, submit, cancel, delete
- **Submitted**: Cannot edit, cancel, or delete
- **Cancelled**: Cannot edit, submit, or delete

## Navigation Updates

**Operations Menu**:
- ~~Billing & Invoices~~ → **Create Invoice** (billing.php)
- **Invoice Management** (invoice_management.php) ← NEW

**Admin Menu**:
- **Invoice Terms** (invoice_terms_master.php)

## File Structure

**New Files**:
- `invoice_management.php` - Main invoice management page
- `edit_invoice.php` - Invoice editing page
- `api/import_invoice_excel.php` - Excel import API

**Modified Files**:
- `includes/master_data.php` - Enhanced billing schema
- `includes/functions.php` - Navigation updates
- `invoice_print.php` - Print functionality
- `api/export_invoice_excel.php` - Export functionality

**New Directories**:
- `uploads/invoice_proofs/` - Submission proof storage

## Security Features

- CSRF protection on all forms
- User authentication required
- Role-based access control
- File upload validation
- SQL injection prevention
- Status-based action restrictions

## Troubleshooting

**Import Issues**:
- Ensure CSV format matches template
- Check client names exist in system
- Verify company GST registration is active
- Review error messages for specific issues

**Submission Issues**:
- Check file upload permissions
- Verify file type is supported
- Ensure uploads directory exists
- Check disk space

**Edit Restrictions**:
- Only pending invoices can be edited
- Submitted invoices must be cancelled first
- Contact admin if edit needed on submitted invoice

## Best Practices

1. **Invoice Submission**:
   - Always upload proof when submitting
   - Use descriptive file names for proofs
   - Keep submission records organized

2. **Invoice Cancellation**:
   - Provide clear cancellation reasons
   - Cancel before submission if changes needed
   - Document cancellation for audit trail

3. **Excel Import**:
   - Use provided template
   - Validate data before import
   - Review import results carefully
   - Test with small batches first

4. **General**:
   - Regularly review pending invoices
   - Keep invoice list organized with filters
   - Use print preview before final printing
   - Maintain backup of important proofs

## API Endpoints

**Import**:
- `POST /api/import_invoice_excel.php`
- Parameters: `csrf_token`, `excel_file`
- Returns: Import results with errors

**Export**:
- `GET /api/export_invoice_excel.php?invoice_id={id}`
- Downloads CSV file

**Print**:
- `GET /invoice_print.php?invoice_id={id}`
- Opens printable invoice

## Database Queries

**Get Pending Invoices**:
```sql
SELECT * FROM invoices WHERE submission_status = 'Pending';
```

**Get Submitted Invoices with Proof**:
```sql
SELECT * FROM invoices WHERE submission_status = 'Submitted' AND submission_proof_path IS NOT NULL;
```

**Get Cancelled Invoices**:
```sql
SELECT i.*, u.username as cancelled_by_user 
FROM invoices i 
LEFT JOIN users u ON i.cancelled_by = u.id 
WHERE i.submission_status = 'Cancelled';
```

## Future Enhancements

Potential features for future versions:
- Email notifications on submission
- Bulk submission of multiple invoices
- Invoice approval workflow
- Payment tracking integration
- Advanced reporting and analytics
- Recurring invoice generation
- Multi-currency support