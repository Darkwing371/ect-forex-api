<?php

/*
 *  Easy Cash & Tax Forex API
 *  Cronjob-Skript
 *
 *  Dieses Skript muss per Crontab stündlich ausgeführt werden.
 *  In crontab: 1 * * * * php ect-forex-cron.php
 *  Es checkt die Serverzeit und stellt sicher, dass die Kursdaten der
 *  externen API immer zur gleichen europäischen Tageszeit abgeholt werden.
 *  Triggert auch monatliche und quartalsweise Wartungsroutinen.
 *
 *
 */




// Modul für Datenbankverbindung importieren
require_once "ect-forex-db.php";
// Modul für Hilfsfunktionen importieren
require_once "ect-forex-helper.php";

// Festlegen, dass nur der regelmäßige CronJob das Skript ausführen darf
allow_cron_only();





// Hole die aktuelle Zeit in Europa ab, egal wo der Server steht und das Skript gerade läuft
// Wir nutzen 'Europe/Berlin' als Proxy für Deutschland und Österreich
// Merke: Jeder Zeitstempel in der Datenbank ist normalisiert auf 'Europe/Berlin'
//
// Die Annahme ist, dass Nutzer von EC&T sowieso in Deutschland oder Österreich
// ansässig sind und hier das Geschäft führen. Uns interessieren deshalb die Kurse
// zu Tageszeiten, die in dieser Zeitzone übliche Geschäftszeiten sind. Die
// Bewertung/Kursumrechnung kann so zu einem praktischen Tages-Eröffnungskurs
// oder Tages-Schlusskurs stattfinden, ohne übermäßig viele Datenpunkte zu speichern
$currentTime = new DateTimeImmutable("now", new DateTimeZone("Europe/Berlin"));

$currentTime_full = $currentTime->format("Y-m-d H:i:s P e");
$currentTime_date = $currentTime->format("Y-m-d");
$currentTime_mon  = $currentTime->format("n");
$currentTime_day  = $currentTime->format("j");
$currentTime_hour = $currentTime->format("H");




// Variablen explizit initialisieren
$manually = $maintenance = $maintenance_monthly = $maintenance_quarterly = NULL;

// Logge Cron-Skript-Ausführung als Info in die Log-Datenbank
// Ist anfänglich zur Systemüberwachung nützlich, kann später auskommentiert werden
if ( get_runenv() != "cron" ) { $manually = " {manually}"; }
to_log("run @ hour ".$currentTime_hour.$manually, "info", "cron");

// Schreibe auch kleine Info-Ausgaben auf die Webseite
header("Content-Type: text/html; charset=UTF-8");
echo "Zeit: " . $currentTime_full . "<br>";
echo "Stunde: " . $currentTime_hour . "<br>";




// Prüfe, ob ein Zeitpunkt für Wartungsarbeiten gekommen ist
// Das ist: der erste Tag eines Monats, einmalig, noch vor dem ersten Fetch,
// also praktisch immer am Ende eines Monats
if ( $currentTime_day == 1 ) {
    // Prüfe, ob Wartung schon stattgefunden hat an diesem Tag
    if ( !was_maintenance_done($currentTime_date) ) {
      // Setze Flag auf true: monatlicher Wartungszeitpunkt ist gekommen
      $maintenance = true;
      $maintenance_monthly = true;
      // Schaue ob zusätzlich noch quartalsweise Wartung angesagt ist
      // Das ist: am 01.01., 01.04., 01.07., 01.10.,
      // also praktisch nach Ablauf Dezember, März, Juni, September
      if ( in_array($currentTime_mon, array(1, 4, 7, 10)) ) {
        $maintenance_quarterly =  true;
        }
      }
    }

// Führe gegebenenfalls die monatlichen Wartungsarbeiten aus
if ( $maintenance_monthly ) {
    // Ermittle Größe der Datenbank und schreibe sie in die Log-DB
    to_log("db size is ".getDBsize_MB()." mb", "system", "general");
    }

// Führe gegebenenfalls die quartalsweisen Wartungsarbeiten aus
if ( $maintenance_quarterly ) {
    // Ermittle Währungen, die schon seit 3 Monaten 'missing' sind
    $lost = scrape_lost_3M();
    $count = count($lost);
    $list = implode(", ", $lost);
    // Wenn etwas als 'lost' erkannt wurde
    if ( $count > 0 ) {
         // Gib Meldung darüber aus
         error_log("EC&T Forex API: {".$count."} Werte seit 3 Monaten vermisst: {".$list."}");
         to_log("lost for 3 months: {".$list."}", "system", "event");
         // Optional: Setze diese Werte in der LUT automatisch auf 'inactive'
         // lut_set_inactive( $lost );
         }
    else {
         // Wenn alles ok, nur kurze Meldung in die Log-DB
         to_log("lost for 3 months: none", "system", "event");
         }
    unset($lost, $list, $count);
    }

// Ganz allgemein am Schluss einer Wartung
if ( $maintenance ) {
  // Meldung über ausgeführe Wartung in die Log-DB
  to_log("maintenance finished", "system", "event");
  }




