<?php
/**
 * O WordPress de mentira, para rodar a LetícIA no terminal.
 *
 * As classes do plugin recebem arrays e devolvem arrays; só encostam no
 * WordPress para ler configuração, guardar cache e falar HTTP. Este arquivo
 * finge as três coisas — e, diferente dos stubs da suíte, **de verdade**:
 * a configuração persiste em disco entre execuções e a chamada de rede sai
 * mesmo, para o Gemini de verdade.
 *
 * Por que não reaproveitar `leticia/tests/stubs-wp.php`: são ferramentas com
 * exigências opostas. A suíte precisa ser efêmera e **sem rede** — um teste que
 * depende da internet falha por motivo errado e logo ninguém roda. O terminal
 * precisa do contrário: estado que sobrevive e rede que funciona. Um arquivo só
 * teria que ser os dois ao mesmo tempo, e seria nenhum dos dois direito.
 *
 * Nada aqui vai para o servidor: `empacotar.py` empacota só `leticia/`.
 */

// Linha de comando (briefing.php) ou servidor embutido (servidor.php). Nunca
// dentro de um servidor web de verdade.
if ( ! in_array( php_sapi_name(), array( 'cli', 'cli-server' ), true ) ) {
	exit( 'Este arquivo roda só localmente.' );
}

define( 'LETICIA_LOCAL', __DIR__ );
define( 'LETICIA_DADOS', __DIR__ . '/dados' );

if ( ! is_dir( LETICIA_DADOS ) ) {
	mkdir( LETICIA_DADOS, 0777, true );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'LETICIA_DIR', dirname( __DIR__ ) . '/leticia/' );
define( 'LETICIA_URL', 'http://localhost/leticia/' );
define( 'LETICIA_VERSAO', '0.1.0' );

// ------------------------------------------------------------------ o .env

/**
 * Lê o .env, se existir.
 *
 * A chave da API nunca fica no código nem no repositório — o `.gitignore` tem o
 * `.env`, e o `.env.example` mostra o formato sem trazer segredo nenhum.
 */
function leticia_ambiente() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}

	$memo    = array();
	$caminho = dirname( __DIR__ ) . '/.env';

	if ( ! is_readable( $caminho ) ) {
		return $memo;
	}

	foreach ( file( $caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $linha ) {
		$linha = trim( $linha );
		if ( '' === $linha || 0 === strpos( $linha, '#' ) || false === strpos( $linha, '=' ) ) {
			continue;
		}
		list( $chave, $valor ) = explode( '=', $linha, 2 );
		$memo[ trim( $chave ) ] = trim( trim( $valor ), "\"'" );
	}

	return $memo;
}

function leticia_env( $chave, $padrao = '' ) {
	$tudo = leticia_ambiente();
	return isset( $tudo[ $chave ] ) && '' !== $tudo[ $chave ] ? $tudo[ $chave ] : $padrao;
}

// ------------------------------------------------------------------ erros

class WP_Error {
	private $codigo;
	private $mensagem;
	private $dados;
	public function __construct( $codigo = '', $mensagem = '', $dados = null ) {
		$this->codigo   = $codigo;
		$this->mensagem = $mensagem;
		$this->dados    = $dados;
	}
	public function get_error_code() {
		return $this->codigo;
	}
	public function get_error_message() {
		return $this->mensagem;
	}
	public function get_error_data() {
		return $this->dados;
	}
}

function is_wp_error( $c ) {
	return $c instanceof WP_Error;
}

// -------------------------------------------------- opções e transients

/**
 * Opções e transients num arquivo só.
 *
 * Persistem entre execuções de propósito: é o que permite fechar o terminal no
 * meio de um briefing e retomar depois — que é justamente o comportamento que
 * este produto precisa ter e que dá trabalho testar de outro jeito.
 */
function leticia_armazenar( $novo = null ) {
	static $memo    = null;
	$caminho        = LETICIA_DADOS . '/opcoes.json';

	if ( null !== $novo ) {
		$memo = $novo;
		file_put_contents( $caminho, json_encode( $novo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), LOCK_EX );
		return $novo;
	}
	if ( null !== $memo ) {
		return $memo;
	}

	$dados = is_readable( $caminho ) ? json_decode( (string) file_get_contents( $caminho ), true ) : array();
	$memo  = is_array( $dados ) ? $dados : array();
	$memo['opcoes']     = isset( $memo['opcoes'] ) ? $memo['opcoes'] : array();
	// Como no WordPress de verdade: o administrador existe sempre, e é o
	// destino de reserva quando LETICIA_DESTINO está em branco.
	$memo['opcoes']['admin_email'] = isset( $memo['opcoes']['admin_email'] ) ? $memo['opcoes']['admin_email'] : 'admin@example.com';
	$memo['transients'] = isset( $memo['transients'] ) ? $memo['transients'] : array();

	return $memo;
}

