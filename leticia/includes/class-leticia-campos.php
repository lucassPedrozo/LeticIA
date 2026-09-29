<?php
/**
 * Os 15 campos do Site Express — a estrutura.
 *
 * Esta é a fonte única da ordem, da obrigatoriedade e do formato de cada
 * campo. As palavras ficam em `conhecimento/campos.md`; aqui está só o que o
 * roteiro precisa para decidir.
 *
 * Nada disto é negociado com o modelo. O modelo classifica e comenta; quem diz
 * qual campo existe, em que ordem e quando o briefing acabou é este arquivo
 * mais o Leticia_Roteiro.
 *
 * ┌ CONTRATO ─────────────────────────────────────────────────────────────┐
 * │ As chaves abaixo são o contrato com o navegador, com a tabela do       │
 * │ registro e com o e-mail que vai para a equipe. Mudar uma chave é       │
 * │ mudar as três coisas ao mesmo tempo — e invalidar os rascunhos que     │
 * │ estiverem abertos. Acrescentar campo no fim é barato; renomear não.    │
 * └───────────────────────────────────────────────────────────────────────┘
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Campos {

	/** Quantos campos cada seção tem. O envio conta como um passo a mais. */
	const SECOES = array(
		1 => 'Seus dados',
		2 => 'Seu negócio',
		3 => 'Arquivos',
	);

	/** @var array|null memo por requisição */
	private static $memo = null;

	/**
	 * A definição crua, sem as palavras.
	 *
	 * tipo:        texto | telefone | email | dominio | escolha | arquivo
	 * obrigatorio: se falta, o briefing não fecha (com duas exceções tratadas
	 *              no roteiro: logo pendente e domínio pendente)
	 * comenta:     se vale a pena gastar uma chamada de modelo mesmo quando a
	 *              resposta não parece pergunta
	 * dica:        o texto de exemplo dentro do campo. Curto — cabe numa linha
	 *              de celular. A explicação mora no detalhe da pergunta, em
	 *              campos.md; repetida aqui, quebrava e saía cortada na pílula.
	 * ouvi:        como a confirmação da resposta falada começa — "Entendi que o
 *              seu WhatsApp é" — antes do texto que ela ouviu.
 * propoe:      nos campos de conteúdo, o rascunho que ela escreve a partir da
 *              resposta — 'texto' (um parágrafo) ou 'lista' (um item por
 *              linha) — e o título que a tela mostra em cima dele. A pessoa
 *              usa, ajusta ou deixa de lado; o que ela respondeu fica gravado
 *              de qualquer jeito.
 * poupa:       nos campos que comentam, a resposta que dispensa o modelo — a
 *              reação escrita basta. 'valido' (passou na validação de
 *              formato), 'contato' (traz número, e-mail ou canal), 'perfil'
 *              (traz @ ou endereço de perfil), 'lista' (dois itens ou mais).
 *              Dúvida e pedido de ajuda vão ao modelo sempre.
 * supor:       se a LetícIA pode propor hipóteses sobre o que a pessoa quis
	 *              dizer e recomendar conteúdo. Falso nos campos de dado puro —
	 *              telefone, e-mail e domínio —, onde supor é inventar. Padrão
	 *              verdadeiro: conduzir é o que este produto faz.
	 */
	private static function cru() {
		return array(

			// ---------------------------------------------------- seção 1
			array(
				'chave'       => 'responsavel',
				'secao'       => 1,
				'tipo'        => 'texto',
				'obrigatorio' => true,
				'comenta'     => false,
				'rotulo'      => 'Responsável',
				'ouvi'        => 'Entendi que o seu nome é',
				'dica'        => 'Ex.: Marina Alves',
				// "Eu, lucas" é gravado como "Lucas".
				'formato'     => 'nome',
			),
			array(
				'chave'       => 'empresa',
				'secao'       => 1,
				'tipo'        => 'texto',
				'obrigatorio' => true,
				'comenta'     => false,
				'rotulo'      => 'Empresa',
				'ouvi'        => 'Entendi que a empresa se chama',
				'dica'        => 'Nome da empresa',
			),
			array(
				'chave'       => 'whatsapp',
				'secao'       => 1,
				'tipo'        => 'telefone',
				'obrigatorio' => true,
				'comenta'     => false,
				'rotulo'      => 'WhatsApp do responsável',
				'ouvi'        => 'Entendi que o seu WhatsApp é',
				'dica'        => '(47) 99999-9999',
				// Campo de dado: a LetícIA explica, mas não sugere número.
				'supor'       => false,
			),
			array(
				'chave'       => 'email',
				'secao'       => 1,
				'tipo'        => 'email',
				'obrigatorio' => false,
				'comenta'     => false,
				'rotulo'      => 'E-mail para contato',
				'ouvi'        => 'Entendi que o seu e-mail é',
				'dica'        => 'voce@empresa.com.br',
				'supor'       => false,
				// "Não tenho" é resposta: fica sem e-mail, sem erro de formato.
				'nega'        => '',
			),
			array(
				'chave'       => 'dominio',
				// Domínio que passou no formato — ou "ainda não tenho", que tem
				// reação escrita própria — não ganha nada com o modelo.
				'poupa'       => 'valido',
				'secao'       => 1,
				'tipo'        => 'dominio',
				'obrigatorio' => true,
				'comenta'     => true,
				'rotulo'      => 'Domínio do site',
				'ouvi'        => 'Entendi que o domínio é',
				'dica'        => 'suaempresa.com.br',
				// O campo técnico do briefing. Ela explica o que é domínio e
				// oferece o "ainda não tenho" — nunca chuta um endereço nem diz
				// se está disponível. Quem verifica isso é a equipe.
				'supor'       => false,
			),

			// ---------------------------------------------------- seção 2
			array(
				'chave'       => 'ramo',
				'secao'       => 2,
				'tipo'        => 'texto',
				'obrigatorio' => true,
				'comenta'     => true,
				'rotulo'      => 'Ramo de atividade',
				'propoe'      => array( 'tipo' => 'texto', 'titulo' => 'Um textinho sobre o seu negócio' ),
				// O texto do site sai daqui: "bolo" ou "mapa" não dão nem o
				// título da página. Abaixo de três palavras que digam alguma
				// coisa, ela pede mais — até três vezes, e depois aceita.
				'minimo_palavras' => 3,
				'reperguntas'     => 3,
				// Leigo conta melhor do que escreve: aqui o microfone é oferecido
				// com uma frase, não só com o ícone.
				'fala'            => 'Se preferir, toque no microfone e conte como contaria para um cliente.',
				'segundos'        => 60,
				'ouvi'        => 'Entendi assim o que vocês fazem:',
				'dica'        => 'Conte do seu jeito',
			),
			array(
				'chave'       => 'servicos',
				'secao'       => 2,
				'tipo'        => 'texto',
				'obrigatorio' => true,
				'comenta'     => true,
				'rotulo'      => 'Serviços',
				'propoe'      => array( 'tipo' => 'lista', 'titulo' => 'Seus serviços, organizados' ),
				// Ao chegar aqui, ela já propõe uma lista a partir do ramo — é
				// mais fácil corrigir uma lista pronta do que escrever do zero.
				'sugere_lista' => true,
				'fala'         => 'Se preferir, toque no microfone e fale os serviços, um depois do outro.',
				'segundos'     => 45,
				'ouvi'        => 'Anotei estes serviços:',
				'dica'        => 'Um serviço por linha',
			),
			array(
				'chave'       => 'endereco',
				'secao'       => 2,
				'tipo'        => 'texto',
				'obrigatorio' => false,
				'comenta'     => false,
				// Mora na etapa do negócio, logo antes dos contatos do site: é
				// informação do site (mapa, rodapé), e é dela que a sugestão de
				// contatos tira o endereço.
				'rotulo'      => 'Endereço da empresa',
				'ouvi'        => 'Entendi que o endereço é',
				'dica'        => 'Rua, número, cidade',
				// O que vai para o e-mail quando a pessoa diz que não tem.
				'nega'        => 'Sem endereço físico',
			),
			array(
				'chave'       => 'contatos_site',
				'segundos'    => 25,
				'poupa'       => 'contato',
				'secao'       => 2,
				'tipo'        => 'texto',
				'obrigatorio' => true,
				'comenta'     => true,
				'rotulo'      => 'Contatos exibidos no site',
				'ouvi'        => 'No site, vão aparecer:',
				'dica'        => 'WhatsApp, e-mail, horário',
				// A resposta já vem sugerida a partir do que a pessoa digitou
				// em whatsapp e email. Pedir de novo o que ela acabou de
				// escrever é o que faz um formulário parecer burro.
				'sugere_de'   => array( 'whatsapp', 'email', 'endereco' ),
			),
			array(
				'chave'       => 'redes_sociais',
				'poupa'       => 'perfil',
				'secao'       => 2,
				'tipo'        => 'texto',
				'obrigatorio' => false,
				'comenta'     => true,
				'rotulo'      => 'Redes sociais',
				// Pergunta junto com as páginas extras, numa tela só: as duas são
				// opcionais, e uma de cada vez eram dois "pular" seguidos.
				'junto'       => 'paginas_extras',
				'rotulo_curto' => 'Redes sociais',
				'ouvi'        => 'Anotei estas redes:',
				'dica'        => '@suaempresa',
				'nega'        => 'Sem redes sociais',
			),
			array(
				'chave'       => 'paginas_extras',
				// Uma página só ("Cardápio") ainda vai: é onde ela recomenda
				// outra a partir do ramo. Uma lista, a pessoa já pensou.
				'poupa'       => 'lista',
				'secao'       => 2,
				'tipo'        => 'texto',
				'obrigatorio' => false,
				'comenta'     => true,
				'rotulo'      => 'Páginas além do menu padrão',
				'rotulo_curto' => 'Páginas a mais',
				'ouvi'        => 'Anotei estas páginas:',
				'dica'        => 'Ex.: Cardápio',
				'nega'        => 'Só as páginas padrão (Home, Sobre, Serviços e Contato)',
				// Comenta porque recomendar página é recomendar a partir do
				// ramo — cardápio para restaurante, portfólio para obra —, e
				// isso só o modelo lendo o contexto consegue fazer.
			),
			array(
				'chave'       => 'imagens_ia',
				'secao'       => 2,
				'tipo'        => 'escolha',
				'obrigatorio' => true,
				'comenta'     => false,
				'rotulo'      => 'Imagens de banco ou IA',
				'opcoes'      => array(
					array( 'valor' => 'sim', 'texto' => 'Sim, podem usar' ),
					array( 'valor' => 'nao', 'texto' => 'Não, só as minhas' ),
				),
			),

			// ---------------------------------------------------- seção 3
			array(
				'chave'       => 'logo',
				'secao'       => 3,
				'tipo'        => 'arquivo',
				'obrigatorio' => true,
				'comenta'     => false,
				'rotulo'      => 'Logomarca',
				// Como ela chama o arquivo numa frase: "só falta mandar a logomarca".
				'chamado'     => 'a logomarca',
				'multiplo'    => false,
				'aceita'      => array( 'png', 'jpg', 'jpeg', 'svg', 'pdf', 'ai', 'eps', 'zip' ),
				'aceita_texto' => 'PNG, JPG, SVG, PDF, AI, EPS ou ZIP',
				// O único upload obrigatório — e nem ele trava o envio.
				'pode_ficar_pendente' => true,
				// Muita gente não tem o arquivo, mas tem a logo na fachada, no
				// cartão ou no Instagram. A equipe redesenha a partir disso, e
				// a pessoa não trava no único arquivo obrigatório.
				'foto'        => 'Sem o arquivo? Uma foto da logo (na fachada, no cartão, na embalagem) ou um print do Instagram também serve.',
			),
			array(
				'chave'       => 'textos',
				'secao'       => 3,
				'tipo'        => 'arquivo',
				'obrigatorio' => false,
				'comenta'     => false,
				'rotulo'      => 'Textos do site',
				'chamado'     => 'os textos do site',
				'multiplo'    => true,
				'aceita'      => array( 'doc', 'docx', 'pdf', 'txt', 'odt' ),
				'aceita_texto' => 'DOC, DOCX, PDF, TXT ou ODT',
			),
			array(
				'chave'       => 'materiais',
				'secao'       => 3,
				'tipo'        => 'arquivo',
				'obrigatorio' => false,
				'comenta'     => false,
				'rotulo'      => 'Fotos, vídeos e materiais',
				'chamado'     => 'as fotos e os materiais',
				'multiplo'    => true,
				'aceita'      => array( 'png', 'jpg', 'jpeg', 'webp', 'heic', 'mp4', 'mov', 'zip' ),
				'aceita_texto' => 'imagens, vídeos ou ZIP',
				// Quem tem 2 GB de fotos manda o endereço de uma pasta. É o
				// caminho que a equipe já usa no WhatsApp.
				'aceita_link' => true,
			),
		);
	}

	/**
	 * A estrutura como está no código, sem o que o painel mudou.
	 *
	 * É o padrão que a tela de configuração mostra e ao qual volta.
	 */
	public static function estrutura() {
		return self::cru();
	}

	/**
	 * O que o painel mudou: quais campos são obrigatórios e quais arquivos
	 * podem ficar para depois.
	 *
	 * Só obrigatoriedade. Ordem, tipo e formato continuam do código — mudar
	 * isso muda o contrato com o e-mail e com os rascunhos abertos.
	 */
	private static function aplicar_regras( array $campo ) {
		$regras = class_exists( 'Leticia_Config' ) ? Leticia_Config::campos() : array();
		if ( ! isset( $regras[ $campo['chave'] ] ) ) {
			return $campo;
		}
		$regra = $regras[ $campo['chave'] ];
		if ( null !== $regra['obrigatorio'] ) {
			$campo['obrigatorio'] = $regra['obrigatorio'];
		}
		if ( 'arquivo' === $campo['tipo'] && null !== $regra['depois'] ) {
			$campo['pode_ficar_pendente'] = $regra['depois'];
		}
		return $campo;
	}

	/** Os 15 campos, com as palavras da base já casadas. */
	public static function todos() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$saida = array();
		foreach ( self::cru() as $campo ) {
			$campo = self::aplicar_regras( $campo );
			$bloco = Leticia_Base::bloco( $campo['chave'] );

			$campo['perguntas'] = ( $bloco && ! empty( $bloco['pergunta'] ) )
				? $bloco['pergunta']
				// Sem a base, o briefing continua: pergunta crua é melhor que
				// erro fatal. A tela de configuração acusa a falta.
				: array( $campo['rotulo'] );

			// O que ela diz depois que a pessoa responde, quando o modelo não
			// disse nada — campo que não comenta, ou a IA fora do ar. É o que
			// impede o modo degradado de soar como formulário.
			$campo['depois'] = $bloco && ! empty( $bloco['depois'] ) ? $bloco['depois'] : array();

			$campo['ajuda'] = array(
				'porque' => $bloco && isset( $bloco['porque'] ) ? $bloco['porque'] : '',
				'serve'  => $bloco && isset( $bloco['serve'] ) ? $bloco['serve'] : '',
				'borda'  => $bloco && isset( $bloco['borda'] ) ? $bloco['borda'] : '',
				'guiar'  => $bloco && isset( $bloco['guiar'] ) ? $bloco['guiar'] : '',
			);

			// Quem não tem 'supor' declarado, supõe. A exceção é escrita, não
			// o contrário: esquecer de marcar um campo de conteúdo o deixaria
			// mudo, e é justamente a condução que faz este produto existir.
			$campo['supor'] = ! isset( $campo['supor'] ) || (bool) $campo['supor'];

			$saida[] = $campo;
		}

		self::$memo = $saida;
		return $saida;
	}

	/** Só as chaves, na ordem. É o contrato. */
	public static function chaves() {
		return wp_list_pluck( self::todos(), 'chave' );
	}

	/**
	 * Quanto tempo, em segundos, uma pessoa leva neste campo.
	 *
	 * Estimativa, para dizer "falta cerca de 3 min" em vez de "8 de 15" —
	 * minuto assusta menos que contagem. Campo de dado é rápido; arquivo
	 * obrigatório pede procurar; opcional costuma ser pulado.
	 */
	public static function segundos( array $campo ) {
		if ( ! empty( $campo['segundos'] ) ) {
			return (int) $campo['segundos'];
		}
		switch ( $campo['tipo'] ) {
			case 'escolha':
				return 10;
			case 'telefone':
			case 'email':
				return 15;
			case 'arquivo':
				return ! empty( $campo['obrigatorio'] ) ? 40 : 20;
			default:
				return 20;
		}
	}

	public static function total() {
		return count( self::cru() );
	}

	/** @return array|null */
	public static function por_chave( $chave ) {
		foreach ( self::todos() as $campo ) {
			if ( $campo['chave'] === $chave ) {
				return $campo;
			}
		}
		return null;
	}

	/** @return int|null posição no roteiro */
	public static function indice( $chave ) {
		foreach ( self::todos() as $i => $campo ) {
			if ( $campo['chave'] === $chave ) {
				return $i;
			}
		}
		return null;
	}

	public static function existe( $chave ) {
		return null !== self::indice( $chave );
	}

	public static function obrigatorios() {
		$saida = array();
		foreach ( self::todos() as $campo ) {
			if ( $campo['obrigatorio'] ) {
				$saida[] = $campo['chave'];
			}
		}
		return $saida;
	}

	/** Quantos campos há em cada seção, na ordem das seções. */
	public static function por_secao() {
		$conta = array();
		foreach ( array_keys( self::SECOES ) as $n ) {
			$conta[ $n ] = 0;
		}
		foreach ( self::todos() as $campo ) {
			$conta[ $campo['secao'] ]++;
		}
		return $conta;
	}

	/**
	 * Uma variante da pergunta, sorteada e presa à sessão, em frase corrida.
	 *
	 * Presa porque a pessoa pode voltar ao campo: ver a pergunta trocar de
	 * texto ao voltar faria parecer que ela mudou de campo.
	 *
	 * @param array $valores o que preenche {nome} e {empresa}
	 */
	public static function pergunta( $chave, $semente = 0, array $valores = array() ) {
		$p = self::pergunta_partes( $chave, $semente, $valores );
		return trim( $p['titulo'] . ' ' . $p['detalhe'] );
	}

	/**
	 * A pergunta dividida: o título, que a tela mostra grande, e o detalhe, que
	 * vem embaixo e menor.
	 *
	 * @return array array( 'titulo' => string, 'detalhe' => string )
	 */
	public static function pergunta_partes( $chave, $semente = 0, array $valores = array() ) {
		$campo = self::por_chave( $chave );
		if ( ! $campo ) {
			return array( 'titulo' => '', 'detalhe' => '' );
		}
		$escolhida = $campo['perguntas'] ? Leticia_Base::escolher( $campo['perguntas'], $semente, $valores ) : null;
		// Nenhuma variante preenchível — base editada com {nome} em todas — ou
		// base ausente: o rótulo é feio, mas é uma pergunta.
		return $escolhida ? $escolhida : array( 'titulo' => $campo['rotulo'], 'detalhe' => '' );
	}

	public static function limpar_cache() {
		self::$memo = null;
	}
}
