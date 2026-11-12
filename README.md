# KontoTrack Backend

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Environments

| Environment | Purpose | Settings |
|---|---|---|
| Local | Development | `.env.example` |
| Staging | Try a release with realistic data before production | `.env.staging.example` |
| Production | Real books | Same keys as staging, with production values |

Every push and pull request runs the checks in `.github/workflows/ci.yml`: code style, the test suite, and all migrations against MySQL.

### Releasing

1. Merge into `develop` and wait for the checks to pass.
2. Deploy to staging: `git pull`, `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`.
3. On staging run `php artisan kontotrack:production-check` and fix everything it reports. Then walk through an invoice, a payment, a month close and a report.
4. Run `php artisan kontotrack:backup` on production, then repeat step 2 there.

Staging and production each need the cron entry `* * * * * php artisan schedule:run` (backups, restore drill, reminder emails).

## Email

KontoTrack sends these emails: invitations and password links, invoices to customers (PDF attached, replies go to the business email), and a morning reminder of deadlines, document requests and approvals.

- Set `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and `MAIL_FROM_ADDRESS` for your mail service. With `MAIL_MAILER=log` nothing is delivered; emails are only written to the log.
- Set `KONTOTRACK_FRONTEND_URL` to the address of the web app. Links in emails open it.
- Reminders are sent at 07:00 by the scheduler (`php artisan kontotrack:reminders:send`). Each user can turn them off in their account settings.

## AI features (optional)

Two features use Claude through Anthropic's API and stay off until `ANTHROPIC_API_KEY` is set: reading the fields of an invoice or receipt, and the assistant. Setting the key means document contents and business figures are sent to Anthropic, so decide that with your clients before turning it on.

- What is read from a document is stored as unreviewed data. A person confirms it in the review form before it is used.
- `KONTOTRACK_AI_MODEL` chooses the model (default `claude-opus-5-5`).

## Backup and recovery

Backups hold the full database and every uploaded business document in one zip archive.

| Task | Command |
|---|---|
| Create a backup, verify it, prune old ones | `php artisan kontotrack:backup` |
| Check an archive against its checksums | `php artisan kontotrack:backup:verify [path]` |
| Rehearse a restore without touching live data | `php artisan kontotrack:backup:restore [path] --drill` |
| Restore the live system | `php artisan kontotrack:backup:restore [path]` |

Without a path, the newest archive is used.

### Configuration

- `KONTOTRACK_BACKUP_PATH`: where archives are written (default `storage/app/backups`). Copy this directory to another machine or bucket; a backup on the same disk as the database does not survive losing that disk.
- `KONTOTRACK_BACKUP_PASSWORD`: when set, every file in the archive is encrypted with AES-256. The same password is needed to verify and restore.
- `KONTOTRACK_BACKUP_KEEP`: how many archives to keep (default 14).
- Keep `APP_KEY` and `KONTOTRACK_BLIND_INDEX_KEY` outside the backups. Employee personal numbers and IBANs are encrypted with them, so a restored database cannot be read without them.

The scheduler runs the backup every day at 02:00 and a restore drill every Sunday at 03:00, so the server needs the cron entry for `php artisan schedule:run`. Failures are written to the application log.

### Restore drill

The drill restores the newest archive into a scratch database (`<database>_restore_drill` on MySQL), checks that every table recorded in the backup is there, compares row counts and deletes the scratch database again. On MySQL the database user needs permission to create and drop that database. Run it before going to production and after every change to the backup setup.

### Restoring after data loss

1. Put the application in maintenance mode: `php artisan down`.
2. Make sure `.env` has the `APP_KEY`, `KONTOTRACK_BLIND_INDEX_KEY` and `KONTOTRACK_BACKUP_PASSWORD` that were in use when the backup was taken. On a new server, create the empty database first.
3. Copy the archive to the server and check it: `php artisan kontotrack:backup:verify /path/to/archive.zip`.
4. Restore it: `php artisan kontotrack:backup:restore /path/to/archive.zip`. The command refuses an archive that fails verification, and first saves the current data as a new archive so the restore itself can be undone. On a new server with an empty database, add `--no-safety-backup`.
5. If the code is newer than the backup, run `php artisan migrate --force`.
6. Sign in, open a business and check the latest invoices, the trial balance and a document download.
7. Bring the application back: `php artisan up`.

Everything entered after the backup was taken is lost by a restore and has to be entered again. Documents uploaded after the backup stay on disk but are no longer listed.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
