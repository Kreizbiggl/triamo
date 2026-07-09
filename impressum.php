<?php
declare(strict_types=1);

/*
 * TRIAMO Impressum
 * ----------------
 * Diese Datei ist bewusst getrennt von der Hauptanwendung gespeichert.
 *
 * Die Hauptanwendung kann den Inhalt mit ?fragment=1 in einen Dialog laden.
 * Beim direkten Aufruf wird eine vollständige HTML-Seite ausgegeben.
 *
 * Vor der Veröffentlichung müssen mindestens Name, vollständige Anschrift
 * und E-Mail-Adresse des Anbieters eingetragen werden.
 */

const LEGAL_APP_NAME = 'TRIAMO';
const LEGAL_SITE_URL = 'https://...';
const LEGAL_UPDATED_AT = 'x. xxx 2026';

/*
 * Anbieter
 */
const LEGAL_OWNER_NAME = 'Name';
const LEGAL_OWNER_ADDRESS = "Straße\nPLZ Ort\nLand";
const LEGAL_OWNER_EMAIL = 'E-Mail-Adresse';
const LEGAL_OWNER_PHONE = '';

/*
 * Projektbeschreibung
 */
const LEGAL_PROJECT_DESCRIPTION =
    'TRIAMO ist ein kostenfreies, nicht kommerzielles Projekt zur Verwaltung privater Bücherbestände.';

/*
 * Verantwortlicher für journalistisch-redaktionelle Inhalte
 *
 * Nur aktivieren, wenn die Webseite tatsächlich journalistisch-redaktionell
 * gestaltete Inhalte anbietet, zum Beispiel einen redaktionellen Blog oder
 * regelmäßig veröffentlichte redaktionelle Beiträge.
 */
const LEGAL_SHOW_EDITORIAL_RESPONSIBLE = false;
const LEGAL_RESPONSIBLE_NAME = LEGAL_OWNER_NAME;
const LEGAL_RESPONSIBLE_ADDRESS = LEGAL_OWNER_ADDRESS;

/*
 * Register- und Steuerangaben
 *
 * Bei einem privaten, nicht kommerziellen Projekt normalerweise leer lassen.
 * Nur tatsächlich vorhandene Angaben eintragen.
 */
const LEGAL_REGISTER_NAME = '';
const LEGAL_REGISTER_COURT = '';
const LEGAL_REGISTER_NUMBER = '';
const LEGAL_VAT_ID = '';

/*
 * Verbraucherstreitbeilegung
 *
 * Nur aktivieren, wenn der Anbieter als Unternehmer handelt und der Hinweis
 * auf die Teilnahme an Verbraucherschlichtungsverfahren benötigt wird.
 */
const LEGAL_SHOW_CONSUMER_DISPUTE_NOTICE = false;
const LEGAL_PARTICIPATES_IN_DISPUTE_RESOLUTION = false;

function legal_escape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function legal_lines(string $value): string
{
    $lines = preg_split('/\R/', $value) ?: [];

    $lines = array_filter(
        array_map('trim', $lines),
        static fn(string $line): bool => $line !== ''
    );

    return implode('<br>', array_map('legal_escape', $lines));
}

function legal_has_register_information(): bool
{
    return trim(LEGAL_REGISTER_NAME) !== ''
        || trim(LEGAL_REGISTER_COURT) !== ''
        || trim(LEGAL_REGISTER_NUMBER) !== '';
}

function legal_has_business_information(): bool
{
    return legal_has_register_information()
        || trim(LEGAL_VAT_ID) !== '';
}

function legal_contains_placeholders(): bool
{
    return str_contains(LEGAL_OWNER_NAME, 'Bitte ')
        || str_contains(LEGAL_OWNER_ADDRESS, 'Bitte ')
        || LEGAL_OWNER_EMAIL === 'bitte-email@example.com';
}

