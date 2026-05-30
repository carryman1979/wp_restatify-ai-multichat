<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Booking prompt and prefill helpers for the AI core runtime.
 */
trait Restatify_Ai_Multichat_Ai_Core_Booking_Trait {

    protected function build_effective_system_prompt(string $custom_prompt, int $max_response_chars, bool $booking_available = false, array $booking_contact_methods = []): string {
        $max_response_chars = $this->normalize_ai_max_response_chars($max_response_chars);

        // 1. ALLE allgemeinen Regeln als EIN Block
        $general_rules = [
            'HARTE SYSTEMANFORDERUNGEN (IMMER EINHALTEN):',
            '- Antworte immer in derselben Sprache wie die letzte Nutzer-Nachricht.',
            '- Gib nur Klartext aus. Keine Markdown-Codeblocks, kein HTML und kein XML. JSON ist nur in der explizit erlaubten Trigger-Zeile zulässig.',
            '- Erlaubte Formatierung nur mit einfachen Zeilenumbrüchen und optionalen Listenzeichen (- oder 1.).',
            '- Halte die Antwortlänge bei maximal ' . $max_response_chars . ' Zeichen.',
            '- Wenn der Nutzer bereits ein konkretes Anliegen schreibt, antworte direkt darauf und beginne nicht mit einer allgemeinen Begrüßung.',
            '- Erfinde keine internen Schritte oder Aktionen (z.B. Weiterleitung, technische Prüfung, Abstimmung mit Team), wenn sie nicht tatsächlich in diesem Chat ausgelöst wurden.',
            '- Mache keine rechtlichen Garantien als Fakt (z.B. "vollständig rechtssicher", "automatisch DSGVO/UWG-konform"). Formuliere vorsichtig und empfehle fachliche Prüfung.',
            '- Erfinde keine Integrations- oder Produktfähigkeiten als bestehende Tatsache (keine fiktiven Konnektoren, APIs, Features).',
            '',
            'WIEDERHOLUNGSVERBOT (STRIKT):',
            '- Fakten, die im bisherigen Gespräch bereits genannt wurden, werden NICHT erneut erklärt oder aufgelistet.',
            '- Bereits bekannte Kontextinformationen (z.B. genutztes CRM, Branche, Unternehmensgröße, Schwerpunkte) werden maximal einmal kurz als Bezugspunkt erwähnt, niemals erneut ausgeführt.',
            '- Feature-Listen und Produktbeschreibungen dürfen pro Gespräch nur einmal vorkommen. Danach ist jede Wiederholung verboten, auch in anderen Worten.',
            '',
            'ANTWORTLÄNGE – PROGRESSIV KÜRZEN:',
            '- Zähle die Gesprächsrunden seit Gesprächsbeginn. Eine Runde = eine Nutzerantwort + eine KI-Antwort.',
            '- Runde 1-2: ausführliche Antwort erlaubt (Kontext aufbauen).',
            '- Runde 3-4: maximal 5 Sätze oder 2 kurze Listenpunkte.',
            '- Runde 5+: maximal 3 Sätze. Kein Einstieg mit Produktpräsentation.',
            '- Im Buchungsmodus (s.u.): maximal 1-2 gezielte Rückfragen, kein Beratungstext.',
            '',
            'KURZANTWORT-REGEL (KRITISCH):',
            '- Wenn deine vorherige Antwort mit einer Frage endete UND die aktuelle Nutzerantwort kürzer als 60 Zeichen ist, gilt sie IMMER als direkte Antwort auf deine Frage.',
            '- In diesem Fall: Beantworte oder verarbeite die Antwort direkt. KEIN erneuter Pitch, KEINE Feature-Liste, KEINE Zusammenfassung bereits bekannter Vorteile.',
            '- Beispiel: Du fragst "Wäre ein kurzer Termin sinnvoll?" → Nutzer antwortet "Ja, habe ich." → Du gehst SOFORT zum Buchungsworkflow, ohne weitere Erklärungen.',
            '',
            'BUCHUNGSMODUS (AKTIVIERUNG UND VERHALTEN):',
            '- Der BUCHUNGSMODUS wird aktiviert, sobald der Nutzer zum zweiten Mal Zustimmung oder Termininteresse signalisiert (z.B. "ja", "gerne", "wäre toll", "passt", "ich habe Interesse", "bin offen").',
            '- Im BUCHUNGSMODUS gilt ausschließlich: Sammle die fehlenden Pflichtinformationen für die Terminbuchung (Name, Kontaktweg, Wunschtermin/Datum+Uhrzeit). Eine Frage pro Nachricht.',
            '- Im BUCHUNGSMODUS sind verboten: Produktbeschreibungen, Feature-Listen, Lösungsvorschläge, Zusammenfassungen, Fragen nach dem Anliegen (das ist bereits bekannt).',
            '- Sobald alle Pflichtinformationen vorhanden sind, gib den Booking-Trigger aus.',
            'BUCHUNGS-/LINK-REGELN (NUR BEACHTEN, WENN TERMINVEREINBARUNG ODER BUCHUNGSWUNSCH VORLIEGT):',
            '- Biete NIEMALS von dir aus einen Kalender-Link, Meeting-Link, Zugangsdaten oder Einwahldaten an – auch nicht nach einer Terminvereinbarung oder Uhrzeitbestätigung. Sende solche Links oder Daten AUSSCHLIESSLICH, wenn der Nutzer EXPLIZIT danach fragt (z. B. "Bitte senden Sie mir den Link", "Wie komme ich ins Meeting?", "Ich brauche die Zugangsdaten", "Schicken Sie mir die Einwahldaten").'
        ];

        // 2. Danach: bedingungsabhängige Buchungs-/Link-Regeln und Trigger-JSON
        $booking_rules = [];
        if ($booking_available) {
            $booking_url = '#booking-open';
            if (function_exists('home_url')) {
                $current_url = $_SERVER['REQUEST_URI'] ?? '';
                $booking_url = home_url($current_url) . '#booking-open';
            }
            $booking_rules = [
                '- DER EINZIG GÜLTIGE LINK DEN DU ANBIETEN DARFST IST: ' . $booking_url . '#booking',
                '- Nach einer Terminvereinbarung oder Uhrzeitbestätigung: Biete an, das Terminbuchungstool (Popup) zu öffnen, aber sende KEINEN Link, außer der Nutzer fordert explizit einen Link oder Zugangsdaten an.',
                '- Beispiel: Nutzer: "11 Uhr wäre mir lieber." → Du bestätigst nur den Termin, bietest aber KEINEN Link an. Stattdessen: "Soll ich das Terminbuchungstool für Sie öffnen?"',
                '- Beispiel: Nutzer: "Können Sie mir den Link schicken?" → Jetzt darfst du den Link senden: ' . $booking_url . '#booking'
            ];
        } else {
            $booking_rules = [
                '- Nach einer Terminvereinbarung oder Uhrzeitbestätigung: Informiere, dass ein Mitarbeiter über diesen Chat informiert wird und versucht, dazu zu kommen, um die Terminvereinbarung abzuschließen. Du kannst immer anbieten, einen Mitarbeiter in den Chat dazuzuholen.',
                '- Beispiel 1: Nutzer: "11 Uhr wäre mir lieber." → Du bestätigst nur den Termin, bietest aber KEINEN Link an. Stattdessen: "Ich informiere einen Kollegen, der Sie gleich im Chat unterstützt."',
                '- Beispiel 2: Nutzer: "Können Sie mir den Link schicken?" → Du: "Natürlich! Ich hole einen Kollegen, der Ihnen die Details zum Vorgang erläutern kann."',
                '- Beispiel 3: Nutzer: "Ich brauche die Zugangsdaten." → Du: "Ein Moment – ich informiere einen Kollegen, der mit Ihnen sofort die Zugangsdaten und Kalender-Details besprechen wird."',
                '- Beispiel 4: Nutzer: "Perfekt, Mittwoch 14 Uhr passt." → Du bestätigst: "Prima! Ein Mitarbeiter von uns kommt gleich in den Chat und hilft Ihnen mit den finalen Details und dem Termin."',
                '- Beispiel 5: Nutzer: "Wie bekomme ich den Kalender-Link?" → Du: "Gerne! Ich hole einen Kollegen, der dir sofort alle notwendigen Links und Informationen schickt."'
            ];
        }

        // Trigger-JSON und Kontaktmethodenhinweise nur, wenn Buchungstool verfügbar
        if ($booking_available) {
            $booking_contact_methods = array_values(array_unique(array_filter(array_map('sanitize_key', $booking_contact_methods), static function ($value) {
                return $value !== '';
            })));
            if (count($booking_contact_methods) === 0) {
                $booking_contact_methods = $this->get_booking_default_contact_methods();
            }

            $contact_methods_hint = implode('|', $booking_contact_methods);
            $prefill_token = $this->get_booking_prefill_token();
            $schema_keys_hint = implode(', ', $this->get_booking_prefill_allowed_keys());
            $booking_rules[] = '- Wenn der Nutzer eine Terminvereinbarung bestätigt oder konkrete Buchungsdaten nennt, füge am Ende genau eine Trigger-Zeile hinzu:';
            $booking_rules[] = '  ' . $prefill_token . '{"intent":"booking_confirm","subject":"...","note":"...","name":"...","email":"...","contact_method":"' . $contact_methods_hint . '","contact_value":"...","date":"YYYY-MM-DD","time":"HH:MM"}';
            $booking_rules[] = '- Trigger-JSON hartes Schema: Erlaubte Keys sind nur ' . $schema_keys_hint . '. Keine weiteren Keys ausgeben.';
            $booking_rules[] = '- Datentypen strikt: alle Felder als String; contact_method nur einer von ' . $contact_methods_hint . '; date nur YYYY-MM-DD; time nur HH:MM.';
            $booking_rules[] = '- Wenn der Nutzer eine nicht unterstützte Kontaktmethode nennt, frage nach und biete nur diese erlaubten Optionen an: ' . $contact_methods_hint . '.';
            $booking_rules[] = '- Fülle subject immer als kurze Zusammenfassung der Terminanfrage aus dem Chat (maximal 90 Zeichen).';
            $booking_rules[] = '- Fülle note immer als kompakte Zusammenfassung der relevanten Chat-Infos aus (Anliegen, Rahmen, Terminwunsch, offene Punkte).';
            $booking_rules[] = '- Sammle vor dem Trigger fehlende Pflichtinfos per Rückfrage im Chat (mindestens Name, Kontaktweg und Terminwunsch mit Datum/Uhrzeit), außer der Nutzer will die Daten selbst im Popup ausfüllen.';
            $booking_rules[] = '- Wenn der Nutzer ausdrücklich selbst im Popup ausfüllen möchte, gib den Trigger trotzdem aus, mindestens als: ' . $prefill_token . '{"intent":"booking_confirm"}';
            $booking_rules[] = '- Gib diese Trigger-Zeile nur aus, wenn ein Termin wirklich gewünscht ist. Bei Ablehnung niemals Trigger ausgeben.';
            $booking_rules[] = '- Die Trigger-Zeile darf die einzige JSON-Ausgabe sein.';
        }

        $prompt = implode("\n", $general_rules) . "\n\n" . implode("\n", $booking_rules);
        $custom_prompt = trim($custom_prompt);

        if ($custom_prompt === '') {
            return $prompt;
        }

        return $custom_prompt . "\n\n" . $prompt;
    }

