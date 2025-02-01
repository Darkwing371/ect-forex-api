<?php

/*
 *  Easy Cash & Tax Forex API
 *  API
 *
 *  Dieses Modul empfängt die Abfragen für EC&T und liefert Kurswerte für
 *  Währungen zurück, die danach vom Empfänger weiterverarbeitet werden können.
 *  Auch bietet sie eine Übersichtsliste aller zur Verfügung stehenden
 *  Währungen an, mit Link zum Datenprovider für nähere Recherche.
 *
 *
 *  Codepage: Western (Windows 1252)
 */




// Modul für Datenbankverbindung importieren
require_once "ect-forex-db.php";
// Modul für Hilfsfunktionen importieren
require_once "ect-forex-helper.php";

// Werden keine Daten per GET oder POST angefragt, dann agiere nicht als API
// Gib lediglich die Übersichtstabelle als Webseite an den Browser aus
if ( empty($_GET) && empty($_POST) ) { frontend_response(); } else {

    // Werden jedoch Daten angefragt, dann agiere als API
    // Schaue ob GET oder POST; bevorzuge GET
    if ( !empty($_POST) ) { $REQ = $_POST; }
    if ( !empty($_GET) )  { $REQ = $_GET; }
    // Wenn bis hier irgendetwas total schief gegangen ist, dann Fehler ausgeben und stoppen
    if ( $REQ === NULL ) { api_error("no request data received"); }

    // Nach erwarteten Variablen Ausschau halten
    // Unerlaubte Zeichen wegfiltern (auch wenn Werte dadurch verändert werden würden)
    $currency = trim(apiAllowed($REQ["currency"]), ",");
    $date = apiAllowed($REQ["date"]);
    $time = apiAllowed($REQ["time"]);
    $meta = apiAllowed($REQ["meta"]);
    $oneshot = apiAllowed($REQ["oneshot"]);

    // Pre-check: wenn 'currency' leer ist, dann sofort alles stoppen
    if ( $currency == "" ) { api_error("no currency requested"); }

    // Defaults erzeugen
    $now = new DateTimeImmutable("now", new DateTimeZone("Europe/Berlin"));
    $date_default = $now->format("Y-m-d");
    $time_default = $now->format("H:i");
    $meta_default = 0;
    $oneshot_default = false;


    // Variable auswerten: 'meta'
    // Wenn 'meta' ohne Wert gesetzt ist, dann implizit als '1' behandeln (Konvention)
    if ( isset($REQ["meta"]) && $meta == "" ) { $meta = 1; }
    // Wenn 'meta' explizit auf '1'/'true'/'timestamp' gesetzt ist, dann Level 1
    elseif ( $meta === "1" || $meta === "true" || $meta === "timestamp" ) { $meta = 1; }
    // Wenn 'meta' explizit auf '2'/'details' gesetzt ist, dann detaillierte Angaben, Level 2
    elseif ( $meta === "2" || $meta === "details" ) { $meta = 2; }
    // In allen anderen Fällen als '0'/'false' behandeln (default)
    else { $meta = $meta_default; }


    // Variable auswerten: 'oneshot'
    // Wenn 'oneshot' gesetzt, dann implizit als 'true' behandeln (Konvention)
    if ( isset($REQ["oneshot"]) && $oneshot == "" ) { $oneshot = true; }
    // Wenn 'oneshot' explizit auf 'true' gesetzt ist, dann logischerweise 'true'
    elseif ( $oneshot === "1" || $oneshot === "true" ) { $oneshot = true; }
    // In allen anderen Fällen als 'false' behandeln (default)
    else { $oneshot = $oneshot_default; }


    // Variable auswerten: 'date' (YYYY-MM-DD)
    // Wenn kein spezielles Datum gesetzt ist, dann das aktuelle benutzen
    if ( $date === "" ) { $date = $date_default; }
    // Strenge Input-Validierung: wenn Datum ungültig, dann voller Stopp
    if ( !validateFormat_date($date) ) { api_error("invalid date requested"); }


    // Variable auswerten: 'time' (HH:MM, H:M, HH, H)
    // Wenn keine spezielle Zeit gesetzt ist: dann die aktuelle nehmen
    if ( $time === "" ) { $time = $time_default; }
    // Strenge Input-Validierung: wenn Zeit ungültig, dann voller Stopp
    if ( !validateFormat_time($time) ) { api_error("invalid time requested"); }

    // Im Falle von einzelne Stunde ('HH') oder Stunden-Fragment ('HH:') die 'time' reparieren
    $time = rtrim($time, ":");
    if ( preg_match("/^[0-9]{1,2}$/", $time) ) { $time .= ":00"; }
    // Bei einstelliger Stunde/Minute die führende Null ansetzen
    list($h, $m) = explode(":", $time);
    if ( preg_match("/^[0-9]{1}$/", $h) ) { $h = "0".$h; }
    if ( preg_match("/^[0-9]{1}$/", $m) ) { $m = "0".$m; }
    // Die doppelt und dreifach überprüfte 'time' wieder zusammensetzen
    $time = $h.":".$m;
    // Stunden und Minute aber trotzdem zusätzlich einstellig weiterführen
    $h = ltrim($h, "0");
    $m = ltrim($m, "0");
    // Zu guter Letzt noch einen kompletten MySQL-Timestamp herstellen ('YYYY-MM-DD HH:MM:SS')
    $timestamp = $date ." ". $time .":00";


    // Variable auswerten: 'currency'
    // Hole alle jemals eingetragenen Währungen aus der Forex-DB
    $forex_currencies = dump_forexdb_currencies();
    // Falls 'currency' das besondere Zeichen '*' ist: Allstar-Abfrage
    if ( $currency === "*" ) {
        // Die Allstar-Abfrage ist eine Komplettabfrage aller Währungswerte
        // Sie ist kostspielig, dauert sehr lange (> 1 min) und ist relativ groß
        // Ohne Metadaten: ~ 125 kB (meta=0)
        // Mit Timestamp: ~ 250 kB (meta=1)
        // Detailliert: ~ 600 kB  (meta=2)
        // Diese Abfrage sollte praktisch nicht durchgeführt werden
        // Sie ist aber der Vollständigkeit halber mit implementiert und
        // eventuell für Test- und Entwicklungszwecke brauchbar
        $currencies = $forex_currencies;
        }
   else {
        // Andernfalls: normale Abfrage
        // Nur die übergebenen Währungen in ein Array zerlegen
        $currencies = explode(",", $currency);
        // Sichergehen, dass alle Ticker in Großbuchstaben vorhanden sind
        // Dies ist eine Konvention für Ticker (hoffentlich auch in alle Ewigkeit)
        array_walk($currencies, function(&$c, $k) { $c = mb_strtoupper($c, 'UTF-8'); });
        // Etwaige Doppelungen vermeiden
        $currencies = array_unique($currencies);
        }


    // Resultate-Array anlegen
    $result = array();

    // Wenn Metadaten gewünscht waren, dann diese Daten schon einmal vorlegen
    if ( $meta > 0 ) { $lut = get_lut_0("all+inactive"); }

    // Auf Sonderfall 'oneshot' überprüfen
    // Das heißt: nur der Kurs einer einzigen, und zwar der ersten, Währung aus der Liste wird direkt zurückgegeben
    if ( $oneshot ) {
      // Sollte 'oneshot' mit '*' kombiniert worden sein, dann ist dies undefiniert
      // Wir defaulten in so einem Fall zu BTC
      if  ( $currency === "*" ) { $currencies = array("BTC"); }
      }

    // Durch alle gewünschten Währungen gehen und die API-Response vorbereiten
    foreach ($currencies as $c) {

        // Prüfen, ob die angefragte Währung überhaupt eine vorhandene Währung ist
        if ( !in_array($c, $forex_currencies) ) {
            // Falls nicht, einen Error in die Metadaten schreiben
            $result[] = array( "currency" => $c, "meta" => array("error" => "not in database") );
            // Sonderfall: falls ein schneller Oneshot angefragt war, nur 'error' zurückgeben
            if ( $oneshot ) { $result = "error"; break; }
            // Zur nächsten Währung übergeben
            continue;
            }

        // SQL-Abfragen vorbereiten
        // Abfrage A: um einen exakten Treffer bei einer bestimmten Fetching-Stunde zu erzielen
        $sql_exact = "SELECT * FROM $db_forex WHERE currency = '$c' AND HOUR(timestamp) = '$h' AND DATE(timestamp) = '$date' LIMIT 1";
        // Abfrage B: um einen Treffer am nächstgelegenen Zeitstempel zu erhalten
        $sql_nearest = "SELECT *, ABS(TIMESTAMPDIFF(SECOND, '$timestamp', timestamp)) AS seconds FROM $db_forex WHERE currency = '$c' ORDER BY seconds ASC LIMIT 1;";

        // Zuerst versuchen, einen exakten Treffer zu erhalten
        $res = $db->query($sql_exact);
        if ( mysqli_num_rows($res) == 1 ) {
             $row = $res->fetch_assoc();
             }
        else {
            // Andernfalls den Wert an der nächsten Näherung benutzen
            $res = $db->query($sql_nearest);
            if ( mysqli_num_rows($res) == 1 ) {
                 $row = $res->fetch_assoc();
                 }
            else {
                 // Wenn überraschenderweise hier aber auch nichts zu finden war,
                 // dann einen Fehler für die Währung zurückgeben
                 $result[] = array( "currency" => $c, "meta" => array("error" => "no value found") );
                 continue;
                 }
             }

        // Daten für die API-Response vorbereiten
        // Werte für den Normalfall
        $values = array( "currency" => $c, "price" => $row["price"]);

        // Wenn Metadaten Level 1 (Timestamp) angefragt wurden
        if ( $meta == 1 ) { $values["meta"] = array( "timestamp" => $row["timestamp"] ); }

        // Wenn Metadaten Level 2 (Details) angefragt wurden
        if ( $meta == 2 ) {

            // Interessante Daten aus der LUT herausfischen
            $name =   $lut[" ".$c]["name"];
            $type =   $lut[" ".$c]["type"];
            $id_lcw =   $lut[" ".$c]["id_livecoinwatch"];

            // Das Metadaten-Array vorbereiten
            $metadata = array();
            $metadata["timestamp"] = $row["timestamp"];
            $metadata["name"] =   $name;
            $metadata["type"] =   $type;
            $metadata["source"] = "";
            $metadata["link"] =   "";

            // Hardcoded: Abhängig vom Typ, die Datenquelle in Schönschrift erzeugen
            switch ($type) {
              case "fiat":   $metadata["source"] = "EZB"; break;
              case "crypto": $metadata["source"] = "LiveCoinWatch"; break;
              default:       $metadata["source"] = "";
              }

            // Hardcoded: Abhängig vom Typ eine URL zur externen Datenquelle erzeugen
            if ( $type == "fiat" ) {
               $link = "https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html";
               if ( isset($lut[" ".$c]) ) {
                 $link = "https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/eurofxref-graph-".mb_strtolower($c).".en.html";
                 }
               }
            if ( $type == "crypto" ) {
               $link = "https://www.livecoinwatch.com/";
               $sanitized_name = str_replace([" ", "-", "/", "\\"], "", $name);
               if ( isset($id_lcw) ) { $link .= "price/".$sanitized_name."-".$id_lcw; }
               }
            $metadata["link"] = $link;

            // Metadaten mit normalem Response-Array vereinen
            $values["meta"] = $metadata;
            unset($name, $type, $id_lcw, $link, $sanitized_name);
            } // Ende: Metadaten vorlegen

        // Für diesen Durchgang ist dies nun unser Ergebnis für diese Währung
        $result[] = $values;

        // Nach einer erfolgreichen Abfrage, den Request-Counter der Währung erhöhen
        increase_request_counter($c);

        // Sonderfall: Wenn 'oneshot' angefragt ist, dann einfach nur
        // schnell den ersten Kurs ausgeben und sofort die Schleife beenden
        if ( $oneshot ) { $result = $row["price"]; break; }

    } // Ende: Loop durch alle gewünschten Währungen


    // Die finale API-Response nun als JSON-Array zurückgeben
    header("Content-Type: application/json; charset=utf-8");
    $response = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $response;

} // Ende: API-Response




