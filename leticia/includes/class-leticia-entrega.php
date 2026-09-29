<?php
/**
 * A entrega: o briefing chegando na equipe.
 *
 * **O e-mail é notificação, não transporte.** Quando ele falha, o briefing não
 * se perde: já está gravado. A falha entra na fila, é retentada, e aparece no
 * painel como a única linha que representa trabalho parado de verdade. A tela
 * do cliente continua dizendo "recebido", porque foi recebido.
 *
 * Três e-mails saem daqui, com o desenho de Leticia_Email:
 *
 *   `[SITE EXPRESS] Novo briefing - {domínio}`   o briefing, para a equipe
 *   `[CONTINUAÇÃO] - {domínio}`                  o que ficou para depois e chegou pelo link
 *   `Recebemos o seu briefing — JoinVix`          a cópia do cliente, se deixou e-mail
 *
 * Em todos, o que segura o prazo vem primeiro, em destaque; as respostas vêm
 * em blocos; o técnico vem no fim, pequeno.
 *
 * **Os arquivos vão anexados, e só isso.** Nada de link público para
 * `wp-content/uploads`: os arquivos vão no e-mail — em mais de um, se não
 * couberem — e saem do servidor depois da entrega.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Entrega {

	const CRON_REENTREGA = 'leticia_reentrega';

	/** Espera entre tentativas, em segundos. Depois da última, só na mão. */
	const RECUOS = array( 300, 900, 2700, 7200, 21600 );

	/** Os blocos do e-mail, na ordem das etapas do briefing. */
	const SECOES_EMAIL = array(
		1 => 'Contato e aprovação',
		2 => 'O negócio',
		3 => 'Arquivos',
	);

	// ------------------------------------------------------------- o assunto

	/**
	 * `[SITE EXPRESS] Novo briefing - {domínio}`
	 *
	 * O prefixo é byte a byte o de hoje: filtro ou marcador que a equipe tenha
	 * no Gmail continua pegando. Sem domínio, entra o nome da empresa mais o
	 * aviso — um assunto dizendo "ainda não tenho" não ajuda ninguém a achar o
	 * e-mail depois.
	 */
	public static function assunto( array $estado ) {
		return self::preencher_assunto( Leticia_Config::assunto(), $estado );
	}

	/** `[CONTINUAÇÃO] - {domínio}`: chegou o que tinha ficado para depois. */
	public static function assunto_continuacao( array $estado ) {
		return self::preencher_assunto( Leticia_Config::assunto_continuacao(), $estado );
	}

	private static function preencher_assunto( $modelo, array $estado ) {
		$dominio  = self::valor( $estado, 'dominio' );
		$empresa  = self::valor( $estado, 'empresa' );
		$pendente = ! empty( $estado['respostas']['dominio']['pendente'] );
		$rabo     = ( $pendente || '' === $dominio )
			? ( '' !== $empresa ? $empresa . ' (sem domínio ainda)' : 'sem domínio ainda' )
			: $dominio;

		return str_replace( array( '[DOMINIO]', '[EMPRESA]' ), array( $rabo, $empresa ), $modelo );
	}

	// --------------------------------------------------------------- o corpo

	/** O briefing para a equipe. */
	public static function corpo_equipe( $sessao, array $estado, array $extra = array() ) {
		$empresa = self::valor( $estado, 'empresa' );
		$blocos  = array();

		// O que segura o prazo, antes de tudo.
		$pendencias = self::pendencias_texto( $estado );
		if ( $pendencias ) {
			$link = self::link_anexo( $sessao, $estado, isset( $extra['pagina'] ) ? $extra['pagina'] : '' );
			$blocos[] = Leticia_Email::aviso(
				'atencao',
				'Atenção: o prazo de 72 horas ainda não começou',
				array_values( $pendencias ),
				'' !== $link
					? 'O cliente recebeu um link para mandar o que ficou faltando. Se ele perder, é este:<br><br>' . Leticia_Email::botao( 'Link do cliente para mandar depois', $link )
					: ''
			);
		} else {
			$blocos[] = Leticia_Email::aviso( 'bom', 'Material completo: as 72 horas começam agora' );
		}

		if ( isset( $extra['total_partes'] ) && $extra['total_partes'] > 1 ) {
			$blocos[] = Leticia_Email::aviso( 'nota', sprintf( 'Os arquivos vieram em %d e-mails, pelo tamanho. Este é o 1º.', (int) $extra['total_partes'] ) );
		}

		foreach ( self::SECOES_EMAIL as $numero => $titulo ) {
			$blocos[] = Leticia_Email::secao( $numero . '. ' . $titulo, self::linhas_da_secao( $estado, $numero, $extra, true ) );
		}

		$blocos[] = Leticia_Email::secao( 'Aceite', array( Leticia_Config::consentimento() => 'Sim, marcado pelo cliente' ) );
		$blocos[] = Leticia_Email::rodape( self::rodape( $sessao, $extra ) );

		return Leticia_Email::documento(
			'Novo briefing' . ( '' !== $empresa ? ': ' . $empresa : '' ),
			self::subtitulo( $estado ),
			$blocos
		);
	}

	/**
	 * A cópia do cliente.
	 *
	 * Sem IP, sem agente de usuário e sem link do painel: nada disso é da conta
	 * de quem preencheu, e mandar de volta o IP da pessoa para a própria pessoa
	 * é o tipo de coisa que só assusta.
	 */
	public static function corpo_cliente( array $estado, $link_anexo = '' ) {
		$nome   = Leticia_Base::primeiro_nome( self::valor( $estado, 'responsavel' ) );
		$blocos = array(
			Leticia_Email::paragrafo( 'A equipe da JoinVix já está com tudo o que você mandou e vai montar o site a partir disso. Se faltar alguma coisa, a gente fala com você pelo WhatsApp que deixou.' ),
		);

		$pendencias = self::pendencias_texto( $estado, true );
		if ( $pendencias ) {
			$blocos[] = Leticia_Email::aviso(
				'atencao',
				'O que ainda falta',
				array_values( $pendencias ),
				'' !== $link_anexo ? 'Quando estiver com o arquivo, é só mandar por aqui:<br><br>' . Leticia_Email::botao( 'Mandar o que ficou faltando', $link_anexo ) : ''
			);
		} else {
			$blocos[] = Leticia_Email::aviso( 'bom', 'Está tudo com a equipe, e as 72 horas já começaram a contar.' );
		}

		foreach ( self::SECOES_EMAIL as $numero => $titulo ) {
			$blocos[] = Leticia_Email::secao( $titulo, self::linhas_da_secao( $estado, $numero, array(), false ) );
		}
		$blocos[] = Leticia_Email::paragrafo( 'Equipe JoinVix', true );

		return Leticia_Email::documento(
			( '' !== $nome ? $nome . ', seu' : 'Seu' ) . ' briefing chegou',
			'Uma cópia do que você respondeu.',
			$blocos
		);
	}

	/** Os e-mails 2 em diante de um briefing grande: só os arquivos. */
	public static function corpo_continuacao( $sessao, array $estado, array $pacote, $indice, $total ) {
		$linhas = array();
		foreach ( $pacote as $arquivo ) {
			$campo    = Leticia_Campos::por_chave( $arquivo['campo'] );
			$rotulo   = $campo ? $campo['rotulo'] : $arquivo['campo'];
			$linhas[] = $arquivo['nome'] . ' (' . size_format( $arquivo['tamanho'], 1 ) . ') — ' . $rotulo;
		}

		$empresa = self::valor( $estado, 'empresa' );
		$rodape  = self::rodape( $sessao, array() );

		return Leticia_Email::documento(
			sprintf( 'Arquivos do briefing%s', '' !== $empresa ? ' de ' . $empresa : '' ),
			sprintf( 'E-mail %d de %d — o que não coube no anterior.', $indice + 1, $total ),
			array(
				Leticia_Email::aviso( 'nota', 'Anexados neste e-mail', $linhas ),
				Leticia_Email::rodape( array_intersect_key( $rodape, array_flip( array( 'Briefing', 'Ver no painel', 'Desenvolvido por' ) ) ) ),
			)
		);
	}

	/** "padariaaurora.com.br · Marina Alves · (47) 99999-8888" */
	private static function subtitulo( array $estado ) {
		$dominio = ! empty( $estado['respostas']['dominio']['pendente'] ) || '' === self::valor( $estado, 'dominio' )
			? 'sem domínio ainda'
			: self::valor( $estado, 'dominio' );
		return implode( ' · ', array_filter( array( $dominio, self::valor( $estado, 'responsavel' ), self::valor( $estado, 'whatsapp' ) ) ) );
	}

	/**
	 * As linhas de um bloco: rótulo do campo => resposta por extenso.
	 *
	 * @return array
	 */
	private static function linhas_da_secao( array $estado, $secao, array $extra, $para_equipe ) {
		$linhas = array();
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( (int) $campo['secao'] !== (int) $secao ) {
				continue;
			}
			$linhas[ $campo['rotulo'] ] = self::mostrar( $estado, $campo, $extra, $para_equipe );

			// O texto que o cliente aprovou vem logo embaixo do que ele
			// respondeu: a equipe vê o que ele contou e o que ele quer no site.
			$resposta = isset( $estado['respostas'][ $campo['chave'] ] ) ? $estado['respostas'][ $campo['chave'] ] : array();
			$site     = isset( $resposta['texto_site'] ) ? trim( (string) $resposta['texto_site'] ) : '';
			if ( '' === $site ) {
				continue;
			}
			if ( isset( $resposta['valor'] ) && trim( (string) $resposta['valor'] ) === $site ) {
				// Veio de um pedido de ajuda: a resposta É o rascunho. Repetir
				// o texto seria ruído; o que a equipe precisa saber é que quem
				// escreveu foi a LetícIA — pode ser genérico, vale confirmar.
				if ( $para_equipe ) {
					$linhas[ $campo['rotulo'] ] .= "\n(escrito pela LetícIA a pedido do cliente, e aprovado por ele)";
				}
				continue;
			}
			$linhas[ $para_equipe ? '↳ Texto para o site (aprovado pelo cliente)' : '↳ Texto para o site' ] = $site;
		}
		return $linhas;
	}

	private static function rodape( $sessao, array $extra ) {
		$agora = function_exists( 'current_time' ) ? current_time( 'timestamp' ) : time();

		return array(
			'Data'              => gmdate( 'd/m/Y', $agora ),
			'Horário'           => gmdate( 'H:i', $agora ),
			'URL da página'     => isset( $extra['pagina'] ) ? $extra['pagina'] : '',
			'Agente de usuário' => isset( $extra['agente'] ) ? $extra['agente'] : '',
			'IP remoto'         => isset( $extra['ip'] ) ? $extra['ip'] : '',
			'Briefing'          => $sessao,
			'Ver no painel'     => self::link_painel( $sessao ),
			'Desenvolvido por'  => 'LetícIA ' . LETICIA_VERSAO,
		);
	}

	/** Como cada resposta aparece no e-mail, em texto puro. */
	private static function mostrar( array $estado, array $campo, array $extra, $para_equipe = true ) {
		$chave = $campo['chave'];
		$r     = isset( $estado['respostas'][ $chave ] ) ? $estado['respostas'][ $chave ] : null;

		if ( ! $r ) {
			return '';
		}

		if ( 'arquivo' === $campo['tipo'] ) {
			return self::mostrar_arquivos( $r, $extra, $para_equipe );
		}

		if ( 'escolha' === $campo['tipo'] ) {
			foreach ( $campo['opcoes'] as $opcao ) {
				if ( $opcao['valor'] === $r['valor'] ) {
					return $opcao['texto'];
				}
			}
		}

		if ( ! empty( $r['pendente'] ) && 'dominio' === $chave ) {
			return 'Ainda não tem — precisa registrar';
		}

		return trim( (string) $r['valor'] );
	}

	private static function mostrar_arquivos( array $r, array $extra, $para_equipe ) {
		if ( ! empty( $r['pendente'] ) ) {
			return $para_equipe ? 'Pendente — o cliente manda depois pelo link' : 'Fica para depois, pelo link acima';
		}

		$mapa   = isset( $extra['partes'] ) ? $extra['partes'] : null;
		$total  = isset( $extra['total_partes'] ) ? (int) $extra['total_partes'] : 1;
		$partes = array();

		foreach ( (array) $r['arquivos'] as $arquivo ) {
			$nome = isset( $arquivo['nome'] ) ? $arquivo['nome'] : '';
			if ( '' === $nome ) {
				continue;
			}
			if ( ! $para_equipe || null === $mapa ) {
				$partes[] = $nome;
				continue;
			}
			// Sem link de download: o arquivo sai do servidor na entrega. O que
			// a equipe precisa saber é em qual e-mail ele está.
			$id = isset( $arquivo['id'] ) ? $arquivo['id'] : '';
			if ( ! isset( $mapa[ $id ] ) ) {
				$partes[] = $nome . ' (não estava mais no servidor — peça de novo ao cliente)';
			} elseif ( $total > 1 && $mapa[ $id ] > 0 ) {
				$partes[] = $nome . sprintf( ' (anexado no e-mail %d de %d)', $mapa[ $id ] + 1, $total );
			} else {
				$partes[] = $nome . ' (anexado)';
			}
		}

		if ( ! empty( $r['link'] ) ) {
			$partes[] = 'Pasta compartilhada: ' . $r['link'];
		}

		return implode( "\n", $partes );
	}

	/**
	 * O endereço de download, para o painel.
	 *
	 * Passa pelo wp-admin de propósito: só abre para quem está logado com
	 * permissão, e só enquanto o arquivo ainda está no servidor.
	 */
	public static function link( array $arquivo ) {
		$base = function_exists( 'admin_url' ) ? admin_url( 'admin.php' ) : 'https://exemplo/wp-admin/admin.php';

		return add_query_arg(
			array(
				'page'    => Leticia_Download::PAGINA,
				'acao'    => 'baixar',
				'arquivo' => $arquivo['id'],
			),
			$base
		);
	}

	/** O briefing aberto no painel. Só abre para quem está logado. */
	public static function link_painel( $sessao ) {
		$base = function_exists( 'admin_url' ) ? admin_url( 'options-general.php' ) : 'https://exemplo/wp-admin/options-general.php';
		return add_query_arg( array( 'page' => Leticia_Download::PAGINA, 'briefing' => $sessao ), $base );
	}

	/** O link de "mandar depois", quando ficou arquivo pendente. */
	public static function link_anexo( $sessao, array $estado, $pagina = '' ) {
		foreach ( Leticia_Roteiro::pendencias( $estado ) as $chave ) {
			$campo = Leticia_Campos::por_chave( $chave );
			if ( $campo && 'arquivo' === $campo['tipo'] ) {
				return Leticia_Anexo::link( $sessao, $pagina );
			}
		}
		return '';
	}

	/**
	 * As pendências em texto, uma por campo.
	 *
	 * Duas vozes: a da equipe, que diz o que fazer; a do cliente, falando com
	 * ele. Qualquer arquivo pode ficar para depois — o painel decide quais —,
	 * então só logomarca e domínio têm frase própria.
	 *
	 * @return array chave => texto
	 */
	public static function pendencias_texto( array $estado, $para_cliente = false ) {
		$avisos = array();

		foreach ( Leticia_Roteiro::pendencias( $estado ) as $chave ) {
			$campo  = Leticia_Campos::por_chave( $chave );
			$rotulo = $campo ? $campo['rotulo'] : $chave;

			if ( 'dominio' === $chave ) {
				$avisos[ $chave ] = $para_cliente
					? 'Domínio: a JoinVix registra para você, e o registro leva um tempo fora das 72 horas.'
					: 'Domínio: ainda não existe e precisa ser registrado — isso fica fora das 72 horas.';
			} elseif ( 'logo' === $chave ) {
				$avisos[ $chave ] = $para_cliente
					? 'Logomarca: você manda depois pelo link, e as 72 horas começam quando ela chegar.'
					: 'Logomarca: não veio. O cliente manda depois pelo link, e as 72 horas só começam quando ela chegar.';
			} else {
				$avisos[ $chave ] = $para_cliente
					? $rotulo . ': você manda depois pelo link.'
					: $rotulo . ': não veio. O cliente manda depois pelo link.';
			}
		}

		return $avisos;
	}

	// --------------------------------------------------------------- o envio

	/**
	 * Manda o briefing para a equipe.
	 *
	 * Nunca devolve erro para a tela do cliente: o briefing já está gravado, e
	 * o que falhou foi o aviso. Quem precisa saber da falha é o painel.
	 *
	 * @return array array( 'ok' => bool, 'erro' => string, 'tentativas' => int )
	 */
	public static function enviar( $sessao, array $estado, array $extra = array() ) {
		$linha      = Leticia_Registro::briefing( $sessao );
		$tentativas = $linha ? (int) $linha['tentativas'] + 1 : 1;

		if ( $linha && ! empty( $linha['entregue'] ) ) {
			// Já foi. Retentativa duplicada não manda o mesmo briefing de novo
			// para a equipe — que é como uma fila de retry vira spam.
			return array( 'ok' => true, 'erro' => '', 'tentativas' => (int) $linha['tentativas'] );
		}

		$destinos = Leticia_Config::destino();
		if ( ! $destinos ) {
			return self::falhou( $sessao, $tentativas, 'não há e-mail de destino configurado' );
		}

		$divisao = self::pacotes( $estado );
		$pacotes = $divisao['pacotes'] ? $divisao['pacotes'] : array( array() );
		$total   = count( $pacotes );
		$feitas  = Leticia_Registro::partes_enviadas( $sessao );

		$extra['partes']       = self::mapa_de_partes( $pacotes );
		$extra['total_partes'] = $total;

		foreach ( $pacotes as $i => $pacote ) {
			if ( in_array( $i, $feitas, true ) ) {
				continue;   // já está na caixa da equipe
			}

			$foi = wp_mail(
				$destinos,
				self::assunto_da_parte( self::assunto( $estado ), $i, $total ),
				0 === $i ? self::corpo_equipe( $sessao, $estado, $extra ) : self::corpo_continuacao( $sessao, $estado, $pacote, $i, $total ),
				self::cabecalhos( $estado ),
				wp_list_pluck( $pacote, 'caminho' )
			);

			if ( ! $foi ) {
				return self::falhou(
					$sessao,
					$tentativas,
					$total > 1 ? sprintf( 'o servidor de e-mail recusou a mensagem %d de %d', $i + 1, $total ) : 'o servidor de e-mail recusou a mensagem'
				);
			}

			if ( $total > 1 ) {
				Leticia_Registro::marcar_parte( $sessao, $i );
			}
		}

		Leticia_Registro::marcar_entregue( $sessao, $tentativas );

		// Entregue, os arquivos saem daqui: a cópia deles está na caixa da equipe.
		Leticia_Arquivos::apagar_do_briefing( $sessao, $estado );

		// A cópia do cliente é secundária: se ela falhar, a entrega continua
		// feita. Ninguém deve reenviar o briefing para a equipe porque a cópia
		// de cortesia não saiu.
		$copia = self::valor( $estado, 'email' );
		if ( '' !== $copia ) {
			wp_mail(
				$copia,
				'Recebemos o seu briefing — JoinVix',
				self::corpo_cliente( $estado, self::link_anexo( $sessao, $estado, isset( $extra['pagina'] ) ? $extra['pagina'] : '' ) ),
				self::cabecalhos( $estado )
			);
		}

		do_action( 'leticia_entregue', $sessao, $tentativas, $total, $divisao['faltando'] );

		return array( 'ok' => true, 'erro' => '', 'tentativas' => $tentativas );
	}

	private static function falhou( $sessao, $tentativas, $motivo ) {
		Leticia_Registro::marcar_tentativa( $sessao, $tentativas );
		self::agendar_retentativa( $sessao, $tentativas );

		do_action( 'leticia_entrega_falhou', $sessao, $tentativas, $motivo );

		return array( 'ok' => false, 'erro' => $motivo, 'tentativas' => $tentativas );
	}

	/**
	 * Os arquivos do briefing, divididos em e-mails.
	 *
	 * O que não cabe num e-mail vai no seguinte. Cada arquivo já subiu com o
	 * teto de um e-mail, então sempre cabe em algum. A ordem é a dos campos, e é
	 * fixa: a tentativa seguinte monta as mesmas partes, e é por isso que dá
	 * para pular as que já saíram.
	 *
	 * @return array array( 'pacotes' => array( array( arquivo, ... ), ... ), 'faltando' => string[] )
	 */
	public static function pacotes( array $estado ) {
		$teto     = Leticia_Config::teto_email();
		$pacotes  = array();
		$atual    = array();
		$soma     = 0;
		$faltando = array();

		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( empty( $estado['respostas'][ $campo['chave'] ]['arquivos'] ) ) {
				continue;
			}
			foreach ( (array) $estado['respostas'][ $campo['chave'] ]['arquivos'] as $arquivo ) {
				$caminho = Leticia_Arquivos::caminho_de( (array) $arquivo );
				if ( '' === $caminho || ! is_readable( $caminho ) ) {
					$faltando[] = isset( $arquivo['nome'] ) ? $arquivo['nome'] : '';
					continue;
				}
				$tamanho = (int) filesize( $caminho );
				if ( $atual && $soma + $tamanho > $teto ) {
					$pacotes[] = $atual;
					$atual     = array();
					$soma      = 0;
				}
				$atual[] = array(
					'id'      => isset( $arquivo['id'] ) ? $arquivo['id'] : '',
					'nome'    => isset( $arquivo['nome'] ) ? $arquivo['nome'] : basename( $caminho ),
					'caminho' => $caminho,
					'tamanho' => $tamanho,
					'campo'   => $campo['chave'],
				);
				$soma += $tamanho;
			}
		}
		if ( $atual ) {
			$pacotes[] = $atual;
		}

		return array( 'pacotes' => $pacotes, 'faltando' => array_filter( $faltando ) );
	}

	/** Em qual e-mail cada arquivo foi, pelo id. */
	private static function mapa_de_partes( array $pacotes ) {
		$mapa = array();
		foreach ( $pacotes as $i => $pacote ) {
			foreach ( $pacote as $arquivo ) {
				$mapa[ $arquivo['id'] ] = $i;
			}
		}
		return $mapa;
	}

	/** O assunto com "(e-mail 2 de 3)" quando há mais de um. */
	public static function assunto_da_parte( $assunto, $indice, $total ) {
		if ( is_array( $assunto ) ) {
			// Compatível com a assinatura antiga, que recebia o estado.
			$assunto = self::assunto( $assunto );
		}
		return $total > 1 ? sprintf( '%s (e-mail %d de %d)', $assunto, $indice + 1, $total ) : $assunto;
	}

	private static function cabecalhos( array $estado ) {
		$cabecalhos = array( 'Content-Type: text/html; charset=UTF-8' );

		// O remetente é a caixa do formulário. Sem isso o WordPress manda como
		// wordpress@dominio, que cai em spam com muito mais facilidade.
		$remetente = Leticia_Config::remetente();
		if ( '' !== $remetente ) {
			$cabecalhos[] = 'From: ' . $remetente;
		}

		// Responder o e-mail cai no cliente, não numa caixa que ninguém lê.
		$email = self::valor( $estado, 'email' );
		if ( '' !== $email ) {
			$nome         = self::valor( $estado, 'responsavel' );
			$cabecalhos[] = 'Reply-To: ' . ( '' !== $nome ? $nome . ' <' . $email . '>' : $email );
		}

		return $cabecalhos;
	}

	// ------------------------------------------------------------ a fila

	public static function agendar_retentativa( $sessao, $tentativas ) {
		$posicao = max( 0, $tentativas - 1 );
		if ( $posicao >= count( self::RECUOS ) ) {
			// Cinco tentativas em seis horas e nada. A partir daqui é decisão de
			// gente, e o painel mostra a linha.
			do_action( 'leticia_entrega_desistiu', $sessao, $tentativas );
			return false;
		}

		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}

		return wp_schedule_single_event(
			time() + self::RECUOS[ $posicao ],
			self::CRON_REENTREGA,
			array( $sessao )
		);
	}

	// ------------------------------------------------ o que chegou depois

	/**
	 * Chegou pelo link o que tinha ficado para depois.
	 *
	 * `[CONTINUAÇÃO] - {domínio}`: e-mail próprio, curto. A primeira coisa que
	 * ele diz é o que muda para a equipe — o prazo começou, ou ainda falta algo.
	 *
	 * Não passa pela fila de retentativa: o briefing já foi entregue, e o
	 * arquivo que não saiu continua baixável pelo painel.
	 */
	public static function avisar_anexo( $sessao, array $estado, $chave, $pagina = '' ) {
		$destinos = Leticia_Config::destino();
		if ( ! $destinos ) {
			return false;
		}

		$campo    = Leticia_Campos::por_chave( $chave );
		$resposta = $estado['respostas'][ $chave ];
		$restam   = self::pendencias_texto( $estado );
		$empresa  = self::valor( $estado, 'empresa' );

		$so_este = array( 'respostas' => array( $chave => $resposta ) );
		$divisao = self::pacotes( $so_este );
		$pacotes = $divisao['pacotes'] ? $divisao['pacotes'] : array( array() );
		$total   = count( $pacotes );

		$blocos   = array();
		$blocos[] = $restam
			? Leticia_Email::aviso( 'atencao', 'Chegou, mas o prazo ainda não começou', array_values( $restam ) )
			: Leticia_Email::aviso( 'bom', 'Material completo: as 72 horas começam agora' );
		$blocos[] = Leticia_Email::secao(
			'O que chegou',
			array( $campo['rotulo'] => self::mostrar_arquivos( $resposta, array( 'partes' => self::mapa_de_partes( $pacotes ), 'total_partes' => $total ), true ) )
		);
		$blocos[] = Leticia_Email::secao(
			'De quem é',
			array(
				'Empresa'     => $empresa,
				'Domínio'     => self::mostrar( $estado, Leticia_Campos::por_chave( 'dominio' ), array() ),
				'Responsável' => self::valor( $estado, 'responsavel' ),
				'WhatsApp'    => self::valor( $estado, 'whatsapp' ),
			)
		);
		$rodape   = self::rodape( $sessao, array( 'pagina' => $pagina ) );
		$blocos[] = Leticia_Email::rodape( array_intersect_key( $rodape, array_flip( array( 'Data', 'Horário', 'Briefing', 'Ver no painel', 'Desenvolvido por' ) ) ) );

		$corpo = Leticia_Email::documento(
			'Continuação do briefing' . ( '' !== $empresa ? ': ' . $empresa : '' ),
			'O cliente mandou pelo link o que tinha ficado para depois.',
			$blocos
		);

		$assunto = self::assunto_continuacao( $estado );
		$foi     = true;
		foreach ( $pacotes as $i => $pacote ) {
			$foi = wp_mail(
				$destinos,
				self::assunto_da_parte( $assunto, $i, $total ),
				0 === $i ? $corpo : self::corpo_continuacao( $sessao, $estado, $pacote, $i, $total ),
				self::cabecalhos( $estado ),
				wp_list_pluck( $pacote, 'caminho' )
			) && $foi;
		}

		// Só sai do servidor se chegou.
		if ( $foi ) {
			Leticia_Arquivos::apagar_do_briefing( $sessao, $so_este );
		}

		do_action( 'leticia_anexo_recebido', $sessao, $chave, (bool) $foi );

		return (bool) $foi;
	}

	/** Chamado pelo cron. Refaz o e-mail a partir do que está gravado. */
	public static function retentar( $sessao ) {
		$linha = Leticia_Registro::briefing( $sessao );

		if ( ! $linha || ! empty( $linha['entregue'] ) || (int) $linha['enviado_em'] < 1 ) {
			return false;
		}

		$estado = Leticia_Roteiro::sanear(
			array( 'respostas' => $linha['respostas'], 'enviado' => true )
		);

		return self::enviar( $sessao, $estado, array( 'pagina' => $linha['pagina'] ) );
	}

	/** O que o painel chama para reenviar na mão. */
	public static function reenviar_agora( $sessao ) {
		return self::retentar( $sessao );
	}

	// --------------------------------------------------------- auxiliares

	private static function valor( array $estado, $chave ) {
		return isset( $estado['respostas'][ $chave ]['valor'] )
			? trim( (string) $estado['respostas'][ $chave ]['valor'] )
			: '';
	}
}
