<?php

/*
 *  Easy Cash & Tax Forex API
 *  Hilfsfunktionen und Tools
 *
 *  Dieses Modul beherbergt einige wichtige und nützliche Hilfsfunktionen.
 *  Es ist 'required' für jedes andere PHP-Skript der EC&T Forex API.
 *
 *
 *
 *
 *
 */




// Hilfsfunktion: Prüft die Laufzeitumgebung eines Skriptes
// Somit können wir herausfinden, ob das Skript von Cron oder aus dem Web aufgerufen wurde
// In exotischen Serverkonfigurationen können diese Auswertungen fehlschlagen,
// aber in den allermeisten Fällen genügt diese Best Practice hier
function get_runenv() {

    // PHPs Server-API auslesen
    if ( php_sapi_name() === "cli" ) {
        if ( isset($_SERVER["TERM"]) ) {
             // Ausführung von der CLI aus, mit einem Terminal offen -> deutet auf manuell hin
             return "cli";
        } else {
             // Ausführung von der CLI aus, OHNE Terminal -> deutet auf Cron hin
             return "cron";
             }

    // Wenn die Umgebung nicht 'cli' ist, dann wahrscheinlich 'cgi-fcgi', 'fpm-fcgi', 'apache2handler' oder ähnliches
    // Ein Web-Agent hat also das Skript ausgeführt, wir wissen aber noch nicht genau welcher
    } else {
        // Bei Shared Hostern werden CronJobs möglicherweise nicht in der crontab angelegt,
        // sondern man legt im Panel eine Zeitsteuerung an, die das Skript per Web-Agent ausführt
        if ( substr_count( strtolower($_SERVER["HTTP_USER_AGENT"]), "cron") > 0 &&
             $_SERVER["REMOTE_ADDR"] == $_SERVER["SERVER_ADDR"] ) {
             // So ein Fall wäre, wenn der User-Agent z.B. 'cronBROWSER v1.1' ist, welcher auf dem selben
             // Server ausgeführt wird, wie das Skript selbst liegt -> deutet also auf Cron hin
             return "cron";

             // Diese Prüfung ist dennoch etwas schwach, da man diesen User-Agent ja auch spoofen könnte
             // Oder aber der Cron des Hosters läuft von einem anderen Server aus als der des Skriptes
             // Sollte das zum Problem werden, würde nur eine gänzlich andere Ausführungsmethode helfen,
             // z.B. mithilfe eines Pre-shared Secrets als Parameter (...php?psk=xyz)

             // Am sichersten wäre es jedoch möglich, wenn man wirklich selbst per crontab anlegen könnte und sich
             // eine Umgebungsvariable für das aufzurufende Skript anlegt (1 * * * * CRON=1 php ect-forex-cron.php)
             // Diese würde man dann auslesen und sich sicher sein können, dass es Cron war, der ausgeführt hat

        } else {
             // In allen anderen Fällen war es wohl wirklich ein Aufruf aus dem Web, per Browser oder Bot
             return "web";
             }
        }
}

// Diese Funktion 'sperrt' ein Skript für die Ausführung per Browser
// Somit wird sichergestellt, dass nur Cron regelmäßig das Skript ausführt
// und niemand von außen großen Schabernack treiben kann
function allow_cron_only() {

  // Frage ob jemand anderes als Cron ausgeführt hat
  if ( get_runenv() != "cron" ) {

    // Ein paar Daten sammeln
    $ip =     $_SERVER["REMOTE_ADDR"];
    $port =   $_SERVER["REMOTE_PORT"];
    $script = $_SERVER["SCRIPT_NAME"];
    $agent =  $_SERVER["HTTP_USER_AGENT"];
    $lang =   $_SERVER["HTTP_ACCEPT_LANGUAGE"];

    // Meldung in die Log-DB
    to_log("call from outside: {".$ip.":".$port.", ".$script.", ".$agent.", ".$lang."}", "warning", "security");
    error_log("EC&T Forex API: Ein Skript wurde von außen angefragt: {".$ip.":".$port.", ".$script.", ".$agent.", ".$lang."}");

    // Dann sofort die weitere Ausführung sang- und klanglos abbrechen
    die();
    }
}




