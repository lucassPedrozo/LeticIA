<?php
/**
 * O painel em Configurações → LetícIA.
 *
 * A tela é HTML gerado a partir de dado que veio de cliente — nome de empresa,
 * texto livre, nome de arquivo. O que se protege aqui é isso não virar script
 * no wp-admin de quem monta o site, e a chave da API não voltar para a página.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$zerar = function () {
	$armazem = new Leticia_Armazem_Json( Leticia_Armazem_Json::MEMORIA );
	Leticia_Registro::usar_armazem( $armazem );
	Leticia_Limites::zerar();
	leticia_zerar_emails();
	$GLOBALS['leticia_pode'] = array( 'edit_pages' => true, 'manage_options' => true );
	$_GET = $_POST = $_REQUEST = array();
	delete_transient( Leticia_Admin::AVISO );
	update_option(
		Leticia_Config::OPCAO,
		array(
			'GEMINI_API_KEY' => 'AIzaSegredoQueNaoPodeAparecer',
			'DESTINO'        => 'briefing@example.com',
		)
	);
};

$tela = function ( array $get = array() ) {
	$_GET = $get;
	ob_start();
	try {
		Leticia_Admin::render();
	} catch ( Exception $e ) {
		ob_end_clean();
		throw $e;
	}
	return ob_get_clean();
};

/** Grava um briefing direto no registro, do jeito que as rotas gravariam. */
$briefing = function ( $sessao, array $valores, array $extra = array() ) {
	$estado = Leticia_Roteiro::novo( 1 );
	foreach ( $valores as $chave => $valor ) {
		if ( 'logo_pendente' === $chave ) {
			$estado = Leticia_Roteiro::responder( $estado, 'logo', '', array( 'pendente' => true ) )['estado'];
			continue;
		}
		$estado = Leticia_Roteiro::responder( $estado, $chave, $valor )['estado'];
	}
	if ( ! empty( $extra['enviado'] ) ) {
		$estado = Leticia_Roteiro::marcar_enviado( $estado );
	}
	Leticia_Registro::salvar( $sessao, $estado, array( 'pagina' => 'https://joinvix.com.br/briefing/' ) );
	return $estado;
};

$casos[] = array(
	'grupo'    => 'painel · briefings',
	'nome'     => 'nome de empresa com script sai escapado',
	'executar' => function () use ( $zerar, $tela, $briefing ) {
		$zerar();
		$briefing( str_repeat( 'a', 32 ), array( 'responsavel' => 'Marina', 'empresa' => '<script>alert(1)</script>' ) );
		$html = $tela();
		if ( false !== strpos( $html, '<script>alert(1)</script>' ) ) {
			return 'o script entrou cru na lista';
		}
		$detalhe = $tela( array( 'briefing' => str_repeat( 'a', 32 ) ) );
		return false === strpos( $detalhe, '<script>alert' ) ? null : 'o script entrou cru no detalhe';
	},
);

$casos[] = array(
	'grupo'    => 'painel · briefings',
	'nome'     => 'o briefing parado mostra em que campo parou',
	'executar' => function () use ( $zerar, $tela, $briefing ) {
		$zerar();
		$briefing( str_repeat( 'b', 32 ), array( 'responsavel' => 'Marina', 'empresa' => 'Padaria Aurora', 'whatsapp' => '47999998888' ) );
		$html = $tela();
		if ( false === strpos( $html, 'Parou em E-mail para contato' ) ) {
			return 'a lista não diz onde parou';
		}
		return false !== strpos( $html, 'Onde as pessoas param' ) && false !== strpos( $html, 'E-mail para contato</span>' ) ? null : 'o gráfico de abandono não mostra o campo';
	},
);

