<?php
/** Handbuch · Kapitel „Gebietskarten“ – Erweiterung „gebietskarten“ (Extension::docs('manual'), nach „Bilder & Dateien“). */
?>
  <p class="lead">Mit Gebietskarten fassen Sie Flächen einer Karte zu <b>Gebieten</b> zusammen – zum Beispiel Regionalgruppen eines Vereins,
    die Kreise eines Verbands oder Vertriebsgebiete – und zeigen sie mit dem Block <b>„Gebietskarte“</b> auf der Website. Die Erweiterung
    schaltet die Administration unter <b>Administration → Funktionen &amp; Erweiterungen</b> ein (Paket „Gebietskarten“).</p>
  <p>Gebietskarten sind keine Straßenkarten: Es gibt keine Adressen, keine Kacheln und kein Zoomen – nur Flächen. Für Standorte und
    Wegbeschreibungen nutzen Sie weiterhin den Block „Karte“. Die Kartendaten kommen vom Bundesamt für Kartographie und Geodäsie
    (frei nutzbar); der Quellenvermerk steht automatisch unter jeder Karte.</p>

  <h3 id="gebietskarten-anlegen">Karte anlegen</h3>
  <ol class="doc-steps">
    <li>Öffnen Sie <b>Gebietskarten</b> im Menü und füllen Sie <b>Neue Karte</b> aus: Titel, <b>Ebene</b> (Bundesländer, Kreise und
      kreisfreie Städte oder Gemeinden) und die <b>Bundesländer im Ausschnitt</b> (keine Auswahl = ganz Deutschland).</li>
    <li>Wählen Sie, woher die Gebiete kommen: <b>hier pflegen</b> (Name, Farbe, Link und Infos je Gebiet in dieser Seite) oder
      <b>aus einer Datentabelle</b> (ein Eintrag = ein Gebiet, siehe unten).</li>
    <li>Für die Ebene <b>Gemeinden</b> laden Sie die Kartendaten der Länder einmal nach: <b>Gebietskarten → Kartendaten</b>, Länder
      ankreuzen, <b>Ausgewählte laden</b>. Danach liegen sie auf Ihrem Server.</li>
  </ol>

  <h3 id="gebietskarten-gebiete">Gebiete bilden</h3>
  <ol class="doc-steps">
    <li>Legen Sie rechts unter <b>Neues Gebiet</b> die Gebiete an (z. B. „Region Nord“, „Region Süd“).</li>
    <li>Wählen Sie oben bei <b>Zuordnen zu</b> ein Gebiet und klicken Sie auf die Flächen der Karte. Ein zweiter Klick gibt eine Fläche
      wieder frei. Jede Fläche gehört höchstens zu einem Gebiet – ordnen Sie sie einem anderen zu, wechselt sie dorthin.</li>
    <li><b>Mit der Tastatur:</b> In der Liste rechts (z. B. „Kreise“) suchen Sie nach Name oder Schlüssel, springen mit <kbd>Tab</kbd> zum
      Kästchen und ordnen mit der <kbd>Leertaste</kbd> zu.</li>
    <li><b>Zuordnung speichern</b> – die Vorschau unten zeigt sofort das Ergebnis, mit den Schaltern <b>Hell</b> und <b>Dunkel</b> auch im
      dunklen Farbschema. Die Vorschau nutzt die Farben Ihres Kits samt der Einstellungen unter <b>Design</b> – so wie die Website.</li>
  </ol>
  <p><b>Reihenfolge der Gebiete:</b> Ziehen Sie ein Gebiet in der Liste rechts am Griff an eine andere Stelle, nutzen Sie die Pfeil-Knöpfe
    oder – mit dem Fokus auf dem Namen – <kbd>Alt</kbd>+<kbd>↑</kbd> bzw. <kbd>Alt</kbd>+<kbd>↓</kbd>. Die Reihenfolge wird sofort gespeichert und gilt für die
    Liste und Legende auf der Website; Gebiete mit „Automatisch (Palette)“ bekommen ihre Farbe nach der Position.</p>
  <p><b>Automatische Farben:</b> Die Palette hat elf gut unterscheidbare Farben (auch bei Farbsehschwäche) – Grau wird nie automatisch vergeben,
    damit kein Gebiet wie „nicht zugeordnet“ aussieht (von Hand bleibt es wählbar). Gebiete mit gemeinsamer Grenze bekommen immer verschiedene
    Farben; erst wenn alle Farben vergeben sind, wiederholt sich eine – und dann nur bei Gebieten, die nicht aneinandergrenzen. Auf allen
    Palettenfarben erreichen die Namen in der Karte hell und dunkel mindestens 4,5:1 Kontrast. Statt der bunten Palette können Sie unter
    <a href="#gebietskarten-darstellung">Darstellung</a> auch <i>Töne der Akzentfarbe</i> wählen.</p>
  <p>Ein Klick auf den Namen eines Gebiets öffnet seine Angaben: <b>Name</b>, <b>Farbe</b> (Design-Farben des Kits, die Palette oder eine
    eigene Farbe), <b>Link</b> (über die Linkauswahl, z. B. die Seite der Regionalgruppe), <b>Infotext</b>, <b>Bild</b> aus der Mediathek
    und <b>Kontakt</b>. Design-Farben und Palette passen sich hell und dunkel automatisch an.</p>
  <p><b>Eigene Farben im Dunkeln:</b> Zu einer eigenen Farbe können Sie im Feld <b>Eigene Farbe im Dunkeln</b> einen zweiten Wert angeben. Bleibt es
    leer, leitet Studio den Wert automatisch ab – passend zum dunklen Hintergrund Ihres Kits und so dunkel, dass die Namen in der Karte mindestens
    4,5:1 Kontrast haben. Unter den Farbfeldern sehen Sie den Kontrast der Beschriftung hell und im Dunkeln; liegt er unter 4,5:1, steht ein Hinweis
    daneben. Ihre eigene helle Farbe ändert Studio nie. Ob die Website überhaupt dunkel erscheint, stellen Sie unter <b>Design</b> ein („Dunkelmodus anbieten“).</p>
  <p>Unter <b>Einstellungen der Karte</b> (<i>Inhalt der Karte</i>) legen Sie fest, ob Flächen ohne Gebiet grau erscheinen, ob die Grenzen innerhalb der Gebiete und
    die Namen in der Karte zu sehen sind (Gestaltung: siehe <a href="#gebietskarten-darstellung">Darstellung</a> und <a href="#gebietskarten-beschriftungen">Beschriftungen</a>),
    ob die Liste der Gebiete unter der Karte steht und ob der Ausschnitt nur bis zum Rand der Gebiete reicht.</p>

  <h3 id="gebietskarten-darstellung">Darstellung</h3>
  <p>Wie die Karte aussieht und reagiert, stellen Sie je Karte im Abschnitt <b>Darstellung</b> ein. Links steht die Vorschau wie auf der Website (mit den
    Schaltern <b>Hell</b> und <b>Dunkel</b>); jede Änderung sehen Sie sofort – den Hover-Effekt, indem Sie mit der Maus über die Vorschau fahren.
    Gespeichert wird mit <b>Darstellung speichern</b>. Das Kit liefert nur die Standardwerte (Farben, Schatten); alles Übrige entscheiden Sie hier.</p>
  <ul>
    <li><b>Hover- und Fokus-Effekt</b> – was passiert, wenn jemand mit der Maus über ein Gebiet fährt oder es mit der Tastatur (<kbd>Tab</kbd>) ansteuert:
      <i>keiner</i> (wie bisher), <i>dezent</i> (die übrigen Gebiete treten zurück) oder <i>animiert</i> (das Gebiet hebt sich mit einer weichen,
      leicht federnden Bewegung samt Schatten aus der Karte, sein Name geht mit). Auf Telefon und Tablet bleibt es beim Antippen wie gewohnt. Wer im
      Betriebssystem „Bewegung reduzieren“ eingestellt hat, sieht bei <i>animiert</i> keine Bewegung, nur einen ruhigen Schatten.</li>
    <li><b>Schatten unter der Karte</b> – ein weicher Schatten unter der ganzen Karte, im dunklen Farbschema etwas kräftiger.</li>
    <li><b>Automatische Farben</b> – für alle Gebiete mit der Farbe „Automatisch“ (auch Karten aus einer Datentabelle ohne Farbfeld): die
      <i>Palette</i> mit gut unterscheidbaren Farben oder <i>Töne der Akzentfarbe</i> – helle und kräftige Abstufungen der Akzentfarbe Ihres Kits,
      hell und dunkel passend. Nachbargebiete bekommen immer verschiedene Töne; liegt ein Ton so, dass weder helle noch dunkle Schrift 4,5:1 erreicht,
      passt Studio ihn leicht an.</li>
    <li><b>Beschriftungen</b> – Schriftgröße, Stil und Großbuchstaben für die ganze Karte (je Gebiet abweichend unter <a href="#gebietskarten-beschriftungen">Beschriftungen</a>)
      und <b>Schatten unter Pill-Beschriftungen</b>. Die Stile <i>Pill hell</i> (Fläche und Schrift des Kits, feiner Rand) und <i>Pill dunkel</i>
      (dunkler Akzent, helle Schrift) setzen den Namen auf ein abgerundetes Etikett; beim Hover-Effekt <i>animiert</i> hebt es sich mit dem Gebiet,
      sein Schatten wächst mit.</li>
  </ul>
  <p>Karten, die vor Version 0.4.0 angelegt wurden, bleiben unverändert: kein Hover-Effekt, kein Schatten, Palette wie bisher.</p>

  <h3 id="gebietskarten-beschriftungen">Beschriftungen</h3>
  <p>Die Namen in der Karte gestalten Sie im Abschnitt <b>Beschriftungen</b> unter der Karte. Er zeigt die Karte wie auf der Website (mit den
    Schaltern <b>Hell</b> und <b>Dunkel</b>); rechts stellen Sie das gewählte Gebiet ein. Ein Klick auf ein Gebiet oder eine Beschriftung wählt es aus.</p>
  <ul>
    <li><b>Beschriftung</b> – <i>wie Karte</i>, <i>zeigen</i> oder <i>ausblenden</i>. So können Sie die Namen der Karte ausschalten und nur einzelne Gebiete
      beschriften – oder umgekehrt. Ausgeblendete Beschriftungen erscheinen im Editor blass, damit Sie sie wiederfinden.</li>
    <li><b>Text</b> – leer bedeutet: der Name des Gebiets. Für lange Namen tragen Sie einen Kurznamen ein („Oberbayern-Süd“ statt „Regionalgruppe
      Oberbayern-Süd und Werdenfelser Land“) oder brechen mit <kbd>Enter</kbd> um (höchstens drei Zeilen). Liste, Info-Fenster und Screenreader nennen
      weiter den vollen Namen.</li>
    <li><b>Lage</b> – ziehen Sie die Beschriftung mit der Maus (oder dem Finger) an ihren Platz. Mit der Tastatur: <kbd>Tab</kbd> bis zur Beschriftung,
      dann die Pfeiltasten (<kbd>Umschalt</kbd>+Pfeil für große Schritte). <b>Lage zurücksetzen</b> stellt die automatische Lage wieder her.</li>
    <li><b>Hinweislinie</b> – für kleine Gebiete wie eine kreisfreie Stadt: Der Text steht außerhalb, eine dünne Linie führt zu einem Punkt im Gebiet.
      Text und Ankerpunkt verschieben Sie getrennt (der Ankerpunkt ist ein eigener Griff, auch mit der Tastatur).</li>
    <li><b>Schriftgröße</b> (Klein, Mittel, Groß oder <i>automatisch nach Fläche</i> – die größte, die ganz ins Gebiet passt), <b>Stil</b> und
      <b>Großbuchstaben</b> – je Gebiet oder <i>wie Karte</i>.</li>
  </ul>
  <p><b>Stile:</b> <i>Automatisch hell oder dunkel</i> wählt je Gebiet die Schriftfarbe mit dem besseren Kontrast zur Gebietsfarbe – hell und im
    dunklen Farbschema getrennt. <i>Schrift des Kits mit Kontrastrand</i> ist die bisherige Darstellung, <i>dunkel</i> und <i>hell</i> legen die
    Schriftfarbe fest, <i>Etikett</i> setzt den Namen auf ein dunkles Schild, <i>Pill hell</i> und <i>Pill dunkel</i> auf ein abgerundetes Etikett
    (siehe <a href="#gebietskarten-darstellung">Darstellung</a>). Rechts sehen Sie Größe, Schriftfarbe und den Kontrast hell und im Dunkeln;
    unter 4,5:1 erscheint eine Warnung, ebenso, wenn der Text nicht ganz in sein Gebiet passt.</p>
  <p>Die Voreinstellungen der ganzen Karte (Schriftgröße, Stil, Großbuchstaben) stehen unter <a href="#gebietskarten-darstellung">Darstellung</a>,
    die Namen an/aus unter <b>Einstellungen der Karte</b>.
    Änderungen sehen Sie sofort in der Karte; sie gelten erst nach <b>Beschriftungen speichern</b>.</p>
  <p><b>Automatische Lage:</b> Studio setzt jeden Namen in die Mitte der größten freien Fläche seines Gebiets – auch bei Gebieten mit Ausbuchtungen,
    bei denen der Schwerpunkt außerhalb läge. Karten, die vor Version 0.3.0 angelegt wurden, behalten ihre bisherige Lage, damit sich auf der Website nichts
    verschiebt. Mit <b>Automatisch neu setzen</b> stellen Sie eine Karte auf die neue Berechnung um (von Hand gesetzte Lagen gehen dabei verloren,
    eigene Texte und Stile bleiben).</p>
  <p><b>Auf dem Telefon</b> (Karte schmaler als rund 576 Pixel) wird die Schrift größer, damit sie lesbar bleibt. Passt ein Name dann nicht
    mehr ganz in sein Gebiet, nimmt er die nächstkleinere Größe; passt auch die kleinste nicht – und bei allen Namen mit Hinweislinie –, entfällt er dort.
    Die Namen stehen weiter an den Gebieten (Screenreader, Tastatur) und in der Liste unter der Karte. Das gilt für Karten mit der neuen automatischen Lage.</p>
  <p><b>Karten aus einer Datentabelle:</b> Lage und Gestaltung speichert die Karte je Eintrag. Für den Text können Sie unter <b>Einstellungen der Karte</b>
    ein Feld der Tabelle wählen (<b>Beschriftung in der Karte</b>, Text oder Textfeld, z. B. „Kurzname“): Dann steht der Text im Eintrag – sichtbar und
    änderbar auch in der Datentabelle – und <b>Beschriftungen speichern</b> schreibt ihn mit Version und Protokoll in den Eintrag, sofern Ihre Rolle den
    Eintrag ändern darf.</p>

  <h3 id="gebietskarten-block">Block „Gebietskarte“</h3>
  <table class="doc-table">
    <tr><th>Einstellung</th><th>Wirkung</th></tr>
    <tr><td>Karte</td><td>Welche Gebietskarte erscheint.</td></tr>
    <tr><td>Darstellung</td><td><b>Übersicht</b> aller Gebiete · <b>ein Gebiet hervorheben</b> (Auswahl darunter) · <b>Gebiet dieser Seite</b> (das Gebiet,
      dessen Link auf diese Seite oder eine übergeordnete zeigt – praktisch für die Seiten der einzelnen Regionalgruppen) · <b>Gebiet dieser
      Website</b> (Datentabelle mit Feld „Website“, siehe unten). Die übrigen Gebiete erscheinen gedämpft.</td></tr>
    <tr><td>Klick auf ein Gebiet</td><td><b>Link öffnen</b> · <b>Info-Fenster</b> mit Bild, Infotext, Kontakt und Link · <b>nichts</b> (nur ansehen).</td></tr>
    <tr><td>Liste der Gebiete</td><td>Unter der Karte zeigen oder nur für Screenreader. Die Liste ist immer da – auch ohne JavaScript und für
      Menschen, die die Karte nicht sehen.</td></tr>
  </table>
  <p>Jedes Gebiet ist mit der Tastatur erreichbar (<kbd>Tab</kbd>), <kbd>Enter</kbd> öffnet den Link bzw. das Info-Fenster, <kbd>Esc</kbd> schließt es.</p>

  <h3 id="gebietskarten-daten">Gebiete aus einer Datentabelle</h3>
  <p>Haben Sie Ihre Gruppen schon als Datentabelle (z. B. „Regionalgruppen“ mit Ansprechpartnern), wählen Sie bei der Karte <b>aus einer
    Datentabelle</b> und dann unter <b>Einstellungen der Karte</b> die Tabelle und das <b>Feld mit den Flächen</b>. Darin stehen die Kreise
    bzw. Gemeinden des Eintrags – als Namen („Wesel, Kleve“) oder amtliche Schlüssel („05170“), durch Komma oder Zeilenumbruch getrennt.
    Name, Farbe, Link, Infotext, Bild und Kontakt können ebenfalls aus Feldern kommen; ohne Link-Feld verlinkt die Detailseite des Eintrags.
    Angaben, die zu keiner Fläche passen, zeigt die Karte in der Verwaltung als Hinweis an.</p>
  <p><b>Per Klick zuordnen:</b> Auch bei Karten aus einer Datentabelle wählen Sie oben bei <b>Zuordnen zu</b> einen Eintrag und klicken auf die
    Flächen (oder nutzen die Liste rechts mit der Tastatur). <b>Zuordnung speichern</b> schreibt das Feld mit den Flächen in die betroffenen Einträge –
    genau so, als hätten Sie den Eintrag in der Datentabelle bearbeitet: mit Version (Notiz „Gebietskarte …: Flächen per Klick zugeordnet“), Eintrag im
    Protokoll und unverändertem Status. Es gelten die Rechte Ihrer Rolle: Sie brauchen „Daten bearbeiten“ für die Tabelle; veröffentlichte Einträge
    ändert nur, wer auch veröffentlichen darf. Einträge, die Sie nicht ändern dürfen (oder die zu einer anderen Website des Netzwerks gehören), sind mit
    einem Schloss markiert – ihre Flächen bleiben, wie sie sind. Das Feld muss eine Mehrfachauswahl, ein Text- oder ein Textfeld sein; Studio schreibt
    die Flächen so, wie die Tabelle es schon hält (Namen oder Schlüssel), und lässt Angaben außerhalb dieser Karte stehen.</p>
  <p><b>Netzwerk mit mehreren Websites:</b> Eine geteilte Datentabelle funktioniert genauso. Jede Website legt ihre eigene Karte auf die geteilte
    Tabelle an. Mit dem Feld <b>Website</b> (Schlüssel oder Adresse der Website) hebt die Darstellung „Gebiet dieser Website“ auf jeder Website
    ihr eigenes Gebiet hervor.</p>

  <h3 id="gebietskarten-grafik">Als Grafik weiterbearbeiten</h3>
  <p>Der Knopf <b>Als Grafik weiterbearbeiten</b> legt die Karte als SVG in der Mediathek ab (mit festen Farben, Namen und Quellenvermerk) und
    öffnet sie im Grafik-Editor, wenn er eingeschaltet ist – etwa für ein Plakat oder einen Social-Media-Beitrag. Ohne Grafik-Editor heißt der Knopf
    <b>Als SVG in die Mediathek</b>. Die Datei ist danach unabhängig von der Karte.</p>

  <h3 id="gebietskarten-quelle">Quellenvermerk</h3>
  <p>Die Kartendaten stehen unter der Datenlizenz Deutschland – Namensnennung 2.0. Studio setzt den Quellenvermerk nach den Bedingungen des BKG
    automatisch und überall gleich: unter jede Karte und in die Vorschau als „Kartengrundlage: © BKG (Jahr) dl-de/by-2-0, Datenquellen, vereinfacht“
    (mit Links), in jeden SVG-Export, die Mediathek und den Grafik-Editor mit den Adressen statt Links. Entfernen Sie ihn im Grafik-Editor nicht.
    Ältere Exporte behalten ihren damaligen Text – exportieren Sie die Karte bei Bedarf neu.</p>
