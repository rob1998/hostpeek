# Hostpeek

See your website on its new server. Before you change DNS.

Enter a website URL and destination IP to create a shareable preview. Use your own domain, name and branding—no hosts-file changes needed.

https://github.com/user-attachments/assets/91cc1df2-ea48-4be4-8e02-22a9656c931f

## Install on cPanel

1. **[Download the latest release](https://github.com/rob1998/hostpeek/releases/latest)** and extract the `hostpeek-…zip` into your hosting account, outside your existing website's public folder. Dependencies are included.
2. **Set up your domain.** Point a domain and its wildcard, such as `preview.example.com` and `*.preview.example.com`, to your hosting account. Set both document roots to Hostpeek's `public/` folder and install an SSL certificate covering both.
3. **Create a database.** Add a MariaDB database and user in cPanel, granting the user all privileges on that database.
4. **Open your domain.** The setup wizard checks your hosting and guides you through your password, database connection and branding.

Requires **PHP 8.2+** with cURL, PDO MySQL and zlib, plus **MariaDB**. No terminal or Composer needed for first-time setup.

## Create a preview

Sign in, paste the original website URL and enter the new server's IP. Open the preview or share its link.

Under **Advanced**, choose an expiration date and optional password. Previews expire after one hour by default and ask search engines not to index them.

## Make it yours

Use a subdomain or a dedicated domain. Set your name, light and dark logos, and favicon during setup. You can change these later in the commented `config.php`; colors live in `public/styles.css`.

The **Help** button inside Hostpeek covers settings and troubleshooting.

## Good to know

Hostpeek is for browsing a site before a move. Target-site form submissions, logins and WebSockets aren't supported yet. Some JavaScript-heavy sites may need additional compatibility work.

When updating, preserve `config.php` and your custom assets, upload the new release, then run `php bin/install.php` in cPanel Terminal to update the database.

[Changelog](CHANGELOG.md) · [GPL-3.0-only](LICENSE)
