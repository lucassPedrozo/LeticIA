<?php
/**
 * Responder por voz.
 *
 * Sem áudio de verdade e sem rede: o filtro `leticia_pre_ouvir` faz o papel do
 * Gemini, e o "áudio" é um cabeçalho de WebM seguido de enchimento — o que a
 * rota confere são os bytes do começo e o tamanho, não o som.
 *
 * O que está sendo protegido é a promessa da tela: a voz nunca grava nada
 * sozinha, o microfone só aparece quando há quem ouça, e quando alguma coisa
 * dá errado a pessoa volta a escrever em vez de ficar presa.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$zerar = function ( $com_chave = true ) {
	$armazem = new Leticia_Armazem_Json( Leticia_Armazem_Json::MEMORIA );
	$armazem->instalar();
	Leticia_Registro::usar_armazem( $armazem );
	Leticia_Limites::zerar();
	leticia_zerar_acoes();
	remove_all_filters( 'leticia_pre_ouvir' );
	remove_all_filters( 'leticia_pre_gerar' );

	update_option(
		Leticia_Config::OPCAO,
		array(
			// Com chave, para a voz existir; nenhuma chamada sai, porque o
			// filtro responde antes da rede.
			'GEMINI_API_KEY' => $com_chave ? 'teste' : '',
			'GEMINI_MODEL'   => 'gemini-3.5-flash-lite',
			'ATIVA'          => '1',
		)
	);
	Leticia_Campos::limpar_cache();
};

/** Bytes que parecem uma gravação do Chrome. */
$webm = function ( $bytes = 4000 ) {
	return "\x1A\x45\xDF\xA3" . str_repeat( "\x00", $bytes - 4 );
};

/** O "Gemini" devolve este JSON, e anota com o que foi chamado. */
$ouvir_com = function ( array $json ) {
	$GLOBALS['leticia_voz_chamadas'] = array();
	remove_all_filters( 'leticia_pre_ouvir' );
	add_filter(
		'leticia_pre_ouvir',
		function ( $nada, $instrucao, $turno, $mime ) use ( $json ) {
			$GLOBALS['leticia_voz_chamadas'][] = array( 'instrucao' => $instrucao, 'turno' => $turno, 'mime' => $mime );
			return array( 'texto' => wp_json_encode( $json ), 'modelo' => 'teste', 'uso' => array( 'entrada' => 0, 'saida' => 0 ) );
		}
	);
};

$ouvido = function ( $texto, $baixa = false, $motivo = 'nenhum' ) {
	return array(
		'houve_fala'         => true,
		'transcricao'        => $texto,
		'texto_interpretado' => $texto,
		'confianca_baixa'    => $baixa,
		'motivo'             => $motivo,
	);
};

$pedir = function ( array $params = array(), $corpo = '' ) {
	return new WP_REST_Request( $params, $corpo );
};

$dados = function ( $r ) {
	return $r instanceof WP_REST_Response ? $r->get_data() : $r;
};

/** Abre um briefing e responde até o campo pedido. */
$ate = function ( $chave ) use ( $pedir, $dados ) {
	$t       = $dados( Leticia_Rest::abrir( $pedir() ) );
	$roteiro = array( 'responsavel' => 'Marina Alves', 'empresa' => 'Padaria Aurora', 'whatsapp' => '47999998888', 'email' => 'marina@aurora.com.br', 'dominio' => 'padariaaurora.com.br', 'endereco' => 'Rua das Flores, 10', 'ramo' => 'padaria artesanal', 'servicos' => 'pães e bolos', 'contatos_site' => 'WhatsApp', 'redes_sociais' => '@aurora', 'paginas_extras' => 'não' );
	foreach ( $roteiro as $c => $v ) {
		if ( $c === $chave ) {
			break;
		}
		$t = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => $c, 'texto' => $v ) ) ) );
	}
	return $t;
};

$status = function ( $erro ) {
	$d = $erro->get_error_data();
	return is_array( $d ) && isset( $d['status'] ) ? (int) $d['status'] : 0;
};

// ------------------------------------------------------------------ formato

$casos[] = array(
	'grupo'    => 'voz · formato',
	'nome'     => 'a rota /voz está registrada',
	'executar' => function () {
		Leticia_Rest::registrar();
		return array_key_exists( 'leticia/v1/voz', leticia_rotas() ) ? null : 'a rota não foi registrada';
	},
);

