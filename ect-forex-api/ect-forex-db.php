<?php

/*
 *  Easy Cash & Tax Forex API
 *  Datenbank-Verbindung
 *
 *  Dieses Modul managt die Datenbankverbindung und Datenbankoperationen
 *  Es ist 'required' für jedes andere PHP-Skript der EC&T Forex API
 *
 *
 *  Codepage: Western (Windows 1252)
 */




// TODO: EDIT HERE
// Verbindungsdaten des Servers und der Datenbank hier eintragen
$db_host  = "127.0.0.1";       // Server-IP (üblicherweise nur lokal)
$db_name  = "";                // Name der Datenbank (Schema)
$db_user  = "";                // Username, welcher Zugriff auf die DB hat
$db_pwd   = "";                // Passwort für diesen Username
$db_forex = "forex";           // Name der gewünschten Forex-Datenbanktabelle
$db_log   = $db_forex."_log";  // Name der zugehörigen Log-Tabelle





// Datenbankverbindung herstellen
$db = new mysqli($db_host, $db_user, $db_pwd, $db_name);

// Fehlerbehandlung für die Verbindung
if ($db->connect_error) {
    die("EC&T Forex API: Datenbank-Verbindung fehlgeschlagen. :'-( <br>" . $db->connect_error . "<br>Bitte Logindaten und/oder Zugriffsberechtigungen überprüfen.");
    }

// UTF-8 multi-byte als Character-Set verwenden
$db->set_charset('utf8mb4');




// Prüft anhand des Information Schemas, ob eine Tabelle schon existiert (MySQL >= 5)
// Wird am Ende dieses Funktionsdeklarationsteils ausgeführt; siehe unten
function does_table_exist($db, $db_name, $db_table) {

    // Erstelle die SQL-Abfrage, um die Existenz der Tabelle zu überprüfen
    $sql = "SELECT COUNT(*) AS count
            FROM information_schema.tables
            WHERE table_schema = '$db_name'
            AND table_name = '$db_table'
            LIMIT 1";

    // Führe die Abfrage aus
    $res = $db->query($sql);

    // Überprüfe, ob ein Ergebnis zurückgegeben wurde
    if ($res) {
        $row = $res->fetch_assoc();
        $res->free();
        return $row['count'] > 0;
    } else {
        // Falls die Abfrage fehlschlägt, einen Fehler auswerfen
        throw new Exception("EC&T Forex API: does_table_exist(): Abfrage fehlgeschlagen: " . $db->error);
    }
}

// Wrapper: Prüft ob im Speziellen die Tabelle in der Variable '$db_forex' (='forex') existiert
function does_forex_table_exist() {
  global $db, $db_name, $db_forex;
  return does_table_exist($db, $db_name, $db_forex);
}

// Wrapper: Prüft ob im Speziellen die Tabelle in der Variable '$db_log' (='forex_log') existiert
function does_log_table_exist() {
  global $db, $db_name, $db_log;
  return does_table_exist($db, $db_name, $db_log);
}




// Erstellt die Datenbanktabelle für die Forex-Daten
// Kursdaten können durch DECIMAL(33,12) mit einer utopischen Präzision
// von 21 Stellen und 12 Nachkommenstellen abgespeichert werden
define("DIGITS", 21);
define("DECIMALS", 12);
function create_forex_table() {
  global $db, $db_name, $db_forex;

  // Präzision der Kursdaten aus den globalen Konstanten holen
  // und für MySQLs numerischen Datentyp 'DECIMAL(M,D)' vorbereiten
  $M = DIGITS + DECIMALS;
  $D = DECIMALS;

  $sql = "CREATE TABLE $db_name.$db_forex
         (id        BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Index' ,
          currency  VARCHAR(8) NOT NULL COMMENT 'Currency Code' ,
          name      VARCHAR(32) NOT NULL COMMENT 'Currency Name' ,
          price     DECIMAL(".$M.",".$D.") NOT NULL COMMENT 'Price in EUR' ,
          source    VARCHAR(32) NOT NULL COMMENT 'Data Source' ,
          timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date, Time' ,
          timezone  VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Should be Europe/Berlin' ,
          PRIMARY KEY (id))
          ENGINE = InnoDB
          CHARSET = utf8mb4 COLLATE utf8mb4_general_ci
          COMMENT = 'EC&T Forex Rate Database with EUR as base currency'";

  $res = $db->query($sql);

  // Fehlerbehandlung
  if ($res) {
    to_log("forex db created: {".$db_forex."}", "system", "event");
    error_log("EC&T Forex API: create_forex_table(): Die Datenbank '".$db_forex."' existierte noch nicht und wurde nun erstellt.");
  } else {
    throw new Exception("EC&T Forex API: create_forex_table(): Die Datenbank konnte nicht erstellt werden: " . $db->error . ".");
    }

}

