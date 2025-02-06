<?php

/*
 *  Easy Cash & Tax Forex API
 *  Datenbank-Verbindung und Datenbank-Operationen
 *
 *  Dieses Modul managt die Datenbank-Verbindung und Datenbank-Operationen.
 *  Es ist 'required' für jedes andere PHP-Skript der EC&T Forex API.
 *  Ebenfalls sorgt es für die 'Installation' der EC&T Forex API, indem es
 *  bei der ersten Ausführung die Datenbank-Tabellen automatisch anlegt.
 *  Es muss hier das entsprechende Datenbank-Login angegeben werden (Zeile 19).
 *
 *
 */




// TODO: EDIT HERE
// Verbindungsdaten des Servers und der Datenbank hier eintragen
$db_host  = "127.0.0.1";       // Server-IP (üblicherweise nur lokal)
$db_name  = "";                // Name der Datenbank (Schema)
$db_user  = "";                // Username, welcher Zugriff auf die DB hat
$db_pwd   = "";                // Passwort für diesen Username

$db_forex = "forex";           // Name der gewünschten Forex-Datenbanktabelle
$db_log   = $db_forex."_log";  // Name der zugehörigen Log-Tabelle
$db_lut   = $db_forex."_lut";  // Name der zugehörigen Look-up-Tabelle




// Pre-Check: ob Datenbank-Zugangsdaten angegeben wurden
if ( $db_host == "" || $db_host == NULL ||
     $db_name == "" || $db_name == NULL ||
     $db_user == "" || $db_user == NULL ||
     $db_pwd  == "" || $db_pwd  == NULL    ) {
     // Falls eine Angabe fehlt, dann Meldung und abbrechen
     error_log("ECT&T Forex API: Zugangsdaten für die Datenbankverbindung fehlen. Bitte in 'ect-forex-db.php', Zeile 19, eintragen.");
     die();
     }

// Datenbankverbindung herstellen
$db = new mysqli($db_host, $db_user, $db_pwd, $db_name);

// Fehlerbehandlung für die Verbindung
if ($db->connect_error) {
    die("EC&T Forex API: Datenbank-Verbindung fehlgeschlagen. :'-( <br>" . $db->connect_error . "<br>Bitte Logindaten und/oder Zugriffsberechtigungen überprüfen.");
    }

// UTF-8 multi-byte als Character-Set verwenden
$db->set_charset('utf8mb4');

// Modul für Hilfsfunktionen importieren
require_once "ect-forex-helper.php";




// Prüft anhand des Information-Schemas, ob eine Tabelle schon existiert (MySQL >= 5)
// Wird am Ende dieses Funktionsdeklarationsteils ausgeführt; siehe ganz unten
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
      // Falls die Abfrage fehlschlägt, einen Fehler ausgeben
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

// Wrapper: Prüft ob im Speziellen die Tabelle in der Variable '$db_lut' (='forex_lut') existiert
function does_lut_table_exist() {
  global $db, $db_name, $db_lut;
  return does_table_exist($db, $db_name, $db_lut);
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

  // SQL-Statement zur Erstellung der Forex-DB
  $sql = "CREATE TABLE $db_name.$db_forex
         (id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Index' ,
          currency  VARCHAR(16) NOT NULL COMMENT 'Currency Code' ,
          price     DECIMAL(".$M.",".$D.") NOT NULL COMMENT 'Price in EUR' ,
          source    VARCHAR(32) NOT NULL COMMENT 'Data Source' ,
          timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date, Time (Europe/Berlin)' ,
          PRIMARY KEY (id))
          ENGINE = InnoDB
          CHARSET = utf8mb4 COLLATE utf8mb4_general_ci
          COMMENT = 'EC&T Forex Rate Database with EUR as Base Currency'";

  // Ausführung
  $res = $db->query($sql);

  // Ergebnis- und Fehlerbehandlung
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

  // SQL-Statement zur Erstellung der Log-DB
  $sql = "CREATE TABLE $db_name.$db_log
         (id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Index' ,
          type      VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'info' COMMENT 'Info, Warning, Error, System' ,
          topic     VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'general' COMMENT 'General, Event, Security, Cron' ,
          message   VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '/' COMMENT 'Meaningful Message' ,
          timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date, Time (Europe/Berlin)' ,
          PRIMARY KEY (id))
          ENGINE = InnoDB
          CHARSET = utf8mb4 COLLATE utf8mb4_general_ci
          COMMENT = 'EC&T Forex API Log'";

  // Ausführung
  $res = $db->query($sql);

  // Ergebnis- und Fehlerbehandlung
  if ($res) {
    to_log("log db created: {".$db_log."}", "system", "event");
    error_log("EC&T Forex API: create_log_table(): Die Datenbank '".$db_log."' existierte noch nicht und wurde nun erstellt.");
  } else {
    throw new Exception("EC&T Forex API: create_log_table(): Die Datenbank konnte nicht erstellt werden: " . $db->error . ".");
    }
}




