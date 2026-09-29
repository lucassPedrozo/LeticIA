<?php
/**
 * A aba Briefings: as leituras que só o registro produz.
 *
 * Em ordem de urgência:
 *
 *   1. **Não entregues.** O cliente enviou e o e-mail não saiu. É a única linha
 *      que representa trabalho parado de verdade, então vem primeiro e com o
 *      botão de reenviar do lado.
 *   2. **Onde as pessoas param.** O campo que mata o briefing. Taxa de
 *      conclusão diz que se perde gente; só isto diz onde.
 *   3. **Dúvidas por campo.** Campo com muita pergunta é campo mal escrito — a
 *      correção é o texto em `campos.md`, não o modelo.
 *   4. **A lista**, e cada briefing aberto com respostas, arquivos e conversa.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Painel {

	/** A janela das leituras. Um mês é o bastante para ver padrão sem esquecer mudança de texto. */
	const DIAS = 30;

	public static function briefings( $filtro = '' ) {
		$resumo = Leticia_Registro::resumo( self::DIAS );

		echo '<div class="leticia-cartoes">';
		Leticia_Admin::cartao( 'neutro', 'Começados em ' . self::DIAS . ' dias', (string) ( $resumo['enviados'] + $resumo['abandonados'] ), 'Contando só quem respondeu pelo menos um campo.' );
		Leticia_Admin::cartao(
			'bom',
			'Enviados',
			(string) $resumo['enviados'],
			self::taxa( $resumo['enviados'], $resumo['enviados'] + $resumo['abandonados'] )
		);
		Leticia_Admin::cartao( $resumo['abandonados'] ? 'atento' : 'neutro', 'Parados no meio', (string) $resumo['abandonados'], 'Ficam aqui por ' . Leticia_Registro::RETENCAO_ABANDONADOS . ' dias — têm nome e WhatsApp.' );
		Leticia_Admin::cartao( $resumo['com_pendencia'] ? 'atento' : 'neutro', 'Com pendência', (string) $resumo['com_pendencia'], 'Arquivo ou domínio que ainda seguram as 72 horas.' );
		echo '</div>';

		self::nao_entregues();

		echo '<div class="leticia-colunas">';
		self::onde_param();
		self::duvidas();
		echo '</div>';

		self::uso();

		self::lista( $filtro );
	}

	// ------------------------------------------------------------ uso

	/**
	 * Como as pessoas preenchem: tempo real, aparelho e o que as novidades
	 * rendem. É o que diz, depois do lançamento, qual pergunta enxugar e se
	 * uma melhoria valeu o que custa.
	 */
	private static function uso() {
		$m = Leticia_Registro::metricas( self::DIAS );

		echo '<section class="leticia-bloco"><h2>Como as pessoas preenchem</h2>';
		echo '<p class="description">Últimos ' . (int) self::DIAS . ' dias. Tempo medido no servidor, do movimento anterior até a resposta; parada de mais de 15 minutos não conta.</p>';

		echo '<div class="leticia-cartoes">';
		Leticia_Admin::cartao(
			'neutro',
			'Tempo real, do começo ao envio',
			$m['total'] ? self::minutos( $m['total'] ) : '—',
			$m['enviados'] ? 'Mediana de ' . $m['enviados'] . ' briefing(s) enviado(s). A tela promete uns ' . (int) ceil( array_sum( array_column( $m['tempos'], 'estimativa' ) ) / 60 ) . ' min.' : 'Nenhum briefing enviado com tempo medido ainda.'
		);
		$total_aparelhos = array_sum( $m['aparelhos'] );
		$partes          = array();
		foreach ( array( 'celular' => 'celular', 'computador' => 'computador', 'tablet' => 'tablet' ) as $chave => $rotulo ) {
			if ( ! empty( $m['aparelhos'][ $chave ] ) ) {
				$partes[] = round( $m['aparelhos'][ $chave ] / $total_aparelhos * 100 ) . '% ' . $rotulo;
			}
		}
		Leticia_Admin::cartao( 'neutro', 'Aparelho', $partes ? $partes[0] : '—', $partes ? implode( ' · ', $partes ) : 'Ainda sem dado.' );
		echo '</div>';

		// Tempo por pergunta: estimativa da tela contra a mediana medida.
		echo '<h3>Tempo por pergunta</h3><table class="widefat striped leticia-tabela"><thead><tr><th>Pergunta</th><th>A tela estima</th><th>Mediana real</th><th>Medidas</th></tr></thead><tbody>';
		foreach ( $m['tempos'] as $t ) {
			$acima = $t['amostras'] >= 3 && $t['mediana'] > $t['estimativa'] * 1.5;
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s%s</td><td>%d</td></tr>',
				esc_html( $t['rotulo'] ),
				esc_html( $t['estimativa'] . ' s' ),
				$t['amostras'] ? esc_html( $t['mediana'] . ' s' ) : '—',
				$acima ? ' <span class="leticia-etiqueta e-atento">acima da estimativa</span>' : '',
				(int) $t['amostras']
			);
		}
		echo '</tbody></table>';

		// As novidades: quanto cada uma é usada.
		$u     = $m['usos'];
		$uso   = function ( $chave ) use ( $u ) { return isset( $u[ $chave ] ) ? (int) $u[ $chave ] : 0; };
		$lista = $uso( 'rascunho_servicos_usar' ) + $uso( 'rascunho_servicos_ajustar' ) + $uso( 'rascunho_servicos_dispensar' );
		$ramo  = $uso( 'rascunho_ramo_usar' ) + $uso( 'rascunho_ramo_ajustar' ) + $uso( 'rascunho_ramo_dispensar' );

		$linhas = array(
			array( 'Lista de serviços sugerida', $uso( 'lista_sugerida' ) . ' sugerida(s)', $lista ? sprintf( '%d usada(s) como veio, %d ajustada(s), %d dispensada(s)', $uso( 'rascunho_servicos_usar' ), $uso( 'rascunho_servicos_ajustar' ), $uso( 'rascunho_servicos_dispensar' ) ) : 'sem decisão ainda' ),
			array( 'Texto sobre o negócio (rascunho)', $ramo . ' decisão(ões)', $ramo ? sprintf( '%d usado(s), %d ajustado(s), %d dispensado(s)', $uso( 'rascunho_ramo_usar' ), $uso( 'rascunho_ramo_ajustar' ), $uso( 'rascunho_ramo_dispensar' ) ) : '—' ),
			array( 'Continuar depois', $uso( 'continuar_link' ) . ' link(s) gerado(s)', sprintf( '%d por e-mail · %d aberto(s) pelo link', $uso( 'continuar_email' ), $uso( 'abriu_link' ) ) ),
			array( 'Lembrete por e-mail', $m['lembretes']['receberam'] . ' briefing(s) lembrado(s)', sprintf( '%d voltaram · %d enviaram depois', $m['lembretes']['voltaram'], $m['lembretes']['enviaram'] ) ),
			array( 'Mínimo de palavras no ramo', $m['curto']['esbarraram'] . ' briefing(s) esbarraram', $m['curto']['esbarraram'] ? sprintf( '%d pararam no ramo e não enviaram', $m['curto']['pararam'] ) : '—' ),
			array( 'Resposta por voz', $uso( 'voz' ) . ' gravação(ões)', isset( $m['com']['voz'] ) ? $m['com']['voz'] . ' briefing(s) usaram' : '—' ),
			array( 'Links com dados preenchidos', $m['links']['criados'] . ' criado(s)', sprintf( '%d aberto(s) · %d enviado(s)', $m['links']['abertos'], $m['links']['enviados'] ) ),
		);

		echo '<h3>As novidades</h3><table class="widefat striped leticia-tabela"><thead><tr><th>O quê</th><th>Quanto</th><th>Resultado</th></tr></thead><tbody>';
		foreach ( $linhas as $l ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $l[0] ), esc_html( $l[1] ), esc_html( $l[2] ) );
		}
		echo '</tbody></table></section>';
	}

	private static function minutos( $segundos ) {
		$segundos = (int) $segundos;
		return $segundos < 90 ? $segundos . ' s' : round( $segundos / 60, 1 ) . ' min';
	}

	private static function taxa( $parte, $total ) {
		if ( $total < 1 ) {
			return 'Nenhum briefing ainda.';
		}
		return sprintf( '%d%% de quem começou chegou ao fim.', (int) round( $parte / $total * 100 ) );
	}

	// ------------------------------------------------------ não entregues

	private static function nao_entregues() {
		$linhas = Leticia_Registro::nao_entregues();
		if ( ! $linhas ) {
			return;
		}

		echo '<h2 id="nao-entregues">Não chegaram na equipe</h2>';
		echo '<p class="description">O cliente terminou e recebeu "recebido" na tela; o e-mail é que não saiu. A fila tenta sozinha por seis horas — depois disso, só daqui.</p>';
		echo '<table class="widefat striped leticia-tabela"><thead><tr><th>Empresa</th><th>Enviado</th><th>Tentativas</th><th></th></tr></thead><tbody>';

		foreach ( $linhas as $b ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%d</td><td>%s</td></tr>',
				esc_url( Leticia_Admin::url( array( 'briefing' => $b['sessao'] ) ) ),
				esc_html( '' !== $b['empresa'] ? $b['empresa'] : '(sem nome)' ),
				esc_html( Leticia_Admin::data( $b['enviado_em'] ) ),
				(int) $b['tentativas'],
				self::botao_reenviar( $b['sessao'] ) // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}

		echo '</tbody></table>';
	}

	public static function botao_reenviar( $sessao ) {
		return sprintf(
			'<form method="post" action="%s" class="leticia-inline"><input type="hidden" name="action" value="leticia_reenviar"><input type="hidden" name="sessao" value="%s">%s<button type="submit" class="button button-primary button-small">Tentar entregar agora</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $sessao ),
			wp_nonce_field( 'leticia_reenviar', '_wpnonce', true, false )
		);
	}

	// ------------------------------------------------------ onde param

	private static function onde_param() {
		$conta = Leticia_Registro::abandono_por_campo();

		echo '<section class="leticia-bloco"><h2>Onde as pessoas param</h2>';

		if ( ! $conta ) {
			echo '<p class="description">Ninguém parou no meio ainda — ou ninguém começou.</p></section>';
			return;
		}

		echo '<p class="description">O campo em que estava cada briefing abandonado. O primeiro da lista é onde mexer primeiro.</p>';
		self::barras( $conta );
		echo '</section>';
	}

	private static function duvidas() {
		$conta = Leticia_Registro::duvidas_por_campo( self::DIAS );

		echo '<section class="leticia-bloco"><h2>Dúvidas por campo</h2>';

		if ( ! $conta ) {
			echo '<p class="description">Nenhuma pergunta em vez de resposta nos últimos ' . (int) self::DIAS . ' dias.</p></section>';
			return;
		}

		echo '<p class="description">Vezes em que a pessoa perguntou em vez de responder. Campo com muita dúvida pede pergunta reescrita em <code>conhecimento/campos.md</code>.</p>';
		self::barras( $conta );
		echo '</section>';
	}

	private static function barras( array $conta ) {
		$maior = max( $conta );

		echo '<ul class="leticia-barras">';
		foreach ( $conta as $chave => $n ) {
			$campo  = Leticia_Campos::por_chave( $chave );
			$rotulo = $campo ? $campo['rotulo'] : ( Leticia_Registro::REVISAO === $chave ? 'Revisão, antes de enviar' : $chave );
			printf(
				'<li><span class="nome">%s</span><span class="trilho"><span style="width:%d%%"></span></span><span class="n">%d</span></li>',
				esc_html( $rotulo ),
				(int) round( $n / $maior * 100 ),
				(int) $n
			);
		}
		echo '</ul>';
	}

	// ------------------------------------------------------------ lista

	private static function lista( $filtro ) {
		$filtros = array(
			''          => 'Todos',
			'enviados'  => 'Enviados',
			'parados'   => 'Parados no meio',
			'pendencia' => 'Com pendência',
		);
		if ( ! isset( $filtros[ $filtro ] ) ) {
			$filtro = '';
		}

		$args = array( 'limite' => 100 );
		if ( 'enviados' === $filtro || 'pendencia' === $filtro ) {
			$args['enviados'] = true;
		} elseif ( 'parados' === $filtro ) {
			$args['enviados'] = false;
		}

		$linhas = array();
		foreach ( Leticia_Registro::armazem()->listar_briefings( $args ) as $b ) {
			// Aberto e fechado sem digitar nada não é briefing, é visita.
			if ( (int) $b['enviado_em'] < 1 && (int) $b['respondidos'] < 1 ) {
				continue;
			}
			if ( 'pendencia' === $filtro && empty( $b['pendencias'] ) ) {
				continue;
			}
			$linhas[] = $b;
		}

		echo '<h2>Briefings</h2><ul class="subsubsub">';
		$partes = array();
		foreach ( $filtros as $chave => $rotulo ) {
			$partes[] = sprintf(
				'<li><a href="%s" class="%s">%s</a></li>',
				esc_url( Leticia_Admin::url( '' === $chave ? array() : array( 'filtro' => $chave ) ) ),
				$chave === $filtro ? 'current' : '',
				esc_html( $rotulo )
			);
		}
		echo implode( ' | ', $partes ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</ul>';

		echo '<table class="widefat striped leticia-tabela"><thead><tr><th>Empresa</th><th>Responsável</th><th>WhatsApp</th><th>Situação</th><th>Atualizado</th></tr></thead><tbody>';

		if ( ! $linhas ) {
			echo '<tr><td colspan="5">Nada por aqui.</td></tr>';
		}

		foreach ( $linhas as $b ) {
			$responsavel = isset( $b['respostas']['responsavel']['valor'] ) ? $b['respostas']['responsavel']['valor'] : '';
			// A última vez que o cliente mexeu — não a última gravação da
			// linha, que o lembrete e a entrega também fazem.
			$quando = Leticia_Registro::ultima_atividade( $b );
			printf(
				'<tr><td><a href="%s"><strong>%s</strong></a></td><td>%s</td><td>%s%s</td><td>%s</td><td title="%s">%s</td></tr>',
				esc_url( Leticia_Admin::url( array( 'briefing' => $b['sessao'] ) ) ),
				esc_html( '' !== $b['empresa'] ? $b['empresa'] : '(sem nome ainda)' ),
				esc_html( $responsavel ),
				esc_html( $b['whatsapp'] ),
				self::chamar( $b, true ), // phpcs:ignore WordPress.Security.EscapeOutput -- montado com esc_*
				self::situacao( $b ), // phpcs:ignore WordPress.Security.EscapeOutput -- montado com esc_*
				esc_attr( Leticia_Admin::data( $quando ) ),
				esc_html( Leticia_Admin::ha_quanto( $quando ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * "Chamar no WhatsApp": para quem parou no meio.
	 *
	 * Abre o WhatsApp da equipe com a conversa do cliente e a mensagem pronta,
	 * já com o link para continuar de onde parou. A equipe só confere e envia.
	 * O link é gerado agora e vale 30 dias.
	 */
	public static function chamar( array $b, $pequeno = false ) {
		if ( (int) $b['enviado_em'] > 0 || (int) $b['respondidos'] < 1 ) {
			return '';
		}
		// Link da equipe que o cliente nem abriu: a mensagem é a do convite,
		// não a de "você parou no meio".
		$url = Leticia_Links::nao_aberto( $b )
			? Leticia_Links::link_whatsapp( (array) $b['respostas'], Leticia_Retomada::link( $b['sessao'], (string) $b['pagina'] ) )
			: Leticia_Retomada::link_whatsapp_equipe( $b );
		if ( '' === $url ) {
			return '';
		}
		return sprintf(
			'%s<a class="button%s" href="%s" target="_blank" rel="noopener">Chamar no WhatsApp</a>',
			$pequeno ? '<br>' : '',
			$pequeno ? ' button-small' : ' button-primary',
			esc_url( $url )
		);
	}

	/** A situação em uma etiqueta: é o que se lê primeiro numa lista. */
	public static function situacao( array $b ) {
		$etiquetas = array();

		if ( (int) $b['enviado_em'] > 0 ) {
			$etiquetas[] = empty( $b['entregue'] )
				? '<span class="leticia-etiqueta e-ruim">Enviado · e-mail não saiu</span>'
				: '<span class="leticia-etiqueta e-bom">Enviado</span>';
		} elseif ( Leticia_Links::nao_aberto( $b ) ) {
			$etiquetas[] = '<span class="leticia-etiqueta e-neutro">Link enviado · ainda não aberto</span>';
		} else {
			$campo       = Leticia_Campos::por_chave( $b['campo_parado'] );
			$etiquetas[] = sprintf(
				'<span class="leticia-etiqueta e-neutro">Parou em %s · %d de %d</span>',
				esc_html( $campo ? $campo['rotulo'] : 'revisão' ),
				(int) $b['respondidos'],
				(int) Leticia_Campos::total()
			);
			if ( ! empty( $b['roteiro']['lembrete'] ) ) {
				$etiquetas[] = '<span class="leticia-etiqueta e-neutro">' . esc_html( 'Lembrete por e-mail ' . Leticia_Admin::ha_quanto( (int) $b['roteiro']['lembrete'] ) ) . '</span>';
			}
		}

		foreach ( (array) $b['pendencias'] as $chave ) {
			$campo       = Leticia_Campos::por_chave( $chave );
			$etiquetas[] = '<span class="leticia-etiqueta e-atento">' . esc_html( ( $campo ? $campo['rotulo'] : $chave ) . ' pendente' ) . '</span>';
		}

		return implode( ' ', $etiquetas );
	}

	// ---------------------------------------------------------- detalhe

	public static function detalhe( $sessao ) {
		$b = Leticia_Registro::briefing( $sessao );

		printf( '<p><a href="%s">&larr; Todos os briefings</a></p>', esc_url( Leticia_Admin::url() ) );

		if ( ! $b ) {
			echo '<p>Esse briefing não existe mais. Abandonados saem depois de ' . (int) Leticia_Registro::RETENCAO_ABANDONADOS . ' dias.</p>';
			return;
		}

		$estado = Leticia_Roteiro::sanear( array( 'respostas' => $b['respostas'], 'enviado' => (int) $b['enviado_em'] > 0 ) );

		printf(
			'<h2 class="leticia-detalhe-titulo">%s</h2><p>%s</p>',
			esc_html( '' !== $b['empresa'] ? $b['empresa'] : '(sem nome ainda)' ),
			self::situacao( $b ) // phpcs:ignore WordPress.Security.EscapeOutput
		);

		echo '<p class="description">';
		printf( 'Começado em %s', esc_html( Leticia_Admin::data( $b['criado_em'] ) ) );
		if ( (int) $b['enviado_em'] > 0 ) {
			printf( ' · enviado em %s', esc_html( Leticia_Admin::data( $b['enviado_em'] ) ) );
		}
		if ( '' !== $b['pagina'] ) {
			printf( ' · pela página <a href="%1$s" target="_blank" rel="noopener">%1$s</a>', esc_url( $b['pagina'] ) );
		}
		echo '</p>';

		if ( (int) $b['enviado_em'] > 0 && empty( $b['entregue'] ) ) {
			echo '<div class="leticia-aviso e-ruim"><strong>O e-mail deste briefing não saiu.</strong><span>' . self::botao_reenviar( $sessao ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		// Parado no meio: o jeito de trazer de volta. O botão abre o WhatsApp
		// com a mensagem pronta; o link é para quem preferir outro canal.
		if ( (int) $b['enviado_em'] < 1 && (int) $b['respondidos'] > 0 ) {
			$chamar = self::chamar( $b );
			printf(
				'<div class="leticia-aviso e-atento"><strong>Parou no meio.</strong><span>O cliente continua de onde parou por este link (vale 30 dias a partir de agora):</span>'
					. '<input type="text" readonly class="large-text code leticia-copiar" value="%s" onclick="this.select()">%s</div>',
				esc_attr( Leticia_Retomada::link( $sessao, (string) $b['pagina'] ) ),
				'' !== $chamar ? '<span>' . $chamar . '</span>' : '' // phpcs:ignore WordPress.Security.EscapeOutput -- montado com esc_*
			);
		}

		$link = (int) $b['enviado_em'] > 0 ? Leticia_Entrega::link_anexo( $sessao, $estado, $b['pagina'] ) : '';
		if ( '' !== $link ) {
			printf(
				'<div class="leticia-aviso e-atento"><strong>Ficou arquivo para depois.</strong><span>O cliente recebeu um link para mandar. Se perdeu, este é o dele (vale 30 dias a partir de agora):</span>'
					. '<input type="text" readonly class="large-text code leticia-copiar" value="%s" onclick="this.select()"></div>',
				esc_attr( $link )
			);
		}

		self::respostas( $b );
		self::conversa( $sessao );
	}

	private static function respostas( array $b ) {
		echo '<h3>Respostas</h3><table class="widefat striped leticia-tabela leticia-respostas"><tbody>';

		foreach ( Leticia_Campos::todos() as $campo ) {
			$chave = $campo['chave'];
			$r     = isset( $b['respostas'][ $chave ] ) ? $b['respostas'][ $chave ] : null;

			if ( ! $r ) {
				$valor = '<span class="leticia-vazio">ainda não respondido</span>';
			} elseif ( ! empty( $r['pendente'] ) ) {
				$valor = '<span class="leticia-etiqueta e-atento">pendente</span>'
					. ( 'dominio' === $chave ? ' ainda não tem — precisa registrar' : ' vai mandar depois' );
			} elseif ( ! empty( $r['pulado'] ) ) {
				$valor = '<span class="leticia-vazio">pulou</span>';
			} elseif ( 'arquivo' === $campo['tipo'] ) {
				$valor = self::arquivos( $r );
			} elseif ( 'escolha' === $campo['tipo'] ) {
				$valor = esc_html( $r['valor'] );
				foreach ( $campo['opcoes'] as $opcao ) {
					if ( $opcao['valor'] === $r['valor'] ) {
						$valor = esc_html( $opcao['texto'] );
					}
				}
			} else {
				$valor = nl2br( esc_html( $r['valor'] ) );
			}

			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $campo['rotulo'] ), $valor ); // phpcs:ignore WordPress.Security.EscapeOutput

			if ( $r && ! empty( $r['texto_site'] ) && trim( $r['texto_site'] ) === trim( $r['valor'] ) ) {
				echo '<tr><th scope="row"></th><td><span class="leticia-etiqueta e-atento">escrito pela LetícIA a pedido do cliente</span></td></tr>';
			} elseif ( $r && ! empty( $r['texto_site'] ) ) {
				printf( '<tr><th scope="row">↳ Texto para o site</th><td>%s <span class="leticia-etiqueta e-bom">aprovado pelo cliente</span></td></tr>', nl2br( esc_html( $r['texto_site'] ) ) );
			}
		}

		echo '</tbody></table>';
	}

	private static function arquivos( array $r ) {
		$partes = array();
		foreach ( (array) $r['arquivos'] as $arquivo ) {
			if ( empty( $arquivo['id'] ) ) {
				continue;
			}
			$tamanho = esc_html( size_format( isset( $arquivo['tamanho'] ) ? (int) $arquivo['tamanho'] : 0 ) );
			// Entregue, o arquivo já saiu do servidor e está no e-mail. Antes
			// disso — rascunho, ou e-mail que não saiu — ainda dá para baixar.
			$partes[] = Leticia_Arquivos::existe( $arquivo )
				? sprintf( '<a href="%s">%s</a> <span class="description">%s</span>', esc_url( Leticia_Entrega::link( $arquivo ) ), esc_html( $arquivo['nome'] ), $tamanho )
				: sprintf( '%s <span class="description">%s · foi no e-mail</span>', esc_html( $arquivo['nome'] ), $tamanho );
		}
		if ( ! empty( $r['link'] ) ) {
			$partes[] = sprintf( 'pasta: <a href="%1$s" target="_blank" rel="noopener noreferrer">%1$s</a>', esc_url( $r['link'] ) );
		}
		return $partes ? implode( '<br>', $partes ) : '<span class="leticia-vazio">nenhum arquivo</span>';
	}

	/**
	 * A conversa: o que a pessoa escreveu em cada campo, e o que aconteceu.
	 *
	 * É a leitura que explica os números de cima. "Dúvidas em domínio: 12" diz
	 * que tem problema; as doze perguntas dizem qual.
	 */
	private static function conversa( $sessao ) {
		$turnos = Leticia_Registro::conversa( $sessao );

		echo '<h3>Conversa</h3>';

		if ( ! $turnos ) {
			echo '<p class="description">Sem conversa guardada. Ela sai depois de ' . (int) Leticia_Registro::RETENCAO_TURNOS . ' dias, e campo de botão ou arquivo não gera turno.</p>';
			return;
		}

		$tipos = array(
			'duvida'         => array( 'atento', 'dúvida' ),
			'fora_de_escopo' => array( 'atento', 'fora do escopo' ),
			'resposta'       => array( 'neutro', 'resposta' ),
		);

		echo '<table class="widefat striped leticia-tabela"><thead><tr><th>Campo</th><th>O que a pessoa escreveu</th><th>Como foi</th></tr></thead><tbody>';
		foreach ( $turnos as $t ) {
			$campo = Leticia_Campos::por_chave( $t['campo'] );

			$notas = array();
			if ( isset( $tipos[ $t['tipo'] ] ) ) {
				$notas[] = sprintf( '<span class="leticia-etiqueta e-%s">%s</span>', esc_attr( $tipos[ $t['tipo'] ][0] ), esc_html( $tipos[ $t['tipo'] ][1] ) );
			}
			if ( ! (int) $t['suficiente'] ) {
				$notas[] = '<span class="leticia-etiqueta e-neutro">pediu mais detalhe</span>';
			}
			if ( '' !== (string) $t['bloqueio'] ) {
				$notas[] = '<span class="leticia-etiqueta e-ruim">trava: ' . esc_html( $t['bloqueio'] ) . '</span>';
			}
			if ( (int) $t['degradado'] ) {
				$notas[] = '<span class="leticia-etiqueta e-neutro">sem IA</span>';
			}
			if ( '' === (string) $t['tipo'] && ! (int) $t['degradado'] ) {
				$notas[] = '<span class="description">não foi à IA</span>';
			}

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $campo ? $campo['rotulo'] : $t['campo'] ),
				nl2br( esc_html( $t['texto'] ) ),
				implode( ' ', $notas ) // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}
		echo '</tbody></table>';
	}
}
