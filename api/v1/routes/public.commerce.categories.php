<?php
// /api/v1/routes/public.commerce.categories.php
// Router compatibility wrapper.
// Some installs route without the .get.php suffix.
// This ensures GET works no matter which file naming convention the router uses.

require_once __DIR__ . "/public.commerce.categories.get.php";