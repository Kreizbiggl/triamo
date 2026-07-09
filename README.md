# Triamo
A software/platform for managing book metadata.

<p align="center">
  <img src="Triamo_Logo_Querformat.svg" alt="TRIAMO Logo" width="600">
</p>

# TRIAMO – Responsive Ein-Datei-Bücherverwaltung

TRIAMO ist eine schlanke, moderne und datenbankbasierte Webanwendung zur Verwaltung deiner privaten Buchbestände oder kleinen Bibliotheken. Das Besondere: Die gesamte Programmlogik, das responsive Frontend sowie die API-Routen befinden sich in einer einzigen, performanten PHP-Datei. Es wird kein schwerfälliges Framework, kein Docker und kein komplexer Build-Prozess benötigt.

## Features

* **All-in-One-Architektur:** Voller Funktionsumfang (SPA - Single Page Application) in einer einzigen `index.php`.
* **Google Books API-Anbindung:** Schnelle Online-Suche und automatische Erfassung von Buchdaten (Titel, Autor, Cover, Beschreibung).
* **Benutzerverwaltung:** Integrierte, erweiterte Benutzerverwaltung mit Registrierung, Login und feingranularen Rechten.
* **Responsive Design:** Optimiert für Smartphones, Tablets und Desktop-Bildschirme.
* **Sicherheit:** Absicherung gegen unbefugte Datei-Downloads, Eingabe-Validierung und Schutz vor unbefugten automatischen Aufrufen.
* **Automatisierung:** Integrierte Routen für Cronjobs (z. B. für automatische Benachrichtigungen oder Verleih-Erinnerungen).

---

## Voraussetzungen

* **Webserver:** Apache (oder nginx) mit PHP-Unterstützung (PHP 8.0 oder höher empfohlen).
* **Datenbank:** Eine leere MySQL- oder MariaDB-Datenbank.
* **API-Key:** Ein kostenloser Google Books API-Schlüssel (für die Online-Buchsuche).

---

## Installation & Einrichtung

Die Einrichtung von TRIAMO ist in wenigen Minuten erledigt:

1. **Dateien hochladen:** Lade die Dateien `index.php`, `impressum.php` und `datenschutz.php` in das Hauptverzeichnis deines Webspaces (per FTP/SFTP) hoch.
2. **Datenbank erstellen:** Lege über die Verwaltung deines Hosting-Anbieters (z. B. phpMyAdmin) eine neue, leere MySQL-Datenbank an.
3. **Konfiguration anpassen:** Öffne die `index.php` auf deinem Server oder vor dem Upload in einem Texteditor und trage deine Daten im oberen Konfigurationsblock ein:

```php
// =================================== KONFIGURATION ===================================
const DB_HOST = 'localhost';          // Dein Datenbank-Host (meist localhost)
const DB_NAME = 'deine_datenbank';    // Name deiner MySQL-Datenbank
const DB_USER = 'dein_benutzer';      // Benutzername der Datenbank
const DB_PASS = 'dein_passwort';      // Passwort der Datenbank
const DB_TABLE_PREFIX = 'triamo_';    // Tabellen-Präfix (kann so bleiben)

const GOOGLE_BOOKS_API_KEY = 'DEIN_GOOGLE_API_KEY'; // Dein Google Books API Key
const CRON_TOKEN = 'DEIN_ZUFÄLLIGER_GEHEIMER_TOKEN'; // Eine lange, zufällige Zeichenkette zur Absicherung von Hintergrundprozessen
// =====================================================================================



Erster Aufruf: Rufe deine Domain im Browser auf (z. B. https://deine-domain.de). TRIAMO erkennt die leere Datenbank, legt alle benötigten Tabellen automatisch an und leitet dich direkt zur Erstellung des Administrator-Kontos weiter.

Rechtliche Hinweise & Vorlagen
Im Repository befinden sich die Dateien impressum.php und datenschutz.php. Diese enthalten neutrale Platzhalter (z. B. [DEIN NAME]).

Bitte öffne diese Dateien vor der Nutzung und trage dort deine tatsächlichen, gesetzlich geforderten Daten ein.

Lizenz
Dieses Projekt ist unter der MIT-Lizenz lizenziert. Das bedeutet, du darfst den Code frei verwenden, modifizieren und teilen, solange der ursprüngliche Copyright-Hinweis erhalten bleibt. Details findest du in der LICENSE-Datei.

Kontakt & Projekt-Zuhause
Entwickelt unter dem Pseudonym Kreizbiggl.