function get_option( $chave, $padrao = false ) {
	$tudo = leticia_armazenar();
	return array_key_exists( $chave, $tudo['opcoes'] ) ? $tudo['opcoes'][ $chave ] : $padrao;
}

function update_option( $chave, $valor, $autoload = null ) {
	$tudo                     = leticia_armazenar();
	$tudo['opcoes'][ $chave ] = $valor;
	leticia_armazenar( $tudo );
	return true;
}

function delete_option( $chave ) {
	$tudo = leticia_armazenar();
	unset( $tudo['opcoes'][ $chave ] );
	leticia_armazenar( $tudo );
	return true;
}

function get_transient( $chave ) {
	$tudo = leticia_armazenar();
	if ( ! isset( $tudo['transients'][ $chave ] ) ) {
		return false;
	}
	$item = $tudo['transients'][ $chave ];
	if ( $item['expira'] > 0 && $item['expira'] < time() ) {
		unset( $tudo['transients'][ $chave ] );
		leticia_armazenar( $tudo );
		return false;
	}
	return $item['valor'];
}

function set_transient( $chave, $valor, $validade = 0 ) {
	$tudo                         = leticia_armazenar();
	$tudo['transients'][ $chave ] = array(
		'valor'  => $valor,
		'expira' => $validade > 0 ? time() + $validade : 0,
	);
	leticia_armazenar( $tudo );
	return true;
}

function delete_transient( $chave ) {
	$tudo = leticia_armazenar();
	unset( $tudo['transients'][ $chave ] );
	leticia_armazenar( $tudo );
	return true;
}

// ------------------------------------------------------- filtros e ações

$GLOBALS['leticia_filtros'] = array();

function add_filter( $tag, $funcao, $prioridade = 10 ) {
	$GLOBALS['leticia_filtros'][ $tag ][] = $funcao;
	return true;
}

function apply_filters( $tag, $valor ) {
	if ( empty( $GLOBALS['leticia_filtros'][ $tag ] ) ) {
		return $valor;
	}
	$extras = array_slice( func_get_args(), 2 );
	foreach ( $GLOBALS['leticia_filtros'][ $tag ] as $funcao ) {
		$valor = call_user_func_array( $funcao, array_merge( array( $valor ), $extras ) );
	}
	return $valor;
}

function remove_all_filters( $tag ) {
	unset( $GLOBALS['leticia_filtros'][ $tag ] );
	return true;
}

$GLOBALS['leticia_acoes'] = array();

function add_action() {
	return true;
}

function do_action( $tag ) {
	$GLOBALS['leticia_acoes'][] = array( 'tag' => $tag, 'args' => array_slice( func_get_args(), 1 ) );
}

// ------------------------------------------------------------------ texto

function sanitize_text_field( $texto ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $texto ) ) );
}

function wp_strip_all_tags( $texto ) {
	return strip_tags( (string) $texto );
}

function sanitize_hex_color( $cor ) {
	$cor = trim( (string) $cor );
	return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $cor ) ? $cor : null;
}

function esc_url_raw( $url ) {
	$url = trim( (string) $url );
	return preg_match( '#^https?://#i', $url ) ? $url : '';
}

function esc_html( $texto ) {
	return htmlspecialchars( (string) $texto, ENT_QUOTES, 'UTF-8' );
}

function is_email( $email ) {
	$email = trim( (string) $email );
	return preg_match( '/^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/', $email ) ? $email : false;
}

function wp_list_pluck( $lista, $campo ) {
	$saida = array();
	foreach ( (array) $lista as $item ) {
		if ( is_array( $item ) && isset( $item[ $campo ] ) ) {
			$saida[] = $item[ $campo ];
		}
	}
	return $saida;
}

function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max > 0 ? $max : PHP_INT_MAX );
}