$casos[] = array(
	'grupo'    => 'voz · formato',
	'nome'     => 'o formato sai dos bytes, com o nome que o Gemini aceita',
	'executar' => function () {
		$esperados = array(
			'audio/webm' => "\x1A\x45\xDF\xA3" . str_repeat( "\x00", 20 ),
			'audio/ogg'  => 'OggS' . str_repeat( "\x00", 20 ),
			// O audio/mp4 do Safari é audio/m4a para a API.
			'audio/m4a'  => "\x00\x00\x00\x1Cftypiso5" . str_repeat( "\x00", 20 ),
			'audio/wav'  => 'RIFF' . "\x24\x08\x00\x00" . 'WAVEfmt ' . str_repeat( "\x00", 8 ),
			'audio/mp3'  => 'ID3' . str_repeat( "\x00", 20 ),
		);
		foreach ( $esperados as $mime => $bytes ) {
			$lido = Leticia_Voz::formato( $bytes );
			if ( $mime !== $lido ) {
				return $mime . ' foi lido como ' . var_export( $lido, true );
			}
		}
		return null === Leticia_Voz::formato( '<?php echo "oi"; ?>' . str_repeat( ' ', 20 ) ) ? null : 'aceitou um arquivo que não é áudio';
	},
);

$casos[] = array(
	'grupo'    => 'voz · formato',
	'nome'     => 'o pedido leva o áudio em inline_data, com schema e temperatura baixa',
	'executar' => function () {
		$corpo = Leticia_Gemini::corpo_voz( 'instrução', 'turno', 'abc', 'audio/webm' );
		$parte = $corpo['contents'][0]['parts'][1];
		if ( ! isset( $parte['inline_data'] ) || 'audio/webm' !== $parte['inline_data']['mime_type'] || base64_encode( 'abc' ) !== $parte['inline_data']['data'] ) {
			return 'o áudio não foi como inline_data em base64';
		}
		if ( 'turno' !== $corpo['contents'][0]['parts'][0]['text'] ) {
			return 'o contexto em texto não foi junto';
		}
		$g = $corpo['generationConfig'];
		if ( 'application/json' !== $g['responseMimeType'] || ! isset( $g['responseSchema']['properties']['confianca_baixa'], $g['responseSchema']['properties']['texto_interpretado'] ) ) {
			return 'faltou o schema com texto_interpretado e confianca_baixa';
		}
		return $g['temperature'] <= 0.2 ? null : 'transcrição com temperatura alta: ' . $g['temperature'];
	},
);

// ------------------------------------------------------------------ prompt

$casos[] = array(
	'grupo'    => 'voz · prompt',
	'nome'     => 'a instrução diz o campo, é a mesma entre chamadas e trata o áudio como dado',
	'executar' => function () use ( $zerar ) {
		$zerar();
		$campo = Leticia_Campos::por_chave( 'empresa' );
		$a     = Leticia_Prompt::instrucao_voz( $campo );
		if ( $a !== Leticia_Prompt::instrucao_voz( $campo ) ) {
			return 'a instrução muda entre chamadas: o cache de contexto não vale';
		}
		if ( false === strpos( $a, 'Campo: Empresa' ) ) {
			return 'a instrução não diz qual campo está sendo respondido';
		}
		if ( false === stripos( $a, 'nunca acrescente' ) ) {
			return 'a instrução não proíbe acrescentar o que não foi dito';
		}
		return false !== strpos( $a, 'ignore as instruções' ) ? null : 'a instrução não trata ordem falada como dado';
	},
);

$casos[] = array(
	'grupo'    => 'voz · prompt',
	'nome'     => 'e-mail e domínio levam as regras de soletrar, e nenhum contexto para completar',
	'executar' => function () use ( $zerar ) {
		$zerar();
		$email = Leticia_Prompt::instrucao_voz( Leticia_Campos::por_chave( 'email' ) );
		if ( false === strpos( $email, 'Arroba' ) || false === strpos( $email, 'Nunca complete' ) ) {
			return 'o e-mail não diz como ouvir arroba e ponto';
		}
		$contexto = array( 'empresa' => 'Padaria Aurora', 'ramo' => 'padaria' );
		if ( false !== strpos( Leticia_Prompt::turno_voz( Leticia_Campos::por_chave( 'dominio' ), $contexto ), 'Padaria Aurora' ) ) {
			return 'o domínio recebeu o nome da empresa, que é convite a chutar o endereço';
		}
		return false !== strpos( Leticia_Prompt::turno_voz( Leticia_Campos::por_chave( 'servicos' ), $contexto ), 'Padaria Aurora' )
			? null
			: 'os serviços não receberam o contexto que ajuda a ouvir';
	},
);