$casos[] = array(
	'grupo'    => 'painel · briefings',
	'nome'     => 'quem preencheu tudo e não enviou conta como parado na revisão',
	'executar' => function () use ( $zerar, $tela ) {
		// O abandono mais caro — o briefing inteiro na mão, a um clique — não
		// aparecia no gráfico, porque não há "próximo campo" na revisão.
		$zerar();
		$estado = Leticia_Roteiro::novo( 1 );
		foreach ( Leticia_Campos::todos() as $campo ) {
			$estado = $campo['obrigatorio'] && 'arquivo' !== $campo['tipo']
				? Leticia_Roteiro::responder( $estado, $campo['chave'], 'dominio' === $campo['chave'] ? 'ainda não tenho' : ( 'whatsapp' === $campo['chave'] ? '47999998888' : ( 'imagens_ia' === $campo['chave'] ? 'sim' : 'algo' ) ) )['estado']
				: ( 'logo' === $campo['chave'] ? Leticia_Roteiro::responder( $estado, 'logo', '', array( 'pendente' => true ) )['estado'] : Leticia_Roteiro::pular( $estado, $campo['chave'] )['estado'] );
		}
		Leticia_Registro::salvar( str_repeat( '7', 32 ), $estado );

		$conta = Leticia_Registro::abandono_por_campo();
		if ( array( Leticia_Registro::REVISAO => 1 ) !== $conta ) {
			return 'a contagem foi ' . json_encode( $conta );
		}
		return false !== strpos( $tela(), 'Revisão, antes de enviar' ) ? null : 'o gráfico não mostra a revisão';
	},
);

$casos[] = array(
	'grupo'    => 'painel · briefings',
	'nome'     => 'visita sem resposta nenhuma não aparece na lista',
	'executar' => function () use ( $zerar, $tela ) {
		$zerar();
		Leticia_Registro::salvar( str_repeat( 'c', 32 ), Leticia_Roteiro::novo( 1 ) );
		return false !== strpos( $tela(), 'Nada por aqui.' ) ? null : 'listou uma visita vazia';
	},
);

$casos[] = array(
	'grupo'    => 'painel · entrega',
	'nome'     => 'briefing não entregue aparece no topo, com o botão de reenviar',
	'executar' => function () use ( $zerar, $tela, $briefing ) {
		$zerar();
		$briefing( str_repeat( 'd', 32 ), array( 'responsavel' => 'Marina', 'empresa' => 'Padaria Aurora' ), array( 'enviado' => true ) );
		$html = $tela();
		if ( false === strpos( $html, '1 briefing não chegou na equipe' ) ) {
			return 'a faixa de atenção não avisou';
		}
		if ( false === strpos( $html, 'id="nao-entregues"' ) ) {
			return 'não listou os não entregues';
		}
		return false !== strpos( $html, 'value="leticia_reenviar"' ) && false !== strpos( $html, 'nonce-leticia_reenviar' ) ? null : 'o botão de reenviar não tem ação ou nonce';
	},
);

$casos[] = array(
	'grupo'    => 'painel · entrega',
	'nome'     => 'reenviar pelo painel entrega e marca como entregue',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$sessao = str_repeat( 'e', 32 );
		$briefing( $sessao, array( 'responsavel' => 'Marina', 'empresa' => 'Padaria Aurora' ), array( 'enviado' => true ) );

		$_POST = $_REQUEST = array( 'sessao' => $sessao, '_wpnonce' => 'nonce-leticia_reenviar', 'action' => 'leticia_reenviar' );
		try {
			Leticia_Admin::reenviar();
			return 'não redirecionou';
		} catch ( Leticia_Redirecionou $r ) {
			if ( false === strpos( $r->getMessage(), 'briefing=' . $sessao ) ) {
				return 'voltou para o lugar errado: ' . $r->getMessage();
			}
		}
		if ( ! leticia_emails_enviados() ) {
			return 'nenhum e-mail saiu';
		}
		$linha = Leticia_Registro::briefing( $sessao );
		return 1 === (int) $linha['entregue'] ? null : 'não marcou como entregue';
	},
);

$casos[] = array(
	'grupo'    => 'painel · entrega',
	'nome'     => 'reenviar sem nonce ou sem permissão não faz nada',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$sessao = str_repeat( 'f', 32 );
		$briefing( $sessao, array( 'responsavel' => 'Marina', 'empresa' => 'Padaria Aurora' ), array( 'enviado' => true ) );

		$_POST = $_REQUEST = array( 'sessao' => $sessao, '_wpnonce' => 'forjado' );
		try {
			Leticia_Admin::reenviar();
		} catch ( Exception $e ) {
			// esperado
		}
		if ( leticia_emails_enviados() ) {
			return 'reenviou sem nonce válido';
		}

		$GLOBALS['leticia_pode'] = array();
		$_POST = $_REQUEST = array( 'sessao' => $sessao, '_wpnonce' => 'nonce-leticia_reenviar' );
		try {
			Leticia_Admin::reenviar();
		} catch ( Leticia_Morreu $e ) {
			return leticia_emails_enviados() ? 'reenviou sem permissão' : null;
		}
		return 'quem não pode passou';
	},
);

