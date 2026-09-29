<?php
/**
 * Stubs mínimos do WordPress, para testar as classes fora dele.
 *
 * As classes de núcleo da LetícIA — Campos, Validacao, Roteiro — recebem
 * arrays e devolvem arrays. Só encostam no WordPress para ler configuração e
 * cache, que é o que este arquivo finge. É o que permite a suíte rodar em
 * milissegundos, sem banco, sem HTTP e sem gastar cota.
 */

if ( 'cli' !== php_sapi_name() ) {
	exit( 'Este arquivo roda só por linha de comando.' );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'LETICIA_DIR', dirname( __DIR__ ) . '/' );
define( 'LETICIA_URL', 'https://exemplo.test/wp-content/plugins/leticia/' );
define( 'LETICIA_VERSAO', '0.1.0' );

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

// ---------------------------------------------------------------- opções

// O admin_email já nasce preenchido, como no WordPress de verdade: é ele o
// destino de reserva quando ninguém configurou para onde o briefing vai.
$GLOBALS['leticia_opcoes']     = array( 'admin_email' => 'admin@example.com' );
$GLOBALS['leticia_transients'] = array();

function get_option( $chave, $padrao = false ) {
	return array_key_exists( $chave, $GLOBALS['leticia_opcoes'] ) ? $GLOBALS['leticia_opcoes'][ $chave ] : $padrao;
}

function update_option( $chave, $valor, $autoload = null ) {
	$GLOBALS['leticia_opcoes'][ $chave ] = $valor;
	return true;
}

function delete_option( $chave ) {
	unset( $GLOBALS['leticia_opcoes'][ $chave ] );
	return true;
}

function get_transient( $chave ) {
	if ( ! isset( $GLOBALS['leticia_transients'][ $chave ] ) ) {
		return false;
	}
	$item = $GLOBALS['leticia_transients'][ $chave ];
	if ( $item['expira'] > 0 && $item['expira'] < time() ) {
		unset( $GLOBALS['leticia_transients'][ $chave ] );
		return false;
	}
	return $item['valor'];
}

function set_transient( $chave, $valor, $validade = 0 ) {
	$GLOBALS['leticia_transients'][ $chave ] = array(
		'valor'  => $valor,
		'expira' => $validade > 0 ? time() + $validade : 0,
	);
	return true;
}

function delete_transient( $chave ) {
	unset( $GLOBALS['leticia_transients'][ $chave ] );
	return true;
}

// ---------------------------------------------------------------- texto

/**
 * Um sistema de filtros mínimo, mas de verdade.
 *
 * Fingir que apply_filters devolve o valor intacto esconderia justamente o
 * caso que interessa testar: o que acontece quando alguém troca o caminho da
 * base por um que não existe.
 */
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

function add_action() {
	return true;
}