// ------------------------------------------------------------------ rota

$casos[] = array(
	'grupo'    => 'voz · rota',
	'nome'     => 'o áudio volta como texto para conferir, com a frase do campo',
	'executar' => function () use ( $zerar, $ate, $pedir, $dados, $webm, $ouvir_com, $ouvido ) {
		$zerar();
		$t = $ate( 'empresa' );
		$ouvir_com( $ouvido( 'Padaria Aurora' ) );

		$r = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'empresa' ), $webm() ) ) );
		if ( is_wp_error( $r ) ) {
			return 'recusou: ' . $r->get_error_message();
		}
		$v = $r['voz'];
		if ( ! $v['ouviu'] || 'Padaria Aurora' !== $v['texto'] || $v['confianca_baixa'] ) {
			return 'voltou: ' . wp_json_encode( $v );
		}
		if ( 'Entendi que a empresa se chama' !== $v['confirmacao'] ) {
			return 'a confirmação saiu: ' . $v['confirmacao'];
		}
		if ( empty( $r['token'] ) ) {
			return 'não devolveu o token';
		}
		return 'audio/webm' === $GLOBALS['leticia_voz_chamadas'][0]['mime'] ? null : 'mandou outro formato ao modelo';
	},
);

$casos[] = array(
	'grupo'    => 'voz · rota',
	'nome'     => 'ouvir não grava nada: o campo continua aberto até a pessoa confirmar',
	'executar' => function () use ( $zerar, $ate, $pedir, $dados, $webm, $ouvir_com, $ouvido ) {
		$zerar();
		$t = $ate( 'empresa' );
		$ouvir_com( $ouvido( 'Padaria Aurora' ) );
		Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'empresa' ), $webm() ) );

		$depois = $dados( Leticia_Rest::abrir( $pedir( array( 'token' => $t['token'] ) ) ) );
		if ( 'empresa' !== $depois['campo']['chave'] || isset( $depois['respostas']['empresa'] ) ) {
			return 'a voz gravou a resposta sem confirmação';
		}

		// Confirmado, o texto segue pelo caminho de quem digitou.
		$fim = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'empresa', 'texto' => 'Padaria Aurora' ) ) ) );
		return isset( $fim['respostas']['empresa'] ) && 'Padaria Aurora' === $fim['respostas']['empresa']['valor'] ? null : 'a confirmação não gravou';
	},
);

$casos[] = array(
	'grupo'    => 'voz · rota',
	'nome'     => 'confiança baixa volta marcada, com o motivo dito para leigo',
	'executar' => function () use ( $zerar, $ate, $pedir, $dados, $webm, $ouvir_com, $ouvido ) {
		$zerar();
		$t = $ate( 'ramo' );
		$ouvir_com( $ouvido( 'Padaria artesanal', true, 'ruido' ) );

		$v = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'ramo' ), $webm() ) ) )['voz'];
		if ( ! $v['confianca_baixa'] || 'ruido' !== $v['motivo'] ) {
			return 'a dúvida do modelo se perdeu';
		}
		return false !== strpos( $v['aviso'], 'barulho' ) ? null : 'o aviso saiu: ' . $v['aviso'];
	},
);

$casos[] = array(
	'grupo'    => 'voz · rota',
	'nome'     => 'e-mail sem formato vira dúvida mesmo com o modelo dizendo que tem certeza',
	'executar' => function () use ( $zerar, $ate, $pedir, $dados, $webm, $ouvir_com, $ouvido ) {
		$zerar();
		$t = $ate( 'email' );
		$ouvir_com( $ouvido( 'marina aurora.com.br' ) );

		$v = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'email' ), $webm() ) ) )['voz'];
		if ( ! $v['confianca_baixa'] || 'soletrar' !== $v['motivo'] ) {
			return 'um e-mail sem arroba voltou como certo';
		}
		return false !== strpos( $v['aviso'], 'letra por letra' ) ? null : 'o aviso saiu: ' . $v['aviso'];
	},
);

