# Deployment

- Tallport isn't deployed with Laravel Cloud or Forge. Installations update themselves from GitHub releases through the built-in updater (System > Status > Update Now, or `php artisan freescout:update`).
- A release is made with `./release.sh` (see the Tallport rules). Nothing else deploys code.
