<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>noodled</title>
<meta name="theme-color" content="#111113">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="noodled">
<link rel="manifest" href="<?php echo esc_url( NOODLED_URL . 'assets/manifest.json' ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( NOODLED_URL . 'assets/icon-192.png' ); ?>">
<style>
:root {
  --bg: #111113;
  --bg-card: #1a1a1f;
  --bg-input: #252530;
  --text: #b0b0b8;
  --text-muted: #5a5a68;
  --text-bright: #e8e8ef;
  --accent: #3b82f6;
  --accent-hover: #2563eb;
  --accent-glow: rgba(59, 130, 246, 0.15);
  --border: #2a2a35;
  --green: #34d399;
  --red: #f87171;
}
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
  font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

/* Ambient glow */
body::before {
  content: '';
  position: fixed;
  top: -40%;
  left: 50%;
  transform: translateX(-50%);
  width: 600px;
  height: 600px;
  background: radial-gradient(circle, var(--accent-glow) 0%, transparent 70%);
  pointer-events: none;
  z-index: 0;
}

.login-wrapper {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 40px;
}

/* Brand */
.brand {
  text-align: center;
}

.brand-logo {
  font-size: 56px;
  font-weight: 800;
  color: var(--text-bright);
  letter-spacing: -2.5px;
  line-height: 1;
  margin-bottom: 12px;
}

.brand-logo span {
  color: var(--accent);
}

.brand-tagline {
  font-size: 15px;
  color: var(--text-muted);
  letter-spacing: 0.3px;
}

/* Card */
.login-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 36px;
  width: 380px;
  max-width: 90vw;
  backdrop-filter: blur(20px);
}

.card-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--text-bright);
  margin-bottom: 4px;
}

.card-sub {
  font-size: 12px;
  color: var(--text-muted);
  margin-bottom: 24px;
}

.login-input {
  width: 100%;
  padding: 12px 16px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--bg-input);
  color: var(--text-bright);
  font-size: 14px;
  outline: none;
  margin-bottom: 12px;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.login-input:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px var(--accent-glow);
}

.login-input::placeholder { color: var(--text-muted); }

.login-btn {
  width: 100%;
  padding: 12px;
  border: none;
  border-radius: 10px;
  background: var(--accent);
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s, transform 0.1s;
  letter-spacing: 0.2px;
}

.login-btn:hover { background: var(--accent-hover); }
.login-btn:active { transform: scale(0.98); }
.login-btn:disabled { opacity: 0.5; cursor: default; transform: none; }

.login-msg {
  margin-top: 16px;
  font-size: 13px;
  text-align: center;
  min-height: 20px;
}

.login-msg.error { color: var(--red); }
.login-msg.success { color: var(--green); }

/* Features */
.features {
  display: flex;
  gap: 32px;
  margin-top: 8px;
}

.feature {
  text-align: center;
  max-width: 120px;
}

.feature-icon {
  font-size: 20px;
  margin-bottom: 6px;
  opacity: 0.7;
}

.feature-text {
  font-size: 11px;
  color: var(--text-muted);
  line-height: 1.4;
}

/* Footer */
.login-footer {
  position: fixed;
  bottom: 20px;
  font-size: 11px;
  color: var(--text-muted);
  letter-spacing: 0.5px;
  z-index: 1;
}

/* Responsive */
@media (max-width: 480px) {
  .brand-logo { font-size: 40px; }
  .features { gap: 20px; }
  .login-card { padding: 28px; }
}
</style>
</head>
<body>

<div class="login-wrapper">
  <div class="brand">
    <div class="brand-logo">noodle<span>d</span></div>
    <div class="brand-tagline">Your notes, everywhere</div>
  </div>

  <div class="login-card">
    <div class="card-title">Sign in</div>
    <div class="card-sub">We'll send a magic link to your email</div>

    <form id="loginForm" onsubmit="handleLogin(event)">
      <input class="login-input" type="email" id="emailInput" placeholder="you@example.com" required autofocus>
      <button class="login-btn" type="submit" id="loginBtn">Continue</button>
    </form>

    <div class="login-msg" id="loginMsg">
      <?php if ( ! empty( $login_error ) ) : ?>
        <span class="error"><?php echo esc_html( $login_error ); ?></span>
      <?php endif; ?>
    </div>
  </div>

  <div class="features">
    <div class="feature">
      <div class="feature-icon">&#128221;</div>
      <div class="feature-text">Markdown notes with rich editing</div>
    </div>
    <div class="feature">
      <div class="feature-icon">&#128274;</div>
      <div class="feature-text">Private notebooks with sharing</div>
    </div>
    <div class="feature">
      <div class="feature-icon">&#128260;</div>
      <div class="feature-text">Syncs with desktop app</div>
    </div>
  </div>
</div>

<div class="login-footer">noodled v<?php echo NOODLED_VERSION; ?></div>

<script>
async function handleLogin(e) {
  e.preventDefault();
  const email = document.getElementById('emailInput').value;
  const btn = document.getElementById('loginBtn');
  const msg = document.getElementById('loginMsg');

  btn.disabled = true;
  btn.textContent = 'Sending...';
  msg.textContent = '';
  msg.className = 'login-msg';

  try {
    const res = await fetch('<?php echo esc_url( rest_url( 'noodled/v1/auth/login' ) ); ?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email }),
    });
    const data = await res.json();

    if (data.error) {
      msg.textContent = data.error;
      msg.className = 'login-msg error';
    } else {
      msg.innerHTML = '&#10003; Check your email for a login link!';
      msg.className = 'login-msg success';
      btn.textContent = 'Link sent';
    }
  } catch (err) {
    msg.textContent = 'Something went wrong. Try again.';
    msg.className = 'login-msg error';
    btn.textContent = 'Continue';
  }

  btn.disabled = false;
}
</script>

</body>
</html>
