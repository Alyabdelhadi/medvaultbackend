# Rolling back

Delete the block between `# BEGIN MedVault Laravel` and `# END MedVault Laravel`
in `public_html/app/.htaccess`. Requests go straight back to WordPress.

Then, in this order:

1. Admin (Laravel, while still reachable via a local hosts entry or before
   deleting the block) → Result Alerts → untick **Polling enabled**, or run
   `php artisan tinker --execute='App\Models\Setting::put("poll_enabled", false);'`.
2. WordPress → Push Notifications → Result Alerts → tick **Polling enabled**.

Anything that changed in Laravel meanwhile (new sign-ups, password changes,
devices, notifications) is not in WordPress. Accounts created after cutover will
have to register again; that is the cost of rolling back, so decide within the
first hours.
