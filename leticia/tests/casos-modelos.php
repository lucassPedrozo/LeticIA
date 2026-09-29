<?php
/**
 * O cliente HTTP, a pausa por modelo e a checagem diária.
 *
 * Aqui o filtro `leticia_pre_gerar` não entra: as respostas falsas passam por
 * `wp_remote_post`, para o caminho coberto ser o de produção — cadeia de
 * modelos, tentativas, prazo e disjuntor.
 *
 * O que se protege é a medição que motivou tudo isto: resposta em loop até o
 * teto custava duas chamadas e até 45 s; a reserva estava morta e ninguém viu;
 * e cada cliente esperava o mesmo tempo esgotado, um por vez.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$ligar = function ( $reserva = 'gemini-3.1-flash-lite' ) {
	update_option(
		Leticia_Config::OPCAO,
		array(
			'GEMINI_API_KEY'       => 'teste',
			'GEMINI_MODEL'         => 'gemini-3.5-flash-lite',
			'GEMINI_MODEL_RESERVA' => $reserva,
			'ATIVA'                => '1',
			'DESTINO'              => 'briefing@example.com',
			'REMETENTE'            => 'formulario@example.com',
		)
	);
	$armazem = new Leticia_Armazem_Json( Leticia_Armazem_Json::MEMORIA );
	$armazem->instalar();
	Leticia_Registro::usar_armazem( $armazem );
	Leticia_Modelos::zerar();
	Leticia_Limites::zerar();
	leticia_zerar_acoes();
	leticia_zerar_emails();
	remove_all_filters( 'leticia_pre_gerar' );
	remove_all_filters( 'leticia_pre_sondar' );
	leticia_http_enfileirar( array() );
	foreach ( array( 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-2.5-flash-lite' ) as $m ) {
		delete_transient( Leticia_Gemini::PREFIXO_SEM_PENSAMENTO . md5( $m ) );
	}
};

/** Uma resposta 200 do Gemini com este texto. */
$ok = function ( $texto, $finish = 'STOP' ) {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array(
			'candidates'    => array( array( 'content' => array( 'parts' => array( array( 'text' => $texto ) ) ), 'finishReason' => $finish ) ),
			'usageMetadata' => array( 'promptTokenCount' => 10, 'candidatesTokenCount' => 5 ),
		) ),
	);
};

$erro = function ( $codigo, $mensagem = '' ) {
	return array(
		'response' => array( 'code' => $codigo ),
		'body'     => wp_json_encode( array( 'error' => array( 'code' => $codigo, 'message' => $mensagem ) ) ),
	);
};

$tempo = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 6001 milliseconds' );

/** O modelo de cada pedido feito, na ordem. */
$modelos_pedidos = function () {
	return array_map(
		function ( $p ) {
			return preg_match( '#/models/([^:]+):#', $p['url'], $m ) ? $m[1] : '';
		},
		leticia_http_pedidos()
	);
};

$json_bom = wp_json_encode( array( 'tipo' => 'resposta', 'suficiente' => true, 'comentario' => 'Cobertura de acrílico deixa a área gourmet clara.' ) );

// ------------------------------------------------------------------ o pedido

$casos[] = array(
	'grupo'    => 'modelos · pedido',
	'nome'     => 'o pedido sai com teto de saída do campo, temperatura 0,4 e raciocínio no mínimo',
	'executar' => function () use ( $ligar, $ok, $json_bom ) {
		$ligar();
		leticia_http_enfileirar( array( $ok( $json_bom ), $ok( $json_bom ) ) );

		Leticia_Modelo::consultar( Leticia_Campos::por_chave( 'dominio' ), 'auroracoberturas.com.br' );
		Leticia_Modelo::consultar( Leticia_Campos::por_chave( 'ramo' ), 'telhas de acrílico para área gourmet' );

		$pedidos = leticia_http_pedidos();
		if ( 2 !== count( $pedidos ) ) {
			return 'esperava 2 pedidos, saíram ' . count( $pedidos );
		}
		$dominio = json_decode( $pedidos[0]['args']['body'], true )['generationConfig'];
		$ramo    = json_decode( $pedidos[1]['args']['body'], true )['generationConfig'];

		if ( Leticia_Gemini::MAX_TOKENS !== $dominio['maxOutputTokens'] || Leticia_Gemini::MAX_TOKENS_RASCUNHO !== $ramo['maxOutputTokens'] ) {
			return 'teto de saída: domínio ' . $dominio['maxOutputTokens'] . ', ramo ' . $ramo['maxOutputTokens'];
		}
		if ( 0.4 !== $dominio['temperature'] ) {
			return 'temperatura ' . $dominio['temperature'];
		}
		if ( ! isset( $dominio['thinkingConfig']['thinkingLevel'] ) || 'minimal' !== $dominio['thinkingConfig']['thinkingLevel'] ) {
			return 'o raciocínio não foi fixado no mínimo';
		}
		return $pedidos[0]['args']['timeout'] <= Leticia_Gemini::TIMEOUT ? null : 'tentativa com prazo de ' . $pedidos[0]['args']['timeout'] . ' s';
	},
);

