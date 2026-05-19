<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Slot Manager for Dual-Session Router.
 * 
 * Handles slot pre-loading and live fetching via booking plugin API.
 * 
 * Spec:
 * - Pre-load: Fetch slots at chat init for immediate suggestions
 * - Live-refresh: Before any concrete slot utterance, query `/v1/slots/search` live
 * - Output: Max 3 slots per response; indicate if more exist
 * - Match logic: Exact match required; if no, offer 3 nearest alternatives (same day, closest to time)
 */
class Restatify_Ai_Dual_Session_Slot_Manager {

    private array $options = [];

    const PRELOAD_WINDOW_DAYS = 30;
    const PRELOAD_CACHE_TTL = 60; // 60 seconds
    const MAX_SLOTS_PER_RESPONSE = 3;
    const MAX_ALTERNATIVE_SLOTS = 3;

    public function __construct(array $options = []) {
        $this->options = $options;
    }

    /**
     * Fetch preloaded slots for initial chat context.
     * 
     * Called at chat init or when user asks general booking question.
     * Returns up to MAX_SLOTS_PER_RESPONSE slots from next 30 days.
     */
    public function fetch_preloaded_slots(): array {
        $start_date = new DateTime('now', new DateTimeZone('UTC'));
        $end_date = clone $start_date;
        $end_date->modify('+' . self::PRELOAD_WINDOW_DAYS . ' days');

        return $this->fetch_slots_from_api(
            $start_date->format('c'),
            $end_date->format('c'),
            null, // Default duration
            null  // User timezone will be detected client-side
        );
    }

    /**
     * Fetch live slots before concrete slot selection.
     * 
     * Called when user expresses concrete time interest.
     * Respects user's requested date/time if provided.
     */
    public function fetch_live_slots(
        ?string $requested_date = null,
        ?int $duration_minutes = null,
        ?string $timezone = null
    ): array {
        $start_date = new DateTime('now', new DateTimeZone('UTC'));
        
        // If user provided a date, prioritize it
        if ($requested_date !== null) {
            try {
                $start_date = new DateTime($requested_date, new DateTimeZone('UTC'));
            } catch (Exception $e) {
                // Fall back to today
            }
        }

        $end_date = clone $start_date;
        $end_date->modify('+7 days'); // Look ahead 7 days from requested date

        return $this->fetch_slots_from_api(
            $start_date->format('c'),
            $end_date->format('c'),
            $duration_minutes ?? 60,
            $timezone
        );
    }

    /**
     * Match slots against user's time request.
     * 
     * Spec:
     * - Exact match required for confirmation
     * - If no exact match: offer 3 nearest alternatives (same day preferred, closest to time)
     */
    public function match_slots(
        array $available_slots,
        string $requested_date,
        string $requested_time
    ): array {
        if (empty($available_slots)) {
            return [
                'exact_match' => null,
                'alternatives' => [],
                'match_type' => 'no_slots',
            ];
        }

        $request_datetime = $this->parse_user_datetime($requested_date, $requested_time);
        if ($request_datetime === null) {
            return [
                'exact_match' => null,
                'alternatives' => [],
                'match_type' => 'invalid_request',
            ];
        }

        // Search for exact match (with 15-minute tolerance)
        $exact_match = null;
        $tolerance_minutes = 15;

        foreach ($available_slots as $slot) {
            $slot_time = $this->parse_slot_datetime($slot);
            if ($slot_time === null) {
                continue;
            }

            $diff = abs($slot_time->getTimestamp() - $request_datetime->getTimestamp());
            if ($diff <= ($tolerance_minutes * 60)) {
                $exact_match = $slot;
                break;
            }
        }

        if ($exact_match !== null) {
            return [
                'exact_match' => $exact_match,
                'alternatives' => [],
                'match_type' => 'exact',
            ];
        }

        // No exact match: find 3 nearest alternatives (same day preferred)
        $alternatives = $this->find_nearest_slots(
            $available_slots,
            $request_datetime,
            self::MAX_ALTERNATIVE_SLOTS
        );

        return [
            'exact_match' => null,
            'alternatives' => $alternatives,
            'match_type' => 'alternatives',
        ];
    }

