<?php

// API routes, split by actor so this mirrors frontend/src (client / owner /
// admin / shared). Everything required here still runs inside the `api`
// middleware group + `/api` prefix from bootstrap/app.php's withRouting().

require __DIR__.'/api/public.php';
require __DIR__.'/api/shared.php';
require __DIR__.'/api/client.php';
require __DIR__.'/api/admin.php';
require __DIR__.'/api/owner.php';
