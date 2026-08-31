<?php
/**
 * Countdown Timer Shortcode
 *
 * @wordpress
 *
 * GOAL:
 * Creates a real-time countdown timer displaying days, hours, minutes, and seconds until a
 * specified date. Uses WordPress timezone settings and validates date input.
 *
 * CONFIGURATION:
 * - CSS: Style classes .countdown-timer, .countdown-segment, .countdown-number, .countdown-label
 * - WordPress: Ensure timezone is set correctly in Settings > General
 * - Date Format: Must use dd/mm/yyyy format (e.g., 31/12/2025)
 * - Accepts an optional `end_text` to show when the timer countdown is finished.
 * - Accepts an optional `time` (in 24 hour format) attribute to configure what time of day. Useful
 *   for offsetting against timezones. Can be HH:MM or HH:MM:SS format.
 *
 * USAGE:
 * [countdown date="31/12/2025"] [countdown date="01/01/2026"]
 *
 * NOTES:
 * Supports multiple timers on same page via static counter Countdown stops at zero (doesn't go
 * negative) Uses site timezone from WordPress settings JavaScript updates every second Timer
 * starts automatically on page load Falls back gracefully with error messages for invalid dates
 */

add_shortcode(
        'countdown',
        function ( $atts ) {
            static $count = 0;

            ++$count;

            // Register script handle once
            static $script_enqueued = false;
            if ( ! $script_enqueued ) {
                wp_register_script( 'bld-countdown-timer', '', [], false, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NoExplicitVersion
                wp_enqueue_script( 'bld-countdown-timer' );
                $script_enqueued = true;
            }

            $atts = shortcode_atts(
                    [
                            'date'     => '', // expects dd/mm/yyyy,
                            'end_text' => '',
                            'time'     => '00:00:00', // expects HH:MM:SS or HH:MM in 24-hour format
                    ],
                    $atts,
                    'countdown'
            );

            if ( empty( $atts['date'] ) ) {
                return '<p class="countdown-error">Countdown date not set.</p>';
            }

            // Convert dd/mm/yyyy to yyyy-mm-dd
            $parts = explode( '/', $atts['date'] );
            if ( count( $parts ) !== 3 ) {
                return '<p class="countdown-error">Invalid date format. Use dd/mm/yyyy.</p>';
            }

            list($day, $month, $year) = $parts;

            if ( ! checkdate( $month, $day, $year ) ) {
                return '<p class="countdown-error">Invalid date. Make sure the day, month, and year are valid.</p>';
            }

            $time = sanitize_text_field( $atts['time'] );
            // Validate time format (HH:MM or HH:MM:SS)
            if ( ! preg_match( '/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $time ) ) {
                return '<p class="countdown-error">Invalid time format. Use HH:MM or HH:MM:SS.</p>';
            }

            try {
                $target_datetime = new DateTime( "$year-$month-$day $time", wp_timezone() );
            } catch ( Exception $e ) {
                return '<p class="countdown-error">Invalid timezone or date. Please check site settings.</p>';
            }

            $iso8601_date = $target_datetime->format( DateTimeInterface::ATOM ); // ISO 8601 format with timezone
            $timer_id     = 'countdown-timer-' . $count;

            $end_text = ! empty( $atts['end_text'] ) ? wp_kses_post( $atts['end_text'] ) : '';

            ob_start();

            ?>

            <div id="<?php echo esc_attr( $timer_id ); ?>" class="countdown-timer" data-target-date="<?php echo esc_attr( $iso8601_date ); ?>" aria-live="polite">
                <div class="countdown-segment">
                    <div class="countdown-number countdown-days">0</div>
                    <div class="countdown-label">Days</div>
                </div>
                <div class="countdown-segment">
                    <div class="countdown-number countdown-hours">0</div>
                    <div class="countdown-label">Hours</div>
                </div>
                <div class="countdown-segment">
                    <div class="countdown-number countdown-minutes">0</div>
                    <div class="countdown-label">Minutes</div>
                </div>
                <div class="countdown-segment">
                    <div class="countdown-number countdown-seconds">0</div>
                    <div class="countdown-label">Seconds</div>
                </div>
            </div>
            <?php
            $output = ob_get_clean();
            ob_start();
            ?>
            <script>
                (function(){
                    function initCountdown(container) {
                        const targetDateStr = container.getAttribute('data-target-date');
                        const targetDate = new Date(targetDateStr).getTime();
                        function update() {
                            const now = new Date().getTime();
                            const distance = targetDate - now;
                            if (distance <= 0) {
                                container.querySelector('.countdown-days').textContent = 0;
                                container.querySelector('.countdown-hours').textContent = 0;
                                container.querySelector('.countdown-minutes').textContent = 0;
                                container.querySelector('.countdown-seconds').textContent = 0;
                                const endText = '<?= esc_js( $end_text ); ?>';
                                if (endText) {
                                    container.innerHTML = endText;
                                }
                                clearInterval(interval);
                                return;
                            }
                            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                            container.querySelector('.countdown-days').textContent = days;
                            container.querySelector('.countdown-hours').textContent = hours;
                            container.querySelector('.countdown-minutes').textContent = minutes;
                            container.querySelector('.countdown-seconds').textContent = seconds;
                        }
                        update(); // initial call
                        const interval = setInterval(update, 1000);
                    }
                    document.addEventListener('DOMContentLoaded', function () {
                        const container = document.getElementById('<?php echo esc_js( $timer_id ); ?>');
                        if (container) {
                            initCountdown(container);
                        }
                    });
                })();

            </script>

            <?php
            $script = ob_get_clean();
            wp_add_inline_script( 'bld-countdown-timer', $script );
            return $output;
        }
);
