# SMS and Appointment Reminder Setup

The system uses SkySMS for:

- patient account verification codes;
- appointment verification codes;
- appointment request and clinic confirmation messages;
- 24-hour appointment reminders.

## SMS configuration

Copy `config/sms.example.php` to `config/sms.php`, then add the active SkySMS API key. Environment variables can also override the file:

- `SKYSMS_ENABLED`
- `SKYSMS_BASE_URL`
- `SKYSMS_API_KEY`
- `SKYSMS_USE_SUBSCRIPTION`

Keep the GitHub repository private when production credentials are stored in tracked configuration files.

## Hostinger reminder cron

Create a Hostinger cron job that runs every 5 or 10 minutes:

```text
/usr/bin/php /home/u901773288/domains/globalife.online/public_html/cron/send_appointment_reminders.php
```

If Hostinger says the file path is different, open **Files > File Manager**, open `public_html`, and copy the full path. The command must end with:

```text
/cron/send_appointment_reminders.php
```

Recommended Hostinger schedule:

```text
*/10 * * * *
```

That means the reminder checker runs every 10 minutes. The command is CLI-only and cannot be opened as a public web page.

The reminder job tracks email and SMS separately. A failed SMS retry will not resend an email that was already delivered.

## Deployment check

Open `deploy_check.php` after deployment. It verifies database access, SMTP authentication, and SkySMS API authentication without sending an SMS.

Remove or restrict `deploy_check.php` after the live deployment is confirmed.