$casos[] = array(
	'grupo'    => 'painel · detalhe',
	'nome'     => 'o detalhe diz que o arquivo foi no e-mail e traz o link de anexo da logo pendente',
	'executar' => function () use ( $zerar, $tela, $briefing ) {
		$zerar();
		$sessao = str_repeat( '9', 32 );
		$estado = $briefing( $sessao, array( 'responsavel' => 'Marina', 'empresa' => 'Padaria Aurora', 'logo_pendente' => 1 ), array( 'enviado' => true ) );

		$estado = Leticia_Roteiro::responder(
			$estado,
			'materiais',
			'',
			array( 'valor' => 'foto.jpg', 'arquivos' => array( array( 'id' => str_repeat( 'ab', 12 ), 'nome' => 'foto.jpg', 'tamanho' => 2048 ) ) )
		)['estado'];
		Leticia_Registro::salvar( $sessao, $estado, array( 'pagina' => 'https://joinvix.com.br/briefing/' ) );

		$html = $tela( array( 'briefing' => $sessao ) );
		// Este arquivo não existe em disco — é o caso do briefing entregue, cujos
		// arquivos saíram do servidor e foram no e-mail.
		if ( false !== strpos( $html, 'acao=baixar&amp;arquivo=' . str_repeat( 'ab', 12 ) ) ) {
			return 'ofereceu download de arquivo que não está mais no servidor';
		}
		if ( false === strpos( $html, 'foto.jpg <span class="description">2 KB · foi no e-mail' ) ) {
			return 'não disse que o arquivo foi no e-mail';
		}
		if ( false === strpos( $html, Leticia_Anexo::PARAMETRO . '=' ) ) {
			return 'a logo pendente não mostra o link de anexo';
		}
		return false !== strpos( $html, 'Ficou arquivo para depois' ) ? null : 'não destacou a pendência';
	},
);

$casos[] = array(
	'grupo'    => 'painel · detalhe',
	'nome'     => 'a conversa mostra dúvida e trava',
	'executar' => function () use ( $zerar, $tela, $briefing ) {
		$zerar();
		$sessao = str_repeat( '8', 32 );
		$briefing( $sessao, array( 'responsavel' => 'Marina' ) );
		Leticia_Registro::turno( $sessao, array( 'campo' => 'dominio', 'texto' => 'o que é domínio?', 'tipo' => 'duvida' ) );
		Leticia_Registro::turno( $sessao, array( 'campo' => 'ramo', 'texto' => 'tela', 'tipo' => 'resposta', 'bloqueio' => 'preco' ) );

		$html = $tela( array( 'briefing' => $sessao ) );
		if ( false === strpos( $html, 'o que é domínio?' ) || false === strpos( $html, '>dúvida<' ) ) {
			return 'a dúvida não apareceu';
		}
		return false !== strpos( $html, 'trava: preco' ) ? null : 'o bloqueio da trava não apareceu';
	},
);

$casos[] = array(
	'grupo'    => 'painel · configuração',
	'nome'     => 'a chave salva nunca volta para a página',
	'executar' => function () use ( $zerar, $tela ) {
		$zerar();
		$html = $tela( array( 'aba' => 'config' ) );
		if ( false === strpos( $html, 'name="leticia_config[DESTINO]"' ) ) {
			return 'a aba de configuração não abriu';
		}
		return false === strpos( $html, 'AIzaSegredoQueNaoPodeAparecer' ) ? null : 'a chave da API foi impressa no HTML';
	},
);

