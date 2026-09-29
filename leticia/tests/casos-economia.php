<?php
/**
 * A economia de chamadas: o modelo só onde ele agrega.
 *
 * Os dois lados importam igual. Poupar demais é a LetícIA muda justo quando a
 * pessoa precisava dela — "tenho insta", "o de sempre", uma dúvida no meio do
 * domínio. Poupar de menos é pagar chamada para dizer "anotado" com outras
 * palavras.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$zerar = function () {
	$armazem = new Leticia_Armazem_Json( Leticia_Armazem_Json::MEMORIA );
	$armazem->instalar();
	Leticia_Registro::usar_armazem( $armazem );
	Leticia_Limites::zerar();
	leticia_zerar_acoes();
	update_option( Leticia_Config::OPCAO, array( 'GEMINI_API_KEY' => 'teste', 'GEMINI_MODEL' => 'gemini-3.5-flash-lite', 'ATIVA' => '1' ) );
	Leticia_Campos::limpar_cache();

	// Todo pedido ao modelo fica anotado com o campo; a resposta é sempre boa.
	$GLOBALS['leticia_chamadas_campo'] = array();
	remove_all_filters( 'leticia_pre_gerar' );
	add_filter( 'leticia_pre_gerar', function ( $nada, $instrucao ) {
		preg_match( '/O CAMPO DE AGORA: ([^\n]+)/u', $instrucao, $m );
		$GLOBALS['leticia_chamadas_campo'][] = isset( $m[1] ) ? $m[1] : '?';
		return array( 'texto' => wp_json_encode( array( 'tipo' => 'resposta', 'suficiente' => true, 'comentario' => 'Reação do modelo.' ) ), 'modelo' => 'teste', 'uso' => array() );
	} );
};

$pedir = function ( array $params ) {
	return new WP_REST_Request( $params, '' );
};

$dados = function ( $r ) {
	return $r instanceof WP_REST_Response ? $r->get_data() : $r;
};

/** Os 11 campos de texto de um briefing típico, na ordem. */
$tipico = array(
	'responsavel'    => 'Marina Alves',
	'empresa'        => 'Padaria Aurora',
	'whatsapp'       => '47999998888',
	'email'          => 'marina@padariaaurora.com.br',
	'dominio'        => 'padariaaurora.com.br',
	'endereco'       => 'Rua das Flores, 10',
	'ramo'           => 'padaria artesanal de fermentação natural, pães e bolos de festa',
	'servicos'       => 'pães de fermentação natural, bolos por encomenda',
	'contatos_site'  => 'WhatsApp (47) 99999-8888 e e-mail',
	'redes_sociais'  => '@padariaaurora',
	'paginas_extras' => 'Cardápio, Encomendas',
);

/** Responde o briefing até antes de $ate e devolve o estado da tela. */
$ate = function ( $ate ) use ( $pedir, $dados, $tipico ) {
	$t = $dados( Leticia_Rest::abrir( $pedir( array() ) ) );
	foreach ( $tipico as $c => $v ) {
		if ( $c === $ate ) {
			break;
		}
		$t = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => $c, 'texto' => $v ) ) ) );
		if ( ! empty( $t['proposta'] ) ) {
			$t = $dados( Leticia_Rest::proposta( $pedir( array( 'token' => $t['token'], 'campo' => $c, 'acao' => 'dispensar' ) ) ) );
		}
	}
	$GLOBALS['leticia_chamadas_campo'] = array();
	return $t;
};

/** Responde um campo e diz se o modelo foi chamado. */
$chamou = function ( $t, $campo, $texto ) use ( $pedir, $dados ) {
	$GLOBALS['leticia_chamadas_campo'] = array();
	$r = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => $campo, 'texto' => $texto ) ) ) );
	return array( (bool) $GLOBALS['leticia_chamadas_campo'], $r );
};

// ------------------------------------------------------------------ poupa

