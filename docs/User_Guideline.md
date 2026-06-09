# Alumet Payment Control Tower User Guideline

This guide explains how each user role should use the current MVP workflow.

## 1. Purpose

The system is used to:

- import unpaid AP invoices from SAP
- create payment requests from unpaid AP invoices
- collect and verify supporting documents
- route requests through accounting, finance, and management review
- prepare payment execution
- track paid and unpaid liabilities

## 2. User Roles

### Admin

- full access to all modules
- can maintain Settings and approval rules

### Maker / Finance Staff

- import SAP and Finance files
- create Payment Requests
- maintain document checklist
- review accounting data such as WHT and tax invoice requirements
- resubmit returned requests

### Checker

- review requests in `Pending Finance Review`
- send complete requests to management approval
- return incorrect requests for correction
- reject invalid requests

### Approver / Executive

- review requests in `Pending Management Approval`
- approve for payment
- return requests for correction
- reject requests

### Finance Manager

- perform accounting and finance review
- approve payment preparation
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
- create a Payment Request from an individual AP invoice when the invoice is still available

### Payment Requests

Use this page to:

- create new requests
- maintain WHT, gross amount, and net payable
- review request status
- upload supporting documents
- move requests through the workflow

### Approval Queue

Use this page to:

- process `Pending Finance Review`
- process `Pending Management Approval`
- review historical decisions

### Payment Batch

Use this page to:

- collect `Approved for Payment` requests
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

### Step 1: Import SAP AP invoices

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

### Step 2: Create Payment Request

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
   - Gross Amount
6. Enter:
   - Due Date
   - Payment Method
   - WHT details if applicable
   - Priority
   - Note
7. Create the request.

System result:

- Payment Request status becomes `Pending Documents`
- linked AP invoices move to `Pending Documents`
- audit log is created

### Step 3: Collect supporting documents

Role:

- Maker / Finance Staff
- Finance Manager
- Procurement support outside the system if needed

Steps:

1. Open Payment Request detail.
2. Upload and confirm supporting documents:
   - `PO`
   - `GRPO`
   - `Invoice`
   - `Tax Invoice` if required
3. Complete the checklist in `Accounting Review Checklist`.
4. Click `Submit to Accounting Review`.

System result:

- status becomes `Pending Accounting Review`

### Step 4: Accounting review

Role:

- Maker / Finance Staff
- Finance Manager
- Admin

Steps:

1. Open Payment Request detail.
2. Confirm 3-way match:
   - PO
   - GRN / GRPO
   - Vendor Invoice
3. Confirm:
   - gross amount
   - WHT applicability
   - WHT rate / base / amount
   - tax invoice requirement
4. Save review details.
5. Click `Submit to Finance Review`.

System result:

- status becomes `Pending Finance Review`

### Step 5: Finance review

Role:

- Checker
- Finance Manager
- Admin

Steps:

1. Open `Approval Queue`.
2. Review request details, checklist, attachments, due date, and payment readiness.
3. Choose one action:
   - `Send for Management Approval`
   - `Return for Correction`
   - `Reject`

System result:

- if passed, status becomes `Pending Management Approval`
- approval tasks are created from approval matrix
- if returned, status becomes `Returned for Correction`
- if rejected, status becomes `Rejected`

### Step 6: Management approval

Role:

- Approver
- Executive
- Finance Manager
- Admin

Steps:

1. Open `Approval Queue`.
2. Review the request.
3. Choose one action:
   - `Approve for Payment`
   - `Return for Correction`
   - `Reject`

System result:

- approved requests become `Approved for Payment`
- returned requests become `Returned for Correction`
- rejected requests become `Rejected`

### Step 7: Correct and resubmit

Role:

- Maker / Finance Staff
- Finance Manager
- Admin

Steps:

1. Open a request in `Returned for Correction`.
2. Review the `return reason`.
3. Upload missing documents or fix accounting details.
4. Click `Resubmit After Correction`.

System result:

- request moves back to `Pending Documents` or `Pending Accounting Review` depending on checklist completeness

### Step 8: Finance payment preparation

Role:

- Finance Manager
- Admin

Steps:

1. Open `Payment Batch`.
2. Select requests in `Approved for Payment`.
3. Create batch.
4. If payment method is cheque, open `Cheques` to prepare cheque records.

### Step 9: Payment completion

Role:

- Finance Manager
- Admin

Steps:

1. Open Payment Request detail.
2. Upload `Payment Proof`.
3. Enter:
   - Payment Date
   - Payment Reference
   - Payment Bank
   - Payer Name
4. Click `Mark as Paid`.

System result:

- Payment Request status becomes `Paid`
- linked SAP AP invoices become `Paid`
- outstanding balance on the request becomes zero

## 5. Key Status Definitions

### AP Invoice statuses

- `Imported`, `pending`, `Unpaid`, `Outstanding`
  - invoice is still available for Payment Request creation if outstanding balance is greater than zero
- `Pending Documents`
  - invoice is already included in an active Payment Request
- `Paid`
  - invoice payment is completed

### Payment Request statuses

- `Pending Documents`
- `Pending Accounting Review`
- `Pending Finance Review`
- `Pending Management Approval`
- `Approved for Payment`
- `Returned for Correction`
- `Paid`
- `Rejected`

## 6. Important Usage Rules

- Select AP invoices from one vendor only in a single Payment Request.
- Always confirm the vendor code before submitting the request.
- Use invoice references in the request to verify source AP invoices.
- Review gross amount, WHT, and net payable before moving a request forward.
- Attach all supporting documents before finance review.
- Do not mark a request as `Paid` until payment details and payment proof are available.

## 7. Recommended Daily Routine

### Finance Staff

1. Import SAP updates.
2. Review unpaid AP invoice list by vendor.
3. Create Payment Requests for due items.
4. Upload documents and complete accounting review details.
5. Resubmit returned requests.

### Checker / Finance Review

1. Review `Pending Finance Review` items.
2. Validate invoices, vendor, due date, WHT, and attachments.
3. Send complete items to management approval.
4. Return or reject incorrect items quickly.

### Approver / Executive

1. Review `Pending Management Approval`.
2. Focus on amount, invoice references, urgency, and readiness.
3. Approve, return, or reject.

### Finance Manager

1. Review accounting and finance status of open items.
2. Build batches from `Approved for Payment`.
3. Prepare payment instruments.
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

### Cannot move request to Finance Review

Check:

- PO, GRPO, and Invoice are confirmed
- Tax Invoice is confirmed if required
- WHT base and amount are filled if WHT is applicable

### Cannot mark request as Paid

Check:

- `Payment Proof` attachment is uploaded
- Payment Date is filled
- Payment Reference is filled
- Payment Bank is filled
- Payer Name is filled
