<?php

/*
 *  Easy Cash & Tax Forex API
 *  Daten-Fetch von externen APIs
 *
 *  Dieses Modul beinhaltet die Funktionen für die Kursabfragen an
 *  verschiedenen externen APIs. Die empfangenen Daten werden in eine
 *  normalisierte Form gebracht, sodass die verschiedenen Quellen zu
 *  einander kompatibel sind und gemeinsam verarbeitet werden können.
 *  Für die API von 'LiveCoinWatch' muss ein API-Key angegeben werden (Zeile 19).
 *
 *
 */




// TODO: EDIT HERE
// API-Key für externen Service hier eintragen
// https://www.livecoinwatch.com/
$apikey_livecoinwatch = "";




// Ruft die API von 'freecodecamp' ab
// https://stock-price-checker-proxy.freecodecamp.rocks/
// Gut für einzelne Abfragen von Fiat-Währungen und Aktienkursen
function fetchAPI_freecodecamp( $currency = "BTC" ) {

  // Name der hiesigen Datenquelle
  $source = "freecodecamp";
  // Basis-Währung für diese Abfrage
  $base = "EUR";

  // Zusammensetzen der API-URL
  // https://stock-price-checker-proxy.freecodecamp.rocks/v1/stock/[symbol]/quote
  // Diese API erwartet direkt ein Währungspaar als Tickersymbol ('symbol'), z.B. 'BTCEUR'
  $symbol = $currency . $base;
  $url = "https://stock-price-checker-proxy.freecodecamp.rocks/v1/stock/".$symbol."/quote";

  // Wir holen die Daten aus der API ab und erhalten ein JSON-Objekt
  // Form: {"change":0,"changePercent":0,"close":0.95,"high":0.96,"latestPrice":0.95,"latestTime":"November 25, 2024","latestVolume":0,"low":0.95,"open":0.95,"previousClose":0.95,"symbol":"USDEUR","volume":0}
  $json = file_get_contents($url);

  // JSON in ein PHP-Array umwandeln
  $raw = json_decode( $json, true );

  // Benötigte Daten vorbereiten
  // Kurs aus API-Daten extrahieren
  $price = strval( $raw["latestPrice"] );

  // Vorbereitete Daten in das Resultate-Array einordnen
  // Leerzeichen voranstellen, um garantiert einen String als Key zu erhalten
  $data[" ".$currency] = array( $currency, $price, $source) ;

return $data;
}




// Ruft die API von 'frankfurter' ab
// https://frankfurter.dev/
// Gut für gebündelte Abfragen von Fiat-Währungen
function fetchAPI_frankfurter() {

  // Name der hiesigen Datenquelle
  $source = "frankfurter";
  // Basis-Währung für diese Abfrage
  $base = "EUR";
  // Hole die beobachteten Fiat-Währungen aus der Look-up-Table ab
  // Im Moment sind das genau alle, welche die Frankfurter-API anbietet
  $lut = get_lut_0("fiat");

  // Wenn an die Funktion ein Parameter übergeben wurde, erwarten wir,
  // dass es ein Array mit gültigen Währungstickern aus der LUT ist
  if ( func_num_args() == 1 ) {
      // Sollte es kein Array sein, aber ein Einzelwert, dann packen wir diesen der Form halber in ein Array
      $arg = func_get_arg(0);
      if ( is_array($arg) ) { $currencies = $arg; } else { $currencies[] = $arg; }
    } else {
      // Andernfalls alle Währungsticker aus der LUT herausholen
      $currencies = array_column( $lut, "currency" );
    }

  // Währungs-Ticker als kommaseparierte Liste vorbereiten
  $symbols = implode(",", $currencies);

  // Zusammensetzen der API-URL
  // https://api.frankfurter.dev/v1/latest?base=EUR&symbols=AUD,BGN,BRL,CAD,...
  // Die Basis-Währung ist standardmäßig 'EUR', wir geben sie aber dennoch explizit an
  // Die API erwartet eine kommaseparierte Liste von Tickerkürzeln ('symbols')
  $url = "https://api.frankfurter.dev/v1/latest?base=".$base."&symbols=".$symbols;

  // Wir holen die Daten aus der API ab und erhalten ein JSON-Objekt
  // Form: {"amount":1.0,"base":"EUR","date":"2024-12-18","rates":{"AUD":1.6602,"BGN":1.9558,"BRL":6.4544,"CAD":1.5022,"CHF":0.9382,...}}
  $json = file_get_contents($url);

  // Relevante JSON-Daten (hier: nur 'rates') in ein PHP-Array umwandeln
  $raw = json_decode( $json, true )["rates"];

  // Resultate-Array initialisieren
  $data = array();

  // Die Rohdaten der API durchgehen
  // Netterweise wird die Basis-Währung von der API in den Resultaten schon weggelassen
  foreach( $raw as $currency => $price) {

    // Vorbereitete Daten in das Resultate-Array einordnen
    // Leerzeichen voranstellen, um garantiert einen String als Key zu erhalten
    $data[" ".$currency] = array($currency, $price, $source);
    }

return $data;
}