$casos[] = array(
	'grupo'    => 'painel · configuração',
	'nome'     => 'quem só vê briefings não abre a configuração',
	'executar' => function () use ( $zerar, $tela ) {
		$zerar();
		$GLOBALS['leticia_pode'] = array( 'edit_pages' => true );
		$html = $tela( array( 'aba' => 'config' ) );
		if ( false !== strpos( $html, 'leticia_config[' ) ) {
			return 'mostrou o formulário de configuração';
		}
		return false === strpos( $html, '>Configuração</a>' ) ? null : 'mostrou a aba de configuração';
	},
);

$casos[] = array(
	'grupo'    => 'painel · configuração',
	'nome'     => 'o teto diário diz quantos briefings cabem por dia',
	'executar' => function () use ( $zerar, $tela ) {
		$zerar();
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'TETO_DIARIO' => '160' ) ) );
		$cabem = (int) floor( 160 / Leticia_Admin::CHAMADAS_POR_BRIEFING );
		if ( false === strpos( $tela( array( 'aba' => 'config' ) ), '<strong>' . $cabem . ' briefings por dia</strong>' ) ) {
			return 'a conta de briefings por dia não apareceu';
		}
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'TETO_DIARIO' => '0' ) ) );
		return false !== strpos( $tela( array( 'aba' => 'config' ) ), '<strong>Sem teto.</strong>' ) ? null : 'sem teto, a tela não disse';
	},
);

$casos[] = array(
	'grupo'    => 'painel · configuração',
	'nome'     => 'o aceite em branco volta a ser o do formulário',
	'executar' => function () {
		$salvo = Leticia_Config::sanitizar( array( 'CONSENTIMENTO' => '' ) );
		if ( '' !== $salvo['CONSENTIMENTO'] ) {
			return 'guardou o vazio como texto';
		}
		$igual = Leticia_Config::sanitizar( array( 'CONSENTIMENTO' => Leticia_Base::texto( 'consentimento' ) ) );
		return '' === $igual['CONSENTIMENTO'] ? null : 'copiar o texto da base congelou uma cópia dele';
	},
);

$casos[] = array(
	'grupo'    => 'painel · configuração',
	'nome'     => 'o aviso de IA: some sem modelo, troca o nome e aceita texto próprio',
	'executar' => function () {
		$antes = get_option( Leticia_Config::OPCAO );

		update_option( Leticia_Config::OPCAO, array_merge( (array) $antes, array( 'GEMINI_API_KEY' => '' ) ) );
		if ( '' !== Leticia_Config::aviso_ia() ) {
			update_option( Leticia_Config::OPCAO, $antes );
			return 'sem chave, o aviso ainda diz que a conversa passa por IA';
		}

		$base = array_merge( (array) $antes, array( 'GEMINI_API_KEY' => str_repeat( 'x', 39 ), 'ATIVA' => '1', 'NOME' => 'Ana', 'AVISO_IA' => '' ) );
		update_option( Leticia_Config::OPCAO, $base );
		$padrao = Leticia_Config::aviso_ia();
		if ( $padrao !== Leticia_Base::texto( 'aviso-ia' ) || false === strpos( $padrao, 'inteligência artificial' ) ) {
			update_option( Leticia_Config::OPCAO, $antes );
			return 'o aviso padrão saiu: ' . $padrao;
		}

		update_option( Leticia_Config::OPCAO, array_merge( $base, array( 'AVISO_IA' => 'A {assistente} é uma IA.' ) ) );
		if ( 'A Ana é uma IA.' !== Leticia_Config::aviso_ia() ) {
			update_option( Leticia_Config::OPCAO, $antes );
			return '{assistente} não virou o nome: ' . Leticia_Config::aviso_ia();
		}

		update_option( Leticia_Config::OPCAO, array_merge( $base, array( 'AVISO_IA' => 'Texto da equipe.' ) ) );
		$proprio = Leticia_Config::aviso_ia();

		$igual = Leticia_Config::sanitizar( array( 'AVISO_IA' => Leticia_Base::texto( 'aviso-ia' ) ) );
		update_option( Leticia_Config::OPCAO, $antes );
		if ( 'Texto da equipe.' !== $proprio ) {
			return 'o texto do painel não valeu: ' . $proprio;
		}
		return '' === $igual['AVISO_IA'] ? null : 'copiar o aviso da base congelou uma cópia dele';
	},
);