$casos[] = array(
	'grupo'    => 'modelos · pedido',
	'nome'     => 'a família 2.5 recebe orçamento zero, e modelo que recusa o parâmetro segue sem ele',
	'executar' => function () use ( $ligar, $ok, $erro, $json_bom ) {
		$ligar();
		$pensar = Leticia_Gemini::pensamento( 'gemini-2.5-flash-lite' );
		if ( array( 'thinkingBudget' => 0 ) !== $pensar ) {
			return '2.5 recebeu ' . wp_json_encode( $pensar );
		}

		leticia_http_enfileirar( array( $erro( 400, 'Invalid value at thinking_config.thinking_level' ), $ok( $json_bom ) ) );
		$r = Leticia_Modelo::consultar( Leticia_Campos::por_chave( 'dominio' ), 'auroracoberturas.com.br' );
		if ( $r['degradado'] ) {
			return 'o parâmetro recusado derrubou a conversa';
		}
		$segundo = json_decode( leticia_http_pedidos()[1]['args']['body'], true );
		if ( isset( $segundo['generationConfig']['thinkingConfig'] ) ) {
			return 'a segunda tentativa repetiu o parâmetro recusado';
		}
		return null === Leticia_Gemini::pensamento( 'gemini-3.5-flash-lite' ) ? null : 'a recusa não foi lembrada';
	},
);

// ------------------------------------------------------------------ resposta cortada

$casos[] = array(
	'grupo'    => 'modelos · pedido',
	'nome'     => 'resposta cortada no teto não é repetida: degrada na hora, com uma chamada só',
	'executar' => function () use ( $ligar, $ok ) {
		$ligar();
		$loop = '{"tipo":"resposta","suficiente":true,"comentario":"atendo Joinville e região muito bem por sinal e com rapidez para quem precisa';
		leticia_http_enfileirar( array( $ok( $loop, 'MAX_TOKENS' ), $ok( $loop, 'MAX_TOKENS' ) ) );

		$r = Leticia_Modelo::consultar( Leticia_Campos::por_chave( 'ramo' ), 'telhas' );
		if ( ! $r['degradado'] ) {
			return 'não degradou';
		}
		if ( 1 !== count( leticia_http_pedidos() ) ) {
			return 'repetiu a chamada cortada: ' . count( leticia_http_pedidos() ) . ' pedidos';
		}
		$registro = leticia_acoes_disparadas( 'leticia_json_quebrado' );
		return $registro && 'cortada' === $registro[0][1] ? null : 'não registrou o motivo';
	},
);

$casos[] = array(
	'grupo'    => 'modelos · pedido',
	'nome'     => 'resposta ilegível sem corte ainda ganha uma segunda chance',
	'executar' => function () use ( $ligar, $ok, $json_bom ) {
		$ligar();
		leticia_http_enfileirar( array( $ok( 'Claro! Vou te ajudar.' ), $ok( $json_bom ) ) );
		$r = Leticia_Modelo::consultar( Leticia_Campos::por_chave( 'ramo' ), 'telhas de acrílico para área gourmet' );
		return ! $r['degradado'] && 2 === count( leticia_http_pedidos() ) ? null : 'não fez a segunda tentativa';
	},
);

// ------------------------------------------------------------------ cadeia e pausa

