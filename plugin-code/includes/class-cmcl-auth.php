<?php
if (!defined('ABSPATH')) exit;

/**
 * The passwordless email-code sign-in flow: the [club_members_signin]
 * shortcode/form, sending and verifying codes, and logging into the shared
 * club member account.
 */
class CMCL_Auth {

    public function __construct() {
        add_shortcode('club_members_signin', [$this, 'signin_shortcode']);
        add_action('init', [$this, 'handle_signin_post'], 1);
    }

    public function signin_shortcode() {
        $state = [
            'step' => 'email',
            'email' => '',
            'msg' => '',
            'err' => '',
            'expires_in' => 0,
        ];

        // Get the return URL from query string or cookie
        $return_url = '';
        if (isset($_GET['cmcl_return'])) {
            $return_url = sanitize_text_field(wp_unslash($_GET['cmcl_return']));
        } elseif (isset($_COOKIE['cmcl_return_url'])) {
            $return_url = sanitize_text_field(wp_unslash($_COOKIE['cmcl_return_url']));
        }

        // Use transient-like state via POST/redirect? Here we render from query args or postback.
        if (isset($_GET['cmcl_msg'])) $state['msg'] = sanitize_text_field(wp_unslash($_GET['cmcl_msg']));
        if (isset($_GET['cmcl_err'])) $state['err'] = sanitize_text_field(wp_unslash($_GET['cmcl_err']));

        // If we have a pending email stored in a short cookie (for UX), show code step.
        $pending_email = isset($_COOKIE['cmcl_pending_email']) ? CMCL_Whitelist::normalize_email(wp_unslash($_COOKIE['cmcl_pending_email'])) : '';
        $pending_exp   = isset($_COOKIE['cmcl_pending_exp']) ? (int)$_COOKIE['cmcl_pending_exp'] : 0;

        if ($pending_email && $pending_exp > time()) {
            $state['step'] = 'code';
            $state['email'] = $pending_email;
            $state['expires_in'] = max(0, $pending_exp - time());
        } else {
            $this->clear_pending_cookies();
        }

        ob_start();
        ?>
        <div class="cmcl-wrap" style="max-width:420px;">
            <?php if ($state['msg']): ?>
                <div class="cmcl-msg" style="padding:10px;background:#e7f7e7;border:1px solid #b7e0b7;margin-bottom:10px;">
                    <?php echo esc_html($state['msg']); ?>
                </div>
            <?php endif; ?>
            <?php if ($state['err']): ?>
                <div class="cmcl-err" style="padding:10px;background:#fdeaea;border:1px solid #f0b4b4;margin-bottom:10px;">
                    <?php echo esc_html($state['err']); ?>
                </div>
            <?php endif; ?>

            <?php if ($state['step'] === 'email'): ?>
                <form method="post">
                    <?php wp_nonce_field('cmcl_send_code'); ?>
                    <input type="hidden" name="cmcl_action" value="send_code" />
                    <?php if ($return_url): ?>
                        <input type="hidden" name="cmcl_return" value="<?php echo esc_attr($return_url); ?>" />
                    <?php endif; ?>
                    <p>
                        <label for="cmcl_email"><strong>Email adres</strong></label><br/>
                        <input id="cmcl_email" name="cmcl_email" type="email" required style="width:100%;padding:8px;" autocomplete="email"/>
                    </p>
                    <p>
                        <button type="submit" style="padding:10px 12px;">Stuur me een code</button>
                    </p>
                </form>

            <?php else: ?>
                <form method="post" id="cmcl_code_form">
                    <?php wp_nonce_field('cmcl_verify_code'); ?>
                    <input type="hidden" name="cmcl_action" value="verify_code" />

                    <p>
                        <label><strong>Email adres</strong></label><br/>
                        <input type="email" value="<?php echo esc_attr($state['email']); ?>" disabled style="width:100%;padding:8px;background:#f3f3f3;"/>
                        <input type="hidden" name="cmcl_email" value="<?php echo esc_attr($state['email']); ?>"/>
                    </p>

                    <p>
                        <label for="cmcl_code"><strong>6-cijferige code</strong></label><br/>
                        <input id="cmcl_code" name="cmcl_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required style="width:100%;padding:8px;" autocomplete="one-time-code"/>
                    </p>

                    <p>
                        <button type="submit" style="padding:10px 12px;">Aanmelden</button>
                        <button type="button" id="cmcl_restart" style="padding:10px 12px;margin-left:8px;">Opnieuw starten</button>
                    </p>

                    <?php if ($return_url): ?>
                        <input type="hidden" name="cmcl_return" value="<?php echo esc_attr($return_url); ?>" />
                    <?php endif; ?>

                    <p style="color:#666;">
                        Code verloopt over <span id="cmcl_timer"><?php echo (int)$state['expires_in']; ?></span> seconden.
                    </p>
                </form>

                <script>
                (function(){
                    var remaining = <?php echo (int)$state['expires_in']; ?>;
                    var timerEl = document.getElementById('cmcl_timer');
                    var restartBtn = document.getElementById('cmcl_restart');

                    function tick(){
                        remaining = Math.max(0, remaining - 1);
                        if (timerEl) timerEl.textContent = remaining;
                        if (remaining <= 0) {
                            // expire UX: clear cookies server-side via a hit
                            window.location.href = window.location.href.split('?')[0] + '?cmcl_restart=1';
                            return;
                        }
                        setTimeout(tick, 1000);
                    }
                    setTimeout(tick, 1000);

                    if (restartBtn) {
                        restartBtn.addEventListener('click', function(){
                            window.location.href = window.location.href.split('?')[0] + '?cmcl_restart=1';
                        });
                    }
                })();
                </script>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_signin_post() {
        // Restart requested
        if (isset($_GET['cmcl_restart'])) {
            $this->clear_pending_cookies();
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        if (empty($_POST['cmcl_action'])) return;

        $action = sanitize_text_field(wp_unslash($_POST['cmcl_action']));

        if ($action === 'send_code') {
            $this->handle_send_code();
            exit;
        }
        if ($action === 'verify_code') {
            $this->handle_verify_code();
            exit;
        }
    }

    private function handle_send_code() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'cmcl_send_code')) {
            $this->redirect_with_err('Invalid request.');
        }