$casos[] = array(
	'grupo'    => 'painel · configuração',
	'nome'     => 'a pasta definida no wp-config vale sobre o padrão',
	'executar' => function () {
		// A constante não pode ser desfeita, então o caso confere o caminho
		// sem ela e a leitura da situação, que é o que o painel mostra.
		$situacao = Leticia_Admin::situacao_pasta();
		if ( $situacao['caminho'] !== Leticia_Arquivos::pasta_base() ) {
			return 'o painel mostra uma pasta diferente da usada';
		}
		return defined( Leticia_Config::CONSTANTE_PASTA ) || '' === Leticia_Config::pasta_definida() ? null : 'inventou uma pasta definida';
	},
);

// ------------------------------------------------------ campos configuráveis

$com_campos = function ( array $campos ) {
	update_option( Leticia_Config::OPCAO, array_merge( (array) get_option( Leticia_Config::OPCAO, array() ), array( 'CAMPOS' => $campos ) ) );
	Leticia_Campos::limpar_cache();
};

$sem_campos = function () use ( $com_campos ) {
	$com_campos( array() );
};

$casos[] = array(
	'grupo'    => 'painel · campos',
	'nome'     => 'o painel muda quem é obrigatório e qual arquivo pode ficar para depois',
	'executar' => function () use ( $zerar, $com_campos, $sem_campos ) {
		$zerar();
		$com_campos(
			array(
				'logo'   => array( 'obrigatorio' => '0', 'depois' => '0' ),
				'textos' => array( 'obrigatorio' => '0', 'depois' => '1' ),
				'email'  => array( 'obrigatorio' => '1' ),
			)
		);
		$logo   = Leticia_Campos::por_chave( 'logo' );
		$textos = Leticia_Campos::por_chave( 'textos' );
		$email  = Leticia_Campos::por_chave( 'email' );
		$sem_campos();

		if ( $logo['obrigatorio'] || ! empty( $logo['pode_ficar_pendente'] ) ) {
			return 'a logo continuou obrigatória ou com link';
		}
		if ( empty( $textos['pode_ficar_pendente'] ) ) {
			return 'os textos não ganharam o "mandar depois"';
		}
		return $email['obrigatorio'] ? null : 'o e-mail não virou obrigatório';
	},
);

$casos[] = array(
	'grupo'    => 'painel · campos',
	'nome'     => 'textos para depois: o briefing envia, e o link sai para os textos',
	'executar' => function () use ( $zerar, $com_campos, $sem_campos ) {
		$zerar();
		$com_campos(
			array(
				'logo'   => array( 'obrigatorio' => '0', 'depois' => '0' ),
				'textos' => array( 'obrigatorio' => '0', 'depois' => '1' ),
			)
		);

		$estado = Leticia_Roteiro::novo( 1 );
		foreach ( array( 'responsavel' => 'Marina Alves', 'empresa' => 'Padaria Aurora', 'whatsapp' => '47999998888', 'dominio' => 'padariaaurora.com.br', 'ramo' => 'padaria', 'servicos' => 'pães', 'contatos_site' => 'WhatsApp', 'imagens_ia' => 'sim' ) as $c => $v ) {
			$estado = Leticia_Roteiro::responder( $estado, $c, $v )['estado'];
		}
		foreach ( array( 'email', 'endereco', 'redes_sociais', 'paginas_extras', 'logo', 'materiais' ) as $c ) {
			$estado = Leticia_Roteiro::pular( $estado, $c )['estado'];
		}
		$r      = Leticia_Roteiro::responder( $estado, 'textos', '', array( 'pendente' => true ) );
		$estado = $r['estado'];

		$pode   = Leticia_Roteiro::pode_enviar( $estado );
		$pend   = Leticia_Roteiro::pendencias( $estado );
		$link   = Leticia_Entrega::link_anexo( str_repeat( '3', 32 ), $estado, 'https://joinvix.com.br/briefing/' );
		$textos = Leticia_Entrega::pendencias_texto( $estado, true );
		$sem_campos();

		if ( ! $pode ) {
			return 'não deixou enviar sem logo, com a logo opcional';
		}
		if ( array( 'textos' ) !== $pend ) {
			return 'as pendências foram ' . implode( ',', $pend );
		}
		if ( '' === $link ) {
			return 'não gerou link para os textos';
		}
		return 'Textos do site: você manda depois pelo link.' === $textos['textos'] ? null : 'a pendência saiu como: ' . $textos['textos'];
	},
);

