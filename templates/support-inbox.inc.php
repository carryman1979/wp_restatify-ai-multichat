<?php

$store = isset($store) && is_array($store) ? $store : [];
$selected_id = isset($selected_id) ? (string) $selected_id : '';
$ai_mode_options = isset($ai_mode_options) && is_array($ai_mode_options) ? $ai_mode_options : [];
$booking_open_token = defined('RESTATIFY_BOOKING_OPEN_TOKEN') ? (string) constant('RESTATIFY_BOOKING_OPEN_TOKEN') : '[[RESTATIFY_BOOKING_OPEN]]';
$booking_confirmed_token = defined('RESTATIFY_BOOKING_CONFIRMED_TOKEN') ? (string) constant('RESTATIFY_BOOKING_CONFIRMED_TOKEN') : '[[RESTATIFY_BOOKING_CONFIRMED]]';
$booking_cancelled_token = defined('RESTATIFY_BOOKING_CANCELLED_TOKEN') ? (string) constant('RESTATIFY_BOOKING_CANCELLED_TOKEN') : '[[RESTATIFY_BOOKING_CANCELLED]]';

echo '<h2>' . esc_html__('Support-Posteingang', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</h2>';
echo '<p>' . esc_html__('Offene Unterhaltungen von Website-Besuchern. Klicke auf eine Unterhaltung, um sie zu prüfen und zu antworten.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</p>';

echo '<div style="display:flex; gap:8px; align-items:center; margin:10px 0 14px;">';
echo '<strong style="margin-right:4px;">' . esc_html__('Filter:', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</strong>';
echo '<button type="button" class="button button-primary" data-mco-conversation-filter="all">' . esc_html__('Alle', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button>';
echo '<button type="button" class="button" data-mco-conversation-filter="confirmed">' . esc_html__('Buchung bestätigt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button>';
echo '<button type="button" class="button" data-mco-conversation-filter="cancelled">' . esc_html__('Buchung abgebrochen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button>';
echo '<button type="button" class="button" data-mco-conversation-filter="system">' . esc_html__('Systemereignisse', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button>';
echo '</div>';

if (count($store) === 0) {
    echo '<p>' . esc_html__('Noch keine Unterhaltungen vorhanden.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</p>';
    return;
}

echo '<table class="widefat striped">';
echo '<thead><tr>';
echo '<th>' . esc_html__('Unterhaltung', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
echo '<th>' . esc_html__('Aktualisiert (UTC)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
echo '<th>' . esc_html__('KI-Modus', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
echo '<th>' . esc_html__('Vorschau', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
echo '<th>' . esc_html__('Aktion', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
echo '</tr></thead><tbody>';

foreach ($store as $id => $conversation) {
    $messages = (array) ($conversation['messages'] ?? []);
    $last = end($messages);
    $preview = is_array($last) ? (string) ($last['message'] ?? '') : '';
    $preview = str_replace([
        $booking_open_token,
        $booking_confirmed_token,
        $booking_cancelled_token,
    ], '', $preview);
    if (function_exists('mb_substr')) {
        $preview = mb_substr($preview, 0, 100);
    } else {
        $preview = substr($preview, 0, 100);
    }

    $preview = trim($preview);

    $conversation_state = 'none';
    $conversation_badge = '';

    for ($idx = count($messages) - 1; $idx >= 0; $idx--) {
        $message_row = is_array($messages[$idx]) ? $messages[$idx] : [];
        $raw = (string) ($message_row['message'] ?? '');
        $sender = (string) ($message_row['sender'] ?? 'visitor');

        if ($raw !== '' && str_contains($raw, $booking_confirmed_token)) {
            $conversation_state = 'confirmed';
            $conversation_badge = '<span style="display:inline-block; padding:2px 8px; border-radius:999px; background:#e7f6ea; color:#116329; font-size:11px; font-weight:600;">' . esc_html__('Buchung bestätigt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</span>';
            break;
        }

        if ($raw !== '' && str_contains($raw, $booking_cancelled_token)) {
            $conversation_state = 'cancelled';
            $conversation_badge = '<span style="display:inline-block; padding:2px 8px; border-radius:999px; background:#fdecec; color:#8a1f1f; font-size:11px; font-weight:600;">' . esc_html__('Buchung abgebrochen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</span>';
            break;
        }

        if ($sender === 'system') {
            $conversation_state = 'system';
            $conversation_badge = '<span style="display:inline-block; padding:2px 8px; border-radius:999px; background:#eef2f6; color:#344054; font-size:11px; font-weight:600;">' . esc_html__('Systemereignis', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</span>';
            break;
        }
    }

    $is_selected = $selected_id !== '' && hash_equals($selected_id, (string) $id);
    $mode = $this->normalize_ai_mode((string) ($conversation['ai_mode'] ?? 'both'));
    $mode_label = (string) ($ai_mode_options[$mode] ?? $mode);
    $open_link = add_query_arg(
        [
            'page' => 'restatify-mco-support-inbox',
            'conversation' => (string) $id,
        ],
        admin_url('admin.php')
    );

    echo '<tr data-mco-conversation-row="' . esc_attr((string) $id) . '" data-mco-conversation-state="' . esc_attr($conversation_state) . '"' . ($is_selected ? ' style="background:#eef6ff"' : '') . '>';
    echo '<td><strong>' . esc_html((string) $id) . '</strong></td>';
    echo '<td>' . esc_html((string) ($conversation['updated_at_gmt'] ?? '')) . '</td>';
    echo '<td>' . esc_html($mode_label) . '</td>';
    echo '<td>' . $conversation_badge . ' ' . esc_html($preview) . '</td>';
    echo '<td>';
    echo '<a class="button" href="' . esc_url($open_link) . '">' . esc_html__('Öffnen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</a> ';
    echo '<button type="button" class="button button-link-delete" data-mco-support-delete data-conversation-id="' . esc_attr((string) $id) . '">' . esc_html__('Loeschen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody></table>';

if ($selected_id !== '' && !empty($store[$selected_id])) {
    $selected = $store[$selected_id];
    $selected_ai_mode = $this->normalize_ai_mode((string) ($selected['ai_mode'] ?? 'both'));
    $booking_overlay_available = function_exists('restatify_booking_ai_handle_message') || shortcode_exists('restatify_booking_popup');
    echo '<div id="restatify-mco-conversation-detail" data-mco-conversation-detail="' . esc_attr($selected_id) . '">';
    echo '<h3 style="margin-top:20px;">' . esc_html__('Unterhaltungsdetails', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</h3>';
    echo '<p><label for="restatify-mco-ai-mode"><strong>' . esc_html__('KI-Verhalten für diesen Chat', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</strong></label></p>';
    echo '<p>';
    echo '<select id="restatify-mco-ai-mode" data-mco-ai-mode style="min-width: 280px;">';
    foreach ($ai_mode_options as $mode_key => $mode_label) {
        echo '<option value="' . esc_attr((string) $mode_key) . '"' . selected($selected_ai_mode, (string) $mode_key, false) . '>' . esc_html((string) $mode_label) . '</option>';
    }
    echo '</select> ';
    echo '<button type="button" class="button" data-mco-support-ai-save data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('KI-Modus speichern', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button>';
    echo '</p>';
    echo '<div style="max-height:360px; overflow:auto; border:1px solid #ccd0d4; border-radius:6px; padding:12px; background:#fff;">';
    foreach ((array) ($selected['messages'] ?? []) as $msg) {
        if (!is_array($msg)) {
            continue;
        }

        $sender = (string) ($msg['sender'] ?? 'visitor');
        $label = $sender === 'support'
            ? __('Support', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)
            : ($sender === 'ai'
                ? __('AI', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)
                : ($sender === 'system' ? __('System', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) : __('Besucher', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)));
        $raw_message = (string) ($msg['message'] ?? '');
        $is_booking_confirmed = str_contains($raw_message, $booking_confirmed_token);
        $is_booking_cancelled = str_contains($raw_message, $booking_cancelled_token);
        $message_text = str_replace([
            $booking_open_token,
            $booking_confirmed_token,
            $booking_cancelled_token,
        ], '', $raw_message);
        $message_text = trim($message_text);

        $badge_html = '';
        if ($is_booking_confirmed) {
            $badge_html = '<span style="display:inline-block; margin-right:8px; padding:2px 8px; border-radius:999px; background:#e7f6ea; color:#116329; font-size:11px; font-weight:600;">' . esc_html__('Buchung bestätigt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</span>';
        } elseif ($is_booking_cancelled) {
            $badge_html = '<span style="display:inline-block; margin-right:8px; padding:2px 8px; border-radius:999px; background:#fdecec; color:#8a1f1f; font-size:11px; font-weight:600;">' . esc_html__('Buchung abgebrochen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</span>';
        } elseif ($sender === 'system') {
            $badge_html = '<span style="display:inline-block; margin-right:8px; padding:2px 8px; border-radius:999px; background:#eef2f6; color:#344054; font-size:11px; font-weight:600;">' . esc_html__('Systemereignis', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</span>';
        }

        echo '<p style="margin:0 0 10px;">';
        echo '<strong>' . esc_html($label) . ':</strong> ';
        echo $badge_html;
        echo esc_html(trim($message_text));
        echo '<br><small>' . esc_html((string) ($msg['time_gmt'] ?? '')) . '</small>';
        echo '</p>';
    }
    echo '</div>';

    echo '<div style="margin-top:14px;">';
    if ($booking_overlay_available) {
        echo '<p><button type="button" class="button" data-mco-support-open-booking data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('Buchungs-Overlay beim Besucher öffnen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button></p>';
    }
    echo '<textarea id="restatify-mco-support-reply" class="large-text" rows="3" placeholder="' . esc_attr__('Support-Antwort eingeben...', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '"></textarea>';
    echo '<p><button type="button" class="button button-primary" data-mco-support-send data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('Support-Antwort senden', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button></p>';
    echo '<p><button type="button" class="button button-link-delete" data-mco-support-delete data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('Diese Unterhaltung löschen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button></p>';
    echo '</div>';
    echo '</div>';
}
