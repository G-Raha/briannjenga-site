<?php
// contact-handler.php

// 1) Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed.');
}

// 2) Grab & trim inputs (must match form names!)
$name    = isset($_POST['name'])    ? trim($_POST['name'])    : '';
$email   = isset($_POST['email'])   ? trim($_POST['email'])   : '';
$company = isset($_POST['company']) ? trim($_POST['company']) : '';
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

error_log('CONTACT POST: ' . print_r($_POST, true));


// 3) Validate
if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  // Optional: uncomment this line to see what actually arrived
  // error_log('CONTACT POST: ' . print_r($_POST, true));

  // User-friendly message
  exit('Please complete all required fields with a valid email.');
}

// 4) Prepare email
$to       = 'info@briannjenga.co.ke';         // your destination mailbox or forwarder
$subject  = 'New contact form submission';
$headers  = "From: noreply@briannjenga.co.ke\r\n";
$headers .= "Reply-To: " . $email . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$body  = "Name: $name\n";
$body .= "Email: $email\n";
$body .= "Company: $company\n";
$body .= "Message:\n$message\n";

// 5) Send
$sent = @mail($to, $subject, $body, $headers);

// 6) Redirect or show a message
if ($sent) {
  header('Location: /thank-you.html');
  exit;
} else {
  // If mail fails (e.g., block, misconfig), at least don’t lose the lead:
  error_log('MAIL FAILED: ' . print_r([
    'to' => $to, 'subject' => $subject, 'headers' => $headers, 'body' => $body
  ], true));
  exit('Thanks! Your message was received, but email delivery failed. We will follow up shortly.');
}
