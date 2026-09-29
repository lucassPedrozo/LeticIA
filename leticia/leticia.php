<?php
/**
 * Plugin Name:       LetícIA
 * Description:       O briefing do Site Express conversado: uma pergunta por vez, com validação no servidor e envio garantido mesmo com a IA fora do ar. Entra na página pelo shortcode [leticia].
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Joinvix
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'LETICIA_VERSAO', '0.1.0' );
define( 'LETICIA_DIR', plugin_dir_path( __FILE__ ) );
define( 'LETICIA_URL', plugin_dir_url( __FILE__ ) );

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

/**
 * O tique diário de manutenção.
 *
 * Hoje ele varre os envios pela metade que ninguém terminou. Sem isso, todo
 * briefing abandonado no meio de um vídeo deixa os pedaços em disco para
 * sempre — e ninguém repara em disco enchendo até o dia em que o site para de
 * gravar.
 */
const LETICIA_CRON = 'leticia_diario';

add_action( LETICIA_CRON, array( 'Leticia_Arquivos', 'limpar_velhos' ) );
add_action( LETICIA_CRON, array( 'Leticia_Registro', 'expurgar' ) );

// A checagem dos modelos: uma geração mínima em cada um, com aviso quando um
// passa a falhar. A listagem da API não serve — modelo desativado continua
// listado.
add_action( LETICIA_CRON, array( 'Leticia_Modelos', 'checar' ) );
add_action( LETICIA_CRON, array( 'Leticia_Retomada', 'lembrar' ) );

// A fila de reentrega: e-mail que falhou é retentado, não esquecido.
add_action( Leticia_Entrega::CRON_REENTREGA, array( 'Leticia_Entrega', 'retentar' ) );

/**
 * Agendamento conferido em toda carga, não só na ativação.
 *
 * `register_activation_hook` só dispara quando alguém clica em "Ativar".
 * Atualizar o plugin por cima dos arquivos não dispara nada, e uma restauração
 * de banco ou um plugin de limpeza de cron apagam o agendamento sem avisar. A
 * checagem custa uma opção autocarregada.
 */
add_action(
	'plugins_loaded',
	function () {
		leticia_agendar();
		// Tabela velha para código novo é o outro jeito de o registro sumir
		// calado: o cliente vê a tela normal e o briefing não existe.
		$armazem = Leticia_Registro::armazem();
		if ( $armazem instanceof Leticia_Armazem_Wpdb ) {
			$armazem->conferir_tabela();
		}
	}
);

function leticia_agendar() {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( LETICIA_CRON ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', LETICIA_CRON );
	}
}

Leticia_Rest::iniciar();
Leticia_Tela::iniciar();
Leticia_Links::iniciar();
Leticia_Download::iniciar();

// O painel só carrega no wp-admin: a página pública não precisa de uma linha dele.
if ( is_admin() ) {
	require_once LETICIA_DIR . 'admin/class-leticia-admin.php';
	require_once LETICIA_DIR . 'admin/class-leticia-painel.php';
	Leticia_Admin::iniciar();

	add_filter(
		'plugin_action_links_' . plugin_basename( __FILE__ ),
		function ( $links ) {
			array_unshift( $links, '<a href="' . esc_url( Leticia_Admin::url() ) . '">Briefings e configuração</a>' );
			return $links;
		}
	);
}

register_activation_hook( __FILE__, 'leticia_ao_ativar' );
register_deactivation_hook( __FILE__, 'leticia_ao_desativar' );

function leticia_ao_ativar() {
	Leticia_Config::ao_ativar();
	Leticia_Registro::instalar();
	Leticia_Arquivos::proteger( Leticia_Arquivos::pasta_base() );
	leticia_agendar();
}

function leticia_ao_desativar() {
	Leticia_Base::limpar_cache();
	$proximo = wp_next_scheduled( LETICIA_CRON );
	if ( $proximo ) {
		wp_unschedule_event( $proximo, LETICIA_CRON );
	}
}