function wp_json_encode( $dados, $opcoes = 0 ) {
	return json_encode( $dados, $opcoes | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}


function wp_unslash( $valor ) {
	return is_string( $valor ) ? stripslashes( $valor ) : $valor;
}

function wp_salt( $escopo = '' ) {
	return leticia_env( 'LETICIA_SALT', 'leticia-desenvolvimento' ) . '|' . $escopo;
}

// --------------------------------------------------------------- arquivos

function trailingslashit( $caminho ) {
	return rtrim( (string) $caminho, "/\\" ) . '/';
}

function wp_mkdir_p( $caminho ) {
	return is_dir( $caminho ) ? true : mkdir( $caminho, 0777, true );
}

function wp_upload_dir() {
	$base = LETICIA_DADOS . '/uploads';
	if ( ! is_dir( $base ) ) {
		mkdir( $base, 0777, true );
	}
	return array( 'basedir' => $base, 'baseurl' => 'http://localhost/uploads' );
}

function sanitize_file_name( $nome ) {
	$nome = preg_replace( '/[^A-Za-z0-9 ._\-\p{L}]/u', '', (string) $nome );
	$nome = preg_replace( '/\s+/', '-', $nome );
	return trim( $nome, '.- ' );
}

function size_format( $bytes, $casas = 0 ) {
	$bytes = (float) $bytes;
	if ( $bytes >= 1073741824 ) {
		return round( $bytes / 1073741824, $casas ) . ' GB';
	}
	if ( $bytes >= 1048576 ) {
		return round( $bytes / 1048576, $casas ) . ' MB';
	}
	if ( $bytes >= 1024 ) {
		return round( $bytes / 1024, $casas ) . ' KB';
	}
	return $bytes . ' B';
}

function nocache_headers() {}

// -------------------------------------------------------------------- HTTP

/**
 * A chamada de rede de verdade, em cURL.
 *
 * É o que diferencia este arquivo dos stubs da suíte: aqui o Gemini responde
 * mesmo, e é por isso que dá para calibrar o tom da LetícIA no terminal antes
 * de ela ter tela.
 */
function wp_remote_post( $url, $args = array() ) {
	if ( ! function_exists( 'curl_init' ) ) {
		return new WP_Error( 'sem_curl', 'Este PHP não tem cURL. Habilite a extensão para falar com a API.' );
	}

	$ch      = curl_init( $url );
	$cabecas = array();
	foreach ( (array) ( isset( $args['headers'] ) ? $args['headers'] : array() ) as $nome => $valor ) {
		$cabecas[] = $nome . ': ' . $valor;
	}

	curl_setopt_array(
		$ch,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => isset( $args['body'] ) ? $args['body'] : '',
			CURLOPT_HTTPHEADER     => $cabecas,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => isset( $args['timeout'] ) ? (int) $args['timeout'] : 15,
			CURLOPT_CONNECTTIMEOUT => 8,
		)
	);

	$corpo  = curl_exec( $ch );
	$codigo = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$erro   = curl_error( $ch );
	curl_close( $ch );

	if ( false === $corpo ) {
		return new WP_Error( 'rede', $erro ? $erro : 'a chamada falhou' );
	}

	return array( 'response' => array( 'code' => $codigo ), 'body' => $corpo );
}

function wp_remote_retrieve_response_code( $r ) {
	return is_array( $r ) && isset( $r['response']['code'] ) ? $r['response']['code'] : 0;
}

function wp_remote_retrieve_body( $r ) {
	return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
}

// ----------------------------------------------------------- o plugin

// Localmente, quem abre a página é a equipe: logado e com permissão.
function is_user_logged_in() { return true; }
function wp_verify_nonce( $nonce, $acao = -1 ) { return 'nonce-' . $acao === $nonce ? 1 : false; }
function wp_login_url( $voltar = '' ) { return '/'; }
function get_permalink( $post = 0 ) { return leticia_origem() . '/links'; }
function wp_get_current_user() { return (object) array( 'display_name' => 'Equipe (local)' ); }
function get_posts( $args = array() ) { return array(); }

