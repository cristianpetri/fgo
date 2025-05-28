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