$casos[] = array(
	'grupo'    => 'economia · poupa',
	'nome'     => 'domínio válido e "ainda não tenho" seguem com a reação escrita, sem chamada',
	'executar' => function () use ( $zerar, $ate, $chamou ) {
		foreach ( array( 'padariaaurora.com.br', 'ainda não tenho' ) as $resposta ) {
			$zerar();
			$t = $ate( 'dominio' );
			list( $foi, $r ) = $chamou( $t, 'dominio', $resposta );
			if ( $foi ) {
				return '"' . $resposta . '" chamou o modelo';
			}
			if ( ! isset( $r['respostas']['dominio'] ) || '' === $r['ponte'] ) {
				return '"' . $resposta . '" não seguiu com reação';
			}
		}
		return 1 <= Leticia_Limites::poupadas_hoje() ? null : 'a chamada poupada não foi contada';
	},
);

$casos[] = array(
	'grupo'    => 'economia · poupa',
	'nome'     => 'contato com canal, sugestão aceita, @ de rede e lista de páginas não chamam',
	'executar' => function () use ( $zerar, $ate, $chamou ) {
		$respostas = array(
			'contatos_site'  => array( 'WhatsApp (47) 99999-8888 e e-mail', 'Só o whats e o horário' ),
			'redes_sociais'  => array( '@padariaaurora', 'instagram.com/padariaaurora' ),
			'paginas_extras' => array( 'Cardápio, Encomendas', 'Cardápio e galeria de bolos' ),
		);
		foreach ( $respostas as $campo => $lista ) {
			foreach ( $lista as $resposta ) {
				$zerar();
				$t = $ate( $campo );
				list( $foi ) = $chamou( $t, $campo, $resposta );
				if ( $foi ) {
					return $campo . ': "' . $resposta . '" chamou o modelo';
				}
			}
		}

		// A sugestão pronta do botão "Usar esses contatos".
		$zerar();
		$t = $ate( 'contatos_site' );
		if ( '' === $t['sugestao'] ) {
			return 'o briefing típico ficou sem sugestão de contato';
		}
		list( $foi ) = $chamou( $t, 'contatos_site', $t['sugestao'] );
		return $foi ? 'aceitar a sugestão chamou o modelo' : null;
	},
);

// ------------------------------------------------------------------ chama

$casos[] = array(
	'grupo'    => 'economia · chama',
	'nome'     => 'resposta vaga, dúvida e pedido de ajuda continuam indo ao modelo',
	'executar' => function () use ( $zerar, $ate, $chamou ) {
		$precisam = array(
			array( 'dominio', 'o que é domínio?' ),
			array( 'contatos_site', 'o de sempre' ),
			array( 'redes_sociais', 'tenho insta da padaria' ),
			array( 'paginas_extras', 'Cardápio' ),
			array( 'paginas_extras', 'me dá uma ideia' ),
			array( 'ramo', 'padaria' ),
			array( 'servicos', 'pães e bolos' ),
		);
		foreach ( $precisam as $par ) {
			$zerar();
			$t = $ate( $par[0] );
			list( $foi ) = $chamou( $t, $par[0], $par[1] );
			if ( ! $foi ) {
				return $par[0] . ': "' . $par[1] . '" não chamou o modelo';
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'economia · chama',
	'nome'     => 'dúvida em campo de formato recebe resposta, e texto inválido que não é dúvida ainda leva o erro',
	'executar' => function () use ( $zerar, $ate, $pedir, $dados ) {
		$zerar();
		$t = $ate( 'dominio' );
		remove_all_filters( 'leticia_pre_gerar' );
		add_filter( 'leticia_pre_gerar', function () {
			return array( 'texto' => wp_json_encode( array( 'tipo' => 'duvida', 'suficiente' => true, 'resposta_duvida' => 'É o endereço do site, tipo suaempresa.com.br.' ) ), 'modelo' => 'teste', 'uso' => array() );
		} );
		$r = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'dominio', 'texto' => 'o que é domínio?' ) ) ) );
		if ( '' !== $r['erro'] || empty( $r['dela']['resposta_duvida'] ) || empty( $r['permanece'] ) ) {
			return 'a dúvida no domínio não foi respondida: ' . wp_json_encode( array( $r['erro'], $r['dela'] ) );
		}

		// O modelo achou que "padaria aurora ponto com?" era resposta: o formato
		// segue reprovado, com o erro de sempre e nada gravado.
		remove_all_filters( 'leticia_pre_gerar' );
		add_filter( 'leticia_pre_gerar', function () {
			return array( 'texto' => wp_json_encode( array( 'tipo' => 'resposta', 'suficiente' => true ) ), 'modelo' => 'teste', 'uso' => array() );
		} );
		$r = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'dominio', 'texto' => 'padaria aurora ponto com?' ) ) ) );
		if ( '' === $r['erro'] || isset( $r['respostas']['dominio'] ) ) {
			return 'um domínio inválido passou por ter ponto de interrogação';
		}

		// Sem modelo, a dúvida volta ao erro de formato, como antes.
		update_option( Leticia_Config::OPCAO, array( 'GEMINI_API_KEY' => '' ) );
		$r = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'dominio', 'texto' => 'o que é domínio?' ) ) ) );
		return '' !== $r['erro'] && ! isset( $r['respostas']['dominio'] ) ? null : 'sem IA, a dúvida não voltou ao erro de formato';
	},
);

