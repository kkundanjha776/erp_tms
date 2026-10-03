# Smart Billing System - User Guide

## Overview
The Smart Billing system has been enhanced with professional invoice formatting, company branding, charge selection, and export capabilities.

## New Features Added

### 1. Company Logo Upload
- **Location**: Company Master (`company_master.php`)
- **How to Use**: 
  - Navigate to Company Master from Admin menu
  - Edit or create a company profile
  - Upload company logo (JPG, PNG, GIF supported)
  - Logo will appear on all invoices

### 2. Terms & Conditions Management
- **Location**: Invoice Terms Master (`invoice_terms_master.php`)
- **Access**: Admin menu → Invoice Terms
- **Features**:
  - Create multiple terms & conditions templates
  - Set one as default for auto-selection
  - Edit/Delete terms as needed
  - Active/Inactive status control

### 3. Print Format Selection
- **Location**: Smart Billing (`billing.php`)
- **Available Formats**:
  - **Standard A4 Invoice**: Professional layout with company branding
  - **Detailed with All Charges**: Includes all charge breakdowns
  - **Simple Commercial**: Clean, minimal format

### 4. Charge Selection/Deselection
- **Location**: Smart Billing → "Include in Invoice" section
- **Available Charges**:
  - Basic Freight
  - Fuel Charge
  - Docket Charge
  - Handling Charge
  - ODA Charge
  - Detention
  - Misc Charge
  - Other Charge
  - Risk Charge
  - CGST
  - SGST
  - IGST
- **How to Use**: Check/uncheck charges to include or exclude them from the invoice

### 5. Excel Export
- **Location**: Smart Billing → "Export Options" section
- **Features**:
  - Export selected dockets to Excel/CSV format
  - Includes only selected charges
  - Contains invoice details, item breakdown, and charge summary
  - Compatible with Excel, Google Sheets, etc.

### 6. Professional A4 PDF Invoice
- **Location**: Generated via Print Preview button
- **Features**:
  - Company logo and branding
  - Professional layout optimized for A4 printing
  - Bill From/Bill To sections with complete address details
  - Itemized table with all charges
  - Terms & conditions integration
  - Signature sections
  - Auto-print functionality

## How to Use the Enhanced Billing System

### Step 1: Setup Company Profile
1. Go to Company Master (Admin menu)
2. Upload your company logo
3. Ensure GST registrations are configured
4. Save the profile

### Step 2: Configure Terms & Conditions
1. Go to Invoice Terms Master (Admin menu)
2. Create your standard terms
3. Mark one as default
4. Save the terms

### Step 3: Create Invoice
1. Navigate to Smart Billing
2. Select company GST registration
3. Choose billing party (client)
4. Select invoice format (Standard/Detailed/Simple)
5. Choose terms & conditions (or leave default)
6. Select charges to include in invoice
7. Search and select dockets
8. Click "Create Invoice"

### Step 4: Print/Export
1. After invoice creation, use the buttons in "Export Options":
   - **Print Preview**: Opens professional A4 invoice in new tab
   - **Excel Export**: Downloads CSV file with all details

## Charge Selection Benefits
- **Commercial Invoice**: Include only Basic Freight + GST
- **Detailed Invoice**: Include all charges for complete transparency
- **Custom Invoice**: Mix and match charges as per client requirements

## File Structure
- `company_master.php` - Enhanced with logo upload
- `invoice_terms_master.php` - New terms management
- `billing.php` - Enhanced with format/charge selection
- `invoice_print.php` - Professional invoice generation
- `api/export_invoice_excel.php` - Excel export functionality
- `includes/master_data.php` - Enhanced with terms functions
- `includes/functions.php` - Updated navigation

## Database Updates
The following tables are automatically created:
- `invoice_terms` - Stores terms & conditions templates

## Troubleshooting
- **Logo not appearing**: Ensure logo file is in `uploads/logos/` directory
- **Terms not showing**: Check terms are set to "Active" status
- **Export not working**: Verify PHP has write permissions for temporary files
- **Print preview blank**: Check browser pop-up blocker settings

## Tips
- Use high-resolution logos for best print quality
- Keep terms concise for better invoice appearance
- Test print preview before final invoice generation
- Use Excel export for record-keeping and analysis