// Gibt mögliche Fehlermeldungen der API aus; stoppt jede weitere Ausgabe
function api_error( $code = "400" ) {

  // Die eigentliche HTTP-Response ist zwar '200 OK',
  // aber die API gibt eigene Fehlermeldungen zurück
  // Die Ausgabe erfolgt ebenfalls als JSON
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode( array( "error" => (string) $code ) );

  // Jede weitere Verarbeitung stoppen
  die();
}




// Diese Funktion startet die Ausgabe der Währungsliste an den Browser
// Sie wird ausgeführt, wenn die PHP-Datei direkt aus dem Web aufgerufen wird,
// ohne GET-Requests, und deswegen nicht als API fungieren muss
function frontend_response() {

    // Ausgabe zu Webseite auf UTF-8 setzen
    header("Content-Type: text/html; charset=UTF-8");

    // Daten zu den Währungen aus der Datenbank abholen
    $currencies = list_all_currencies_data_latest();
    $count = count($currencies);

    // HTML-Ausgabe beginnen zusammenzubasteln
    $body = '<p>
              Die folgende Tabelle zeigt alle <b> '.$count.' Werte</b>, welche in der EC&T Forex API verfügbar sind.
              Darunter Fiat-Währungen, Krypto-Währungen, Stablecoins und Token.
              Benutzen Sie ‘Strg+F’, oder die Funktion ‘Auf Seite suchen’, und/oder den Tabellenfilter,
              um eine gewünschte Währung zu finden.
              <br><br>
             </p>

             <input class="filter-input" type="search" data-table="filter-table" placeholder="Tabelle filtern&nbsp;&hellip;">
              <br><br>
             <table class="filter-table">
                <thead>
                  <tr>
                    <td>Ticker</td>
                    <td>Name</td>
                    <td></td>
                    <td>Kurs in EUR</td>
                    <td>Letztes Datum</td>
                    <td>Link</td>
                  </tr>
                </thead>
                <tbody>
              ';
    // Codepage des HTML-Strings in UTF-8 konvertieren
    $body = mb_convert_encoding($body, "UTF-8", "CP1252");

    // Durch die Währungen cyclen und HTML-Ausgabe erstellen
    foreach ( $currencies as $c ) {

        // Die Codepage dieser Daten ist schon in UTF-8 und muss nicht konvertiert werden
        // 'currency', 'name', 'price', 'latest', 'type', 'source', 'link'
        list($currency, $name, $price, $date, $type, $source, $link) = array_values($c);

        $body .= '
                  <tr>
                    <td>'.$currency.'</td>
                    <td>'.$name.'</td>
                    <td></td>
                    <td>'.$price.'</td>
                    <td>'.$date.'</td>
                    <td><a class="'.$type.'" href="'.$link.'" target="_blank" title="'.$source.'"><span>'.$source.'</span></td>
                  </tr>
                 ';
        //if (++$i==5) {break;}
        }

    $part =  '</tbody>
              </table>
              <br>
             ';
    $body .= mb_convert_encoding($part, "UTF-8", "CP1252");


    // Das komplette HTML inklusive dem eben erstellten 'body' an den Browser ausgeben
    html( $body );
}




