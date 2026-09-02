# Appointment Email Automation

The booking page sends the appointment OTP and booking receipt immediately through the configured SMTP mailbox.

## Hostinger reminder cron

In hPanel, open **Websites > Dashboard > Advanced > Cron Jobs** and run the reminder worker every 5 minutes.

Use the PHP path shown by Hostinger. The command normally looks similar to:

```text
/usr/bin/php /home/YOUR_HOSTINGER_USER/domains/globalife.online/public_html/cron/send_appointment_reminders.php
```

Set the schedule to:

```text
*/5 * * * *
```

Replace `YOUR_HOSTINGER_USER` with the real home-directory username shown in Hostinger File Manager.

## Local XAMPP reminder task

The OTP and booking receipt work immediately on localhost. For automatic reminders, create a Windows Task Scheduler task that runs every 5 minutes:

```text
C:\xampp1.2\php\php.exe C:\xampp1.2\htdocs\vince\cron\send_appointment_reminders.php
```

The reminder is queued for 24 hours before the appointment and is only sent after clinic staff changes the appointment status to `confirmed`.