// Ruft die API von 'LiveCoinWatch' ab
// https://www.livecoinwatch.com/tools/api
// Gut für sämtliche Kryptowährungen, praktisch kein API-Limit
// Man muss sich anmelden und einen API-Key generieren (oben eintragen, Zeile 19)
function fetchAPI_livecoinwatch() {
global $apikey_livecoinwatch;

  // Wenn API-Key fehlt, Fehlermeldung und sofort die weitere Verarbeitung abbrechen
  if ( $apikey_livecoinwatch == "" || $apikey_livecoinwatch == NULL ) {
      to_log("no api key: livecoinwatch", "error", "general");
      error_log("EC&T Forex API: fetchAPI_livecoinwatch(): Es wurde kein API-Key für den Service von LiveCoinWatch angegeben. Bitte in der Datei 'ect-forex-fetch.php', Zeile 19, eintragen.");
      return array();
      }

  // Name der hiesigen Datenquelle
  $source = "livecoinwatch";
  // Basis-Währung für diese Abfrage
  $base = "EUR";

  // Hole die LUT ab, für die beobachteten Krypto-Währungen, Token und Stablecoins
  $lut = get_lut_0("crypto");
  // Bereite ein Mapping vor, als simples assoziatives Array: [ currency] => [api-id]
  // Wichtig ist hier, dem Array-Key das Leerzeichen voranzustellen, um sicher einen String zu erhalten
  $map_to_apiid = array();
  foreach ($lut as $c) { $map_to_apiid[" ".$c["currency"]] = $c["id_livecoinwatch"]; }
  // Dasselbe noch mal umgedreht: [ api-id] => [currency]
  $map_to_currency = array();
  foreach ($lut as $c) { $map_to_currency[" ".$c["id_livecoinwatch"]] = $c["currency"]; }
  unset($c);

  // Wenn an die Funktion ein Parameter übergeben wurde, erwarten wir,
  // dass es ein Array mit gültigen Währungstickern aus der LUT ist
  if ( func_num_args() == 1 ) {
      // Sollte es kein Array sein, aber ein Einzelwert, dann packen wir diesen der Form halber in ein Array
      $arg = func_get_arg(0);
      if ( is_array($arg) ) { $currencies = $arg; } else { $currencies[] = $arg; }
    } else {
      // Andernfalls einfach alle Währungsticker aus der LUT herausholen
      $currencies = array_column( $lut, "currency" );
    }

  // Das Mapping anwenden: die Währungen in Ticker-IDs der API umwandeln
  foreach ( $currencies as $currency ) { $mapping[] = $map_to_apiid[" ".$currency]; }
  $currencies = $mapping;
  unset($currency, $mapping);

  // Wir haben möglicherweise sehr viele Daten abzufragen,
  // deswegen müssen wir stückchenweise vorgehen
  $size = count($currencies); // Zielmenge; alle gewhitelisteten Currencies müssen abgefragt werden
  $chunk = 100;               // API-Request-Limit (max. 100); wir fragen in dieser Stückelung ab
  $j = 0;                     // Ein Zähler, wie viele Werte schließlich abgefragt/geschrieben wurden
  $e = 0;                     // Ein Zähler für etwaig aufgetretene (HTTP-)Fehler
  $emax = 20;                 // Maximale Fehleranzahl bei welcher die Abfrage hart abgebrochen wird

  // Ergebnis-Variable für die einzelnen Abfragen initialisieren
  $result = array();
  $halt = NULL;

  // Pro Chunk die API abfragen
  for ( $i=0; $i < $size; $i+=$chunk ) {
  begin:

        // Den gewünschten Chunk aus dem Währungsarray herausnehmen
        $slice = array_slice( $currencies, $i, $chunk, false );

        // Daten für die Abfrage vorbereiten, laut API-Docs
        // https://livecoinwatch.github.io/lcw-api-docs/#coinsmap
        $data = json_encode(array("codes" => $slice, "currency" => $base, "sort" => "code", "order" => "ascending", 'offset' => 0, "limit" => 0, "meta" => false));
        $context_options = array( "http" => array ( "method" => 'POST',
                                                    "header" => "Content-type: application/json\r\n"
                                                              . "x-api-key: ".$apikey_livecoinwatch."\r\n",
                                                    "content" => $data
                                                  ));
        $context = stream_context_create($context_options);

        // Abfrage starten
        $stream = fopen("https://api.livecoinwatch.com/coins/map", "r", false, $context);

        // Bei HTTP-Fehler: einfach etwas warten und neu versuchen
        if ( $stream === false ) {
          echo "<br>"; echo "HTTP-Error. 5s Wartezeit.";
          // Fehlerzähler erhöhen
          $e++;
          error_log("EC&T: HTTP-Error " . $e);
          // Wenn zuviele Fehler aufgetreten sind, heute alles abbrechen
          if ( $e == $emax ) { $halt = true; break; }
          // Wartezeit: 5s
          sleep(5);
          // Zurück zum Schleifenanfang und noch mal versuchen
          goto begin;
          }

        // Wenn soweit kein HTTP-Fehler aufgetreten ist: Stream weiter verarbeiten
        $response = stream_get_contents($stream);
        // Die Response des aktuellen Chunk-Streams abspeichern
        $result[] = $response;

        // Kurz warten, bevor neuer HTTP-Request gestartet wird
        usleep(500000); // 0,5s
  } // Ende: Chunk-Loop

  // Wenn fertig, Stream schließen
  fclose($stream);
  unset($i, $data, $slice, $context, $stream);


  // Durch alle vereinten Resultate-Chunks cyclen
  foreach ( $result as $json ) {

        // Das erhaltene JSON-Array in ein PHP-Array umwandeln
        $raw = json_decode( $json, true );

        // Durch das PHP-Array gehen und gewünschte Werte herauspicken
        // Format: array(5) { ["code"]=> string(3) "BTC" ["rate"]=> float(100000.000) ["volume"]=> int() ["cap"]=> NULL ["delta"]=> array(6) {...}
        foreach ( $raw as $id => $values ) {

              // Tickersymbol extrahieren und Re-Mapping anwenden
              // Berücksichtigen, dass sich im Array-Key das Leerzeichen befindet,
              // welches wir vorher der API-ID vorangestellt haben, um mappen zu können
              $currency = $map_to_currency[" ".$values["code"]];

              // Kurs extrahieren, wenn vorhanden; ansonsten Währung komplett überspringen
              if ( is_numeric($values["rate"]) ) { $price = $values["rate"]; } else { continue; }

              // Vorbereitete Daten in das Resultate-Array einordnen
              // Leerzeichen voranstellen, um garantiert einen String als Key zu erhalten
              // Dies ist wichtig, da es Shitcoins mit Zahlen (!) als Name gibt, und
              // als Keys würden PHP-Arrays damit leider entsetzlichen Unfug bauen
              // Siehe: https://www.php.net/manual/de/language.types.array.php#language.types.array.key-casts
              $data[" ".$currency] = array($currency, $price, $source);

              } // Ende: durch einzelnes Resultat cyclen
        } // Ende: durch Resultate cyclen

  // Alle Keys wegen der Schönheit noch alphabetisch sortieren
  ksort($data, SORT_STRING);

  // Fehlerbehandlung
  if ( $e > 0 || $halt ) {
    if ($halt) { to_log("fetching errors: ".$e." (max), fetch aborted", "error", "event"); }
    else       { to_log("fetching errors: ".$e, "error", "event"); }
    }

return $data;
}




