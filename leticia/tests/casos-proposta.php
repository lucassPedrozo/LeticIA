<?php
/**
 * O rascunho: ela julga a resposta, conduz quando falta, e escreve.
 *
 * O fluxo protegido é o do pedido da equipe:
 *
 *   "O que a sua empresa faz?"  →  "telhas"
 *   "Me conta mais: telha de acrílico, cerâmica…?"  →  "telhas de acrílico pra área gourmet"
 *   "Escrevi uma sugestão…"  →  usar | ajustar | não usar
 *
 * E o que não pode acontecer: "me dá uma ideia" gravado como resposta, "não
 * sei o que colocar" virando "Sem redes sociais", rascunho com fato inventado
 * chegando à tela, e o que a pessoa respondeu sumindo quando ela aprova o texto.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$zerar = function ( $com_chave = true ) {
	$armazem = new Leticia_Armazem_Json( Leticia_Armazem_Json::MEMORIA );
	$armazem->instalar();
	Leticia_Registro::usar_armazem( $armazem );
	Leticia_Limites::zerar();
	leticia_zerar_acoes();
	leticia_zerar_emails();
	remove_all_filters( 'leticia_pre_gerar' );
	$GLOBALS['leticia_turnos_modelo'] = array();

	update_option(
		Leticia_Config::OPCAO,
		array(
			'GEMINI_API_KEY' => $com_chave ? 'teste' : '',
			'GEMINI_MODEL'   => 'gemini-3.5-flash-lite',
			'ATIVA'          => '1',
			'REMETENTE'      => 'formulario@example.com',
			'DESTINO'        => 'briefing@example.com',
		)
	);
	Leticia_Campos::limpar_cache();
};

/** O "modelo" devolve estes JSONs, um por chamada, e anota o turno que recebeu. */
$modelo_diz = function ( array $respostas ) {
	remove_all_filters( 'leticia_pre_gerar' );
	add_filter(
		'leticia_pre_gerar',
		function ( $nada, $instrucao, $turno ) use ( &$respostas ) {
			$GLOBALS['leticia_turnos_modelo'][] = array( 'instrucao' => $instrucao, 'turno' => $turno );
			$json = count( $respostas ) > 1 ? array_shift( $respostas ) : $respostas[0];
			return array( 'texto' => wp_json_encode( $json ), 'modelo' => 'teste', 'uso' => array() );
		}
	);
};

$json = function ( array $dados ) {
	return array_merge(
		array( 'tipo' => 'resposta', 'suficiente' => true, 'valor_limpo' => null, 'comentario' => null, 'repergunta' => null, 'resposta_duvida' => null, 'proposta' => null ),
		$dados
	);
};

$pedir = function ( array $params = array() ) {
	return new WP_REST_Request( $params, '' );
};

$dados = function ( $r ) {
	return $r instanceof WP_REST_Response ? $r->get_data() : $r;
};

/** Responde sem modelo até o campo pedido. */
$ate = function ( $chave ) use ( $pedir, $dados ) {
	remove_all_filters( 'leticia_pre_gerar' );
	add_filter( 'leticia_pre_gerar', function () {
		return array( 'texto' => wp_json_encode( array( 'tipo' => 'resposta', 'suficiente' => true ) ), 'modelo' => 'teste', 'uso' => array() );
	} );
	$t       = $dados( Leticia_Rest::abrir( $pedir() ) );
	$roteiro = array( 'responsavel' => 'Marina Alves', 'empresa' => 'Aurora Coberturas', 'whatsapp' => '47999998888', 'email' => 'marina@aurora.com.br', 'dominio' => 'auroracoberturas.com.br', 'endereco' => 'não tenho', 'ramo' => 'telhas de acrílico', 'servicos' => 'instalação de telhas' );
	foreach ( $roteiro as $c => $v ) {
		if ( $c === $chave ) {
			break;
		}
		$t = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => $c, 'texto' => $v ) ) ) );
		if ( ! empty( $t['proposta'] ) ) {
			$t = $dados( Leticia_Rest::proposta( $pedir( array( 'token' => $t['token'], 'campo' => $c, 'acao' => 'dispensar' ) ) ) );
		}
	}
	remove_all_filters( 'leticia_pre_gerar' );
	$GLOBALS['leticia_turnos_modelo'] = array();
	return $t;
};

