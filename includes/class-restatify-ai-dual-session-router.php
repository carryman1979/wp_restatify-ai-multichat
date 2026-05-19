<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-restatify-ai-ui-string-store.php';

/**
 * Restatify_Ai_Dual_Session_Router
 *
 * Routes every visitor message to Session1 (booking) or Session2 (general AI).
 *
 * Key responsibilities:
 *  - Normalize conversation format (runtime passes $conversation['messages'], tests pass flat arrays)
 *  - Calculate booking-intent confidence from message + conversation context
 *  - Fast-track to Session1 when intent is clear
 *  - Suppress re-routing after explicit rejection
 *  - Force conversion to Session1 after 20 turns
 *  - Return delegate_to_session2_ai:true so runtime lets Gemini handle Session2 messages
 */
class Restatify_Ai_Dual_Session_Router {

    const CONFIDENCE_AUTO_BOOKING             = 0.98;
    const CONFIDENCE_CLARIFY_MIN              = 0.30;
    const CONFIDENCE_CLARIFY_MAX              = 0.97;
    const MAX_CLARIFICATION_ATTEMPTS          = 2;
    const MAX_CUSTOMER_TURNS_BEFORE_CONVERSION = 20;
    const BOOKING_REJECTION_SUPPRESS_TURNS    = 2;
    const SESSION1_MAX_QUESTIONS              = 10;

    /** @var Restatify_Ai_Dual_Session_State_Machine */
    public $state_machine;
    /** @var Restatify_Ai_Dual_Session_Slot_Manager */
    public $slot_manager;
    /** @var array */
    private $options;

