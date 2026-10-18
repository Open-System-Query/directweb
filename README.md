# DirectWeb

**Website hosting and management, with versions built in.**

![The DirectWeb dashboard with grouped website, administration and system tools](Docs/Images/directweb-dashboard.png)

DirectWeb manages static websites through a compact admin panel. Upload a website, create a draft, share a preview and publish when it is ready. Previous versions remain available for switching back.

The same core runs on a PHP webserver or inside WordPress. Its interface uses [LUMUI](https://github.com/Open-System-Query/lumui), while plugins add services such as MCP, file sharing and outgoing mail.

## Highlights

- Versioned websites with drafts, preview links and explicit publishing
- MCP tools for editing, previewing and publishing through compatible AI clients
- Backups, scheduled jobs and activity history
- Optional plugins with their own settings and admin pages
- Public file sharing with short links and configurable upload limits
- User permissions and optional two-factor authentication in the webserver edition
- 21 languages with editable translation catalogs

## Screenshots

Screenshots use demo data and the public icon set.

| Websites | Versions | File sharing |
| --- | --- | --- |
| [![Website overview](Docs/Images/directweb-websites.png)](Docs/Images/directweb-websites.png) | [![Draft and live website versions](Docs/Images/directweb-versions.png)](Docs/Images/directweb-versions.png) | [![Public file upload portal](Docs/Images/directweb-sharing.png)](Docs/Images/directweb-sharing.png) |

README images are kept in [`Docs/Images`](Docs/Images).

## Repository

- [`Source/Shared`](Source/Shared): application core, plugins, translations and assets
- [`Source/Webserver`](Source/Webserver): webserver entry points and routing
- [`Source/WordPress`](Source/WordPress): WordPress integration
- [`Docs`](Docs): screenshots and documentation assets
- [`Build.php`](Build.php): creates upload packages from the shared source and each integration

## Build

Run from the repository directory with PHP 8.1 or newer and the ZIP extension:

```sh
php Build.php
```

Packages are written to `Build/Packages`:

- `directweb-webserver.zip`
- `directweb-wordpress.zip`
- `Plugins/`: separate DirectWeb plugin packages

Use `php Build.php webserver`, `php Build.php wordpress` or `php Build.php plugins` to build a single target. The source directories are combined during packaging; upload the resulting package.

## Install

**Webserver:** extract `directweb-webserver.zip` into a domain root or subfolder, including `.htaccess`. Open the installation URL and complete setup. Manage the installation at `/admin` beneath that URL.

**WordPress:** upload `directweb-wordpress.zip` through **Plugins → Add New → Upload Plugin**, activate it and open **DirectWeb** in the admin menu. WordPress supplies the admin login.

Both editions require PHP 8.1 or newer with PDO SQLite, SQLite3, ZIP, Sodium, Mbstring, OpenSSL, Fileinfo and Argon2id support. The webserver package requires HTTPS and Apache rewrite support with the supplied `.htaccess` rules enabled. Scheduled jobs use a hosting cron task in the webserver edition and WordPress cron in the WordPress edition.

Website uploads contain static HTML, CSS, JavaScript and assets, with an `index.html` or `index.htm` entry point. Enable additional features from **Plugins**. Mail is optional; MCP changes create drafts until explicitly published.

## License

DirectWeb is released under the [MIT License](LICENSE) by [Open System Query](https://opensystemquery.nl/).

Bundled dependencies retain their [own licenses](Source/Shared/licenses). This repository includes Font Awesome Free icons; Font Awesome Pro assets are excluded.