// Quick and dirty: das HTML-Gerüst
// Der eigentliche Inhalt von '<body>' muss extra zusammengebaut werden
function html( $body = '' ) {

    echo '
        <!doctype html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <meta name="viewport" content="width=device-width, minimum-scale=0.86, initial-scale=1, maximum-scale=5">
            <meta name="description" content="EasyCash&amp;Tax Forex API">
            <title>EasyCash&amp;Tax Forex API</title>

            <base href="/">

            <link rel="shortcut icon" href="data:image/x-icon;base64,AAABAAEAEBAAAAEACABoBQAAFgAAACgAAAAQAAAAIAAAAAEACAAAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAABDn84AdLbZAE+22wAmkMcAsdfrAGO02QA6m8wAQ57OAGCx2AC71+kAOZrLAKDP5gCl0egAptLoAE+m0gD2+v0ASqTRAEmm0gCdzuYAns7mAGW12wBSptMAttvtAHHJ5gBUqdMAw+LwAEuy2wBKos8ATqjRAEix2QC63+4AZbjbAEOhzwC93e4AO53NADGWxwCFwN8ARKHPAF2s1ADH7vkAMJbKAFis1gCJzegAPZzNAGGz2ACBweAAlMbhABuGwABittsASKvWAGe02gD3+/0AgL7eAMLe7QCEvNwA6Pz+AJHC3wCGudoAEH69AJjL5QBTrNQA0e31AFq32wBSqNIAN6rWADyr1QA/n84AbsbjAEij0ABXqdIAQJ7OAKHP5gCf2O4AYK3VAOLw9wBPtdsANJfJAC+i0QA7m80AjMvlAJXJ4wA/n88ATaXRAJ3N5gBOos8ALZLHAMTk8AB2v+AAxe76AC2PxgBWsNcAV7HZAFKy2QDF7foAz+v1AI3F4gCe1+0AYLTZAP7//wBYp9IA+fz9AHDN6QBUq9UAULjdAFm33AA5pdMAOpnKANLn8gBns9oAXbndAE+y2QAmkcYAYLDZAJXK5ACl1+wAOJvNAFCn0gBsttoAZMTkACeNxACBxuIAYrPZAJ/I4gCp1OkAN6fSAOX//wDx+PsAwODvAO32+gCDwN8A6//+AEehzwDm8vgAxen1AHG83wB+w+MAXrDYADWXygCc0uoAecPjAGbC4gBTp9IAdb3fAGO12QD///8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAkJCQkJCQkJCQkJCQkJCQkJCQUEYRH1wthGsuOHpKkJCQkIMmADJDbUlFQmFoj5CQkJAcTzY5eDCJGCUsF2eQkJCQeViQkH2OVRsKKYwakJCQkDs8CI2IW0w/alQ+bpCQkJANbwMvc2lRcjUJHouQkJCQEyIgAAUCMYpePYWHkJCQkAsGRBVXdnxjEoFfIZCQkJBHK05ZBx1AATOAfmKQkJCQUygQcCplTQGQkJCQkJCQkHtSIzp3QUskkJCQkJCQkJAMZmBWGUhaNJCQkJCQkJCQBBRdgjcnhnGQkJCQkJCQkA9/dQ50bBZkkJCQkJCQkJCQkJCQkJCQkJCQkJCQkAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=" />

            <script>
              (function(document) {
                "use strict";

                var LightTableFilter = (function(Arr) {

                  var _input;

                  function _onInputEvent(e) {
                    _input = e.target;
                    var tables = document.getElementsByClassName(_input.getAttribute("data-table"));
                    Arr.forEach.call(tables, function(table) {
                      Arr.forEach.call(table.tBodies, function(tbody) {
                        Arr.forEach.call(tbody.rows, _filter);
                      });
                    });
                  }

                  function _filter(row) {
                    var text = row.textContent.toLowerCase(), val = _input.value.toLowerCase();
                    row.style.display = text.indexOf(val) === -1 ? "none" : "table-row";
                  }

                  return {
                    init: function() {
                      var inputs = document.getElementsByClassName("filter-input");
                      Arr.forEach.call(inputs, function(input) {
                        input.oninput = _onInputEvent;
                      });
                    }
                  };
                })(Array.prototype);

                document.addEventListener("readystatechange", function() {
                  if (document.readyState === "complete") {
                    LightTableFilter.init();
                  }
                });

              })(document);
            </script>


            <style>

              :root {
                --rand:  0.0em;
                }

              @media screen and (min-width: 680px) {
                :root { --rand: 5em; }
                }

              body {
                background: #fffefd;
                line-height: 1.6em;
                padding-left: calc(var(--rand) + 0.7em);
                padding-top: 1.4em;
                }

              img.ect-fa-logo {
                margin-left: -0.7em;
                width: 100%;
                max-width: max-content;
                }

              p {
              padding: 0;
              margin: 0;
              max-width: 60em;
              padding-right: var(--rand);
              }

              input.filter-input,
              input.filter-input:focus {
                border: 1px solid black;
                outline: 0;
                top: -1px;
                position: relative;
                padding: 6px 4px 4px 6px;
                margin-left: -6px;
                border-radius: 1px;
                }

              table {
                border-collapse: collapse;
                padding: 0;
                margin-top: -1px;
                position: relative;
                }

              table td {
                padding: 0;
                white-space: nowrap;
                }

              table thead { font-weight: bold; }
              table thead td:nth-of-type(1):after { content: " / Name"; }
              table thead td:nth-of-type(2),
              table thead td:nth-of-type(5) { display: none; }

              table td:nth-of-type(2) {
                max-width: 10em;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis
                }
              table td:nth-of-type(4),
              table td:nth-of-type(5),
              table td:nth-of-type(6) { padding-left: 0.65rem; }

              table tr:first-of-type { border-top: 1px solid #cae8ee; }
              table tr { border-bottom: 1px solid #cae8ee; }
              table tbody td { line-height: 1.3em; font-size: 0.65em; }

              table tbody td:nth-of-type(1) { border-top: 4px solid transparent; }
              table tbody td:nth-of-type(2) { border-bottom: 3px solid transparent; }

              table tbody td:nth-of-type(1),
              table tbody td:nth-of-type(2),
              table tbody td:nth-of-type(4),
              table tbody td:nth-of-type(5) { display: block; }

              table tbody td:last-of-type { vertical-align: middle; }
              table tbody td:last-of-type a { text-decoration: none; }
              table tbody td:last-of-type a span { display: none; }

              table tbody td:last-of-type a:before {
                width: 2em;
                height: 2em;
                margin-bottom: -0.35em;

                content: "";
                display: inline-block;
                margin-right: 0.2em;
                background-size: cover;
                background-image: url("data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAGAAAABgCAMAAADVRocKAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAADBQTFRFV5jtIi5HY374p6y2M9DT+Pn7a3OF/v7+wu/xqMj10tfkbcflP7nd+/v94+nw7/L25HrdiAAAA3lJREFUeNrUmu2WoyAMhlEMEbDJ/d/toraCXwgIe3bTOfNjOubxTUgIrQI3+1Q071W0cB8iRBv3HiGa+f8SRDv/K0E09L8QREv/M+EvAD5NLQeAyysbkOQZmInMSEQAmQTx7J1JayuEmMQ0WatH4poAoMX54n9lOIomqAQAo3vRix9h+v44IQwVAGj05n0WMP0kTNMwaXoNAG37+f5DBd4GO/I7ALvb9943EZ4waH4DYN07E+H9+xwsQRoGC+UAWv0fNXgFM2CwVApY7z9UsEYniNAMGDQUAn7+90ESe8KiAYoAm//N/fRLsQgFOHtaS5cAFn3vEYtZrUf3EjYQsDLGfAB4Ad86cP0HXCsFgFHbIECzWcwGUN8HCnobZtL1vg0xpEgQ9wK+/s+djWzg/kmCeBJwVa5k93nOBHgBjmCvOjOyXRWsCJsHwF2ArhchjIGAYaAsgAkE9AZvCkl7AS5GkAEAEyi47QQINlCgswBBCoS+v3AcvIIhMgqcAWGKI22ArVcQS8IJYIQHxKS7GHkFI5Yp0LEN28XIDs+VIGKLKAb40EjjzzgD8Ktj7exxv0o4f50VzI7d8MacOySm5mC2iuO1+DS2q3adexTGJgpYejNQBoidN1B23mSugkW2O3Tw/foGVQ5gNkZKtRjj3eAd+O8M5gCc6+DSuwyEAhTl5GAX3E7xcwY6BTkhQgpvrpPwGKBohK5ysANcJRCM2qvMA6A5EOC4xI7/wLmVvL/e5TCsVQS5f7uj3Eo+3mGnJMHSENwvPr7ZSYTsVnG8R+dFGnfMJyPV6S3KH373q3zTobqrP0ssaNeHpRozRWX7gUkGmBIFbltLJzCWKPiATI2SLNsyEVMJShbuaKdyuE9D6ZZJMjUNpXsyJIqQhXvy3HiuEEqp9GoTT7MJ0CHbSrruo5LTkDK2OMZvj5aSaJ42jqV+n4akuch10nnEAPZTlklNQ/rghfFSv0tD+WSn0ppSMQDxvPHVna5PiZaXbe/F+M4mpe29Oh+kbP9vAIjyOQ3vTjinpQSVAedEVz+jmaem9BJwquhTQb8+ZbKMx+g1APaJVtUVHBJdP0Tz3h07jFQ56cugEKABALcj1cWkWuezCjcbuNlbScBWAGQyBi7bda0vS/Hmg4d/6dvYUsD//414+4cG2j/20P7BjfaPnrR/eKY2wzv9I8AAfN6EPoLJvYEAAAAASUVORK5CYII=");
                }
              table tbody td:last-of-type a.fiat:before {
                background-image: url("data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAGAAAABgCAMAAADVRocKAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAABhQTFRF/v7/8e8VATGanalIJ0+Sm67XUW9vWXm92ugDBwAAA7JJREFUeNrsWema6iAMZTkh7//Gl60FQqil6p87Ruebamn2HBI05kc/+tGPfvS/EaU3fZE9cXxl+gJ3dpHgCgH8WeYH454+JiPprglIMr6gfQijiHejQZK9tX78it8SwcInkb/1QXxJ76rfeyVK6D5VY55KoBpc71uQfWgGwEVh+Q4/5Z919rZpjclfeBxr6vh4PUujADwPdVPU+4WAeOM0adtLzRnxKuhlhvR+mEuL0r0ieif/O7UDsJD+KACijn1KqVxsihB+00GhMq80IEYxajND7RBaHOy9JiL4LRNQ9e05nK5B0jfUT1X9tNbfj3M2ALYro8o/TBb5do37+wNPsNYxG5HCCgikjRSKOBZm0BnCcqoQc6rcpy0Q6hiN7mlugoBAPMrRFf+nxQbFgPBBvJg85Jdw7R5VMyseui0AblMAchHZDUzdE+BtRrfjr174ChfhWRC4gRfsJUFuP7qA1Cvz0TYfSQTfAVBR2tvzVQzwMtlOAaUBrzzNUSkVSyiz9xm8YgSQWzlJ+c5cjKnuSO4mRjazh+poT94J7vEIyTxRBcDWRiLkKogYnd/xFdJF+j+XY3lEWgAjkwwlYJmD7/cXcS0tqI+QbJiNbMZZALL1agqFdR3QtQU8NFalDnyO+kCrUiYJNkaWCYva34KiHEUhAJcCShphC+yuLGClJ9pGU74QgE/sBxg5wr3wkfMbTprbQjNpQC2zOxiA2kq23XkVAhHk5qM4H/neSUpXEY4VOKesyUOyDlolDK1KrjiBDmknCrLtoDlJZEzPUh/mSVgxv2LoxY61iocmC1BNiJA9M4wy0ngQ5I4QR09/dEUyR4yWaHWPmGbKFRyFMmUpBkxpqhW7KmIKOrQcVVykFWM3P1XYW+73s2pzp7g24UbPpZTea8DCRZ8lzo7Y3XDRZGt/ViGgPIwgQcpCbeBDv3OnnNR9nqass/poMZyqFpSTB7zCuq6WC391lVkHjMd+YT5S82ep0Xp6NxcpQRVVz1kqjeL2/BQviodwwR8LAeXMkqAN40EL2TKzl8ceOQ7EL/fPqsrydMOsC0dTLMgqrqvWtXJ1cEPzTIWxvOpR2gV/mBsjHWPdZZlXwGKu+6iqoWHWPXjpfh2uF1pGO4g5yWEwt/N9evW8ed0N0u2T530XHZlI6u8V7G60S8bdo+QVGua6e70Y7grI6RlV5hgCh41DyQ0BjwjfFuCeHMH+LQvc9y34BfkvWACHOgzkn0wwQuTcpaPum/MyDGsKQ/dPgAEAL4g1IWntof8AAAAASUVORK5CYII=");
                }
              table tbody td:last-of-type a.crypto:before {
                background-image: url("data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAGAAAABgCAMAAADVRocKAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAGBQTFRF////R8K+LDFC6enrvr/EOT5ObXF9LDNDiY2WLTdHyMvPLz5NMUlXNFplN2t0On2DPpOWRLSyQqenr+Tj4fX17vn5+v39/f7+Rru4U8bCZczIe9PQldzaxOvq1fHw9fz76I/YywAAA7JJREFUeNrMWtu2oyAMFWbOdBwvoKCVm/3/vxxrtdUKlkTPWicvfagrW8jOTgImyU+xayN797C+b5rrqc5v0lljtBqMEEWGH2Ot65tTnLdSGE28po3obwfdS6sV2TNt+xb/8s7se58wBG6vbkKTSNO2Qbx9tPu7KegqekOApgSAuY0lCNN9rH/Y7oAX0VqCtphgN4YcsM/bJDU5ZMp9YM9B/4PtIvSKkO9EOMX/zi7JU/wPCIFIN5qcZNrL1iuOn5UXwSfhAuW/LP0Z5wkwxn1d0tz/zyYMN0wAeE5pVsWFASNA7EIpTWsSs0kSEd2CjhYAIHIFAGcQzx7+KQs8YJba7eDsudAPAMuEBqdAXdCnleTzEhx2e+5WRKgebAEdo0vLu2B9w4lcna/80ywIoCRCJDqWrv3TtAo+LKYqD0pilr0BUB5W1StKhXiZRgJMyWYRGreEYGR/j64ImasvNCIRplTAFMqSRiUCUQ1KJkg1BTodGZvvPNnjhHpeAOt4kYYqwhwERCnuplQbS0HNsnqnWR16CXgI+Hrzq26nv2gRMe6KzwmwiLJDczTvonowgQ9xzMMIgHri6B55ljSy2BCX5HsAnhzl3wRQz4UscmoDAwA4ilpBncZzFAXAIBwdAWA0nXV0T3/eWhdxRIZiAGBSMbcssQsYOhfQZMkvII6OYwJIroEcHYpym7ShgtPxIEezLhbA7pRMvlUbKEcffYsIaw7r/DJUxfofR/JAlNmjqh/iKFG34Hw5ZVTZeUplHQ1gwtNBuVV9SKlctde+VKvSbd9WgkOsmuAhyKI3LLo1ZlYBd8i3R/Wye54QGKhUroa0PpixS4RlOwc81dk08PxthrkjwDm6OE1wgdZtOUmCZeg1A25SYX7Z7AVU8BTMURM6jJoVYcjjF5kuYI6uTkNWg+ZMlzsfy7e9AnB0fZ7jtmV3fNm3qR7AUfV2ImU2C8jX9H9sVA3Ogc1xwlMkmGfgy1ERXse53Pgqo2bu/Q26Z5tdT8D1NuqAUumCB7+FL2HBpVIEj8bnvuRNcRisnTOBqxb3aqxKnzrFypAJ3j8aHjwDui+NA0XUY7+/gvnELvlx/zOCly2cn+A/Sf59gSR5u/8fL7p+/YFI8kbhIu5+27/oBSgRd3uPvaoDXGZiIJQFXI3fLPgMxkjoZb76nt1ZSFM8hHa4Dwdk3K2+dQe+2HCfloH9JGGB0Qc/rdDGyeQUa3phjdbPD0+0NkY42SZnWtvIvndi/HhGNk3yY+y/AAMAuktR6V3WnWoAAAAASUVORK5CYII=");
                }

              table:after {
                content: "\2B29";
                position: absolute;
                text-align: center;
                width: 100%;
                }

          @media screen and (min-width: 360px) {
              table td:nth-of-type(2) { max-width: 15em; }
              }

            @media screen and (min-width: 420px) {
                table td:nth-of-type(2) { max-width: unset; }
                }

            @media screen and (min-width: 480px) {
                table tbody tr td { line-height: 1.4rem; font-size: 0.8em; }
                table tbody td:nth-of-type(1) { border-top-width: 5px; }
                table tbody td:nth-of-type(2) { border-bottom-width: 4px; }
              }


            @media screen and (min-width: 600px) {
                table tbody tr td { line-height: 1.6rem; font-size: 1em; }
                table tbody td:last-of-type a:before {
                    width: 1.65em;
                    height: 1.65em;
                    }
              }


            @media screen and (min-width: 960px) {

                body {
                  background-color: #fff;
                  background-image: linear-gradient(90deg, transparent var(--rand), #cae8ee var(--rand), #cae8ee calc(var(--rand) + 0.05em), transparent calc(var(--rand) + 0.1em)),
                                    linear-gradient(#eee .1em, transparent .1em);
                  background-size: 100% 1.6em;
                  }

                table tbody tr td { line-height: 1.6rem; font-size: 0.8em; }

                table thead td:nth-of-type(1):after { content: unset; }

                table thead td:nth-of-type(1), table tbody td:nth-of-type(1),
                table thead td:nth-of-type(2), table tbody td:nth-of-type(2),
                table thead td:nth-of-type(3), table tbody td:nth-of-type(3),
                table thead td:nth-of-type(4), table tbody td:nth-of-type(4),
                table thead td:nth-of-type(5), table tbody td:nth-of-type(5),
                table thead td:nth-of-type(6), table tbody td:nth-of-type(6) { display: table-cell; }

                table thead td:nth-of-type(1), table tbody td:nth-of-type(1) { padding-left: 0;    padding-right: 0; border-top: none; }
                table thead td:nth-of-type(2), table tbody td:nth-of-type(2) { padding-left: 1rem; padding-right: 0; border-bottom: none; }
                table thead td:nth-of-type(3), table tbody td:nth-of-type(3) { padding-left: 0;    padding-right: 0; }
                table thead td:nth-of-type(4), table tbody td:nth-of-type(4) { padding-left: 1rem; padding-right: 0; text-align: right; }
                table thead td:nth-of-type(5), table tbody td:nth-of-type(5) { padding-left: 1rem; padding-right: 0; text-align: left; }
                table thead td:nth-of-type(6), table tbody td:nth-of-type(6) { padding-left: 1rem; padding-right: 0; }

                table tr, table tr:first-of-type { border: none; }
                table tbody td { line-height: 1.6em; }

                table tbody td:last-of-type { vertical-align: top; }
                table tbody td:last-of-type a span { display: inherit; }
                table tbody td:last-of-type a:before { width: 1em;  height: 1em; margin-bottom: -0.15em; }
              }

            @media screen and (min-width: 1200px) {
                table tbody tr td { line-height: 1.6rem; font-size: 1em; }
              }

            </style>

        </head>

        <body>

          <img class="ect-fa-logo" alt="EasyCash&Tax-Forex-API-Logo" src="data:image/png;base64,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" />

        <br>
        <br>
        ';

    echo $body;

    echo '
         </body>
         </html>
         ';
}

?>
