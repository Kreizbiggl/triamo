<?php
declare(strict_types=1);

/*
 * TRIAMO Datenschutzerklärung
 * ---------------------------
 * Diese Datei ist bewusst getrennt von der Hauptanwendung gespeichert.
 *
 * Die Hauptanwendung kann den Inhalt mit ?fragment=1 in einen Dialog laden.
 * Beim direkten Aufruf wird eine vollständige HTML-Seite ausgegeben.
 *
 * Vor der Veröffentlichung müssen insbesondere Name, Anschrift und
 * E-Mail-Adresse des Verantwortlichen ergänzt und die tatsächlich
 * eingesetzten externen Dienste geprüft werden.
 */

const PRIVACY_APP_NAME = 'TRIAMO';
const PRIVACY_APP_URL = 'https://...';
const PRIVACY_UPDATED_AT = 'x. xxx 2026';

const PRIVACY_CONTROLLER_NAME = '';
const PRIVACY_CONTROLLER_ADDRESS = "Straße\nPLZ Ort\nLand";
const PRIVACY_CONTROLLER_EMAIL = 'E-Mail-Adresse';

const PRIVACY_HOSTING_PROVIDER = 'Provider';
const PRIVACY_HOSTING_OWNER = 'Inhaber: ...';
const PRIVACY_HOSTING_ADDRESS = "Straße\nPLZ Ort\nLand";
const PRIVACY_HOSTING_COUNTRY = 'Land';

function privacy_escape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function privacy_lines(string $value): string
{
    $lines = preg_split('/\R/', $value) ?: [];
    $lines = array_filter(
        array_map('trim', $lines),
        static fn(string $line): bool => $line !== ''
    );

    return implode('<br>', array_map('privacy_escape', $lines));
}

