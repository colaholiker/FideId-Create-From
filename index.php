<?php
/**
 * Formular zur Beantragung einer FIDE-ID beim Deutschen Schachbund.
 * Format laut https://www.schachbund.de/fide-identifikationsnummer.html:
 *   Nachname, Vorname[, Titel]; JJJJ-MM-TT; M/F
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Konfiguration
// ---------------------------------------------------------------------------
const MAIL_TO       = 'elo@schachbund.com';
const MAIL_FROM     = 'noreply@example.org';   // Absender-Adresse eurer Domain
const TEST_MODE     = true;                     // true = nur Vorschau, keine E-Mail senden
const MAX_PLAYERS   = 20;
const MAX_FILE_SIZE = 5 * 1024 * 1024;          // 5 MB pro Ausweiskopie
const ALLOWED_MIME  = ['image/jpeg', 'image/png', 'application/pdf'];

// ---------------------------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------------------------
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Umlaute ersetzen, sonstige diakritische Zeichen ersatzlos entfernen. */
function transliterate(string $s, string $extraAllowed = ''): string
{
    $s = strtr(trim($s), [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'ẞ' => 'SS',
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
    ]);
    if (class_exists('Normalizer')) {
        $s = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($s, Normalizer::FORM_D));
    } else {
        $s = (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    }
    // Alles außer Buchstaben, Leerzeichen, Bindestrich und Apostroph entfernen
    $s = preg_replace("/[^A-Za-z \\-'" . preg_quote($extraAllowed, '/') . ']/', '', $s);
    return preg_replace('/\s+/', ' ', trim($s));
}

/** Titel wie Namen behandeln, zusätzlich Punkte erlauben (z. B. "Dr.", "Prof. Dr."). */
function transliterateTitle(string $s): string
{
    return transliterate($s, '.');
}

function formatPlayer(array $p): string
{
    $name = transliterate($p['nachname']) . ', ' . transliterate($p['vorname']);
    $titel = transliterateTitle($p['titel']);
    if ($titel !== '') {
        $name .= ', ' . $titel;
    }
    return $name . '; ' . $p['geburtsdatum'] . '; ' . $p['geschlecht'];
}

function isValidDate(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d && $dt <= new DateTime();
}

function isUnder16(string $d): bool
{
    return (new DateTime($d))->diff(new DateTime())->y < 16;
}

function oneLine(string $s): string
{
    return trim(str_replace(["\r", "\n"], ' ', $s));
}

// ---------------------------------------------------------------------------
// Verarbeitung
// ---------------------------------------------------------------------------
$errors  = [];
$success = false;
$mailBody = '';