// Erstellt die Datenbanktabelle für die Log-Einträge
// Diese Funktionalität hilft uns, uns ein paar Hinweise abzuspeichern,
// wie sich das System verhält und ob es zu Ungereimtheiten kommt
function create_log_table() {
  global $db, $db_name, $db_log;

  $sql = "CREATE TABLE $db_name.$db_log
         (id        BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Index' ,
          type      VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'info' COMMENT 'Info, Warning, Error, System' ,
          topic     VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'general' COMMENT 'General, Event, Security, Cron' ,
          message   VARCHAR(4096) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '/' COMMENT 'Meaningful Message' ,
          timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date, Time' ,
          timezone  VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Should be Europe/Berlin' ,
          PRIMARY KEY (id))
          ENGINE = InnoDB
          CHARSET = utf8mb4 COLLATE utf8mb4_general_ci
          COMMENT = 'EC&T Forex API Log'";

  $res = $db->query($sql);

  // Fehlerbehandlung
  if ($res) {
    to_log("log db created: {".$db_log."}", "system", "event");
    error_log("EC&T Forex API: create_log_table(): Die Datenbank '".$db_log."' existierte noch nicht und wurde nun erstellt.");
  } else {
    throw new Exception("EC&T Forex API: create_log_table(): Die Datenbank konnte nicht erstellt werden: " . $db->error . ".");
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
  // -,.:@{}
  return preg_replace('/[^a-zA-Z0-9\ \-\.\,\:\@\{\}]/', '_', $string);
}




// Hauptfunktion, um Nachrichten in die Log-DB einzutragen
// $msg:   eine beliebige, kurze Nachricht
// $type:  info, warning, error, system
// $topic: general, event, security, cron
function to_log( $message, $type = 'info', $topic = 'general' ) {
  global $db, $db_log;

  // Wenn keine Nachricht mitgegeben wurde: Stopp
  if ( !$message ) { return false; }

  // Strings sicherheitshalber bereinigen
  $message = asciiAllowed($message);
  $type = asciiPure($type);
  $topic = asciiPure($topic);

  // Aktuelle Zeit nochmals speziell für die Log-Funktion abholen
  $currentTime = new DateTimeImmutable("now", new DateTimeZone("Europe/Berlin"));
  $timestamp  = $currentTime->format('Y-m-d H:i:s');
  $timezone  = $currentTime->format('P e');

  // Jetzt in Log-DB eintragen (hier mit Prepared Statement)
  $sql = $db->prepare("INSERT INTO $db_log (type, topic, message, timestamp, timezone) VALUES (?, ?, ?, ?, ?)");
  $sql->bind_param("sssss", $type, $topic, $message, $timestamp, $timezone);
  $res = $sql->execute();

  // Fehlerbehandlung
  if (!$res) {
    throw new Exception("EC&T Forex API: to_log(): Konnte nicht in Log-DB (".$db_log.") schreiben: " . $db->error . ".");
    return false;
    }

return true;
}




// Hauptfunktion, um Kursdaten in die Forex-DB einzutragen
// An dieser Stelle müssen wir uns sehr viel Mühe geben, dass wir keinen Unfug
// in die Datenbank eintragen und lieber alles doppelt und dreifach prüfen
// $currency: Kürzel der Währung [EUR, USD, BTC, XMR ...]
// $name:     Name der Währung [Euro, US-Dollar, Bitcoin, Monero ...]
// $price:    Kurs in EUR [Präzision: 21 Stellen und 12 Nachkommastellen]
// $source:   Name der externen Datenquelle als Hinweis hinterlassen
// Es wird erwartet, dass alle Daten/Zahlen als Strings vorliegen
function to_forex( $currency, $name, $price, $source ) {
  global $db, $db_forex;

  // Zuerst möglichen Whitespace entfernen
  $currency = trim($currency);
  $name = trim($name);
  $price = trim($price);
  $source = trim($source);

  // Daten auf Einhaltung des Reinheitsgebotes überprüfen
  // Wir wollen wirklich sichergehen, dass wir nur Zeichen in die Datenbank lassen,
  // die wir ok finden: [a-zA-Z0-9] bzw. [a-zA-Z0-9 -,.:@{}]
  $currency_clean = asciiPure($currency);
  $name_clean     = asciiAllowed($name);
  $price_clean    = asciiAllowed($price);
  $source_clean   = asciiAllowed($source);
  // Prüfen, ob diese Reinigung zu einer Änderung geführt hat
  if ( $currency != $currency_clean ||
       $name     != $name_clean     ||
       $price    != $price_clean    ||
       $source   != $source_clean      ) {
       // Falls ja, vertrauen wir den Daten nicht und brechen ab
       // Sobald dieser Fehler auftritt, insbesondere wenn er gehäuft auftritt,
       // sollte die Struktur der extrernen Daten erneut überprüft werden
       // Bis dahin sind fehlende Datenbankeinträge zu erwarten
       $cleaned = implode(", ", array($currency_clean, $name_clean, $price_clean, $source_clean));
       $contaminated = implode(", ", array($currency, $name, $price, $source));
       to_log("bad forex values received. data dismissed: {".$cleaned."}", "error", "event");
       error_log("EC&T Forex API: to_forex(): Kontaminierte Daten empfangen. Eintrag verworfen: {".$contaminated."}.");
       return false;
       }
  unset($currency_clean, $name_clean, $price_clean, $source_clean);

  // Prüfen, ob eine oder mehere Variablen leer sind oder völlig fehlen
  $missing = array();
  if ( mb_strlen($currency) == 0 || !isset($currency) ) { $missing[] = "currency"; }
  if ( mb_strlen($name) == 0     || !isset($name) )     { $missing[] = "name"; }
  if ( mb_strlen($price) == 0    || !isset($price) )    { $missing[] = "price"; }
  if ( mb_strlen($source) == 0   || !isset($source) )   { $missing[] = "source"; }
  // Falls ja: ausführliche Meldung und Stopp
  // Sobald dieser Feher auftritt, gehäuft auftritt, oder gar regelmäßig auftritt,
  // sollte die Struktur der externen Daten überprüft und gegebenenfalls der Code angepasst werden
  if ( !empty($missing) ) {
      $missing = implode(", ", $missing);
      $leftover = implode(", ", array($currency, $name, $price, $source));
      to_log("missing forex value: {".$missing."}. data dismissed: {".$leftover."}", "error", "event");
      error_log("EC&T Forex API: to_forex(): Fehlende oder fehlerhafte Daten (".$missing."). Eintrag verworfen: {".$leftover."}.");
      return false;
    }

  // Währungskürzel überprüfen
  // Besteht aus typischerweise nur Großbuchstaben
  if ( $currency != mb_strtoupper($currency) ) {
    to_log("type warning: currency code {".$currency."} not uppercase", "warning", "event");
    error_log("EC&T Forex API: to_forex(): Typen-Warnung: Kurzname '".$currency."' besteht nicht aus Grossbuchstaben.");
    }
  // Besteht aus typischerweise nicht mehr als 8 Zeichen (3-4)
  $len = mb_strlen($currency);
  if ( $len > 8 ) {
    to_log("type warning: currency code {".$currency."} of non-canonical length {".$len."}", "warning", "event");
    error_log("EC&T Forex API: to_forex(): Typen-Warnung: Kurzname '".$currency."' untypisch lang (".$len.").");
    }
  unset($len);

  // Kursdaten überprüfen und normalisieren
  // Trennzeichen standardisieren: Kommas in Punkte umwandeln
  $price = str_replace(",", ".", $price);
  // Trennpunkte zählen
  $p = mb_substr_count($price, ".");
  // Wenn jetzt aber mehr als 1 Punkt vorhanden ist, haben wir ein kleines Problem
  if ( $p > 1 ) {
    // Wir interpretieren den hintersten Punkt als Dezimalpunkt,
    // alle anderen visuellen Tausender-Trennpunkte löschen wir
    // Finde dazu nun die Position des hintersten Punktes
    $pos = mb_strrpos($price, ".");
    // Ziehe davon die Menge der übrigen gefundenen Punkte ab, um die Stellen auszugleichen
    $pos = $pos - ($p-1);
    // Lösche nun alle Punkte; die Stellen verschieben sich damit
    $price = str_replace(".", "", $price);
    // Füge den einen Dezimalpunkt an der korrekten, hintersten Stelle wieder ein
    $price = substr($price, 0, $pos) .".". substr($price, $pos);
    }
  unset($p, $pos);

  // Den Kurs in Ganzzahl und Nachkommastelle aufsplitten
  list($int, $frac) = explode(".", $price);


  // Ganzzahl auf Plausibilität prüfen
  // Muss eine Zahl sein, dürfte aber im Zweifelsfall auch leer sein
  if ( !is_numeric($int) && $int != "") {
    to_log("type error: price of {".$currency."} is not a number. data dismissed: {".asciiPure($int)."}", "error", "event");
    error_log("EC&T Forex API: to_forex(): Typen-Fehler: Preis von '".$currency."' ist keine Zahl. Daten verworfen: {".$int."}.");
    return false;
    }
  // Darf nicht länger als 21 Stellen sein (Konstante: 'DIGITS')
  if ( mb_strlen($int) > DIGITS ) {
    to_log("type overflow: price of {".$currency."} is loo large. data dismissed: {".asciiPure($int)."}", "error", "event");
    error_log("EC&T Forex API: to_forex(): Typen-Ueberlauf: Preis von '".$currency."' ist hoeher als jemals erwartbar war. Daten verworfen: {".$int."}.");
    return false;
    }

  // Nachkommastellen auf 12 begrenzen (truncaten, nicht runden) (Konstante: 'DECIMALS')
  $frac = mb_substr($frac, 0, DECIMALS);

  // Nachkommastellen auf Plausibilität prüfen
  // Muss eine Zahl sein, darf aber auch leer sein (wenn keine Nachkommastellen vorhanden sind)
  if ( !is_numeric($frac) && $frac != "") {
    to_log("type error: decimals of {".$currency."} are not a number. data dismissed: {".asciiPure($frac)."}", "error", "event");
    error_log("EC&T Forex API: to_forex(): Typen-Fehler: Nachkommastellen von '".$currency."' sind keine Zahlen. Daten verworfen: {".$frac."}");
    return false;
    }

  // Gereinigte und überprüfte Kommazahl wieder zusammensetzen
  // Behandelt auch den unsauberen Edgecase, sollte im Ursprungspreis die
  // führende Null oder gar die Null nach dem Trennzeichen weggelassen worden sein
  $price = $int .".". $frac;
  unset($int, $frac);

  // Aktuelle Zeit nochmals speziell für die Eintragung der Kursdaten abholen
  $currentTime = new DateTimeImmutable("now", new DateTimeZone("Europe/Berlin"));
  $timestamp  = $currentTime->format('Y-m-d H:i:s');
  $timezone  = $currentTime->format('P e');

  // Jetzt überprüfte Daten in die Forex-DB eintragen (hier mit Prepared Statement)
  $sql = $db->prepare("INSERT INTO $db_forex (currency, name, price, source, timestamp, timezone) VALUES (?, ?, ?, ?, ?, ?)");
  $sql->bind_param("ssssss", $currency, $name, $price, $source, $timestamp, $timezone);
  $res = $sql->execute();

  // Fehlerbehandlung
  if (!$res) {
    to_log("forex db: write error", "error", "general");
    throw new Exception("EC&T Forex API: to_forexdb(): Konnte nicht in Forex-DB (".$db_forex.") schreiben: " . $db->error. ".");
    return false;
    }

return true;
}




// Prüft, ob Fetching-Daten schon für ein bestimmtes Datum zu einer bestimmten Uhrzeit existieren
// $date: 'YYYY-MM-DD' als String im MySQL-Format
// $hour: Zahl [0..24] als String oder Integer
function does_fetch_exist( $date, $hour = "0" ) {
  global $db, $db_log, $db_forex;

  // Prüfen, ob das übergebene $date dem gewünschten Format entspricht
  // MySQL: YYYY-MM-DD == PHP: Y-m-d
  $format = "Y-m-d";
  $formatCheck = DateTimeImmutable::createFromFormat($format, $date);
  if ($formatCheck && $formatCheck->format($format) === $date) {}
  else {
    error_log("EC&T Forex API: does_fetch_exist(): Das angefragte Datum (".$date.") ist kein korrektes Datum im MySQL-Format (YYYY-MM-DD).");
    return false;
    }

  // Prüfen, ob $hour eine korrekte Stunde wiederspiegelt
  if ( is_numeric($hour) ) { $hour = intval($hour); }
  if ( $hour >= 0 && $hour <= 24) {}
  else {
    error_log("EC&T Forex API: does_fetch_exist(): Die angefragte Zahl (".$hour.") ist keine valide Stunde.");
    return false;
    }

  // Korrektes Datumsobjekt herstellen
  // Berücksichtigt den Spezialfall '24 Uhr' und rückt Datum damit einen Tag weiter
  $dateObj = new DateTime($date, new DateTimeZone("Europe/Berlin"));
  $dateObj->setTime($hour, 0);

  // Extrahiere Datum und Stunde wieder als Einzelwerte
  $date = $dateObj->format($format);
  $hour = intval($dateObj->format("H"));

  // Resultat-Variablen anlegen, mit Default-Werten
  $log_fetch = false;
  $forex_fetch = false;

  // In Log-DB passende Einträge über einen ausgeführten Fetch finden
  $sql = "SELECT * FROM $db_log WHERE DATE(timestamp) = '$date' AND HOUR(timestamp) = $hour AND topic = 'event' AND message LIKE '%fetching time%'";
  $res = $db->query($sql);
  if (mysqli_num_rows($res) > 0) { $log_fetch = true; }

  // In Forex-DB passende Einträge zu vorhandenen Kursdaten finden
  $sql = "SELECT * FROM $db_forex WHERE DATE(timestamp) = '$date' AND HOUR(timestamp) = $hour";
  $res = $db->query($sql);
  if (mysqli_num_rows($res) > 0) { $forex_fetch = true; }

  // Die Resultate bewerten
  // Wenn beide Abfragen unterschiedlich ausfallen, dann ist irgend etwas komisch
  if ( $log_fetch != $forex_fetch ) {
    to_log("fetch data integrity questionable: please check db", 'warning', 'general');
    error_log("EC&T Forex API: does_fetch_exist(): Zum angefragten Zeitpunkt (".$date." ".$hour.":00) wurden keine konsistenten Daten gefunden.");
    }

  // Wenn beide Abfragen etwas gefunden haben, wird angenommen, dass alles okay ist
  if ( $log_fetch && $forex_fetch ) { return true; }

return false;
}




// Prüfen, ob alle Forex-API-Datenbanktabellen existieren; ansonsten: anlegen
// Könnte dann auch nach dem erstmaligen Starten auskommentiert werden
does_log_table_exist()   ? true : create_log_table();
does_forex_table_exist() ? true : create_forex_table();




?>