// Fügt mehrere Resultate-Arrays der API-Fetches zusammen
// Die übergebenen Arrays müssen strikt dieser Form entsprechen:
// ' currency' => ['currency', 'name', 'price', 'source']
// Sollten Duplikate zu Tickern ('currency') vorhanden sein, also aus
// nachgelagerten APIs erneut geliefert werden, so wird immer der Wert
// aus der ersten, bevorzugten API beibehalten
// Wir befüllen somit also das resultierende Array langsam mit Fallbacks
function array_mash( /* Empfängt Liste von Arrays */ ) {

  // Nimm die übergebenen Arrays der Fetches auf
  $fetches = func_get_args();

  // Plausibilitätsprüfung: Prüfe, ob es sich wirklich um Arrays handelt,
  // die auch (höchstwahrscheinlich) der gewünschten Form entsprechen
  // Loope dafür durch alle erhaltenen API-Fetches
  foreach ($fetches as $id => $fetch) {

    // Prüfe, ob es sich bei dem einzelnen Fetch überhaupt um ein gescheites Array handelt
    if ( !is_array($fetch) || !isset($fetch) || count($fetch) == 0 || $fetch === NULL ) {
         // Falls also nein: sofort verwerfen und nächsten Fetch ansehen
         unset( $fetches[$id] );
         continue;
         }

    // Loope durch einen einzelnen Fetch und schaue genauer auf die enthaltenen Daten
    foreach ($fetch as $key => $data) {

      // Schaue, ob die Array-Daten der gewünschten Form entsprechen
      // Teste auf: Array?    genau 3 Werte?       assoz. mit ' currency' => ['currency',...]? (Wichtig: Leerzeichen vorher entfernen beim Vergleich!)
      if ( is_array($data) && count($data) == 3 && ltrim($key, " ") === $data[0] ) { true; } else {

           // Wenn eine Sache davon nicht stimmt: verwerfe diesen Datensatz
           unset( $fetches[$id][$key] );

           // Edgecase: Es könnte passieren, dass an dieser Stelle der gesamte Fetch
           // leer wird. Dies würde geschehen, wenn alle Datensätze Fehler hätten
           // und gelöscht würden. Wir müssen diesen, dann leeren, Fetch aber nicht extra
           // löschen, da dies die nachfolgende Operation ('+=') automatisch tun wird

           } // Ende: Test auf ordnungsgemäße Form der Datensätze
      } // Ende: Loop durch jeden einzelnen Fetch
    } // Ende: Loop durch alle Fetches

  // Resultate-Array initialisieren
  $res = array();

  // Loope schließlich durch alle übrig gebliebenen und korrekten Arrays
  foreach ($fetches as $fetch) {
    // Nutze den Union-Operator ('+='), um die Werte der Arrays schrittweise aufzufüllen
    // Werte aus frühen (d.h. zuerst übergebenen) Arrays bekommen so Priorität
    // Auf diese Weise kann man die APIs etwas nach Wichtigkeit sortieren
    $res += $fetch;
    }

return $res;
}




// Vergleicht die gelieferten Werte eines Fetching-Events
// mit der Gesamtheit der gewünschten Werte aus der LUT ('Whitelist')
// Gibt die vermissten, also nicht gelieferten Werte als Array zurück
function fetch_detect_missing( $fetch ) {

  // Ermittelt die Differenz
  $diff = array_diff( list_currencies_0(), array_keys($fetch) );

  // Vorangestelltes Leerzeichen noch wegschmeißen
  $missing = array();
  foreach ( $diff as $d ) { $missing[] = ltrim($d, " "); }

return $missing;
}
?>
