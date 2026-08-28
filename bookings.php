<?php
declare(strict_types=1);

// Public compatibility entry point for customer bookings.
// The existing customer UI and API remain the single source of truth.
$_GET['page'] = 'bookings';
require __DIR__ . '/customer.php';
