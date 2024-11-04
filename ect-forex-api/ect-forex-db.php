<?php

/*
 *  Easy Cash & Tax Forex API
 *  Datenbank-Verbindung
 *
 *  Dieses Modul managt die Datenbankverbindung
 *  Es ist 'required' für jedes andere PHP-Skript der EC&T Forex API
 *
 *
 *  Codepage: Western (Windows 1252)
 */




// TODO: EDIT HERE
// Verbindungsdaten des Servers und der Datenbank hier eintragen
$db_host = "127.0.0.1";     // Server-IP (üblicherweise nur lokal)
$db_name = "";              // Name der Datenbank (Schema)
$db_user = "";              // Username, welcher Zugriff auf die DB hat
$db_pwd  = "";              // Passwort für diesen Username
$db_table = "forex";        // Name der gewünschten Datenbanktabelle

// Datenbankverbindung herstellen
$db = new mysqli($db_host, $db_user, $db_pwd, $db_name);

// Fehlerbehandlung für die Verbindung
if ($db->connect_error) {
    die("Verbindung fehlgeschlagen. :'-( <br>" . $db->connect_error . "<br>Bitte Logindaten und/oder Zugriffsberechtigungen überprüfen.");
    }

// UTF-8 multi-byte als Character-Set verwenden
$db->set_charset('utf8mb4');





// Prüft anhand des Information Schemas, ob eine Tabelle existiert (MySQL <= 5)
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
        throw new Exception("Abfrage fehlgeschlagen: " . $db->error);
    }
}


// Wrapper: Prüft ob im Speziellen die Tabelle in der Variable '$db_table' (='forex') existiert
function does_forex_table_exist() {
  global $db, $db_name, $db_table;
  return does_table_exist($db, $db_name, $db_table);
}


// Erstellt im Fall der Fälle eine neue Datenbanktabelle für die Forex-Daten
function create_forex_table() {
  global $db, $db_name, $db_table;

  $sql = "CREATE TABLE $db_name.$db_table
         (id        INT NOT NULL AUTO_INCREMENT COMMENT 'Index' ,
          date      DATE NOT NULL COMMENT 'Date' ,
          timestamp TIMESTAMP NOT NULL COMMENT 'Date, Time, TZ' ,
          currency  VARCHAR(3) NOT NULL COMMENT 'Currency Symbol' ,
          price     FLOAT NOT NULL COMMENT 'Price in EUR' ,
          PRIMARY KEY (id))
          ENGINE = InnoDB
          CHARSET=utf8mb4 COLLATE utf8mb4_general_ci
          COMMENT = 'EC&T Forex Rate Database'";

  $res = $db->query($sql);

  if ($res) {
    error_log("Datenbank '".$db_table."' existierte noch nicht und wurde nun erstellt.");
  } else {
    throw new Exception("Datenbank konnte nicht erstellt werden: " . $db->error);
    }

}


// Prüfen, ob Forex-Datenbanktabelle existiert; ansonsten: anlegen
//var_dump(does_forex_table_exist());
does_forex_table_exist() ? true : create_forex_table();



?>
