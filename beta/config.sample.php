<?php
/* Copy this file to config.php on the server (hPanel → File Manager) and fill in real values.
   config.php is git-ignored so secrets never reach GitHub. */
return [
  'owner_pass'         => 'CHANGE_ME',                      // admin.html password
  'cron_key'           => 'CHANGE_ME',                      // ?action=cron&key=
  'rzp_key_id'         => 'rzp_live_XXXXXXXXXXXXXX',        // Razorpay key id
  'rzp_key_secret'     => 'XXXXXXXXXXXXXXXXXXXXXXXX',       // Razorpay key secret
  'rzp_webhook_secret' => '',                               // optional, Razorpay → Webhooks
  'smtp_host'          => 'smtp.hostinger.com',
  'smtp_port'          => 465,
  'smtp_user'          => 'info@scanplay.in',
  'smtp_pass'          => 'MAILBOX_PASSWORD',
  'mail_from_name'     => 'ScanPlay',
  'google_client_id'   => 'CHANGE_ME.apps.googleusercontent.com',
];
