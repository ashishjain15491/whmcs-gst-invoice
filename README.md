# WHMCS GST Invoice Addon

## Prerequisites

Before installing this addon, ensure your WHMCS system is properly configured:

### Enable Proforma Invoicing
- Navigate to **Setup > System Settings > Invoices**
- Enable the Proforma Invoicing option

### Sequential Invoice Number Format
- Go to **Setup > System Settings > Invoices**
- Configure sequential invoice numbering as per your requirements

### Setup Tax Configuration

1. Navigate to **Setup > Tax Configuration**
2. Enable Tax Support
3. Add GSTIN in the Tax ID / VAT Number field
4. Enable Customer Tax IDs / VAT Numbers option
5. Set Taxation Type to your requirement

### Set Tax Rules

#### Level 1 Rules
- **IGST**: Country: India, State: Any, Tax Rate: 18%
- **CGST**: Country: India, State: Your GST Registered State, Tax Rate: 9%

#### Level 2 Rules
- **SGST**: Country: India, State: Your GST Registered State, Tax Rate: 9%

### Advanced Tax Settings

In **Tax Configuration**, configure:
- Taxed Items
- Calculation Mode
- Disable Compound Tax

## Installation

1. Download the addon
2. Extract files to `/modules/addons/whmcs-gst-invoice/`
3. Activate in **Setup > Addon Modules**
4. Configure addon settings as needed

## Support

For issues, refer to WHMCS documentation or contact support.