    public function __construct( $options = [] ) {
        $this->options      = is_array( $options ) ? $options : [];
        $this->state_machine = new Restatify_Ai_Dual_Session_State_Machine();
        $this->slot_manager  = new Restatify_Ai_Dual_Session_Slot_Manager();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Route one visitor message and return a routing result array.
     *
     * @param string $latest_message  The visitor's latest message.
     * @param array  $conversation    Either a flat turns array OR a runtime conversation
     *                                object with ['id' => ..., 'messages' => [...]].
     * @param string $session_id      Stable session identifier (use conversation id).
     * @param string $ip_address      Visitor IP for cooldown checks.
     * @return array  Routing result.
     */
    public function route_message(
        string $latest_message,
        array  $conversation = [],
        string $session_id   = '',
        string $ip_address   = ''
    ): array {
        $latest_message = trim( $latest_message );
        if ( $latest_message === '' ) {
            return $this->build_error_response( 'Message is empty' );
        }

        $session_id = $session_id !== '' ? $session_id : wp_hash( time() . wp_rand() );
        $ip_address = $ip_address !== '' ? $ip_address : $this->get_client_ip();

        // Normalise conversation to a flat turns array regardless of source format.
        $turns = $this->extract_message_turns( $conversation );

        // Load persisted state.
        $state          = $this->state_machine->get_session_state( $session_id );
        $customer_turns = ( $state['customer_turns'] ?? 0 ) + 1;
        $state['customer_turns'] = $customer_turns;
        $language_code = $this->resolve_conversation_language_code( $latest_message, $turns, $state );
        $state['language_code'] = $language_code;

        // ── Sticky Session1 ──────────────────────────────────────────────────
        if ( ( $state['current_session'] ?? '' ) === 'session1' && ! empty( $state['booking_flow_active'] ) ) {
            $this->state_machine->update_session_state( $session_id, $state );
            return $this->build_session1_response( $latest_message, $turns, $session_id, $state, $language_code );
        }

        // ── Rejection suppression (cool-down after explicit "no") ────────────
        if ( ( $state['rejection_suppression'] ?? 0 ) > 0 ) {
            $state['rejection_suppression'] = max( 0, $state['rejection_suppression'] - 1 );
            $this->state_machine->update_session_state( $session_id, $state );
            return $this->build_session2_response();
        }

        // ── Explicit rejection ───────────────────────────────────────────────
        if ( $this->is_explicit_booking_rejection( $latest_message, $turns ) ) {
            $state['current_session']      = 'session2';
            $state['rejection_suppression'] = self::BOOKING_REJECTION_SUPPRESS_TURNS;
            $this->state_machine->update_session_state( $session_id, $state );
            return [
                'session'               => 'session2',
                'action'                => 'routing_session2',
                'delegate_to_session2_ai' => true,
                'user_facing_text'      => '',
                'confidence'            => 0.0,
            ];
        }

        // ── Calculate confidence ─────────────────────────────────────────────
        $confidence          = $this->calculate_booking_confidence( $latest_message, $turns, $language_code );
        $state['confidence'] = $confidence;

        // ── Route to Session1 at high confidence ─────────────────────────────
        if ( $confidence >= self::CONFIDENCE_AUTO_BOOKING ) {
            $state['current_session']   = 'session1';
            $state['booking_flow_active'] = true;
            $this->state_machine->update_session_state( $session_id, $state );

            if ( class_exists( 'Restatify_Ai_Session1_Debug_Logger', false ) ) {
                Restatify_Ai_Session1_Debug_Logger::log_session1_entry(
                    $session_id,
                    $latest_message,
                    'confidence_threshold',
                    [ 'confidence' => $confidence ]
                );
            }
            return $this->build_session1_response( $latest_message, $turns, $session_id, $state, $language_code );
        }

        // ── Clarification band ───────────────────────────────────────────────
        if ( $confidence >= self::CONFIDENCE_CLARIFY_MIN ) {
            $clarify_count = $state['clarification_attempts'] ?? 0;
            if ( $clarify_count < self::MAX_CLARIFICATION_ATTEMPTS ) {
                $state['clarification_attempts'] = $clarify_count + 1;
                $this->state_machine->update_session_state( $session_id, $state );
                return [
                    'session'               => 'clarification',
                    'action'                => 'ask_clarification',
                    'confidence'            => $confidence,
                    'user_facing_text'      => $this->get_clarification_text( $language_code ),
                    'delegate_to_session2_ai' => false,
                ];
            }
            // Max clarifications exhausted → push to Session1.
            $state['current_session']   = 'session1';
            $state['booking_flow_active'] = true;
            $this->state_machine->update_session_state( $session_id, $state );
            return $this->build_session1_response( $latest_message, $turns, $session_id, $state, $language_code );
        }

        // ── Forced conversion after 20 turns ─────────────────────────────────
        if ( $customer_turns >= self::MAX_CUSTOMER_TURNS_BEFORE_CONVERSION ) {
            $state['current_session']   = 'session1';
            $state['booking_flow_active'] = true;
            $this->state_machine->update_session_state( $session_id, $state );
            return [
                'action'                => 'forced_conversion',
                'session'               => 'session1',
                'confidence'            => $confidence,
                'user_facing_text'      => '',
                'force_overlay'         => true,
                'partial_prefill'       => [],
            ];
        }

        // ── Default: Session2 (delegate to AI) ───────────────────────────────
        $state['current_session'] = 'session2';
        $this->state_machine->update_session_state( $session_id, $state );
        return $this->build_session2_response();
    }

    // -------------------------------------------------------------------------
    // Confidence scoring
    // -------------------------------------------------------------------------

    /**
     * Score booking intent (0.0–1.0) from the latest message and conversation context.
     *
     * Rules are additive; the result is capped at 1.0.
     * A scheduling context in the AI's recent turns acts as a multiplier.
     */
    private function calculate_booking_confidence( string $message, array $turns, string $language_code = 'de' ): float {
        $msg     = mb_strtolower( $message );
        $score   = 0.0;
        $signals = $this->get_intent_signals_for_language( $language_code );
        $ctx     = $this->conversation_has_scheduling_context( $turns, $signals );

        // --- Pure contact info (phone / email) ---
        $has_phone = (bool) preg_match( '/\+?\d[\d\s\-\(\)]{8,}/', $message );
        $has_email = (bool) preg_match( '/[\w.\-]+@[\w.\-]+\.[a-z]{2,}/iu', $message );
        if ( $has_phone || $has_email ) {
            $score += $ctx ? self::CONFIDENCE_AUTO_BOOKING : 0.30;
        }

        // --- Explicit short affirmation ---
        $affirmations = $signals['affirmation'];
        foreach ( $affirmations as $a ) {
            if ( $msg === $a || mb_strpos( $msg, $a . ' ' ) === 0 || mb_strpos( $msg, $a . ',' ) === 0 ) {
                $score += $ctx ? self::CONFIDENCE_AUTO_BOOKING : 0.20;
                break;
            }
        }

        // --- Direct booking/scheduling keywords in user message ---
        if ( $this->contains_any_phrase( $msg, $signals['booking'] ) ) {
            $score += self::CONFIDENCE_AUTO_BOOKING; // direct intent → auto-route
        }

        // --- Specific clock time ---
        if ( preg_match( '/\d{1,2}\s*uhr|\d{1,2}:\d{2}|\d{1,2}\s*o\'clock|\d{1,2}\s*(a\.?m\.?|p\.?m\.?)?/iu', $message ) ) {
            $score += $ctx ? 0.60 : 0.20;
        }

        // --- Date / day-of-week reference ---
        if ( $this->contains_any_phrase( $msg, $signals['date'] ) ) {
            $score += $ctx ? 0.50 : 0.15;
        }

        // --- Time-of-day or call reference ---
        if ( $this->contains_any_phrase( $msg, $signals['time_of_day'] ) || $this->contains_any_phrase( $msg, $signals['scheduling_context'] ) ) {
            $score += $ctx ? 0.50 : 0.20;
        }

        // --- Question about timing after scheduling context ---
        if ( $ctx && $this->starts_with_any_phrase( $msg, $signals['timing_question'] ) ) {
            $score += 0.40;
        }

        // --- Negative signals: decay score ---
        if ( $this->contains_any_phrase( $msg, $signals['rejection'] ) ) {
            $score *= 0.15;
        }

        return min( 1.0, $score );
    }

    // -------------------------------------------------------------------------
    // Conversation helpers
    // -------------------------------------------------------------------------

    /**
     * Normalize conversation to a flat array of turn objects.
     *
     * Handles three formats:
     *  1. Runtime format: $conversation['messages'] = [['sender'=>'ai','message'=>'...'], ...]
     *  2. Test flat format: $conversation = [['sender'=>'ai','message'=>'...'], ...]
     *  3. OpenAI role format: $conversation = [['role'=>'assistant','content'=>'...'], ...]
     */
    private function extract_message_turns( array $conversation ): array {
        if ( isset( $conversation['messages'] ) && is_array( $conversation['messages'] ) ) {
            return $conversation['messages'];
        }
        if ( ! empty( $conversation ) && isset( $conversation[0] ) && is_array( $conversation[0] ) ) {
            return $conversation;
        }
        return [];
    }

    /**
     * Return true if any recent AI turn contains scheduling-related language.
     * Checks both sender/message AND role/content conversation formats.
     */
    private function conversation_has_scheduling_context( array $turns, array $signals = [] ): bool {
        $terms = $signals['scheduling_context'] ?? [
            'termin', 'call', 'zeitraum', 'wann', 'telefonieren', 'anrufen', 'besprechung', 'meeting',
            'gespraech', 'erreichbar', 'verfuegbar', 'passen', 'vereinbaren', 'callback', 'rueckruf',
            'appointment', 'book', 'booking', 'schedule', 'time', 'slot',
        ];

        foreach ( array_reverse( $turns ) as $turn ) {
            $sender = mb_strtolower( (string) ( $turn['sender'] ?? $turn['role'] ?? '' ) );
            $text   = mb_strtolower( (string) ( $turn['message'] ?? $turn['content'] ?? '' ) );

            if ( in_array( $sender, [ 'ai', 'assistant', 'model' ], true ) ) {
                if ( $this->contains_any_phrase( $text, $terms ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Detect explicit rejection of a booking offer.
     * Only treated as rejection when there is a scheduling context in the AI turns.
     */
    private function is_explicit_booking_rejection( string $message, array $turns ): bool {
        $msg = mb_strtolower( $message );
        $rejection_terms = [
            'nein', 'nicht', 'kein', 'keine', 'noe', 'gar nicht', 'lieber nicht', 'im moment nicht', 'jetzt nicht',
            'no', 'not now', 'do not', "don't", 'no thanks', 'later', 'not interested',
        ];
        if ( ! $this->contains_any_phrase( $msg, $rejection_terms ) ) {
            return false;
        }
        return $this->conversation_has_scheduling_context( $turns );
    }

    /**
     * Explicit booking acceptance helper (accessed via reflection in unit tests).
     */
    private function is_explicit_booking_acceptance( string $message, array $conversation, string $language ): bool {
        $turns       = $this->extract_message_turns( $conversation );
        $affirmations = [
            'ja', 'gerne', 'okay', 'ok', 'klar', 'natuerlich', 'auf jeden fall', 'passt', 'machen wir', 'klingt gut', 'sehr gerne', 'einverstanden',
            'yes', 'sure', 'sounds good', 'perfect', 'works for me', 'agreed',
        ];
        $msg         = mb_strtolower( trim( $message ) );

        foreach ( $affirmations as $a ) {
            if ( $msg === $a || mb_strpos( $msg, $a . ' ' ) === 0 || mb_strpos( $msg, $a . ',' ) === 0 ) {
                if ( $this->conversation_has_scheduling_context( $turns ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Session1 response builder
    // -------------------------------------------------------------------------

    /**
     * Build a Session1 response: collect missing booking data first, then open the overlay.
     */
    private function build_session1_response( string $message, array $turns, string $session_id, array $state, string $language_code = 'de' ): array {
        $prefill   = $this->extract_prefill_from_context( $message, $turns, $state, $language_code );
        $collected = array_merge( $state['collected_fields'] ?? [], $prefill );
        $collected = $this->finalize_booking_prefill( $collected, $turns, $message );
        $missing_fields = $this->get_missing_required_booking_fields( $collected );
        $question_count = (int) ( $state['booking_attempt_count'] ?? 0 );

        $state['collected_fields'] = $collected;
        $state['partial_prefill']  = $collected;

        if ( ! empty( $missing_fields ) && $question_count < self::SESSION1_MAX_QUESTIONS ) {
            $question = $this->build_session1_question( $missing_fields, $collected, $language_code, $turns, $message, $state );
            $state['booking_attempt_count'] = $question_count + 1;
            $state['last_session1_field'] = (string) ( $missing_fields[0] ?? '' );
            $state['last_session1_question'] = $question;
            $this->state_machine->update_session_state( $session_id, $state );

            if ( class_exists( 'Restatify_Ai_Session1_Debug_Logger', false ) ) {
                Restatify_Ai_Session1_Debug_Logger::log_session1_extraction( $session_id, $message, $collected, $missing_fields );
                Restatify_Ai_Session1_Debug_Logger::log_session1_question( $session_id, (string) $missing_fields[0], $question );
            }

            return [
                'session'                 => 'session1',
                'action'                  => 'collect_data',
                'confidence'              => $state['confidence'] ?? 0.0,
                'user_facing_text'        => $question,
                'booking_payload'         => null,
                'force_overlay'           => false,
                'partial_prefill'         => $collected,
                'delegate_to_session2_ai' => false,
            ];
        }

        $state['last_session1_field'] = '';
        $state['last_session1_question'] = '';

        $this->state_machine->update_session_state( $session_id, $state );

        if ( class_exists( 'Restatify_Ai_Session1_Debug_Logger', false ) ) {
            Restatify_Ai_Session1_Debug_Logger::log_session1_extraction( $session_id, $message, $collected, $missing_fields );
            Restatify_Ai_Session1_Debug_Logger::log_session1_confirmation( $session_id, $collected );
        }

        $open_text = $this->get_open_overlay_text( $language_code, empty( $missing_fields ) );

        return [
            'session'                 => 'session1',
            'action'                  => 'open_booking_overlay',
            'confidence'              => $state['confidence'] ?? 0.0,
            'user_facing_text'        => $open_text,
            'booking_payload'         => ! empty( $collected ) ? $collected : null,
            'force_overlay'           => true,
            'partial_prefill'         => $collected,
            'delegate_to_session2_ai' => false,
        ];
    }

    /**
     * Extract prefill data (name, contact, date, time) from the full conversation.
     */
    private function extract_prefill_from_context( string $latest_message, array $turns, array $state = [], string $language_code = 'de' ): array {
        $transcript = $this->build_prefill_transcript( $turns, $latest_message );
        if ( $transcript === '' ) {
            return [];
        }

        $required_order = $this->get_required_booking_fields_order();
        $current_collected = is_array( $state['collected_fields'] ?? null ) ? (array) $state['collected_fields'] : [];
        $current_missing = $this->get_missing_required_booking_fields( $current_collected );

        $llm_payload = $this->extract_prefill_via_llm(
            $transcript,
            (string) ( $state['last_session1_field'] ?? ( $current_missing[0] ?? '' ) ),
            (string) ( $state['last_session1_question'] ?? '' ),
            $language_code,
            $required_order
        );

        return $this->normalize_extracted_prefill_payload( $llm_payload );
    }

    /**
     * @return array<string,string>
     */
    private function finalize_booking_prefill( array $collected, array $turns, string $latest_message ): array {
        if ( empty( $collected['contact_method'] ) ) {
            if ( ! empty( $collected['email'] ) ) {
                $collected['contact_method'] = 'email';
            } elseif ( ! empty( $collected['phone'] ) ) {
                $collected['contact_method'] = 'phone';
            }
        }

        if ( empty( $collected['contact_value'] ) ) {
            if ( ! empty( $collected['email'] ) ) {
                $collected['contact_value'] = (string) $collected['email'];
            } elseif ( ! empty( $collected['phone'] ) ) {
                $collected['contact_value'] = (string) $collected['phone'];
            }
        }

        if ( empty( $collected['subject'] ) ) {
            $core_fields_present = ! empty( $collected['name'] ) && ! empty( $collected['contact_value'] );
            if ( $core_fields_present ) {
                $collected['subject'] = 'Unverbindliches Kennenlerngespraech';
            }
        }

        if ( empty( $collected['note'] ) ) {
            $core_fields_present = ! empty( $collected['name'] ) && ! empty( $collected['contact_value'] );
            if ( $core_fields_present ) {
                $collected['note'] = 'Anfrage aus Chat-Kontext: Bitte mit dem Kunden die Anforderungen im Erstgespraech konkretisieren.';
            }
        }

        return array_filter( $collected, static function ( $value ): bool {
            return !( is_string( $value ) && trim( $value ) === '' );
        } );
    }

    /**
     * @return array<int,string>
     */
    private function get_missing_required_booking_fields( array $collected ): array {
        // subject is auto-inferred from context once name+contact are present – not asked explicitly.
        $required = [ 'name', 'email', 'contact_value' ];
        $missing  = [];

        foreach ( $required as $field ) {
            if ( empty( $collected[ $field ] ) ) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param array<int,string> $missing_fields
     */
    private function build_session1_question( array $missing_fields, array $collected, string $language_code = 'de', array $turns = [], string $latest_message = '', array $state = [] ): string {
        $field = (string) ( $missing_fields[0] ?? 'contact_value' );

        $llm_question = $this->build_session1_question_via_llm( $field, $collected, $turns, $latest_message, $language_code, $state );
        if ( $llm_question !== '' ) {
            return $llm_question;
        }

        if ( $field === 'contact_value' && ! empty( $collected['email'] ) ) {
            return Restatify_Ai_Ui_String_Store::get( 'question.contact_extra', $language_code, $this->options );
        }

        $key = in_array( $field, [ 'name', 'email', 'subject', 'contact_value' ], true )
            ? 'question.' . $field
            : 'question.default';

        return Restatify_Ai_Ui_String_Store::get( $key, $language_code, $this->options );
    }

    private function get_open_overlay_text( string $language_code, bool $all_fields_present ): string {
        $key = $all_fields_present ? 'open_overlay.complete' : 'open_overlay.incomplete';
        return Restatify_Ai_Ui_String_Store::get( $key, $language_code, $this->options );
    }

    /**
     * @return array<int,string>
     */
    private function get_required_booking_fields_order(): array {
        return [ 'name', 'email', 'contact_value' ];
    }

    private function build_prefill_transcript( array $turns, string $latest_message ): string {
        $lines = [];

        foreach ( $turns as $turn ) {
            if ( ! is_array( $turn ) ) {
                continue;
            }

            $raw_sender = mb_strtolower( (string) ( $turn['sender'] ?? $turn['role'] ?? '' ) );
            $text = trim( (string) ( $turn['message'] ?? $turn['content'] ?? '' ) );
            if ( $text === '' ) {
                continue;
            }

            $speaker = in_array( $raw_sender, [ 'ai', 'assistant', 'model' ], true ) ? 'assistant' : 'visitor';
            $lines[] = $speaker . ': ' . $text;
        }

        $latest = trim( $latest_message );
        if ( $latest !== '' ) {
            $lines[] = 'visitor: ' . $latest;
        }

        return trim( implode( "\n", $lines ) );
    }

    /**
     * @param array<int,string> $required_order
     * @return array<string,mixed>
     */
    private function extract_prefill_via_llm( string $transcript, string $last_requested_field, string $last_question, string $language_code, array $required_order ): array {
        $prompt_parts = [];
        $prompt_parts[] = 'You extract structured booking data from a multilingual chat transcript.';
        $prompt_parts[] = 'Return strict JSON only (single object). No prose, no markdown.';
        $prompt_parts[] = 'If a value is unknown, use an empty string.';
        $prompt_parts[] = 'Date must be YYYY-MM-DD and time must be HH:MM in 24h format when known.';
        $prompt_parts[] = 'Use the exact required field order when reasoning about completeness: ' . implode( ', ', $required_order ) . '.';
        $prompt_parts[] = 'Prefer facts explicitly provided by the visitor in the transcript.';
        if ( $last_requested_field !== '' ) {
            $prompt_parts[] = 'Last requested field: ' . $last_requested_field . '.';
        }
        if ( $last_question !== '' ) {
            $prompt_parts[] = 'Last assistant question: ' . $last_question;
        }
        $prompt_parts[] = 'Conversation language hint: ' . $this->normalize_language_code( $language_code ) . '.';
        $prompt_parts[] = 'JSON schema keys:';
        $prompt_parts[] = '{"name":"","email":"","phone":"","contact_method":"","contact_value":"","date":"","time":"","time_of_day":"","subject":"","note":"","chat_purpose_title":"","chat_request_summary":""}';
        $prompt_parts[] = 'Transcript:';
        $prompt_parts[] = $transcript;

        $response = $this->run_structured_json_llm_task( implode( "\n", $prompt_parts ) );
        return is_array( $response ) ? $response : [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    private function normalize_extracted_prefill_payload( array $payload ): array {
        $result = [];
        $string_keys = [
            'name', 'email', 'phone', 'contact_method', 'contact_value',
            'date', 'time', 'time_of_day', 'subject', 'note',
            'chat_purpose_title', 'chat_request_summary',
        ];

        foreach ( $string_keys as $key ) {
            $value = $payload[ $key ] ?? '';
            if ( ! is_scalar( $value ) ) {
                continue;
            }

            $clean = trim( (string) $value );
            if ( $clean !== '' ) {
                $result[ $key ] = $clean;
            }
        }

        if ( ! empty( $result['email'] ) && function_exists( 'sanitize_email' ) ) {
            $result['email'] = sanitize_email( (string) $result['email'] );
            if ( $result['email'] === '' ) {
                unset( $result['email'] );
            }
        }

        if ( ! empty( $result['phone'] ) ) {
            $result['phone'] = trim( str_replace( [ ' ', '-', '(', ')' ], '', (string) $result['phone'] ) );
            if ( $result['phone'] === '' ) {
                unset( $result['phone'] );
            }
        }

        if ( ! empty( $result['date'] ) ) {
            $date = \DateTime::createFromFormat( 'Y-m-d', (string) $result['date'] );
            if ( ! ( $date instanceof \DateTime ) || $date->format( 'Y-m-d' ) !== (string) $result['date'] ) {
                unset( $result['date'] );
            }
        }

        if ( ! empty( $result['time'] ) ) {
            $time = \DateTime::createFromFormat( 'H:i', (string) $result['time'] );
            if ( ! ( $time instanceof \DateTime ) || $time->format( 'H:i' ) !== (string) $result['time'] ) {
                unset( $result['time'] );
            }
        }

        if ( ! empty( $result['contact_method'] ) ) {
            $method = strtolower( (string) $result['contact_method'] );
            if ( ! in_array( $method, [ 'email', 'phone', 'zoom', 'teams', 'whatsapp' ], true ) ) {
                unset( $result['contact_method'] );
            } else {
                $result['contact_method'] = $method;
            }
        }

        if ( empty( $result['subject'] ) && ! empty( $result['chat_purpose_title'] ) ) {
            $result['subject'] = (string) $result['chat_purpose_title'];
        }

        if ( empty( $result['note'] ) && ! empty( $result['chat_request_summary'] ) ) {
            $result['note'] = (string) $result['chat_request_summary'];
        }

        if ( empty( $result['contact_method'] ) ) {
            if ( ! empty( $result['email'] ) ) {
                $result['contact_method'] = 'email';
            } elseif ( ! empty( $result['phone'] ) ) {
                $result['contact_method'] = 'phone';
            }
        }

        if ( empty( $result['contact_value'] ) ) {
            if ( ! empty( $result['email'] ) ) {
                $result['contact_value'] = (string) $result['email'];
            } elseif ( ! empty( $result['phone'] ) ) {
                $result['contact_value'] = (string) $result['phone'];
            }
        }

        return array_filter( $result, static function ( $value ): bool {
            return !( is_string( $value ) && trim( $value ) === '' );
        } );
    }

    private function build_session1_question_via_llm( string $target_field, array $collected, array $turns, string $latest_message, string $language_code, array $state ): string {
        $transcript = $this->build_prefill_transcript( $turns, $latest_message );
        if ( $transcript === '' || $target_field === '' ) {
            return '';
        }

        $prompt_parts = [];
        $prompt_parts[] = 'Write exactly one short follow-up question for a booking assistant.';
        $prompt_parts[] = 'Question language must match: ' . $this->normalize_language_code( $language_code ) . '.';
        $prompt_parts[] = 'Target missing field: ' . $target_field . '.';
        $prompt_parts[] = 'Known collected fields JSON: ' . wp_json_encode( $collected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! empty( $state['last_session1_question'] ) ) {
            $prompt_parts[] = 'Avoid repeating this exact previous question: ' . (string) $state['last_session1_question'];
        }
        $prompt_parts[] = 'Return strict JSON only: {"question":"..."}';
        $prompt_parts[] = 'Transcript:';
        $prompt_parts[] = $transcript;

        $payload = $this->run_structured_json_llm_task( implode( "\n", $prompt_parts ) );
        if ( ! is_array( $payload ) ) {
            return '';
        }

        $question = trim( (string) ( $payload['question'] ?? '' ) );
        if ( $question === '' ) {
            return '';
        }

        return $this->trim_question_sentence( $question );
    }

    /**
     * @return array<string,mixed>
     */
    private function run_structured_json_llm_task( string $prompt ): array {
        if ( ! is_array( $this->options ) ) {
            return [];
        }

        $callback = $this->options['session1_llm_json_callback'] ?? null;
        if ( is_callable( $callback ) ) {
            $result = call_user_func( $callback, $prompt, $this->options );
            return is_array( $result ) ? $result : [];
        }

        if ( ! function_exists( 'wp_remote_post' ) || ! function_exists( 'is_wp_error' ) || ! function_exists( 'wp_remote_retrieve_body' ) ) {
            return [];
        }

        $endpoint = trim( (string) ( $this->options['ai_api_endpoint'] ?? Restatify_Ai_Multichat_Plugin::DEFAULT_AI_ENDPOINT ) );
        $api_key  = trim( (string) ( $this->options['ai_api_key'] ?? '' ) );
        $model    = trim( (string) ( $this->options['ai_model'] ?? 'gpt-4o-mini' ) );

        if ( $endpoint === '' || $api_key === '' ) {
            return [];
        }

        $endpoint_lower = strtolower( $endpoint );
        $is_gemini = strpos( $endpoint_lower, 'generativelanguage.googleapis.com' ) !== false || strpos( $endpoint_lower, 'gemini' ) !== false;

        if ( $is_gemini ) {
            $url = $endpoint;
            if ( strpos( $url, 'key=' ) === false ) {
                $sep = strpos( $url, '?' ) === false ? '?' : '&';
                $url .= $sep . 'key=' . rawurlencode( $api_key );
            }

            $body = [
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => [ [ 'text' => $prompt ] ],
                    ],
                ],
                'generationConfig' => [
                    'temperature'     => 0.1,
                    'maxOutputTokens' => 700,
                ],
            ];

            $response = wp_remote_post(
                $url,
                [
                    'timeout' => 25,
                    'headers' => [ 'Content-Type' => 'application/json' ],
                    'body'    => wp_json_encode( $body ),
                ]
            );

            if ( is_wp_error( $response ) ) {
                return [];
            }

            $decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
            $text = (string) ( $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '' );
            return $this->extract_first_json_object( $text );
        }

        $payload = [
            'model' => $model !== '' ? $model : 'gpt-4o-mini',
            'messages' => [
                [ 'role' => 'system', 'content' => 'Return strict JSON only.' ],
                [ 'role' => 'user', 'content' => $prompt ],
            ],
            'temperature' => 0.1,
        ];

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout' => 25,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body' => wp_json_encode( $payload ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $text = (string) ( $decoded['choices'][0]['message']['content'] ?? '' );
        return $this->extract_first_json_object( $text );
    }

    /**
     * @return array<string,mixed>
     */
    private function extract_first_json_object( string $text ): array {
        $candidate = trim( $text );
        $start = strpos( $candidate, '{' );
        $end = strrpos( $candidate, '}' );

        if ( $start === false || $end === false || $end < $start ) {
            return [];
        }

        $json = substr( $candidate, $start, ( $end - $start ) + 1 );
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private function trim_question_sentence( string $question ): string {
        $clean = trim( str_replace( [ "\r", "\n" ], ' ', $question ) );
        if ( $clean === '' ) {
            return '';
        }

        if ( strlen( $clean ) > 280 ) {
            $clean = substr( $clean, 0, 277 ) . '...';
        }

        return $clean;
    }

    // -------------------------------------------------------------------------
    // Response builders
    // -------------------------------------------------------------------------

    private function build_session2_response( string $reason = 'routing_session2' ): array {
        return [
            'session'               => 'session2',
            'action'                => $reason,
            'delegate_to_session2_ai' => true,
            'user_facing_text'      => '',
            'confidence'            => 0.0,
        ];
    }

    private function build_error_response( string $msg ): array {
        return [
            'action'                => 'error',
            'session'               => 'session2',
            'delegate_to_session2_ai' => true,
            'user_facing_text'      => '',
            'message'               => $msg,
        ];
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    private function get_client_ip(): string {
        return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ) );
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function get_intent_signals_for_language( string $language_code ): array {
        $lang = $this->normalize_language_code( $language_code );

        if ( class_exists( 'Restatify_Ai_Language_Keyword_Store', false ) ) {
            $set = Restatify_Ai_Language_Keyword_Store::keyword_set_for_language( $lang, $this->options );
            if ( is_array( $set ) && count( $set ) > 0 ) {
                return $set;
            }
        }

        return [
            'booking' => [ 'termin', 'buchen', 'buchung', 'reservierung', 'vereinbaren', 'appointment', 'book', 'booking', 'reserve', 'schedule', 'meeting', 'call', 'slot' ],
            'affirmation' => [ 'ja', 'gerne', 'okay', 'ok', 'klar', 'passt', 'yes', 'sure', 'sounds good', 'perfect', 'works for me' ],
            'rejection' => [ 'nein', 'nicht', 'kein', 'keine', 'no', 'not now', 'do not', "don't", 'no thanks', 'later' ],
            'scheduling_context' => [ 'termin', 'meeting', 'call', 'appointment', 'book', 'booking', 'schedule', 'time', 'slot', 'verfuegbar', 'available', 'availability', 'wann', 'tomorrow' ],
            'date' => [ 'morgen', 'uebermorgen', 'heute', 'naechste woche', 'montag', 'dienstag', 'mittwoch', 'donnerstag', 'freitag', 'samstag', 'sonntag', 'today', 'tomorrow', 'next week', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ],
            'time_of_day' => [ 'nachmittag', 'nachmittags', 'vormittag', 'vormittags', 'morgens', 'abends', 'mittags', 'morning', 'afternoon', 'evening', 'noon' ],
            'timing_question' => [ 'wann', 'wann genau', 'um wie viel', 'welche uhrzeit', 'welcher tag', 'wie viel uhr', 'when', 'what time', 'which day', 'how about', 'around' ],
        ];
    }

    private function normalize_language_code( string $language_code ): string {
        $value = strtolower( trim( $language_code ) );
        if ( $value === '' ) {
            return 'de';
        }

        if ( preg_match( '/^[a-z]{2,3}/', $value, $m ) !== 1 ) {
            return 'de';
        }

        return (string) $m[0];
    }

    private function resolve_conversation_language_code( string $latest_message, array $turns, array &$state ): string {
        $detected = $this->detect_language_code( $latest_message, $turns );
        $current = $this->normalize_language_code( (string) ( $state['language_code'] ?? '' ) );

        if ( $current === '' || $current === 'en' && empty( $state['language_code'] ) ) {
            $state['language_code'] = $detected;
            $state['language_switch_votes'] = 0;
            return $detected;
        }

        if ( $detected === $current ) {
            $state['language_switch_votes'] = 0;
            return $current;
        }

        $votes = (int) ( $state['language_switch_votes'] ?? 0 ) + 1;
        if ( $votes >= 2 ) {
            $state['language_code'] = $detected;
            $state['language_switch_votes'] = 0;
            return $detected;
        }

        $state['language_switch_votes'] = $votes;
        return $current;
    }

    private function detect_language_code( string $message, array $turns ): string {
        $text = mb_strtolower( trim( $message ) );
        if ( $text === '' ) {
            return 'de';
        }

        $de_terms = [ 'ich', 'wir', 'moechte', 'möchte', 'termin', 'morgen', 'uebermorgen', 'übermorgen', 'uhr', 'koennen', 'können', 'bitte', 'danke', 'wie', 'wann' ];
        $en_terms = [ 'hello', 'hi', 'tomorrow', 'today', 'book', 'booking', 'appointment', 'schedule', 'around', "o'clock", 'p.m', 'a.m', 'please', 'thanks', 'can we', 'how can we proceed' ];

        $de_score = 0;
        $en_score = 0;

        foreach ( $de_terms as $term ) {
            if ( mb_strpos( $text, $term ) !== false ) {
                $de_score++;
            }
        }
        foreach ( $en_terms as $term ) {
            if ( mb_strpos( $text, $term ) !== false ) {
                $en_score++;
            }
        }

        if ( preg_match( '/[äöüß]/u', $text ) ) {
            $de_score += 2;
        }
        if ( preg_match( '/\b(the|and|for|with|would|could|should)\b/u', $text ) ) {
            $en_score += 1;
        }

        if ( $de_score === $en_score && count( $turns ) > 0 ) {
            foreach ( array_reverse( $turns ) as $turn ) {
                $sender = mb_strtolower( (string) ( $turn['sender'] ?? $turn['role'] ?? '' ) );
                if ( ! in_array( $sender, [ 'visitor', 'user', 'human' ], true ) ) {
                    continue;
                }

                $msg = mb_strtolower( (string) ( $turn['message'] ?? $turn['content'] ?? '' ) );
                if ( mb_strpos( $msg, 'tomorrow' ) !== false || mb_strpos( $msg, "o'clock" ) !== false ) {
                    return 'en';
                }
                if ( mb_strpos( $msg, 'morgen' ) !== false || mb_strpos( $msg, 'uhr' ) !== false ) {
                    return 'de';
                }
            }
        }

        if ( $de_score === $en_score ) {
            return 'de';
        }

        return $de_score > $en_score ? 'de' : 'en';
    }

    /**
     * @param array<int,string> $phrases
     */
    private function contains_any_phrase( string $haystack, array $phrases ): bool {
        foreach ( $phrases as $phrase ) {
            $value = mb_strtolower( trim( (string) $phrase ) );
            if ( $value === '' ) {
                continue;
            }

            if ( mb_strpos( $value, ' ' ) !== false || strpos( $value, "'") !== false || strpos( $value, '-' ) !== false ) {
                if ( mb_strpos( $haystack, $value ) !== false ) {
                    return true;
                }
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote( $value, '/' ) . '(?![\p{L}\p{N}_])/u';
            if ( preg_match( $pattern, $haystack ) === 1 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $phrases
     */
    private function starts_with_any_phrase( string $haystack, array $phrases ): bool {
        $value = ltrim( $haystack );
        foreach ( $phrases as $phrase ) {
            $needle = mb_strtolower( trim( (string) $phrase ) );
            if ( $needle !== '' && ( $value === $needle || mb_strpos( $value, $needle . ' ' ) === 0 || mb_strpos( $value, $needle . '?' ) === 0 || mb_strpos( $value, $needle . ',' ) === 0 ) ) {
                return true;
            }
        }

        return false;
    }

    private function get_clarification_text( string $language_code ): string {
        return Restatify_Ai_Ui_String_Store::get( 'clarification', $language_code, $this->options );
    }
}
