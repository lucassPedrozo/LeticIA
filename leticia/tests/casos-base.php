<?php
/**
 * A paridade entre a estrutura e as palavras.
 *
 * `Leticia_Campos` diz quais campos existem; `conhecimento/campos.md` diz o que
 * a LetícIA fala em cada um. São dois arquivos de propósito — mudar a pergunta
 * tem que ser editar texto, não PHP —, e é justamente por serem dois que eles
 * podem sair de sincronia. Estes casos existem para isso doer na hora, e não
 * na frente de um cliente.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$casos[] = array(
	'grupo'    => 'base · leitura',
	'nome'     => 'o arquivo existe e é interpretável',
	'executar' => function () {
		if ( ! Leticia_Base::existe() ) {
			return 'não achei ' . Leticia_Base::caminho();
		}
		$dados = Leticia_Base::carregar();
		if ( is_wp_error( $dados ) ) {
			return $dados->get_error_message();
		}
		return ! empty( $dados['campos'] ) ? null : 'não saiu campo nenhum da leitura';
	},
);

$casos[] = array(
	'grupo'    => 'base · paridade',
	'nome'     => 'todo campo tem bloco, com as cinco rubricas preenchidas',
	'executar' => function () {
		$problemas = array();

		foreach ( Leticia_Campos::chaves() as $chave ) {
			$bloco = Leticia_Base::bloco( $chave );
			if ( ! $bloco ) {
				$problemas[] = $chave . ' não tem bloco';
				continue;
			}
			if ( empty( $bloco['pergunta'] ) ) {
				$problemas[] = $chave . ' sem pergunta';
			}
			foreach ( array( 'porque', 'serve', 'borda', 'guiar' ) as $rubrica ) {
				if ( empty( $bloco[ $rubrica ] ) ) {
					$problemas[] = $chave . ' sem "' . $rubrica . '"';
				}
			}
		}

		return $problemas ? implode( '; ', $problemas ) : null;
	},
);

$casos[] = array(
	'grupo'    => 'base · paridade',
	'nome'     => 'não há bloco órfão na base',
	'executar' => function () {
		$dados = Leticia_Base::carregar();
		if ( is_wp_error( $dados ) ) {
			return $dados->get_error_message();
		}
		$orfaos = array_diff( array_keys( $dados['campos'] ), Leticia_Campos::chaves() );
		return $orfaos ? 'bloco sem campo: ' . implode( ', ', $orfaos ) : null;
	},
);

$casos[] = array(
	'grupo'    => 'base · paridade',
	'nome'     => 'cada campo tem pelo menos duas variantes de pergunta',
	'executar' => function () {
		$magros = array();
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( count( $campo['perguntas'] ) < 2 ) {
				$magros[] = $campo['chave'];
			}
		}
		// Variar o fraseado é o que mais disfarça que do outro lado não tem
		// gente. Uma variante só faz a LetícIA soar igual em todo briefing.
		return $magros ? 'só uma variante em: ' . implode( ', ', $magros ) : null;
	},
);

$casos[] = array(
	'grupo'    => 'base · paridade',
	'nome'     => 'os textos soltos que o roteiro usa existem',
	'executar' => function () {
		$faltam = array();
		foreach ( array( 'abertura-secao-2', 'abertura-secao-3', 'consentimento', 'aviso-ia', 'sem-imagens-de-banco' ) as $chave ) {
			if ( '' === Leticia_Base::texto( $chave ) ) {
				$faltam[] = $chave;
			}
		}
		return $faltam ? 'faltando: ' . implode( ', ', $faltam ) : null;
	},
);

$casos[] = array(
	'grupo'    => 'base · paridade',
	'nome'     => 'o consentimento é o texto aprovado, palavra por palavra',
	'executar' => function () {
		// O cliente assina isto. Se o texto mudar sem que a equipe tenha
		// decidido mudar, é defeito — não é melhoria de redação.
		$oficial = 'Concordo em enviar estas respostas e arquivos para a equipe da JoinVix criar o meu site, e autorizo que entrem em contato comigo. Sei que o que respondi, por escrito ou por áudio, passou por uma inteligência artificial (o Gemini, do Google).';
		$achado  = Leticia_Base::texto( 'consentimento' );
		return $achado === $oficial ? null : 'o texto do aceite mudou: "' . $achado . '"';
	},
);

$casos[] = array(
	'grupo'    => 'base · leitura',
	'nome'     => 'a prosa do cabeçalho não vira campo',
	'executar' => function () {
		$texto = "# título\n\n## como usar este arquivo\n\nprosa qualquer.\n\n"
			. "## campo: ramo\n\n### Pergunta\n\n- E aí?\n\n### Por que perguntamos\n\nporque sim.\n";
		$dados = Leticia_Base::interpretar( $texto );

		if ( array( 'ramo' ) !== array_keys( $dados['campos'] ) ) {
			return 'saiu ' . implode( ', ', array_keys( $dados['campos'] ) );
		}
		if ( array( 'E aí?' ) !== $dados['campos']['ramo']['pergunta'] ) {
			return 'a pergunta não foi lida';
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'base · leitura',
	'nome'     => 'a base sumindo não derruba o briefing',
	'executar' => function () {
		// Sem a base, a LetícIA perde as palavras boas — não a capacidade de
		// perguntar, validar e enviar. Nada aqui pode virar erro fatal.
		add_filter(
			'leticia_caminho_base',
			function () {
				return LETICIA_DIR . 'conhecimento/nao-existe.md';
			}
		);
		Leticia_Base::limpar_cache();
		Leticia_Campos::limpar_cache();

		$erro   = null;
		$campos = Leticia_Campos::todos();

		if ( 15 !== count( $campos ) ) {
			$erro = 'perdeu campos';
		} elseif ( empty( $campos[0]['perguntas'][0] ) ) {
			$erro = 'ficou sem pergunta nenhuma';
		} elseif ( ! is_wp_error( Leticia_Base::carregar() ) ) {
			$erro = 'disse que leu uma base que não existe';
		}

		remove_all_filters( 'leticia_caminho_base' );
		Leticia_Base::limpar_cache();
		Leticia_Campos::limpar_cache();

		return $erro;
	},
);

return $casos;