require_once LETICIA_DIR . 'includes/class-leticia-config.php';
require_once LETICIA_DIR . 'includes/class-leticia-base.php';
require_once LETICIA_DIR . 'includes/class-leticia-campos.php';
require_once LETICIA_DIR . 'includes/class-leticia-validacao.php';
require_once LETICIA_DIR . 'includes/class-leticia-roteiro.php';
require_once LETICIA_DIR . 'includes/class-leticia-trava.php';
require_once LETICIA_DIR . 'includes/class-leticia-prompt.php';
require_once LETICIA_DIR . 'includes/class-leticia-gemini.php';
require_once LETICIA_DIR . 'includes/class-leticia-modelos.php';
require_once LETICIA_DIR . 'includes/class-leticia-modelo.php';
require_once LETICIA_DIR . 'includes/class-leticia-voz.php';
require_once LETICIA_DIR . 'includes/class-leticia-limites.php';
require_once LETICIA_DIR . 'includes/class-leticia-arquivos.php';
require_once LETICIA_DIR . 'includes/class-leticia-armazem.php';
require_once LETICIA_DIR . 'includes/class-leticia-armazem-json.php';
require_once LETICIA_DIR . 'includes/class-leticia-armazem-wpdb.php';
require_once LETICIA_DIR . 'includes/class-leticia-registro.php';
require_once LETICIA_DIR . 'includes/class-leticia-rascunho.php';
require_once LETICIA_DIR . 'includes/class-leticia-anexo.php';
require_once LETICIA_DIR . 'includes/class-leticia-retomada.php';
require_once LETICIA_DIR . 'includes/class-leticia-links.php';
require_once LETICIA_DIR . 'includes/class-leticia-email.php';
require_once LETICIA_DIR . 'includes/class-leticia-entrega.php';
require_once LETICIA_DIR . 'includes/class-leticia-rest.php';
require_once LETICIA_DIR . 'includes/class-leticia-download.php';
require_once LETICIA_DIR . 'includes/class-leticia-tela.php';
require_once LETICIA_DIR . 'admin/class-leticia-admin.php';
require_once LETICIA_DIR . 'admin/class-leticia-painel.php';

// O armazém em arquivo, e não o de banco: aqui não existe $wpdb.
Leticia_Registro::usar_armazem( new Leticia_Armazem_Json( LETICIA_DADOS . '/briefings.json' ) );
Leticia_Registro::instalar();

/**
 * A configuração: o que o painel salvou, com o .env por cima.
 *
 * O .env vale a cada execução, para editar o arquivo valer na hora seguinte.
 * Só as variáveis que ele define, porém — o resto vem do que foi salvo pelo
 * painel local, em `local/dados/opcoes.json`. Assim dá para testar a aba de
 * Configuração de verdade, e o painel mostra travado o que vem do .env em vez
 * de fingir que salvou.
 */
$leticia_do_env = array();
foreach ( array(
	'GEMINI_API_KEY'       => 'LETICIA_GEMINI_API_KEY',
	'GEMINI_MODEL'         => 'LETICIA_GEMINI_MODEL',
	'GEMINI_MODEL_RESERVA' => 'LETICIA_GEMINI_MODEL_RESERVA',
	'ATIVA'                => 'LETICIA_ATIVA',
	'TETO_DIARIO'          => 'LETICIA_TETO_DIARIO',
	'REMETENTE'            => 'LETICIA_REMETENTE',
	'DESTINO'              => 'LETICIA_DESTINO',
	'LINK_ANEXO_DEPOIS'    => 'LETICIA_LINK_ANEXO_DEPOIS',
) as $leticia_opcao => $leticia_variavel ) {
	if ( '' !== leticia_env( $leticia_variavel ) ) {
		$leticia_do_env[ $leticia_opcao ] = leticia_env( $leticia_variavel );
	}
}

$leticia_salvo = get_option( Leticia_Config::OPCAO, array() );
update_option( Leticia_Config::OPCAO, array_merge( is_array( $leticia_salvo ) ? $leticia_salvo : array(), $leticia_do_env ) );

add_filter(
	'leticia_config_travada',
	function () use ( $leticia_do_env ) {
		return array_keys( $leticia_do_env );
	}
);

// ------------------------------------------------ e-mail e agendamento

$GLOBALS['leticia_emails'] = array();

function wp_mail( $para, $assunto, $corpo, $cabecalhos = array(), $anexos = array() ) {
	if ( ! empty( $GLOBALS['leticia_email_falha'] ) ) {
		return false;
	}
	$GLOBALS['leticia_emails'][] = array(
		'para'       => $para,
		'assunto'    => $assunto,
		'corpo'      => $corpo,
		'cabecalhos' => (array) $cabecalhos,
		'anexos'     => (array) $anexos,
	);
	return true;
}

function leticia_emails_enviados() {
	return $GLOBALS['leticia_emails'];
}

function leticia_zerar_emails() {
	$GLOBALS['leticia_emails']       = array();
	$GLOBALS['leticia_email_falha']  = false;
	$GLOBALS['leticia_agendamentos'] = array();
}

$GLOBALS['leticia_agendamentos'] = array();

function wp_schedule_single_event( $quando, $tag, $args = array() ) {
	$GLOBALS['leticia_agendamentos'][] = array( 'quando' => $quando, 'tag' => $tag, 'args' => $args );
	return true;
}

