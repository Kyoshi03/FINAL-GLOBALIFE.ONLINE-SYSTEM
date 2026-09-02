# SMTP Email Notifications

The system sends email verification and forgot-password OTP codes through authenticated SMTP.

## Hostinger Email

1. Use the active Hostinger mailbox `globalife@globalife.online`.
2. Open `config/mail.production.php`.
3. Replace `PALITAN_NG_EMAIL_ACCOUNT_PASSWORD` with the mailbox password.
4. Upload the file to `public_html/config/mail.production.php`.
5. Visit `https://globalife.online/deploy_check.php`.

Hostinger configuration:

```php
<?php
return [
    'enabled' => true,
    'host' => 'smtp.hostinger.com',
    'port' => 465,
    'encryption' => 'ssl',
    'username' => 'globalife@globalife.online',
    'password' => 'YOUR_MAILBOX_PASSWORD',
    'from_email' => 'globalife@globalife.online',
    'from_name' => 'Globalife Medical Laboratory & Polyclinic',
];
```

For providers that require port 587, use:

```php
'port' => 587,
'encryption' => 'tls',
```

Never commit or share the SMTP password.

## Local XAMPP

For localhost, create `config/mail.local.php` with the same SMTP settings. The system
automatically uses:

- `config/mail.local.php` on localhost/XAMPP
- `config/mail.production.php` on the live Hostinger domain
