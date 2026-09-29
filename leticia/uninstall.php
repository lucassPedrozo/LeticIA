<?php
/**
 * Roda quando o plugin é excluído em Plugins → Excluir.
 *
 * O WordPress inclui este arquivo sem carregar o plugin. A regra do que sai e
 * do que fica está em `Leticia_Desinstalar`, que não depende de nenhuma outra
 * classe — é por isso que dá para chamar só ela daqui.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-leticia-desinstalar.php';

global $wpdb;
Leticia_Desinstalar::executar( $wpdb );