function leticia_agendamentos() {
	return $GLOBALS['leticia_agendamentos'];
}

/** O endereço de onde o servidor local está respondendo; no terminal, um de exemplo. */
function leticia_origem() {
	return isset( $_SERVER['HTTP_HOST'] ) ? 'http://' . $_SERVER['HTTP_HOST'] : 'https://exemplo.test';
}

function admin_url( $caminho = '' ) {
	return leticia_origem() . '/wp-admin/' . ltrim( (string) $caminho, '/' );
}

function add_query_arg( $args, $url = '' ) {
	$separador = false === strpos( $url, '?' ) ? '?' : '&';
	return $url . $separador . http_build_query( $args );
}

function current_time( $tipo = 'timestamp' ) {
	return time();
}

// ---------------------------------------------------------- REST

/**
 * Um WP_REST_Request mínimo.
 *
 * O suficiente para chamar os handlers direto, sem subir o WordPress: os
 * parâmetros, o corpo cru dos pedaços de upload, e nada mais. Testar o handler
 * é testar a regra; testar o roteador do WordPress seria testar o WordPress.
 */
class WP_REST_Request {
	private $params;
	private $corpo;
	public function __construct( array $params = array(), $corpo = '' ) {
		$this->params = $params;
		$this->corpo  = $corpo;
	}
	public function get_param( $chave ) {
		return isset( $this->params[ $chave ] ) ? $this->params[ $chave ] : null;
	}
	public function set_param( $chave, $valor ) {
		$this->params[ $chave ] = $valor;
	}
	public function get_params() {
		return $this->params;
	}
	public function get_body() {
		return $this->corpo;
	}
}

class WP_REST_Response {
	public $data;
	public function __construct( $data = null ) {
		$this->data = $data;
	}
	public function get_data() {
		return $this->data;
	}
}

function rest_ensure_response( $coisa ) {
	return $coisa instanceof WP_REST_Response ? $coisa : new WP_REST_Response( $coisa );
}

function register_rest_route( $ns, $caminho, $args ) {
	$GLOBALS['leticia_rotas'][ $ns . $caminho ] = $args;
	return true;
}

$GLOBALS['leticia_rotas'] = array();

function leticia_rotas() {
	return $GLOBALS['leticia_rotas'];
}

function add_shortcode() { return true; }
function shortcode_atts( $padroes, $dados ) { return array_merge( $padroes, (array) $dados ); }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_add_inline_style() {}
function wp_localize_script() {}
function rest_url( $caminho = '' ) { return leticia_origem() . '/wp-json/' . ltrim( (string) $caminho, '/' ); }
function wp_create_nonce() { return 'nonce-de-teste'; }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function home_url( $caminho = '' ) { return leticia_origem() . '/' . ltrim( (string) $caminho, '/' ); }

// ------------------------------------------------------------------ admin

/** Redirecionar e morrer interrompem a requisição; aqui viram exceção. */
class Leticia_Redirecionou extends Exception {}
class Leticia_Morreu extends Exception {}

function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function wp_kses_post( $t ) { return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $t ); }
function sanitize_key( $t ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $t ) ); }
function checked( $a, $b = true, $imprimir = true ) {
	$saida = (string) $a === (string) $b ? " checked='checked'" : '';
	if ( $imprimir ) {
		echo $saida;
	}
	return $saida;
}
function wp_nonce_field( $acao = -1, $nome = '_wpnonce', $referer = true, $imprimir = true ) {
	$campo = '<input type="hidden" name="' . $nome . '" value="nonce-' . $acao . '">';
	if ( $imprimir ) {
		echo $campo;
	}
	return $campo;
}
function settings_fields( $grupo ) {
	echo '<input type="hidden" name="option_page" value="' . esc_attr( $grupo ) . '">';
	wp_nonce_field( $grupo . '-options' );
}
function submit_button( $texto = 'Salvar' ) {
	echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html( $texto ) . '</button></p>';
}
function add_options_page() { return 'settings_page_leticia'; }
function register_setting() { return true; }

// Localmente não há sessão para proteger: o nonce existe no HTML para a tela
// ser a mesma de produção, e aqui sempre confere.
function check_admin_referer() { return 1; }
function wp_safe_redirect( $url ) {
	header( 'Location: ' . $url, true, 302 );
	return true;
}
function wp_die( $mensagem = '', $titulo = '', $args = array() ) {
	http_response_code( isset( $args['response'] ) ? (int) $args['response'] : 500 );
	exit( esc_html( $mensagem ) );
}
function current_user_can() { return true; }
