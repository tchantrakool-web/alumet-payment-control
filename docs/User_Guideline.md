# Alumet Payment Control Tower User Guideline

This guide explains how each user role should use the system.

## 1. Purpose

The system is used to:

- import unpaid AP invoices from SAP
- create payment requests from unpaid AP invoices
- route requests through checking and approval
- prepare payment execution
- track paid and unpaid liabilities

## 2. User Roles

### Admin

- full access to all modules
- can maintain Settings and approval rules

### Maker / Finance Staff

- import SAP and Finance files
- create Payment Requests
- attach supporting documents

### Checker

- review requests in `Waiting Check`
- pass to approver or reject

### Approver / Executive

- review requests in `Waiting Approval`
- approve or reject

### Finance Manager

- import data
- create Payment Requests
- mark approved requests as `Ready to Pay`
- create payment batches
- manage cheques
- mark requests as `Paid`
- monitor reports and calendar

## 3. Main Modules

### Import Center

Use this page to upload:

- `SAP B1 Export`
- `Finance AP File`

Expected result:

- unpaid AP invoices appear in AP Invoice Queue
- vendor names and vendor codes are available for Payment Request creation

### AP Invoice Queue

Use this page to:

- review imported AP invoices
- see invoice total, paid amount, and outstanding balance
- create a Payment Request from an individual AP invoice

### Payment Requests

Use this page to:

- create new requests
- review request status
- see invoice references and outstanding balance

### Approval Queue

Use this page to:

- process `Waiting Check`
- process `Waiting Approval`
- review historical decisions

### Payment Batch

Use this page to:

- collect `Ready to Pay` requests
- create payment batches

### Cheques

Use this page to:

- prepare cheques for eligible requests
- update cheque receiving / release status

### Reports

Use this page to:

- review vendor aging
- review payment forecast
- review paid / unpaid requests
- review approval lead time

### Settings

Admin only.

Use this page to:

- maintain company defaults
- maintain approval matrix thresholds and sequence

## 4. End-to-End Process

## Step 1: Import SAP AP invoices

Role:

- Maker / Finance Staff
- Finance Manager
- Admin

Steps:

1. Open `Import Center`.
2. Upload the SAP export file.
3. Confirm the import history shows imported records.
4. Open `AP Invoice Queue` to verify unpaid items.

Important:

- The system uses unpaid SAP AP invoices with outstanding balance greater than zero.
- Vendor code and vendor name should be present after import.

## Step 2: Create Payment Request

Role:

- Maker / Finance Staff
- Finance Manager
- Admin

Steps:

1. Open `Payment Requests > Create Payment Request`.
2. Review `Unpaid AP Invoices by Vendor`.
3. Expand a vendor group if needed.
4. Select one or more AP invoices for the same vendor.
5. Confirm:
   - Vendor
   - Vendor Code
   - Outstanding Total
6. Enter:
   - Due Date
   - Payment Method
   - Priority
   - Note
7. Submit to checker.

System result:

- Payment Request status becomes `Waiting Check`
- linked AP invoices move to `Waiting Check`
- audit log is created

## Step 3: Checker review

Role:

- Checker
- Finance Manager
- Admin

Steps:

1. Open `Approval Queue`.
2. Review request details, invoice references, and outstanding amount.
3. Choose:
   - `Pass to Approver`
   - `Reject`

System result:

- if passed, status becomes `Waiting Approval`
- approval tasks are created from approval matrix
- if rejected, status becomes `Rejected`

## Step 4: Approver review

Role:

- Approver
- Executive
- Finance Manager
- Admin

Steps:

1. Open `Approval Queue`.
2. Review the request.
3. Choose:
   - `Approve`
   - `Reject`

System result:

- approved requests become `Approved`
- rejected requests become `Rejected`

## Step 5: Finance payment preparation

Role:

- Finance Manager
- Admin

Steps:

1. Open Payment Request detail.
2. For approved requests, click `Mark Ready to Pay`.
3. Open `Payment Batch`.
4. Select `Ready to Pay` requests.
5. Create batch.
6. If payment method is cheque, open `Cheques` to prepare cheque records.

## Step 6: Payment completion

Role:

- Finance Manager
- Admin

Steps:

1. Open Payment Request detail.
2. Click `Mark as Paid`.

System result:

- Payment Request status becomes `Paid`
- linked SAP AP invoices become `Paid`
- outstanding balance on the request becomes zero

## 5. Key Status Definitions

### AP Invoice statuses

- `ยังไม่ได้จ่ายเงิน` or other unpaid status
  - invoice is still available for Payment Request creation if outstanding balance is greater than zero
- `Waiting Check`
  - invoice is already included in a submitted Payment Request
- `Paid`
  - invoice payment is completed

### Payment Request statuses

- `Waiting Check`
- `Waiting Approval`
- `Approved`
- `Ready to Pay`
- `Paid`
- `Rejected`

## 6. Important Usage Rules

- Select AP invoices from one vendor only in a single Payment Request.
- Always confirm the vendor code before submitting the request.
- Use invoice references in the request to verify source AP invoices.
- Review outstanding balance before moving a request forward.
- Attach all supporting documents before checker review when possible.

## 7. Recommended Daily Routine

### Finance Staff

1. Import SAP updates.
2. Review unpaid AP invoice list by vendor.
3. Create Payment Requests for due items.
4. Attach documents.

### Checker

1. Review `Waiting Check` items.
2. Validate invoices, vendor, due date, and attachments.
3. Pass or reject quickly.

### Approver / Executive

1. Review `Waiting Approval`.
2. Focus on amount, invoice references, and urgency.
3. Approve or reject.

### Finance Manager

1. Review approved items.
2. Mark items `Ready to Pay`.
3. Build batches and payment instruments.
4. Mark completed payments as `Paid`.
5. Review Reports and Payment Calendar.

## 8. Troubleshooting

### No vendor appears after SAP import

Check:

- SAP file contains `VendorCode` and `VendorName`
- import completed successfully
- unpaid AP invoice rows have outstanding balance

### No AP invoice appears in Create Payment Request

Check:

- SAP import completed
- AP invoice `ap_balance > 0`
- invoice status is still unpaid / available

### Cannot submit Payment Request

Check:

- at least one AP invoice is selected
- Due Date is filled
- all selected invoices belong to the same vendor