function render_datenschutz_fragment(): string
{
    ob_start();
    ?>
    <article class="legal-content">
        <h1>Datenschutzerklärung</h1>

        <p class="muted">
            Stand: <?= privacy_escape(PRIVACY_UPDATED_AT) ?>
        </p>

        <h2>1. Verantwortlicher</h2>

        <p>
            Verantwortlich für die Verarbeitung personenbezogener Daten im
            Rahmen der Anwendung <?= privacy_escape(PRIVACY_APP_NAME) ?> ist:
        </p>

        <address>
            <strong><?= privacy_escape(PRIVACY_CONTROLLER_NAME) ?></strong><br>
            <?= privacy_lines(PRIVACY_CONTROLLER_ADDRESS) ?><br>
            E-Mail:
            <a href="mailto:<?= privacy_escape(PRIVACY_CONTROLLER_EMAIL) ?>">
                <?= privacy_escape(PRIVACY_CONTROLLER_EMAIL) ?>
            </a>
        </address>

        <h2>2. Gegenstand dieser Datenschutzerklärung</h2>

        <p>
            Diese Datenschutzerklärung informiert darüber, welche
            personenbezogenen Daten bei der Nutzung der unter
            <a href="<?= privacy_escape(PRIVACY_APP_URL) ?>">
                <?= privacy_escape(PRIVACY_APP_URL) ?>
            </a>
            erreichbaren Anwendung
            <?= privacy_escape(PRIVACY_APP_NAME) ?> verarbeitet werden.
        </p>

        <p>
            <?= privacy_escape(PRIVACY_APP_NAME) ?> dient der Verwaltung
            privater Bücherbestände. Die Anwendung ermöglicht insbesondere
            die Verwaltung von Benutzerkonten, Haushalten, Büchern,
            Standorten, Verleihungen, Vormerkungen, Freigaben,
            Erinnerungen und Sicherungskopien.
        </p>

        <p>
            Personenbezogene Daten sind alle Informationen, die sich auf
            eine bestimmte oder bestimmbare natürliche Person beziehen.
        </p>

        <h2>3. Bereitstellung der Anwendung und Server-Logdateien</h2>

        <p>
            Beim Aufruf der Anwendung werden durch den Webserver technisch
            erforderliche Daten verarbeitet. Dazu können insbesondere
            gehören:
        </p>

        <ul>
            <li>IP-Adresse des aufrufenden Geräts</li>
            <li>Datum und Uhrzeit des Abrufs</li>
            <li>aufgerufene Seite oder Datei</li>
            <li>übertragene Datenmenge</li>
            <li>Referrer-URL</li>
            <li>Browsertyp und Browserversion</li>
            <li>Betriebssystem</li>
            <li>Zugriffsstatus und HTTP-Statuscode</li>
            <li>technische Fehler- und Sicherheitsinformationen</li>
        </ul>

        <p>
            Die Verarbeitung erfolgt, um die Anwendung technisch
            bereitzustellen, die Stabilität und Sicherheit des Betriebs
            zu gewährleisten, Fehler zu erkennen und missbräuchliche
            Zugriffe abzuwehren.
        </p>

        <p>
            Rechtsgrundlage ist Art. 6 Abs. 1 Buchstabe f DSGVO.
            Das berechtigte Interesse besteht im sicheren, zuverlässigen
            und störungsfreien Betrieb der Anwendung.
        </p>

        <p>
            Server-Logdaten werden nur so lange gespeichert, wie dies für
            den technischen Betrieb, die Fehleranalyse und die Aufklärung
            von Sicherheitsvorfällen erforderlich ist. Anschließend werden
            sie gelöscht oder anonymisiert, sofern keine gesetzlichen
            Aufbewahrungspflichten oder konkreten Sicherheitsgründe eine
            längere Speicherung erfordern.
        </p>

        <h2>4. Registrierung und Benutzerkonto</h2>

        <p>
            Für die Registrierung und Verwaltung eines Benutzerkontos
            werden insbesondere folgende Daten verarbeitet:
        </p>

        <ul>
            <li>Name</li>
            <li>Haushaltsname</li>
            <li>E-Mail-Adresse</li>
            <li>Benutzerrolle und Berechtigungen</li>
            <li>Haushaltszuordnung</li>
            <li>verschlüsselter Passwort-Hash</li>
            <li>Zeitpunkt der Registrierung</li>
            <li>Zeitpunkt und technische Informationen zu Anmeldungen</li>
            <li>Änderungen an Konto- und Profildaten</li>
        </ul>

        <p>
            Passwörter werden nicht im Klartext gespeichert. Stattdessen
            wird ein technisch erzeugter Passwort-Hash gespeichert.
        </p>

        <p>
            Die Verarbeitung ist erforderlich, um das Benutzerkonto
            einzurichten, die Anmeldung zu ermöglichen, Benutzerrechte
            zu prüfen und die Funktionen der Anwendung bereitzustellen.
        </p>

        <p>
            Rechtsgrundlage ist Art. 6 Abs. 1 Buchstabe b DSGVO.
            Soweit Daten zur Abwehr von Missbrauch, zur Zugriffskontrolle
            oder zur IT-Sicherheit verarbeitet werden, beruht die
            Verarbeitung zusätzlich auf Art. 6 Abs. 1 Buchstabe f DSGVO.
        </p>

        <p>
            Die zur Registrierung abgefragten Pflichtangaben sind für die
            Einrichtung eines Kontos erforderlich. Ohne diese Angaben kann
            <?= privacy_escape(PRIVACY_APP_NAME) ?> nicht oder nur
            eingeschränkt genutzt werden.
        </p>

        <h2>5. Bibliotheks-, Buch- und Standortdaten</h2>

        <p>
            Bei der Nutzung von <?= privacy_escape(PRIVACY_APP_NAME) ?>
            können insbesondere folgende Daten gespeichert werden:
        </p>

        <ul>
            <li>ISBN und andere Buchkennungen</li>
            <li>Titel, Untertitel und Beschreibung</li>
            <li>Autoren und weitere Beteiligte</li>
            <li>Verlag und Erscheinungsjahr</li>
            <li>Cover und sonstige Buchdateien</li>
            <li>Schlagwörter und Kategorien</li>
            <li>Gebäude, Räume, Regale und Fächer</li>
            <li>Inventarnummern und Barcodes</li>
            <li>Status eines Buches</li>
            <li>Änderungs- und Bestandshistorie</li>
            <li>Angaben zu Büchereibüchern und Rückgabefristen</li>
        </ul>

        <p>
            Buchdaten sind für sich genommen in der Regel keine
            personenbezogenen Daten. Ein Personenbezug kann jedoch
            entstehen, wenn Buchdaten einem Benutzer, einem Haushalt,
            einem Entleiher oder einer anderen identifizierbaren Person
            zugeordnet werden.
        </p>

        <p>
            Die Verarbeitung erfolgt zur Bereitstellung der vom Benutzer
            gewünschten Verwaltungsfunktionen. Rechtsgrundlage ist
            Art. 6 Abs. 1 Buchstabe b DSGVO.
        </p>

        <h2>6. Verleihungen und Vormerkungen</h2>

        <p>
            Zur Verwaltung von Verleihungen und Vormerkungen können
            insbesondere folgende Daten verarbeitet werden:
        </p>

        <ul>
            <li>Name oder Bezeichnung des Entleihers</li>
            <li>ausgeliehenes oder vorgemerktes Buch</li>
            <li>Ausleihdatum</li>
            <li>Fälligkeitsdatum</li>
            <li>Rückgabedatum</li>
            <li>Status der Verleihung oder Vormerkung</li>
            <li>Reihenfolge von Vormerkungen</li>
            <li>Erinnerungs- und Benachrichtigungsstatus</li>
            <li>zugehöriger Haushalt und Benutzer</li>
            <li>Historie des Vorgangs</li>
        </ul>

        <p>
            Die Verarbeitung erfolgt zur Verwaltung und Dokumentation der
            Verleih- und Vormerkungsvorgänge.
        </p>

        <p>
            Rechtsgrundlage ist Art. 6 Abs. 1 Buchstabe b DSGVO, soweit
            die Verarbeitung zur Bereitstellung der Anwendung erforderlich
            ist. Soweit Vorgänge aus Sicherheitsgründen oder zur Klärung
            von Unstimmigkeiten dokumentiert werden, kann die Verarbeitung
            zusätzlich auf Art. 6 Abs. 1 Buchstabe f DSGVO beruhen.
        </p>

        <p>
            Benutzer dürfen personenbezogene Daten anderer Personen nur
            eingeben, wenn sie hierzu berechtigt sind. Die betroffene
            Person ist gegebenenfalls über die Verarbeitung ihrer Daten
            zu informieren.
        </p>

        <h2>7. Haushalte und Freigaben zwischen Benutzerkonten</h2>

        <p>
            <?= privacy_escape(PRIVACY_APP_NAME) ?> ermöglicht es, Daten
            einem Haushalt zuzuordnen und anderen Benutzerkonten Zugriff
            auf einen Haushalt zu gewähren.
        </p>

        <p>Dabei können insbesondere folgende Daten verarbeitet werden:</p>

        <ul>
            <li>Haushaltszuordnung</li>
            <li>freigebende und zugreifende Benutzerkonten</li>
            <li>Art und Umfang der Berechtigung</li>
            <li>einmalig verwendbare Zugriffsschlüssel</li>
            <li>Zeitpunkt der Erstellung und Einlösung eines Zugriffsschlüssels</li>
            <li>Status einer Freigabe</li>
            <li>Zeitpunkt des Entzugs oder der Pausierung einer Freigabe</li>
        </ul>

        <p>
            Benutzer mit einer entsprechenden Freigabe können die Daten
            des freigegebenen Haushalts im Rahmen ihrer Berechtigungen
            einsehen.
        </p>

        <p>
            Die Verarbeitung erfolgt zur Bereitstellung der vom Benutzer
            veranlassten Freigabefunktion. Rechtsgrundlage ist
            Art. 6 Abs. 1 Buchstabe b DSGVO.
        </p>

        <h2>8. Öffentliche Freigabelinks</h2>

        <p>
            <?= privacy_escape(PRIVACY_APP_NAME) ?> ermöglicht die
            Erstellung zeitlich begrenzter öffentlicher Freigabelinks.
            Personen, die über einen solchen Link verfügen, können die
            dafür freigegebenen Inhalte ohne eigenes Benutzerkonto
            aufrufen.
        </p>

        <p>
            Bei der Erstellung und Nutzung eines öffentlichen Links können
            insbesondere folgende Daten verarbeitet werden:
        </p>

        <ul>
            <li>zufällig erzeugter Zugriffsschlüssel</li>
            <li>freigegebener Haushalt oder Datenbestand</li>
            <li>Erstellungs- und Ablaufzeitpunkt</li>
            <li>Widerrufsstatus</li>
            <li>Zeitpunkt eines Abrufs</li>
            <li>gekürzte oder anderweitig beschränkte technische Protokolldaten</li>
            <li>vom Benutzer zur Veröffentlichung ausgewählte Buchdaten</li>
        </ul>

        <p>
            Wer einen öffentlichen Freigabelink erhält, kann ihn
            grundsätzlich an weitere Personen weitergeben. Benutzer
            sollten deshalb prüfen, welche Inhalte sie über einen solchen
            Link zugänglich machen. Personenbezogene Angaben,
            Verleihdaten oder andere vertrauliche Informationen sollten
            nicht öffentlich freigegeben werden.
        </p>

        <p>
            Die Verarbeitung erfolgt zur Bereitstellung der vom Benutzer
            veranlassten Freigabe. Rechtsgrundlage ist Art. 6 Abs. 1
            Buchstabe b DSGVO.
        </p>

        <p>
            Die technische Protokollierung zur Missbrauchserkennung und
            Absicherung der Freigabelinks beruht auf Art. 6 Abs. 1
            Buchstabe f DSGVO.
        </p>

        <p>
            Freigabedaten werden gelöscht oder gesperrt, wenn der Link
            abläuft, widerrufen oder gelöscht wird und keine Sicherheits-
            oder Nachweispflichten eine weitere Speicherung erfordern.
        </p>

        <h2>9. Cookies und Sitzungsverwaltung</h2>

        <p>
            <?= privacy_escape(PRIVACY_APP_NAME) ?> verwendet ein
            technisch notwendiges Session-Cookie. Dieses Cookie wird
            benötigt, um insbesondere folgende Funktionen zu ermöglichen:
        </p>

        <ul>
            <li>Anmeldung und Zuordnung einer Sitzung</li>
            <li>Prüfung von Benutzerrechten</li>
            <li>Schutz vor unberechtigten Zugriffen</li>
            <li>Schutz vor Cross-Site-Request-Forgery-Angriffen</li>
            <li>Aufrechterhaltung der Sitzung während der Nutzung</li>
        </ul>

        <p>
            Das Session-Cookie wird nicht für Werbung, Reichweitenmessung
            oder die Erstellung von Benutzerprofilen verwendet.
        </p>

        <p>
            Rechtsgrundlage für die Speicherung und den Zugriff auf das
            technisch notwendige Cookie ist § 25 Abs. 2 Nr. 2 TDDDG.
            Die anschließende Verarbeitung personenbezogener Daten erfolgt
            auf Grundlage von Art. 6 Abs. 1 Buchstabe b und Buchstabe f
            DSGVO.
        </p>

        <p>
            Das Session-Cookie verliert grundsätzlich mit dem Ende der
            Sitzung seine Gültigkeit. Benutzer können Cookies über die
            Einstellungen ihres Browsers löschen. Wird das technisch
            notwendige Cookie blockiert, können Anmeldung und Nutzung der
            Anwendung nicht ordnungsgemäß funktionieren.
        </p>

        <h2>10. Kamera und Barcode-Scan</h2>

        <p>
            <?= privacy_escape(PRIVACY_APP_NAME) ?> kann die Kamera eines
            Geräts verwenden, um ISBN- und Standort-Barcodes zu erkennen.
        </p>

        <p>
            Der Kamerazugriff erfolgt nur, nachdem der Benutzer die
            entsprechende Berechtigung im Browser erteilt hat. Das
            Kamerabild wird im Browser zur Erkennung des Barcodes
            verarbeitet. Kamerabilder werden durch
            <?= privacy_escape(PRIVACY_APP_NAME) ?> nicht dauerhaft
            gespeichert.
        </p>

        <p>
            Der erkannte Barcode beziehungsweise die erkannte Nummer kann
            anschließend an den Server übertragen und dort verarbeitet
            werden.
        </p>

        <p>
            Die Kameraberechtigung kann jederzeit über die Browser- oder
            Geräteeinstellungen entzogen werden. Die Anwendung kann
            alternativ ohne Kamera durch manuelle Eingabe oder mit einem
            als Tastatur arbeitenden Barcodescanner genutzt werden.
        </p>

        <h2>11. Externe Buch- und Metadatendienste</h2>

        <p>
            Zur automatischen Ergänzung oder Suche von Buchinformationen
            kann <?= privacy_escape(PRIVACY_APP_NAME) ?> externe Katalog-
            und Metadatendienste verwenden. Hierzu können je nach
            technischer Konfiguration insbesondere gehören:
        </p>

        <ul>
            <li>Google Books</li>
            <li>Open Library beziehungsweise Internet Archive</li>
            <li>Deutsche Nationalbibliothek</li>
            <li>weitere aktivierte Buch- und Katalogdienste</li>
        </ul>

        <p>
            An diese Dienste können insbesondere folgende Daten
            übermittelt werden:
        </p>

        <ul>
            <li>ISBN</li>
            <li>Buchtitel</li>
            <li>Autorenname</li>
            <li>Verlag</li>
            <li>sonstige eingegebene Suchbegriffe</li>
            <li>technische Verbindungsdaten des anfragenden Servers</li>
        </ul>

        <p>
            Die Abfrage dient dazu, Buchinformationen wie Titel, Autoren,
            Beschreibungen, Cover, Verlag und Erscheinungsjahr
            automatisiert zu ergänzen.
        </p>

        <p>
            Rechtsgrundlage ist Art. 6 Abs. 1 Buchstabe b DSGVO, soweit
            die Abfrage zur Ausführung einer vom Benutzer angeforderten
            Funktion erfolgt. Ergänzend kann Art. 6 Abs. 1 Buchstabe f
            DSGVO herangezogen werden. Das berechtigte Interesse besteht
            in der effizienten und fehlerarmen Erfassung von Buchdaten.
        </p>

        <p>
            Für die weitere Verarbeitung durch den jeweiligen externen
            Dienst ist dessen Betreiber verantwortlich. Es gelten die
            Datenschutzhinweise des jeweiligen Anbieters.
        </p>

        <p>
            Einzelne Anbieter können Daten außerhalb der Europäischen
            Union oder des Europäischen Wirtschaftsraums verarbeiten.
            Soweit personenbezogene Daten in ein Drittland übermittelt
            werden, erfolgt dies nur unter Beachtung der Voraussetzungen
            der Art. 44 ff. DSGVO, insbesondere auf Grundlage eines
            Angemessenheitsbeschlusses oder geeigneter Garantien.
        </p>

        <p>
            Benutzer sollten keine personenbezogenen oder vertraulichen
            Informationen als Suchbegriff an externe Metadatendienste
            übermitteln.
        </p>

        <h2>12. E-Mail-Erinnerungen und Systemnachrichten</h2>

        <p>
            Soweit die Erinnerungs- oder Benachrichtigungsfunktion
            aktiviert ist, kann <?= privacy_escape(PRIVACY_APP_NAME) ?>
            E-Mails zu Verleihungen, Rückgabefristen, Vormerkungen oder
            anderen Vorgängen versenden.
        </p>

        <p>Hierfür können insbesondere folgende Daten verarbeitet werden:</p>

        <ul>
            <li>Name</li>
            <li>E-Mail-Adresse</li>
            <li>Buchdaten</li>
            <li>Ausleih- oder Fälligkeitsdatum</li>
            <li>Art und Inhalt der Erinnerung</li>
            <li>Versandzeitpunkt</li>
            <li>Versandstatus und technische Fehlermeldungen</li>
        </ul>

        <p>
            Die Verarbeitung erfolgt zur Bereitstellung der vom Benutzer
            genutzten Erinnerungs- und Benachrichtigungsfunktionen.
            Rechtsgrundlage ist Art. 6 Abs. 1 Buchstabe b DSGVO.
        </p>

        <p>
            Für den E-Mail-Versand können der Hostinganbieter oder ein
            gesonderter E-Mail-Dienstleister eingesetzt werden. Dabei
            werden die zum Versand erforderlichen Daten an den jeweiligen
            Dienstleister übermittelt.
        </p>

        <h2>13. Hosting</h2>

        <p>
            <?= privacy_escape(PRIVACY_APP_NAME) ?> wird bei folgendem
            Hostinganbieter betrieben:
        </p>

        <address>
            <strong><?= privacy_escape(PRIVACY_HOSTING_PROVIDER) ?></strong><br>
            <?= privacy_escape(PRIVACY_HOSTING_OWNER) ?><br>
            <?= privacy_lines(PRIVACY_HOSTING_ADDRESS) ?>
        </address>

        <p>
            Der Hostinganbieter stellt insbesondere Speicherplatz,
            Webserver, Datenbanken, E-Mail-Funktionen, technische
            Infrastruktur, Datensicherungen und Sicherheitsmaßnahmen zur
            Verfügung.
        </p>

        <p>
            Im Rahmen des Hostings kann der Anbieter auf personenbezogene
            Daten zugreifen, soweit dies zur Erbringung seiner Leistungen,
            zur Wartung, zur Fehlerbehebung oder zur Gewährleistung der
            Sicherheit erforderlich ist.
        </p>

        <p>
            Die Anwendung wird in
            <strong><?= privacy_escape(PRIVACY_HOSTING_COUNTRY) ?></strong>
            betrieben.
        </p>

        <p>
            Die Verarbeitung durch den Hostinganbieter erfolgt auf
            Grundlage von Art. 6 Abs. 1 Buchstabe f DSGVO. Das berechtigte
            Interesse besteht in der sicheren, zuverlässigen und
            wirtschaftlichen Bereitstellung der Anwendung.
        </p>

        <p>
            Soweit der Hostinganbieter personenbezogene Daten
            weisungsgebunden verarbeitet, erfolgt dies auf Grundlage
            eines Vertrags zur Auftragsverarbeitung gemäß Art. 28 DSGVO.
        </p>

        <h2>14. Sicherungskopien sowie Import und Export</h2>

        <p>
            <?= privacy_escape(PRIVACY_APP_NAME) ?> ermöglicht Benutzern
            je nach Berechtigung die Erstellung und Wiederherstellung von
            Sicherungskopien.
        </p>

        <p>
            Sicherungskopien können unter anderem Benutzer-, Haushalts-,
            Buch-, Standort-, Verlaufs-, Verleih- und Vormerkungsdaten
            enthalten.
        </p>

        <p>
            Wer eine Sicherungskopie herunterlädt, exportiert oder
            außerhalb von <?= privacy_escape(PRIVACY_APP_NAME) ?>
            speichert, ist für deren sichere Aufbewahrung verantwortlich.
            Sicherungskopien sollten vor unberechtigtem Zugriff geschützt,
            verschlüsselt gespeichert und gelöscht werden, sobald sie
            nicht mehr benötigt werden.
        </p>

        <p>
            Beim Wiederherstellen einer Sicherungskopie können die darin
            enthaltenen personenbezogenen Daten erneut in die Anwendung
            importiert und verarbeitet werden.
        </p>

        <p>
            Zusätzlich können durch den Hostinganbieter technische
            Sicherungskopien erstellt werden. Gelöschte Daten können bis
            zur turnusmäßigen Überschreibung vorübergehend in solchen
            Sicherungskopien verbleiben.
        </p>

        <p>
            Die Sicherungskopien werden ausschließlich zur
            Wiederherstellung nach technischen Störungen, Datenverlusten
            oder Sicherheitsvorfällen verwendet.
        </p>

        <h2>15. Empfänger personenbezogener Daten</h2>

        <p>
            Personenbezogene Daten können abhängig von der jeweiligen
            Funktion insbesondere folgenden Empfängern zugänglich gemacht
            werden:
        </p>

        <ul>
            <li>dem Betreiber und technisch berechtigten Administratoren</li>
            <li>Benutzern desselben Haushalts</li>
            <li>Benutzern, denen eine Haushaltsfreigabe erteilt wurde</li>
            <li>Personen, denen ein öffentlicher Freigabelink übermittelt wurde</li>
            <li>dem Hostinganbieter</li>
            <li>eingesetzten E-Mail-Dienstleistern</li>
            <li>externen Buch- und Metadatendiensten</li>
            <li>Behörden oder Gerichten, soweit eine gesetzliche Verpflichtung besteht</li>
        </ul>

        <p>
            Eine darüber hinausgehende Weitergabe erfolgt nur, wenn eine
            gesetzliche Grundlage besteht, sie zur Bereitstellung der
            Anwendung erforderlich ist oder die betroffene Person
            eingewilligt hat.
        </p>

        <h2>16. Speicherdauer und Löschung</h2>

        <p>
            Personenbezogene Daten werden nur so lange gespeichert, wie
            sie für den jeweiligen Verarbeitungszweck erforderlich sind.
        </p>

        <p>Es gelten insbesondere folgende Kriterien:</p>

        <ul>
            <li>
                Kontodaten werden grundsätzlich bis zur Löschung des
                Kontos oder Beendigung des Nutzungsverhältnisses
                gespeichert.
            </li>
            <li>
                Bibliotheks- und Haushaltsdaten werden gespeichert, solange
                der Benutzer oder Haushalt besteht und die Daten nicht
                gelöscht werden.
            </li>
            <li>
                Verleih-, Vormerkungs- und Verlaufsdaten werden gespeichert,
                solange sie für die Verwaltung oder Nachvollziehbarkeit
                des Vorgangs benötigt werden.
            </li>
            <li>
                Freigaben werden bis zu ihrem Ablauf, Widerruf oder ihrer
                Löschung gespeichert.
            </li>
            <li>
                Protokolldaten werden gelöscht, sobald sie für Betrieb,
                Sicherheit und Fehleranalyse nicht mehr erforderlich sind.
            </li>
            <li>
                Versanddaten werden gelöscht, sobald sie für die
                Durchführung und Dokumentation des E-Mail-Versands nicht
                mehr benötigt werden.
            </li>
            <li>
                Daten in Sicherungskopien werden bei der turnusmäßigen
                Überschreibung der Sicherungskopien gelöscht.
            </li>
        </ul>

        <p>
            Eine längere Speicherung kann erfolgen, wenn gesetzliche
            Aufbewahrungspflichten bestehen, Daten zur Geltendmachung,
            Ausübung oder Verteidigung von Rechtsansprüchen benötigt
            werden oder ein konkreter Sicherheitsvorfall untersucht wird.
        </p>

        <h2>17. Datensicherheit</h2>

        <p>
            <?= privacy_escape(PRIVACY_APP_NAME) ?> wird über eine
            verschlüsselte HTTPS-Verbindung bereitgestellt. Dadurch werden
            übertragene Daten vor dem unbefugten Mitlesen während der
            Übertragung geschützt.
        </p>

        <p>
            Darüber hinaus werden angemessene technische und
            organisatorische Maßnahmen eingesetzt, um personenbezogene
            Daten vor Verlust, Veränderung, Zerstörung und unberechtigtem
            Zugriff zu schützen.
        </p>

        <p>
            Trotz dieser Maßnahmen kann eine vollständig risikofreie
            Datenübertragung und Speicherung nicht garantiert werden.
            Benutzer sind dafür verantwortlich, sichere Passwörter zu
            verwenden, Zugangsdaten geheim zu halten und sich nach der
            Nutzung insbesondere auf gemeinsam verwendeten Geräten
            abzumelden.
        </p>

        <h2>18. Keine automatisierte Entscheidungsfindung</h2>

        <p>
            Es findet keine ausschließlich automatisierte
            Entscheidungsfindung einschließlich Profiling im Sinne von
            Art. 22 DSGVO statt.
        </p>

        <p>
            Statistische Auswertungen innerhalb von
            <?= privacy_escape(PRIVACY_APP_NAME) ?> dienen ausschließlich
            der Darstellung und Verwaltung des vorhandenen Buchbestands.
        </p>

        <h2>19. Rechte betroffener Personen</h2>

        <p>
            Betroffene Personen haben nach Maßgabe der gesetzlichen
            Voraussetzungen insbesondere folgende Rechte:
        </p>

        <ul>
            <li>Recht auf Auskunft nach Art. 15 DSGVO</li>
            <li>Recht auf Berichtigung nach Art. 16 DSGVO</li>
            <li>Recht auf Löschung nach Art. 17 DSGVO</li>
            <li>Recht auf Einschränkung der Verarbeitung nach Art. 18 DSGVO</li>
            <li>Recht auf Datenübertragbarkeit nach Art. 20 DSGVO</li>
            <li>Recht auf Widerspruch nach Art. 21 DSGVO</li>
            <li>
                Recht auf Widerruf einer erteilten Einwilligung mit
                Wirkung für die Zukunft
            </li>
        </ul>

        <p>
            Zur Ausübung dieser Rechte genügt eine Nachricht an die unter
            Abschnitt 1 genannte Kontaktadresse.
        </p>

        <h3>Widerspruchsrecht</h3>

        <p>
            Soweit personenbezogene Daten auf Grundlage von Art. 6 Abs. 1
            Buchstabe f DSGVO verarbeitet werden, kann aus Gründen, die
            sich aus der besonderen Situation der betroffenen Person
            ergeben, jederzeit Widerspruch gegen die Verarbeitung
            eingelegt werden.
        </p>

        <p>
            Nach einem Widerspruch werden die betroffenen Daten nicht
            weiter auf dieser Grundlage verarbeitet, es sei denn, es
            bestehen zwingende schutzwürdige Gründe für die Verarbeitung
            oder die Verarbeitung dient der Geltendmachung, Ausübung oder
            Verteidigung von Rechtsansprüchen.
        </p>

        <h2>20. Beschwerderecht</h2>

        <p>
            Betroffene Personen haben nach Art. 77 DSGVO das Recht, sich
            bei einer Datenschutzaufsichtsbehörde zu beschweren.
        </p>

        <p>
            Die Beschwerde kann insbesondere bei der
            Datenschutzaufsichtsbehörde des gewöhnlichen Aufenthaltsorts,
            des Arbeitsplatzes, des Orts des mutmaßlichen Verstoßes oder
            des Sitzes des Verantwortlichen eingereicht werden.
        </p>

        <h2>21. Änderungen dieser Datenschutzerklärung</h2>

        <p>
            Diese Datenschutzerklärung kann angepasst werden, wenn
            <?= privacy_escape(PRIVACY_APP_NAME) ?> technisch
            weiterentwickelt wird, neue Dienste eingesetzt werden oder
            sich gesetzliche Anforderungen ändern.
        </p>

        <p>
            Es gilt die jeweils unter
            <a href="<?= privacy_escape(PRIVACY_APP_URL) ?>">
                <?= privacy_escape(PRIVACY_APP_URL) ?>
            </a>
            veröffentlichte aktuelle Fassung.
        </p>

        <h2>22. Hinweis zur rechtlichen Prüfung</h2>

        <p>
            Diese Datenschutzerklärung wurde auf Grundlage der
            beschriebenen Funktionen erstellt. Sie ersetzt keine
            rechtliche Prüfung der tatsächlichen technischen
            Konfiguration, der eingesetzten Dienste und der konkreten
            Verarbeitungsvorgänge.
        </p>
    </article>
    <?php

    return (string) ob_get_clean();
}

