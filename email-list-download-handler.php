<?php
/* email-list-download-handler.php
 * Purpose: capture “lead + download” submissions (toolkits, Echoes excerpt)
 * - Validates & sanitizes inputs
 * - Appends to CSV
 * - Writes to a simple log
 * - Emails admin (info@) with BCC to Gmail
 * - Sends a nice auto-reply to the user with the right download link(s)
 * - Redirects to dl-thank-you.html?file=<slug>
 */

///// CONFIG /////////////////////////////////////////////////////////////////
$admin_to      = 'info@briannjenga.co.ke';
$admin_bcc     = 'jbnjenga2011@gmail.com'; // optional BCC
$from_mailbox = 'noreply@briannjenga.co.ke';  
$site_name     = 'JBN Content Consultancy';

$storage_dir   = __DIR__ . '/storage';
$csv_file      = $storage_dir . '/leads.csv'; // master “download leads” CSV
$log_file      = $storage_dir . '/dl.log';    // event log

// Map a short slug -> human title + file path (adjust to your real paths)
$resources = [
  // Echoes of Valor
  'echoes_ch1_pdf'   => ['title' => 'Echoes of Valor — Chapter 1 (PDF)',  'path' => '/assets/pdfs/echoes-ch1.pdf'],
  'echoes_ch1_epub'  => ['title' => 'Echoes of Valor — Chapter 1 (EPUB)', 'path' => '/assets/ebooks/echoes-ch1.epub'],
  'echoes_ch1_mobi'  => ['title' => 'Echoes of Valor — Chapter 1 (MOBI)', 'path' => '/assets/ebooks/echoes-ch1.mobi'],

  // Toolkits (update the filenames if yours differ)
  'serp_real_estate_toolkit'     => ['title' => '📍 SERP Real Estate Toolkit (PDF)',                'path' => '/assets/toolkits/serp-real-estate-toolkit.pdf'],
  'content_value_quadrant'   => ['title' => '📊 Build a Content Value Quadrant (PDF)',         'path' => '/assets/toolkits/content-value-quadrant.pdf'],
  'omnichannel_starter_stack_for_small_brands'  => ['title' => '📦 Omnichannel Starter Stack (PDF)',               'path' => '/assets/toolkits/omnichannel-starter-stack-for-small-brands.pdf'],
  '10_ways_to_make_your_content_more_bingeable'    => ['title' => '🎬 10 Ways to Make Content More Bingeable (PDF)',  'path' => '/assets/toolkits/10-ways-to-make-your-content-more-bingeable.pdf'],
  'net_positive_business_scorecard'   => ['title' => '🌱 Net Positive Business Scorecard (PDF)',         'path' => '/assets/toolkits/net-positive-business-scorecard.pdf'],
  '5_apps_that_prioritize_digital_wellbeing'    => ['title' => '📱 5 Apps that Prioritize Digital Well-Being (PDF)','path' => '/assets/toolkits/5-apps-that-prioritize-digital-wellbeing.pdf'],
  'multi_metric_esg'     => ['title' => '🌍 Beyond Carbon — Multi-Metric ESG (PDF)',        'path' => '/assets/toolkits/multi-metric-esg.pdf'],
  '5_case_studies_of_ethical_pay_to_play'  => ['title' => '✨ Mini Case Studies: Ethical Paid Visibility (PDF)','path' => '/assets/toolkits/5-case-studies-of-ethical-pay-to-play.pdf'],
];

///// HELPERS ////////////////////////////////////////////////////////////////
function clean($v){ return trim(filter_var($v, FILTER_SANITIZE_FULL_SPECIAL_CHARS)); }
function log_line($file,$msg){
  @file_put_contents($file, '['.date('Y-m-d H:i:s')."] $msg\n", FILE_APPEND|LOCK_EX);
}
// 1) Replace your send_mail() with this version
function send_mail($to,$subj,$body,$from,$replyTo=null,$bcc=null,$envelopeFrom=null){
  $h  = "MIME-Version: 1.0\r\n";
  $h .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $h .= "From: $from\r\n";
  if($replyTo){ $h .= "Reply-To: $replyTo\r\n"; }
  if($bcc){     $h .= "Bcc: $bcc\r\n"; }

  // set Return-Path via -f (envelope sender). Also add header for completeness.
  if($envelopeFrom){ $h .= "Return-Path: $envelopeFrom\r\n"; }
  $params = $envelopeFrom ? "-f$envelopeFrom" : "";

  return @mail($to, $subj, $body, $h, $params);
}




///// BASIC GUARDRAILS (POST only, honeypot, time-trap) /////////////////////
if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  http_response_code(405);
  exit('Method not allowed');
}

$name     = clean($_POST['name'] ?? '');
$email    = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$resource = clean($_POST['resource'] ?? '');       // slug
$source   = clean($_POST['source']   ?? 'site_download');
$form_id  = clean($_POST['form_name']?? 'download_form');