    /**
     * Find nearest slots to requested datetime.
     * 
     * Prioritizes same day, then closest to requested time.
     */
    private function find_nearest_slots(
        array $slots,
        DateTime $request_datetime,
        int $limit = 3
    ): array {
        $request_date = $request_datetime->format('Y-m-d');
        $request_timestamp = $request_datetime->getTimestamp();

        $same_day_slots = [];
        $other_slots = [];

        foreach ($slots as $slot) {
            $slot_time = $this->parse_slot_datetime($slot);
            if ($slot_time === null) {
                continue;
            }

            $slot_date = $slot_time->format('Y-m-d');
            $distance = abs($slot_time->getTimestamp() - $request_timestamp);

            if ($slot_date === $request_date) {
                $same_day_slots[] = ['slot' => $slot, 'distance' => $distance];
            } else {
                $other_slots[] = ['slot' => $slot, 'distance' => $distance];
            }
        }

        // Sort by distance
        usort($same_day_slots, function ($a, $b) {
            return $a['distance'] <=> $b['distance'];
        });
        usort($other_slots, function ($a, $b) {
            return $a['distance'] <=> $b['distance'];
        });

        // Combine: same day first, then others
        $sorted = array_merge($same_day_slots, $other_slots);
        $result = [];

        foreach (array_slice($sorted, 0, $limit) as $item) {
            $result[] = $item['slot'];
        }

        return $result;
    }

    /**
     * Fetch slots from booking plugin API.
     * 
     * Calls `/v1/slots/search` with start_iso, end_iso, duration_minutes, timezone.
     */
    private function fetch_slots_from_api(
        string $start_iso,
        string $end_iso,
        ?int $duration_minutes = null,
        ?string $timezone = null
    ): array {
        // Check if booking plugin API is available
        if (!function_exists('rest_get_url_prefix')) {
            return [];
        }

        $api_url = rest_url('restatify_booking/v1/slots/search');
        
        $args = [
            'body' => json_encode([
                'start_iso' => $start_iso,
                'end_iso' => $end_iso,
                'duration_minutes' => $duration_minutes ?? 60,
                'timezone' => $timezone ?? 'UTC',
            ]),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 10,
        ];

        $response = wp_remote_post($api_url, $args);

        if (is_wp_error($response)) {
            return [];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return [];
        }

        $slots = $data['slots'] ?? [];

        // Limit to MAX_SLOTS_PER_RESPONSE for user display
        return array_slice($slots, 0, self::MAX_SLOTS_PER_RESPONSE);
    }

    /**
     * Parse user's datetime from date and time strings.
     * 
     * Handles various formats: "morgen 14:30", "Montag", "2.5.", etc.
     */
    private function parse_user_datetime(string $date_str, string $time_str): ?DateTime {
        try {
            $date_str = trim(strtolower($date_str));
            $time_str = trim($time_str);

            // Handle relative dates
            if ($date_str === 'heute') {
                $date = new DateTime('now', new DateTimeZone('UTC'));
            } elseif ($date_str === 'morgen') {
                $date = new DateTime('tomorrow', new DateTimeZone('UTC'));
            } elseif ($date_str === 'übermorgen' || $date_str === 'uebermorgen') {
                $date = new DateTime('+2 days', new DateTimeZone('UTC'));
            } else {
                // Try to parse absolute date
                $date = new DateTime($date_str, new DateTimeZone('UTC'));
            }

            // Parse time
            if (preg_match('/(\d{1,2})[:\.](\d{2})/', $time_str, $matches)) {
                $hour = intval($matches[1]);
                $minute = intval($matches[2]);
                $date->setTime($hour, $minute, 0);
            } else {
                // No time provided, use 09:00
                $date->setTime(9, 0, 0);
            }

            return $date;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Parse slot datetime from slot object.
     */
    private function parse_slot_datetime(array $slot): ?DateTime {
        try {
            $start = $slot['start_iso'] ?? $slot['start'] ?? null;
            if ($start === null) {
                return null;
            }

            return new DateTime($start, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Format slot for user display (human-readable).
     */
    public function format_slot_for_display(array $slot): string {
        try {
            $start = new DateTime($slot['start_iso'] ?? $slot['start'], new DateTimeZone('UTC'));
            $label = $slot['label'] ?? '';

            if ($label !== '') {
                return $label;
            }

            return $start->format('d.m.Y \u\m H:i \U\h\r');
        } catch (Exception $e) {
            return '';
        }
    }
}