    protected function is_booking_plugin_available(): bool {
        return function_exists('restatify_booking_ai_handle_message');
    }

    protected function extract_booking_prefill_payload(string &$content): ?array {
        $prefill_token = $this->get_booking_prefill_token();

        if (strpos($content, $prefill_token) === false) {
            return null;
        }

        $pattern = '/' . preg_quote($prefill_token, '/') . '\\s*(\{[^\n\r]*\})/u';
        if (!preg_match($pattern, $content, $matches)) {
            return null;
        }

        $raw_json = trim((string) ($matches[1] ?? ''));
        if ($raw_json === '') {
            return null;
        }

        $decoded = json_decode($raw_json, true);
        if (!is_array($decoded)) {
            return null;
        }

        $payload = $this->normalize_booking_prefill_payload($decoded);
        $content = trim(str_replace((string) $matches[0], '', $content));
        return count($payload) > 0 ? $payload : null;
    }

    protected function normalize_booking_prefill_payload(array $payload): array {
        $out = [];
        $allowed_contact_methods = $this->get_booking_contact_methods();
        if (count($allowed_contact_methods) === 0) {
            $allowed_contact_methods = $this->get_booking_default_contact_methods();
        }

        $intent = sanitize_key((string) ($payload['intent'] ?? ''));
        if ($intent === 'booking_confirm') {
            $out['intent'] = $intent;
        }

        $subject = sanitize_text_field((string) ($payload['subject'] ?? ''));
        if ($subject !== '') {
            $out['subject'] = $this->truncate_chat_text($subject, 190);
        }

        $note = sanitize_textarea_field((string) ($payload['note'] ?? ''));
        if ($note !== '') {
            $out['note'] = $this->truncate_chat_text($note, 1000);
        }

        $name = sanitize_text_field((string) ($payload['name'] ?? ''));
        if ($name !== '') {
            $out['name'] = $this->truncate_chat_text($name, 190);
        }

        $email = sanitize_email((string) ($payload['email'] ?? ''));
        if ($email !== '') {
            $out['email'] = $email;
        }

        $contact_method = sanitize_key((string) ($payload['contact_method'] ?? ''));
        if (in_array($contact_method, $allowed_contact_methods, true)) {
            $out['contact_method'] = $contact_method;
        }

        $contact_value = sanitize_text_field((string) ($payload['contact_value'] ?? ''));
        if ($contact_value !== '') {
            $out['contact_value'] = $this->truncate_chat_text($contact_value, 190);
        }

        $date = sanitize_text_field((string) ($payload['date'] ?? ''));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $out['date'] = $date;
        }