$responder = function ( $token, $campo, $texto ) use ( $pedir, $dados ) {
	return $dados( Leticia_Rest::responder( $pedir( array( 'token' => $token, 'campo' => $campo, 'texto' => $texto ) ) ) );
};

$decidir = function ( $token, $campo, $acao, $texto = '' ) use ( $pedir, $dados ) {
	return $dados( Leticia_Rest::proposta( $pedir( array( 'token' => $token, 'campo' => $campo, 'acao' => $acao, 'texto' => $texto ) ) ) );
};

$rascunho_ramo = 'A Aurora Coberturas instala telhas de acrílico para áreas gourmet e varandas, deixando o espaço claro e protegido da chuva.';

// ---------------------------------------------------------------- pedido de ajuda

$casos[] = array(
	'grupo'    => 'rascunho · ajuda',
	'nome'     => 'pedido de ajuda é reconhecido, e "não sei o que colocar" deixa de ser negativa',
	'executar' => function () {
		foreach ( array( 'não sei o que colocar', 'me dá uma ideia', 'O que eu escrevo aqui?', 'pode escrever pra mim?', 'me ajuda', 'não tenho ideia' ) as $frase ) {
			if ( ! Leticia_Validacao::pede_ajuda( $frase ) ) {
				return 'não reconheceu: ' . $frase;
			}
		}
		foreach ( array( 'telhas de acrílico', 'não tenho', 'não sei', 'sem redes sociais' ) as $frase ) {
			if ( Leticia_Validacao::pede_ajuda( $frase ) ) {
				return 'viu pedido de ajuda em: ' . $frase;
			}
		}
		if ( Leticia_Validacao::e_negativa( 'não sei o que colocar', false ) ) {
			return '"não sei o que colocar" ainda é negativa';
		}
		return Leticia_Validacao::e_negativa( 'não sei' ) ? null : '"não sei" deixou de ser negativa';
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · ajuda',
	'nome'     => '"não sei o que colocar" num obrigatório vai ao modelo, que conduz sem gravar nada',
	'executar' => function () use ( $zerar, $ate, $modelo_diz, $json, $responder ) {
		$zerar();
		$t = $ate( 'ramo' );
		$modelo_diz( array( $json( array( 'tipo' => 'ajuda', 'resposta_duvida' => 'Me conta três coisas: o que vocês vendem, pra quem, e o que têm de diferente.' ) ) ) );

		$r = $responder( $t['token'], 'ramo', 'não sei o que colocar' );
		if ( ! $GLOBALS['leticia_turnos_modelo'] ) {
			return 'o pedido de ajuda não chegou ao modelo';
		}
		if ( empty( $r['permanece'] ) || isset( $r['respostas']['ramo'] ) ) {
			return 'o pedido de ajuda foi gravado como resposta';
		}
		return false !== strpos( $r['dela']['repergunta'] . $r['dela']['resposta_duvida'], 'o que vocês vendem' ) ? null : 'a condução do modelo não chegou à tela';
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · ajuda',
	'nome'     => 'sem IA, o pedido de ajuda ganha a fala escrita e nunca vira resposta',
	'executar' => function () use ( $zerar, $ate, $responder ) {
		$zerar( false );
		$t = $ate( 'ramo' );
		foreach ( array( 'me dá uma ideia do que escrever', 'não sei o que colocar' ) as $frase ) {
			$r = $responder( $t['token'], 'ramo', $frase );
			if ( empty( $r['permanece'] ) || isset( $r['respostas']['ramo'] ) ) {
				return '"' . $frase . '" foi gravado como o ramo';
			}
			if ( '' === (string) $r['dela']['repergunta'] ) {
				return 'ficou sem nada a dizer para "' . $frase . '"';
			}
		}
		// E num opcional com "não tenho", o pedido não vira "Sem redes sociais".
		$t = $ate( 'redes_sociais' );
		$r = $responder( $t['token'], 'redes_sociais', 'não sei o que colocar' );
		return ! isset( $r['respostas']['redes_sociais'] ) ? null : 'o pedido virou "' . $r['respostas']['redes_sociais']['valor'] . '"';
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · ajuda',
	'nome'     => 'com o suficiente, o pedido de ajuda já vem com rascunho, e usar responde o campo',
	'executar' => function () use ( $zerar, $ate, $modelo_diz, $json, $responder, $decidir, $rascunho_ramo ) {
		$zerar();
		$t = $ate( 'ramo' );
		$modelo_diz( array( $json( array( 'tipo' => 'ajuda', 'resposta_duvida' => 'Montei a partir do nome da empresa, confere.', 'proposta' => $rascunho_ramo ) ) ) );

		$r = $responder( $t['token'], 'ramo', 'escreve pra mim, sou péssima nisso' );
		if ( empty( $r['proposta'] ) || 'ajuda' !== $r['proposta']['origem'] ) {
			return 'o rascunho de ajuda não veio';
		}
		if ( isset( $r['respostas']['ramo'] ) ) {
			return 'o campo foi respondido antes da decisão';
		}
		if ( 'Escrevi' === substr( $r['proposta']['intro'], 0, 7 ) || false === strpos( $r['proposta']['intro'], 'Montei' ) ) {
			return 'a frase do modelo não apresentou o rascunho';
		}

		$fim = $decidir( $r['token'], 'ramo', 'usar' );
		if ( ! isset( $fim['respostas']['ramo'] ) || $rascunho_ramo !== $fim['respostas']['ramo']['valor'] ) {
			return 'usar não respondeu o campo com o rascunho';
		}
		if ( $rascunho_ramo !== $fim['respostas']['ramo']['texto_site'] ) {
			return 'o texto aprovado não ficou marcado';
		}
		return 'servicos' === $fim['campo']['chave'] && null === $fim['proposta'] ? null : 'não seguiu para os serviços';
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · ajuda',
	'nome'     => 'a apresentação do rascunho é curta, e a etapa é a do campo, não a 1',
	'executar' => function () use ( $zerar, $ate, $modelo_diz, $json, $responder ) {
		$zerar();
		$t = $ate( 'servicos' );
		$longa = 'Para o seu ramo, eu colocaria a instalação de coberturas de policarbonato para áreas gourmet e varandas, além de acrílico para garagens e passagens cobertas. Dá uma olhada.';
		$modelo_diz( array( $json( array( 'tipo' => 'ajuda', 'resposta_duvida' => $longa, 'proposta' => "Instalação: telhas\nManutenção: limpeza" ) ) ) );

		$r = $responder( $t['token'], 'servicos', 'me ajuda, não sei o que colocar' );
		if ( mb_strlen( $r['proposta']['intro'], 'UTF-8' ) > 110 ) {
			return 'a apresentação ficou longa: ' . $r['proposta']['intro'];
		}
		if ( 2 !== $r['progresso']['secao'] ) {
			return 'o rascunho de serviços mostrou a etapa ' . $r['progresso']['secao'];
		}

		// E a condução sem rascunho, que mantém a pessoa no campo, também.
		$modelo_diz( array( $json( array( 'tipo' => 'ajuda', 'resposta_duvida' => 'O que vocês mais vendem?' ) ) ) );
		$r = $responder( $r['token'], 'servicos', 'me dá uma ideia' );
		return 2 === $r['progresso']['secao'] ? null : 'a condução mostrou a etapa ' . $r['progresso']['secao'];
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · ajuda',
	'nome'     => '"escrever do meu jeito" volta ao campo aberto',
	'executar' => function () use ( $zerar, $ate, $modelo_diz, $json, $responder, $decidir, $rascunho_ramo ) {
		$zerar();
		$t = $ate( 'ramo' );
		$modelo_diz( array( $json( array( 'tipo' => 'ajuda', 'proposta' => $rascunho_ramo ) ) ) );
		$r   = $responder( $t['token'], 'ramo', 'me ajuda a escrever' );
		$fim = $decidir( $r['token'], 'ramo', 'dispensar' );
		if ( empty( $fim['permanece'] ) || 'ramo' !== $fim['campo']['chave'] || isset( $fim['respostas']['ramo'] ) ) {
			return 'não voltou ao campo aberto';
		}
		return null === $fim['proposta'] ? null : 'o rascunho continuou na tela';
	},
);

// ---------------------------------------------------------------- julgar e escrever

$casos[] = array(
	'grupo'    => 'rascunho · resposta',
	'nome'     => '"telhas" ganha repergunta; a segunda vai ao modelo com a primeira e volta com rascunho',
	'executar' => function () use ( $zerar, $ate, $modelo_diz, $json, $responder, $rascunho_ramo ) {
		$zerar();
		$t = $ate( 'ramo' );
		$modelo_diz( array(
			$json( array( 'suficiente' => false, 'repergunta' => 'São telhas de acrílico, cerâmica ou metálicas? Com isso a gente escreve o texto do site junto.' ) ),
			$json( array( 'comentario' => 'Área gourmet coberta é o que mais vende telha de acrílico.', 'proposta' => $rascunho_ramo ) ),
		) );

		$r1 = $responder( $t['token'], 'ramo', 'telhas' );
		if ( empty( $r1['permanece'] ) || false === strpos( $r1['dela']['repergunta'], 'acrílico' ) ) {
			return 'a resposta curta não ganhou repergunta';
		}

		$r2 = $responder( $r1['token'], 'ramo', 'de acrílico, pra área gourmet e varanda' );
		$turno = $GLOBALS['leticia_turnos_modelo'][1]['turno'];
		if ( false === strpos( $turno, 'tentativas anteriores' ) || false === strpos( $turno, 'telhas' ) ) {
			return 'a segunda chamada não levou a primeira tentativa';
		}
		if ( empty( $r2['proposta'] ) || $rascunho_ramo !== $r2['proposta']['texto'] || 'resposta' !== $r2['proposta']['origem'] ) {
			return 'o rascunho não veio';
		}
		if ( ! isset( $r2['respostas']['ramo'] ) || 'de acrílico, pra área gourmet e varanda' !== $r2['respostas']['ramo']['valor'] ) {
			return 'a resposta do cliente não ficou gravada';
		}
		if ( 'Área gourmet coberta é o que mais vende telha de acrílico.' !== $r2['proposta']['intro'] ) {
			return 'o comentário não apresentou o rascunho';
		}
		return 'Um textinho sobre o seu negócio' === $r2['proposta']['titulo'] ? null : 'título: ' . $r2['proposta']['titulo'];
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · resposta',
	'nome'     => 'usar, ajustar e não usar: a resposta do cliente fica, e o texto aprovado vai junto',
	'executar' => function () use ( $zerar, $ate, $modelo_diz, $json, $responder, $decidir, $rascunho_ramo ) {
		$acoes = array(
			'usar'      => $rascunho_ramo,
			'ajustar'   => 'Telhas de acrílico sob medida para varandas.',
			'dispensar' => '',
		);
		foreach ( $acoes as $acao => $esperado ) {
			$zerar();
			$t = $ate( 'ramo' );
			$modelo_diz( array( $json( array( 'proposta' => $rascunho_ramo ) ) ) );
			$r   = $responder( $t['token'], 'ramo', 'telhas de acrílico pra área gourmet' );
			$fim = $decidir( $r['token'], 'ramo', $acao, 'ajustar' === $acao ? $esperado : 'texto que o navegador não devia poder mandar' );

			if ( 'telhas de acrílico pra área gourmet' !== $fim['respostas']['ramo']['valor'] ) {
				return $acao . ': a resposta do cliente mudou';
			}
			if ( $esperado !== $fim['respostas']['ramo']['texto_site'] ) {
				return $acao . ': texto aprovado "' . $fim['respostas']['ramo']['texto_site'] . '"';
			}
			if ( 'servicos' !== $fim['campo']['chave'] || '' === $fim['ponte'] ) {
				return $acao . ': não seguiu com a reação escrita';
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · resposta',
	'nome'     => 'o rascunho esperando decisão sobrevive a recarregar a página',
	'executar' => function () use ( $zerar, $ate, $modelo_diz, $json, $responder, $pedir, $dados, $rascunho_ramo ) {
		$zerar();
		$t = $ate( 'ramo' );
		$modelo_diz( array( $json( array( 'proposta' => $rascunho_ramo ) ) ) );
		$r      = $responder( $t['token'], 'ramo', 'telhas de acrílico pra área gourmet' );
		$depois = $dados( Leticia_Rest::abrir( $pedir( array( 'token' => $r['token'] ) ) ) );
		return ! empty( $depois['proposta'] ) && $rascunho_ramo === $depois['proposta']['texto'] ? null : 'o rascunho sumiu ao recarregar';
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · resposta',
	'nome'     => 'voltar ou responder outro campo deixa o rascunho de lado, e decidir depois não quebra',
	'executar' => function () use ( $zerar, $ate, $modelo_diz, $json, $responder, $decidir, $pedir, $dados, $rascunho_ramo ) {
		$zerar();
		$t = $ate( 'ramo' );
		$modelo_diz( array( $json( array( 'proposta' => $rascunho_ramo ) ), $json( array() ) ) );
		$r = $responder( $t['token'], 'ramo', 'telhas de acrílico pra área gourmet' );

		$voltou = $dados( Leticia_Rest::voltar( $pedir( array( 'token' => $r['token'], 'campo' => 'empresa' ) ) ) );
		if ( null !== $voltou['proposta'] ) {
			return 'voltar para outro campo mostrou o rascunho em vez do campo';
		}
		$seguiu = $responder( $r['token'], 'servicos', 'instalação' );
		if ( null !== $seguiu['proposta'] ) {
			return 'responder outro campo manteve o rascunho';
		}
		$tarde = $decidir( $seguiu['token'], 'ramo', 'usar' );
		if ( is_wp_error( $tarde ) ) {
			return 'decidir um rascunho que já saiu deu erro';
		}
		return '' === $tarde['respostas']['ramo']['texto_site'] ? null : 'gravou um rascunho abandonado';
	},
);

// ---------------------------------------------------------------- sem inventar

$casos[] = array(
	'grupo'    => 'rascunho · trava',
	'nome'     => 'rascunho com preço ou telefone inventado não chega à tela, e a resposta segue',
	'executar' => function () use ( $zerar, $ate, $modelo_diz, $json, $responder ) {
		foreach ( array( 'Telhas a partir de R$ 90 o metro.', 'Ligue (47) 3333-4444 e peça seu orçamento.' ) as $ruim ) {
			$zerar();
			$t = $ate( 'ramo' );
			$modelo_diz( array( $json( array( 'proposta' => $ruim ) ) ) );
			$r = $responder( $t['token'], 'ramo', 'telhas de acrílico pra área gourmet' );
			if ( null !== $r['proposta'] ) {
				return 'passou: ' . $ruim;
			}
			if ( ! isset( $r['respostas']['ramo'] ) || 'servicos' !== $r['campo']['chave'] ) {
				return 'o rascunho barrado travou o campo';
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · trava',
	'nome'     => 'rascunho só em campo de conteúdo, só com resposta suficiente, e sem marcação',
	'executar' => function () use ( $zerar, $ate, $modelo_diz, $json, $responder ) {
		$zerar();
		$t = $ate( 'dominio' );
		$modelo_diz( array( $json( array( 'proposta' => 'Um texto qualquer.' ) ) ) );
		$r = $responder( $t['token'], 'dominio', 'auroracoberturas.com.br' );
		if ( null !== $r['proposta'] ) {
			return 'o domínio ganhou rascunho';
		}

		$zerar();
		$t = $ate( 'servicos' );
		$modelo_diz( array( $json( array( 'proposta' => "- **Instalação**: telhas de acrílico\n* Manutenção: limpeza e troca" ) ) ) );
		$r = $responder( $t['token'], 'servicos', 'instalação e manutenção de telhas' );
		if ( "Instalação: telhas de acrílico\nManutenção: limpeza e troca" !== $r['proposta']['texto'] ) {
			return 'a marcação ficou: ' . wp_json_encode( $r['proposta']['texto'] );
		}

		$modelo_diz( array( $json( array( 'suficiente' => false, 'repergunta' => 'Que tipo de telha?', 'proposta' => 'Texto antecipado.' ) ) ) );
		$saida = Leticia_Modelo::consultar( Leticia_Campos::por_chave( 'ramo' ), 'telhas', array(), array() );
		return null === $saida['proposta'] ? null : 'resposta insuficiente veio com rascunho';
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · prompt',
	'nome'     => 'o prompt dos campos de conteúdo manda escrever sem inventar; o de dado, não',
	'executar' => function () use ( $zerar ) {
		$zerar();
		$ramo = Leticia_Prompt::instrucao( Leticia_Campos::por_chave( 'ramo' ) );
		foreach ( array( 'O RASCUNHO', 'Nunca invente fato', 'tempo de mercado', 'QUANDO A PESSOA PEDE AJUDA', 'QUANDO A PESSOA SE ALONGA', '"telhas", não basta' ) as $trecho ) {
			if ( false === strpos( $ramo, $trecho ) ) {
				return 'o prompt do ramo não tem: ' . $trecho;
			}
		}
		if ( false === strpos( Leticia_Prompt::instrucao( Leticia_Campos::por_chave( 'servicos' ) ), 'Uma linha por serviço' ) ) {
			return 'os serviços não pedem lista';
		}
		$email = Leticia_Prompt::instrucao( Leticia_Campos::por_chave( 'email' ) );
		if ( false !== strpos( $email, 'O RASCUNHO' ) || false === strpos( $email, 'proposta: sempre null' ) ) {
			return 'o e-mail recebeu instrução de rascunho';
		}
		return false !== strpos( $email, 'onde a pessoa encontra a informação' ) ? null : 'campo de dado não diz como ajudar sem chutar';
	},
);

// ---------------------------------------------------------------- entrega

$casos[] = array(
	'grupo'    => 'rascunho · entrega',
	'nome'     => 'o e-mail da equipe traz a resposta e, embaixo, o texto aprovado',
	'executar' => function () use ( $zerar, $rascunho_ramo ) {
		$zerar();
		$estado = Leticia_Roteiro::novo( 1 );
		$estado = Leticia_Roteiro::responder( $estado, 'ramo', 'telhas de acrílico pra área gourmet' )['estado'];
		$estado['respostas']['ramo']['texto_site'] = $rascunho_ramo;

		$texto = Leticia_Email::texto( Leticia_Entrega::corpo_equipe( str_repeat( 'a', 32 ), $estado ) );
		$resp  = strpos( $texto, 'telhas de acrílico pra área gourmet' );
		$site  = strpos( $texto, 'Texto para o site (aprovado pelo cliente)' );
		if ( false === $resp || false === $site || $site < $resp ) {
			return 'o texto aprovado não veio embaixo da resposta';
		}
		return false !== strpos( $texto, 'deixando o espaço claro' ) ? null : 'o texto aprovado sumiu do e-mail';
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · entrega',
	'nome'     => 'resposta escrita pela LetícIA a pedido vem marcada, sem repetir o texto',
	'executar' => function () use ( $zerar ) {
		$zerar();
		$lista  = "Instalação: telhas de acrílico\nManutenção: limpeza";
		$estado = Leticia_Roteiro::novo( 1 );
		$estado = Leticia_Roteiro::responder( $estado, 'servicos', $lista )['estado'];
		$estado['respostas']['servicos']['texto_site'] = $lista;

		$texto = Leticia_Email::texto( Leticia_Entrega::corpo_equipe( str_repeat( 'a', 32 ), $estado ) );
		if ( 1 !== substr_count( $texto, 'Manutenção: limpeza' ) ) {
			return 'a lista saiu repetida ou sumiu';
		}
		if ( false === strpos( $texto, 'escrito pela LetícIA a pedido do cliente' ) ) {
			return 'a equipe não ficou sabendo quem escreveu';
		}
		return false === strpos( Leticia_Email::texto( Leticia_Entrega::corpo_cliente( $estado ) ), 'escrito pela LetícIA' ) ? null : 'a marca interna foi na cópia do cliente';
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · tela',
	'nome'     => 'a tela desenha o rascunho com as três saídas e manda a decisão para /proposta',
	'executar' => function () {
		Leticia_Rest::registrar();
		if ( ! array_key_exists( 'leticia/v1/proposta', leticia_rotas() ) ) {
			return 'a rota /proposta não foi registrada';
		}
		$js = file_get_contents( LETICIA_DIR . 'public/leticia.js' );
		foreach ( array( 'function telaProposta', "'/proposta'", 'Usar este texto', 'Ajustar', 'Escrever do meu jeito', 'Não usar', 'texto_site' ) as $trecho ) {
			if ( false === strpos( $js, $trecho ) ) {
				return 'o JS não tem: ' . $trecho;
			}
		}
		foreach ( array( 'proposta-intro', 'proposta-convite', 'proposta-usada', 'proposta-ajustada', 'proposta-dispensada' ) as $texto ) {
			if ( ! Leticia_Base::variantes( $texto ) ) {
				return 'falta o texto ' . $texto;
			}
		}
		return null;
	},
);

// ------------------------------------------------------ a lista sugerida

/** Até serviços, com um ramo que passa do mínimo e o modelo quieto. */
$ate_servicos = function () use ( $pedir, $dados ) {
	remove_all_filters( 'leticia_pre_gerar' );
	add_filter( 'leticia_pre_gerar', function () {
		return array( 'texto' => wp_json_encode( array( 'tipo' => 'resposta', 'suficiente' => true ) ), 'modelo' => 'teste', 'uso' => array() );
	} );
	$t = $dados( Leticia_Rest::abrir( $pedir() ) );
	foreach ( array( 'responsavel' => 'Marina Alves', 'empresa' => 'Aurora Coberturas', 'whatsapp' => '47999998888', 'email' => 'marina@aurora.com.br', 'dominio' => 'auroracoberturas.com.br', 'endereco' => 'não tenho', 'ramo' => 'telhas de acrílico para área gourmet e varanda' ) as $c => $v ) {
		$t = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => $c, 'texto' => $v ) ) ) );
	}
	remove_all_filters( 'leticia_pre_gerar' );
	$GLOBALS['leticia_turnos_modelo'] = array();
	return $t;
};

$casos[] = array(
	'grupo'    => 'rascunho · lista sugerida',
	'nome'     => 'ao chegar em serviços, ela propõe a lista a partir do ramo — uma vez só',
	'executar' => function () use ( $zerar, $ate_servicos, $modelo_diz, $json, $pedir, $dados ) {
		$zerar();
		$t = $ate_servicos();
		if ( 'servicos' !== $t['campo']['chave'] || 'servicos' !== $t['sugerir'] ) {
			return 'a tela de serviços não pediu a sugestão: ' . wp_json_encode( array( $t['campo']['chave'], $t['sugerir'] ) );
		}

		$lista = "Instalação de telhas de acrílico\nCobertura para área gourmet\nCobertura para varanda";
		$modelo_diz( array( $json( array( 'tipo' => 'ajuda', 'proposta' => $lista ) ) ) );
		$s = $dados( Leticia_Rest::sugerir( $pedir( array( 'token' => $t['token'], 'campo' => 'servicos' ) ) ) );

		$turno = $GLOBALS['leticia_turnos_modelo'][0]['turno'];
		if ( false === strpos( $turno, 'ainda não respondeu' ) || false === strpos( $turno, 'área gourmet' ) ) {
			return 'o pedido ao modelo não levou o ramo: ' . $turno;
		}
		if ( empty( $s['proposta'] ) || 'ajuda' !== $s['proposta']['origem'] || false === strpos( $s['proposta']['texto'], 'Cobertura para varanda' ) ) {
			return 'a lista não virou rascunho de ajuda: ' . wp_json_encode( $s['proposta'] );
		}
		if ( isset( $s['respostas']['servicos'] ) ) {
			return 'a sugestão foi gravada como resposta antes de a pessoa decidir';
		}

		// Dispensou: volta para a pergunta, e a sugestão não volta.
		$d = $dados( Leticia_Rest::proposta( $pedir( array( 'token' => $s['token'], 'campo' => 'servicos', 'acao' => 'dispensar' ) ) ) );
		if ( 'servicos' !== $d['campo']['chave'] || '' !== $d['sugerir'] ) {
			return 'depois de dispensar: ' . wp_json_encode( array( $d['campo']['chave'], $d['sugerir'] ) );
		}
		$de_novo = $dados( Leticia_Rest::sugerir( $pedir( array( 'token' => $d['token'], 'campo' => 'servicos' ) ) ) );
		if ( 1 !== count( $GLOBALS['leticia_turnos_modelo'] ) || ! empty( $de_novo['proposta'] ) ) {
			return 'pediu a sugestão ao modelo de novo';
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · lista sugerida',
	'nome'     => 'usar a lista sugerida grava os serviços sem outra chamada',
	'executar' => function () use ( $zerar, $ate_servicos, $modelo_diz, $json, $pedir, $dados ) {
		$zerar();
		$t = $ate_servicos();
		$modelo_diz( array( $json( array( 'tipo' => 'ajuda', 'proposta' => "Instalação de telhas de acrílico\nCobertura para área gourmet" ) ) ) );
		$s = $dados( Leticia_Rest::sugerir( $pedir( array( 'token' => $t['token'], 'campo' => 'servicos' ) ) ) );
		$u = $dados( Leticia_Rest::proposta( $pedir( array( 'token' => $s['token'], 'campo' => 'servicos', 'acao' => 'usar' ) ) ) );
		if ( 1 !== count( $GLOBALS['leticia_turnos_modelo'] ) ) {
			return 'usar a lista chamou o modelo';
		}
		if ( empty( $u['respostas']['servicos'] ) || false === strpos( $u['respostas']['servicos']['texto_site'] . $u['respostas']['servicos']['valor'], 'Cobertura para área gourmet' ) ) {
			return 'a lista não ficou gravada: ' . wp_json_encode( isset( $u['respostas']['servicos'] ) ? $u['respostas']['servicos'] : null );
		}
		return 'contatos_site' === $u['campo']['chave'] ? null : 'seguiu para ' . $u['campo']['chave'];
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · lista sugerida',
	'nome'     => 'sem modelo, ou se ele não escreve lista, a pergunta fica como estava',
	'executar' => function () use ( $zerar, $ate_servicos, $modelo_diz, $json, $pedir, $dados ) {
		$zerar( false );
		$t = $ate_servicos();
		if ( '' !== $t['sugerir'] ) {
			return 'sem IA, a tela pediu sugestão';
		}

		$zerar();
		$t = $ate_servicos();
		$modelo_diz( array( $json( array( 'tipo' => 'ajuda', 'proposta' => null ) ) ) );
		$s = $dados( Leticia_Rest::sugerir( $pedir( array( 'token' => $t['token'], 'campo' => 'servicos' ) ) ) );
		if ( ! empty( $s['proposta'] ) || 'servicos' !== $s['campo']['chave'] ) {
			return 'sem lista, a tela mudou';
		}

		// Pedir sugestão de um campo que não é o atual não faz nada.
		$zerar();
		$t = $ate_servicos();
		$modelo_diz( array( $json( array( 'tipo' => 'ajuda', 'proposta' => 'x' ) ) ) );
		Leticia_Rest::sugerir( $pedir( array( 'token' => $t['token'], 'campo' => 'contatos_site' ) ) );
		return array() === $GLOBALS['leticia_turnos_modelo'] ? null : 'sugeriu para um campo que não é o da vez';
	},
);

// ------------------------------------------------------ tempo e voz

$casos[] = array(
	'grupo'    => 'tela · tempo e voz',
	'nome'     => 'a tela fala em minutos que faltam, e a apresentação também',
	'executar' => function () use ( $zerar, $pedir, $dados, $ate_servicos ) {
		$zerar( false );
		$t     = $dados( Leticia_Rest::abrir( $pedir() ) );
		$cheio = (int) $t['progresso']['minutos'];
		if ( $cheio < 4 || $cheio > 10 ) {
			return 'o briefing inteiro diz ' . $cheio . ' minutos';
		}
		if ( false !== strpos( $t['apresentacao']['detalhe'], '{' ) || false === strpos( $t['apresentacao']['detalhe'], (string) $cheio ) ) {
			return 'a apresentação não disse o tempo: ' . $t['apresentacao']['detalhe'];
		}
		$meio = $ate_servicos();
		if ( (int) $meio['progresso']['minutos'] >= $cheio ) {
			return 'o tempo não diminuiu: ' . $meio['progresso']['minutos'];
		}
		$tudo = Leticia_Roteiro::novo( 1 );
		foreach ( Leticia_Campos::todos() as $campo ) {
			$tudo['respostas'][ $campo['chave'] ] = array( 'valor' => 'x', 'bruto' => 'x', 'pulado' => false, 'pendente' => false, 'negado' => false, 'link' => '', 'arquivos' => array(), 'texto_site' => '' );
		}
		return 0 === Leticia_Roteiro::minutos_restantes( $tudo ) ? null : 'terminado e ainda faltam minutos';
	},
);

$casos[] = array(
	'grupo'    => 'tela · tempo e voz',
	'nome'     => 'os campos de conteúdo oferecem o microfone com uma frase',
	'executar' => function () use ( $zerar, $ate_servicos ) {
		$zerar();
		$t = $ate_servicos();
		if ( '' === $t['campo']['fala'] || false === strpos( $t['campo']['fala'], 'microfone' ) ) {
			return 'serviços veio sem a frase do microfone';
		}
		$ramo = Leticia_Campos::por_chave( 'ramo' );
		if ( empty( $ramo['fala'] ) ) {
			return 'o ramo ficou sem a frase';
		}
		return empty( Leticia_Campos::por_chave( 'whatsapp' )['fala'] ) ? null : 'campo de dado ganhou frase de conteúdo';
	},
);

return $casos;
