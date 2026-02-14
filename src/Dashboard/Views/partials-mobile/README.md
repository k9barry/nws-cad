# Mobile Dashboard Partials

This directory contains the mobile-specific modal components for the NWS CAD Dashboard.

## Components

### filters-modal.php
Mobile-optimized filters modal with:
- Quick select buttons for time periods (Today, Yesterday, Last 7 Days, Last 30 Days)
- Dropdown filters for Jurisdiction, Agency, Status, Priority
- Text input for Call Type
- Touch-friendly buttons and large tap targets
- Reset and Apply actions

### call-detail-modal.php
Mobile call details modal that displays:
- Call information (ID, Type, Status, Priority, Received time)
- Location details (Address, Cross Street)
- Assigned units with status badges
- Responsive layout optimized for small screens

### analytics-modal.php
Mobile analytics modal featuring:
- Call Volume Over Time chart
- Call Types Distribution chart
- Priority Distribution chart
- Status Distribution chart
- Responsive charts using Chart.js

## Usage

These partials are automatically included in `dashboard-mobile.php` when a mobile device is detected.

## Styling

Mobile-specific styles are defined in `/public/assets/css/mobile.css`

## JavaScript

Mobile-specific functionality is in `/public/assets/js/mobile.js`
