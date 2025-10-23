<?php
/**
 * Plugin Name: Expo Registro
 * Description: Registro con folio aleatorio + QR (verificación), correo mostrando QR (logo grande, tarjeta redondeada) y adjunto, admin (lista/detalle/CSV, eliminar) y verificación /verificar con escáner. Independiente del tema.
 * Version: 1.9.1
 * Author: Mariana Limón
 */

if (!defined('ABSPATH')) exit;

class ExpoRegistroInd {
  private $table;
  private $from_email = 'registros@limonyya.com';
  private $from_name  = 'Colegio de Arquitectos de Querétaro';

  public function __construct() {
    global $wpdb; $this->table = $wpdb->prefix.'expo_registros';

    register_activation_hook(__FILE__, ['ExpoRegistroInd','activate']);

    add_action('init',               [$this,'add_rewrite']);
    add_action('template_redirect',  [$this,'render_verificar_page']);
    add_shortcode('expo_registro_form', [$this,'shortcode_form']);
    add_action('rest_api_init',      [$this,'register_routes']);
    add_action('admin_menu',         [$this,'admin_menu']);
    add_action('admin_post_expo_delete', [$this,'handle_delete']);
  }

  /* ---------- Activación ---------- */
  public static function activate() {
    global $wpdb;
    $table   = $wpdb->prefix.'expo_registros';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      folio VARCHAR(40) NOT NULL,
      nombre VARCHAR(160) NOT NULL,
      empresa VARCHAR(160) DEFAULT '',
      puesto VARCHAR(160) DEFAULT '',
      email VARCHAR(160) NOT NULL,
      telefono VARCHAR(60) DEFAULT '',
      ciudad VARCHAR(160) DEFAULT '',
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY folio (folio),
      KEY email (email)
    ) $charset;";
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    add_rewrite_rule('^verificar/?$', 'index.php?expo_verificar=1', 'top');
    add_rewrite_tag('%expo_verificar%', '([0-1])');
    flush_rewrite_rules();
  }

  /* ---------- Reescritura /verificar ---------- */
  public function add_rewrite() {
    add_rewrite_rule('^verificar/?$', 'index.php?expo_verificar=1', 'top');
    add_rewrite_tag('%expo_verificar%', '([0-1])');
  }

  /* ---------- Página de verificación (con escáner) ---------- */
  public function render_verificar_page() {
    if (get_query_var('expo_verificar') !== '1') return;

    $raw = isset($_GET['folio']) ? sanitize_text_field($_GET['folio']) : '';
    $folio = '';
    if ($raw) {
      if (strpos($raw, 'folio=') !== false) {
        parse_str(parse_url($raw, PHP_URL_QUERY) ?: '', $q);
        $folio = isset($q['folio']) ? sanitize_text_field($q['folio']) : '';
      } else { $folio = $raw; }
    }

    global $wpdb;
    $row = $folio ? $wpdb->get_row($wpdb->prepare("SELECT folio,nombre,empresa FROM {$this->table} WHERE folio=%s", $folio)) : null;

    nocache_headers(); header('Content-Type: text/html; charset=UTF-8'); ?>
    <!doctype html><html lang="es"><head>
      <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
      <title>Verificación de Acceso</title>
      <style>
        :root{--ok:#16a34a;--bad:#dc2626}
        *{box-sizing:border-box} body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial}
        .wrap{min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px;background:#bfd1e9}
        .card{width:min(900px,95vw);padding:28px;border-radius:18px;background:#fff;box-shadow:0 10px 40px rgba(0,0,0,.25);text-align:center}
        h1{margin:0 0 8px;font-size:clamp(26px,5vw,40px)} p{margin:6px 0 0;color:#334155}
        .ok{border:6px solid var(--ok)} .bad{border:6px solid var(--bad)}
        .tag{display:inline-block;margin:14px 0;padding:8px 14px;border-radius:999px;font-weight:700;color:#fff}
        .tag.ok{background:var(--ok)} .tag.bad{background:var(--bad)}
        .box{margin-top:14px;padding:14px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;text-align:left}
        .row{display:flex;gap:10px;justify-content:center;margin-top:14px;flex-wrap:wrap}
        input[type=text]{padding:12px 14px;border:1px solid #cbd5e1;border-radius:10px;min-width:260px}
        button{padding:12px 16px;border:0;border-radius:10px;background:#384673;color:#fff;font-weight:600;cursor:pointer}

        /* --- Scanner limpio y responsivo --- */
        #reader{margin:14px auto; width:100%; max-width:520px; min-height:300px; overflow-anchor:none;}
        #reader video, #reader canvas, #reader img { width:100% !important; height:auto !important }
        #reader select, #reader button { max-width:100% }
        #reader .html5-qrcode-info-wrapper,
        #reader .html5-qrcode-element img,
        #reader img[alt="info"],
        #reader img[alt="Info icon"]{ display:none !important; }
        #reader .qr-shaded-region{ background: rgba(0,0,0,.28) !important; background-image: none !important; }

        @media (max-width:480px){
          .row{flex-direction:column; align-items:stretch}
          input[type=text]{min-width:unset; width:100%}
          button#scanBtn{width:100%}
        }
      </style>
      <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    </head><body><div class="wrap">
      <div class="card <?php echo $folio ? ($row ? 'ok' : 'bad') : ''; ?>">
        <?php if ($folio && $row): ?>
          <h1>ACCESO AUTORIZADO</h1>
          <span class="tag ok">Folio válido</span>
          <div class="box">
            <strong>Folio:</strong> <?php echo esc_html($row->folio); ?><br>
            <strong>Nombre:</strong> <?php echo esc_html($row->nombre); ?><br>
            <strong>Empresa:</strong> <?php echo esc_html($row->empresa); ?>
          </div>
        <?php elseif($folio && !$row): ?>
          <h1>ACCESO DENEGADO</h1>
          <span class="tag bad">Folio no encontrado</span>
          <div class="box">
            <strong>Folio:</strong> <?php echo esc_html($folio); ?><br>
            <p>Este folio no está en la lista de registros.</p>
          </div>
        <?php else: ?>
          <h1>VERIFICAR FOLIO</h1>
          <p>Escanea un QR o escribe el folio manualmente.</p>
        <?php endif; ?>

        <div class="row">
          <form method="get" action="<?php echo esc_url(home_url('/verificar')); ?>">
            <input type="hidden" name="expo_verificar" value="1">
            <input type="text" name="folio" placeholder="Pega la URL/folio o escribe: EXPO-YYYYMMDD-ABC123" value="<?php echo esc_attr($raw); ?>">
            <button type="submit">Verificar manual</button>
          </form>
          <button id="scanBtn" type="button">Escanear con cámara</button>
        </div>

        <div id="reader" style="display:none"></div>

        <small>Tip: el QR puede codificar la URL con el parámetro <code>?folio=...</code> o sólo el folio.</small>
      </div>
    </div>
    <script>
      const btn = document.getElementById('scanBtn'), box = document.getElementById('reader');
      let scanner = null; let lastWidth = 0;

      function calcQrbox(){
        const w = Math.min(box.clientWidth || window.innerWidth, 520);
        const size = Math.max(180, Math.floor(w * 0.72));
        return { width: size, height: size };
      }

      function renderScanner(){
        const opts = { fps: 10, qrbox: calcQrbox(), aspectRatio: 1.0, rememberLastUsedCamera: true };
        const onScan = (text) => {
          try{
            let folio = text;
            const url = new URL(text);
            const q = url.searchParams.get('folio');
            if (q) folio = q;
            window.location = "<?php echo esc_js(trailingslashit(home_url('verificar'))); ?>?expo_verificar=1&folio=" + encodeURIComponent(folio);
          }catch(_){
            window.location = "<?php echo esc_js(trailingslashit(home_url('verificar'))); ?>?expo_verificar=1&folio=" + encodeURIComponent(text);
          }
        };
        const y = window.pageYOffset;
        scanner = new Html5QrcodeScanner('reader', opts, false);
        scanner.render(onScan, console.error);
        window.scrollTo({ top: y });
      }

      btn?.addEventListener('click', () => {
        box.style.display = 'block';
        if (!scanner) { lastWidth = box.clientWidth; renderScanner(); }
      });

      let resizeT;
      window.addEventListener('resize', () => {
        if (!scanner) return;
        clearTimeout(resizeT);
        resizeT = setTimeout(() => {
          const w = box.clientWidth;
          if (Math.abs(w - lastWidth) < 24) return;
          lastWidth = w;
          const y = window.pageYOffset;
          scanner.clear().then(()=>{ scanner = null; renderScanner(); window.scrollTo({ top: y }); });
        }, 180);
      });

      document.addEventListener('visibilitychange', () => {
        if (document.hidden && scanner) {
          const y = window.pageYOffset;
          scanner.clear().then(()=>{ scanner = null; window.scrollTo({ top: y }); });
        }
      });
    </script>
    </body></html>
    <?php
    exit;
  }

  /* ---------- Shortcode de registro (con estilos mejorados) ---------- */
  public function shortcode_form() {
    $nonce = wp_create_nonce('wp_rest');
    $rest  = esc_url_raw(rest_url('expo/v1/registrar'));
    ob_start(); ?>
    <style>
      /* Tarjeta contenedora del form */
      #expo-card{
        background:#fff;border-radius:16px;border:1px solid #e6e9ef;
        box-shadow:0 10px 30px rgba(16,24,40,.06);
        padding:22px 22px 26px; max-width:720px;
      }
      #expo-form{margin:0}
      #expo-form .grid{display:grid;gap:14px;grid-template-columns:1fr 1fr}
      #expo-form .full{grid-column:1/-1}
      #expo-form label{display:block;font-weight:600;margin-bottom:6px;color:#111827}
      #expo-form input{
        width:100%;padding:12px 14px;border:1px solid #d0d7e2;border-radius:12px;
        outline:none; transition:border-color .15s, box-shadow .15s;
      }
      #expo-form input:focus{
        border-color:#50ad28; box-shadow:0 0 0 3px rgba(80,173,40,.2);
      }
      #expo-form button{
        margin-top:18px; padding:12px 18px; border:0; border-radius:12px;
        background:#50ad28; color:#fff; font-weight:700; cursor:pointer;
        box-shadow:0 8px 20px rgba(80,173,40,.25);
        transition: transform .12s ease, box-shadow .12s ease, filter .12s ease;
      }
      #expo-form button:hover{
        transform: translateY(-1px);
        box-shadow:0 12px 28px rgba(80,173,40,.3);
        filter:brightness(1.03);
      }
      #expo-form button:active{
        transform: translateY(0);
        box-shadow:0 6px 16px rgba(80,173,40,.25);
      }
      @media (max-width:720px){
        #expo-form .grid{grid-template-columns:1fr}
      }
    </style>

    <div id="expo-card">
      <form id="expo-form">
        <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">
        <div class="grid">
          <div class="full">
            <label>Nombre completo*</label>
            <input name="nombre" required>
          </div>
          <div>
            <label>Empresa</label>
            <input name="empresa">
          </div>
          <div>
            <label>Puesto</label>
            <input name="puesto">
          </div>
          <div>
            <label>Teléfono</label>
            <input name="telefono" pattern="[0-9\s+-]+">
          </div>
          <div>
            <label>Ciudad</label>
            <input name="ciudad">
          </div>
          <div>
            <label>Email*</label>
            <input type="email" name="email" required>
          </div>
        </div>
        <button type="submit">Registrarme</button>
        <p id="expo-msg" style="margin-top:10px"></p>
      </form>
    </div>

    <script>
      (function(){
        const f=document.getElementById('expo-form'), msg=document.getElementById('expo-msg');
        f.addEventListener('submit', async (e)=>{
          e.preventDefault(); msg.style.color=''; msg.textContent='Enviando...';
          const data = Object.fromEntries(new FormData(f).entries());
          try{
            const r = await fetch("<?php echo $rest; ?>",{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':"<?php echo esc_js($nonce); ?>"},body:JSON.stringify(data)});
            const j = await r.json(); if(!r.ok) throw new Error(j?.message||'Error');
            msg.style.color='green'; msg.textContent='✅ Registro exitoso. Revisa tu correo.'; f.reset();
          }catch(err){ msg.style.color='crimson'; msg.textContent='Error: '+err.message; }
        });
      })();
    </script>
    <?php return ob_get_clean();
  }

  /* ---------- API: registrar y exportar ---------- */
  public function register_routes() {
    register_rest_route('expo/v1','/registrar',[
      'methods'=>'POST',
      'callback'=>[$this,'handle_register'],
      'permission_callback'=>function(){ return wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE']??'', 'wp_rest'); }
    ]);
    register_rest_route('expo/v1','/export',[
      'methods'  => 'GET',
      'callback' => [$this,'export_csv'],
      'permission_callback' => function () {
        // Aceptar nonce por header o por query para poder abrir con un link
        $nonce = $_GET['_wpnonce'] ?? ($_SERVER['HTTP_X_WP_NONCE'] ?? '');
        return current_user_can('manage_options') && wp_verify_nonce($nonce, 'wp_rest');
      }
    ]);
  }

  /* Folio aleatorio (no predecible) */
  private function generate_folio() {
    global $wpdb;
    $date = current_time('Ymd');
    $rand = function() {
      $b = bin2hex(random_bytes(5)); $n = base_convert($b, 16, 36);
      return strtoupper(substr($n, 0, 6));
    };
    for ($i=0; $i<10; $i++) {
      $token = $rand();
      $folio = sprintf('EXPO-%s-%s', $date, $token);
      $exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$this->table} WHERE folio=%s LIMIT 1", $folio));
      if (!$exists) return $folio;
    }
    $token = strtoupper(substr(base_convert(bin2hex(random_bytes(6)),16,36),0,8));
    return sprintf('EXPO-%s-%s', $date, $token);
  }

  public function handle_register(\WP_REST_Request $req){
    if(!empty($req->get_param('website'))){ return new \WP_REST_Response(['message'=>'Bloqueado'],400); }

    $nombre = sanitize_text_field($req->get_param('nombre'));
    $empresa= sanitize_text_field($req->get_param('empresa'));
    $puesto = sanitize_text_field($req->get_param('puesto'));
    $tel    = sanitize_text_field($req->get_param('telefono'));
    $ciudad = sanitize_text_field($req->get_param('ciudad'));
    $email  = sanitize_email($req->get_param('email'));

    if(!$nombre || !$email) return new \WP_REST_Response(['message'=>'Nombre y email son obligatorios'],400);
    if(!is_email($email)) return new \WP_REST_Response(['message'=>'Email no válido'],400);

    $folio = $this->generate_folio();
    $now   = current_time('mysql');

    global $wpdb;
    $ok = $wpdb->insert($this->table,[
      'folio'=>$folio,'nombre'=>$nombre,'empresa'=>$empresa,'puesto'=>$puesto,'email'=>$email,'telefono'=>$tel,'ciudad'=>$ciudad,'created_at'=>$now
    ],['%s','%s','%s','%s','%s','%s','%s','%s']);
    if(!$ok) return new \WP_REST_Response(['message'=>'No se pudo guardar'],500);

    /* ------ Generar QR ------ */
    $verify_url = add_query_arg( 'folio', rawurlencode($folio), home_url('/verificar') );
    $qr_remote  = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data='.rawurlencode($verify_url);
    $qr_backup  = "https://chart.googleapis.com/chart?chs=240x240&cht=qr&chl=" . rawurlencode($verify_url);

    $upload = wp_upload_dir(); wp_mkdir_p($upload['path']);
    $qr_filename = 'qr-'.sanitize_file_name($folio).'.png';
    $qr_path     = trailingslashit($upload['path']).$qr_filename;
    $qr_public   = trailingslashit($upload['url']).$qr_filename;

    $response = wp_remote_get($qr_remote, ['timeout'=>15]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
      $response = wp_remote_get($qr_backup, ['timeout'=>15]);
    }
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
      file_put_contents($qr_path, wp_remote_retrieve_body($response));
    }

    /* ------ Email: HTML con logo grande + tarjeta redondeada ------ */
    $subject = 'Registro confirmado – Colegio de Arquitectos de Querétaro';
    $headers = [
      'From: '.$this->from_name.' <'.$this->from_email.'>',
      'Content-Type: text/html; charset=UTF-8',
    ];

    // 👉 Cambia esta URL por tu logo final adaptado si lo subes al sitio.
    $logo_url = 'https://limonyya.com/ColegiodeArquitectosQtro/wp-content/uploads/2025/10/logo.png';

    $qr_src = file_exists($qr_path) ? $qr_public : ( !is_wp_error($response) ? ( $qr_remote ?: $qr_backup ) : $qr_backup );

    // ajustes rápidos
    $logo_max_h = 130;        // altura máx del logo (sube a 130-140 si lo quieres más grande)
    $ribbon_bg  = '#CFE1F7';  // color del listón azul (cámbialo si quieres otro tono)
    $card_w     = 700;        // ancho máximo de la tarjeta

    $qr_src = file_exists($qr_path) ? $qr_public : ( !is_wp_error($response) ? ( $qr_remote ?: $qr_backup ) : $qr_backup );

    $html  = '<div style="background:#f6f7f9;padding:24px 12px">';
    $html .=   '<div style="max-width:'.$card_w.'px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;text-align:center">';

              // listón azul a lo ancho + logo centrado
    $html .=     '<div style="background:'.$ribbon_bg.';padding:18px 24px;border-radius:16px 16px 0 0">';
    $html .=       '<img src="'.esc_url($logo_url).'" alt="Logo" style="max-height:'.$logo_max_h.'px;display:block;margin:0 auto">';
    $html .=     '</div>';

              // contenido de la tarjeta
    $html .=     '<div style="padding:22px 24px 26px">';
    $html .=       '<h2 style="margin:6px 0 8px;font-family:Arial,sans-serif;color:#111827;line-height:1.25">¡Registro confirmado!</h2>';
    $html .=       '<p style="margin:0 0 14px;font-family:Arial,sans-serif;color:#111827">Gracias por registrarte a <strong>Colegio de Arquitectos de Querétaro</strong>.</p>';

    $html .=       '<p style="margin:0 0 10px;font-family:Arial,sans-serif;color:#111827"><strong>Folio:</strong> '.esc_html($folio).'</p>';

    $html .=       '<p style="margin:0 0 14px;font-family:Arial,sans-serif;color:#111827">'
                .    '<strong>Nombre:</strong> '.esc_html($nombre).'<br>'
                .    '<strong>Empresa:</strong> '.esc_html($empresa).'<br>'
                .    '<strong>Puesto:</strong> '.esc_html($puesto).'<br>'
                .    '<strong>Teléfono:</strong> '.esc_html($tel).'<br>'
                .    '<strong>Ciudad:</strong> '.esc_html($ciudad).'<br>'
                .    '<strong>Email:</strong> '.esc_html($email).'</p>';

    $html .=       '<p style="margin:0 0 12px;font-family:Arial,sans-serif;color:#111827">Presenta este QR en el acceso:</p>';

    $html .=       '<p style="margin:0"><img src="'.esc_url($qr_src).'" width="240" height="240" alt="QR '.esc_attr($folio).'" style="display:block;margin:0 auto"></p>';

    $html .=       '<p style="margin:14px 0 0;font-family:Arial,sans-serif;color:#334155;font-size:14px">'
                .  'Si no ves el QR solo descárgalo de los archivos adjuntos en este correo o solicita que busquen tu folio para darte acceso.'
                .  '</p>'
                .  '<div style="height:22px"></div>';

    $html .=     '</div>'; // /contenido
    $html .=   '</div>';   // /card
    $html .= '</div>';     // /bg


    if (file_exists($qr_path)) {
      add_action('phpmailer_init', function($phpmailer) use ($qr_path) {
        $phpmailer->isHTML(true);
        $phpmailer->addAttachment($qr_path, 'qr.png');
      });
    }

    wp_mail($email, $subject, $html, $headers);
    wp_mail($this->from_email, 'Nuevo registro: '.$folio, $html, $headers);

    return ['ok'=>true,'folio'=>$folio];
  }

  public function export_csv(){
    if(!current_user_can('manage_options')) return new \WP_REST_Response(['message'=>'No autorizado'],403);
    global $wpdb;
    $rows = $wpdb->get_results("SELECT id,folio,nombre,empresa,puesto,email,telefono,ciudad,created_at FROM {$this->table} ORDER BY id DESC", ARRAY_A);
    nocache_headers();
    header('Content-Type:text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=expo-registros-'.date('Ymd-His').'.csv');
    $out=fopen('php://output','w');
    fputcsv($out, array_keys($rows ? $rows[0] : ['id','folio','nombre','empresa','puesto','email','telefono','ciudad','created_at']));
    if($rows) foreach($rows as $r) fputcsv($out,$r);
    fclose($out); exit;
  }

  /* ---------- Admin ---------- */
  public function admin_menu(){
    add_menu_page('Expo Registros','Expo Registros','manage_options','expo-registros',[$this,'admin_page'],'dashicons-tickets',26);
    add_submenu_page('expo-registros','Detalle de registro','Detalle','manage_options','expo-detalle',[$this,'detail_page']);
  }

  public function admin_page(){
    global $wpdb;
    $rows = $wpdb->get_results("SELECT id,folio,nombre,empresa,puesto,email,telefono,ciudad,created_at FROM {$this->table} ORDER BY id DESC LIMIT 1000", ARRAY_A);
    $export = esc_url( add_query_arg(
      '_wpnonce',
      wp_create_nonce('wp_rest'),
      rest_url('expo/v1/export')
    ));


    if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
      echo '<div class="notice notice-success is-dismissible"><p>Registro eliminado.</p></div>';
    } elseif (isset($_GET['deleted']) && $_GET['deleted'] === '0') {
      echo '<div class="notice notice-error is-dismissible"><p>No se pudo eliminar el registro.</p></div>';
    }

    echo '<div class="wrap"><h1>Expo - Registros</h1>';
    echo '<p><a class="button button-primary" href="'.$export.'">Exportar CSV</a></p>';
    echo '<table class="widefat striped"><thead><tr>
      <th>ID</th><th>Folio</th><th>Nombre</th><th>Empresa</th><th>Puesto</th><th>Email</th><th>Teléfono</th><th>Ciudad</th><th>Fecha</th><th>Acciones</th>
    </tr></thead><tbody>';

    if($rows){
      foreach($rows as $r){
        $detail = admin_url('admin.php?page=expo-detalle&id='.(int)$r['id']);
        $nonce  = wp_create_nonce('expo_delete_'.$r['id']);
        $del    = admin_url('admin-post.php?action=expo_delete&id='.(int)$r['id'].'&_wpnonce='.$nonce);
        echo '<tr>';
        echo '<td>'.(int)$r['id'].'</td>';
        echo '<td>'.esc_html($r['folio']).'</td>';
        echo '<td>'.esc_html($r['nombre']).'</td>';
        echo '<td>'.esc_html($r['empresa']).'</td>';
        echo '<td>'.esc_html($r['puesto']).'</td>';
        echo '<td>'.esc_html($r['email']).'</td>';
        echo '<td>'.esc_html($r['telefono']).'</td>';
        echo '<td>'.esc_html($r['ciudad']).'</td>';
        echo '<td>'.esc_html($r['created_at']).'</td>';
        echo '<td><a class="button" href="'.esc_url($detail).'">Abrir</a> ';
        echo '<a class="button button-secondary" style="margin-left:6px" href="'.esc_url($del).'" ';
        echo 'onclick="return confirm(\'¿Eliminar este registro?\')">Eliminar</a></td>';
        echo '</tr>';
      }
    } else {
      echo '<tr><td colspan="10">Sin registros aún.</td></tr>';
    }
    echo '</tbody></table></div>';
  }

  public function handle_delete() {
    if (!current_user_can('manage_options')) wp_die('No autorizado.');
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) wp_redirect(admin_url('admin.php?page=expo-registros&deleted=0'));

    check_admin_referer('expo_delete_'.$id);

    global $wpdb;
    $ok = $wpdb->delete($this->table, ['id'=>$id], ['%d']);
    $flag = $ok ? '1' : '0';
    wp_redirect( admin_url('admin.php?page=expo-registros&deleted='.$flag) );
    exit;
  }

  public function detail_page(){
    if(!isset($_GET['id'])){ echo '<div class="wrap"><h1>Sin ID</h1></div>'; return; }
    global $wpdb; $id=(int)$_GET['id'];
    $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d",$id), ARRAY_A);
    echo '<div class="wrap"><h1>Detalle de registro</h1>';
    if(!$r){ echo '<p>No encontrado.</p></div>'; return; }
    echo '<table class="widefat fixed"><tbody>';
    foreach($r as $k=>$v){ echo '<tr><th style="width:180px">'.esc_html($k).'</th><td>'.esc_html((string)$v).'</td></tr>'; }
    echo '</tbody></table></div>';
  }
}

new ExpoRegistroInd();