$casos[] = array(
	'grupo'    => 'voz · rota',
	'nome'     => '"não tenho" falado num opcional não vira dúvida',
	'executar' => function () use ( $zerar, $ate, $pedir, $dados, $webm, $ouvir_com, $ouvido ) {
		$zerar();
		$t = $ate( 'endereco' );
		$ouvir_com( $ouvido( 'Não tenho' ) );
		$v = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'endereco' ), $webm() ) ) )['voz'];
		return ! $v['confianca_baixa'] && 'Não tenho' === $v['texto'] ? null : 'voltou: ' . wp_json_encode( $v );
	},
);

$casos[] = array(
	'grupo'    => 'voz · rota',
	'nome'     => 'e-mail certo ainda pede para conferir letra por letra; texto livre não',
	'executar' => function () use ( $zerar, $ate, $pedir, $dados, $webm, $ouvir_com, $ouvido ) {
		$zerar();
		$t = $ate( 'email' );
		$ouvir_com( $ouvido( 'marina.alvis@example.com' ) );
		$v = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'email' ), $webm() ) ) )['voz'];
		if ( $v['confianca_baixa'] || false === strpos( $v['nota'], 'letra por letra' ) ) {
			return 'o e-mail com certeza não pediu conferência: ' . wp_json_encode( $v );
		}
		$t = $ate( 'ramo' );
		$ouvir_com( $ouvido( 'Padaria artesanal' ) );
		$v = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'ramo' ), $webm() ) ) )['voz'];
		return '' === $v['nota'] ? null : 'texto livre ganhou nota de soletrar';
	},
);

$casos[] = array(
	'grupo'    => 'voz · rota',
	'nome'     => 'silêncio volta sem texto e com o convite a gravar de novo',
	'executar' => function () use ( $zerar, $ate, $pedir, $dados, $webm, $ouvir_com ) {
		$zerar();
		$t = $ate( 'responsavel' );
		$ouvir_com( array( 'houve_fala' => false, 'transcricao' => null, 'texto_interpretado' => null, 'confianca_baixa' => true, 'motivo' => 'ruido' ) );
		$v = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'responsavel' ), $webm() ) ) )['voz'];
		if ( $v['ouviu'] || '' !== $v['texto'] ) {
			return 'silêncio virou texto';
		}
		return '' !== $v['aviso'] ? null : 'não disse nada para a pessoa';
	},
);

$casos[] = array(
	'grupo'    => 'voz · rota',
	'nome'     => 'o texto do modelo sai sem marcação e cabe na caixa',
	'executar' => function () use ( $zerar, $ate, $pedir, $dados, $webm, $ouvir_com, $ouvido ) {
		$zerar();
		$t = $ate( 'servicos' );
		$ouvir_com( $ouvido( "<b>Pães</b>   e bolos\n\n\n<script>x</script>" . str_repeat( 'a', 2000 ) ) );
		$v = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'servicos' ), $webm() ) ) )['voz'];
		if ( false !== strpos( $v['texto'], '<' ) ) {
			return 'marcação passou: ' . substr( $v['texto'], 0, 60 );
		}
		if ( 0 !== strpos( $v['texto'], "Pães e bolos\n" ) ) {
			return 'espaço e linha em branco não foram arrumados: ' . substr( $v['texto'], 0, 30 );
		}
		return mb_strlen( $v['texto'], 'UTF-8' ) <= 1200 ? null : 'passou do teto da caixa de texto';
	},
);

$casos[] = array(
	'grupo'    => 'voz · falhas',
	'nome'     => 'resposta quebrada do modelo vira convite a escrever, e fica registrada',
	'executar' => function () use ( $zerar, $ate, $pedir, $dados, $webm, $status ) {
		$zerar();
		$t = $ate( 'ramo' );
		add_filter( 'leticia_pre_ouvir', function () {
			return array( 'texto' => 'desculpe, não consigo', 'modelo' => 'teste', 'uso' => array() );
		} );
		$r = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'ramo' ), $webm() ) ) );
		if ( ! is_wp_error( $r ) || 'voz_falhou' !== $r->get_error_code() || 502 !== $status( $r ) ) {
			return 'não recusou do jeito combinado';
		}
		if ( false === strpos( $r->get_error_message(), 'escrever' ) ) {
			return 'a mensagem não oferece escrever';
		}
		return leticia_acoes_disparadas( 'leticia_voz_falhou' ) ? null : 'a falha não foi registrada';
	},
);