// Erstellt die Datenbanktabelle für die Currency-Look-up-Table (LUT)
// Hier werden ein paar Rahmendaten für die einzelnen Forex-/Crypto-Werte abgelegt
function create_lut_table() {
global $db, $db_name, $db_lut;

  // SQL-Statement zur Erstellung der LUT-DB
  $sql = "CREATE TABLE $db_name.$db_lut
         (id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Index' ,
          currency VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Currency Code' ,
          name     VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Currency Name' ,
          type     VARCHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'crypto' COMMENT 'Currency Type' ,
          requests BIGINT UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Request Counter' ,
          inactive BOOLEAN NULL DEFAULT NULL COMMENT 'Stop Fetching' ,
          id_livecoinwatch VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'LiveCoinWatch Ticker' ,
          PRIMARY KEY (id))
          ENGINE = InnoDB
          CHARSET = utf8mb4 COLLATE utf8mb4_general_ci
          COMMENT = 'EC&T Currency Data Look-up Table'";

  // Ausführung
  $res = $db->query($sql);

  // Ergebnis- und Fehlerbehandlung
  if ($res) {
    to_log("lut db created: {".$db_lut."}", "system", "event");
    error_log("EC&T Forex API: create_lut_table(): Die Datenbank '".$db_lut."' existierte noch nicht und wurde nun erstellt.");
  } else {
    throw new Exception("EC&T Forex API: create_lut_table(): Die Datenbank konnte nicht erstellt werden: " . $db->error . ".");
    }

  // Geschwünschte Währungen aus der Datei 'ect-forex-currencies.init' einfügen
  // Es wird erwartet, dass diese Datei wirklich in UTF-8 ist, wegen der benötigten Zeichen
  // Vorbereitete CSV-Datei öffnen
  $filename = 'ect-forex-currencies.init';
  $file = fopen( $filename, 'r') or die("Fehler beim üffnen der Datei " . $filename ."!");

  // Durch die Zeilen loopen
  // Wir nehmen die von uns vorbereiteten CSV-Daten für bare Münze und wollen nicht,
  // dass die vordefinierten Trennzeichen von 'fgetcsv()' unseren Ablauf stören
  while ( ( $data = fgetcsv($file, NULL, ";", chr(21), chr(21)) ) !== FALSE)  {

      // Kommentarzeilen überspringen
      // Steuerzeichen für Kommentare: '//' am Anfang der Zeile
      $p = mb_strpos( trim($data[0]), "//", 0, "UTF-8");
      if ( $p === 0 ) { continue; }

      // CSV-Daten der Zeile einlesen
      $currency = trim($data[0]);
      $name     = trim($data[1]);
      $type     = trim($data[2]);
      $id_livecoinwatch = trim($data[3]);

      // Daten in LUT-DB eintragen (mit Prepared Statement)
      $sql = $db->prepare("INSERT INTO $db_lut (currency, name, type, id_livecoinwatch) VALUES (?, ?, ?, ?)");
      $sql->bind_param("ssss", $currency, $name, $type, $id_livecoinwatch);
      $res = $sql->execute();

      // Fehlerbehandlung
      if (!$res) {
        throw new Exception("EC&T Forex API: create_lut_table(): Konnte nicht in LUT-DB (".$db_lut.") schreiben: " . $db->error . ".");
        return false;
        }
  }
  fclose($file);
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

  // Jetzt in Log-DB eintragen (hier mit Prepared Statement)
  $sql = $db->prepare("INSERT INTO $db_log (type, topic, message, timestamp) VALUES (?, ?, ?, ?)");
  $sql->bind_param("ssss", $type, $topic, $message, $timestamp);
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
// $currency: Kürzel der Wührung [CHF, USD, BTC, XMR ...]
// $price:    Kurs in EUR [Präzision: 21 Stellen und 12 Nachkommastellen]
// $source:   Name der externen Datenquelle als Hinweis hinterlassen
// Es wird erwartet, dass alle Daten/Zahlen als Strings vorliegen
function to_forex( $currency, $price, $source ) {
global $db, $db_forex;

  // Zuerst möglichen Whitespace entfernen
  $currency = trim($currency);
  $price = trim($price);
  $source = trim($source);

  // Daten auf Einhaltung des Reinheitsgebotes überprüfen
  // Wir wollen wirklich sichergehen, dass wir nur Zeichen in die Datenbank lassen,
  // die wir ok finden: [a-zA-Z0-9 -+,.:@{}/]
  // Ganz selten haben Coins sehr sehr komische Tickerkürzel. Sollte ein solcher
  // Coin später neu hinzugefügt werden, muss in den hier verwendeten Funktionen
  // im Einzelfall der Zeichensatz behutsam erweitert werden.
  $currency_clean = asciiAllowed($currency);
  $price_clean    = asciiAllowed($price);
  $source_clean   = asciiAllowed($source);
  // Prüfen, ob diese Reinigung zu einer Änderung geführt hat
  if ( $currency != $currency_clean ||
       $price    != $price_clean    ||
       $source   != $source_clean      ) {
       // Falls ja, vertrauen wir den Daten nicht und brechen ab
       // Sobald dieser Fehler auftritt, insbesondere wenn er gehäuft auftritt,
       // sollte die Struktur der extrernen Daten erneut überprüft werden
       // Bis dahin sind fehlende Datenbankeinträge zu erwarten
       $cleaned = implode(", ", array($currency_clean, $price_clean, $source_clean));
       $contaminated = implode(", ", array($currency, $price, $source));
       to_log("bad forex values received. data dismissed: {".$cleaned."}", "error", "event");
       error_log("EC&T Forex API: to_forex(): Kontaminierte Daten empfangen. Eintrag verworfen: {".$contaminated."}.");
       return false;
       }
  unset($currency_clean, $price_clean, $source_clean);

  // Prüfen, ob eine oder mehrere Variablen leer sind oder völlig fehlen
  $missing = array();
  if ( mb_strlen($currency) == 0 || !isset($currency) ) { $missing[] = "currency"; }
  if ( mb_strlen($price) == 0    || !isset($price) )    { $missing[] = "price"; }
  if ( mb_strlen($source) == 0   || !isset($source) )   { $missing[] = "source"; }
  // Falls ja: ausführliche Meldung und Stopp
  // Sobald dieser Feher auftritt, gehäuft auftritt, oder gar regelmäßig auftritt,
  // sollte die Struktur der externen Daten überprüft und gegebenenfalls der Code angepasst werden
  if ( !empty($missing) ) {
      $missing = implode(", ", $missing);
      $leftover = implode(", ", array($currency, $price, $source));
      to_log("missing forex value: {".$missing."}. data dismissed: {".$leftover."}", "error", "event");
      error_log("EC&T Forex API: to_forex(): Fehlende oder fehlerhafte Daten {".$missing."}. Eintrag verworfen: {".$leftover."}.");
      return false;
    }

  // Währungskürzel/Tickersymbol überprüfen
  // Besteht typischerweise aus 3-4 Zeichen, wir lassen aber bis zu 16 zu
  // Das entsprechende Datenbank-Feld 'currency' lässt auch nur max. 16 Zeichen zu
  // Hier dürfte also niemals eine Warnung produziert werden, sondern es müsste
  // schon vorher von der API kein Ergebnis zurückgeliefert werden, da ein
  // abgeschnittenes Kürzel, also eine nicht existente Währung, angefragt wurde
  $len = mb_strlen($currency);
  if ( $len > 16 ) {
    to_log("type warning: currency code {".$currency."} of atypical length {".$len."}", "warning", "event");
    error_log("EC&T Forex API: to_forex(): Typen-Warnung: Kurzname '".$currency."' untypisch lang {".$len."}.");
    }
  unset($len);

  // Nun die übernommenen Kursdaten überprüfen und normalisieren
  // Wir erwarten hier einen gewissen Mindeststandard an 'freundlich formatierten' Kursen
  // Sollten zu wild lokalisierte Zahlenformate übergeben werden,
  // müsste hier an dieser Stelle noch einmal gesondert darauf reagiert werden
  // Zuerst: Trennzeichen standardisieren: Leerzeichen verwerfen
  $price = str_replace(" ", "", $price);
  // Trennzeichen standardisieren: Komma in Punkte umwandeln; Anzahl der ersetzten Kommas merken
  $price = str_replace(",", ".", $price, $count_comma);

  // Sollte eine Float exponentiell formatiert gewesen sein ('e', 'E'), dann gesondert vorbereiten
  if ( is_numeric($price) ) {
    if ( mb_substr_count($price, "e") == 1 || mb_substr_count($price, "E") == 1 ) {
      $price = (float) $price;
      // Float vorerst auf Überpräzision runden (Konstante 'DECIMALS'+1)
      $price = number_format($price, DECIMALS+1, ".", "");
      }
    }

  // Trennpunkte zählen
  $p = mb_substr_count($price, ".");
  // Wenn jetzt mehr als 1 Punkt vorhanden ist, haben wir ein kleines Problem
  if (  $p > 1 ) {
    // Wenn vorher entweder ALLE oder KEIN Komma ersetzt wurde, dann war die Zahl eine Integer
    if ( $count_comma != $p xor $count_comma != 0 ) {
        // Lösche einfach alle optischen Tausender Trennpunkte
        $price = str_replace(".", "", $price);
        }
    // Ansonsten ist die Zahl eine Dezimalzahl gewesen
    else {
        // Wir interpretieren dann den hintersten Punkt als Dezimalpunkt
        // Alle anderen visuellen Tausender-Trennpunkte löschen wir
        // Finde dazu nun die Position des hintersten Punktes
        $pos = mb_strrpos($price, ".");
        // Ziehe davon die Menge der übrigen gefundenen Punkte ab, um die Stellen auszugleichen
        $pos = $pos - ($p-1);
        // Lösche nun alle Punkte; die Stellen verschieben sich damit
        $price = str_replace(".", "", $price);
        // Füge den einen Dezimalpunkt an der korrekten, hintersten Stelle wieder ein
        $price = substr($price, 0, $pos) .".". substr($price, $pos);
        }
    }
  unset($p, $pos);

  // Den Kurs in Ganzzahl und Nachkommastelle aufsplitten
  // Dabei darauf achten, dass per Default das Array auf jeden Fall
  // zweistellig wird, um eine PHP-Warnung zu umgehen
  // Kudos: https://www.php.net/manual/en/function.list.php#128059
  list($int, $frac) = explode(".", $price) + [NULL, NULL];

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
    error_log("EC&T Forex API: to_forex(): Typen-Überlauf: Preis von '".$currency."' ist höher als jemals erwartbar war. Daten verworfen: {".$int."}.");
    return false;
    }

  // Nachkommastellen auf 12 begrenzen (truncaten, nicht runden) (Konstante: 'DECIMALS')
  $frac = mb_substr($frac, 0, DECIMALS);

  // Nachkommastellen auf Plausibilität prüfen
  // Muss eine Zahl sein, darf aber auch leer sein (wenn keine Nachkommastellen vorhanden sind)
  if ( !is_numeric($frac) && $frac != "") {
    to_log("type error: decimals of {".$currency."} are not a number. data dismissed: {".asciiPure($frac)."}", "error", "event");
    error_log("EC&T Forex API: to_forex(): Typen-Fehler: Nachkommastellen von '".$currency."' sind keine Zahlen. Daten verworfen: {".$frac."}.");
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

  // Jetzt überprüfte Daten in die Forex-DB eintragen (hier mit Prepared Statement)
  $sql = $db->prepare("INSERT INTO $db_forex (currency, price, source, timestamp) VALUES (?, ?, ?, ?)");
  $sql->bind_param("ssss", $currency, $price, $source, $timestamp);
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
  if ( mysqli_num_rows($res) > 0 ) { $log_fetch = true; }

  // In Forex-DB passende Einträge zu vorhandenen Kursdaten finden
  $sql = "SELECT * FROM $db_forex WHERE DATE(timestamp) = '$date' AND HOUR(timestamp) = $hour";
  $res = $db->query($sql);
  if ( mysqli_num_rows($res) > 0 ) { $forex_fetch = true; }

  // Die Resultate bewerten
  // Wenn beide Abfragen unterschiedlich ausfallen, dann ist irgend etwas komisch
  if ( $log_fetch != $forex_fetch ) {
    to_log("fetching data integrity questionable: please check db", 'warning', 'event');
    error_log("EC&T Forex API: does_fetch_exist(): Zum angefragten Zeitpunkt (".$date." ".$hour.":00) wurden keine konsistenten Daten gefunden.");
    }

  // Wenn beide Abfragen etwas gefunden haben, wird angenommen, dass alles okay ist
  if ( $log_fetch && $forex_fetch ) { return true; }

return false;
}




// Holt die komplette Look-up-Tabelle (LUT) ab,
// als assoziatives Array und stellt dem Array-Key ein Leerzeichen voran,
// um diesen sicher als einen String zu erhalten (hacky, aber leider nötig)
// Beachtet dabei, ob Währungen auf 'inactive' gesetzt wurden
function get_lut_0( $type = "all" ) {
global $db, $db_log, $db_lut;

  // Je nach gewünschtem Typ die Datenbankabfrage anpassen
  // Spare dabei im Normalfall die 'inactive' aus
  switch ($type) {
    case "fiat":         $sql = "SELECT * FROM $db_lut WHERE type = 'fiat' AND inactive IS NOT true"; break;
    case "crypto":       $sql = "SELECT * FROM $db_lut WHERE type = 'crypto' AND inactive IS NOT true"; break;
    case "all+inactive": $sql = "SELECT * FROM $db_lut"; break;
    default: /* "all" */ $sql = "SELECT * FROM $db_lut WHERE inactive IS NOT true";
    }

  // Resultate-Array initialisieren
  $lut = array();

  // Datenbankabfrage starten
  $res = $db->query($sql);
  // Bei Ergebnis ...
  if ( mysqli_num_rows($res) > 0 ) {
    // ... das Resultate-Array beschreiben
    while ( $row = $res->fetch_assoc() ) {
        // [ BTC] => id, 'BTC', 'Bitcoin', type, requests, inactive, id_livecoinwatch
        // Leerzeichen wird im Key vorangestellt
        $lut[" ".$row["currency"]] = $row;
        }
    }

  return $lut;
}

// Wrapper: Gibt nur die Währungsticker aus der LUT zurück,
// als assoziatives Array und MIT dem Leerzeichen in Key UND Value
// Erscheint sinnlos, aber wir benötigen genau solch ein Array später
// beim Herausfinden, welche Werte 'missing' waren bei einem Fetch
function list_currencies_0( $type = "all" ) {

  // Frage LUT ab
  $lut = get_lut_0( $type );
  // Ziehe nur die Spalte 'currency' heraus
  $currencies = array();
  foreach ($lut as $c) {
    $keyval = " ".$c["currency"];
    // [ BTC] => ' BTC'
    // Leerzeichen wird überall vorangestellt
    $currencies[$keyval] = $keyval;
    }

return $currencies;
}




function dump_forexdb_currencies() {
global $db, $db_forex;

    // Direkt die riesige Forex-DB nach Währungskürzel gruppieren
    // Wir lassen hier die Power von MySQL für uns arbeiten
    $sql = "SELECT currency FROM $db_forex GROUP BY currency";
    $res = $db->query($sql);
    if ( mysqli_num_rows($res) > 0 ) {
        while ( $row = $res->fetch_assoc() ) {
          $forex_currencies[] = $row["currency"];
          }
      }

return $forex_currencies;
}




// Holt die letzten Kurse von allen Währungen mit Datum ab
// Diese Funktion ist relativ aufwändig und sollte nur verwendet werden,
// um die Gesamtübersicht für die Front-End-Response der API zu erstellen
function list_all_currencies_data_latest() {
global $db, $db_forex;

    // Alle jemals eingetragenen Währungskürzel aus der Forex-DB holen
    // Somit erwischen wir wirklich jeden Eintrag, auch eventuell verwaiste
    $forex_currencies = dump_forexdb_currencies();

    // Währungen aus der LUT abholen
    $lut = get_lut_0("all+inactive");
    $lut_currencies = array_column( $lut, "currency" );
    // Währungen, die sich nicht in der LUT befinden, aber einen Forex-Eintrag haben
    $leftover_currencies = array_diff( $forex_currencies, $lut_currencies );
    // Währungen, geordnet nach LUT-Reiehenfolge und mit 'leftovers' hinten dran
    $ordered_currencies = array_merge( $lut_currencies, $leftover_currencies );
    unset($res, $leftover_currencies);


    // Alle Währungen mit Daten anreichern
    // 'currency', 'name', 'price', 'latest', 'type', 'source', 'link'
    $currencies = array();
    foreach ( $ordered_currencies as $c ) {

      $currency = array_key_exists(" ".$c, $lut) ? $lut[" ".$c]["currency"] : $c;
      $name =     array_key_exists(" ".$c, $lut) ? $lut[" ".$c]["name"] : NULL;
      $requests = array_key_exists(" ".$c, $lut) ? $lut[" ".$c]["requests"] : NULL;
      $type =     array_key_exists(" ".$c, $lut) ? $lut[" ".$c]["type"] : NULL;
      $id_lcw =   array_key_exists(" ".$c, $lut) ? $lut[" ".$c]["id_livecoinwatch"] : NULL;

      // Letzten Kurs der Währung abholen
      $sql = "SELECT * FROM $db_forex WHERE currency = '".$currency."' ORDER BY timestamp DESC LIMIT 1";
      $res = $db->query($sql);
      if ( mysqli_num_rows($res) > 0 ) {
            $currency_data = $res->fetch_assoc();
          } else unset($currency_data);

      $price = $currency_data["price"];
      $latest = $currency_data["timestamp"];
      $source = $currency_data["source"];

      // Hardcoded: Abhängig vom Typ die Datenquelle in Schönschrift erzeugen
      switch ($type) {
        case "fiat":   $source = "EZB"; break;
        case "crypto": $source = "LiveCoinWatch"; break;
        default:       $source = "Suche";
        }

      // Hardcoded: Abhängig vom Typ eine URL zur externen Datenquelle erzeugen
      if ( $type == "crypto" ) {
         $link = "https://www.livecoinwatch.com/";
         $sanitized_name = str_replace([" ", "-", "/", "\\"], "", $name);
         if ( isset($id_lcw) ) { $link .= "price/".$sanitized_name."-".$id_lcw; }
         }

      if ( $type == "fiat" ) {
         $link = "https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html";
         if ( array_key_exists(" ".$currency, $lut) ) {
           $link = "https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/eurofxref-graph-".mb_strtolower($currency).".en.html";
          }
         }

      if ( !isset($type) ) { $link = rtrim("https://www.startpage.com/do/search?q=currency+".$currency."+".$name, "+"); }


      $currencies[] = array( "currency" => $currency,
                             "name "    => $name,
                             "price"    => $price,
                             "latest"   => $latest,
                             "requests" => $requests,
                             "type"     => $type,
                             "source"   => $source,
                             "link"     => $link
                            );

      } // Ende: Cycle durch 'ordered_currencies'

return $currencies;
}



// Sammelt alle Währungen aus der Log-DB, die in den letzten 3 Monaten
// durchgehend einen Eintrag erhielten, dass sie 'missing' waren
// Wird benötigt, um das Ausmaß von 'missing' abzuschätzen und
// im Nachgang die Werte auf 'inactive' zu setzen
function scrape_lost_3M() {
global $db, $db_log;

  // Ergebnisvariable initialisieren
  $lost = array();

  // Zu allererst prüfen, ob die Datenbank überhaupt schon 3 Monate existiert
  //if ( db_age() < 90 ) { return $lost; }

  // Sammle alle Log-Einträge aus den letzten 3 Monaten zusammen,
  // wo eine Meldung über nicht gelieferte Werte eines Fetches existiert
  $sql = "SELECT * FROM $db_log
          WHERE type = 'info' AND topic = 'event'
          AND message LIKE 'fetched but missing%'
          AND timestamp >= NOW() - INTERVAL 3 MONTH";

  // Datenbankabfrage starten
  $res = $db->query($sql);

  // Wenn Ergebnisse vorhanden
  if ( mysqli_num_rows($res) > 0 ) {
    // Gehe durch die Zeilen
    while ( $row = $res->fetch_assoc() ) {
        // Sammle immer nur das Feld 'message'
        $messages[] = $row;
        }
    }

  // Alle 'messages' durchforsten und Liste in ein klares Array verwandeln
  foreach ( $messages as $msg ) {

    // Liste aus Message freilegen
    $raw = explode("missing:", $msg['message']);
    $raw = explode("{", $raw[1]);
    $raw = rtrim($raw[1], "}");

    // Liste als Array abspeichern
    $list[] = explode(", ", $raw);
    }

  // Jetzt die Schnittmenge von allen 'list'-Arrays bilden,
  // so erhalten wir die Währungen, die seit 3 Monaten durchgängig fehlen
  $lost = call_user_func_array("array_intersect", $list);

return $lost;
}




// Setzt in der LUT Währungen auf 'inactive',
// sodass diese nicht mehr von der externen API abgefragt werden
// Empfängt während der Wartung Währungen, die als 'lost' erkannt wurden
function lut_set_inactive( $currencies = NULL ) {
global $db, $db_lut;

  // Kurzer Sanity-Check
  if ( !is_array($currencies) ) { return; }

  // Hardcoded Sicherheitsnetz: falls die falschen Währungen 'inactive' gesetzt werden sollen
  $safetynet = array( "BTC", "XMR", "USD", "CHF", "GBP" );
  foreach ( $safetynet as $c ) {
    // Schaue, ob kritische Währung 'lost' ist und gib gleichzeitig deren Array-Position zurück
    if ( ($pos = array_search( $c, $currencies )) !== false ) {
        // Das hier sollte eigentlich nie getriggert werden
        // Falls doch, dann ist schon sehr lange vorher sehr viel schief gegangen
        // Deswegen geben wir dieses mal eine etwas eindringlichere Fehlermeldung aus
        error_log("EC&T Forex API: lut_set_inactive(): Achtung! Kritische Währung {".$c."} hätte auf 'inactive' gesetzt werden sollen. Bitte Datenbank, Code und Funktionalität des Systems überprüfen!");
        to_log("critical currency {".$c."} potentially lost, please check db and code", "error", "general");
        // Die Währung aus dem Array herausnehmen, um sie nicht 'inactive' zu setzen
        unset( $currencies[$pos] );
      }
    }
  unset($c, $pos, $securitynet);

  // Durch alle Währungen durchgehen
  $i = 0;
  foreach ( $currencies as $currency ) {

      // Statement vorbereiten für das 'inactive' setzen einer Währung
      // Grundsätzlich wollen wir das aber nur bei Krypto-Währungen tun,
      // da hier auf den hinteren Rängen immer sehr viel Fluktuation herrscht
      $sql = "UPDATE $db_lut SET inactive = true
              WHERE type = 'crypto' AND currency = '$currency'";

      // Datenbank-Update starten
      $do = $db->query($sql);

      // Wenn Änderungen in der DB stattgefunden haben, dann
      if ( $db->affected_rows ) {
        // mitzählen und mitschreiben
        $i++;
        $c[] = $currency;
        }
      }

  if ( $i > 0 ) {
    // Log-Meldung ausgeben
    $list = implode(", ", $c);
    error_log("EC&T Forex API: {".$i."} Währungen in der LUT auf 'inactive' gesetzt: {".$list."}.");
    to_log("set to inactive: {".$list."}", "system", "event");
    }

return $i;
}




// Erhöht den Request-Counter für eine Währung in der LUT um 1
// Diese Funktion wird getriggert, wenn eine Währung per API abgefragt wird
function increase_request_counter( $c = false ) {
global $db, $db_lut;

    // SQL-Statement um Request-Feld bei einer Währung um 1 zu erhöhen
    $sql = "UPDATE $db_lut SET requests = (requests + 1) WHERE currency = '$c'";

    // Datenbank-Update starten, wenn etwas übergeben wurde
    if ( $c !== false ) { $do = $db->query($sql); }

    // Wenn genau ein Änderungen in der DB stattgefunden hat, dann erfolgreich
    if ( $db->affected_rows == 1 ) { return true; }

return false;
}




// Gibt die Größe (Speicherplatzbelegung) der Datenbank(en) zurück
function getDBsize_MB( $table = "all" ) {
global $db, $db_name, $db_forex, $db_log, $db_lut;

    // Ergebnisvariable initialisieren
    $size = NULL;

    // Default-Fall: Größe ALLER angelegten Datenbank-Tabellen wird abgefragt
    if ( $table == "all" || $table == "" ) {

      $size  = getDBsize_MB($db_forex);
      $size += getDBsize_MB($db_log);
      $size += getDBsize_MB($db_lut);
      $size  = round($size, 2);
      }

    // Ansonsten wird nur die eine, gewünschte Datenbank-Tabelle abgefragt
    else {

      // Aus Information-Schema die Metadaten zur Datenbank-Tabelle abfragen
      // Gleich in MB umrechnen, mit zwei Kommastellen Genauigkeit
      $sql = "SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2)
              AS 'size'
              FROM information_schema.TABLES
              WHERE table_schema = '$db_name'
              AND   table_name = '$table'";

      // Datenbankabfrage starten
      $res = $db->query($sql);

      // Wenn eine Ergebniszeile zurückgeliefert wird
      if ( mysqli_num_rows($res) == 1 ) {
        // Den Wert abholen
        while ( $row = $res->fetch_assoc() ) { $size = (float) $row["size"]; }
        }
      }

return $size;
}




// Prüft, ob die Wartungsroutine an einem bestimmten Datum schon ausgeführt wurde
// $date: 'YYYY-MM-DD' als String im MySQL-Format
function was_maintenance_done( $date ) {
global $db, $db_log;

  // Ergebnisvariable initialisieren
  $done = false;

  // Schaue in der Log-DB nach,
  // ob am übergebenen Datum schon ein Eintrag über die Maintenance existiert
  $sql = "SELECT * FROM $db_log
          WHERE type = 'system'
          AND topic = 'event'
          AND message LIKE 'maintenance%'
          and DATE(timestamp) = '$date'";

  // Datenbankabfrage starten
  $res = $db->query($sql);

  // Wenn ein Ergebnis zurückgeliefert wird, dann hat Maintenance stattgefunden
  if ( mysqli_num_rows($res) > 0 ) { $done = true; }

return $done;
}




// Fischt das Installationsdatum der Forex-DB aus der Log-DB
function install_date() {
global $db, $db_log;

  $sql = "SELECT timestamp FROM $db_log
          WHERE message LIKE 'forex db created%'
          ORDER BY id ASC";

  // Datenbankabfrage starten
  $res = $db->query($sql);

  // Wenn eine Ergebniszeile zurückgeliefert wird
  if ( mysqli_num_rows($res) > 0 ) {
    // den 'timestamp' abholen
    $row = $res->fetch_assoc();
    $timestamp = $row["timestamp"];
    }

  // Timestamp im MySQL-Format (YYYY-MM-DD HH:MM:SS)
  // auf PHP-Notation mappen (Y-m-d G:i:s) und in Datumsobjekt umwandeln
  $timestamp = DateTimeImmutable::createFromFormat('Y-m-d G:i:s', $timestamp, new DateTimeZone("Europe/Berlin"));

return $timestamp;
}

// Gibt das Alter der Forex-DB zurück (in Tagen)
function db_age( $currentTime = null ) {

  // Falls kein Zieldatum mitgegeben, nehmen wir das aktuelle
  if ( !isset($currentTime) ) { $currentTime = new DateTimeImmutable("now", new DateTimeZone("Europe/Berlin")); }
  // Differenz aus Installationsdatum zu Zieldatum
  $age = install_date()->diff($currentTime);
  // Konvertiert zu Tagen
  $age = (int) $age->format('%a');

return $age;
}



// Prüfen, ob alle EC&T-Forex-API-Datenbanktabellen existieren; ansonsten: anlegen
// Könnte dann auch nach dem erstmaligen Starten auskommentiert werden,
// wenn die EC&T Forex API als 'installiert' gilt
does_log_table_exist()   ? true : create_log_table();
does_lut_table_exist()   ? true : create_lut_table();
does_forex_table_exist() ? true : create_forex_table();

?>
