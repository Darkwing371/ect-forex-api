<?php

/*
 *  Easy Cash & Tax Forex API
 *  Cronjob-Skript
 *
 *  Dieses Skript muss per Crontab stündlich ausgeführt werden.
 *  In crontab: 1 * * * * php ect-forex-cron.php
 *  Es checkt die Serverzeit und stellt sicher, dass die Kursdaten der
 *  externen API immer zur gleichen europäischen Tageszeit abgeholt werden.
 *
 *
 *  Codepage: Western (Windows 1252)
 */





// Modul für Datenbankverbindung importieren
require_once "ect-forex-db.php";


// Hole die aktuelle Zeit in Europa ab, egal wo der Server steht und das Skript gerade läuft
// Wir nutzen 'Europe/Berlin' als Proxy für Deutschland und Österreich
//
// Die Annahme ist, dass Nutzer von EC&T sowieso in Deutschland oder Österreich
// ansässig sind und hier ihr Geschäft führen. Uns interessieren deshalb die Kurse
// zu Tageszeiten, die in dieser Zeitzone übliche Geschäftszeiten sind. Die
// Bewertung/Kursumrechnung kann so zu einem praktischen Tages-Eröffnungskurs
// oder Tages-Schlusskurs stattfinden, ohne übermäßig viele Datenpunkte zu speichern
$currentTime = new DateTimeImmutable("now", new DateTimeZone("Europe/Berlin"));

$currentTime_full = $currentTime->format("Y-m-d H:i:s P e");
$currentTime_date = $currentTime->format("Y-m-d");
$currentTime_hour = $currentTime->format("H");


// Logge Cron-Skript-Ausführung als Info in die Log-Datenbank
to_log("run @ hour ".$currentTime_hour, "info", "cron");

// Schreibe auch kleine Info-Ausgaben auf die Webseite
echo "Zeit: " . $currentTime_full . "<br>";
echo "Stunde: " . $currentTime_hour . "<br>";


// Zu welchen Stunden soll ein Kurs vom externen Dienstleister abgeholt werden?
// Zu dieser Zeit sollen Snapshots der Kurse in der eigenen Datenbank landen
//
// 06:00 als allgemeiner Tages-Eröffungskurs
// 18:00 wenn das Abendgeschäft in Restaurants und Bars beginnt und die (normalen) Börsen geschlossen sind
// 24:00 als allgemeiner Tages-Schlusskurs, auch für den stationären Handel
//(06:00 auch als möglicher Schlusskurs für Spätshops, Tankstellen, Nachtclubs etc.)
//
// In den EC&T-Optionen sollte der Nutzer einstellen können, welche Uhrzeit
// er als Tages-Eröffnungs- und -schlusskurs ansehen möchte. (Default: 6 und 18)
$fetchingTime = array(6, 18, 24);  // int [0..24]


// Die angegebenen Uhrzeiten/Stunden etwas auf Plausibilität prüfen
foreach ( $fetchingTime as &$hour ) {
  // Nur Integer zulassen
  if(is_numeric($hour)) { $hour = intval($hour); } else { $hour = NULL; }
  // Nichts größer als 24 Uhr zulassen
  if ($hour > 24) { $hour = NULL; }
  // 24 Uhr in 0 Uhr umwandeln
  if ($hour == 24) { $hour = 0; }
  }
  unset($hour);


// Überprüfen, ob generell die Zeit für einen Fetch gekommen ist
//
// Erinnerung: Dieses Cron-Skript sollte stündlich ausgeführt werden,
// bzw. 1 Minute nach der vollen Stunde, um sicherzustellen, dass wenigstens
// eine Kurskerze in dieser Stunde schon existiert
// In crontab: 1 * * * * php ect-forex-cron.php
// Die Zeitlogik liegt so im Skript selbst und wird nicht übermäßig auf die
// Ebene der Serverkonfiguration verschoben
//
// Unwahrscheinlicher Edgecase: Der Server steht in einem der Länder,
// welche Zeitzonen mit keinen vollen Stunden Zeitverschiebung haben
// Im Moment würde dann das Skript eine halbe Stunde "zu früh", bzw. eine
// halbe Stunde (oder gar viertel Stunde) "zu spät" die Daten abholen
// Um das abzufangen, müsste man das Skript von Cron immer eine Minute nach
// einer viertel Stunde ausführen
// In crontab: 1,16,31,46 * * * * php ect-forex-cron.php
// Dann sollte man allerdings auch die Log-Meldung über die schon vorhandenen
// Daten auskommentieren; siehe übernächster Kommentar
if ( in_array( intval($currentTime_hour), $fetchingTime) ) {

  // Prüfe, ob in dieser Stunde eventuell schon Daten abgeholt wurden
  $data = does_fetch_exist($currentTime_date, $currentTime_hour);
  if ($data) {
    // Gib Info über den Umstand aus, dass Daten schon vorhanden sind
    // Wir brauchen uns dann hier nicht weiter zu kümmern
    // Wird das Cron-Skript häufiger als einmal pro Stunde ausgeführt,
    // sollte man die Meldungen von to_log() und/oder error_log() lieber auskommentieren
    to_log("fetching time @ hour " . $currentTime_hour . ": data already present", "info", "event");
    error_log("EC&T Forex API: does_fetch_exist(): Daten fuer {".$currentTime_date." ".$currentTime_hour.":00} sind schon vorhanden.");
    echo "Daten für ".$currentTime_hour.":00 sind schon vorhanden<br>";

  // Falls keine Daten vorhanden sind: starte ein Fetching-Event
  } else {
    // Das Event in der Log-DB festhalten
    // Wichtiges Event, der Log-Eintrag sollte immer stattfinden
    to_log("fetching time @ hour " . $currentTime_hour, "info", "event");
    echo "Fetching Time!<br>";

    // TODO: Führe das Fetching von den externen APIs aus


    // Vorläufig: Dummy-Daten
    $dummy_fetch = array(
                   array( "TXT", "Text", ".1", "Dummy Source" ),
                   array( "BTC", "Bitcoin", "86420,12345678", "Dummy Source" ),
                   array( "USD", "US-Dollar", "210000000000000000000.000000000012", "Dummy Source" ),
                   array( "SHT", "Shitcoin", "-1.286.420,1234567891011", "Dummy Source" ),
                   array( "BABBL", "Babble Token", "0", "Dummy Source" ),
                   array( "DIGI", "Digital Coin", "1.00000000001255", "Dummy Source" ),
                   );

    // Trage Daten in Forex-DB ein
    foreach ( $dummy_fetch as $dataset ) {
        list($currency, $name, $price, $source) = $dataset;
        to_forex($currency, $name, $price, $source);
        }


    // TODO: Überprüfen, ob die Daten auch wirklich korrekt eingegangen sind
    // does_fetch_exist()
    // Gegebenenfalls auch noch tiefere Plausibilitätsprüfung durchführen
    // to_log("fetching time @ hour " . $currentTime_hour . ": data successfully received", "info", "event");
    // to_log("fetching time @ hour " . $currentTime_hour . ": data received with some errors", "warning", "event");
  }

} else {
  echo "No Fetching Time";
}



?>
