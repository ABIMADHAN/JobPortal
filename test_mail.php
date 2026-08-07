<?php

require_once __DIR__ . '/mail/send_mail.php';

if (sendEmail(
    "giridhar4434@gmail.com",
    "SMTP Test",
    "<h2>Hello!</h2><p>Your PHP SMTP setup is working successfully.</p>"
)) {
    echo "✅ Email Sent Successfully";
} else {
    echo "❌ Failed to send email";
}