function sanitize_text_field( $texto ) {
	$texto = strip_tags( (string) $texto );
	return trim( preg_replace( '/[\r\n\t]+/', ' ', $texto ) );
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
	// Igual ao do WordPress no que importa aqui: exige arroba, ponto no
	// domínio e nada de espaço.
	return (bool) preg_match( '/^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/', $email ) ? $email : false;
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

/** Ninguém pode nada, a menos que o caso diga: $GLOBALS['leticia_pode']['manage_options'] = true. */
function current_user_can( $permissao = '' ) {
	return ! empty( $GLOBALS['leticia_pode'][ $permissao ] );
}

function wp_unslash( $valor ) {
	return is_string( $valor ) ? stripslashes( $valor ) : $valor;
}

// -------------------------------------------------- HTTP e ações

/**
 * Nenhuma chamada de rede sai daqui.
 *
 * A suíte cobre o caminho do modelo pelo filtro `leticia_pre_gerar`. Se algum
 * caso chegar a este ponto, é defeito do caso — e o erro diz isso, em vez de
 * tentar a internet e demorar quinze segundos para falhar.
 */
function wp_remote_post( $url, $args = array() ) {
	// Os casos do cliente HTTP enfileiram respostas falsas; cada chamada leva
	// a primeira da fila e fica anotada, com o modelo e o corpo.
	if ( ! empty( $GLOBALS['leticia_http_fila'] ) ) {
		$GLOBALS['leticia_http_pedidos'][] = array( 'url' => $url, 'args' => $args );
		$proxima = array_shift( $GLOBALS['leticia_http_fila'] );
		return is_callable( $proxima ) ? call_user_func( $proxima, $url, $args ) : $proxima;
	}
	return new WP_Error( 'rede', 'A suíte offline não faz chamada de rede.' );
}

/** Anota os ganchos desagendados, para os casos da desinstalação. */
function wp_unschedule_hook( $gancho ) {
	$GLOBALS['leticia_desagendados'][] = $gancho;
	return 1;
}

/** Enfileira respostas falsas para wp_remote_post. */
function leticia_http_enfileirar( array $respostas ) {
	$GLOBALS['leticia_http_fila']    = $respostas;
	$GLOBALS['leticia_http_pedidos'] = array();
}

function leticia_http_pedidos() {
	return isset( $GLOBALS['leticia_http_pedidos'] ) ? $GLOBALS['leticia_http_pedidos'] : array();
}

function wp_remote_retrieve_response_code( $r ) {
	return is_array( $r ) && isset( $r['response']['code'] ) ? $r['response']['code'] : 0;
}

function wp_remote_retrieve_body( $r ) {
	return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
}

function wp_json_encode( $dados, $opcoes = 0 ) {
	return json_encode( $dados, $opcoes | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

$GLOBALS['leticia_acoes'] = array();

function do_action( $tag ) {
	$GLOBALS['leticia_acoes'][] = array( 'tag' => $tag, 'args' => array_slice( func_get_args(), 1 ) );
}

function leticia_acoes_disparadas( $tag ) {
	$saida = array();
	foreach ( $GLOBALS['leticia_acoes'] as $acao ) {
		if ( $acao['tag'] === $tag ) {
			$saida[] = $acao['args'];
		}
	}
	return $saida;
}

function leticia_zerar_acoes() {
	$GLOBALS['leticia_acoes'] = array();
}

function rawurlencode_stub() {}

// ------------------------------------------- sistema de arquivos

function trailingslashit( $caminho ) {
	return rtrim( (string) $caminho, "/\\" ) . '/';
}

function wp_mkdir_p( $caminho ) {
	return is_dir( $caminho ) ? true : mkdir( $caminho, 0777, true );
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

/** A suíte trabalha numa pasta temporária, nunca na do WordPress. */
$GLOBALS['leticia_pasta_teste'] = sys_get_temp_dir() . '/leticia-teste-' . getmypid();
add_filter(
	'leticia_pasta_base',
	function () {
		return $GLOBALS['leticia_pasta_teste'];
	}
);

register_shutdown_function(
	function () {
		$raiz = $GLOBALS['leticia_pasta_teste'];
		if ( ! is_dir( $raiz ) ) {
			return;
		}
		$itens = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $raiz, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $itens as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $raiz );
	}
);

// ------------------------------------------------ e-mail e agendamento

$GLOBALS['leticia_emails'] = array();

function wp_mail( $para, $assunto, $corpo, $cabecalhos = array(), $anexos = array() ) {
	// Aceita só os N primeiros e recusa o resto: o servidor que cai no meio
	// de um briefing em várias partes.
	if ( isset( $GLOBALS['leticia_email_limite'] ) ) {
		if ( $GLOBALS['leticia_email_limite'] < 1 ) {
			return false;
		}
		$GLOBALS['leticia_email_limite']--;
	}
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

function admin_url( $caminho = '' ) {
	return 'https://exemplo.test/wp-admin/' . ltrim( (string) $caminho, '/' );
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
function rest_url( $caminho = '' ) { return 'https://exemplo.test/wp-json/' . ltrim( (string) $caminho, '/' ); }
function wp_create_nonce() { return 'nonce-de-teste'; }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function home_url( $caminho = '' ) { return 'https://exemplo.test/' . ltrim( (string) $caminho, '/' ); }

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

function check_admin_referer( $acao ) {
	$enviado = isset( $_REQUEST['_wpnonce'] ) ? $_REQUEST['_wpnonce'] : '';
	if ( 'nonce-' . $acao !== $enviado ) {
		throw new Leticia_Morreu( 'nonce' );
	}
	return 1;
}
function wp_safe_redirect( $url ) { throw new Leticia_Redirecionou( $url ); }
function wp_die( $mensagem = '' ) { throw new Leticia_Morreu( (string) $mensagem ); }

// ----------------------------------------------------- login e permissões
/** Logado só quando o caso diz: $GLOBALS['leticia_logado'] = true. */
function is_user_logged_in() { return ! empty( $GLOBALS['leticia_logado'] ); }
function wp_verify_nonce( $nonce, $acao = -1 ) { return 'nonce-' . $acao === $nonce ? 1 : false; }
function wp_login_url( $voltar = '' ) { return 'https://exemplo.test/wp-login.php?redirect_to=' . rawurlencode( $voltar ); }
function get_permalink( $post = 0 ) { return is_object( $post ) && isset( $post->permalink ) ? $post->permalink : 'https://exemplo.test/links/'; }
function wp_get_current_user() { return (object) array( 'display_name' => 'Equipe Teste' ); }
function get_posts( $args = array() ) { return isset( $GLOBALS['leticia_paginas'] ) ? $GLOBALS['leticia_paginas'] : array(); }
