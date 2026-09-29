<?php
/**
 * O roteiro — a máquina de estados do briefing.
 *
 * Esta classe é a regra de ouro do projeto: **o roteiro é do PHP, o texto é do
 * modelo**. Aqui se decide quais campos existem, em que ordem, se a resposta é
 * válida em formato, quanto falta na barra e quando o briefing acabou. O modelo
 * não escolhe o próximo campo, não declara o fim e não se lembra de nada — o
 * que ele lembraria é justamente o que ele pode alucinar.
 *
 * Três consequências, e todas as três são requisito:
 *
 *   1. A barra de progresso é honesta: conta campo resolvido, não turno de
 *      conversa.
 *   2. Nenhum campo é pulado nem inventado, aconteça o que acontecer com a API.
 *   3. Modelo fora do ar, o briefing continua. Sem cota, sem chave, com erro de
 *      rede: vira um formulário guiado de perguntas estáticas, sem comentário,
 *      e envia normalmente. Perder um lead porque a IA caiu é o pior defeito
 *      possível deste produto.
 *
 * Funções puras: recebem um estado e devolvem outro. Não tocam banco, nem
 * sessão, nem HTTP — é o que permite a suíte offline cobrir o roteiro inteiro
 * em milissegundos.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Roteiro {

	/** No máximo uma repergunta por campo. Insistir duas vezes perde o cliente. */
	const REPERGUNTAS_POR_CAMPO = 1;

	public static function novo( $semente = null ) {
		return array(
			'respostas'     => array(),
			'indice'        => 0,
			'reperguntados' => array(),
			// A última reação dita. Vai ao modelo para ele não repetir a mesma
			// ideia na resposta seguinte — ele não lembra de nada sozinho.
			'ultima_ponte'  => '',
			// Rascunhos esperando a pessoa decidir: chave => texto, origem
			// ('resposta' ou 'ajuda') e a frase que o apresenta.
			'propostas'     => array(),
			// A primeira tentativa de um campo que ganhou repergunta. Vai ao
			// modelo junto com a segunda, para o rascunho sair das duas.
			'anteriores'    => array(),
			'semente'       => null === $semente ? wp_rand( 0, 999999 ) : (int) $semente,
			'enviado'       => false,
		);
	}

	/** Completa um estado vindo de fora (rascunho, requisição) sem confiar nele. */
	public static function sanear( $estado ) {
		$base = self::novo( isset( $estado['semente'] ) ? (int) $estado['semente'] : 0 );
		if ( ! is_array( $estado ) ) {
			return $base;
		}

		$respostas = array();
		if ( isset( $estado['respostas'] ) && is_array( $estado['respostas'] ) ) {
			foreach ( $estado['respostas'] as $chave => $dados ) {
				// Campo fora da lista não entra. É a defesa contra um cliente
				// adulterado inventar campo que a equipe nunca vai ler.
				if ( ! Leticia_Campos::existe( $chave ) || ! is_array( $dados ) ) {
					continue;
				}
				$respostas[ $chave ] = array(
					'valor'    => isset( $dados['valor'] ) ? (string) $dados['valor'] : '',
					'bruto'    => isset( $dados['bruto'] ) ? (string) $dados['bruto'] : '',
					'pulado'   => ! empty( $dados['pulado'] ),
					'pendente' => ! empty( $dados['pendente'] ),
					'negado'   => ! empty( $dados['negado'] ),
					'link'     => isset( $dados['link'] ) ? (string) $dados['link'] : '',
					'arquivos' => isset( $dados['arquivos'] ) && is_array( $dados['arquivos'] ) ? $dados['arquivos'] : array(),
					'texto_site' => isset( $dados['texto_site'] ) ? mb_substr( (string) $dados['texto_site'], 0, Leticia_Validacao::TETO_CARACTERES, 'UTF-8' ) : '',
				);
			}
		}

		$reperguntados = array();
		if ( isset( $estado['reperguntados'] ) && is_array( $estado['reperguntados'] ) ) {
			foreach ( $estado['reperguntados'] as $chave => $quantas ) {
				if ( Leticia_Campos::existe( $chave ) ) {
					$reperguntados[ $chave ] = (int) $quantas;
				}
			}
		}

		$propostas = array();
		if ( isset( $estado['propostas'] ) && is_array( $estado['propostas'] ) ) {
			foreach ( $estado['propostas'] as $chave => $p ) {
				if ( ! Leticia_Campos::existe( $chave ) || ! is_array( $p ) || empty( $p['texto'] ) ) {
					continue;
				}
				$propostas[ $chave ] = array(
					'texto'  => mb_substr( (string) $p['texto'], 0, Leticia_Validacao::TETO_CARACTERES, 'UTF-8' ),
					'intro'  => isset( $p['intro'] ) ? mb_substr( (string) $p['intro'], 0, Leticia_Modelo::TETO_FALA, 'UTF-8' ) : '',
					'origem' => isset( $p['origem'] ) && 'ajuda' === $p['origem'] ? 'ajuda' : 'resposta',
				);
			}
		}

		$anteriores = array();
		if ( isset( $estado['anteriores'] ) && is_array( $estado['anteriores'] ) ) {
			foreach ( $estado['anteriores'] as $chave => $texto ) {
				if ( Leticia_Campos::existe( $chave ) && is_string( $texto ) && '' !== trim( $texto ) ) {
					$anteriores[ $chave ] = mb_substr( $texto, 0, Leticia_Validacao::TETO_CARACTERES, 'UTF-8' );
				}
			}
		}

		$base['respostas']     = $respostas;
		$base['propostas']     = $propostas;
		$base['anteriores']    = $anteriores;
		$base['reperguntados'] = $reperguntados;
		$base['ultima_ponte']  = isset( $estado['ultima_ponte'] ) && is_string( $estado['ultima_ponte'] )
			? mb_substr( $estado['ultima_ponte'], 0, Leticia_Modelo::TETO_FALA, 'UTF-8' )
			: '';
		$base['enviado']       = ! empty( $estado['enviado'] );
		$base['indice']        = self::posicao( $base, isset( $estado['indice'] ) ? (int) $estado['indice'] : 0 );

		return $base;
	}

	private static function posicao( array $estado, $pedido ) {
		$total = Leticia_Campos::total();
		if ( $pedido < 0 ) {
			return 0;
		}
		return min( $pedido, $total );   // total = está na revisão
	}

	// ------------------------------------------------------------- consultas

	public static function resolvido( array $estado, $chave ) {
		return isset( $estado['respostas'][ $chave ] );
	}

	public static function quantos_resolvidos( array $estado ) {
		$n = 0;
		foreach ( Leticia_Campos::chaves() as $chave ) {
			if ( self::resolvido( $estado, $chave ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * O próximo campo a perguntar: o primeiro que ainda não foi resolvido.
	 *
	 * Sempre o primeiro, e não "o seguinte ao atual" — é isto que garante que
	 * voltar para corrigir o campo 3 não deixe o 9 para trás quando a pessoa
	 * seguir. Devolve null quando não falta nenhum: aí é hora da revisão.
	 *
	 * @return array|null
	 */
	public static function proximo( array $estado ) {
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( ! self::resolvido( $estado, $campo['chave'] ) ) {
				return $campo;
			}
		}
		return null;
	}

	/** O campo em que a conversa está agora. Null quer dizer revisão. */
	public static function campo_atual( array $estado ) {
		$todos = Leticia_Campos::todos();
		if ( isset( $todos[ $estado['indice'] ] ) ) {
			return $todos[ $estado['indice'] ];
		}
		return null;
	}

	/** Todo obrigatório resolvido. Pendência não impede: é aviso, não bloqueio. */
	public static function pode_enviar( array $estado ) {
		foreach ( Leticia_Campos::obrigatorios() as $chave ) {
			if ( ! self::resolvido( $estado, $chave ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * O que segura o cronômetro das 72 horas. Vai em destaque para a equipe.
	 */
	public static function pendencias( array $estado ) {
		$saida = array();
		foreach ( $estado['respostas'] as $chave => $dados ) {
			if ( ! empty( $dados['pendente'] ) ) {
				$saida[] = $chave;
			}
		}
		return $saida;
	}

	/**
	 * A barra.
	 *
	 * O envio é o passo a mais no denominador: com 15 de 15 respondidos a barra
	 * ainda não está cheia, porque o briefing ainda não saiu daqui. É o que
	 * impede a tela de prometer 100% antes de a equipe ter recebido qualquer
	 * coisa.
	 */
	/**
	 * Quantos minutos, mais ou menos, faltam para terminar.
	 *
	 * Arredonda para cima: prometer menos do que leva é o que faz a pessoa
	 * desistir no meio. Zero só quando não falta nada.
	 */
	public static function minutos_restantes( array $estado ) {
		$segundos = 0;
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( ! self::resolvido( $estado, $campo['chave'] ) ) {
				$segundos += Leticia_Campos::segundos( $campo );
			}
		}
		return $segundos > 0 ? (int) ceil( $segundos / 60 ) : 0;
	}

	public static function progresso( array $estado ) {
		$total      = Leticia_Campos::total();
		$resolvidos = self::quantos_resolvidos( $estado );
		$campo      = self::campo_atual( $estado );

		$passos = $total + 1;
		$feitos = $resolvidos + ( $estado['enviado'] ? 1 : 0 );

		return array(
			'respondidos' => $resolvidos,
			'total'       => $total,
			'minutos'     => self::minutos_restantes( $estado ),
			'secao'       => $campo ? $campo['secao'] : count( Leticia_Campos::SECOES ),
			'secoes'      => count( Leticia_Campos::SECOES ),
			'por_secao'   => Leticia_Campos::por_secao(),
			'fracao'      => $passos > 0 ? round( $feitos / $passos, 4 ) : 0.0,
		);
	}

	// ------------------------------------------------------------- transições

	/**
	 * Grava a resposta de um campo.
	 *
	 * Aceita qualquer campo conhecido, não só o atual: corrigir é a ação mais
	 * pedida num formulário e a mais ausente num chat. Quem garante que nada
	 * fica para trás é proximo(), que sempre volta ao primeiro pendente.
	 *
	 * @return array array( 'estado' => array, 'erro' => string|'', 'validacao' => array )
	 */
	public static function responder( array $estado, $chave, $bruto, array $extra = array() ) {
		$campo = Leticia_Campos::por_chave( $chave );
		if ( ! $campo ) {
			return self::recusa( $estado, 'Esse campo não existe neste briefing.' );
		}

		// Arquivo não passa por validação de texto: quem valida extensão e MIME
		// é Leticia_Arquivos, no momento em que o pedaço chega.
		if ( 'arquivo' === $campo['tipo'] ) {
			return self::gravar( $estado, $campo, array(
				'valor'    => isset( $extra['valor'] ) ? (string) $extra['valor'] : '',
				'link'     => isset( $extra['link'] ) ? (string) $extra['link'] : '',
				'arquivos' => isset( $extra['arquivos'] ) && is_array( $extra['arquivos'] ) ? $extra['arquivos'] : array(),
				'pendente' => ! empty( $extra['pendente'] ) && ! empty( $campo['pode_ficar_pendente'] ),
				'bruto'    => '',
			) );
		}

		$v = Leticia_Validacao::checar( $campo, $bruto );
		if ( ! $v['ok'] ) {
			$saida              = self::recusa( $estado, $v['erro'] );
			$saida['validacao'] = $v;
			return $saida;
		}

		$saida = self::gravar( $estado, $campo, array(
			'valor'    => $v['valor'],
			'bruto'    => (string) $bruto,
			'pendente' => ! empty( $v['pendente'] ),
			'negado'   => ! empty( $v['negado'] ),
			// Negar sem valor (e-mail: "não tenho") é, para a equipe, um campo
			// em branco — como pular.
			'pulado'   => ! empty( $v['negado'] ) && '' === $v['valor'],
			// valor_limpo do modelo só entra em campo de texto livre, e nunca
			// reescreve o conteúdo: o gravado é o que a pessoa quis dizer.
			'limpo'    => isset( $extra['limpo'] ) ? (string) $extra['limpo'] : '',
		) );

		$saida['validacao'] = $v;
		return $saida;
	}

	/** Pula um opcional. Nunca vai ao modelo — "não sei" não precisa de IA. */
	public static function pular( array $estado, $chave ) {
		$campo = Leticia_Campos::por_chave( $chave );
		if ( ! $campo ) {
			return self::recusa( $estado, 'Esse campo não existe neste briefing.' );
		}
		if ( $campo['obrigatorio'] ) {
			return self::recusa( $estado, 'Esta eu preciso mesmo: sem ela, a equipe não consegue montar o site.' );
		}
		return self::gravar( $estado, $campo, array( 'valor' => '', 'bruto' => '', 'pulado' => true ) );
	}

	/**
	 * Reabre um campo para correção.
	 *
	 * A resposta antiga continua gravada até ser reescrita — é o que faz a
	 * barra não andar para trás quando alguém volta para conferir.
	 */
	public static function voltar_para( array $estado, $chave ) {
		$indice = Leticia_Campos::indice( $chave );
		if ( null === $indice ) {
			return self::recusa( $estado, 'Esse campo não existe neste briefing.' );
		}
		$estado['indice'] = $indice;
		return array( 'estado' => $estado, 'erro' => '', 'validacao' => array() );
	}

	/** Anota que este campo já foi reperguntado. Só cabe uma vez. */
	public static function marcar_repergunta( array $estado, $chave ) {
		$atual = isset( $estado['reperguntados'][ $chave ] ) ? (int) $estado['reperguntados'][ $chave ] : 0;
		$estado['reperguntados'][ $chave ] = $atual + 1;
		return $estado;
	}

	// ------------------------------------------------------------ rascunho

	/** Guarda a primeira tentativa, para a segunda ir ao modelo junto. */
	public static function guardar_anterior( array $estado, $chave, $bruto ) {
		$estado['anteriores'][ $chave ] = mb_substr( (string) $bruto, 0, Leticia_Validacao::TETO_CARACTERES, 'UTF-8' );
		return $estado;
	}

	/**
	 * Guarda um rascunho esperando decisão.
	 *
	 * @param string $origem 'resposta' — a pessoa respondeu e o campo já está
	 *                       gravado; 'ajuda' — ela pediu ajuda e o campo ainda
	 *                       está aberto.
	 */
	public static function guardar_proposta( array $estado, $chave, $texto, $origem, $intro = '' ) {
		$estado['propostas']            = array();
		$estado['propostas'][ $chave ] = array(
			'texto'  => (string) $texto,
			'intro'  => (string) $intro,
			'origem' => 'ajuda' === $origem ? 'ajuda' : 'resposta',
		);
		return $estado;
	}

	/**
	 * O rascunho que a tela deve mostrar agora, se houver.
	 *
	 * @return array|null array( 'chave' => string, 'proposta' => array )
	 */
	public static function proposta_pendente( array $estado ) {
		if ( ! empty( $estado['enviado'] ) || empty( $estado['propostas'] ) ) {
			return null;
		}
		foreach ( $estado['propostas'] as $chave => $proposta ) {
			// Rascunho de ajuda só vale enquanto o campo continuar aberto.
			if ( 'ajuda' === $proposta['origem'] && self::resolvido( $estado, $chave ) ) {
				continue;
			}
			if ( 'resposta' === $proposta['origem'] && ! isset( $estado['respostas'][ $chave ] ) ) {
				continue;
			}
			return array( 'chave' => $chave, 'proposta' => $proposta );
		}
		return null;
	}

	/**
	 * Qualquer outra ação — responder, pular, voltar — deixa o rascunho de lado.
	 * Não fica um texto esperando decisão escondido atrás de outro campo.
	 */
	public static function esquecer_propostas( array $estado ) {
		$estado['propostas'] = array();
		return $estado;
	}

	/**
	 * A decisão sobre o rascunho: usar, ajustar ou deixar de lado.
	 *
	 * "Usar" grava o texto que o servidor guardou, e não um que o navegador
	 * mande. "Ajustar" grava o que a pessoa escreveu — é resposta dela, como
	 * qualquer outra. Quando o rascunho veio de um pedido de ajuda, o campo
	 * ainda está aberto: o texto aprovado passa pela validação do campo e vira
	 * a resposta.
	 *
	 * @return array array( 'estado', 'erro', 'origem' )
	 */
	public static function decidir_proposta( array $estado, $chave, $acao, $texto = '' ) {
		if ( ! isset( $estado['propostas'][ $chave ] ) ) {
			$saida           = self::recusa( $estado, 'Essa sugestão não está mais aqui.' );
			$saida['origem'] = '';
			return $saida;
		}

		$proposta = $estado['propostas'][ $chave ];
		unset( $estado['propostas'][ $chave ] );

		if ( 'dispensar' === $acao ) {
			return array( 'estado' => $estado, 'erro' => '', 'validacao' => array(), 'origem' => $proposta['origem'] );
		}

		$final = 'ajustar' === $acao ? trim( (string) $texto ) : $proposta['texto'];
		if ( '' === $final ) {
			$saida           = self::recusa( $estado, 'O texto ficou vazio. Escreva alguma coisa ou volte para a sugestão.' );
			$saida['origem'] = $proposta['origem'];
			return $saida;
		}
		if ( mb_strlen( $final, 'UTF-8' ) > Leticia_Validacao::TETO_CARACTERES ) {
			$saida           = self::recusa( $estado, 'Ficou um pouco longo. Consegue encurtar?' );
			$saida['origem'] = $proposta['origem'];
			return $saida;
		}

		if ( 'ajuda' === $proposta['origem'] ) {
			$r = self::responder( $estado, $chave, $final );
			if ( '' !== $r['erro'] ) {
				$r['origem'] = 'ajuda';
				return $r;
			}
			$estado = $r['estado'];
		} elseif ( ! isset( $estado['respostas'][ $chave ] ) ) {
			$saida           = self::recusa( $estado, 'Essa sugestão não está mais aqui.' );
			$saida['origem'] = '';
			return $saida;
		}

		$estado['respostas'][ $chave ]['texto_site'] = $final;

		return array( 'estado' => $estado, 'erro' => '', 'validacao' => array(), 'origem' => $proposta['origem'] );
	}

	/**
	 * Ainda cabe repergunta neste campo?
	 *
	 * Uma, por padrão. O campo pode pedir mais (`reperguntas`) quando o que
	 * sai dele é o texto do site — e ainda assim o limite existe: depois dele,
	 * o que vier é aceito, e a equipe completa.
	 */
	public static function pode_reperguntar( array $estado, $chave ) {
		$feitas = isset( $estado['reperguntados'][ $chave ] ) ? (int) $estado['reperguntados'][ $chave ] : 0;
		$campo  = Leticia_Campos::por_chave( $chave );
		$limite = $campo && ! empty( $campo['reperguntas'] ) ? (int) $campo['reperguntas'] : self::REPERGUNTAS_POR_CAMPO;
		return $feitas < $limite;
	}

	/**
	 * O que ela diz quando a resposta ficou curta e o modelo não disse nada.
	 *
	 * Varia a cada tentativa, para a segunda vez não soar como a primeira.
	 */
	public static function fala_curta( array $estado, $chave ) {
		$vezes = isset( $estado['reperguntados'][ $chave ] ) ? (int) $estado['reperguntados'][ $chave ] : 0;
		$fala  = Leticia_Base::escolher( 'curto-' . $chave, (int) $estado['semente'] + $vezes, self::valores( $estado ) );
		if ( ! $fala ) {
			$fala = Leticia_Base::escolher( 'curto', (int) $estado['semente'] + $vezes, self::valores( $estado ) );
		}
		return $fala ? trim( $fala['titulo'] . ' ' . $fala['detalhe'] ) : '';
	}

	public static function marcar_enviado( array $estado ) {
		$estado['enviado'] = true;
		return $estado;
	}

	private static function gravar( array $estado, array $campo, array $dados ) {
		$valor = isset( $dados['valor'] ) ? $dados['valor'] : '';
		if ( ! empty( $dados['limpo'] ) && 'texto' === $campo['tipo'] ) {
			$valor = $dados['limpo'];
		}

		$estado['respostas'][ $campo['chave'] ] = array(
			'valor'    => $valor,
			'bruto'    => isset( $dados['bruto'] ) ? $dados['bruto'] : '',
			'pulado'   => ! empty( $dados['pulado'] ),
			'pendente' => ! empty( $dados['pendente'] ),
			'negado'   => ! empty( $dados['negado'] ),
			'link'     => isset( $dados['link'] ) ? $dados['link'] : '',
			'arquivos' => isset( $dados['arquivos'] ) ? $dados['arquivos'] : array(),
			// Resposta nova, texto aprovado nenhum: o rascunho antigo foi escrito
			// para a resposta antiga.
			'texto_site' => isset( $dados['texto_site'] ) ? $dados['texto_site'] : '',
		);
		unset( $estado['anteriores'][ $campo['chave'] ] );

		// O ponteiro anda para o primeiro pendente, que pode não ser o
		// seguinte: quem voltou para corrigir volta de onde parou.
		$proximo          = self::proximo( $estado );
		$estado['indice'] = $proximo ? Leticia_Campos::indice( $proximo['chave'] ) : Leticia_Campos::total();

		return array( 'estado' => $estado, 'erro' => '', 'validacao' => array() );
	}

	private static function recusa( array $estado, $mensagem ) {
		return array( 'estado' => $estado, 'erro' => $mensagem, 'validacao' => array() );
	}

	// ------------------------------------------------------------------ falas

	/**
	 * O que a LetícIA diz antes da pergunta, quando há o que dizer.
	 *
	 * Tudo texto estático da base: nenhuma destas falas gasta chamada de API.
	 */
	public static function falas_antes( array $estado, array $campo ) {
		$falas    = array();
		$abertura = self::abertura( $estado, $campo );
		if ( '' !== $abertura ) {
			$falas[] = $abertura;
		}
		$aviso = self::aviso( $estado, $campo );
		if ( '' !== $aviso ) {
			$falas[] = $aviso;
		}
		return $falas;
	}

	/** É a primeira pergunta da etapa? Abertura de etapa aparece uma vez só. */
	public static function primeiro_da_secao( array $estado, array $campo ) {
		foreach ( Leticia_Campos::todos() as $outro ) {
			if ( $outro['secao'] !== $campo['secao'] ) {
				continue;
			}
			if ( $outro['chave'] === $campo['chave'] ) {
				break;
			}
			if ( self::resolvido( $estado, $outro['chave'] ) ) {
				return false;
			}
		}
		return true;
	}

	/** A frase de abertura da etapa, quando é a primeira pergunta dela. */
	public static function abertura( array $estado, array $campo ) {
		if ( ! self::primeiro_da_secao( $estado, $campo ) ) {
			return '';
		}
		$abertura = Leticia_Base::escolher( 'abertura-secao-' . $campo['secao'], (int) $estado['semente'], self::valores( $estado ) );
		return $abertura ? trim( $abertura['titulo'] . ' ' . $abertura['detalhe'] ) : '';
	}

	/**
	 * O aviso que muda o que o campo significa para esta pessoa.
	 *
	 * Hoje um só: "Não, só as minhas" em imagens faz materiais continuar
	 * opcional, mas agora sem foto nenhuma o site fica sem imagem.
	 */
	public static function aviso( array $estado, array $campo ) {
		if ( 'materiais' === $campo['chave'] && self::recusou_banco_de_imagens( $estado ) ) {
			return Leticia_Base::texto( 'sem-imagens-de-banco' );
		}
		return '';
	}

	/**
	 * O que preenche {nome} e {empresa} nos textos da base.
	 *
	 * Chamar a pessoa pelo nome e a empresa pelo nome é o jeito mais barato de
	 * o briefing parecer conversa: não custa chamada nenhuma, e a pessoa acabou
	 * de dizer os dois.
	 */
	public static function valores( array $estado ) {
		$pega = function ( $chave ) use ( $estado ) {
			return isset( $estado['respostas'][ $chave ]['valor'] ) ? trim( (string) $estado['respostas'][ $chave ]['valor'] ) : '';
		};
		// O primeiro arquivo que ficou para depois, do jeito que se fala dele.
		$pendente = '';
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( 'arquivo' === $campo['tipo'] && ! empty( $estado['respostas'][ $campo['chave'] ]['pendente'] ) ) {
				$pendente = isset( $campo['chamado'] ) ? $campo['chamado'] : mb_strtolower( $campo['rotulo'], 'UTF-8' );
				break;
			}
		}

		return array(
			'nome'       => Leticia_Base::primeiro_nome( $pega( 'responsavel' ) ),
			'empresa'    => $pega( 'empresa' ),
			'assistente' => Leticia_Config::nome(),
			'pendente'   => $pendente,
			'minutos'    => (string) max( 1, self::minutos_restantes( $estado ) ),
		);
	}

	/**
	 * A reação à resposta que acabou de chegar — a ponte até a próxima pergunta.
	 *
	 * Texto estático da base, sem chamada de API. Entra quando o modelo não
	 * disse nada: campo que não comenta, ou IA fora do ar. É o que faz o modo
	 * degradado continuar parecendo alguém conduzindo, e não um formulário que
	 * troca de tela.
	 *
	 * Pulou, ficou pendente e respondeu são reações diferentes — "anotei" depois
	 * de um pulo é o tipo de coisa que denuncia que ninguém está lendo.
	 *
	 * @return string '' quando não há o que dizer
	 */
	public static function ponte( array $estado, $chave ) {
		$campo = Leticia_Campos::por_chave( $chave );
		if ( ! $campo || ! isset( $estado['respostas'][ $chave ] ) ) {
			return '';
		}

		$r       = $estado['respostas'][ $chave ];
		$valores = self::valores( $estado );
		$semente = (int) $estado['semente'] + (int) Leticia_Campos::indice( $chave );

		if ( ! empty( $r['negado'] ) && Leticia_Base::variantes( 'depois-de-negar-' . $chave ) ) {
			// "Tudo bem! Vou anotar que a empresa não tem endereço físico."
			$lista = Leticia_Base::variantes( 'depois-de-negar-' . $chave );
		} elseif ( ! empty( $r['pulado'] ) ) {
			$lista = Leticia_Base::variantes( 'depois-de-pular' );
		} elseif ( ! empty( $r['pendente'] ) ) {
			$lista = Leticia_Base::variantes( 'depois-de-adiar-' . $chave );
			if ( ! $lista ) {
				// Arquivo que o painel liberou para depois e não tem fala própria.
				$lista = Leticia_Base::variantes( 'depois-de-adiar' );
			}
		} else {
			$lista = self::depois_para( $campo, (string) $r['valor'] );
		}

		if ( ! $lista ) {
			return '';
		}
		$escolhida = Leticia_Base::escolher( $lista, $semente, $valores );
		return $escolhida ? trim( $escolhida['titulo'] . ' ' . $escolhida['detalhe'] ) : '';
	}

	/**
	 * As reações que servem para este valor.
	 *
	 * Variante que começa com "(valor)" só vale para quem escolheu aquela
	 * opção: "combinado, a equipe completa com banco de imagens" dito para quem
	 * acabou de clicar em "só as minhas" é o contrário do que a pessoa pediu.
	 */
	private static function depois_para( array $campo, $valor ) {
		$marcadas = array();
		$livres   = array();
		foreach ( $campo['depois'] as $variante ) {
			if ( preg_match( '/^\(([a-z0-9_]+)\)\s*(.+)$/u', $variante, $m ) ) {
				if ( $m[1] === $valor ) {
					$marcadas[] = $m[2];
				}
				continue;
			}
			$livres[] = $variante;
		}
		return $marcadas ? $marcadas : $livres;
	}

	public static function recusou_banco_de_imagens( array $estado ) {
		return isset( $estado['respostas']['imagens_ia'] )
			&& 'nao' === $estado['respostas']['imagens_ia']['valor'];
	}

	/**
	 * A resposta já sugerida, quando dá para montar do que a pessoa digitou.
	 *
	 * Hoje só contatos_site: a pessoa acabou de dar WhatsApp e e-mail, e
	 * perguntar de novo é o que faz um formulário parecer burro. Vira botão na
	 * tela — e botão não gasta cota.
	 *
	 * @return string '' quando não há o que sugerir
	 */
	public static function sugestao( array $estado, array $campo ) {
		if ( empty( $campo['sugere_de'] ) ) {
			return '';
		}

		$partes = array();
		foreach ( (array) $campo['sugere_de'] as $origem ) {
			if ( ! isset( $estado['respostas'][ $origem ] ) ) {
				continue;
			}
			$valor = trim( $estado['respostas'][ $origem ]['valor'] );
			// "Sem endereço físico" é resposta, mas não é contato.
			if ( '' === $valor || ! empty( $estado['respostas'][ $origem ]['negado'] ) || ! empty( $estado['respostas'][ $origem ]['pulado'] ) ) {
				continue;
			}
			$rotulos  = array( 'whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'endereco' => 'Endereço' );
			$rotulo   = isset( $rotulos[ $origem ] ) ? $rotulos[ $origem ] : $origem;
			$partes[] = $rotulo . ' ' . $valor;
		}

		return $partes ? implode( ' · ', $partes ) : '';
	}
}
