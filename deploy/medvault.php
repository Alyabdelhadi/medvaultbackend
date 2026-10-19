<?php

/*
| Front controller for the MedVault Laravel backend, installed as
| public_html/app/medvault.php beside the WordPress install.
|
| The rewrite rules in deploy/htaccess-app.conf send the app's API
| (/app/wp-json/custom/v1/*), the photo upload (/app/wp-json/wp/v2/media) and
| the admin (/app/admin) here. Everything else stays with WordPress until it
| is retired. Because this file sits in /app, Laravel sees /app as its base
| URL and every route works unprefixed (tests/Feature/SubdirectoryMountTest).
|
| Point the path below at the backend checkout, which must live OUTSIDE
| public_html so its .env, storage and vendor are never web-readable.
*/

// public_html/app/medvault.php → ~/medvault-backend/public/index.php
require dirname(__DIR__, 2).'/medvault-backend/public/index.php';