$casos[] = array(
	'grupo'    => 'painel · campos',
	'nome'     => 'sem "mandar depois", o campo não aceita ficar pendente',
	'executar' => function () use ( $zerar, $com_campos, $sem_campos ) {
		$zerar();
		$com_campos( array( 'logo' => array( 'obrigatorio' => '1', 'depois' => '0' ) ) );
		$r = Leticia_Roteiro::responder( Leticia_Roteiro::novo( 1 ), 'logo', '', array( 'pendente' => true ) );
		$sem_campos();
		return empty( $r['estado']['respostas']['logo']['pendente'] ) ? null : 'aceitou pendência num campo sem link';
	},
);

$casos[] = array(
	'grupo'    => 'painel · campos',
	'nome'     => 'salvar a tabela: caixa desmarcada vira "não", e sem a tabela nada muda',
	'executar' => function () use ( $zerar, $sem_campos ) {
		$zerar();
		$salvo = Leticia_Config::sanitizar(
			array(
				'CAMPOS_ENVIADO' => '1',
				'CAMPOS'         => array( 'textos' => array( 'depois' => '1' ), 'responsavel' => array( 'obrigatorio' => '1' ) ),
			)
		);
		if ( '0' !== $salvo['CAMPOS']['logo']['obrigatorio'] || '0' !== $salvo['CAMPOS']['logo']['depois'] ) {
			return 'a logo desmarcada não virou "não"';
		}
		if ( '1' !== $salvo['CAMPOS']['textos']['depois'] || isset( $salvo['CAMPOS']['responsavel']['depois'] ) ) {
			return 'marcou errado: ' . json_encode( $salvo['CAMPOS'] );
		}
		update_option( Leticia_Config::OPCAO, $salvo );
		$outro = Leticia_Config::sanitizar( array( 'CONSENTIMENTO' => '' ) );
		$sem_campos();
		return $outro['CAMPOS'] === $salvo['CAMPOS'] ? null : 'salvar outra coisa apagou a tabela de campos';
	},
);

$casos[] = array(
	'grupo'    => 'painel · campos',
	'nome'     => 'a aba de configuração mostra a tabela, e avisa arquivo obrigatório sem link',
	'executar' => function () use ( $zerar, $tela, $com_campos, $sem_campos ) {
		$zerar();
		$com_campos( array( 'logo' => array( 'obrigatorio' => '1', 'depois' => '0' ) ) );
		$html = $tela( array( 'aba' => 'config' ) );
		$sem_campos();
		if ( false === strpos( $html, 'name="leticia_config[CAMPOS][textos][depois]"' ) ) {
			return 'a tabela de campos não apareceu';
		}
		if ( false !== strpos( $html, 'name="leticia_config[CAMPOS][empresa][depois]"' ) ) {
			return 'ofereceu "mandar depois" em campo que não é arquivo';
		}
		return false !== strpos( $html, 'sem o arquivo, não envia' ) ? null : 'não avisou do arquivo obrigatório sem link';
	},
);

$casos[] = array(
	'grupo'    => 'painel · campos',
	'nome'     => 'o assunto da continuação é configurável',
	'executar' => function () use ( $zerar ) {
		$zerar();
		$estado = Leticia_Roteiro::responder( Leticia_Roteiro::novo( 1 ), 'dominio', 'padariaaurora.com.br' )['estado'];
		if ( '[CONTINUAÇÃO] - padariaaurora.com.br' !== Leticia_Entrega::assunto_continuacao( $estado ) ) {
			return 'padrão: ' . Leticia_Entrega::assunto_continuacao( $estado );
		}
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'ASSUNTO_CONTINUACAO' => '[SITE EXPRESS] Chegou mais - [DOMINIO]' ) ) );
		return '[SITE EXPRESS] Chegou mais - padariaaurora.com.br' === Leticia_Entrega::assunto_continuacao( $estado ) ? null : 'não usou o configurado';
	},
);

return $casos;