        $time = sanitize_text_field((string) ($payload['time'] ?? ''));
        if ($time !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1) {
            $out['time'] = $time;
        }

        return $out;
    }

    protected function get_booking_contact_methods(): array {
        if (!class_exists('Restatify_Booking_Assistant_Constants')) {
            return [];
        }

        if (!class_exists('\Restatify\Shared\Util\BookingContactMethodsResolver', false)) {
            throw new RuntimeException('Missing required shared dependency: Restatify\\Shared\\Util\\BookingContactMethodsResolver');
        }

        $option_key = defined('Restatify_Booking_Assistant_Constants::OPTION_KEY')
            ? (string) constant('Restatify_Booking_Assistant_Constants::OPTION_KEY')
            : 'restatify_booking_options';

        $options = get_option($option_key, []);
        if (!is_array($options)) {
            $options = [];
        }

        return \Restatify\Shared\Util\BookingContactMethodsResolver::methodsFromOptions($options);
    }

    protected function enrich_booking_prefill_payload(array $payload, array $conversation, string $latest_message): array {
        if (empty($payload['subject'])) {
            $payload['subject'] = $this->build_booking_subject_from_context($conversation, $latest_message);
        }

        if (empty($payload['note'])) {
            $payload['note'] = $this->build_booking_note_from_context($conversation, $latest_message);
        }

        return $payload;
    }

    protected function build_booking_subject_from_context(array $conversation, string $latest_message): string {
        $candidates = [];

        $latest = sanitize_text_field($latest_message);
        if ($latest !== '') {
            $candidates[] = $latest;
        }

        $history = array_reverse((array) ($conversation['messages'] ?? []));
        foreach ($history as $item) {
            if (!is_array($item) || (string) ($item['sender'] ?? '') !== 'visitor') {
                continue;
            }

            $text = sanitize_text_field((string) ($item['message'] ?? ''));
            if ($text !== '') {
                $candidates[] = $text;
            }

            if (count($candidates) >= 3) {
                break;
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim(preg_replace('/\s+/u', ' ', $candidate));
            if ($candidate !== '') {
                return $this->truncate_chat_text($candidate, 90);
            }
        }

        return 'Termin-Anfrage aus Chat';
    }

    protected function build_booking_note_from_context(array $conversation, string $latest_message): string {
        $lines = [];
        $history = array_slice((array) ($conversation['messages'] ?? []), -6);

        foreach ($history as $item) {
            if (!is_array($item) || empty($item['message'])) {
                continue;
            }

            $sender = (string) ($item['sender'] ?? 'visitor');
            $role = $sender === 'visitor' ? 'Kunde' : 'Assistent';
            $text = sanitize_textarea_field((string) $item['message']);
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            if ($text === '') {
                continue;
            }

            $lines[] = $role . ': ' . $this->truncate_chat_text($text, 220);
        }

        $latest = sanitize_textarea_field($latest_message);
        $latest = trim(preg_replace('/\s+/u', ' ', $latest));
        if ($latest !== '') {
            $latest_line = 'Kunde: ' . $this->truncate_chat_text($latest, 220);
            if (empty($lines) || end($lines) !== $latest_line) {
                $lines[] = $latest_line;
            }
        }

        if (empty($lines)) {
            return 'Termin-Anfrage aus dem Chat. Details werden im Popup ergaenzt.';
        }

        $note = "Zusammenfassung aus dem Chat:\n" . implode("\n", $lines);
        return $this->truncate_chat_text($note, 1000);
    }

    protected function attach_booking_prefill_trigger(string $content, array $payload): string {
        $booking_open_token = defined('RESTATIFY_BOOKING_OPEN_TOKEN') ? (string) constant('RESTATIFY_BOOKING_OPEN_TOKEN') : '[[RESTATIFY_BOOKING_OPEN]]';
        $prefill_token = $this->get_booking_prefill_token();
        $payload_json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload_json) || $payload_json === '') {
            return $content;
        }

        $trigger = $booking_open_token . ' ' . $prefill_token . $payload_json;
        return trim($content) === '' ? $trigger : trim($content) . "\n" . $trigger;
    }

    protected function get_booking_prefill_token(): string {
        if (class_exists('\\Restatify\\Shared\\Contracts\\BookingPrefillSchema', false)) {
            return (string) \Restatify\Shared\Contracts\BookingPrefillSchema::TOKEN;
        }

        return '[[RESTATIFY_BOOKING_PREFILL]]';
    }

    /**
     * @return array<int,string>
     */
    protected function get_booking_prefill_allowed_keys(): array {
        if (class_exists('\\Restatify\\Shared\\Contracts\\BookingPrefillSchema', false)) {
            return \Restatify\Shared\Contracts\BookingPrefillSchema::allowedKeys();
        }

        return ['intent', 'subject', 'note', 'name', 'email', 'contact_method', 'contact_value', 'date', 'time'];
    }

    /**
     * @return array<int,string>
     */
    protected function get_booking_default_contact_methods(): array {
        if (class_exists('\\Restatify\\Shared\\Contracts\\BookingPrefillSchema', false)) {
            return \Restatify\Shared\Contracts\BookingPrefillSchema::defaultContactMethods();
        }

        return ['phone', 'zoom', 'teams', 'whatsapp', 'email'];
    }
}