$casos[] = array(
	'grupo'    => 'economia · chama',
	'nome'     => 'campo que não comenta só chama com dúvida; negativa nunca chama',
	'executar' => function () {
		$empresa = Leticia_Campos::por_chave( 'empresa' );
		$ok      = array( 'ok' => true );
		if ( 'nao_comenta' !== Leticia_Modelo::motivo_para_poupar( $empresa, 'Padaria Aurora', Leticia_Roteiro::novo( 1 ), $ok ) ) {
			return 'empresa chamou sem dúvida';
		}
		if ( '' !== Leticia_Modelo::motivo_para_poupar( $empresa, 'é o nome fantasia ou o da nota?', Leticia_Roteiro::novo( 1 ), $ok ) ) {
			return 'a dúvida na empresa não chamou';
		}
		$redes = Leticia_Campos::por_chave( 'redes_sociais' );
		return 'negado' === Leticia_Modelo::motivo_para_poupar( $redes, 'não tenho', Leticia_Roteiro::novo( 1 ), array( 'ok' => true, 'negado' => true ) ) ? null : 'a negativa chamou';
	},
);

// ------------------------------------------------------------------ o briefing inteiro

$casos[] = array(
	'grupo'    => 'economia · briefing',
	'nome'     => 'um briefing típico faz duas chamadas, e não seis',
	'executar' => function () use ( $zerar, $pedir, $dados, $tipico ) {
		$zerar();
		$t = $dados( Leticia_Rest::abrir( $pedir( array() ) ) );
		foreach ( $tipico as $c => $v ) {
			$t = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => $c, 'texto' => $v ) ) ) );
		}
		$campos = $GLOBALS['leticia_chamadas_campo'];
		if ( array( 'Ramo de atividade', 'Serviços' ) !== $campos ) {
			return 'chamou em: ' . implode( ', ', $campos );
		}
		return 4 === Leticia_Limites::poupadas_hoje() ? null : 'poupadas contadas: ' . Leticia_Limites::poupadas_hoje();
	},
);

$casos[] = array(
	'grupo'    => 'economia · prompt',
	'nome'     => 'a instrução não volta a inchar',
	'executar' => function () {
		// Antes do corte: 7.154 caracteres na empresa e 10.221 no ramo. O teto
		// fica com folga para editar a base, e apita quando alguém recolocar
		// exemplo repetido.
		$tetos = array( 'empresa' => 5000, 'dominio' => 5600, 'ramo' => 7200 );
		foreach ( $tetos as $chave => $teto ) {
			$tamanho = mb_strlen( Leticia_Prompt::instrucao( Leticia_Campos::por_chave( $chave ) ), 'UTF-8' );
			if ( $tamanho > $teto ) {
				return $chave . ': ' . $tamanho . ' caracteres (teto ' . $teto . ')';
			}
		}
		return null;
	},
);

return $casos;
