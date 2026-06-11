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

	/**
	 * Admin-only maintenance page: merge "duplicate person" notebooks (a notebook
	 * you own whose name matches a drop folder shared back to you) into the shared
	 * folder, so each person appears once. Dry-run by default; a nonce-protected
	 * ?confirm=1 executes. Moving into the shared folder means those notes become
	 * visible to that member — the dry run spells out exactly what moves.
	 */
	private static function serve_merge_dups(): void {
		self::send_no_cache_headers();
		if ( ! ( is_user_logged_in() && current_user_can( 'manage_options' ) ) ) {
			status_header( 403 );
			echo '<p style="font-family:sans-serif;padding:24px">' . esc_html__( 'Admins only.', 'noodled' ) . '</p>';
			return;
		}
		$admin_id = Noodled_Auth::current_user_id();
		$plan     = Noodled_Notebooks::find_dup_merges( $admin_id );
		$back      = esc_url( self::get_app_url() );
		$confirmed = isset( $_GET['confirm'] ) && $_GET['confirm'] === '1'
			&& isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'noodled_merge_dups' );

		echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<title>noodled — merge duplicate folders</title>';
		echo '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 20px;line-height:1.5;color:#1a1a1f">';
		echo '<h2>Merge duplicate folders</h2>';

		if ( $confirmed ) {
			$done = Noodled_Notebooks::merge_dups( $admin_id );
			if ( ! $done ) {
				echo '<p>Nothing to merge.</p>';
			} else {
				echo '<p><strong>Done.</strong></p><ul>';
				foreach ( $done as $d ) {
					echo '<li>' . esc_html( sprintf(
						/* translators: 1: notebook name, 2: number of notes */
						'%1$s — moved %2$d note(s) into the shared folder%3$s',
						$d['name'], (int) $d['moved'], $d['owned_deleted'] ? ', removed the empty duplicate' : ''
					) ) . '</li>';
				}
				echo '</ul>';
			}
			echo '<p><a href="' . $back . '">&larr; Back to noodled</a></p>';
			return;
		}

		if ( ! $plan ) {
			echo '<p>No duplicate person-notebooks found. Nothing to do.</p>';
			echo '<p><a href="' . $back . '">&larr; Back to noodled</a></p>';
			return;
		}

		echo '<p>These notebooks you own share a name with a folder shared back to you. '
			. 'Merging moves your notes <strong>into the shared folder</strong>, so they become visible to that person. '
			. 'Review carefully:</p>';
		foreach ( $plan as $p ) {
			echo '<div style="border:1px solid #ddd;border-radius:8px;padding:12px 16px;margin:12px 0">';
			echo '<strong>' . esc_html( $p['name'] ) . '</strong> — '
				. esc_html( sprintf( '%d note(s) will move into the shared folder:', count( $p['notes'] ) ) );
			echo '<ul style="margin:8px 0">';
			foreach ( $p['notes'] as $note ) {
				echo '<li>' . esc_html( $note['title'] ?: '(untitled)' ) . '</li>';
			}
			echo '</ul></div>';
		}
		$go = esc_url( wp_nonce_url( add_query_arg( [ 'noodled_admin' => 'merge_dups', 'confirm' => '1' ], self::get_app_url() ), 'noodled_merge_dups' ) );
		echo '<p style="margin-top:20px"><a href="' . $go . '" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">Merge now</a>'
			. ' &nbsp; <a href="' . $back . '" style="color:#555">Cancel</a></p>';
	}

	private static function build_config( array $current_user ): array {
		return [
			'apiBase'   => rest_url( 'noodled/v1' ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'version'   => NOODLED_VERSION,
			'appUrl'    => self::get_app_url(),
			'brandName' => Noodled_Settings::get_brand_name(),
			'map'       => Noodled_Settings::get_map_config(),
			'user'      => [
				'name'  => $current_user['name'],
				'email' => $current_user['email'],
				'admin' => $current_user['role'] === 'admin',
				'owner' => Noodled_Auth::is_owner(),
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
		// Public legal pages (Privacy / Terms) — reachable by anyone, no session needed.
		if ( isset( $_GET['noodled_legal'] ) ) {
			self::serve_legal( sanitize_key( wp_unslash( $_GET['noodled_legal'] ) ) );
			exit;
		}

		// One-click email magic link: verify the PIN, set the session, then
		// bounce to the clean app URL so the address bar carries no secret.
		if ( isset( $_GET['noodled_login'], $_GET['noodled_email'] ) ) {
			// Magic links get pre-fetched by mail scanners and re-opened by the
			// user. If this browser already has a valid session, just bounce into
			// the app rather than re-spending the single-use PIN (which would then
			// look "expired" on the user's real click).
			if ( Noodled_Auth::is_authenticated() ) {
				wp_safe_redirect( self::get_app_url() );
				exit;
			}
			$result = Noodled_Auth::login_with_pin(
				wp_unslash( $_GET['noodled_email'] ),
				wp_unslash( $_GET['noodled_login'] ),
				true // one-click link: per-IP rate limit, not the typed-PIN email cap
			);
			// On failure bounce back with the email so the login box can pre-fill it
			// (the user shouldn't have to retype it to get a fresh PIN).
			$target = isset( $result['error'] )
				? add_query_arg( [ 'login' => 'expired', 'e' => sanitize_email( wp_unslash( $_GET['noodled_email'] ) ) ], self::get_app_url() )
				: self::get_app_url();
			wp_safe_redirect( $target );
			exit;
		}

		// Admin maintenance: merge "duplicate person" notebooks (a notebook you own
		// whose name matches a drop folder shared back to you) into the shared
		// folder. Dry-run by default; ?confirm=1 executes. Admins only.
		if ( isset( $_GET['noodled_admin'] ) && $_GET['noodled_admin'] === 'merge_dups' ) {
			self::serve_merge_dups();
			exit;
		}

		self::send_no_cache_headers();

		// Mint a long-lived app session for a signed-in WP admin so they aren't
		// logged out when the short WordPress login cookie lapses.
		Noodled_Auth::ensure_admin_session();

		$current_user = Noodled_Auth::get_current_user();

		// Admin landing preview: view the public landing page even while logged in
		// (you can't otherwise see it once authenticated).
		if ( isset( $_GET['noodled_preview'] ) && $_GET['noodled_preview'] === 'landing'
			&& $current_user && ( $current_user['role'] ?? '' ) === 'admin' ) {
			$landing_file = NOODLED_PATH . 'templates/landing.html';
			$landing = file_exists( $landing_file ) ? file_get_contents( $landing_file ) : Noodled_Settings::get_landing_html();
			if ( $landing ) { self::serve_landing( $landing, true ); exit; }
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

	private static function serve_landing( string $html, bool $preview = false ): void {
		$app_url     = self::get_app_url();
		$login_api   = rest_url( 'noodled/v1/auth/login' );
		$pin_api     = rest_url( 'noodled/v1/auth/pin' );
		$verify_api  = rest_url( 'noodled/v1/auth/verify' );
		$request_api = rest_url( 'noodled/v1/auth/request' );
		$pk_opts_api = rest_url( 'noodled/v1/auth/passkey/auth-options' );
		$pk_auth_api = rest_url( 'noodled/v1/auth/passkey/auth' );
		$brand       = Noodled_Settings::get_brand_name();
		$accent      = Noodled_Settings::get_accent_color();

		// Translatable overlay strings. HTML labels use esc_html/esc_attr; the inline
		// JS reuses the same esc_js() values so the rendered text and the JS-reset
		// text stay identical (and all are extractable via these __() calls).
		$l_signin     = esc_html( sprintf( __( 'Sign in to %s', 'noodled' ), $brand ) );
		$l_pinsub     = esc_html( __( "We'll send a PIN to your email", 'noodled' ) );
		$l_continue   = esc_html( __( 'Continue', 'noodled' ) );
		$l_havepin    = esc_html( __( 'Already have a PIN? Sign in', 'noodled' ) );
		$l_passkey    = esc_html( __( 'Sign in with a passkey', 'noodled' ) );
		$j_passkeyfail = esc_js( __( 'Passkey sign-in failed. Use your PIN instead.', 'noodled' ) );
		$j_passkeywait = esc_js( __( 'Follow your device prompt…', 'noodled' ) );
		$l_enterpin   = esc_html( __( 'Enter your PIN', 'noodled' ) );
		$l_codesub    = esc_html( __( 'The 6-digit code from your noodle email', 'noodled' ) );
		$l_signinbtn  = esc_html( __( 'Sign in', 'noodled' ) );
		$l_newpin     = esc_html( __( 'Need a new PIN?', 'noodled' ) );
		$l_getnoodle  = esc_html( __( 'Get a noodle', 'noodled' ) );
		$l_reqsub     = esc_html( sprintf( __( "Request access to %s. We'll email you a login PIN once you're approved.", 'noodled' ), $brand ) );
		$l_reqbtn     = esc_html( __( 'Request a noodle', 'noodled' ) );
		$l_havelogin  = esc_html( __( 'Already have one? Sign in', 'noodled' ) );
		$a_email      = esc_attr( __( 'Email address', 'noodled' ) );
		$a_pin        = esc_attr( __( '6-digit PIN', 'noodled' ) );
		$a_yourname   = esc_attr( __( 'Your name', 'noodled' ) );
		// Inline-JS status strings (esc_js for single-quoted JS context).
		$j_sending    = esc_js( __( 'Sending...', 'noodled' ) );
		$j_verifying  = esc_js( __( 'Verifying...', 'noodled' ) );
		$j_wrong      = esc_js( __( 'Something went wrong', 'noodled' ) );
		$j_welcome    = esc_js( __( 'Welcome!', 'noodled' ) );
		$j_continue   = esc_js( __( 'Continue', 'noodled' ) );
		$j_signin     = esc_js( __( 'Sign in', 'noodled' ) );
		$j_login      = esc_js( __( 'Log in', 'noodled' ) );
		$j_reqbtn     = esc_js( __( 'Request a noodle', 'noodled' ) );
		$j_reqrecv    = esc_js( __( 'Request received!', 'noodled' ) );
		$j_enteremail = esc_js( __( 'Please enter your email.', 'noodled' ) );
		$j_expired    = esc_js( __( 'That sign-in link expired, tap Continue to get a fresh PIN.', 'noodled' ) );
		$j_getnoodle  = esc_js( __( 'Get a noodle', 'noodled' ) );

		$login_inject = <<<HTML
<!-- Noodled Login Overlay -->
<style>
.n-login-overlay{display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.n-login-overlay.show{display:flex}
.n-login-box{background:#1a1a1f;border:1px solid #2a2a35;border-radius:16px;padding:36px;width:380px;max-width:90vw;color:#b0b0b8}
.n-login-box h3{color:#e8e8ef;margin:0 0 4px;font-size:16px}
.n-login-box .sub{color:#b9b9c6;font-size:12px;margin-bottom:20px}
.n-login-input{width:100%;padding:12px 16px;border:1px solid #2a2a35;border-radius:10px;background:#252530;color:#e8e8ef;font-size:16px;outline:none;margin-bottom:10px;box-sizing:border-box}
.n-login-input:focus{border-color:{$accent};box-shadow:0 0 0 3px rgba(0,120,212,0.15)}
.n-login-btn{width:100%;padding:12px;border:none;border-radius:10px;background:{$accent};color:#fff;font-size:14px;font-weight:600;cursor:pointer}
.n-login-btn:hover{filter:brightness(1.1)}
.n-login-btn:focus-visible{outline:2px solid #fff;outline-offset:2px}
.n-login-btn:disabled{opacity:0.5}
.n-login-msg{margin-top:12px;font-size:13px;text-align:center;min-height:20px}
.n-login-msg.error{color:#f87171}
.n-login-msg.success{color:#34d399}
.n-login-link{color:#b9b9c6;font-size:13.5px;text-decoration:none;display:block;text-align:center;margin-top:12px;background:none;border:none;cursor:pointer;font-family:inherit;width:100%}
.n-login-link:focus-visible{outline:2px solid {$accent};outline-offset:2px;border-radius:4px}
.n-login-link:hover{color:#e8e8ef}
.n-login-link.lg{font-size:15px;font-weight:600;color:{$accent};margin-top:18px}
.n-login-link.lg:hover{filter:brightness(1.15)}
.n-pin-input{text-align:center;font-size:24px;letter-spacing:8px}
</style>

<div class="n-login-overlay" id="nLoginOverlay" role="dialog" aria-modal="true" aria-labelledby="nLoginTitle" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="n-login-box">
    <div id="nStepEmail">
      <h3 id="nLoginTitle">{$l_signin}</h3>
      <div class="sub">{$l_pinsub}</div>
      <input class="n-login-input" type="email" id="nEmail" placeholder="you@example.com" aria-label="{$a_email}">
      <button class="n-login-btn" id="nEmailBtn" onclick="nSendPin()">{$l_continue}</button>
      <button type="button" class="n-login-link" id="nPasskeyBtn" style="display:none" onclick="nPasskey()">&#128272; {$l_passkey}</button>
      <button type="button" class="n-login-link lg" onclick="nGotPin()">{$l_havepin} &rarr;</button>
    </div>
    <div id="nStepPin" style="display:none">
      <h3 id="nPinTitle">{$l_enterpin}</h3>
      <div class="sub">{$l_codesub}</div>
      <input class="n-login-input" type="email" id="nPinEmail" placeholder="you@example.com" aria-label="{$a_email}">
      <input class="n-login-input n-pin-input" type="text" id="nPin" placeholder="000000" aria-label="{$a_pin}" maxlength="6" inputmode="numeric" autocomplete="one-time-code">
      <button class="n-login-btn" id="nPinBtn" onclick="nVerifyPin()">{$l_signinbtn}</button>
      <button type="button" class="n-login-link" onclick="nShowStep('nStepEmail')">{$l_newpin}</button>
    </div>
    <div class="n-login-msg" id="nLoginMsg"></div>
  </div>
</div>

<script>
let _nEmail='';
let _nLoginReturn=null;
function nOpenLogin(){const o=document.getElementById('nLoginOverlay');if(!o)return;_nLoginReturn=document.activeElement;o.classList.add('show');const f=document.getElementById('nEmail');if(f)setTimeout(()=>f.focus(),30);}
function nShowStep(id){['nStepEmail','nStepPin'].forEach(s=>{const e=document.getElementById(s);if(e)e.style.display=s===id?'block':'none'});const ov=document.getElementById('nLoginOverlay');if(ov)ov.setAttribute('aria-labelledby',id==='nStepPin'?'nPinTitle':'nLoginTitle');document.getElementById('nLoginMsg').textContent='';document.getElementById('nLoginMsg').className='n-login-msg'}
function nGotPin(){const e=document.getElementById('nEmail').value.trim();if(e)_nEmail=e;nGotoPin();}
// Go to the PIN step — only ever ask for the PIN. If we know the email we verify
// email+PIN; if not, we verify by the PIN alone (same as the magic link).
function nGotoPin(){const pe=document.getElementById('nPinEmail');if(pe){if(_nEmail)pe.value=_nEmail;pe.style.display='none';}nShowStep('nStepPin');const f=document.getElementById('nPin');if(f)f.focus();}
async function nSendPin(){const email=document.getElementById('nEmail').value;if(!email)return;_nEmail=email;const btn=document.getElementById('nEmailBtn');btn.disabled=true;btn.textContent='{$j_sending}';const msg=document.getElementById('nLoginMsg');msg.textContent='';try{const r=await fetch('{$login_api}',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email})});const d=await r.json();if(d.error){msg.textContent=d.error;msg.className='n-login-msg error'}else{nGotoPin()}}catch(e){msg.textContent='{$j_wrong}';msg.className='n-login-msg error'}btn.disabled=false;btn.textContent='{$j_continue}'}
async function nVerifyPin(){const pin=document.getElementById('nPin').value.trim();if(!pin)return;const email=(_nEmail||document.getElementById('nPinEmail').value||'').trim();const msg=document.getElementById('nLoginMsg');const btn=document.getElementById('nPinBtn');btn.disabled=true;btn.textContent='{$j_verifying}';try{let r;if(email){r=await fetch('{$pin_api}',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({email,pin})});}else{r=await fetch('{$verify_api}?token='+encodeURIComponent(pin),{credentials:'same-origin'});}const d=await r.json();if(d.error){msg.textContent=d.error;msg.className='n-login-msg error';btn.disabled=false;btn.textContent='{$j_signin}'}else{msg.innerHTML='&#10003; {$j_welcome}';msg.className='n-login-msg success';setTimeout(()=>window.location.href='{$app_url}'+('{$app_url}'.includes('?')?'&':'?')+'_='+Date.now(),500)}}catch(e){msg.textContent='{$j_wrong}';msg.className='n-login-msg error';btn.disabled=false;btn.textContent='{$j_signin}'}}
function nB64uToBuf(s){s=s.replace(/-/g,'+').replace(/_/g,'/');while(s.length%4)s+='=';const r=atob(s),a=new Uint8Array(r.length);for(let i=0;i<r.length;i++)a[i]=r.charCodeAt(i);return a.buffer;}
function nBufToB64u(b){let s='';const a=new Uint8Array(b);for(let i=0;i<a.length;i++)s+=String.fromCharCode(a[i]);return btoa(s).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+\$/,'');}
async function nPasskey(){const msg=document.getElementById('nLoginMsg');const email=(document.getElementById('nEmail').value||'').trim();try{msg.textContent='{$j_passkeywait}';msg.className='n-login-msg';const o=await(await fetch('{$pk_opts_api}',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({email:email})})).json();const pub={challenge:nB64uToBuf(o.challenge),rpId:o.rpId,timeout:o.timeout,userVerification:o.userVerification,allowCredentials:(o.allowCredentials||[]).map(function(c){return{type:'public-key',id:nB64uToBuf(c.id)};})};const cred=await navigator.credentials.get({publicKey:pub});const body={handle:o.handle,id:cred.id,clientDataJSON:nBufToB64u(cred.response.clientDataJSON),authenticatorData:nBufToB64u(cred.response.authenticatorData),signature:nBufToB64u(cred.response.signature)};const d=await(await fetch('{$pk_auth_api}',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify(body)})).json();if(d&&d.success){msg.innerHTML='&#10003; {$j_welcome}';msg.className='n-login-msg success';setTimeout(function(){window.location.href='{$app_url}'+('{$app_url}'.includes('?')?'&':'?')+'_='+Date.now();},400);}else{msg.textContent=(d&&d.error)||'{$j_passkeyfail}';msg.className='n-login-msg error';}}catch(e){if(e&&e.name==='NotAllowedError'){msg.textContent='';}else{msg.textContent='{$j_passkeyfail}';msg.className='n-login-msg error';}}}
if(window.PublicKeyCredential){var _pkb=document.getElementById('nPasskeyBtn');if(_pkb)_pkb.style.display='block';}
document.addEventListener('keydown',e=>{if(e.key==='Enter'){const pin=document.getElementById('nStepPin');if(pin&&pin.style.display!=='none')nVerifyPin();else if(document.getElementById('nStepEmail').style.display!=='none')nSendPin()}else if(e.key==='Escape'){const open=document.querySelectorAll('.n-login-overlay.show');if(open.length){open.forEach(o=>o.classList.remove('show'));if(_nLoginReturn){try{_nLoginReturn.focus();}catch(_){}_nLoginReturn=null;}}}});
// Expired one-click link → open login modal, pre-fill the email so they don't retype it.
(function(){const q=new URLSearchParams(location.search);if(q.get('login')==='expired'){nOpenLogin();const em=q.get('e');if(em){_nEmail=em;const ne=document.getElementById('nEmail');if(ne)ne.value=em;const pe=document.getElementById('nPinEmail');if(pe)pe.value=em;}const m=document.getElementById('nLoginMsg');if(m){m.textContent='{$j_expired}';m.className='n-login-msg error';}}})();
// Inject login button into page nav (ghost style, left of Get Noodled)
(function(){
  const cta=document.querySelector('.nav-cta');
  if(cta){
    const a=document.createElement('button');
    a.type='button';
    a.textContent='{$j_login}';
    a.className='btn btn--ghost';
    a.onclick=function(e){e.preventDefault();nOpenLogin();};
    cta.parentNode.insertBefore(a,cta);
    cta.style.marginLeft='8px';
  }
  document.querySelectorAll('.noodled-open-login').forEach(el=>{el.style.cursor='pointer';el.addEventListener('click',e=>{e.preventDefault();nOpenLogin()})});
})();
</script>
HTML;

		$request_inject = <<<HTML
<!-- Noodled "Get a noodle" — inline form styles -->
<style>
.ngn-form{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;align-items:center;max-width:540px;margin:0 auto}
.ngn-input{flex:1 1 180px;min-width:0;padding:13px 16px;border:1px solid rgba(0,0,0,0.16);border-radius:10px;background:#fff;color:#1A1A1A;font-size:16px;font-family:inherit;outline:none}
.ngn-input::placeholder{color:#767676}
.ngn-input:focus{border-color:{$accent};box-shadow:0 0 0 3px rgba(0,120,212,0.16)}
.ngn-form .btn{flex:0 0 auto}
.ngn-msg{flex-basis:100%;text-align:center;margin-top:6px;font-size:14px;min-height:20px}
.ngn-msg.error{color:#b91c1c}
.ngn-msg.success{color:#15803d;font-weight:600}
</style>
<!-- Noodled "Get a noodle" Request Overlay -->
<div class="n-login-overlay" id="nReqOverlay" role="dialog" aria-modal="true" aria-labelledby="nReqTitle" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="n-login-box">
    <div id="nReqForm">
      <h3 id="nReqTitle">{$l_getnoodle}</h3>
      <div class="sub">{$l_reqsub}</div>
      <input class="n-login-input" type="text" id="nReqName" placeholder="{$a_yourname}" aria-label="{$a_yourname}">
      <input class="n-login-input" type="email" id="nReqEmail" placeholder="you@example.com" aria-label="{$a_email}">
      <button class="n-login-btn" id="nReqBtn" onclick="nRequestNoodle()">{$l_reqbtn}</button>
      <button type="button" class="n-login-link" onclick="document.getElementById('nReqOverlay').classList.remove('show');document.getElementById('nLoginOverlay').classList.add('show')">{$l_havelogin}</button>
    </div>
    <div class="n-login-msg" id="nReqMsg"></div>
  </div>
</div>
<script>
async function nRequestNoodle(){
  const name=document.getElementById('nReqName').value.trim();
  const email=document.getElementById('nReqEmail').value.trim();
  const msg=document.getElementById('nReqMsg');
  if(!email){msg.textContent='{$j_enteremail}';msg.className='n-login-msg error';return;}
  const btn=document.getElementById('nReqBtn');btn.disabled=true;btn.textContent='{$j_sending}';
  try{
    const r=await fetch('{$request_api}',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,email})});
    const d=await r.json();
    if(d.error){msg.textContent=d.error;msg.className='n-login-msg error';btn.disabled=false;btn.textContent='{$j_reqbtn}';}
    else{document.getElementById('nReqForm').style.display='none';msg.innerHTML='&#10003; '+(d.message||'{$j_reqrecv}');msg.className='n-login-msg success';}
  }catch(e){msg.textContent='{$j_wrong}';msg.className='n-login-msg error';btn.disabled=false;btn.textContent='{$j_reqbtn}';}
}
// Inline "Get a noodle" form (in the final CTA section)
async function nGetNoodle(e){
  e.preventDefault();
  const name=document.getElementById('nGetName').value.trim();
  const email=document.getElementById('nGetEmail').value.trim();
  const msg=document.getElementById('nGetMsg');
  if(!email){msg.textContent='{$j_enteremail}';msg.className='ngn-msg error';return false;}
  const btn=document.getElementById('nGetBtn');btn.disabled=true;btn.textContent='{$j_sending}';
  try{
    const r=await fetch('{$request_api}',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,email})});
    const d=await r.json();
    if(d.error){msg.textContent=d.error;msg.className='ngn-msg error';btn.disabled=false;btn.textContent='{$j_getnoodle}';}
    else{document.getElementById('nGetForm').style.display='none';msg.innerHTML='&#10003; '+(d.message||'{$j_reqrecv}');msg.className='ngn-msg success';}
  }catch(err){msg.textContent='{$j_wrong}';msg.className='ngn-msg error';btn.disabled=false;btn.textContent='{$j_getnoodle}';}
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
          <input type="text" id="nGetName" class="ngn-input" placeholder="{$a_yourname}" aria-label="{$a_yourname}" autocomplete="name">
          <input type="email" id="nGetEmail" class="ngn-input" placeholder="you@example.com" aria-label="{$a_email}" autocomplete="email" required>
          <button type="submit" class="btn btn--lg" id="nGetBtn">{$l_getnoodle}</button>
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

		// Footer Privacy / Terms links → real legal pages (were dead href="#")
		$privacy_url = esc_url( add_query_arg( 'noodled_legal', 'privacy', self::get_app_url() ) );
		$terms_url   = esc_url( add_query_arg( 'noodled_legal', 'terms', self::get_app_url() ) );
		$html = str_replace(
			[ '<a href="#">Privacy</a>', '<a href="#">Terms</a>' ],
			[ '<a href="' . $privacy_url . '">Privacy</a>', '<a href="' . $terms_url . '">Terms</a>' ],
			$html
		);

		// The demo note-editor mockup uses a second <h1> (.ed-title) for its fake
		// note title. Two <h1>s hurt SEO/structure, so demote it to a styled
		// heading-role div (keeps the look, fixes the duplicate top-level heading).
		$html = preg_replace(
			'#<h1(\s+class="ed-title"[^>]*)>(.*?)</h1>#s',
			'<div$1 role="heading" aria-level="2">$2</div>',
			$html
		);

		// Admin "view marketing site" preview: a floating Back-to-app button so the
		// admin can pop out of the public landing page and return to the app.
		$back_inject = '';
		if ( $preview ) {
			$app_back = esc_url( self::get_app_url() );
			$back_lbl = esc_html__( 'Back to app', 'noodled' );
			$close_lbl = esc_attr__( 'Close preview', 'noodled' );
			$back_inject = '<a href="' . $app_back . '" aria-label="' . $close_lbl . '" '
				. 'style="position:fixed;top:16px;right:16px;z-index:2147483000;display:inline-flex;align-items:center;gap:8px;'
				. 'background:#1a1a1f;color:#fff;border:1px solid #33333f;border-radius:999px;padding:11px 18px;'
				. 'font:600 14px/1 system-ui,-apple-system,sans-serif;text-decoration:none;box-shadow:0 6px 20px rgba(0,0,0,.45)">'
				. '<span aria-hidden="true">&#10005;</span> ' . $back_lbl . '</a>';
		}

		$html = str_ireplace( '</body>', $login_inject . $request_inject . $back_inject . "\n</body>", $html );
		echo $html;
	}

	/* ── Public legal pages (Privacy / Terms) ──
	   Stub copy an admin can replace; reachable at ?noodled_legal=privacy|terms. */

	private static function serve_legal( string $which ): void {
		self::send_no_cache_headers();
		$brand  = Noodled_Settings::get_brand_name();
		$accent = Noodled_Settings::get_accent_color();
		$home   = esc_url( self::get_app_url() );
		$email  = sanitize_email( get_option( 'admin_email' ) );

		if ( $which === 'terms' ) {
			$h1    = __( 'Terms of Service', 'noodled' );
			$intro = sprintf( __( 'By using %s you agree to these terms. Please read them carefully.', 'noodled' ), $brand );
			$sections = [
				[ __( 'Your account', 'noodled' ), __( 'You are responsible for keeping your sign-in details secure and for activity that happens under your account. Tell us right away if you think someone else has access to it.', 'noodled' ) ],
				[ __( 'Acceptable use', 'noodled' ), __( 'Do not use the service to store or share unlawful content, to infringe anyone else\'s rights, or to disrupt the service for other people.', 'noodled' ) ],
				[ __( 'Your content', 'noodled' ), __( 'Your notes, notebooks, and attachments stay yours. You grant us only the permission needed to store, process, and display that content back to you so the service can work.', 'noodled' ) ],
				[ __( 'Availability', 'noodled' ), __( 'The service is provided "as is", without warranties of any kind. We may add, change, or remove features, and we cannot guarantee the service will always be available or error-free.', 'noodled' ) ],
				[ __( 'Termination', 'noodled' ), __( 'You may stop using the service at any time. We may suspend or close accounts that break these terms.', 'noodled' ) ],
			];
		} else {
			$which = 'privacy';
			$h1    = __( 'Privacy Policy', 'noodled' );
			$intro = sprintf( __( '%s is a private note-taking service. This policy explains what we store and how we use it.', 'noodled' ), $brand );
			$sections = [
				[ __( 'What we store', 'noodled' ), __( 'Your account email and the notes, notebooks, and attachments you create. That content is private to your account by default.', 'noodled' ) ],
				[ __( 'How we use it', 'noodled' ), __( 'Only to provide the service to you. We do not sell your data or share it with third parties for advertising.', 'noodled' ) ],
				[ __( 'Email', 'noodled' ), __( 'We email you sign-in PINs and any notifications you choose to receive, such as when a notebook is shared with you.', 'noodled' ) ],
				[ __( 'Security', 'noodled' ), __( 'Notes are visible only to your account and the people you share them with. Attachments are served only to sessions allowed to read the note they belong to.', 'noodled' ) ],
				[ __( 'Your control', 'noodled' ), __( 'You can edit or delete your notes at any time, and you can ask us to remove your account and its data.', 'noodled' ) ],
			];
		}

		$title    = esc_html( sprintf( __( '%1$s — %2$s', 'noodled' ), $h1, $brand ) );
		$h1_esc   = esc_html( $h1 );
		$intro_esc = esc_html( $intro );
		$back     = esc_html( sprintf( __( 'Back to %s', 'noodled' ), $brand ) );
		$copy     = esc_html( sprintf( __( '© %1$s %2$s', 'noodled' ), date_i18n( 'Y' ), $brand ) );
		$lang     = esc_attr( get_bloginfo( 'language' ) );
		$accent_a = esc_attr( $accent );

		$body = '';
		foreach ( $sections as $s ) {
			$body .= '<h2>' . esc_html( $s[0] ) . "</h2>\n<p>" . esc_html( $s[1] ) . "</p>\n";
		}

		$contact_block = '';
		if ( $email ) {
			$link = '<a style="color:' . $accent_a . '" href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
			/* translators: %s: contact email address (rendered as a mailto link) */
			$contact_block = '<p>' . wp_kses(
				sprintf( __( 'Questions about this policy? Contact us at %s.', 'noodled' ), $link ),
				[ 'a' => [ 'href' => [], 'style' => [] ] ]
			) . '</p>';
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		echo <<<HTML
<!doctype html>
<html lang="$lang">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$title</title>
<style>
:root{--accent:$accent_a}
*{box-sizing:border-box}
body{margin:0;background:#15151a;color:#d6d6e0;font:16px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
main{max-width:680px;margin:0 auto;padding:56px 22px 80px}
a.back{display:inline-block;margin-bottom:28px;color:var(--accent);text-decoration:none;font-size:14px}
a.back:hover,a.back:focus-visible{text-decoration:underline}
h1{color:#fff;font-size:30px;margin:0 0 6px;font-family:Fraunces,Georgia,serif}
.intro{color:#a9a9b8;margin:0 0 32px}
h2{color:#fff;font-size:18px;margin:30px 0 6px}
p{margin:0 0 14px}
footer{margin-top:40px;padding-top:18px;border-top:1px solid #2a2a35;color:#8a8a99;font-size:13.5px}
a:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:3px}
</style>
</head>
<body>
<main>
<a class="back" href="$home">&larr; $back</a>
<h1>$h1_esc</h1>
<p class="intro">$intro_esc</p>
$body
<footer>
$contact_block
<p>$copy</p>
</footer>
</main>
</body>
</html>
HTML;
	}
}
