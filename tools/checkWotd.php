<?php
/**
 * This script checks whether exactly one WotD is set and whether it has an image, for the upcoming N days.
 **/

require_once __DIR__ . '/../phplib/util.php';
log_scriptLog("checkWotd: starting");

define('NUM_DAYS', 3);

$MAIL_INFO = Config::get('WotD.rcpt-info',array());
$MAIL_ERROR = Config::get('WotD.rcpt-error',array());
$sender = Config::get('WotD.sender', '');
$replyto = Config::get('WotD.reply-to', '');
$MAIL_HEADERS = array("From: $sender", "Reply-To: $replyto", 'Content-Type: text/plain; charset=UTF-8');

$sendEmail = false;
$quiet = false;
foreach ($argv as $i => $arg) {
  if ($i) {
    switch ($arg) {
    case '--send-email': $sendEmail = true; break;
    case '--quiet': $quiet = true; break;
    default: print "Unknown flag $arg -- aborting\n"; exit;
    }
  }
}

$staticFiles = file(Config::get('static.url') . 'fileList.txt');
$messages = array();
$firstErrorDate = null;

for ($d = 0; $d <= NUM_DAYS; $d++) {
  $date = date("Y-m-d", strtotime("+{$d} days"));

  // Check that exactly one WotD exists
  $wotds = WordOfTheDay::get_all_by_displayDate($date);
  if (count($wotds) != 1) {
    addError($date, count($wotds) ? sprintf("Există %s cuvinte", count($wotds)) : "Nu există niciun cuvânt");
    continue;
  }

  // Check that it has exactly one WotD rel
  $rels = WordOfTheDayRel::get_all_by_wotdId($wotds[0]->id);
  if (count($rels) != 1) {
    addError($date, count($rels) ? sprintf("Există %s definiții asociate", count($rels)) : "Nu există nicio definiție asociată");
    continue;
  }
   
  // Check that the definition exists
  $def = Definition::get_by_id($rels[0]->refId);
  if (!$def) {
    addError($date, sprintf("Definiția cu id-ul %s nu există", $rels[0]->refId));
    continue;
  }

  // Check that we haven't had the same word or a similar one in the past.
  // Currently we look for words that contain, or are contained by, the proposed word.
  $query = sprintf("select d.lexicon, w.displayDate from WordOfTheDay w, WordOfTheDayRel r, Definition d " .
                   "where w.id = r.wotdId and r.refId = d.id and r.refType = 'Definition' and w.displayDate < '%s' and " .
                   "((instr('%s', lexicon) > 0) or (lexicon like '%%%s%%'))",
                   $date, $def->lexicon, $def->lexicon);
  $dups = db_getArrayOfRows($query);
  if (count($dups)) {
    $msg = "Cuvântul {$def->lexicon} seamnănă cu următoarele cuvinte deja propuse:";
    foreach ($dups as $dup) {
      $msg .= " {$dup[0]} ({$dup[1]})";
    }
    addInfo($date, $msg);
  }

  // Check that there is an image
  if (!$wotds[0]->image) {
    $assignedImage = assignImageByName($wotds[0], $def);
    if ($assignedImage) {
      $wotds[0]->image = $assignedImage;
      $wotds[0]->save();
      addInfo($date, "Am asociat definiția '{$def->lexicon}' cu imaginea {$assignedImage}");
    } else {
      addError($date, sprintf("Definiția '%s' nu are o imagine asociată (motivul alegerii: %s)", $def->lexicon, $wotds[0]->description));
      continue;
    }
  }

  // Check that the image file exists
  if (!$wotds[0]->imageExists()) {
    addError($date, sprintf("Definiția '%s' are imaginea asociată '%s', dar fișierul nu există", $def->lexicon, $wotds[0]->image));
    continue;
  }

  // Warn if the image has no credits
  if (!$wotds[0]->getImageCredits()) {
    addInfo($date, "Imaginea {$wotds[0]->image} nu are credite; verificați conținutul fișierului docs/imageCredits/wotd.desc");
  }
}

log_scriptLog("checkWotd: collected " . count($messages) . " messages");
if (count($messages)) {
  if ($firstErrorDate) {
    $today = date("Y-m-d", strtotime("today"));
    $days = daysBetween($today, $firstErrorDate);
    switch ($days) {
    case 0: 
    case 1: $subject = 'ACUM'; break;
    case 2: $subject = 'ASTĂZI'; break;
    case 3: $subject = 'cel târziu mâine'; break;
    default: $subject = sprintf("în %s zile", $days - 1);
    }
    $subject = 'Cuvântul zilei: acțiune necesară ' . $subject;
    $mailTo = array_merge($MAIL_INFO, $MAIL_ERROR);
  } else {
    $subject = 'Cuvântul zilei: notă informativă';
    $mailTo = $MAIL_INFO;
  }
  $mailTo = implode(', ', $mailTo);

  SmartyWrap::assign('numDays', NUM_DAYS);
  SmartyWrap::assign('messages', $messages);
  $body = SmartyWrap::fetch('email/checkWotd.ihtml');
  if ($sendEmail) {
    log_scriptLog("checkWotd: sending email");
    mail($mailTo, $subject, $body, implode("\r\n", $MAIL_HEADERS));
  } else if (!$quiet) {
    print "---- DRY RUN ----\n";
    print "Către: $mailTo\nSubiect: $subject\n\n$body\n";
  }
  log_scriptLog("checkWotd: ending");  
}

/*********************************************************************/

function addError($date, $text) {
  global $firstErrorDate;
  addMessage('eroare', $date, $text);
  if (!$firstErrorDate || $date < $firstErrorDate) {
    $firstErrorDate = $date;
  }
}

function addInfo($date, $text) {
  addMessage('info', $date, $text);
}

function addMessage($type, $date, $text) {
  global $messages;

  $messages[] = array('type' => $type, 'date' => $date, 'text' => $text);
  log_scriptLog("checkWotd: adding message [$type] [$date] [$text]");
}

function daysBetween($date1, $date2) {
  $d1 = new DateTime($date1);
  $d2 = new DateTime($date2);
  return $d2->diff($d1)->days;
}

/**
 * Attempts to assign an image automatically for the given date and word.
 * Returns the image name on success, null on failure
 **/
function assignImageByName($wotd, $def) {
  global $staticFiles;

  $yearMonth = substr($wotd->displayDate, 0, 7);
  $strippedLexicon = stripImageName($def->lexicon);
  foreach ($staticFiles as $file) {
    if (StringUtil::startsWith($file, "img/wotd/$yearMonth/")) {
      $file = basename(trim($file));
      $strippedFile = stripImageName($file);
      if (preg_match("/{$strippedLexicon}\\.(png|jpg|jpeg)/", $strippedFile)) {
        return "$yearMonth/$file";
      }
    }
  }
  return null;
}

// Convert to Latin-1 and strip '-' and ' '
function stripImageName($fileName) {
  $s = StringUtil::unicodeToLatin($fileName);
  $s = str_replace(array('-', ' ', 'ş', 'ţ', 'Ş', 'Ţ'), array('', '', 's', 't', 's', 't'), $s);
  $s = mb_strtolower($s);
  return $s;
}

?>