$casos[] = array(
	'grupo'    => 'voz · falhas',
	'nome'     => 'sem chave, a voz não existe: nem microfone na tela, nem chamada na rota',
	'executar' => function () use ( $zerar, $ate, $pedir, $dados, $webm, $ouvir_com, $ouvido, $status ) {
		$zerar( false );
		$t = $ate( 'empresa' );
		if ( 0 !== $t['voz'] ) {
			return 'a tela ofereceu microfone sem modelo para ouvir';
		}
		$ouvir_com( $ouvido( 'Padaria Aurora' ) );
		$r = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'empresa' ), $webm() ) ) );
		if ( ! is_wp_error( $r ) || 'voz_indisponivel' !== $r->get_error_code() || 503 !== $status( $r ) ) {
			return 'a rota não recusou';
		}
		return ! $GLOBALS['leticia_voz_chamadas'] ? null : 'chamou o modelo mesmo desligada';
	},
);

$casos[] = array(
	'grupo'    => 'voz · falhas',
	'nome'     => 'desligada no painel, o microfone some mesmo com a conversa ligada',
	'executar' => function () use ( $zerar, $ate ) {
		$zerar();
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'VOZ' => '0' ) ) );
		$t = $ate( 'empresa' );
		return 0 === $t['voz'] && Leticia_Config::pode_comentar() ? null : 'a voz desligada continuou na tela';
	},
);

$casos[] = array(
	'grupo'    => 'voz · falhas',
	'nome'     => 'campo de botão ou de arquivo não aceita voz',
	'executar' => function () use ( $zerar, $pedir, $dados, $webm ) {
		$zerar();
		$t = $dados( Leticia_Rest::abrir( $pedir() ) );
		foreach ( array( 'imagens_ia', 'logo', 'nao_existe' ) as $chave ) {
			$r = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => $chave ), $webm() ) ) );
			if ( ! is_wp_error( $r ) || 'campo_invalido' !== $r->get_error_code() ) {
				return $chave . ' aceitou voz';
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'voz · falhas',
	'nome'     => 'vazio, formato estranho e áudio longo demais são recusados antes do modelo',
	'executar' => function () use ( $zerar, $ate, $pedir, $dados, $webm, $ouvir_com, $ouvido, $status ) {
		$zerar();
		$t = $ate( 'empresa' );
		$ouvir_com( $ouvido( 'x' ) );
		$testes = array(
			'audio_vazio'   => array( '', 400 ),
			'formato_audio' => array( str_repeat( 'x', 4000 ), 415 ),
			'audio_grande'  => array( $webm( Leticia_Voz::TETO_OPUS + 10 ), 413 ),
		);
		foreach ( $testes as $codigo => $caso ) {
			$r = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'empresa' ), $caso[0] ) ) );
			if ( ! is_wp_error( $r ) || $codigo !== $r->get_error_code() || $caso[1] !== $status( $r ) ) {
				return 'esperava ' . $codigo . ', veio ' . ( is_wp_error( $r ) ? $r->get_error_code() : 'sucesso' );
			}
		}
		return ! $GLOBALS['leticia_voz_chamadas'] ? null : 'gastou chamada com áudio recusado';
	},
);

$casos[] = array(
	'grupo'    => 'voz · falhas',
	'nome'     => 'briefing já enviado não aceita gravação',
	'executar' => function () use ( $zerar, $pedir, $dados, $webm ) {
		$zerar();
		$t      = $dados( Leticia_Rest::abrir( $pedir() ) );
		$sessao = Leticia_Rascunho::conferir( $t['token'] );
		Leticia_Registro::salvar( $sessao, Leticia_Roteiro::marcar_enviado( Leticia_Roteiro::novo( 1 ) ) );
		$r = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'empresa' ), $webm() ) ) );
		return is_wp_error( $r ) && 'ja_enviado' === $r->get_error_code() ? null : 'aceitou voz num briefing enviado';
	},
);

// ------------------------------------------------------------------ cota