$fragment = isset($_GET['fragment'])
    && (string) $_GET['fragment'] === '1';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

if ($fragment) {
    echo render_datenschutz_fragment();
    exit;
}
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        Datenschutz – <?= privacy_escape(PRIVACY_APP_NAME) ?>
    </title>

    <meta
        name="robots"
        content="index,follow"
    >

    <style>
        :root {
            color-scheme: light;
            --background: #f4f7f7;
            --surface: #ffffff;
            --text: #162321;
            --muted: #60716e;
            --border: #d8e3e1;
            --link: #0f766e;
            --link-hover: #115e59;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family:
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
            font-size: 16px;
            line-height: 1.65;
        }

        main {
            max-width: 960px;
            margin: 0 auto;
            padding: 32px 20px 64px;
        }

        .legal-content {
            padding: clamp(22px, 4vw, 42px);
            overflow-wrap: break-word;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 4px 16px rgba(17, 43, 39, 0.05);
        }

        h1,
        h2,
        h3 {
            line-height: 1.25;
            text-wrap: balance;
        }

        h1 {
            margin-top: 0;
            margin-bottom: 0.5rem;
            font-size: clamp(2rem, 5vw, 2.75rem);
        }

        h2 {
            margin-top: 2.5rem;
            margin-bottom: 0.75rem;
            font-size: clamp(1.3rem, 3vw, 1.6rem);
        }

        h3 {
            margin-top: 1.75rem;
            font-size: 1.1rem;
        }

        p,
        ul,
        address {
            margin-top: 0.75rem;
            margin-bottom: 1rem;
        }

        ul {
            padding-left: 1.4rem;
        }

        li + li {
            margin-top: 0.3rem;
        }

        address {
            font-style: normal;
        }

        a {
            color: var(--link);
            text-decoration-thickness: 0.08em;
            text-underline-offset: 0.15em;
        }

        a:hover,
        a:focus-visible {
            color: var(--link-hover);
        }

        .muted {
            color: var(--muted);
        }

        .back {
            display: inline-block;
            margin-bottom: 16px;
            font-weight: 600;
        }

        @media (max-width: 600px) {
            main {
                padding: 20px 12px 40px;
            }

            .legal-content {
                border-radius: 14px;
            }
        }

        @media print {
            body {
                background: #ffffff;
            }

            main {
                max-width: none;
                padding: 0;
            }

            .legal-content {
                padding: 0;
                border: 0;
                box-shadow: none;
            }

            .back {
                display: none;
            }

            a {
                color: inherit;
                text-decoration: none;
            }
        }
    </style>
</head>
<body>
<main>
    <a class="back" href="./">Zurück zur Anwendung</a>

    <?= render_datenschutz_fragment() ?>
</main>
</body>
</html>
