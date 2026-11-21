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
      dunklen Farbschema.</li>
  </ol>
  <p>Ein Klick auf den Namen eines Gebiets öffnet seine Angaben: <b>Name</b>, <b>Farbe</b> (Design-Farben des Kits, die Palette oder eine
    eigene Farbe), <b>Link</b> (über die Linkauswahl, z. B. die Seite der Regionalgruppe), <b>Infotext</b>, <b>Bild</b> aus der Mediathek
    und <b>Kontakt</b>. Design-Farben und Palette passen sich hell und dunkel automatisch an; eine eigene Farbe gilt in beiden Fällen gleich.</p>
  <p>Unter <b>Einstellungen der Karte</b> legen Sie fest, ob Flächen ohne Gebiet grau erscheinen, ob die Grenzen innerhalb der Gebiete und
    die Namen in der Karte zu sehen sind, ob die Liste der Gebiete unter der Karte steht und ob der Ausschnitt nur bis zum Rand der Gebiete reicht.</p>

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
  <p><b>Netzwerk mit mehreren Websites:</b> Eine geteilte Datentabelle funktioniert genauso. Jede Website legt ihre eigene Karte auf die geteilte
    Tabelle an. Mit dem Feld <b>Website</b> (Schlüssel oder Adresse der Website) hebt die Darstellung „Gebiet dieser Website“ auf jeder Website
    ihr eigenes Gebiet hervor.</p>

  <h3 id="gebietskarten-grafik">Als Grafik weiterbearbeiten</h3>
  <p>Der Knopf <b>Als Grafik weiterbearbeiten</b> legt die Karte als SVG in der Mediathek ab (mit festen Farben, Namen und Quellenvermerk) und
    öffnet sie im Grafik-Editor, wenn er eingeschaltet ist – etwa für ein Plakat oder einen Social-Media-Beitrag. Ohne Grafik-Editor heißt der Knopf
    <b>Als SVG in die Mediathek</b>. Die Datei ist danach unabhängig von der Karte.</p>