function render_impressum_fragment(): string
{
    ob_start();
    ?>
    <article class="legal-content">
        <h1>Impressum</h1>

        <p class="muted">
            Anbieterkennzeichnung für
            <?= legal_escape(LEGAL_APP_NAME) ?>
        </p>

        <?php if (legal_contains_placeholders()): ?>
            <div class="setup-warning" role="alert">
                <strong>Hinweis für den Betreiber:</strong>
                Vor der Veröffentlichung müssen Name, Anschrift und
                E-Mail-Adresse vollständig eingetragen werden.
            </div>
        <?php endif; ?>

        <h2>Angaben zum Anbieter</h2>

        <address>
            <strong><?= legal_escape(LEGAL_OWNER_NAME) ?></strong><br>
            <?= legal_lines(LEGAL_OWNER_ADDRESS) ?>
        </address>

        <h2>Kontakt</h2>

        <p>
            E-Mail:
            <a href="mailto:<?= legal_escape(LEGAL_OWNER_EMAIL) ?>">
                <?= legal_escape(LEGAL_OWNER_EMAIL) ?>
            </a>

            <?php if (trim(LEGAL_OWNER_PHONE) !== ''): ?>
                <br>
                Telefon: <?= legal_escape(LEGAL_OWNER_PHONE) ?>
            <?php endif; ?>
        </p>

        <h2>Projekt</h2>

        <p>
            <?= legal_escape(LEGAL_PROJECT_DESCRIPTION) ?>
        </p>

        <p>
            Die Anwendung ist unter
            <a href="<?= legal_escape(LEGAL_SITE_URL) ?>">
                <?= legal_escape(LEGAL_SITE_URL) ?>
            </a>
            erreichbar.
        </p>

        <?php if (LEGAL_SHOW_EDITORIAL_RESPONSIBLE): ?>
            <h2>
                Verantwortlich für journalistisch-redaktionelle Inhalte
            </h2>

            <p>
                Verantwortlich nach § 18 Abs. 2 Medienstaatsvertrag:
            </p>

            <address>
                <strong>
                    <?= legal_escape(LEGAL_RESPONSIBLE_NAME) ?>
                </strong><br>
                <?= legal_lines(LEGAL_RESPONSIBLE_ADDRESS) ?>
            </address>
        <?php endif; ?>

        <?php if (legal_has_business_information()): ?>
            <h2>Register- und Steuerangaben</h2>

            <?php if (legal_has_register_information()): ?>
                <p>
                    <?php if (trim(LEGAL_REGISTER_NAME) !== ''): ?>
                        Register:
                        <?= legal_escape(LEGAL_REGISTER_NAME) ?>
                    <?php endif; ?>

                    <?php if (trim(LEGAL_REGISTER_COURT) !== ''): ?>
                        <br>
                        Registergericht:
                        <?= legal_escape(LEGAL_REGISTER_COURT) ?>
                    <?php endif; ?>

                    <?php if (trim(LEGAL_REGISTER_NUMBER) !== ''): ?>
                        <br>
                        Registernummer:
                        <?= legal_escape(LEGAL_REGISTER_NUMBER) ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if (trim(LEGAL_VAT_ID) !== ''): ?>
                <p>
                    Umsatzsteuer-Identifikationsnummer gemäß § 27a
                    Umsatzsteuergesetz:<br>
                    <?= legal_escape(LEGAL_VAT_ID) ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (LEGAL_SHOW_CONSUMER_DISPUTE_NOTICE): ?>
            <h2>Verbraucherstreitbeilegung</h2>

            <?php if (LEGAL_PARTICIPATES_IN_DISPUTE_RESOLUTION): ?>
                <p>
                    Der Anbieter ist bereit oder verpflichtet, an einem
                    Streitbeilegungsverfahren vor einer
                    Verbraucherschlichtungsstelle teilzunehmen.
                </p>

                <p>
                    Die zuständige Verbraucherschlichtungsstelle muss an
                    dieser Stelle mit Name, Anschrift und Internetadresse
                    angegeben werden.
                </p>
            <?php else: ?>
                <p>
                    Der Anbieter ist nicht bereit und nicht verpflichtet,
                    an Streitbeilegungsverfahren vor einer
                    Verbraucherschlichtungsstelle teilzunehmen.
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <h2>Haftung für eigene Inhalte</h2>

        <p>
            Die Inhalte dieser Webseite und der Anwendung wurden mit
            angemessener Sorgfalt erstellt. Eine Gewähr für die
            Richtigkeit, Vollständigkeit und Aktualität der bereitgestellten
            Inhalte wird nur im gesetzlich vorgesehenen Umfang übernommen.
        </p>

        <p>
            Gesetzliche Haftungsansprüche bleiben unberührt. Insbesondere
            gelten Haftungsbeschränkungen nicht bei vorsätzlichem oder grob
            fahrlässigem Verhalten sowie bei Schäden aus der Verletzung des
            Lebens, des Körpers oder der Gesundheit.
        </p>

        <h2>Externe Links</h2>

        <p>
            Diese Webseite kann Verweise auf externe Webseiten enthalten,
            auf deren Inhalte der Anbieter keinen unmittelbaren Einfluss
            hat. Für die Inhalte der verlinkten Webseiten ist der jeweilige
            Anbieter verantwortlich.
        </p>

        <p>
            Externe Links werden zum Zeitpunkt ihrer Aufnahme auf erkennbare
            Rechtsverstöße geprüft. Eine fortlaufende Kontrolle externer
            Inhalte ist ohne konkrete Anhaltspunkte nicht zumutbar. Wird ein
            Rechtsverstoß bekannt, wird der betreffende Link geprüft und
            erforderlichenfalls entfernt.
        </p>

        <h2>Urheberrecht</h2>

        <p>
            Die vom Anbieter erstellten Inhalte und Werke auf dieser
            Webseite unterliegen dem deutschen Urheberrecht, soweit sie
            urheberrechtlich geschützt sind.
        </p>

        <p>
            Die Vervielfältigung, Bearbeitung, Verbreitung oder sonstige
            Verwertung außerhalb der gesetzlichen Grenzen bedarf der
            vorherigen Zustimmung des jeweiligen Rechteinhabers.
        </p>

        <p class="legal-updated">
            Stand: <?= legal_escape(LEGAL_UPDATED_AT) ?>
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
    echo render_impressum_fragment();
    exit;
}
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="robots"
        content="index,follow"
    >

    <title>
        Impressum – <?= legal_escape(LEGAL_APP_NAME) ?>
    </title>

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
            --warning-background: #fff8e6;
            --warning-border: #e2b84b;
            --warning-text: #59420a;
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
        h2 {
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

        p,
        address {
            margin-top: 0.75rem;
            margin-bottom: 1rem;
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

        .muted,
        .legal-updated {
            color: var(--muted);
        }

        .legal-updated {
            margin-top: 2.5rem;
            margin-bottom: 0;
            font-size: 0.925rem;
        }

        .back {
            display: inline-block;
            margin-bottom: 16px;
            font-weight: 600;
        }

        .setup-warning {
            margin: 1.5rem 0;
            padding: 14px 16px;
            color: var(--warning-text);
            background: var(--warning-background);
            border: 1px solid var(--warning-border);
            border-radius: 10px;
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

            .back,
            .setup-warning {
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

    <?= render_impressum_fragment() ?>
</main>
</body>
</html>
