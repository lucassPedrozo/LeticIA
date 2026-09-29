<?php
/**
 * O registro — e as duas leituras que só ele produz.
 *
 * **Onde as pessoas param.** O campo que mata o briefing é a informação mais
 * valiosa que este sistema gera, e nenhuma outra métrica a revela. Taxa de
 * conclusão diz que se perde gente; só o abandono por campo diz onde.
 *
 * **Dúvidas por campo.** Campo com muita pergunta é campo mal escrito. A
 * correção é mudar o texto estático da pergunta em `campos.md` — não mexer na
 * base inteira, e muito menos no modelo.
 *
 * Sobre prazos: a **conversa** sai depois de noventa dias, porque a partir daí
 * ela não diagnostica mais nada e só guarda dado pessoal. O **briefing** fica:
 * enviado, é a entrega que a equipe usa; abandonado, é um cliente que deixou
 * oito respostas e um WhatsApp, e isso é ativo comercial, não lixo. O abandonado
 * tem prazo próprio, mais longo, e configurável.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Registro {

	/** Quanto tempo a conversa fica guardada. */
	const RETENCAO_TURNOS = 90;

	/** Quanto tempo um briefing abandonado fica no painel antes de sair. */
	const RETENCAO_ABANDONADOS = 180;

	const CRON = 'leticia_diario';

	/** A "chave" de quem parou depois do último campo, na tela de revisão. */
	const REVISAO = 'revisao';

	/** @var Leticia_Armazem|null */
	private static $armazem = null;

	/**
	 * Quem guarda. Trocável por filtro — é o que permite o terminal e a suíte
	 * usarem o armazém em arquivo sem que nada mais no plugin saiba disso.
	 */
	public static function armazem() {
		if ( null !== self::$armazem ) {
			return self::$armazem;
		}
		self::$armazem = apply_filters( 'leticia_armazem', new Leticia_Armazem_Wpdb() );
		return self::$armazem;
	}

	public static function usar_armazem( Leticia_Armazem $armazem = null ) {
		self::$armazem = $armazem;
	}

	public static function instalar() {
		return self::armazem()->instalar();
	}

	// ------------------------------------------------------------- gravação

	/**
	 * Grava o estado do briefing. Chamado a cada campo respondido.
	 *
	 * O `campo_parado` é calculado aqui e guardado numa coluna própria em vez
	 * de ser deduzido na hora do relatório: quando a pergunta "onde as pessoas
	 * param" for feita, ela precisa ser um agrupamento, não uma varredura de
	 * JSON linha a linha.
	 */
	public static function salvar( $sessao, array $estado, array $extra = array() ) {
		$respostas = isset( $estado['respostas'] ) ? $estado['respostas'] : array();

		$dados = array(
			'campo_parado' => self::campo_parado( $estado ),
			'respondidos'  => Leticia_Roteiro::quantos_resolvidos( $estado ),
			'empresa'      => self::valor( $respostas, 'empresa' ),
			'whatsapp'     => self::valor( $respostas, 'whatsapp' ),
			'email'        => self::valor( $respostas, 'email' ),
			'respostas'    => $respostas,
			'arquivos'     => isset( $extra['arquivos'] ) ? $extra['arquivos'] : array(),
			'pendencias'   => Leticia_Roteiro::pendencias( $estado ),
			'pagina'       => isset( $extra['pagina'] ) ? $extra['pagina'] : '',
			'roteiro'      => array(
				'reperguntados' => isset( $estado['reperguntados'] ) ? $estado['reperguntados'] : array(),
				'ultima_ponte'  => isset( $estado['ultima_ponte'] ) ? $estado['ultima_ponte'] : '',
				'propostas'     => isset( $estado['propostas'] ) ? $estado['propostas'] : array(),
				'anteriores'    => isset( $estado['anteriores'] ) ? $estado['anteriores'] : array(),
				// A última vez que a pessoa mexeu no briefing. O atualizado_em
				// não serve: marcar o lembrete e as partes do e-mail também
				// regravam a linha. E mexer zera o lembrete — parou de novo,
				// cabe outro.
				'atividade'     => time(),
				'lembrete'      => 0,
			),
		);

		$existente = self::armazem()->ler_briefing( $sessao );

		self::medir( $dados, $existente, $estado, $extra );

		// O roteiro guarda também o que a entrega anotou — quais e-mails de um
		// briefing em várias partes já saíram. Regravar as respostas não pode
		// apagar isso, senão a próxima tentativa manda de novo o que já chegou.
		if ( $existente && is_array( $existente['roteiro'] ) ) {
			$dados['roteiro'] = array_merge( $existente['roteiro'], $dados['roteiro'] );
		}

		if ( ! empty( $estado['enviado'] ) ) {
			// Só marca a hora do envio uma vez. Clicar duas vezes em enviar não
			// pode reescrever o carimbo — é por ele que a equipe conta as 72h.
			$dados['enviado_em'] = ( $existente && $existente['enviado_em'] > 0 )
				? (int) $existente['enviado_em']
				: time();
		}

		return self::armazem()->gravar_briefing( $sessao, $dados );
	}

	/**
	 * O campo em que a pessoa parou.
	 *
	 * É o primeiro não resolvido — o mesmo que o roteiro perguntaria a seguir.
	 * Briefing completo devolve vazio: quem terminou não parou em lugar nenhum.
	 */
	public static function campo_parado( array $estado ) {
		$proximo = Leticia_Roteiro::proximo( $estado );
		return $proximo ? $proximo['chave'] : '';
	}

	/**
	 * Grava um turno da conversa.
	 *
	 * Registra o que o cliente escreveu e o que a LetícIA respondeu, com o que
	 * o painel precisa: se foi dúvida, se a trava barrou, se rodou degradado, e
	 * quanto custou em token.
	 */
	public static function turno( $sessao, array $dados ) {
		return self::armazem()->gravar_turno( $sessao, $dados );
	}

	public static function conversa( $sessao ) {
		return self::armazem()->turnos( $sessao );
	}

	public static function briefing( $sessao ) {
		return self::armazem()->ler_briefing( $sessao );
	}

	/**
	 * O e-mail saiu.
	 *
	 * Coluna própria, e não dedução a partir do log: "gravado" e "avisado" são
	 * estados diferentes, e é justamente a diferença entre os dois que o painel
	 * precisa mostrar.
	 */
	public static function marcar_entregue( $sessao, $tentativas = 1 ) {
		return self::armazem()->gravar_briefing(
			$sessao,
			array( 'entregue' => 1, 'tentativas' => (int) $tentativas )
		);
	}

	/**
	 * Um dos e-mails de um briefing em várias partes saiu.
	 *
	 * Guardado a cada parte, e não no fim: se a segunda falhar, a tentativa
	 * seguinte começa por ela, sem repetir a primeira na caixa da equipe.
	 */
	// ------------------------------------------------------------- medição

	/** Parado mais que isto num campo não é tempo de resposta: é pausa. */
	const PAUSA = 900;

	/**
	 * O que a gravação ensina sobre como a pessoa preenche.
	 *
	 * - **Tempo por campo**: do último movimento dela até o campo novo ficar
	 *   resolvido. Medido no servidor, sem confiar em relógio do navegador.
	 *   Mais de 15 minutos não conta — é almoço, não demora da pergunta. O
	 *   primeiro campo fica sem tempo: antes dele não há movimento anotado.
	 * - **Aparelho**: celular, tablet ou computador, pelo navegador que abriu.
	 * - **Voltou depois do lembrete**: mexeu no briefing com um lembrete
	 *   anotado — o lembrete trouxe a pessoa de volta.
	 */
	private static function medir( array &$dados, $existente, array $estado, array $extra ) {
		$antes   = $existente && is_array( $existente['roteiro'] ) ? $existente['roteiro'] : array();
		$agora   = time();
		$tempos  = isset( $antes['tempos'] ) && is_array( $antes['tempos'] ) ? $antes['tempos'] : array();
		$usos    = isset( $antes['usos'] ) && is_array( $antes['usos'] ) ? $antes['usos'] : array();
		$ultimo  = ! empty( $antes['atividade'] ) ? (int) $antes['atividade'] : 0;
		$tinha   = $existente && is_array( $existente['respostas'] ) ? $existente['respostas'] : array();

		foreach ( Leticia_Campos::todos() as $campo ) {
			$chave = $campo['chave'];
			if ( isset( $tinha[ $chave ] ) || ! Leticia_Roteiro::resolvido( $estado, $chave ) ) {
				continue;
			}
			$gasto = $ultimo ? $agora - $ultimo : 0;
			if ( $ultimo && $gasto <= self::PAUSA && ! isset( $tempos[ $chave ] ) ) {
				$tempos[ $chave ] = $gasto;
			}
			break;   // um campo por gravação; o resto (se houver) não tem tempo seu
		}

		if ( ! empty( $antes['lembrete'] ) ) {
			$usos['voltou_lembrete'] = 1;
		}

		$dados['roteiro']['tempos'] = $tempos;
		$dados['roteiro']['usos']   = $usos;
		if ( empty( $antes['aparelho'] ) && ! empty( $extra['agente'] ) ) {
			$dados['roteiro']['aparelho'] = self::aparelho( $extra['agente'] );
		}
	}

	/** Celular, tablet ou computador, pelo agente do navegador. */
	public static function aparelho( $agente ) {
		$agente = (string) $agente;
		if ( preg_match( '/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $agente ) ) {
			return 'tablet';
		}
		if ( preg_match( '/Mobi|iPhone|iPod|Android|Windows Phone/i', $agente ) ) {
			return 'celular';
		}
		return 'computador';
	}

	/**
	 * Conta um uso de algo novo — sugestão aceita, link de continuar, voz.
	 *
	 * É o que diz se uma melhoria funciona: "quantas vezes a lista sugerida
	 * foi usada" responde se valeu a chamada a mais.
	 */
	public static function contar_uso( $sessao, $chave, $quantos = 1 ) {
		$linha = self::armazem()->ler_briefing( $sessao );
		if ( ! $linha ) {
			return false;
		}
		$roteiro = is_array( $linha['roteiro'] ) ? $linha['roteiro'] : array();
		$usos    = isset( $roteiro['usos'] ) && is_array( $roteiro['usos'] ) ? $roteiro['usos'] : array();

		$usos[ $chave ]  = ( isset( $usos[ $chave ] ) ? (int) $usos[ $chave ] : 0 ) + (int) $quantos;
		$roteiro['usos'] = $usos;

		return self::armazem()->gravar_briefing( $sessao, array( 'roteiro' => $roteiro ) );
	}

	/**
	 * As leituras de uso: tempo real, aparelho e as novidades.
	 *
	 * @return array
	 */
	public static function metricas( $dias = 30 ) {
		$desde  = time() - ( (int) $dias * DAY_IN_SECONDS );
		$linhas = self::armazem()->listar_briefings( array( 'desde' => $desde, 'limite' => 2000 ) );

		$tempos    = array();
		$totais    = array();
		$aparelhos = array();
		$usos      = array();
		$com       = array();
		$lembrados = array( 'receberam' => 0, 'voltaram' => 0, 'enviaram' => 0 );
		$curto     = array( 'esbarraram' => 0, 'pararam' => 0 );
		$links     = array( 'criados' => 0, 'abertos' => 0, 'enviados' => 0 );

		foreach ( $linhas as $b ) {
			$r = is_array( $b['roteiro'] ) ? $b['roteiro'] : array();
			$preenchido = ! empty( $r['preenchidos'] );

			if ( $preenchido ) {
				$links['criados']++;
				if ( ! empty( $r['aberto_em'] ) ) {
					$links['abertos']++;
				}
				if ( (int) $b['enviado_em'] > 0 ) {
					$links['enviados']++;
				}
			}

			// Quem nunca mexeu não diz nada sobre tempo nem sobre aparelho.
			if ( (int) $b['respondidos'] < 1 || ( $preenchido && empty( $r['aberto_em'] ) ) ) {
				continue;
			}

			if ( ! empty( $r['aparelho'] ) ) {
				$aparelhos[ $r['aparelho'] ] = isset( $aparelhos[ $r['aparelho'] ] ) ? $aparelhos[ $r['aparelho'] ] + 1 : 1;
			}

			$soma = 0;
			foreach ( ( isset( $r['tempos'] ) ? (array) $r['tempos'] : array() ) as $chave => $segundos ) {
				$tempos[ $chave ][] = (int) $segundos;
				$soma              += (int) $segundos;
			}
			if ( (int) $b['enviado_em'] > 0 && $soma > 0 ) {
				$totais[] = $soma;
			}

			$u = isset( $r['usos'] ) ? (array) $r['usos'] : array();
			foreach ( $u as $chave => $n ) {
				$usos[ $chave ] = ( isset( $usos[ $chave ] ) ? $usos[ $chave ] : 0 ) + (int) $n;
				$com[ $chave ]  = ( isset( $com[ $chave ] ) ? $com[ $chave ] : 0 ) + 1;
			}

			if ( ! empty( $r['lembrete'] ) || ! empty( $u['lembretes'] ) ) {
				$lembrados['receberam']++;
				if ( ! empty( $u['voltou_lembrete'] ) ) {
					$lembrados['voltaram']++;
					if ( (int) $b['enviado_em'] > 0 ) {
						$lembrados['enviaram']++;
					}
				}
			}
			if ( ! empty( $u['curto_ramo'] ) ) {
				$curto['esbarraram']++;
				if ( (int) $b['enviado_em'] < 1 && 'ramo' === $b['campo_parado'] ) {
					$curto['pararam']++;
				}
			}
		}

		$por_campo = array();
		foreach ( Leticia_Campos::todos() as $campo ) {
			$lista                         = isset( $tempos[ $campo['chave'] ] ) ? $tempos[ $campo['chave'] ] : array();
			$por_campo[ $campo['chave'] ] = array(
				'rotulo'     => $campo['rotulo'],
				'estimativa' => Leticia_Campos::segundos( $campo ),
				'mediana'    => self::mediana( $lista ),
				'amostras'   => count( $lista ),
			);
		}

		return array(
			'tempos'    => $por_campo,
			'total'     => self::mediana( $totais ),
			'enviados'  => count( $totais ),
			'aparelhos' => $aparelhos,
			'usos'      => $usos,
			'com'       => $com,
			'lembretes' => $lembrados,
			'curto'     => $curto,
			'links'     => $links,
		);
	}

	private static function mediana( array $numeros ) {
		if ( ! $numeros ) {
			return 0;
		}
		sort( $numeros );
		$meio = (int) floor( count( $numeros ) / 2 );
		return count( $numeros ) % 2 ? (int) $numeros[ $meio ] : (int) round( ( $numeros[ $meio - 1 ] + $numeros[ $meio ] ) / 2 );
	}

	/**
	 * Anota que a lista sugerida deste campo já foi pedida ao modelo.
	 *
	 * Uma vez por briefing: recarregar a página não pode gastar outra
	 * chamada, e quem dispensou a sugestão não quer vê-la de novo.
	 */
	public static function marcar_sugerido( $sessao, $chave ) {
		$linha   = self::armazem()->ler_briefing( $sessao );
		$roteiro = $linha && is_array( $linha['roteiro'] ) ? $linha['roteiro'] : array();

		$roteiro['sugeridos']           = isset( $roteiro['sugeridos'] ) ? (array) $roteiro['sugeridos'] : array();
		$roteiro['sugeridos'][ $chave ] = time();

		return self::armazem()->gravar_briefing( $sessao, array( 'roteiro' => $roteiro ) );
	}

	public static function ja_sugerido( $sessao, $chave ) {
		$linha = self::armazem()->ler_briefing( $sessao );
		return $linha && ! empty( $linha['roteiro']['sugeridos'][ $chave ] );
	}

	/** Anota que o lembrete desta parada já saiu. */
	public static function marcar_lembrete( $sessao ) {
		$linha   = self::armazem()->ler_briefing( $sessao );
		$roteiro = $linha && is_array( $linha['roteiro'] ) ? $linha['roteiro'] : array();

		$roteiro['lembrete']          = time();
		$roteiro['usos']              = isset( $roteiro['usos'] ) && is_array( $roteiro['usos'] ) ? $roteiro['usos'] : array();
		$roteiro['usos']['lembretes'] = ( isset( $roteiro['usos']['lembretes'] ) ? (int) $roteiro['usos']['lembretes'] : 0 ) + 1;

		return self::armazem()->gravar_briefing( $sessao, array( 'roteiro' => $roteiro ) );
	}

	/**
	 * Quando a pessoa mexeu no briefing pela última vez.
	 *
	 * Linha gravada antes deste campo existir não tem a anotação: vale o
	 * atualizado_em, que naquela época era a mesma coisa.
	 */
	public static function ultima_atividade( array $linha ) {
		return ! empty( $linha['roteiro']['atividade'] ) ? (int) $linha['roteiro']['atividade'] : (int) $linha['atualizado_em'];
	}

	public static function marcar_parte( $sessao, $indice ) {
		$linha   = self::armazem()->ler_briefing( $sessao );
		$roteiro = $linha && is_array( $linha['roteiro'] ) ? $linha['roteiro'] : array();
		$feitas  = isset( $roteiro['partes_enviadas'] ) ? (array) $roteiro['partes_enviadas'] : array();

		$feitas[]                   = (int) $indice;
		$roteiro['partes_enviadas'] = array_values( array_unique( array_map( 'intval', $feitas ) ) );

		return self::armazem()->gravar_briefing( $sessao, array( 'roteiro' => $roteiro ) );
	}

	public static function partes_enviadas( $sessao ) {
		$linha = self::armazem()->ler_briefing( $sessao );
		return $linha && isset( $linha['roteiro']['partes_enviadas'] ) ? array_map( 'intval', (array) $linha['roteiro']['partes_enviadas'] ) : array();
	}

	/** O e-mail não saiu. A linha fica no painel até sair ou alguém decidir. */
	public static function marcar_tentativa( $sessao, $tentativas ) {
		return self::armazem()->gravar_briefing(
			$sessao,
			array( 'entregue' => 0, 'tentativas' => (int) $tentativas )
		);
	}

	/**
	 * Briefings gravados cujo e-mail não chegou à equipe.
	 *
	 * É a única leitura do painel que representa trabalho parado: o cliente
	 * cumpriu a parte dele e ninguém do outro lado sabe disso.
	 */
	public static function nao_entregues( $limite = 50 ) {
		$saida = array();
		foreach ( self::armazem()->listar_briefings( array( 'enviados' => true, 'limite' => $limite ) ) as $b ) {
			if ( empty( $b['entregue'] ) ) {
				$saida[] = $b;
			}
		}
		return $saida;
	}

	public static function apagar( $sessao ) {
		return self::armazem()->apagar_briefing( $sessao );
	}

	// ------------------------------------------------------------- leituras

	public static function concluidos( $limite = 50 ) {
		return self::armazem()->listar_briefings( array( 'enviados' => true, 'limite' => $limite ) );
	}

	public static function abandonados( $limite = 50 ) {
		return self::armazem()->listar_briefings( array( 'enviados' => false, 'limite' => $limite ) );
	}

	/**
	 * Onde as pessoas param.
	 *
	 * Conta só os abandonados, e só os que já tinham respondido alguma coisa:
	 * quem abriu a página e fechou sem digitar nada não parou num campo — não
	 * chegou a começar, e contá-lo empilharia ruído no primeiro campo, que é
	 * justamente onde ele mais atrapalha a leitura.
	 */
	public static function abandono_por_campo( $limite = 500 ) {
		$conta = array();

		foreach ( self::armazem()->listar_briefings( array( 'enviados' => false, 'limite' => $limite ) ) as $b ) {
			// Link da equipe ainda fechado: as respostas são dela, não do
			// cliente, e ele não parou em campo nenhum.
			if ( (int) $b['respondidos'] < 1 || Leticia_Links::nao_aberto( $b ) ) {
				continue;
			}
			// Todos os campos respondidos e não enviado: parou na revisão, com
			// o aceite e o botão na frente. Deixar de fora escondia justamente
			// o abandono mais caro — o do briefing inteiro preenchido.
			$chave           = '' !== $b['campo_parado'] ? $b['campo_parado'] : self::REVISAO;
			$conta[ $chave ] = isset( $conta[ $chave ] ) ? $conta[ $chave ] + 1 : 1;
		}

		arsort( $conta );
		return $conta;
	}

	/** Dúvidas por campo. Campo com muita pergunta é campo mal escrito. */
	public static function duvidas_por_campo( $dias = 30 ) {
		$conta = array();
		$desde = time() - ( (int) $dias * DAY_IN_SECONDS );

		foreach ( self::armazem()->listar_briefings( array( 'desde' => $desde, 'limite' => 500 ) ) as $b ) {
			foreach ( self::armazem()->turnos( $b['sessao'] ) as $turno ) {
				if ( 'duvida' !== $turno['tipo'] && 'fora_de_escopo' !== $turno['tipo'] ) {
					continue;
				}
				$chave           = $turno['campo'];
				$conta[ $chave ] = isset( $conta[ $chave ] ) ? $conta[ $chave ] + 1 : 1;
			}
		}

		arsort( $conta );
		return $conta;
	}

	/**
	 * O resumo que a faixa de atenção do painel lê.
	 *
	 * Só o que exige decisão de alguém. Painel que avisa de tudo não é lido.
	 */
	public static function resumo( $dias = 30 ) {
		$desde  = time() - ( (int) $dias * DAY_IN_SECONDS );
		$linhas = self::armazem()->listar_briefings( array( 'desde' => $desde, 'limite' => 500 ) );

		$resumo = array(
			'total'          => count( $linhas ),
			'enviados'       => 0,
			'abandonados'    => 0,
			'com_pendencia'  => 0,
			'nao_entregues'  => 0,
			'bloqueios'      => 0,
			'degradados'     => 0,
		);

		foreach ( $linhas as $b ) {
			if ( $b['enviado_em'] > 0 ) {
				$resumo['enviados']++;
				if ( ! $b['entregue'] ) {
					// Briefing gravado cujo e-mail não saiu. É a única linha do
					// painel que representa trabalho parado de verdade.
					$resumo['nao_entregues']++;
				}
			} elseif ( (int) $b['respondidos'] > 0 && ! Leticia_Links::nao_aberto( $b ) ) {
				$resumo['abandonados']++;
			}
			if ( ! empty( $b['pendencias'] ) ) {
				$resumo['com_pendencia']++;
			}

			foreach ( self::armazem()->turnos( $b['sessao'] ) as $turno ) {
				if ( '' !== $turno['bloqueio'] ) {
					$resumo['bloqueios']++;
				}
				if ( $turno['degradado'] ) {
					$resumo['degradados']++;
				}
			}
		}

		return $resumo;
	}

	// ------------------------------------------------------------- expurgo

	/**
	 * A manutenção diária.
	 *
	 * Devolve o que apagou, para o painel poder mostrar que a limpeza está
	 * mesmo acontecendo — expurgo que ninguém vê é expurgo que ninguém percebe
	 * quando para de rodar.
	 */
	public static function expurgar() {
		return array(
			'turnos'    => self::armazem()->expurgar_turnos( self::RETENCAO_TURNOS ),
			'briefings' => self::armazem()->expurgar_briefings( self::RETENCAO_ABANDONADOS ),
		);
	}

	private static function valor( array $respostas, $chave ) {
		return isset( $respostas[ $chave ]['valor'] ) ? (string) $respostas[ $chave ]['valor'] : '';
	}
}
