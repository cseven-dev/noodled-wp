<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_App {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_rewrite' ] );
		add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'render' ] );

		if ( Noodled_Settings::is_homepage_mode() ) {
			add_action( 'template_redirect', [ __CLASS__, 'render_homepage' ], 5 );
		}
	}

	public static function register_rewrite() {
		$path = Noodled_Settings::get_app_path();
		add_rewrite_rule( '^' . preg_quote( $path, '/' ) . '/?$', 'index.php?noodled_app=1', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'noodled_app';
		return $vars;
	}

	public static function get_app_url(): string {
		if ( Noodled_Settings::is_homepage_mode() ) {
			return home_url( '/' );
		}
		return home_url( '/' . Noodled_Settings::get_app_path() . '/' );
	}

	/* ── Shared helpers ── */

	private static function send_no_cache_headers(): void {
		header( 'Cache-Control: no-cache, no-store, must-revalidate, private' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'Vary: Cookie' );
	}

	private static function build_config( array $current_user ): array {
		return [
			'apiBase'   => rest_url( 'noodled/v1' ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'version'   => NOODLED_VERSION,
			'appUrl'    => self::get_app_url(),
			'brandName' => Noodled_Settings::get_brand_name(),
			'user'      => [
				'name'  => $current_user['name'],
				'email' => $current_user['email'],
				'admin' => $current_user['role'] === 'admin',
			],
		];
	}

	/* ── Route: homepage ── */

	public static function render_homepage() {
		if ( ! is_front_page() && ! is_home() ) return;
		self::serve_request();
	}

	/* ── Route: /noodled/ (or custom path) ── */

	public static function render() {
		if ( ! get_query_var( 'noodled_app' ) ) return;
		self::serve_request();
	}

	/* ── Core request handler ── */

	private static function serve_request(): void {
		// One-click email magic link: verify the PIN, set the session, then
		// bounce to the clean app URL so the address bar carries no secret.
		if ( isset( $_GET['noodled_login'], $_GET['noodled_email'] ) ) {
			$result = Noodled_Auth::login_with_pin(
				wp_unslash( $_GET['noodled_email'] ),
				wp_unslash( $_GET['noodled_login'] )
			);
			// On failure bounce back with the email so the login box can pre-fill it
			// (the user shouldn't have to retype it to get a fresh PIN).
			$target = isset( $result['error'] )
				? add_query_arg( [ 'login' => 'expired', 'e' => sanitize_email( wp_unslash( $_GET['noodled_email'] ) ) ], self::get_app_url() )
				: self::get_app_url();
			wp_safe_redirect( $target );
			exit;
		}

		self::send_no_cache_headers();

		$current_user = Noodled_Auth::get_current_user();

		// Admin landing preview: view the public landing page even while logged in
		// (you can't otherwise see it once authenticated).
		if ( isset( $_GET['noodled_preview'] ) && $_GET['noodled_preview'] === 'landing'
			&& $current_user && ( $current_user['role'] ?? '' ) === 'admin' ) {
			$landing_file = NOODLED_PATH . 'templates/landing.html';
			$landing = file_exists( $landing_file ) ? file_get_contents( $landing_file ) : Noodled_Settings::get_landing_html();
			if ( $landing ) { self::serve_landing( $landing ); exit; }
		}

		if ( ! $current_user ) {
			// Check file-based landing page first, then DB upload
			$landing_file = NOODLED_PATH . 'templates/landing.html';
			$landing = file_exists( $landing_file ) ? file_get_contents( $landing_file ) : Noodled_Settings::get_landing_html();
			if ( $landing ) {
				self::serve_landing( $landing );
			} else {
				include NOODLED_PATH . 'templates/login.php';
			}
			exit;
		}

		$config = self::build_config( $current_user );
		include NOODLED_PATH . 'templates/app.php';
		exit;
	}

	/* ── Landing page with injected login modal ── */

	private static function serve_landing( string $html ): void {
		$app_url     = self::get_app_url();
		$login_api   = rest_url( 'noodled/v1/auth/login' );
		$pin_api     = rest_url( 'noodled/v1/auth/pin' );
		$verify_api  = rest_url( 'noodled/v1/auth/verify' );
		$request_api = rest_url( 'noodled/v1/auth/request' );
		$brand       = Noodled_Settings::get_brand_name();
		$accent      = Noodled_Settings::get_accent_color();

		$login_inject = <<<HTML
<!-- Noodled Login Overlay -->
<style>
.n-login-overlay{display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.n-login-overlay.show{display:flex}
.n-login-box{background:#1a1a1f;border:1px solid #2a2a35;border-radius:16px;padding:36px;width:380px;max-width:90vw;color:#b0b0b8}
.n-login-box h3{color:#e8e8ef;margin:0 0 4px;font-size:16px}
.n-login-box .sub{color:#5a5a68;font-size:12px;margin-bottom:20px}
.n-login-input{width:100%;padding:12px 16px;border:1px solid #2a2a35;border-radius:10px;background:#252530;color:#e8e8ef;font-size:16px;outline:none;margin-bottom:10px;box-sizing:border-box}
.n-login-input:focus{border-color:{$accent};box-shadow:0 0 0 3px rgba(0,120,212,0.15)}
.n-login-btn{width:100%;padding:12px;border:none;border-radius:10px;background:{$accent};color:#fff;font-size:14px;font-weight:600;cursor:pointer}
.n-login-btn:hover{filter:brightness(1.1)}
.n-login-btn:disabled{opacity:0.5}
.n-login-msg{margin-top:12px;font-size:13px;text-align:center;min-height:20px}
.n-login-msg.error{color:#f87171}
.n-login-msg.success{color:#34d399}
.n-login-link{color:#9a9aa8;font-size:13.5px;text-decoration:none;display:block;text-align:center;margin-top:12px}
.n-login-link:hover{color:#e8e8ef}
.n-login-link.lg{font-size:15px;font-weight:600;color:{$accent};margin-top:18px}
.n-login-link.lg:hover{filter:brightness(1.15)}
.n-pin-input{text-align:center;font-size:24px;letter-spacing:8px}
</style>

<div class="n-login-overlay" id="nLoginOverlay" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="n-login-box">
    <div id="nStepEmail">
      <h3>Sign in to {$brand}</h3>
      <div class="sub">We'll send a PIN to your email</div>
      <input class="n-login-input" type="email" id="nEmail" placeholder="you@example.com">
      <button class="n-login-btn" id="nEmailBtn" onclick="nSendPin()">Continue</button>
      <a href="#" class="n-login-link lg" onclick="event.preventDefault();nGotPin()">Already have a PIN? Sign in &rarr;</a>
    </div>
    <div id="nStepPin" style="display:none">
      <h3>Enter your PIN</h3>
      <div class="sub">The 6-digit code from your noodle email</div>
      <input class="n-login-input" type="email" id="nPinEmail" placeholder="you@example.com">
      <input class="n-login-input n-pin-input" type="text" id="nPin" placeholder="000000" maxlength="6" inputmode="numeric" autocomplete="one-time-code">
      <button class="n-login-btn" id="nPinBtn" onclick="nVerifyPin()">Sign in</button>
      <a href="#" class="n-login-link" onclick="event.preventDefault();nShowStep('nStepEmail')">Need a new PIN?</a>
    </div>
    <div class="n-login-msg" id="nLoginMsg"></div>
  </div>
</div>

<script>
let _nEmail='';
function nShowStep(id){['nStepEmail','nStepPin'].forEach(s=>{const e=document.getElementById(s);if(e)e.style.display=s===id?'block':'none'});document.getElementById('nLoginMsg').textContent='';document.getElementById('nLoginMsg').className='n-login-msg'}
function nGotPin(){const e=document.getElementById('nEmail').value.trim();if(e)_nEmail=e;nGotoPin();}
// Go to the PIN step — only ever ask for the PIN. If we know the email we verify
// email+PIN; if not, we verify by the PIN alone (same as the magic link).
function nGotoPin(){const pe=document.getElementById('nPinEmail');if(pe){if(_nEmail)pe.value=_nEmail;pe.style.display='none';}nShowStep('nStepPin');const f=document.getElementById('nPin');if(f)f.focus();}
async function nSendPin(){const email=document.getElementById('nEmail').value;if(!email)return;_nEmail=email;const btn=document.getElementById('nEmailBtn');btn.disabled=true;btn.textContent='Sending...';const msg=document.getElementById('nLoginMsg');msg.textContent='';try{const r=await fetch('{$login_api}',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email})});const d=await r.json();if(d.error){msg.textContent=d.error;msg.className='n-login-msg error'}else{nGotoPin()}}catch(e){msg.textContent='Something went wrong';msg.className='n-login-msg error'}btn.disabled=false;btn.textContent='Continue'}
async function nVerifyPin(){const pin=document.getElementById('nPin').value.trim();if(!pin)return;const email=(_nEmail||document.getElementById('nPinEmail').value||'').trim();const msg=document.getElementById('nLoginMsg');const btn=document.getElementById('nPinBtn');btn.disabled=true;btn.textContent='Verifying...';try{let r;if(email){r=await fetch('{$pin_api}',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({email,pin})});}else{r=await fetch('{$verify_api}?token='+encodeURIComponent(pin),{credentials:'same-origin'});}const d=await r.json();if(d.error){msg.textContent=d.error;msg.className='n-login-msg error';btn.disabled=false;btn.textContent='Sign in'}else{msg.innerHTML='&#10003; Welcome!';msg.className='n-login-msg success';setTimeout(()=>window.location.href='{$app_url}'+('{$app_url}'.includes('?')?'&':'?')+'_='+Date.now(),500)}}catch(e){msg.textContent='Something went wrong';msg.className='n-login-msg error';btn.disabled=false;btn.textContent='Sign in'}}
document.addEventListener('keydown',e=>{if(e.key==='Enter'){const pin=document.getElementById('nStepPin');if(pin&&pin.style.display!=='none')nVerifyPin();else if(document.getElementById('nStepEmail').style.display!=='none')nSendPin()}});
// Expired one-click link → open login modal, pre-fill the email so they don't retype it.
(function(){const q=new URLSearchParams(location.search);if(q.get('login')==='expired'){const o=document.getElementById('nLoginOverlay');if(o)o.classList.add('show');const em=q.get('e');if(em){_nEmail=em;const ne=document.getElementById('nEmail');if(ne)ne.value=em;const pe=document.getElementById('nPinEmail');if(pe)pe.value=em;}const m=document.getElementById('nLoginMsg');if(m){m.textContent='That sign-in link expired — tap Continue to get a fresh PIN.';m.className='n-login-msg error';}}})();
// Inject login button into page nav (ghost style, left of Get Noodled)
(function(){
  const cta=document.querySelector('.nav-cta');
  if(cta){
    const a=document.createElement('a');
    a.href='#';
    a.textContent='Log in';
    a.className='btn btn--ghost';
    a.onclick=function(e){e.preventDefault();document.getElementById('nLoginOverlay').classList.add('show')};
    cta.parentNode.insertBefore(a,cta);
    cta.style.marginLeft='8px';
  }
  document.querySelectorAll('.noodled-open-login').forEach(el=>{el.style.cursor='pointer';el.addEventListener('click',e=>{e.preventDefault();document.getElementById('nLoginOverlay').classList.add('show')})});
})();
</script>
HTML;

		$request_inject = <<<HTML
<!-- Noodled "Get a noodle" — inline form styles -->
<style>
.ngn-form{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;align-items:center;max-width:540px;margin:0 auto}
.ngn-input{flex:1 1 180px;min-width:0;padding:13px 16px;border:1px solid rgba(0,0,0,0.16);border-radius:10px;background:#fff;color:#1A1A1A;font-size:16px;font-family:inherit;outline:none}
.ngn-input::placeholder{color:#9a9a9a}
.ngn-input:focus{border-color:{$accent};box-shadow:0 0 0 3px rgba(0,120,212,0.16)}
.ngn-form .btn{flex:0 0 auto}
.ngn-msg{flex-basis:100%;text-align:center;margin-top:6px;font-size:14px;min-height:20px}
.ngn-msg.error{color:#b91c1c}
.ngn-msg.success{color:#15803d;font-weight:600}
</style>
<!-- Noodled "Get a noodle" Request Overlay -->
<div class="n-login-overlay" id="nReqOverlay" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="n-login-box">
    <div id="nReqForm">
      <h3>Get a noodle</h3>
      <div class="sub">Request access to {$brand}. We'll email you a login PIN once you're approved.</div>
      <input class="n-login-input" type="text" id="nReqName" placeholder="Your name">
      <input class="n-login-input" type="email" id="nReqEmail" placeholder="you@example.com">
      <button class="n-login-btn" id="nReqBtn" onclick="nRequestNoodle()">Request a noodle</button>
      <a href="#" class="n-login-link" onclick="event.preventDefault();document.getElementById('nReqOverlay').classList.remove('show');document.getElementById('nLoginOverlay').classList.add('show')">Already have one? Sign in</a>
    </div>
    <div class="n-login-msg" id="nReqMsg"></div>
  </div>
</div>
<script>
async function nRequestNoodle(){
  const name=document.getElementById('nReqName').value.trim();
  const email=document.getElementById('nReqEmail').value.trim();
  const msg=document.getElementById('nReqMsg');
  if(!email){msg.textContent='Please enter your email.';msg.className='n-login-msg error';return;}
  const btn=document.getElementById('nReqBtn');btn.disabled=true;btn.textContent='Sending...';
  try{
    const r=await fetch('{$request_api}',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,email})});
    const d=await r.json();
    if(d.error){msg.textContent=d.error;msg.className='n-login-msg error';btn.disabled=false;btn.textContent='Request a noodle';}
    else{document.getElementById('nReqForm').style.display='none';msg.innerHTML='&#10003; '+(d.message||'Request received!');msg.className='n-login-msg success';}
  }catch(e){msg.textContent='Something went wrong';msg.className='n-login-msg error';btn.disabled=false;btn.textContent='Request a noodle';}
}
// Inline "Get a noodle" form (in the final CTA section)
async function nGetNoodle(e){
  e.preventDefault();
  const name=document.getElementById('nGetName').value.trim();
  const email=document.getElementById('nGetEmail').value.trim();
  const msg=document.getElementById('nGetMsg');
  if(!email){msg.textContent='Please enter your email.';msg.className='ngn-msg error';return false;}
  const btn=document.getElementById('nGetBtn');btn.disabled=true;btn.textContent='Sending...';
  try{
    const r=await fetch('{$request_api}',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,email})});
    const d=await r.json();
    if(d.error){msg.textContent=d.error;msg.className='ngn-msg error';btn.disabled=false;btn.textContent='Get a noodle';}
    else{document.getElementById('nGetForm').style.display='none';msg.innerHTML='&#10003; '+(d.message||'Request received!');msg.className='ngn-msg success';}
  }catch(err){msg.textContent='Something went wrong';msg.className='ngn-msg error';btn.disabled=false;btn.textContent='Get a noodle';}
  return false;
}
(function(){
  function openReq(e){e.preventDefault();document.getElementById('nReqOverlay').classList.add('show');document.getElementById('nReqName').focus();}
  // "Get Noodled" (nav) + hero primary CTA + any .noodled-open-request hook → request modal
  document.querySelectorAll('.nav-cta, .hero__cta a.btn--lg:not(.btn--ghost), .noodled-open-request').forEach(el=>{el.style.cursor='pointer';el.addEventListener('click',openReq);});
})();
</script>
HTML;

		// Remove c-Seven ribbon (not needed when served by plugin)
		$html = preg_replace( '/<!-- ={3,} C-SEVEN RIBBON ={3,} -->.*?<\/div>\s*<\/div>/s', '', $html );
		// Remove ribbon-related styling and fix nav
		$html = str_replace( 'padding-top: 44px', 'padding-top: 0', $html );
		$html = str_replace( 'position: sticky;', 'position: relative;', $html );
		$html = str_replace( 'top: 44px;', 'top: 0;', $html );
		$html = str_replace( '.nav-cta { margin-left: auto; }', '.nav-cta { margin-left: auto; margin-right: 8px; }', $html );

		// Fix old metadata URL if present
		$html = str_replace(
			'https://c7.ca/c7-plugins/noodled/metadata.json',
			'https://c7.ca/noodled/metadata.json',
			$html
		);

		// "Get Noodled" → "Get a noodle" (nav button + final-CTA eyebrow)
		$html = str_replace( 'Get Noodled', 'Get a noodle', $html );

		// Final-CTA lead copy: download phrasing → signup/approval phrasing
		$html = str_replace(
			'Drop it on your WordPress in a few minutes, log in with a PIN, and start untangling. It\'s free to run.',
			'Request a noodle below. Once you\'re approved, we\'ll email you a login PIN and you can start untangling. It\'s free.',
			$html
		);

		// Replace the desktop "Download the plugin" button with the inline signup form
		$getnoodle_form = <<<HTML
<form class="ngn-form reveal d2" id="nGetForm" onsubmit="return nGetNoodle(event)">
          <input type="text" id="nGetName" class="ngn-input" placeholder="Your name" autocomplete="name">
          <input type="email" id="nGetEmail" class="ngn-input" placeholder="you@example.com" autocomplete="email" required>
          <button type="submit" class="btn btn--lg" id="nGetBtn">Get a noodle</button>
          <div class="ngn-msg" id="nGetMsg"></div>
        </form>
HTML;
		$html = str_replace(
			'<a class="btn btn--lg" href="#" id="dl-btn">Download the plugin</a>',
			$getnoodle_form,
			$html
		);

		// Repoint the "See it move again" ghost button label is fine; update the fine-print note
		// (rename id so the metadata-fetch script no longer overwrites it with download text)
		$html = preg_replace(
			'/<p class="tiny reveal d3" id="dl-note">.*?<\/p>/s',
			'<p class="tiny reveal d3" id="getnoodle-note">Free &middot; We\'ll email you a login PIN once you\'re approved &middot; made in Toronto by <a href="https://c7.ca" style="color:var(--n-blue); text-decoration:none; font-weight:600;">c-Seven</a></p>',
			$html
		);

		// Footer "Download" link → "Get a noodle"
		$html = str_replace( '<a href="#get">Download</a>', '<a href="#get">Get a noodle</a>', $html );

		$html = str_ireplace( '</body>', $login_inject . $request_inject . "\n</body>", $html );
		echo $html;
	}
}
