/**
 * Countdown and redirect script.
 * @module local_questionconverter/countdown
 * @copyright 2026 Renzo Medina <me@renzomedina.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const init = () => {
    const countdownEl = document.getElementById('countdown');
    if (!countdownEl) {
        return;
    }

    const redirectUrl = countdownEl.dataset.redirectUrl;
    if (!redirectUrl) {
        return;
    }

    const initialSeconds = parseInt(
        countdownEl.dataset.seconds || countdownEl.textContent,
        10
    );
    let seconds = Number.isNaN(initialSeconds) || initialSeconds <= 0 ? 10 : initialSeconds;

    const interval = setInterval(() => {
        seconds -= 1;
        countdownEl.textContent = seconds;

        if (seconds <= 0) {
            clearInterval(interval);
            window.location.href = redirectUrl;
        }
    }, 1000);
};