// Hilfsfunktion, zur Bereinigung von Strings
// Lässt nur ganz eng alphanumerisch zu
function asciiPure($string) {
  // Zugelassen sind ausschließlich:
  // a-z A-Z 0-9
  return preg_replace('/[^a-zA-Z0-9]/', '_', $string);
}

// Hilfsfunktion, zur Bereinigung von Strings
// Lässt nur bestimmte, erlaubte Zeichen zu
function asciiAllowed($string) {
  // Zugelassen sind ausschließlich:
  // a-z A-Z 0-9
  // Leerzeichen
  // -+,.:@{}/
  return preg_replace('/[^a-zA-Z0-9\ \-\+\.\,\:\@\{\}\/]/', '_', $string);
}

// Hilfsfunktion, zur Bereinigung von Strings
// Lässt einen kleinen, erweiteren Zeichensatz zu
function asciiExtended($string) {
  // Zugelassen sind ausschließlich:
  // a-z A-Z 0-9
  // äöüßÄÖÜl(PL)
  // Leerzeichen
  // -+,.:@{}/                        ä       ö       ü       ß       Ä       Ö       Ü     ß(Cap.)  l(PL)
  return preg_replace('/[^a-zA-Z0-9\x{00E4}\x{00F6}\x{00FC}\x{00DF}\x{00C4}\x{00D6}\x{00DC}\x{1E9E}\x{0142}\ \-\+\.\,\:\@\(\)\[\]\{\}\/]/u', '_', $string);
}

// Hilfsfunktion, zur Bereinigung von API-Request-Inputs
// Lässt nur bestimmte, erlaubte Zeichen zu
function apiAllowed($string) {
    // Zugelassen sind ausschließlich:
    // a-z A-Z 0-9
    // .,:-*
  return preg_replace('/[^a-zA-Z0-9\.\,\:\-\*]/', '', $string);
}




// Hilfsfunktion, um zu prüfen, ob eine Uhrzeit wirklich im richtigen Format ist
function validateFormat_time( $t = "18:00" ) {

  // Versuche am erwarteten Trennzeichen zu splitten
  $part = explode(":", $t);

  // Sollten wir einen Wert der Form 'XX:YY' erhalten haben
  if ( count($part) == 2 ) {

          // Stunde strikt auf Ziffern prüfen
          // Diese Prüfung vergibt es, falls die Stunde einstellig, ohne führende Null ist
          if ( preg_match("/^[0-9]{1,2}$/", $part[0]) ) {
              // In Integer umwandeln
              $hour = (int) $part[0];
              // Auf Plausibilität prüfen
              if ( ($hour >= 0) && ($hour <= 24) ) { true; } else { return false; }
              } else { return false; }

          // Sollten wir ein verunfalltes Fragment der Form 'XX:' erhalten haben,
          // dann lassen wir es als Stunde trotzdem gelten
          if ( $part[1] == "" ) { $part[1] = "00"; }

          // Minute strikt auf Ziffern prüfen
          // Diese Prüfung vergibt es, falls die Minute einstellig, ohne führende Null ist
          if ( preg_match("/^[0-9]{1,2}$/", $part[1]) ) {
              // In Integer umwandeln
              $min = (int) $part[1];
              // Auf Plausibilität prüfen
              if ( ($min >= 0) && ($min <= 59) ) { true; } else { return false; }
              } else { return false; }

          // Die maximal zulässige Uhrzeit prüfen
          if ( ($hour >= 24) && ($min > 0) ) { return false; } else { return true; }

      } // Ende: part == 2


  // Wenn wir möglicherweise nur eine volle Stunde der Form 'XX' erhalten haben
  if ( count($part) == 1 ) {

          // Stunde strikt auf Ziffern prüfen
          // Diese Prüfung vergibt es, falls die Stunde einstellig, ohne führende Null ist
          if ( preg_match("/^[0-9]{1,2}$/", $part[0]) ) {
              // In integer umwandeln
              $hour = (int) $part[0];
              // Auf Plausibilität prüfen
              if ( ($hour >= 0) && ($hour <= 24) ) { return true; } else { return false; }
              } else { return false; }

       } // Ende: part == 1

// Wenn keine der beiden Bedingungen bis hier her gegriffen hat
// dann war es auch keine valide Uhrzeit
return false;
}




