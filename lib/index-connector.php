<?php
/**
 * Calluna Index Connector — Kunden-Feedback-Overlay + REST-Bridge für die
 * Calluna-Index-Konsole. Theme-unabhängig (läuft über die Companion-App auf
 * JEDER Kundenseite). Speicherung als CPT `reise_feedback`, REST unter `reise/v1`.
 *
 * Doppel-Load-Schutz: greift nur, wenn die Funktionen nicht bereits (z. B. durch
 * ein Theme) definiert sind.
 *
 * @package CallunaCompanion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'reise_rest_site' ) ) :

	if ( ! defined( 'REISE_FB_CPT' ) )      define( 'REISE_FB_CPT', 'reise_feedback' );
	if ( ! defined( 'REISE_FB_STATES' ) )   define( 'REISE_FB_STATES', array( 'offen' => 'Offen', 'arbeit' => 'In Arbeit', 'erledigt' => 'Erledigt' ) );
	if ( ! defined( 'REISE_FB_KINDS' ) )    define( 'REISE_FB_KINDS', array( 'wunsch' => '💬 Änderung / Wunsch', 'idee' => '💡 Idee', 'fehler' => '🐞 Fehler' ) );
	if ( ! defined( 'REISE_FB_SHOT_MAX' ) ) define( 'REISE_FB_SHOT_MAX', 3000000 );

	/* ==========================================================================
	   Custom Post Type
	   ========================================================================== */
	add_action( 'init', function () {
		register_post_type( REISE_FB_CPT, array(
			'labels'          => array(
				'name'          => 'Feedback',
				'singular_name' => 'Feedback',
				'menu_name'     => 'Feedback',
				'all_items'     => 'Alle Einträge',
				'edit_item'     => 'Feedback ansehen',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'menu_position'   => 26,
			'menu_icon'       => 'dashicons-feedback',
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'supports'        => array( 'title', 'editor', 'author' ),
			'hierarchical'    => false,
		) );
	} );

	/* ==========================================================================
	   Einstellungen: Index-Token + Variante (Settings → Calluna Index)
	   ========================================================================== */
	add_action( 'admin_menu', function () {
		add_options_page( 'Calluna Index', 'Calluna Index', 'manage_options', 'calluna-index', 'reise_index_settings_page' );
	} );
	add_action( 'admin_init', function () {
		register_setting( 'calluna_index', 'reise_index_token' );
		register_setting( 'calluna_index', 'reise_variant' );
	} );
	function reise_index_settings_page() {
		echo '<div class="wrap"><h1>Calluna Index</h1><p>Verbindet diese Seite mit der Calluna-Index-Konsole (Feedback, Marke, Betrieb).</p><form method="post" action="options.php">';
		settings_fields( 'calluna_index' );
		echo '<table class="form-table">';
		echo '<tr><th scope="row"><label for="reise_index_token">Index-Token</label></th><td><input type="text" id="reise_index_token" name="reise_index_token" value="' . esc_attr( get_option( 'reise_index_token', '' ) ) . '" class="regular-text" style="width:440px"><p class="description">Gemeinsames Token aus der Index-Konsole (Header <code>X-Calluna-Index</code>).</p></td></tr>';
		echo '<tr><th scope="row"><label for="reise_variant">Variante</label></th><td><input type="text" id="reise_variant" name="reise_variant" value="' . esc_attr( get_option( 'reise_variant', 'wp' ) ) . '" class="regular-text"><p class="description">wp oder astro.</p></td></tr>';
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	/* ==========================================================================
	   Frontend: Overlay ausgeben (nur eingeloggt)
	   ========================================================================== */
	add_action( 'wp_footer', function () {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$u   = wp_get_current_user();
		$cfg = array(
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'reise_fb' ),
			'user'    => $u->display_name,
			'isAdmin' => current_user_can( 'edit_posts' ),
			'url'     => ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' ),
			'title'   => wp_get_document_title(),
			'kinds'   => REISE_FB_KINDS,
			'states'  => REISE_FB_STATES,
		);
		?>
		<style id="reise-fb-css">
		#reise-fb-btn{position:fixed;right:20px;bottom:20px;z-index:99998;display:flex;align-items:center;gap:8px;height:46px;padding:0 18px;border:0;border-radius:999px;background:var(--ink,#0E1C17);color:#fff;font:600 14px/1 var(--fb,system-ui,sans-serif);letter-spacing:.02em;cursor:pointer;box-shadow:0 10px 26px -10px rgba(0,0,0,.55);transition:transform .12s ease}
		#reise-fb-btn:hover{transform:translateY(-2px)}
		#reise-fb-btn b{color:var(--gold,#BD9403);font-weight:700}
		#reise-fb-ov{position:fixed;inset:0;z-index:99999;display:none;align-items:flex-end;justify-content:flex-end;background:rgba(8,14,11,.42);padding:20px}
		#reise-fb-ov.on{display:flex}
		#reise-fb-panel{width:100%;max-width:420px;max-height:86vh;overflow:auto;background:#fff;border-radius:10px;box-shadow:0 30px 70px -20px rgba(0,0,0,.5);font:14px/1.5 var(--fb,system-ui,sans-serif);color:var(--ink,#16231d)}
		.reise-fb-hd{display:flex;align-items:center;gap:8px;padding:16px 18px;background:var(--ink,#0E1C17);color:#fff;border-radius:10px 10px 0 0}
		.reise-fb-hd b{font:700 16px/1 var(--fd,var(--fb,serif));letter-spacing:.01em}
		.reise-fb-hd .x{margin-left:auto;background:none;border:0;color:rgba(255,255,255,.7);font-size:22px;line-height:1;cursor:pointer}
		.reise-fb-bd{padding:16px 18px}
		.reise-fb-tabs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-bottom:12px}
		.reise-fb-tabs button{padding:8px 4px;border:1px solid #d7dbd8;border-radius:6px;background:#fff;color:#3c463f;font-size:12.5px;cursor:pointer}
		.reise-fb-tabs button.on{border-color:var(--gold,#BD9403);background:color-mix(in srgb,var(--gold,#BD9403) 12%,#fff);color:var(--ink,#0E1C17);font-weight:600}
		#reise-fb-msg{width:100%;min-height:96px;padding:10px 12px;border:1px solid #d7dbd8;border-radius:6px;font:inherit;resize:vertical;box-sizing:border-box}
		#reise-fb-msg:focus{outline:none;border-color:var(--gold,#BD9403)}
		.reise-fb-drop{margin-top:10px;border:2px dashed #d7dbd8;border-radius:6px;background:#f6f7f6;padding:12px;text-align:center;font-size:12px;color:#6a746d}
		.reise-fb-drop a{color:var(--gold,#8E345C);cursor:pointer;text-decoration:underline}
		.reise-fb-shot{position:relative;margin-top:10px}
		.reise-fb-shot img{width:100%;max-height:150px;object-fit:cover;border-radius:6px;display:block}
		.reise-fb-shot button{position:absolute;top:6px;right:6px;background:rgba(0,0,0,.6);color:#fff;border:0;border-radius:4px;padding:3px 8px;font-size:11px;cursor:pointer}
		.reise-fb-meta{margin-top:8px;font-size:11px;color:#8a938c;word-break:break-all}
		.reise-fb-send{margin-top:12px;width:100%;padding:12px;border:0;border-radius:6px;background:var(--gold,#BD9403);color:#fff;font-weight:700;font-size:14px;cursor:pointer}
		.reise-fb-send:disabled{opacity:.5;cursor:default}
		.reise-fb-list{border-top:1px solid #eceeec;margin-top:16px;padding-top:12px}
		.reise-fb-list h4{margin:0 0 8px;font:600 11px/1 var(--fb);text-transform:uppercase;letter-spacing:.08em;color:#8a938c}
		.reise-fb-item{border:1px solid #eceeec;border-radius:6px;padding:10px 12px;margin-bottom:8px}
		.reise-fb-item .r{display:flex;align-items:center;gap:6px;font-size:11px;color:#8a938c;margin-bottom:4px}
		.reise-fb-item .r .s{margin-left:auto;font-weight:700;padding:2px 8px;border-radius:999px;font-size:10px}
		.s-offen{background:#fdecec;color:#b23b3b}.s-arbeit{background:#fdf3e0;color:#8a5a10}.s-erledigt{background:#e7f4ec;color:#2e7d47}
		.reise-fb-item p{margin:0;font-size:13px;color:#2c352f}
		.reise-fb-item .st{margin-top:6px;display:flex;gap:4px}
		.reise-fb-item .st button{font-size:10.5px;padding:3px 8px;border:1px solid #d7dbd8;background:#fff;border-radius:4px;cursor:pointer}
		.reise-fb-empty{font-size:12px;color:#8a938c}
		</style>
		<button id="reise-fb-btn" type="button" aria-haspopup="dialog">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
			<span>Feedback</span>
		</button>
		<div id="reise-fb-ov" role="dialog" aria-modal="true" aria-label="Feedback geben">
			<div id="reise-fb-panel">
				<div class="reise-fb-hd"><b>Änderung &amp; Wunsch</b><button class="x" type="button" aria-label="Schließen">&times;</button></div>
				<div class="reise-fb-bd">
					<div class="reise-fb-tabs" id="reise-fb-tabs"></div>
					<textarea id="reise-fb-msg" placeholder="Was soll geändert werden? Beschreibe deinen Wunsch möglichst konkret …"></textarea>
					<div id="reise-fb-shotwrap"></div>
					<div class="reise-fb-drop" id="reise-fb-drop">Screenshot hierher ziehen, <b>Strg/Cmd+V</b> einfügen oder <a id="reise-fb-pick">auswählen</a><input type="file" id="reise-fb-file" accept="image/png,image/jpeg,image/webp" hidden></div>
					<div class="reise-fb-meta" id="reise-fb-page"></div>
					<button class="reise-fb-send" id="reise-fb-send" type="button">Absenden</button>
					<div class="reise-fb-list"><h4>Bisherige Einträge</h4><div id="reise-fb-items"><div class="reise-fb-empty">Wird geladen …</div></div></div>
				</div>
			</div>
		</div>
		<script>
		(function(){
			var CFG = <?php echo wp_json_encode( $cfg ); ?>;
			var kind = 'wunsch', shot = null;
			var $ = function(id){return document.getElementById(id);};
			var esc = function(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});};
			var tabs = $('reise-fb-tabs');
			Object.keys(CFG.kinds).forEach(function(k){
				var b=document.createElement('button'); b.textContent=CFG.kinds[k]; b.dataset.k=k;
				if(k===kind)b.className='on';
				b.onclick=function(){kind=k;[].forEach.call(tabs.children,function(c){c.className=c.dataset.k===k?'on':'';});};
				tabs.appendChild(b);
			});
			$('reise-fb-page').textContent = '📄 ' + CFG.title;
			var ov=$('reise-fb-ov');
			function open(){ov.classList.add('on');load();}
			function close(){ov.classList.remove('on');}
			$('reise-fb-btn').onclick=open;
			ov.querySelector('.x').onclick=close;
			ov.addEventListener('click',function(e){if(e.target===ov)close();});
			document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
			function setShot(dataUrl){
				shot=dataUrl; var w=$('reise-fb-shotwrap');
				if(!dataUrl){w.innerHTML='';return;}
				w.innerHTML='<div class="reise-fb-shot"><img alt="Screenshot"><button type="button">× entfernen</button></div>';
				w.querySelector('img').src=dataUrl;
				w.querySelector('button').onclick=function(){setShot(null);};
			}
			function readFile(f){
				if(!f||!/^image\/(png|jpeg|webp)$/.test(f.type))return;
				if(f.size>2500000){alert('Bild zu groß (max ~2,5 MB).');return;}
				var r=new FileReader();r.onload=function(){setShot(r.result);};r.readAsDataURL(f);
			}
			$('reise-fb-pick').onclick=function(){$('reise-fb-file').click();};
			$('reise-fb-file').onchange=function(e){readFile(e.target.files[0]);};
			var drop=$('reise-fb-drop');
			drop.addEventListener('dragover',function(e){e.preventDefault();});
			drop.addEventListener('drop',function(e){e.preventDefault();readFile(e.dataTransfer.files[0]);});
			document.addEventListener('paste',function(e){
				if(!ov.classList.contains('on'))return;
				var it=(e.clipboardData||{}).items||[];
				for(var i=0;i<it.length;i++){if(it[i].type&&it[i].type.indexOf('image')===0){readFile(it[i].getAsFile());break;}}
			});
			var send=$('reise-fb-send');
			send.onclick=function(){
				var msg=$('reise-fb-msg').value.trim();
				if(!msg){$('reise-fb-msg').focus();return;}
				send.disabled=true;send.textContent='Sende …';
				var fd=new FormData();
				fd.append('action','reise_fb_submit');fd.append('nonce',CFG.nonce);
				fd.append('kind',kind);fd.append('message',msg);
				fd.append('url',CFG.url);fd.append('ptitle',CFG.title);
				if(shot)fd.append('shot',shot);
				fetch(CFG.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
					send.disabled=false;send.textContent='Absenden';
					if(!d||!d.success){alert((d&&d.data)||'Fehler beim Senden.');return;}
					$('reise-fb-msg').value='';setShot(null);load();
				}).catch(function(){send.disabled=false;send.textContent='Absenden';alert('Netzwerkfehler.');});
			};
			function load(){
				var box=$('reise-fb-items');
				var fd=new FormData();fd.append('action','reise_fb_list');fd.append('nonce',CFG.nonce);
				fetch(CFG.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
					var items=(d&&d.data)||[];
					if(!items.length){box.innerHTML='<div class="reise-fb-empty">Noch keine Einträge.</div>';return;}
					box.innerHTML=items.map(function(it){
						var st='';
						if(CFG.isAdmin){st='<div class="st">'+Object.keys(CFG.states).map(function(s){
							return '<button data-id="'+it.id+'" data-s="'+s+'"'+(s===it.status?' style="font-weight:700"':'')+'>'+CFG.states[s]+'</button>';}).join('')+'</div>';}
						return '<div class="reise-fb-item"><div class="r"><span>'+esc(CFG.kinds[it.kind]||it.kind)+'</span><span>· '+esc(it.author)+'</span><span class="s s-'+esc(it.status)+'">'+esc(CFG.states[it.status]||it.status)+'</span></div><p>'+esc(it.message)+'</p>'+st+'</div>';
					}).join('');
					if(CFG.isAdmin){[].forEach.call(box.querySelectorAll('.st button'),function(b){b.onclick=function(){
						var fd2=new FormData();fd2.append('action','reise_fb_status');fd2.append('nonce',CFG.nonce);
						fd2.append('id',b.dataset.id);fd2.append('status',b.dataset.s);
						fetch(CFG.ajax,{method:'POST',body:fd2,credentials:'same-origin'}).then(function(r){return r.json();}).then(load);
					};});}
				});
			}
		})();
		</script>
		<?php
	} );

	/* ==========================================================================
	   AJAX: Absenden
	   ========================================================================== */
	add_action( 'wp_ajax_reise_fb_submit', function () {
		check_ajax_referer( 'reise_fb', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Nicht angemeldet.', 403 );
		}
		$kind = isset( $_POST['kind'] ) ? sanitize_key( $_POST['kind'] ) : 'wunsch';
		if ( ! isset( REISE_FB_KINDS[ $kind ] ) ) {
			$kind = 'wunsch';
		}
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		if ( '' === trim( $message ) ) {
			wp_send_json_error( 'Bitte einen Text eingeben.' );
		}
		$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$ptitle = isset( $_POST['ptitle'] ) ? sanitize_text_field( wp_unslash( $_POST['ptitle'] ) ) : '';

		$post_id = wp_insert_post( array(
			'post_type'    => REISE_FB_CPT,
			'post_status'  => 'publish',
			'post_author'  => get_current_user_id(),
			'post_title'   => wp_trim_words( $message, 10, '…' ),
			'post_content' => $message,
		), true );
		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( 'Speichern fehlgeschlagen.' );
		}
		update_post_meta( $post_id, '_fb_kind', $kind );
		update_post_meta( $post_id, '_fb_status', 'offen' );
		update_post_meta( $post_id, '_fb_url', $url );
		update_post_meta( $post_id, '_fb_ptitle', $ptitle );

		if ( ! empty( $_POST['shot'] ) ) {
			$shot = wp_unslash( $_POST['shot'] );
			if ( strlen( $shot ) <= REISE_FB_SHOT_MAX
				&& preg_match( '#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=\s]+$#', $shot ) ) {
				update_post_meta( $post_id, '_fb_shot', $shot );
			}
		}
		wp_send_json_success( array( 'id' => $post_id ) );
	} );

	/* ==========================================================================
	   AJAX: Liste (alle eingeloggten sehen alle Einträge)
	   ========================================================================== */
	add_action( 'wp_ajax_reise_fb_list', function () {
		check_ajax_referer( 'reise_fb', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Nicht angemeldet.', 403 );
		}
		$q   = new WP_Query( array(
			'post_type'      => REISE_FB_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = array(
				'id'      => $p->ID,
				'kind'    => get_post_meta( $p->ID, '_fb_kind', true ) ?: 'wunsch',
				'status'  => get_post_meta( $p->ID, '_fb_status', true ) ?: 'offen',
				'message' => $p->post_content,
				'author'  => get_the_author_meta( 'display_name', $p->post_author ),
				'date'    => get_the_date( 'd.m.Y', $p ),
			);
		}
		wp_send_json_success( $out );
	} );

	/* ==========================================================================
	   AJAX: Status ändern (nur Redakteure/Admins)
	   ========================================================================== */
	add_action( 'wp_ajax_reise_fb_status', function () {
		check_ajax_referer( 'reise_fb', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Keine Berechtigung.', 403 );
		}
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '';
		if ( ! $id || ! isset( REISE_FB_STATES[ $status ] ) || get_post_type( $id ) !== REISE_FB_CPT ) {
			wp_send_json_error( 'Ungültig.' );
		}
		update_post_meta( $id, '_fb_status', $status );
		wp_send_json_success();
	} );

	/* ==========================================================================
	   Backend: Übersichts-Spalten + Detail-Metabox
	   ========================================================================== */
	add_filter( 'manage_' . REISE_FB_CPT . '_posts_columns', function ( $cols ) {
		return array(
			'cb'        => $cols['cb'] ?? '',
			'title'     => 'Feedback',
			'fb_kind'   => 'Typ',
			'fb_status' => 'Status',
			'fb_page'   => 'Seite',
			'author'    => 'Von',
			'date'      => 'Datum',
		);
	} );
	add_action( 'manage_' . REISE_FB_CPT . '_posts_custom_column', function ( $col, $post_id ) {
		if ( 'fb_kind' === $col ) {
			echo esc_html( REISE_FB_KINDS[ get_post_meta( $post_id, '_fb_kind', true ) ] ?? '—' );
		} elseif ( 'fb_status' === $col ) {
			$s = get_post_meta( $post_id, '_fb_status', true ) ?: 'offen';
			echo '<strong>' . esc_html( REISE_FB_STATES[ $s ] ?? $s ) . '</strong>';
		} elseif ( 'fb_page' === $col ) {
			$u = get_post_meta( $post_id, '_fb_url', true );
			$t = get_post_meta( $post_id, '_fb_ptitle', true );
			if ( $u ) {
				echo '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener">' . esc_html( $t ?: $u ) . '</a>';
			}
		}
	}, 10, 2 );

	add_action( 'add_meta_boxes', function () {
		add_meta_box( 'reise_fb_detail', 'Feedback-Details', function ( $post ) {
			$kind = get_post_meta( $post->ID, '_fb_kind', true );
			$stat = get_post_meta( $post->ID, '_fb_status', true ) ?: 'offen';
			$url  = get_post_meta( $post->ID, '_fb_url', true );
			$shot = get_post_meta( $post->ID, '_fb_shot', true );
			wp_nonce_field( 'reise_fb_meta', 'reise_fb_meta_nonce' );
			echo '<p><strong>Typ:</strong> ' . esc_html( REISE_FB_KINDS[ $kind ] ?? '—' ) . '</p>';
			echo '<p><label for="reise_fb_status"><strong>Status:</strong></label> <select name="reise_fb_status" id="reise_fb_status">';
			foreach ( REISE_FB_STATES as $k => $lbl ) {
				echo '<option value="' . esc_attr( $k ) . '" ' . selected( $stat, $k, false ) . '>' . esc_html( $lbl ) . '</option>';
			}
			echo '</select></p>';
			if ( $url ) {
				echo '<p><strong>Seite:</strong> <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a></p>';
			}
			$gh = get_post_meta( $post->ID, '_fb_gh_url', true );
			if ( $gh ) {
				echo '<p><strong>GitHub:</strong> <a href="' . esc_url( $gh ) . '" target="_blank" rel="noopener">Issue #' . (int) get_post_meta( $post->ID, '_fb_gh_num', true ) . '</a></p>';
			}
			if ( $shot && preg_match( '#^data:image/(png|jpeg|webp);base64,#', $shot ) ) {
				echo '<p><strong>Screenshot:</strong></p><img src="' . esc_attr( $shot ) . '" style="max-width:100%;height:auto;border:1px solid #ddd;border-radius:4px">';
			}
		}, REISE_FB_CPT, 'side' );
	} );

	add_action( 'save_post_' . REISE_FB_CPT, function ( $post_id ) {
		if ( ! isset( $_POST['reise_fb_meta_nonce'] ) || ! wp_verify_nonce( $_POST['reise_fb_meta_nonce'], 'reise_fb_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['reise_fb_status'] ) ) {
			$s = sanitize_key( $_POST['reise_fb_status'] );
			if ( isset( REISE_FB_STATES[ $s ] ) ) {
				update_post_meta( $post_id, '_fb_status', $s );
			}
		}
	} );

	/* ==========================================================================
	   REST-API für die Calluna-Index-Konsole (Token-geschützt).
	   Auth: Header X-Calluna-Index === Option reise_index_token.
	   ========================================================================== */
	function reise_index_auth( WP_REST_Request $request ) {
		$tok = (string) get_option( 'reise_index_token', '' );
		if ( '' === $tok ) {
			return new WP_Error( 'reise_no_token', 'Index-Token nicht konfiguriert.', array( 'status' => 403 ) );
		}
		$hdr = (string) $request->get_header( 'x-calluna-index' );
		if ( '' !== $hdr && hash_equals( $tok, $hdr ) ) {
			return true;
		}
		return new WP_Error( 'reise_forbidden', 'Ungültiges Index-Token.', array( 'status' => 401 ) );
	}

	function reise_fb_counts() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.meta_value AS s, COUNT(*) AS c FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_fb_status' AND p.post_type = %s AND p.post_status = 'publish'
			 GROUP BY pm.meta_value", REISE_FB_CPT ) );
		$out = array( 'offen' => 0, 'arbeit' => 0, 'erledigt' => 0, 'total' => 0 );
		foreach ( (array) $rows as $r ) {
			if ( isset( $out[ $r->s ] ) ) {
				$out[ $r->s ] = (int) $r->c;
			}
			$out['total'] += (int) $r->c;
		}
		return $out;
	}

	add_action( 'rest_api_init', function () {
		$auth = 'reise_index_auth';
		register_rest_route( 'reise/v1', '/site', array( 'methods' => 'GET', 'callback' => 'reise_rest_site', 'permission_callback' => $auth ) );
		register_rest_route( 'reise/v1', '/feedback', array( 'methods' => 'GET', 'callback' => 'reise_rest_feedback', 'permission_callback' => $auth ) );
		register_rest_route( 'reise/v1', '/feedback/status', array( 'methods' => 'POST', 'callback' => 'reise_rest_feedback_status', 'permission_callback' => $auth ) );
		register_rest_route( 'reise/v1', '/noindex', array( 'methods' => 'POST', 'callback' => 'reise_rest_noindex', 'permission_callback' => $auth ) );
		register_rest_route( 'reise/v1', '/categories', array( 'methods' => 'POST', 'callback' => 'reise_rest_categories', 'permission_callback' => $auth ) );
		register_rest_route( 'reise/v1', '/feedback/github', array( 'methods' => 'POST', 'callback' => 'reise_rest_feedback_github', 'permission_callback' => $auth ) );
	} );

	function reise_rest_feedback_github( WP_REST_Request $r ) {
		$id  = absint( $r->get_param( 'id' ) );
		$url = esc_url_raw( (string) $r->get_param( 'url' ) );
		$num = absint( $r->get_param( 'number' ) );
		if ( ! $id || get_post_type( $id ) !== REISE_FB_CPT ) {
			return new WP_Error( 'reise_bad', 'Ungültig.', array( 'status' => 400 ) );
		}
		update_post_meta( $id, '_fb_gh_url', $url );
		update_post_meta( $id, '_fb_gh_num', $num );
		return array( 'ok' => true, 'url' => $url, 'number' => $num );
	}

	function reise_rest_categories( WP_REST_Request $r ) {
		$add     = (array) $r->get_param( 'add' );
		$remove  = (array) $r->get_param( 'remove' );
		$created = array();
		foreach ( $add as $c ) {
			$name = sanitize_text_field( is_array( $c ) ? ( $c['name'] ?? '' ) : $c );
			if ( '' === $name ) {
				continue;
			}
			$slug = sanitize_title( is_array( $c ) && ! empty( $c['slug'] ) ? $c['slug'] : $name );
			if ( ! term_exists( $slug, 'category' ) ) {
				wp_insert_term( $name, 'category', array( 'slug' => $slug ) );
				$created[] = $slug;
			}
		}
		$deleted = array();
		$default = (int) get_option( 'default_category' );
		foreach ( $remove as $slug ) {
			$t = get_term_by( 'slug', sanitize_title( $slug ), 'category' );
			if ( $t && $t->term_id !== $default && 0 === (int) $t->count ) {
				wp_delete_term( $t->term_id, 'category' );
				$deleted[] = $t->slug;
			}
		}
		return array( 'ok' => true, 'created' => $created, 'deleted' => $deleted );
	}

	function reise_rest_site() {
		$cats = array();
		foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) ) as $t ) {
			$cats[] = array( 'name' => $t->name, 'slug' => $t->slug );
		}
		$brand = array( 'colors' => array(), 'fonts' => array(), 'categories' => $cats );
		if ( function_exists( 'reise_brand' ) ) {
			$brand['colors'] = (array) reise_brand( 'colors', array() );
			$brand['fonts']  = (array) reise_brand( 'fonts', array() );
		}
		return array(
			'blog_public'   => (int) get_option( 'blog_public' ),
			'wp_version'    => get_bloginfo( 'version' ),
			'theme_version' => defined( 'REISE_VERSION' ) ? REISE_VERSION : '',
			'variant'       => (string) get_option( 'reise_variant', 'wp' ),
			'posts'         => (int) wp_count_posts()->publish,
			'feedback'      => reise_fb_counts(),
			'brand'         => $brand,
		);
	}

	function reise_rest_feedback() {
		$q   = new WP_Query( array(
			'post_type'      => REISE_FB_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = array(
				'id'       => $p->ID,
				'kind'     => get_post_meta( $p->ID, '_fb_kind', true ) ?: 'wunsch',
				'status'   => get_post_meta( $p->ID, '_fb_status', true ) ?: 'offen',
				'message'  => $p->post_content,
				'url'      => get_post_meta( $p->ID, '_fb_url', true ),
				'ptitle'   => get_post_meta( $p->ID, '_fb_ptitle', true ),
				'has_shot' => (bool) get_post_meta( $p->ID, '_fb_shot', true ),
				'author'   => get_the_author_meta( 'display_name', $p->post_author ),
				'date'     => get_the_date( 'd.m.Y H:i', $p ),
				'gh_url'   => get_post_meta( $p->ID, '_fb_gh_url', true ),
				'gh_num'   => (int) get_post_meta( $p->ID, '_fb_gh_num', true ),
			);
		}
		return $out;
	}

	function reise_rest_feedback_status( WP_REST_Request $r ) {
		$id     = absint( $r->get_param( 'id' ) );
		$status = sanitize_key( (string) $r->get_param( 'status' ) );
		if ( ! $id || ! isset( REISE_FB_STATES[ $status ] ) || get_post_type( $id ) !== REISE_FB_CPT ) {
			return new WP_Error( 'reise_bad', 'Ungültig.', array( 'status' => 400 ) );
		}
		update_post_meta( $id, '_fb_status', $status );
		return array( 'ok' => true, 'id' => $id, 'status' => $status );
	}

	function reise_rest_noindex( WP_REST_Request $r ) {
		$v = $r->get_param( 'value' );
		update_option( 'blog_public', $v ? 1 : 0 );
		return array( 'ok' => true, 'blog_public' => (int) get_option( 'blog_public' ) );
	}

endif;
