<?php
/**
 * O contrato do armazém, nos dois armazéns.
 *
 * Cada caso daqui roda duas vezes: no arquivo JSON e no banco (com um $wpdb
 * de mentira). O resto da suíte usa só o JSON, e foi assim que passou um
 * defeito de produção inteiro — no banco, marcar um briefing como entregue
 * apagava as respostas dele.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/wpdb-falso.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$casos = array();

$armazens = array(
	'json'  => function () {
		$caminho = sys_get_temp_dir() . '/leticia-teste-armazem-' . getmypid() . '.json';
		if ( file_exists( $caminho ) ) {
			unlink( $caminho );
		}
		$a = new Leticia_Armazem_Json( $caminho );
		$a->instalar();
		return $a;
	},
	'banco' => function () {
		$GLOBALS['wpdb'] = new Leticia_Wpdb_Falso();
		// Sem instalar(): ele chama o dbDelta do WordPress, que aqui não existe.
		// A tabela do $wpdb falso nasce no primeiro insert.
		return new Leticia_Armazem_Wpdb();
	},
);

$sessao = 'cccccccccccccccccccccccccccccccc';

$respostas = array(
	'empresa' => array( 'valor' => 'Padaria Aurora', 'bruto' => 'Padaria Aurora', 'pulado' => false, 'pendente' => false, 'link' => '', 'arquivos' => array() ),
	'logo'    => array( 'valor' => '', 'bruto' => '', 'pulado' => false, 'pendente' => true, 'link' => '', 'arquivos' => array() ),
);

foreach ( $armazens as $nome => $fabrica ) {

	$casos[] = array(
		'grupo'    => 'armazém · ' . $nome,
		'nome'     => 'gravar só algumas colunas não apaga as outras',
		'executar' => function () use ( $fabrica, $sessao, $respostas ) {
			$a = $fabrica();
			$a->gravar_briefing( $sessao, array( 'respostas' => $respostas, 'empresa' => 'Padaria Aurora', 'enviado_em' => 1700000000, 'pendencias' => array( 'logo' ) ) );
			$a->gravar_briefing( $sessao, array( 'entregue' => 1, 'tentativas' => 1 ) );

			$l = $a->ler_briefing( $sessao );
			if ( ! $l ) {
				return 'o briefing sumiu';
			}
			if ( empty( $l['respostas']['empresa'] ) ) {
				return 'marcar como entregue apagou as respostas';
			}
			if ( 1700000000 !== (int) $l['enviado_em'] ) {
				return 'marcar como entregue zerou o carimbo de envio';
			}
			if ( array( 'logo' ) !== array_values( $l['pendencias'] ) ) {
				return 'as pendências mudaram: ' . implode( ',', $l['pendencias'] );
			}
			return 1 === (int) $l['entregue'] ? null : 'não marcou a entrega';
		},
	);

	$casos[] = array(
		'grupo'    => 'armazém · ' . $nome,
		'nome'     => 'regravar as respostas não desfaz a entrega',
		'executar' => function () use ( $fabrica, $sessao, $respostas ) {
			$a = $fabrica();
			$a->gravar_briefing( $sessao, array( 'respostas' => $respostas, 'enviado_em' => 1700000000 ) );
			$a->gravar_briefing( $sessao, array( 'entregue' => 1, 'tentativas' => 2 ) );
			// A logomarca chega depois, pelo link: as respostas são regravadas.
			$a->gravar_briefing( $sessao, array( 'respostas' => $respostas, 'pendencias' => array() ) );

			$l = $a->ler_briefing( $sessao );
			if ( 1 !== (int) $l['entregue'] || 2 !== (int) $l['tentativas'] ) {
				return 'a entrega foi desfeita: entregue=' . $l['entregue'] . ' tentativas=' . $l['tentativas'];
			}
			return $l['pendencias'] ? 'a pendência não saiu' : null;
		},
	);

	$casos[] = array(
		'grupo'    => 'armazém · ' . $nome,
		'nome'     => 'a data de criação não é reescrita',
		'executar' => function () use ( $fabrica, $sessao ) {
			$a = $fabrica();
			$a->gravar_briefing( $sessao, array( 'criado_em' => 1600000000, 'empresa' => 'A' ) );
			$a->gravar_briefing( $sessao, array( 'criado_em' => 1800000000, 'empresa' => 'B' ) );
			$l = $a->ler_briefing( $sessao );
			return 1600000000 === (int) $l['criado_em'] ? null : 'criado_em virou ' . $l['criado_em'];
		},
	);

	$casos[] = array(
		'grupo'    => 'armazém · ' . $nome,
		'nome'     => 'listar separa enviados de abandonados',
		'executar' => function () use ( $fabrica ) {
			$a = $fabrica();
			$a->gravar_briefing( str_repeat( '1', 32 ), array( 'enviado_em' => 1700000000 ) );
			$a->gravar_briefing( str_repeat( '2', 32 ), array( 'respondidos' => 3 ) );
			$a->gravar_briefing( str_repeat( '3', 32 ), array( 'respondidos' => 5 ) );

			$enviados    = $a->listar_briefings( array( 'enviados' => true ) );
			$abandonados = $a->listar_briefings( array( 'enviados' => false ) );

			if ( 1 !== count( $enviados ) || 2 !== count( $abandonados ) ) {
				return sprintf( '%d enviados e %d abandonados', count( $enviados ), count( $abandonados ) );
			}
			return 1 === count( $a->listar_briefings( array( 'limite' => 1 ) ) ) ? null : 'o limite não valeu';
		},
	);

	$casos[] = array(
		'grupo'    => 'armazém · ' . $nome,
		'nome'     => 'o expurgo não apaga briefing enviado',
		'executar' => function () use ( $fabrica ) {
			$a = $fabrica();
			$a->gravar_briefing( str_repeat( '1', 32 ), array( 'enviado_em' => 1 ) );
			$a->gravar_briefing( str_repeat( '2', 32 ), array( 'respondidos' => 3 ) );
			// Os dois ficam velhos.
			$a->expurgar_briefings( -1 );
			$sobrou = $a->listar_briefings();
			if ( 1 !== count( $sobrou ) ) {
				return 'sobraram ' . count( $sobrou );
			}
			return str_repeat( '1', 32 ) === $sobrou[0]['sessao'] ? null : 'apagou o enviado';
		},
	);

	$casos[] = array(
		'grupo'    => 'armazém · ' . $nome,
		'nome'     => 'o envio completo deixa o briefing inteiro e entregue',
		'executar' => function () use ( $fabrica, $sessao ) {
			Leticia_Registro::usar_armazem( $fabrica() );
			leticia_zerar_emails();

			$estado = Leticia_Roteiro::novo( 1 );
			foreach ( array( 'responsavel' => 'Marina Alves', 'empresa' => 'Padaria Aurora', 'whatsapp' => '47999998888' ) as $c => $v ) {
				$estado = Leticia_Roteiro::responder( $estado, $c, $v )['estado'];
				Leticia_Registro::salvar( $sessao, $estado );
			}
			$estado = Leticia_Roteiro::marcar_enviado( $estado );
			Leticia_Registro::salvar( $sessao, $estado );
			Leticia_Entrega::enviar( $sessao, $estado );

			$l = Leticia_Registro::briefing( $sessao );
			if ( 3 !== count( $l['respostas'] ) ) {
				return 'depois da entrega, o briefing tem ' . count( $l['respostas'] ) . ' respostas';
			}
			if ( (int) $l['enviado_em'] < 1 ) {
				return 'depois da entrega, ele virou abandonado';
			}
			return 1 === (int) $l['entregue'] ? null : 'não ficou marcado como entregue';
		},
	);
}

return $casos;