// Zu welchen Stunden soll ein Kurs vom externen Dienstleister abgeholt werden?
// Zu dieser Zeit sollen Snapshots der Kurse in der eigenen Datenbank landen
//
// 06:00 als allgemeiner Tages-Eröffungskurs
// 12:00 der Vollständigkeit halber mit abrufen
// 18:00 wenn das Abendgeschäft in Restaurants und Bars beginnt und die (normalen) Börsen geschlossen sind
// 24:00 als allgemeiner Tages-Schlusskurs, auch für den stationären Handel
// 06:00 auch als möglicher Schlusskurs für Spätshops, Tankstellen, Nachtclubs etc.
//
// In den EC&T-Optionen sollte der Nutzer einstellen können, welche Uhrzeit
// er als Tages-Eröffnungs- und -schlusskurs ansehen möchte. (Default: 6 und 18)
$fetchingTime = array(6, 12, 18, 24);  // int [0..24]


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
// Dann sollte man allerdings besser auch noch die Log-Meldung über die schon
// vorhandenen Daten auskommentieren; siehe übernächster Kommentar
if ( in_array( intval($currentTime_hour), $fetchingTime) ) {

  // Prüfe, ob in dieser Stunde eventuell schon Daten abgeholt wurden
  $data = does_fetch_exist($currentTime_date, $currentTime_hour);

  if ($data) {
    // Gib Info über den Umstand aus, dass Daten schon vorhanden sind
    // Wir brauchen uns dann hier nicht weiter zu kümmern
    // Wird das Cron-Skript häufiger als einmal pro Stunde ausgeführt, sollte
    // man die Meldungen von to_log() und/oder error_log() lieber auskommentieren
    to_log("fetching time @ hour " . $currentTime_hour . ": data already present", "info", "event");
    error_log("EC&T Forex API: does_fetch_exist(): Daten für {".$currentTime_date." ".$currentTime_hour.":00} sind schon vorhanden.");
    echo "Daten für ".$currentTime_hour.":00 sind schon vorhanden<br>";

  // Falls keine Daten vorhanden sind: starte ein Fetching-Event
  } else {
    // Das Event in der Log-DB festhalten
    // Wichtiges Event, dieser Log-Eintrag sollte immer stattfinden
    to_log("fetching time @ hour " . $currentTime_hour, "info", "event");
    echo "Fetching Time!<br>";

    // Lade das Modul für die externen API-Abfragen
    require_once "ect-forex-fetch.php";


    // Frage die API von 'frankfurter' ab (Fiat-Währungen)
    $fetch_frankfurter = fetchAPI_frankfurter();

    // Frage die API von 'LiveCoinWatch' ab (Krypto-Währungen, Stablecoins und Token)
    $fetch_livecoinwatch = fetchAPI_livecoinwatch();

    // Frage die API von 'freecodecamp' ab, als Notfall-Fallback (Einzelwerte)
    $fetch_freecodecamp_fallback_BTC = fetchAPI_freecodecamp("BTC");
    $fetch_freecodecamp_fallback_XMR = fetchAPI_freecodecamp("XMR");

    // Fasse die einzelnen Fetches zusammen
    // Bilde auf diese Weise auch mögliche Fallbacks für einzelne Tickerwerte
    // Je früher ein Fetch angegeben ist, desto höher seine Priorität
    $fetch = array_mash( $fetch_frankfurter,
                         $fetch_livecoinwatch,
                         $fetch_freecodecamp_fallback_BTC,
                         $fetch_freecodecamp_fallback_XMR
                       );

    // Schaue, welche Währungen bis hierher nicht geliefert wurden
    $missing = fetch_detect_missing( $fetch );

    // Es ist gut möglich, dass für einige kleinere Krypto-Währungen, mit wenig
    // Handelsaktivität, noch keine neuen Kursdaten vorhanden sind und deswegen
    // beim Fetchen ein paar Tage keine Daten von der API geliefert werden
    // Es kann aber auch sein, dass ein Token oder ein Shitcoin geruggt wurde,
    // oder als Scam entlarvt wurde und der Handel oder das Listing
    // komplett eingestellt wurde
    // Wenn also immer wieder die selben Verdächtigen in der Liste auftauchen,
    // oder die Liste stetig wächst, könnte man sich überlegen, ob man nicht
    // die Abfragen dieser Werte in Zukunft gleich ganz unterlässt. Zu diesem
    // Zweck gibt es das quartalsweise Wartungsintervall, wo die Funktion
    // 'scrape_lost_3M()' nachschaut, welche Werte durchgängig seit drei Monaten
    // schon nicht mehr geliefert wurden. Spätestens dann könnte man in der LUT
    // das Flag bei einer Währung händisch auf 'inactive' setzen
    // Optional kann man dies aber auch mit der Funktion 'lut_set_inactive()'
    // automatisch durchführen lassen; siehe 'maintenace_quarterly', Zeile 97
    // Die Werte werden dann einfach nicht mehr abgerufen. Die schon vorhandenen
    // historischen Daten bleiben allerdings immer in der Datenbank verfügbar

    // Wenn etwas 'missing' ist, dann tue etwas
    if ( !empty($missing) ) {
        $count = count($missing);
        $list = implode(", ", $missing);
        // Ausgabe ins Error-Log
        error_log("EC&T Forex API: Fetch konnte {".$count."} Werte nicht liefern: {".$list."}");
        // Ausgabe als Meldung in die Log-DB; wichtig, darauf nimmt 'scrape_lost_3M()' Bezug
        to_log("fetched but missing: ".$count." {".$list."}", "info", "event");
        // Optional: Ausgabe in den Browser
        // echo "<pre>"; v($missing); echo "</pre>";
      }


    // Schließlich: Trage die empfangenen Daten in die Forex-DB ein
    foreach ( $fetch as $dataset ) {
        list($currency, $price, $source) = $dataset;
        to_forex($currency, $price, $source);
        }


    // Ausführungszeit des Skriptes/Fetches messen
    $nowTime = new DateTimeImmutable("now", new DateTimeZone("Europe/Berlin"));
    $seconds = diffTime_s( $currentTime, $nowTime );
    // Eine Fertigmeldung in die Log-DB schreiben
    to_log("fetch finished in ".$seconds." s", "info", "event");

  } // Ende Fetching-Event

// Falls kein Zeitpunkt für einen Fetch gekommen war
} else {
  echo "No Fetching Time";
}

?>
