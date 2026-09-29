<?php
/**
 * O contrato dos campos.
 *
 * As chaves são contrato com três coisas ao mesmo tempo: o navegador, a tabela
 * do registro e o e-mail que vai para a equipe. Renomear uma chave quebra as
 * três e invalida os rascunhos abertos — então a lista está escrita aqui, à
 * mão, e mexer nela tem que ser uma decisão, não um efeito colateral.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$casos[] = array(
	'grupo'    => 'campos · contrato',
	'nome'     => 'as chaves e os tipos são os combinados',
	'executar' => function () {
		$esperado = array(
			'responsavel'    => 'texto',
			'empresa'        => 'texto',
			'whatsapp'       => 'telefone',
			'email'          => 'email',
			'dominio'        => 'dominio',
			'ramo'           => 'texto',
			'servicos'       => 'texto',
			'endereco'       => 'texto',
			'contatos_site'  => 'texto',
			'redes_sociais'  => 'texto',
			'paginas_extras' => 'texto',
			'imagens_ia'     => 'escolha',
			'logo'           => 'arquivo',
			'textos'         => 'arquivo',
			'materiais'      => 'arquivo',
		);

		$achado = array();
		foreach ( Leticia_Campos::todos() as $campo ) {
			$achado[ $campo['chave'] ] = $campo['tipo'];
		}

		if ( $achado !== $esperado ) {
			return 'mudou: ' . wp_json_encode( array_diff_assoc( $achado, $esperado ) );
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'campos · contrato',
	'nome'     => 'os obrigatórios são os nove do formulário',
	'executar' => function () {
		$esperado = array( 'responsavel', 'empresa', 'whatsapp', 'dominio', 'ramo', 'servicos', 'contatos_site', 'imagens_ia', 'logo' );
		$achado   = Leticia_Campos::obrigatorios();
		sort( $esperado );
		sort( $achado );
		return $achado === $esperado ? null : 'obrigatórios: ' . implode( ', ', $achado );
	},
);

$casos[] = array(
	'grupo'    => 'campos · contrato',
	'nome'     => 'só seis campos gastam chamada de modelo por padrão',
	'executar' => function () {
		// Campo que comenta chama o modelo mesmo quando a resposta não parece
		// pergunta. Cada um a mais é uma chamada a mais por briefing — e com
		// teto de 200 por dia, são briefings a menos.
		$esperado = array( 'dominio', 'ramo', 'servicos', 'contatos_site', 'redes_sociais', 'paginas_extras' );
		$achado   = array();
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( ! empty( $campo['comenta'] ) ) {
				$achado[] = $campo['chave'];
			}
		}
		return $achado === $esperado ? null : 'comentam: ' . implode( ', ', $achado );
	},
);

$casos[] = array(
	'grupo'    => 'campos · contrato',
	'nome'     => 'imagens_ia é botão, não digitação',
	'executar' => function () {
		$campo = Leticia_Campos::por_chave( 'imagens_ia' );
		if ( 'escolha' !== $campo['tipo'] ) {
			return 'virou ' . $campo['tipo'];
		}
		$valores = wp_list_pluck( $campo['opcoes'], 'valor' );
		return array( 'sim', 'nao' ) === $valores ? null : 'opções: ' . implode( ', ', $valores );
	},
);

$casos[] = array(
	'grupo'    => 'campos · contrato',
	'nome'     => 'cada campo de arquivo diz o que aceita',
	'executar' => function () {
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( 'arquivo' !== $campo['tipo'] ) {
				continue;
			}
			if ( empty( $campo['aceita'] ) || empty( $campo['aceita_texto'] ) ) {
				return $campo['chave'] . ' não diz o que aceita';
			}
			foreach ( $campo['aceita'] as $ext ) {
				if ( ! preg_match( '/^[a-z0-9]{2,5}$/', $ext ) ) {
					return $campo['chave'] . ' tem extensão estranha: ' . $ext;
				}
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'campos · perguntas',
	'nome'     => 'a variante é sorteada, mas presa à semente',
	'executar' => function () {
		// Presa porque a pessoa pode voltar ao campo: ver a pergunta trocar de
		// texto ao voltar faria parecer que ela mudou de campo.
		$valores = array( 'nome' => 'Marina', 'empresa' => 'Padaria Aurora' );
		$a = Leticia_Campos::pergunta( 'ramo', 4, $valores );
		$b = Leticia_Campos::pergunta( 'ramo', 4, $valores );
		if ( $a !== $b ) {
			return 'a mesma semente deu perguntas diferentes';
		}
		$campo = Leticia_Campos::por_chave( 'ramo' );
		$vistas = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$vistas[ Leticia_Campos::pergunta( 'ramo', $i, $valores ) ] = true;
		}
		return count( $vistas ) === count( $campo['perguntas'] ) ? null : 'sementes diferentes não cobrem as variantes';
	},
);

$casos[] = array(
	'grupo'    => 'campos · perguntas',
	'nome'     => 'nenhuma pergunta usa negrito, lista ou emoji',
	'executar' => function () {
		// Isto aqui é um balão de conversa, não um documento. Marcação sai como
		// lixo na tela, e emoji em excesso é o que faz parecer robô animado.
		foreach ( Leticia_Campos::todos() as $campo ) {
			foreach ( $campo['perguntas'] as $pergunta ) {
				if ( preg_match( '/\*\*|^[-*]\s|^\d+\./u', $pergunta ) ) {
					return $campo['chave'] . ': "' . $pergunta . '"';
				}
				if ( preg_match( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $pergunta ) ) {
					return $campo['chave'] . ' tem emoji na pergunta';
				}
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'campos · perguntas',
	'nome'     => 'pergunta de campo opcional avisa que dá pra pular',
	'executar' => function () {
		// Sem esse aviso a pessoa digita "não sei" — e gasta uma chamada de API
		// para dizer nada.
		$sem_saida = array();
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( $campo['obrigatorio'] ) {
				continue;
			}
			// Variante por variante: a pessoa vê uma só, e é nela que a saída
			// precisa estar. Somar as três esconderia justamente a que falta.
			foreach ( $campo['perguntas'] as $pergunta ) {
				$plano = Leticia_Validacao::simplificar( $pergunta );
				if ( ! preg_match( '/\b(pula|pular|opcional|nao)\b/u', $plano ) ) {
					$sem_saida[] = $campo['chave'] . ': "' . $pergunta . '"';
				}
			}
		}
		return $sem_saida ? 'sem saída explícita: ' . implode( ', ', $sem_saida ) : null;
	},
);

return $casos;