$form = [
    'antragsteller' => '',
    'email'         => '',
    'verein'        => '',
    'turnier'       => '',
    'bemerkung'     => '',
    'players'       => [['nachname' => '', 'vorname' => '', 'titel' => '', 'geburtsdatum' => '', 'geschlecht' => '', 'dsb' => '1']],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['antragsteller', 'email', 'verein', 'turnier', 'bemerkung'] as $k) {
        $form[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if ($form['antragsteller'] === '') $errors[] = 'Bitte den Namen des Antragstellers angeben.';
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    if (empty($_POST['consent_fide'])) $errors[] = 'Bitte die Weitergabe der Daten an die FIDE bestätigen.';

    $form['players'] = [];
    $rawPlayers = array_slice((array)($_POST['players'] ?? []), 0, MAX_PLAYERS, true);
    $attachments = [];
    $anyUnder16 = false;
    $anyForeign = false;

    foreach ($rawPlayers as $idx => $raw) {
        $p = [
            'nachname'     => trim((string)($raw['nachname'] ?? '')),
            'vorname'      => trim((string)($raw['vorname'] ?? '')),
            'titel'        => trim((string)($raw['titel'] ?? '')),
            'geburtsdatum' => trim((string)($raw['geburtsdatum'] ?? '')),
            'geschlecht'   => (string)($raw['geschlecht'] ?? ''),
            'dsb'          => !empty($raw['dsb']) ? '1' : '',
            'ausland'      => !empty($raw['ausland']) ? '1' : '',
        ];
        // Komplett leere Zeilen ignorieren
        if ($p['nachname'] === '' && $p['vorname'] === '' && $p['geburtsdatum'] === '') {
            continue;
        }
        $nr = count($form['players']) + 1;
        $form['players'][] = $p;

        if (transliterate($p['nachname']) === '') $errors[] = "Spieler $nr: Nachname fehlt.";
        if (transliterate($p['vorname']) === '')  $errors[] = "Spieler $nr: Vorname fehlt.";
        if (!isValidDate($p['geburtsdatum'])) {
            $errors[] = "Spieler $nr: Ungültiges Geburtsdatum.";
        } elseif (isUnder16($p['geburtsdatum'])) {
            $anyUnder16 = true;
        }
        if (!in_array($p['geschlecht'], ['M', 'F'], true)) $errors[] = "Spieler $nr: Bitte Geschlecht wählen.";
        if ($p['ausland']) $anyForeign = true;

        // Ausweiskopie nur nötig, wenn kein Mitglied in einem DSB-Verein
        if (!$p['dsb']) {
            $f = [
                'name'     => $_FILES['ausweis']['name'][$idx] ?? '',
                'tmp_name' => $_FILES['ausweis']['tmp_name'][$idx] ?? '',
                'error'    => $_FILES['ausweis']['error'][$idx] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $_FILES['ausweis']['size'][$idx] ?? 0,
            ];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "Spieler $nr: Ohne DSB-Mitgliedschaft ist eine Ausweiskopie erforderlich.";
            } elseif ($f['size'] > MAX_FILE_SIZE) {
                $errors[] = "Spieler $nr: Ausweiskopie ist zu groß (max. 5 MB).";
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                if (!in_array($mime, ALLOWED_MIME, true)) {
                    $errors[] = "Spieler $nr: Ausweiskopie muss JPG, PNG oder PDF sein.";
                } else {
                    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime];
                    $attachments[] = [
                        'filename' => 'Ausweis_' . str_replace(' ', '_', transliterate($p['nachname'] . '_' . $p['vorname'])) . '.' . $ext,
                        'mime'     => $mime,
                        'data'     => file_get_contents($f['tmp_name']),
                    ];
                }
            }
        }
    }

    if (!$form['players']) $errors[] = 'Bitte mindestens einen Spieler eintragen.';
    if ($anyUnder16 && empty($_POST['consent_parents'])) {
        $errors[] = 'Mindestens ein Spieler ist unter 16 Jahre alt – bitte die Zustimmung der Erziehungsberechtigten bestätigen.';
    }
    if ($anyForeign && empty($_POST['consent_foreign'])) {
        $errors[] = 'Bitte bestätigen, dass ausländische Spieler ausdrücklich mit einer deutschen FIDE-ID einverstanden sind.';
    }

    if (!$errors) {
        $lines = array_map('formatPlayer', $form['players']);

        $mailBody = "Hallo,\n\n"
            . "hiermit beantrage ich für folgende Spieler eine FIDE-ID:\n\n"
            . implode("\n", $lines) . "\n\n"
            . ($form['turnier'] !== '' ? 'Turnier / Anlass: ' . oneLine($form['turnier']) . "\n" : '')
            . ($form['verein'] !== '' ? 'Verein: ' . oneLine($form['verein']) . "\n" : '')
            . "\n"
            . "Ich bestätige, dass die Daten an die FIDE weitergegeben werden sollen bzw. die betroffenen Personen der Weitergabe zugestimmt haben.\n"
            . ($anyUnder16 ? "Für Spieler unter 16 Jahren liegt die Zustimmung der Erziehungsberechtigten vor.\n" : '')
            . ($anyForeign ? "Ausländische Spieler haben ausdrücklich ihr Einverständnis zu einer deutschen FIDE-ID gegeben.\n" : '')
            . ($attachments ? "\nFür Spieler ohne Mitgliedschaft in einem DSB-Verein ist eine Ausweiskopie angehängt.\n" : '')
            . ($form['bemerkung'] !== '' ? "\nBemerkung:\n" . $form['bemerkung'] . "\n" : '')
            . "\nMit freundlichen Grüßen\n"
            . oneLine($form['antragsteller']) . "\n"
            . $form['email'] . "\n";

        $subject = 'Antrag FIDE-ID' . ($form['turnier'] !== '' ? ' - ' . oneLine($form['turnier']) : '');
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers = [
            'From: ' . MAIL_FROM,
            'Reply-To: ' . $form['email'],
            'Cc: ' . $form['email'],
            'MIME-Version: 1.0',
        ];

        if ($attachments) {
            $boundary = 'b_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $body = "--$boundary\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . str_replace("\n", "\r\n", $mailBody) . "\r\n";
            foreach ($attachments as $a) {
                $body .= "--$boundary\r\n"
                    . "Content-Type: {$a['mime']}; name=\"{$a['filename']}\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\n"
                    . "Content-Disposition: attachment; filename=\"{$a['filename']}\"\r\n\r\n"
                    . chunk_split(base64_encode($a['data'])) . "\r\n";
            }
            $body .= "--$boundary--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $body = str_replace("\n", "\r\n", $mailBody);
        }

        if (TEST_MODE) {
            $success = true;
        } elseif (mail(MAIL_TO, $encodedSubject, $body, implode("\r\n", $headers))) {
            $success = true;
        } else {
            $errors[] = 'Die E-Mail konnte nicht versendet werden. Bitte später erneut versuchen.';
        }
    }

    if (!$form['players']) {
        $form['players'][] = ['nachname' => '', 'vorname' => '', 'titel' => '', 'geburtsdatum' => '', 'geschlecht' => '', 'dsb' => '1'];
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FIDE-ID beantragen</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 960px; margin: 2rem auto; padding: 0 1rem; color: #222; }
        fieldset { border: 1px solid #ccc; border-radius: 6px; margin-bottom: 1.5rem; padding: 1rem; }
        label { display: block; margin: .5rem 0 .2rem; font-weight: 600; }
        input[type=text], input[type=email], input[type=date], select, textarea { width: 100%; padding: .4rem; box-sizing: border-box; }
        .player { border: 1px dashed #bbb; border-radius: 6px; padding: .8rem; margin-bottom: 1rem; position: relative; }
        .row { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: .8rem; }
        .check { font-weight: normal; display: flex; gap: .4rem; align-items: flex-start; }
        .remove { position: absolute; top: .4rem; right: .4rem; }
        .errors { background: #fde8e8; border: 1px solid #e0a0a0; padding: .8rem 1.2rem; border-radius: 6px; }
        .success { background: #e6f6e6; border: 1px solid #90c890; padding: .8rem 1.2rem; border-radius: 6px; }
        pre { background: #f4f4f4; padding: 1rem; white-space: pre-wrap; border-radius: 6px; }
        button { padding: .5rem 1rem; cursor: pointer; }
        small { color: #666; }
        footer { margin-top: 3rem; padding-top: 1rem; border-top: 1px solid #ccc; font-size: .85rem; color: #666; }
        @media (max-width: 700px) { .row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<h1>FIDE-ID beantragen</h1>
<p>
    Antrag für deutsche Spieler ohne FIDE-ID beim Deutschen Schachbund
    (<a href="https://www.schachbund.de/fide-identifikationsnummer.html" target="_blank" rel="noopener">Informationen</a>).
    Bitte vorher auf <a href="https://ratings.fide.com" target="_blank" rel="noopener">ratings.fide.com</a> prüfen,
    ob bereits eine FIDE-ID existiert.
</p>

<?php if ($errors): ?>
    <div class="errors">
        <strong>Bitte korrigieren:</strong>
        <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="success">
        <?php if (TEST_MODE): ?>
            <strong>Testmodus:</strong> Es wurde keine E-Mail versendet. So würde die E-Mail an <?= h(MAIL_TO) ?> aussehen:
        <?php else: ?>
            <strong>Vielen Dank!</strong> Der Antrag wurde an <?= h(MAIL_TO) ?> gesendet. Eine Kopie geht an <?= h($form['email']) ?>.
        <?php endif; ?>
    </div>
    <pre><?= h($mailBody) ?></pre>
    <p><a href="<?= h($_SERVER['PHP_SELF']) ?>">Neuen Antrag stellen</a></p>
<?php else: ?>

<form method="post" enctype="multipart/form-data">
    <fieldset>
        <legend>Antragsteller</legend>
        <div class="row" style="grid-template-columns: 1fr 1fr 1fr;">
            <div>
                <label for="antragsteller">Name *</label>
                <input type="text" id="antragsteller" name="antragsteller" required value="<?= h($form['antragsteller']) ?>">
            </div>
            <div>
                <label for="email">E-Mail *</label>
                <input type="email" id="email" name="email" required value="<?= h($form['email']) ?>">
            </div>
            <div>
                <label for="verein">Verein</label>
                <input type="text" id="verein" name="verein" value="<?= h($form['verein']) ?>">
            </div>
        </div>
        <label for="turnier">Turnier / Anlass</label>
        <input type="text" id="turnier" name="turnier" value="<?= h($form['turnier']) ?>"
               placeholder="z. B. 5. Musterstädter Open, 01.–03.11.2026">
    </fieldset>

    <fieldset>
        <legend>Spieler</legend>
        <small>Umlaute werden automatisch ersetzt (ä→ae, ö→oe, ü→ue, ß→ss), Akzente entfallen.</small>
        <div id="players">
            <?php foreach ($form['players'] as $i => $p): ?>
                <div class="player">
                    <button type="button" class="remove" onclick="removePlayer(this)" title="Entfernen">✕</button>
                    <div class="row">
                        <div>
                            <label>Nachname *</label>
                            <input type="text" name="players[<?= $i ?>][nachname]" value="<?= h($p['nachname']) ?>">
                        </div>
                        <div>
                            <label>Vorname *</label>
                            <input type="text" name="players[<?= $i ?>][vorname]" value="<?= h($p['vorname']) ?>">
                        </div>
                        <div>
                            <label>Geburtsdatum *</label>
                            <input type="date" name="players[<?= $i ?>][geburtsdatum]" value="<?= h($p['geburtsdatum']) ?>">
                        </div>
                        <div>
                            <label>Geschlecht *</label>
                            <select name="players[<?= $i ?>][geschlecht]">
                                <option value="">– bitte wählen –</option>
                                <option value="M" <?= $p['geschlecht'] === 'M' ? 'selected' : '' ?>>männlich (M)</option>
                                <option value="F" <?= $p['geschlecht'] === 'F' ? 'selected' : '' ?>>weiblich (F)</option>
                            </select>
                        </div>
                    </div>
                    <label>Titel</label>
                    <input type="text" name="players[<?= $i ?>][titel]" value="<?= h($p['titel']) ?>" placeholder="z. B. Dr." style="max-width: 15rem;">
                    <label class="check"><input type="checkbox" name="players[<?= $i ?>][ausland]" value="1" <?= !empty($p['ausland']) ? 'checked' : '' ?>> Ausländischer Spieler</label>
                    <label class="check"><input type="checkbox" class="dsb" name="players[<?= $i ?>][dsb]" value="1" onchange="toggleUpload(this)" <?= !empty($p['dsb']) ? 'checked' : '' ?>> Mitglied in einem Verein des DSB</label>
                    <div class="upload" style="<?= !empty($p['dsb']) ? 'display:none' : '' ?>">
                        <label>Ausweiskopie (JPG, PNG oder PDF, max. 5 MB) *</label>
                        <input type="file" name="ausweis[<?= $i ?>]" accept=".jpg,.jpeg,.png,.pdf">
                        <small>Nachname, Vornamen und Geburtsdatum müssen lesbar sein, alles andere darf geschwärzt werden.</small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" onclick="addPlayer()">+ Weiteren Spieler hinzufügen</button>
    </fieldset>

    <fieldset>
        <legend>Bestätigungen</legend>
        <label class="check"><input type="checkbox" name="consent_fide" value="1" required>
            Ich bestätige, dass die Daten an die FIDE weitergegeben werden sollen bzw. die betroffenen Personen der Weitergabe zugestimmt haben. *</label>
        <label class="check"><input type="checkbox" name="consent_parents" value="1">
            Bei Spielern unter 16 Jahren liegt die Zustimmung der Erziehungsberechtigten vor.</label>
        <label class="check"><input type="checkbox" name="consent_foreign" value="1">
            Ausländische Spieler sind ausdrücklich mit einer deutschen FIDE-ID einverstanden.</label>
        <label for="bemerkung">Bemerkung</label>
        <textarea id="bemerkung" name="bemerkung" rows="3"><?= h($form['bemerkung']) ?></textarea>
    </fieldset>

    <button type="submit">Antrag absenden</button>
</form>

<script>
    let playerIndex = <?= max(array_keys($form['players'])) + 1 ?>;
    const maxPlayers = <?= MAX_PLAYERS ?>;

    function addPlayer() {
        const container = document.getElementById('players');
        if (container.children.length >= maxPlayers) return;
        const clone = container.firstElementChild.cloneNode(true);
        clone.querySelectorAll('input, select').forEach(el => {
            el.name = el.name.replace(/\[\d+\]/, '[' + playerIndex + ']');
            if (el.type === 'checkbox') el.checked = el.classList.contains('dsb');
            else el.value = '';
        });
        clone.querySelector('.upload').style.display = 'none';
        container.appendChild(clone);
        playerIndex++;
    }

    function removePlayer(btn) {
        const container = document.getElementById('players');
        if (container.children.length > 1) btn.closest('.player').remove();
    }

    function toggleUpload(cb) {
        cb.closest('.player').querySelector('.upload').style.display = cb.checked ? 'none' : '';
    }
</script>
<?php endif; ?>

<footer>
    <p>
        <strong>Hinweis:</strong> Dies ist kein offizielles Angebot des Deutschen Schachbundes (DSB) oder der FIDE,
        sondern ein Spaßprojekt des Schachvereins Balingen. Es wird keinerlei Gewährleistung oder Haftung
        für Richtigkeit, Vollständigkeit oder Verfügbarkeit übernommen.
        <?php if (TEST_MODE): ?>Es wird keine E-Mail verschickt.<?php endif; ?>
    </p>
</footer>

</body>
</html>