$casos[] = array(
	'grupo'    => 'voz · cota',
	'nome'     => 'cada gravação conta no dia, e a sessão tem teto próprio de gravações',
	'executar' => function () use ( $zerar, $ate, $pedir, $dados, $webm, $ouvir_com, $ouvido, $status ) {
		$zerar();
		$t = $ate( 'empresa' );
		$ouvir_com( $ouvido( 'Padaria Aurora' ) );
		$antes = Leticia_Limites::usadas_hoje();

		for ( $i = 0; $i < Leticia_Limites::POR_JANELA_VOZ; $i++ ) {
			$r = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'empresa' ), $webm() ) ) );
			if ( is_wp_error( $r ) ) {
				return 'recusou cedo, na gravação ' . ( $i + 1 ) . ': ' . $r->get_error_code();
			}
		}
		if ( Leticia_Limites::usadas_hoje() - $antes !== Leticia_Limites::POR_JANELA_VOZ ) {
			return 'as gravações não contaram no dia';
		}

		$r = $dados( Leticia_Rest::ouvir( $pedir( array( 'token' => $t['token'], 'campo' => 'empresa' ), $webm() ) ) );
		if ( ! is_wp_error( $r ) || 'voz_ritmo' !== $r->get_error_code() || 429 !== $status( $r ) ) {
			return 'passou do teto de gravações da sessão';
		}

		// E o teto da voz é só da voz: responder digitando continua.
		$depois = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'empresa', 'texto' => 'Padaria Aurora' ) ) ) );
		return ! is_wp_error( $depois ) && isset( $depois['respostas']['empresa'] ) ? null : 'o teto da voz travou a resposta digitada';
	},
);

$casos[] = array(
	'grupo'    => 'voz · cota',
	'nome'     => 'o painel guarda os segundos entre 10 e 120',
	'executar' => function () use ( $zerar ) {
		$zerar();
		foreach ( array( '5' => '10', '45' => '45', '600' => '120' ) as $entra => $sai ) {
			$salvo = Leticia_Config::sanitizar( array( 'VOZ' => '1', 'VOZ_SEGUNDOS' => $entra ) );
			if ( $sai !== $salvo['VOZ_SEGUNDOS'] ) {
				return $entra . ' virou ' . $salvo['VOZ_SEGUNDOS'];
			}
		}
		$salvo = Leticia_Config::sanitizar( array() );
		return '0' === $salvo['VOZ'] ? null : 'caixa desmarcada não desligou a voz';
	},
);

// ------------------------------------------------------------------ tela

$casos[] = array(
	'grupo'    => 'voz · tela',
	'nome'     => 'a tela diz os segundos, e só campo de digitar leva microfone',
	'executar' => function () use ( $zerar, $ate ) {
		$zerar();
		$t = $ate( 'empresa' );
		if ( 60 !== $t['voz'] ) {
			return 'a tela não trouxe os segundos: ' . var_export( $t['voz'], true );
		}
		if ( true !== $t['campo']['voz'] ) {
			return 'o campo de texto veio sem voz';
		}
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( in_array( $campo['tipo'], array( 'escolha', 'arquivo' ), true ) && Leticia_Voz::aceita_campo( $campo ) ) {
				return $campo['chave'] . ' aceita voz';
			}
			if ( Leticia_Voz::aceita_campo( $campo ) && empty( $campo['ouvi'] ) ) {
				return $campo['chave'] . ' não tem a frase de confirmação';
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'voz · tela',
	'nome'     => 'o navegador sem gravação cai no campo de texto, e o microfone sempre fecha',
	'executar' => function () {
		$js = file_get_contents( LETICIA_DIR . 'public/leticia.js' );
		$exigidos = array(
			'window.MediaRecorder'   => 'não confere se o navegador grava',
			'NotAllowedError'        => 'não trata a permissão negada',
			'Voz.bloqueada = true'   => 'não para de oferecer o microfone que não funciona',
			'trilha.stop()'          => 'não fecha o microfone depois de gravar',
			'Está certo?'            => 'não pede confirmação',
			'Gravar de novo'         => 'não oferece regravar',
			'Corrigir escrevendo'    => 'não oferece corrigir escrevendo',
		);
		foreach ( $exigidos as $trecho => $falha ) {
			if ( false === strpos( $js, $trecho ) ) {
				return $falha;
			}
		}
		return false === strpos( $js, 'generativelanguage' ) && false === stripos( $js, 'api_key' ) ? null : 'o navegador fala com o Gemini direto';
	},
);

return $casos;
