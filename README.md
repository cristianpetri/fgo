# FGO Invoice REST API Integration for WHMCS

A simple open-source integration for WHMCS that exposes a REST API endpoint for creating invoices through the [FGO.ro](https://fgo.ro) invoicing service.

## Overview

This script provides a custom REST API endpoint within WHMCS to allow external applications to issue invoices in FGO directly from your WHMCS system.  
It is ideal for connecting your billing platform, external apps, or other services with FGO's invoicing API, enabling automated invoice creation.

## Features

- Exposes a REST endpoint (`/fgo/v1/issue`) for creating invoices.
- Accepts invoice data via JSON POST requests.
- Calls the FGO.ro API to generate invoices in real time.
- Returns the FGO API response as JSON.
- Easy to integrate into any WHMCS instance.

## Installation

1. Copy both `fgo_hooks.php` and `fgo.php` into your WHMCS root or modules directory.
2. In `fgo.php`, replace `YOUR_API_KEY_HERE` with your actual FGO API key.
3. Make sure your webserver allows access to the new endpoint.
4. No WHMCS template or admin changes are required.

## Usage

Send a POST request to:

```
https://your-domain.com/fgo/v1/issue
```

with a JSON body containing:

```json
{
    "email": "client@email.com",
    "sum": "100",
    "currency": "RON",
    "description": "Invoice description",
    "company": "Company Name",
    "iban": "RO49AAAA1B31007593840000",
    "cif": "RO12345678",
    "address": "Street Example, no. 1",
    "name": "Contact Name",
    "phone": "0712345678"
}
```

### Example cURL request

```bash
curl -X POST https://your-domain.com/fgo/v1/issue     -H "Content-Type: application/json"     -d '{
        "email": "client@email.com",
        "sum": "100",
        "currency": "RON",
        "description": "Invoice description",
        "company": "Company Name",
        "iban": "RO49AAAA1B31007593840000",
        "cif": "RO12345678",
        "address": "Street Example, no. 1",
        "name": "Contact Name",
        "phone": "0712345678"
    }'
```

**Response:**  
- On success: JSON with invoice details as returned by FGO.
- On error: JSON with an `"error"` key and the error message.

## Security

- The endpoint is public by default.  
  **You should restrict access (by IP, token, or authentication) before using this in production.**

## FGO Licensing and API Access

To use this integration, you need an active FGO subscription with API access (available starting with the GO Premium plan or higher).  
Check the latest FGO subscription details and pricing here: [https://www.fgo.ro/abonamente/](https://www.fgo.ro/abonamente/)

## License

This project is open source under the MIT License.

## Contributing

Feel free to open issues or submit pull requests for improvements and bug fixes!

---

**Author:** Cristian Petri