        $email = isset($_POST['cmcl_email']) ? CMCL_Whitelist::normalize_email(wp_unslash($_POST['cmcl_email'])) : '';
        if (!$email || !is_email($email)) {
            $this->redirect_with_err('Voer een valide e-mail adres in.');
        }

        if (!CMCL_Whitelist::is_whitelisted($email)) {
            $this->redirect_with_err('Je hebt geen toegang met dit adres.');
        }

        // Preserve the return URL in a cookie
        $return = isset($_REQUEST['cmcl_return']) ? sanitize_text_field(wp_unslash($_REQUEST['cmcl_return'])) : '';
        if ($return) {
            setcookie('cmcl_return_url', $return, [
                'expires'  => time() + 3600,
                'path'     => COOKIEPATH ?: '/',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }

        // Prune timeouts before adding new code
        self::prune_expired_codes();

        // With 900,000 possible 6-digit codes and at most ~200 active codes at a
        // time, collision probability is < 0.03%. The previous approach called
        // wp_check_password() (bcrypt, ~100ms) for every stored code per candidate,
        // causing multi-second delays and 500 gateway timeouts under normal load.
        $codes = (array) get_option(CMCL_Core::OPT_CODES, []);
        $code = (string) random_int(100000, 999999);

        // Store code for email
        $exp = time() + CMCL_Core::CODE_TTL;
        $codes[$email] = [
            'hash' => wp_hash_password($code),
            'exp'  => $exp,
        ];
        update_option(CMCL_Core::OPT_CODES, $codes, false);

        // Email it
        $subject = 'Jouw aanmeldcode voor AHV Centaur';
        $message = "Jouw aanmeldcode is: {$code}\n\nDeze code verloopt over 10 minuten.";
        wp_mail($email, $subject, $message);

        // Set pending cookies for UX (10 minutes)
        setcookie('cmcl_pending_email', $email, [
            'expires'  => $exp,
            'path'     => COOKIEPATH ?: '/',
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        setcookie('cmcl_pending_exp', (string)$exp, [
            'expires'  => $exp,
            'path'     => COOKIEPATH ?: '/',
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);

        $this->redirect_with_msg('Code is verstuurd. Controleer je e-mail.');
    }

    private function handle_verify_code() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'cmcl_verify_code')) {
            $this->redirect_with_err('Ongeldig verzoek.');
        }

        $email = isset($_POST['cmcl_email']) ? CMCL_Whitelist::normalize_email(wp_unslash($_POST['cmcl_email'])) : '';
        $code  = isset($_POST['cmcl_code']) ? preg_replace('/\D+/', '', wp_unslash($_POST['cmcl_code'])) : '';

        if (!$email || !is_email($email) || strlen($code) !== 6) {
            $this->redirect_with_err('Ongeldig e-mail adres of code.');
        }

        if (!CMCL_Whitelist::is_whitelisted($email)) {
            $this->redirect_with_err('Je hebt geen toegang met dit adres.');
        }

        self::prune_expired_codes();
        $codes = (array) get_option(CMCL_Core::OPT_CODES, []);
        if (empty($codes[$email]['hash']) || empty($codes[$email]['exp']) || (int)$codes[$email]['exp'] <= time()) {
            $this->redirect_with_err('Deze code is verlopen, probeer het nog een keer.');
        }

        if (!wp_check_password($code, $codes[$email]['hash'])) {
            $this->redirect_with_err('Ongeldige code.');
        }

        // Success: consume code
        unset($codes[$email]);
        update_option(CMCL_Core::OPT_CODES, $codes, false);
        $this->clear_pending_cookies();

        // Log in as the shared user
        CMCL_Core::ensure_role();
        CMCL_Core::ensure_shared_user();

        $user = get_user_by('login', CMCL_Core::SHARED_USER);
        if (!$user) {
            $this->redirect_with_err('Gedeelde gebruiker ontbreekt. Neem contact op met de sitebeheerder.');
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, is_ssl());

        // Mark session start (server + client) to enforce hard 24h timeout.
        $now = time();
        update_user_meta($user->ID, CMCL_Core::OPT_LOGIN_TS, $now);

        setcookie(CMCL_Core::COOKIE_NAME, (string)$now, [
            'expires'  => $now + CMCL_Core::SESSION_TTL,
            'path'     => COOKIEPATH ?: '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $return = '';
        if (isset($_GET['cmcl_return'])) {
            $return = rawurldecode(sanitize_text_field(wp_unslash($_GET['cmcl_return'])));
        } elseif (isset($_REQUEST['cmcl_return'])) {
            $return = rawurldecode(sanitize_text_field(wp_unslash($_REQUEST['cmcl_return'])));
        }

        if ($return && wp_http_validate_url($return)) {
            wp_safe_redirect($return);
        } else {
            wp_safe_redirect(home_url('/'));
        }
        exit;
    }

    private function redirect_with_msg($msg) {
        $url = CMCL_Signin_Page::url();
        wp_safe_redirect(add_query_arg('cmcl_msg', rawurlencode($msg), $url));
        exit;
    }

    private function redirect_with_err($err) {
        $url = CMCL_Signin_Page::url();
        wp_safe_redirect(add_query_arg('cmcl_err', rawurlencode($err), $url));
        exit;
    }

    private function clear_pending_cookies() {
        foreach (['cmcl_pending_email','cmcl_pending_exp','cmcl_return_url'] as $c) {
            if (isset($_COOKIE[$c])) {
                setcookie($c, '', [
                    'expires'  => time() - 3600,
                    'path'     => COOKIEPATH ?: '/',
                    'secure'   => is_ssl(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
                unset($_COOKIE[$c]);
            }
        }
    }

    /* ----------------------- Code storage housekeeping -------------------- */

    public static function prune_expired_codes() {
        $codes = (array) get_option(CMCL_Core::OPT_CODES, []);
        $now = time();
        $changed = false;
        foreach ($codes as $email => $rec) {
            $exp = isset($rec['exp']) ? (int)$rec['exp'] : 0;
            if ($exp <= $now) {
                unset($codes[$email]);
                $changed = true;
            }
        }
        if ($changed) update_option(CMCL_Core::OPT_CODES, $codes, false);
    }
}