// Hilfsfunktion, um zu prüfen, ob ein Datum wirklich im richtigen Format ist
function validateFormat_date( $d = "2025-01-21" ) {

    // Versuche am erwarteten Trennzeichen zu splitten
    $part = explode("-", $d);

    // Sollten wir einen Wert der Form 'XXXX-YY-ZZ' erhalten haben
    if ( count($part) == 3 ) {

          // Jahr strikt auf Ziffern prüfen
          // Es muss vierstellig sein
          if ( preg_match("/^[0-9]{4}$/", $part[0]) ) {
              // In Integer umwandeln
              $year = (int) $part[0];
              // Etwas auf Plausibilität prüfen; bei Jahr theoretisch nicht notwendig
              if ( ($year >= 2000) && ($year <= 3000) ) { true; } else { return false; }
              } else { return false; }

          // Monat strikt auf Ziffern prüfen
          // Diese Prüfung vergibt es, falls der Monat einstellig, ohne führende Null ist
          if ( preg_match("/^[0-9]{1,2}$/", $part[1]) ) {
              // In Integer umwandeln
              $month = (int) $part[1];
              // Auf Plausibilität prüfen
              if ( ($month >= 1) && ($month <= 12) ) { true; } else { return false; }
              } else { return false; }

          // Tag strikt auf Ziffern prüfen
          // Diese Prüfung vergibt es, falls der Tag einstellig, ohne führende Null ist
          if ( preg_match("/^[0-9]{1,2}$/", $part[2]) ) {
              // In Integer umwandeln
              $day = (int) $part[2];
              // Auf Plausibilität prüfen
              if ( ($day >= 1) && ($day <= 31) ) { return true; } else { return false; }
              } else { return false; }

      } // Ende: part == 3

// Wenn obige Bedinung nicht angesprungen ist,
// dann war es kein ordentliches Datum
return false;
}




// Hilfsfunktion: Variablen-Name und Inhalt als Debug-Output mit print_r()
// Kudos: https://stackoverflow.com/questions/255312/how-to-get-a-variable-name-as-a-string-in-php/36921487#36921487
function v( $variable ){
      // read backtrace
      $bt   = debug_backtrace();
      // read file
      $file = file($bt[0]['file']);
      // select exact print_var_name($varname) line
      $src  = $file[$bt[0]['line']-1];
      // search pattern
      $pat = '#(.*)'.__FUNCTION__.' *?\( *?(.*) *?\)(.*)#i';
      // extract $varname from match no 2
      $var  = preg_replace($pat, '$2', $src);
      // print to browser
      echo '<pre>';
      echo trim($var) .": ";
      echo "(".@count($variable).")";
      print_r($variable);
      echo "</pre>";
}

// Etwas verbosere Variante von v() mit var_dump()
function vv( $variable ){
      // read backtrace
      $bt   = debug_backtrace();
      // read file
      $file = file($bt[0]['file']);
      // select exact print_var_name($varname) line
      $src  = $file[$bt[0]['line']-1];
      // search pattern
      $pat = '#(.*)'.__FUNCTION__.' *?\( *?(.*) *?\)(.*)#i';
      // extract $varname from match no 2
      $var  = preg_replace($pat, '$2', $src);
      // print to browser
      echo '<pre>';
      echo trim($var) .": ";
      var_dump($variable);
      echo "</pre>";
}




// Hilfsfunktion, um eine aufgetretene PHP-Warnung näher zu untersuchen
// und eine aussagekräftige Variable zurückzugeben, die bei der Untersuichung hilft
function catch_last_error( $message = " ", $token = NULL ) {

  if ( error_get_last() !== NULL && str_contains(error_get_last()["message"], $message) ) {
       error_log("CATCH!    ".$token);
       }
}




// Ermittelt die Differenz zwischen zwei Zeitobjekten, in Sekunden
// Nützlich zur Darstellung für die Skriptlaufzeit
function diffTime_s( $startTime, $stopTime ) {

  // Zeitdifferenz ausrechnen
  $diff = $startTime->diff($stopTime);
  // Differenz in Sekunden umrechnen
  // Es wird davon ausgegangen, dass das Skript nicht länger als 24h (!) läuft
  $m = $diff->h*60; $m += $diff->i;
  $s = $diff->s + $m*60;
  // Millisekunden ebenfalls noch abholen
  $u = round($diff->f, 3);
  $s = $s + $u;

return $s;
}

?>