$casos[] = array(
	'grupo'    => 'modelos · pausa',
	'nome'     => 'tempo esgotado vai direto para a reserva, sem repetir no mesmo modelo',
	'executar' => function () use ( $ligar, $ok, $tempo, $json_bom, $modelos_pedidos ) {
		$ligar();
		leticia_http_enfileirar( array( $tempo, $ok( $json_bom ) ) );
		$r = Leticia_Gemini::gerar( 'x', 'y' );
		if ( is_wp_error( $r ) ) {
			return 'falhou: ' . $r->get_error_message();
		}
		return array( 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite' ) === $modelos_pedidos() ? null : 'ordem: ' . implode( ', ', $modelos_pedidos() );
	},
);

$casos[] = array(
	'grupo'    => 'modelos · pausa',
	'nome'     => 'três falhas seguidas pausam o modelo, e as chamadas seguintes nem tentam ele',
	'executar' => function () use ( $ligar, $ok, $tempo, $json_bom, $modelos_pedidos ) {
		$ligar();
		for ( $i = 0; $i < Leticia_Modelos::FALHAS_PARA_PAUSAR; $i++ ) {
			leticia_http_enfileirar( array( $tempo, $ok( $json_bom ) ) );
			Leticia_Gemini::gerar( 'x', 'y' );
		}
		if ( ! Leticia_Modelos::pausado( 'gemini-3.5-flash-lite' ) ) {
			return 'não pausou depois de ' . Leticia_Modelos::FALHAS_PARA_PAUSAR . ' falhas';
		}
		leticia_http_enfileirar( array( $ok( $json_bom ) ) );
		Leticia_Gemini::gerar( 'x', 'y' );
		return array( 'gemini-3.1-flash-lite' ) === $modelos_pedidos() ? null : 'ainda tentou o pausado: ' . implode( ', ', $modelos_pedidos() );
	},
);

$casos[] = array(
	'grupo'    => 'modelos · pausa',
	'nome'     => 'um sucesso no meio zera a contagem de falhas',
	'executar' => function () use ( $ligar, $ok, $tempo, $json_bom ) {
		$ligar( '' );
		leticia_http_enfileirar( array( $tempo ) );
		Leticia_Gemini::gerar( 'x', 'y' );
		leticia_http_enfileirar( array( $tempo ) );
		Leticia_Gemini::gerar( 'x', 'y' );
		leticia_http_enfileirar( array( $ok( $json_bom ) ) );
		Leticia_Gemini::gerar( 'x', 'y' );
		leticia_http_enfileirar( array( $tempo ) );
		Leticia_Gemini::gerar( 'x', 'y' );
		return Leticia_Modelos::pausado( 'gemini-3.5-flash-lite' ) ? 'pausou somando falhas separadas por um sucesso' : null;
	},
);

$casos[] = array(
	'grupo'    => 'modelos · pausa',
	'nome'     => 'com todos pausados, a resposta é instantânea e sem pedido nenhum',
	'executar' => function () use ( $ligar, $ok, $json_bom ) {
		$ligar();
		Leticia_Modelos::pausar( 'gemini-3.5-flash-lite', 120, 'teste' );
		Leticia_Modelos::pausar( 'gemini-3.1-flash-lite', 120, 'teste' );
		leticia_http_enfileirar( array( $ok( $json_bom ) ) );

		$comeco = microtime( true );
		$r      = Leticia_Modelo::consultar( Leticia_Campos::por_chave( 'ramo' ), 'telhas de acrílico para área gourmet' );
		if ( ! $r['degradado'] ) {
			return 'não degradou';
		}
		if ( leticia_http_pedidos() ) {
			return 'fez pedido com todos os modelos pausados';
		}
		$saude = Leticia_Rest::saude( new WP_REST_Request() )->get_data();
		return false === $saude['conversando'] ? null : 'a /saude disse que está conversando';
	},
);

$casos[] = array(
	'grupo'    => 'modelos · pausa',
	'nome'     => 'cota do dia pausa até a meia-noite do Pacífico; a do minuto, um minuto',
	'executar' => function () use ( $ligar, $erro, $ok, $json_bom ) {
		$ligar( '' );
		leticia_http_enfileirar( array( $erro( 429, 'Quota exceeded for metric: generate_content_free_tier_requests, limit: 20. quotaId: GenerateRequestsPerDayPerProjectPerModel-FreeTier' ) ) );
		Leticia_Gemini::gerar( 'x', 'y' );
		$pausa = Leticia_Modelos::pausa( 'gemini-3.5-flash-lite' );
		if ( ! $pausa || $pausa['ate'] - time() < 60 || $pausa['ate'] - time() > DAY_IN_SECONDS ) {
			return 'a cota do dia não pausou até a virada: ' . wp_json_encode( $pausa );
		}

		$ligar( '' );
		leticia_http_enfileirar( array( $erro( 429, 'Quota exceeded. quotaId: GenerateRequestsPerMinutePerProjectPerModel-FreeTier' ) ) );
		Leticia_Gemini::gerar( 'x', 'y' );
		$pausa = Leticia_Modelos::pausa( 'gemini-3.5-flash-lite' );
		if ( ! $pausa || $pausa['ate'] - time() > Leticia_Modelos::PAUSA_COTA_MINUTO ) {
			return 'a cota do minuto pausou por ' . ( $pausa ? $pausa['ate'] - time() : 0 ) . ' s';
		}

		// Meia-noite em Los Angeles: 1º de janeiro de 2026, 23:30 lá = 30 minutos.
		$quase = ( new DateTime( '2026-01-01 23:30:00', new DateTimeZone( 'America/Los_Angeles' ) ) )->getTimestamp();
		return 1800 === Leticia_Modelos::segundos_ate_virada( $quase ) ? null : 'virada calculada: ' . Leticia_Modelos::segundos_ate_virada( $quase );
	},
);

$casos[] = array(
	'grupo'    => 'modelos · pausa',
	'nome'     => 'modelo que sumiu (404) sai por horas; chave recusada não pausa nem troca de modelo',
	'executar' => function () use ( $ligar, $erro, $ok, $json_bom, $modelos_pedidos ) {
		$ligar();
		leticia_http_enfileirar( array( $erro( 404, 'This model is no longer available to new users.' ), $ok( $json_bom ) ) );
		$r = Leticia_Gemini::gerar( 'x', 'y' );
		$pausa = Leticia_Modelos::pausa( 'gemini-3.5-flash-lite' );
		if ( is_wp_error( $r ) || ! $pausa || $pausa['ate'] - time() < 3600 ) {
			return 'o 404 não pausou por horas, ou não foi para a reserva';
		}

		$ligar();
		leticia_http_enfileirar( array( $erro( 403, 'API key not valid.' ), $ok( $json_bom ) ) );
		$r = Leticia_Gemini::gerar( 'x', 'y' );
		if ( ! is_wp_error( $r ) || 'chave' !== $r->get_error_code() ) {
			return 'a chave recusada não voltou como erro';
		}
		if ( 1 !== count( $modelos_pedidos() ) ) {
			return 'tentou outro modelo com a mesma chave recusada';
		}
		return Leticia_Modelos::pausado( 'gemini-3.5-flash-lite' ) ? 'pausou o modelo por problema de chave' : null;
	},
);

// ------------------------------------------------------------------ checagem

$casos[] = array(
	'grupo'    => 'modelos · checagem',
	'nome'     => 'a checagem testa principal e reserva, pausa o que falhou e avisa uma vez só',
	'executar' => function () use ( $ligar ) {
		$ligar( 'gemini-2.5-flash-lite' );
		add_filter( 'leticia_pre_sondar', function ( $nada, $modelo ) {
			return 'gemini-2.5-flash-lite' === $modelo
				? array( 'ok' => false, 'codigo' => 'modelo_sumiu', 'mensagem' => 'no longer available to new users', 'ms' => 300 )
				: array( 'ok' => true, 'codigo' => '', 'mensagem' => '', 'ms' => 900 );
		} );

		$r = Leticia_Modelos::checar();
		if ( 2 !== count( $r ) || ! $r['gemini-3.5-flash-lite']['ok'] || $r['gemini-2.5-flash-lite']['ok'] ) {
			return 'resultado: ' . wp_json_encode( $r );
		}
		if ( ! Leticia_Modelos::pausado( 'gemini-2.5-flash-lite' ) ) {
			return 'a reserva morta não saiu da cadeia';
		}
		$emails = leticia_emails_enviados();
		if ( 1 !== count( $emails ) || false === strpos( $emails[0]['assunto'], 'gemini-2.5-flash-lite' ) ) {
			return 'o aviso não saiu direito: ' . count( $emails ) . ' e-mails';
		}
		if ( false === strpos( Leticia_Email::texto( $emails[0]['corpo'] ), 'não há reserva para assumir' ) ) {
			return 'o aviso não disse o que muda';
		}

		// No dia seguinte, a mesma falha não manda outro e-mail.
		Leticia_Modelos::checar();
		return 1 === count( leticia_emails_enviados() ) ? null : 'mandou e-mail de novo pela mesma falha';
	},
);

$casos[] = array(
	'grupo'    => 'modelos · checagem',
	'nome'     => 'o painel aponta o modelo que falhou e a falta de reserva; a /saude não expõe a mensagem',
	'executar' => function () use ( $ligar ) {
		$ligar( 'gemini-2.5-flash-lite' );
		add_filter( 'leticia_pre_sondar', function ( $nada, $modelo ) {
			return 'gemini-2.5-flash-lite' === $modelo
				? array( 'ok' => false, 'codigo' => 'modelo_sumiu', 'mensagem' => 'no longer available to new users', 'ms' => 300 )
				: array( 'ok' => true, 'codigo' => '', 'mensagem' => '', 'ms' => 900 );
		} );
		Leticia_Modelos::checar( false );

		ob_start();
		Leticia_Admin::atencao();
		$painel = ob_get_clean();
		if ( false === strpos( $painel, 'O modelo de reserva não respondeu na checagem' ) || false === strpos( $painel, 'no longer available' ) ) {
			return 'o painel não apontou a reserva morta';
		}

		$saude = wp_json_encode( Leticia_Rest::saude( new WP_REST_Request() )->get_data() );
		if ( false !== strpos( $saude, 'no longer available' ) ) {
			return 'a /saude pública expôs a mensagem de erro';
		}
		if ( false === strpos( $saude, '"checado_ok":false' ) ) {
			return 'a /saude não disse que a reserva falhou';
		}

		$ligar( '' );
		ob_start();
		Leticia_Admin::atencao();
		return false !== strpos( ob_get_clean(), 'Sem modelo de reserva' ) ? null : 'o painel não avisou que não há reserva';
	},
);

$casos[] = array(
	'grupo'    => 'modelos · checagem',
	'nome'     => 'modelo que volta a responder sai da pausa na checagem',
	'executar' => function () use ( $ligar ) {
		$ligar();
		Leticia_Modelos::pausar( 'gemini-3.5-flash-lite', 3600, 'teste' );
		add_filter( 'leticia_pre_sondar', function () {
			return array( 'ok' => true, 'codigo' => '', 'mensagem' => '', 'ms' => 500 );
		} );
		Leticia_Modelos::checar( false );
		return Leticia_Modelos::pausado( 'gemini-3.5-flash-lite' ) ? 'continuou pausado depois de responder' : null;
	},
);

$casos[] = array(
	'grupo'    => 'modelos · checagem',
	'nome'     => 'a sonda tenta duas vezes antes de chamar tempo esgotado de falha',
	'executar' => function () use ( $ligar, $ok, $tempo, $erro ) {
		$ligar();
		leticia_http_enfileirar( array( $tempo, $ok( 'ok' ) ) );
		$r = Leticia_Gemini::sondar( 'gemini-3.1-flash-lite' );
		if ( ! $r['ok'] || 2 !== count( leticia_http_pedidos() ) ) {
			return 'um tempo esgotado já contou como modelo fora';
		}
		if ( Leticia_Gemini::TIMEOUT_SONDA !== leticia_http_pedidos()[0]['args']['timeout'] ) {
			return 'a sonda usou o prazo curto da conversa';
		}
		// 404 é conclusivo: não repete.
		leticia_http_enfileirar( array( $erro( 404, 'no longer available' ), $ok( 'ok' ) ) );
		$r = Leticia_Gemini::sondar( 'gemini-2.5-flash-lite' );
		return ! $r['ok'] && 1 === count( leticia_http_pedidos() ) ? null : 'repetiu um 404';
	},
);

// ------------------------------------------------------------------ chave

$casos[] = array(
	'grupo'    => 'modelos · chave',
	'nome'     => 'a chave colada com a linha do .env, aspas ou espaço chega limpa',
	'executar' => function () {
		// Falsa, e escrita em dois pedaços de propósito: uma chave inteira no
		// arquivo, mesmo inventada, faz o GitHub barrar o push por parecer
		// chave de verdade.
		$certa = 'AIza' . 'SyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q';
		$sujas = array(
			'LETICIA_GEMINI_API_KEY=' . $certa,
			'"' . $certa . '"',
			"  " . $certa . "\n",
			"GEMINI_API_KEY = '" . $certa . "'",
			$certa . "\u{200B}",
		);
		foreach ( $sujas as $suja ) {
			if ( $certa !== Leticia_Config::limpar_chave( $suja ) ) {
				return 'não limpou: ' . wp_json_encode( $suja ) . ' → ' . wp_json_encode( Leticia_Config::limpar_chave( $suja ) );
			}
		}
		update_option( Leticia_Config::OPCAO, Leticia_Config::sanitizar( array( 'GEMINI_API_KEY' => 'LETICIA_GEMINI_API_KEY="' . $certa . '"' ) ) );
		$resumo = Leticia_Config::resumo_chave();
		update_option( Leticia_Config::OPCAO, array() );
		if ( 39 !== $resumo['tamanho'] || '5p6Q' !== $resumo['fim'] || ! $resumo['formato_ok'] ) {
			return 'resumo: ' . wp_json_encode( $resumo );
		}
		return false === strpos( wp_json_encode( $resumo ), 'AIzaSy' ) ? null : 'o resumo expôs a chave';
	},
);

$casos[] = array(
	'grupo'    => 'modelos · chave',
	'nome'     => '"API key not valid" é erro de chave: não pausa modelo nem gasta chamada na reserva',
	'executar' => function () use ( $ligar, $erro, $ok, $json_bom, $modelos_pedidos ) {
		$ligar();
		leticia_http_enfileirar( array( $erro( 400, 'API key not valid. Please pass a valid API key.' ), $ok( $json_bom ) ) );
		$r = Leticia_Gemini::gerar( 'x', 'y' );
		if ( ! is_wp_error( $r ) || 'chave' !== $r->get_error_code() ) {
			return 'veio como ' . ( is_wp_error( $r ) ? $r->get_error_code() : 'sucesso' );
		}
		if ( 1 !== count( $modelos_pedidos() ) ) {
			return 'tentou a reserva com a mesma chave';
		}
		return Leticia_Modelos::pausado( 'gemini-3.5-flash-lite' ) ? 'pausou o modelo por causa da chave' : null;
	},
);

$casos[] = array(
	'grupo'    => 'modelos · chave',
	'nome'     => 'com a chave recusada, a checagem testa uma vez só e o painel fala da chave, não do modelo',
	'executar' => function () use ( $ligar ) {
		$ligar();
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'GEMINI_API_KEY' => 'AIza-errada' ) ) );
		$sondas = 0;
		add_filter( 'leticia_pre_sondar', function () use ( &$sondas ) {
			$sondas++;
			return array( 'ok' => false, 'codigo' => 'chave', 'mensagem' => 'A chave da API foi recusada. API key not valid.', 'ms' => 200 );
		} );

		$r = Leticia_Modelos::checar();
		if ( 1 !== $sondas || 2 !== count( $r ) || 'chave' !== $r['gemini-3.1-flash-lite']['codigo'] ) {
			return 'sondas: ' . $sondas . ', resultado: ' . wp_json_encode( $r );
		}
		$emails = leticia_emails_enviados();
		if ( 1 !== count( $emails ) || false === strpos( $emails[0]['assunto'], 'Chave da API recusada' ) ) {
			return 'o e-mail não falou da chave';
		}

		ob_start();
		Leticia_Admin::atencao();
		$painel = ob_get_clean();
		if ( false === strpos( $painel, 'A chave da API foi recusada pelo Google' ) || false === strpos( $painel, 'termina em <code>rada</code>' ) ) {
			return 'o painel não apontou a chave';
		}
		if ( false !== strpos( $painel, 'Troque o modelo' ) ) {
			return 'o painel mandou trocar o modelo por um problema de chave';
		}
		return false !== strpos( $painel, '.env</code> só vale para o ambiente local' ) ? null : 'o painel não explicou que o .env é só local';
	},
);

$casos[] = array(
	'grupo'    => 'modelos · checagem',
	'nome'     => 'a checagem está no tique diário',
	'executar' => function () {
		$plugin = file_get_contents( LETICIA_DIR . 'leticia.php' );
		return false !== strpos( $plugin, "add_action( LETICIA_CRON, array( 'Leticia_Modelos', 'checar' ) )" ) ? null : 'a checagem não foi agendada';
	},
);

// Limpeza: os próximos grupos não podem herdar modelo pausado nem fila HTTP.
$casos[] = array(
	'grupo'    => 'modelos · checagem',
	'nome'     => 'nada fica pausado para os casos seguintes',
	'executar' => function () use ( $ligar ) {
		$ligar();
		update_option( Leticia_Config::OPCAO, array() );
		return null;
	},
);

return $casos;
