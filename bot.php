<?php
/**
 * pr0gramm inbox autoresponse Bot
 * 
 * @author    RundesBalli <rundesballi@rundesballi.com>
 * @copyright 2019 RundesBalli
 * @version   1.0
 * @license   MIT-License
 */

/**
 * Einbinden der Konfigurationsdatei.
 */
require_once(__DIR__.DIRECTORY_SEPARATOR."config.php");

/**
 * Abfragen des Sync und feststellen, ob sich ein Element in der Inbox befindet, falls nicht wird das Script beendet.
 * 
 * Erläuterung, warum nicht sofort /api/inbox/all abgefragt wird:
 * Die Response vom Sync ist nur ein Bruchteil so groß wie die Response von All.
 * Da das Script idR minütlich ausgeführt wird, spart das auf Dauer enorm Traffic ein.
 */
$inbox = apiCall("https://pr0gramm.com/api/user/sync?offset=9999999")['inbox'];
$inboxCount = $inbox['comments']+$inbox['mentions']+$inbox['messages']+$inbox['notifications']+$inbox['follows'];
if($inboxCount == 0) {
  die();
}

/**
 * Es ist wenigstens eine Nachricht vorhanden, also werden alle Nachrichten abgerufen und umgedreht,
 * damit die älteste Nachricht zuerst kommt.
 */
$response = apiCall("https://pr0gramm.com/api/inbox/all")['messages'];
$response = array_reverse($response);

/**
 * Durchgang der gesamten Response
 */
foreach($response as $key => $message) {
  /**
   * Da die Response auch Nachrichten zurückgibt, die bereits gelesen wurden, wird zuerst geprüft,
   * ob die Nachricht schon verarbeitet wurde.
   */
  if($message['read'] == 0) {
    /**
     * Innerhalb eines Code Feldes müssen bei Telegram die Zeichen ` und \ escaped werden.
     * @see https://core.telegram.org/bots/api#formatting-options
     */
    $message['message'] = str_replace("\\", "\\\\", $message['message']);
    $message['message'] = str_replace("`", "\`", $message['message']);
    
    if($message['type'] == 'message') {
      /**
       * Private Nachricht weiterleiten
       */
      $text = "WEITERGELEITETE NACHRICHT\n".
      "von @".$message['name']."\n".
      "https://pr0gramm.com/user/".$message['name']."\n".
      "-----------\n".
      date("d.m.Y, H:i:s", $message['created'])."\n".
      "===========\n".
      $message['message']."\n".
      "===========\n".
      "ENDE WEITERGELEITETE NACHRICHT";
      apiCall("https://pr0gramm.com/api/inbox/post", array('recipientName' => $forwardUser, '_nonce' => $nonce, 'comment' => $text));
      /**
       * Den User über die Weiterleitung informieren
       */
      $text = "Hallo ".$message['name'].",\n".
      "deine Nachricht wurde an den richtigen Account @".$forwardUser." weitergeleitet.\n".
      "Du bekommst schnellstmöglich eine Antwort und brauchst nichts weiter zu tun.";
      apiCall("https://pr0gramm.com/api/inbox/post", array('recipientName' => $message['name'], '_nonce' => $nonce, 'comment' => $text));
    } elseif($message['type'] == 'comment') {
      /**
       * Kommentar / Erwähnung
       * Problem: Mentions und Kommentarantworten sehen gleich aus.
       * Lösung: Erst prüfen ob eine Verlinkung an den falschen Account stattgefunden hat, falls nicht dann ist es eine Kommentarantwort.
       * $pr0Username aus der config vom apiCall
       */
      if(preg_match('/@'.$pr0Username.'/i', $message['message'], $matches) === 1) {
        /**
         * preg_match gibt bei Übereinstimmung 1 zurück, sonst 0 oder false bei Fehler.
         */
        $text = "Hallo ".$message['name'].",\n".
        "du hast den falschen Account markiert.\n".
        "Der korrekte Account ist @".$forwardUser.".\n".
        "Da jetzt die korrekte Markierung getätigt wurde, musst du nichts weiter unternehmen.";
        $response = apiCall("https://pr0gramm.com/api/comments/post", array('itemId' => $message['itemId'], 'parentId' => $message['id'], '_nonce' => $nonce, 'comment' => $text));
        /**
         * Wenn das tiefste Level erreicht wurde kann kein Kommentar mehr gepostet werden.
         * Damit der User trotzdem benachrichtigt wird, postet der Bot einfach unter den Post selbst, markiert den User und postet den relatierten Kommentar.
         */
        if(isset($response['error']) AND $response['error'] == 'maxLevels') {
          $text = "Hallo @".$message['name'].",\n".
          "relatiert: https://pr0gramm.com/new/".$message['itemId'].":comment".$message['id']." (letzte Kommentarebene erreicht)\n".
          "du hast den falschen Account markiert. Der korrekte Account ist @".$forwardUser.".\n".
          "Da jetzt die korrekte Markierung getätigt wurde, musst du nichts weiter unternehmen.";
          apiCall("https://pr0gramm.com/api/comments/post", array('itemId' => $message['itemId'], 'parentId' => 0, '_nonce' => $nonce, 'comment' => $text));
        }
      }
    } else {
      /**
       * Alle anderen Nachrichtentypen können ignoriert werden.
       * Das nächste Element wird geladen.
       */
      continue;
    }
  }
}
?>