// Honeypot: must be empty
$hp = $_POST['website'] ?? '';
if(!empty($hp)){ http_response_code(400); exit('Bad bot'); }

// Time-trap: require at least 2s between page render and submit (optional)
$ts = (int)($_POST['ts'] ?? 0);
if($ts && (time() - $ts) < 2){ http_response_code(400); exit('Too fast'); }

// Validate
if(!$name || !$email || !$resource || !isset($resources[$resource])){
  http_response_code(400);
  exit('Please complete all required fields.');
}

$ip  = $_SERVER['REMOTE_ADDR'] ?? '';
$ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
$now = date('c');

///// ENSURE STORAGE EXISTS //////////////////////////////////////////////////
if(!is_dir($storage_dir)){ @mkdir($storage_dir, 0755, true); }
if(!file_exists($csv_file)){
  @file_put_contents($csv_file, "date,name,email,resource,ip,ua,source,form\n");
}
if(!file_exists($log_file)){ @touch($log_file); }

///// WRITE CSV + LOG ////////////////////////////////////////////////////////
$row = [$now, $name, $email, $resource, $ip, $ua, $source, $form_id];
$csv_ok = false;
if($f = @fopen($csv_file, 'a')){
  if(fputcsv($f, $row)){ $csv_ok = true; }
  fclose($f);
}
log_line($log_file, ($csv_ok?'CSV append OK':'CSV append FAIL').": $email, $resource");

///// EMAILS /////////////////////////////////////////////////////////////////
$title = $resources[$resource]['title'];
$path  = $resources[$resource]['path'];
$full  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https://' : 'http://')
       . $_SERVER['HTTP_HOST'] . $path;

// Admin
$admin_subject = "New download lead: {$title}";
$admin_body = "Lead captured on {$site_name}\n\n"
            . "Name:     {$name}\n"
            . "Email:    {$email}\n"
            . "Resource: {$title} ({$resource})\n"
            . "Link:     {$full}\n"
            . "IP:       {$ip}\n"
            . "UA:       {$ua}\n"
            . "Source:   {$source}\n"
            . "Form ID:  {$form_id}\n"
            . "Time:     {$now}\n";
// 2) Use a real mailbox for From + envelope sender (pick ONE address and be consistent)
// -----------------------------
// EMAILS (Admin + Auto-reply)
// -----------------------------

// Build the absolute download link
$scheme = 'https://';
$full   = $scheme . $_SERVER['HTTP_HOST'] . $path;

// 1) Admin notification (info@, BCC Gmail, Reply-To = lead)
$admin_subject = "New download lead: {$title}";
$admin_body = "Lead captured on {$site_name}\n\n"
            . "Name:     {$name}\n"
            . "Email:    {$email}\n"
            . "Resource: {$title} ({$resource})\n"
            . "Link:     {$full}\n"
            . "IP:       {$ip}\n"
            . "UA:       {$ua}\n"
            . "Source:   {$source}\n"
            . "Form ID:  {$form_id}\n"
            . "Time:     {$now}\n";

if (send_mail(
      $admin_to,
      $admin_subject,
      $admin_body,
      "JBN Content Consultancy <{$from_mailbox}>",  // From
      $email,                                       // Reply-To (lead)
      $admin_bcc,                                   // BCC
      $from_mailbox                                 // envelope sender (-f)
)) {
  log_line($log_file, 'Admin mail sent');
} else {
  log_line($log_file, 'Admin mail FAILED');
}

// 2) User auto-reply (From = noreply@, Reply-To = info@)
$user_subject = "Your download: {$title}";
$user_body    = "Hi {$name},\n\n"
              . "Thanks for requesting {$title}.\n"
              . "You can download it here:\n{$full}\n\n"
              . "Helpful links:\n"
              . "- Blog: https://briannjenga.co.ke/blog.html\n"
              . "- Echoes excerpt: https://briannjenga.co.ke/blog.html#echoes\n"
              . "- All free toolkits: https://briannjenga.co.ke/landing.html\n\n"
              . "-- \n"
              . "JBN Content Consultancy\n"
              . "https://briannjenga.co.ke\n";

if (send_mail(
      $email,
      $user_subject,
      $user_body,
      "JBN Content Consultancy <{$from_mailbox}>",  // From (noreply)
      $admin_to,                                    // Reply-To (info@)
      null,                                         // no BCC on user mail
      $from_mailbox                                 // envelope sender (-f)
)) {
  log_line($log_file, 'Auto-reply sent');
} else {
  log_line($log_file, 'Auto-reply FAILED');
}


///// REDIRECT ///////////////////////////////////////////////////////////////
$dest = '/dl-thank-you.html?file='.rawurlencode($resource);
header('Location: ' . $dest, true, 302);
exit;
