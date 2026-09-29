<?php
/**
 * Configurações → LetícIA.
 *
 * Uma página, duas abas, na ordem das perguntas que alguém faz ao abri-la:
 *
 *   Briefings      chegou tudo na equipe? onde as pessoas param? que campo
 *                  confunde? — e cada briefing aberto, com arquivo e conversa
 *   Configuração   chave, cota, e-mail, aceite, pasta dos arquivos
 *
 * A faixa de atenção aparece nas duas, porque de onde quer que se entre é
 * preciso saber se tem briefing parado.
 *
 * **Quem vê o quê.** Briefings é trabalho de quem monta site: `edit_pages`, a
 * mesma permissão que baixa anexo. Configuração mexe em chave de API e no
 * destino dos e-mails: `manage_options`.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Admin {

	const GRUPO  = 'leticia_grupo';
	const PAGINA = 'leticia';

	/** Quem vê os briefings. A mesma permissão do download de anexo. */
	const VER = 'edit_pages';

	/** Quem mexe na configuração. */
	const CONFIGURAR = 'manage_options';

	const AVISO = 'leticia_aviso_painel';

	public static function iniciar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'registrar' ) );
		add_action( 'admin_post_leticia_testar', array( __CLASS__, 'testar' ) );
		add_action( 'admin_post_leticia_reenviar', array( __CLASS__, 'reenviar' ) );
	}

	public static function menu() {
		$tela = add_options_page( 'LetícIA', 'LetícIA', self::VER, self::PAGINA, array( __CLASS__, 'render' ) );
		add_action( 'load-' . $tela, array( __CLASS__, 'estilo' ) );
	}

	public static function estilo() {
		add_action(
			'admin_enqueue_scripts',
			function () {
				wp_enqueue_style( 'leticia-admin', LETICIA_URL . 'admin/leticia-admin.css', array(), LETICIA_VERSAO );
			}
		);
	}

	public static function registrar() {
		register_setting(
			self::GRUPO,
			Leticia_Config::OPCAO,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Leticia_Config', 'sanitizar' ),
				'default'           => Leticia_Config::padroes(),
			)
		);
	}

	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGINA ), $args ), admin_url( 'options-general.php' ) );
	}

	// ------------------------------------------------------------------ ações

	/**
	 * "Testar a chave": uma chamada de verdade, com a instrução mais curta que
	 * existe.
	 *
	 * Conta na cota do dia, e a tela diz isso. Um teste que não gasta nada é um
	 * teste que não passa pelo mesmo caminho do cliente.
	 */
	public static function testar() {
		self::exigir( self::CONFIGURAR, 'leticia_testar' );

		// Testa o principal e a reserva, um por um, fora da cadeia: "a chave
		// funciona" não diz nada sobre a reserva, e foi a reserva que morreu.
		$r = Leticia_Modelos::checar( false );

		if ( ! $r ) {
			self::avisar( 'ruim', 'Sem chave ou sem modelo configurado: não há o que testar.' );
		} else {
			$partes = array();
			$ruim   = false;
			$codigos = array_unique( wp_list_pluck( $r, 'codigo' ) );
			if ( array( 'chave' ) === array_values( $codigos ) ) {
				$resumo = Leticia_Config::resumo_chave();
				self::avisar(
					'ruim',
					sprintf( 'O Google recusou a chave (API key not valid) — o problema não é o modelo. A chave em uso tem %d caracteres e termina em "%s": compare com a sua. Nenhuma cota foi gasta.', (int) $resumo['tamanho'], $resumo['fim'] )
				);
				self::voltar( array( 'aba' => 'config' ) );
			}
			foreach ( $r as $modelo => $m ) {
				$partes[] = $m['ok']
					? sprintf( '%s (%s) respondeu em %s ms', $modelo, $m['papel'], number_format( $m['ms'], 0, ',', '.' ) )
					: sprintf( '%s (%s) falhou: %s', $modelo, $m['papel'], $m['mensagem'] );
				$ruim = $ruim || ! $m['ok'];
			}
			self::avisar( $ruim ? 'ruim' : 'bom', implode( ' · ', $partes ) . '. Cada modelo testado contou uma chamada na cota de hoje.' );
		}

		self::voltar( array( 'aba' => 'config' ) );
	}

	/** "Tentar entregar agora", para o briefing que a fila não conseguiu. */
	public static function reenviar() {
		self::exigir( self::VER, 'leticia_reenviar' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- conferido em exigir()
		$sessao = isset( $_POST['sessao'] ) ? sanitize_text_field( wp_unslash( $_POST['sessao'] ) ) : '';

		if ( ! Leticia_Rascunho::sessao_valida( $sessao ) ) {
			self::avisar( 'ruim', 'Esse briefing não existe.' );
			self::voltar();
		}

		$r = Leticia_Entrega::reenviar_agora( $sessao );

		if ( is_array( $r ) && $r['ok'] ) {
			self::avisar( 'bom', 'Entregue. O e-mail saiu para ' . implode( ', ', Leticia_Config::destino() ) . '.' );
		} elseif ( is_array( $r ) ) {
			self::avisar( 'ruim', 'Ainda não saiu: ' . $r['erro'] . '. Vale conferir o SMTP do site.' );
		} else {
			self::avisar( 'atento', 'Nada a reenviar: esse briefing já foi entregue ou ainda não foi enviado pelo cliente.' );
		}

		self::voltar( array( 'briefing' => $sessao ) );
	}

	private static function exigir( $permissao, $nonce ) {
		if ( ! current_user_can( $permissao ) ) {
			wp_die( esc_html( 'Sem permissão.' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	private static function avisar( $nivel, $texto ) {
		set_transient( self::AVISO, array( 'nivel' => $nivel, 'texto' => $texto ), 60 );
	}

	private static function voltar( array $args = array() ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	// ----------------------------------------------------------------- tela

	public static function render() {
		if ( ! current_user_can( self::VER ) ) {
			wp_die( esc_html( 'Sem permissão.' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- só leitura
		$aba      = isset( $_GET['aba'] ) && 'config' === $_GET['aba'] && current_user_can( self::CONFIGURAR ) ? 'config' : 'briefings';
		$briefing = isset( $_GET['briefing'] ) ? sanitize_text_field( wp_unslash( $_GET['briefing'] ) ) : '';
		$filtro   = isset( $_GET['filtro'] ) ? sanitize_key( wp_unslash( $_GET['filtro'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap leticia-admin">';
		self::cabecalho( $aba );

		if ( 'config' === $aba ) {
			self::configuracao();
		} elseif ( Leticia_Rascunho::sessao_valida( $briefing ) ) {
			Leticia_Painel::detalhe( $briefing );
		} else {
			Leticia_Painel::briefings( $filtro );
		}

		echo '</div>';
	}

	private static function cabecalho( $aba ) {
		echo '<h1 class="leticia-titulo">' . esc_html( Leticia_Config::nome() ) . '</h1>';

		echo '<nav class="nav-tab-wrapper wp-clearfix leticia-abas">';
		printf(
			'<a href="%s" class="nav-tab %s">Briefings</a>',
			esc_url( self::url() ),
			'briefings' === $aba ? 'nav-tab-active' : ''
		);
		if ( current_user_can( self::CONFIGURAR ) ) {
			printf(
				'<a href="%s" class="nav-tab %s">Configuração</a>',
				esc_url( self::url( array( 'aba' => 'config' ) ) ),
				'config' === $aba ? 'nav-tab-active' : ''
			);
		}
		echo '</nav>';

		$aviso = get_transient( self::AVISO );
		if ( is_array( $aviso ) ) {
			delete_transient( self::AVISO );
			printf( '<div class="leticia-aviso e-%s"><span>%s</span></div>', esc_attr( $aviso['nivel'] ), esc_html( $aviso['texto'] ) );
		}

		self::atencao();
	}

	/**
	 * Só o que exige decisão de alguém.
	 *
	 * Painel que avisa de tudo não é lido — e aí o aviso que importava passa
	 * junto com os outros. Sem nada a dizer, uma linha discreta.
	 */
	public static function atencao() {
		$itens = array();

		$parados = count( Leticia_Registro::nao_entregues() );
		if ( $parados > 0 ) {
			$itens[] = array(
				'ruim',
				1 === $parados ? '1 briefing não chegou na equipe' : $parados . ' briefings não chegaram na equipe',
				'O cliente enviou e o e-mail não saiu. <a href="' . esc_url( self::url() ) . '#nao-entregues">Ver e reenviar</a>',
			);
		}

		if ( ! Leticia_Config::esta_configurado() ) {
			$itens[] = array( 'atento', 'Sem chave da API', 'O briefing funciona e envia, mas sem a LetícIA comentar nem conduzir: é o modo formulário.' );
		} elseif ( ! Leticia_Config::esta_ativa() ) {
			$itens[] = array( 'atento', 'IA desligada', 'O interruptor está desligado: o briefing segue coletando, sem conversa.' );
		} elseif ( Leticia_Limites::disjuntor_aberto() ) {
			$itens[] = array( 'atento', 'Cota do dia esgotada', 'Até a virada do dia, os briefings seguem sem comentário da IA.' );
		}

		if ( Leticia_Config::pode_comentar() ) {
			$estado_modelos = Leticia_Modelos::estado();
			$chave_recusada = in_array( 'chave', wp_list_pluck( $estado_modelos, 'codigo' ), true );
			if ( $chave_recusada ) {
				$resumo  = Leticia_Config::resumo_chave();
				$itens[] = array(
					'ruim',
					'A chave da API foi recusada pelo Google',
					sprintf(
						'Nenhum modelo atende com ela, então os briefings seguem sem IA. A chave em uso vem do %s, tem %d caracteres e termina em <code>%s</code> — compare com a sua. No WordPress, a chave é a deste painel ou a do <code>wp-config.php</code>: o <code>.env</code> só vale para o ambiente local.',
						esc_html( $resumo['origem'] ),
						(int) $resumo['tamanho'],
						esc_html( $resumo['fim'] )
					),
				);
			}
			foreach ( $estado_modelos as $modelo => $m ) {
				if ( $chave_recusada && 'chave' === $m['codigo'] ) {
					continue;
				}
				if ( false === $m['checado_ok'] ) {
					$itens[] = array(
						'principal' === $m['papel'] ? 'ruim' : 'atento',
						sprintf( 'O modelo %s não respondeu na checagem', 'principal' === $m['papel'] ? 'principal' : 'de reserva' ),
						'<code>' . esc_html( $modelo ) . '</code>: ' . esc_html( $m['erro'] ) . ' Troque o modelo abaixo e use "Testar os modelos".',
					);
				} elseif ( $m['pausado_ate'] > 0 ) {
					$itens[] = array(
						'atento',
						sprintf( 'Modelo %s pausado', 'principal' === $m['papel'] ? 'principal' : 'de reserva' ),
						'<code>' . esc_html( $modelo ) . '</code>: ' . esc_html( $m['motivo'] ) . '. Volta sozinho ' . esc_html( self::ha_quanto_falta( $m['pausado_ate'] ) ) . '.',
					);
				}
			}
			if ( '' === Leticia_Config::modelo_reserva() ) {
				$itens[] = array( 'atento', 'Sem modelo de reserva', 'Se o principal cair ou estourar a cota, os briefings seguem sem comentário da IA até ele voltar.' );
			}
		}

		$pasta = self::situacao_pasta();
		if ( ! $pasta['gravavel'] ) {
			$itens[] = array( 'ruim', 'A pasta dos arquivos não aceita gravação', 'Logomarca e fotos não vão subir. Caminho: <code>' . esc_html( $pasta['caminho'] ) . '</code>' );
		}

		if ( ! Leticia_Base::existe() ) {
			$itens[] = array( 'atento', 'A base de textos sumiu', 'As perguntas aparecem só com o nome do campo. Confira <code>conhecimento/campos.md</code>.' );
		}

		if ( ! $itens ) {
			echo '<p class="leticia-tranquilo">Nada pedindo atenção agora.</p>';
			return;
		}

		echo '<div class="leticia-atencao">';
		foreach ( $itens as $i ) {
			printf(
				'<div class="leticia-aviso e-%s"><strong>%s</strong><span>%s</span></div>',
				esc_attr( $i[0] ),
				esc_html( $i[1] ),
				wp_kses_post( $i[2] )
			);
		}
		echo '</div>';
	}

	// --------------------------------------------------------- configuração

	private static function configuracao() {
		$c       = Leticia_Config::tudo();
		$travado = (array) apply_filters( 'leticia_config_travada', array() );
		$nome    = Leticia_Config::OPCAO;

		self::situacao();

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '" class="leticia-form">';
		settings_fields( self::GRUPO );

		// ---- inteligência
		echo '<h2>Inteligência</h2><table class="form-table" role="presentation">';

		self::linha(
			'Conversa com IA',
			sprintf(
				'<label><input type="checkbox" name="%s[ATIVA]" value="1" %s> Ligada</label>'
				. '<p class="description">Desligada, o briefing continua no ar e enviando — só sem comentário e sem condução.</p>',
				esc_attr( $nome ),
				checked( '1', (string) $c['ATIVA'], false )
			)
		);

		if ( Leticia_Config::chave_vem_de_constante() ) {
			$campo_chave = '<p><code>LETICIA_GEMINI_API_KEY</code> definida no <code>wp-config.php</code>. Para trocar, é lá.</p>' . self::resumo_chave();
		} else {
			// A chave salva nunca volta para a página. Em branco, fica a atual.
			$campo_chave = sprintf(
				'<input type="password" class="regular-text code" name="%s[GEMINI_API_KEY]" value="" autocomplete="off" placeholder="%s">'
				. '<p class="description">Em branco, mantém a chave atual. Use uma chave só da %s — dividir chave com outro produto divide a cota, e o dia movimentado de um derruba o outro.</p>',
				esc_attr( $nome ),
				esc_attr( '' !== trim( (string) $c['GEMINI_API_KEY'] ) ? 'uma chave já está salva' : 'cole a chave da API do Gemini' ),
				esc_html( Leticia_Config::nome() )
			) . self::resumo_chave();
		}
		self::linha( 'Chave da API', $campo_chave, 'GEMINI_API_KEY', $travado );

		self::linha( 'Modelo', self::texto( 'GEMINI_MODEL', $c, 'code' ), 'GEMINI_MODEL', $travado );
		self::linha(
			'Modelo reserva',
			self::texto( 'GEMINI_MODEL_RESERVA', $c, 'code' ) . '<p class="description">Opcional. Entra quando o principal estoura a cota ou some.</p>',
			'GEMINI_MODEL_RESERVA',
			$travado
		);

		$teto = Leticia_Config::teto_diario();
		self::linha(
			'Teto diário de chamadas',
			sprintf(
				'<input type="number" min="0" max="50000" class="small-text" name="%s[TETO_DIARIO]" value="%d">'
				. '<p class="description">%s</p>',
				esc_attr( $nome ),
				(int) $c['TETO_DIARIO'],
				$teto > 0
					? sprintf(
						'Cada briefing gasta perto de %d chamadas. Com %d, cabem uns <strong>%d briefings por dia</strong> conversando; depois disso, seguem sem IA até a virada do dia. <strong>0</strong> tira o teto.',
						self::CHAMADAS_POR_BRIEFING,
						$teto,
						(int) floor( $teto / self::CHAMADAS_POR_BRIEFING )
					)
					: sprintf( '<strong>Sem teto.</strong> Cada briefing gasta perto de %d chamadas, e a cobrança é a da conta do Gemini. O limite por pessoa e por sessão continua valendo.', self::CHAMADAS_POR_BRIEFING )
			),
			'TETO_DIARIO',
			$travado
		);

		self::linha(
			'Responder por voz',
			sprintf(
				'<label><input type="checkbox" name="%1$s[VOZ]" value="1" %2$s> Mostrar o microfone nos campos de texto</label>'
				. '<p><label>Até <input type="number" min="10" max="120" class="small-text" name="%1$s[VOZ_SEGUNDOS]" value="%3$d"> segundos por gravação</label></p>'
				. '<p class="description">O áudio vai ao mesmo modelo, que devolve o texto para o cliente conferir antes de gravar. Cada gravação é uma chamada, e o Gemini cobra <strong>32 tokens por segundo</strong> de áudio — %3$d s são até %4$s tokens de entrada. O áudio não é guardado. Sem a conversa ligada, o microfone não aparece.</p>',
				esc_attr( $nome ),
				checked( '1', (string) ( isset( $c['VOZ'] ) ? $c['VOZ'] : '1' ), false ),
				Leticia_Config::voz_segundos(),
				number_format( Leticia_Config::voz_segundos() * 32, 0, ',', '.' )
			),
			'VOZ',
			$travado
		);
		echo '</table>';

		// ---- entrega
		echo '<h2>Entrega</h2><table class="form-table" role="presentation">';
		self::linha( 'Quem dispara', self::texto( 'REMETENTE', $c ) . '<p class="description">O remetente do e-mail. Precisa ser uma caixa que o SMTP do site pode usar.</p>', 'REMETENTE', $travado );
		self::linha( 'Nome do remetente', self::texto( 'REMETENTE_NOME', $c ), 'REMETENTE_NOME', $travado );
		self::linha(
			'Quem recebe',
			sprintf(
				'<textarea name="%s[DESTINO]" rows="4" class="large-text code">%s</textarea><p class="description">Um e-mail por linha.</p>',
				esc_attr( $nome ),
				esc_textarea( (string) $c['DESTINO'] )
			),
			'DESTINO',
			$travado
		);
		self::linha(
			'Assunto',
			self::texto( 'ASSUNTO', $c, 'large-text' ) . '<p class="description"><code>[DOMINIO]</code> vira o domínio do cliente; <code>[EMPRESA]</code>, o nome da empresa.</p>',
			'ASSUNTO',
			$travado
		);
		self::linha(
			'Assunto da continuação',
			self::texto( 'ASSUNTO_CONTINUACAO', $c, 'large-text' ) . '<p class="description">Quando o cliente manda pelo link o que tinha ficado para depois. Mesmas marcações do assunto.</p>',
			'ASSUNTO_CONTINUACAO',
			$travado
		);
		echo '</table>';

		// ---- briefing
		echo '<h2>Briefing</h2><table class="form-table" role="presentation">';
		self::linha(
			'Texto do aceite',
			sprintf(
				'<input type="text" class="large-text" name="%s[CONSENTIMENTO]" value="%s" placeholder="%s">'
				. '<p class="description">O que o cliente marca antes de enviar. Em branco, vale o texto padrão, que já menciona a IA.</p>',
				esc_attr( $nome ),
				esc_attr( (string) $c['CONSENTIMENTO'] ),
				esc_attr( Leticia_Base::texto( 'consentimento' ) )
			),
			'CONSENTIMENTO',
			$travado
		);
		self::linha(
			'Aviso sobre IA',
			sprintf(
				'<textarea class="large-text" rows="3" name="%s[AVISO_IA]" placeholder="%s">%s</textarea>'
				. '<p class="description">Aparece embaixo da conversa enquanto a IA está ligada. <code>{assistente}</code> vira o nome da assistente. Em branco, vale o texto padrão.</p>',
				esc_attr( $nome ),
				esc_attr( Leticia_Base::texto( 'aviso-ia' ) ),
				esc_textarea( (string) ( isset( $c['AVISO_IA'] ) ? $c['AVISO_IA'] : '' ) )
			),
			'AVISO_IA',
			$travado
		);
		self::linha(
			'Página para mandar arquivo depois',
			self::texto( 'LINK_ANEXO_DEPOIS', $c, 'large-text code', 'url' )
				. '<p class="description">Onde abre o link que o cliente recebe quando deixa um arquivo para depois. Precisa ter o shortcode <code>[leticia]</code>. Em branco, é a própria página em que o briefing foi preenchido.</p>',
			'LINK_ANEXO_DEPOIS',
			$travado
		);
		self::linha(
			'Formulário clássico',
			self::texto( 'FORMULARIO_CLASSICO', $c, 'large-text code', 'url' ) . '<p class="description">A saída que aparece quando a tela não consegue falar com o servidor.</p>',
			'FORMULARIO_CLASSICO',
			$travado
		);
		self::linha(
			'Anexos por e-mail',
			sprintf(
				'<input type="number" min="1" max="50" class="small-text" name="%s[TETO_EMAIL_MB]" value="%d"> MB'
				. '<p class="description">Os arquivos vão anexados e saem do servidor depois da entrega. Este é o máximo de anexo em cada e-mail — e, por isso, de cada arquivo. O que não couber num e-mail vai no seguinte. Acima de 15 MB, Gmail e Outlook começam a recusar.</p>',
				esc_attr( $nome ),
				(int) Leticia_Config::teto_email() / 1048576
			),
			'TETO_EMAIL_MB',
			$travado
		);
		self::linha(
			'Lembrete por e-mail',
			sprintf(
				'<label><input type="checkbox" name="%s[LEMBRETE]" value="1" %s> Lembrar quem parou no meio</label>'
				. '<p class="description">Um e-mail só, com o link para continuar de onde parou, para quem deixou e-mail no briefing e está parado há mais de um dia (até uma semana). Se a pessoa voltar e parar de novo, recebe outro.</p>',
				esc_attr( $nome ),
				checked( '1', (string) ( isset( $c['LEMBRETE'] ) ? $c['LEMBRETE'] : '1' ), false )
			),
			'LEMBRETE',
			$travado
		);
		self::linha(
			'Ao excluir o plugin',
			sprintf(
				'<label><input type="checkbox" name="%s[APAGAR_AO_DESINSTALAR]" value="1" %s> Apagar também os briefings e as conversas</label>'
				. '<p class="description">Excluir o plugin (em Plugins → Excluir) sempre remove a configuração, os agendamentos e os arquivos temporários. Desativar não remove nada. '
				. 'Sem esta caixa, os briefings ficam no banco — bom para reinstalar sem perder o histórico, mas eles contêm nome, WhatsApp e e-mail de clientes, e a limpeza automática dos abandonados deixa de rodar.</p>',
				esc_attr( $nome ),
				checked( '1', (string) ( isset( $c['APAGAR_AO_DESINSTALAR'] ) ? $c['APAGAR_AO_DESINSTALAR'] : '0' ), false )
			),
			'APAGAR_AO_DESINSTALAR',
			$travado
		);
		echo '</table>';

		self::tabela_de_campos();

		// ---- aparência
		echo '<h2>Aparência</h2><table class="form-table" role="presentation">';
		self::linha( 'Nome da assistente', self::texto( 'NOME', $c ), 'NOME', $travado );
		self::linha(
			'Cor de destaque',
			sprintf( '<input type="text" class="small-text code" name="%s[COR]" value="%s" pattern="#[0-9A-Fa-f]{3,6}">', esc_attr( $nome ), esc_attr( Leticia_Config::cor() ) ),
			'COR',
			$travado
		);
		echo '</table>';

		submit_button( 'Salvar configuração' );
		echo '</form>';

		// Formulário à parte: botão de ação não pode viajar junto com o salvar.
		printf(
			'<form method="post" action="%s" class="leticia-testar"><input type="hidden" name="action" value="leticia_testar">%s'
				. '<button type="submit" class="button">Testar os modelos</button>'
				. ' <span class="description">Uma chamada de verdade no principal e outra na reserva — contam na cota de hoje. Salve antes de testar uma troca. O teste também roda sozinho uma vez por dia.</span></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'leticia_testar', '_wpnonce', true, false )
		);
	}

	/**
	 * Os campos do briefing: quais são obrigatórios, e quais arquivos podem
	 * ficar para depois.
	 *
	 * "Pode mandar depois" é o que faz aparecer o botão "Não estou com o
	 * arquivo agora" e gera o link de envio posterior. Obrigatório sem "depois"
	 * trava o briefing até o arquivo subir — a tela avisa quando é isso.
	 */
	private static function tabela_de_campos() {
		$nome    = Leticia_Config::OPCAO;
		$padrao  = array();
		foreach ( Leticia_Campos::estrutura() as $campo ) {
			$padrao[ $campo['chave'] ] = $campo;
		}

		echo '<h2>Campos do briefing</h2>';
		echo '<p class="description">A ordem e as perguntas não mudam aqui. <strong>Obrigatório</strong>: o cliente não consegue pular. <strong>Pode mandar depois</strong> (só arquivos): aparece o botão "Não estou com o arquivo agora", o briefing segue, e o cliente recebe um link para mandar o arquivo depois.</p>';
		printf( '<input type="hidden" name="%s[CAMPOS_ENVIADO]" value="1">', esc_attr( $nome ) );

		echo '<table class="widefat striped leticia-tabela leticia-campos"><thead><tr><th>Campo</th><th>Etapa</th><th>Obrigatório</th><th>Pode mandar depois</th><th></th></tr></thead><tbody>';

		foreach ( Leticia_Campos::todos() as $campo ) {
			$chave    = $campo['chave'];
			$original = $padrao[ $chave ];
			$arquivo  = 'arquivo' === $campo['tipo'];
			$depois   = $arquivo && ! empty( $campo['pode_ficar_pendente'] );

			$mudou = (bool) $campo['obrigatorio'] !== (bool) $original['obrigatorio']
				|| ( $arquivo && $depois !== ! empty( $original['pode_ficar_pendente'] ) );

			$nota = '';
			if ( $arquivo && $campo['obrigatorio'] && ! $depois ) {
				$nota = '<span class="leticia-etiqueta e-atento">sem o arquivo, não envia</span>';
			} elseif ( $mudou ) {
				$nota = '<span class="leticia-etiqueta e-neutro">diferente do padrão</span>';
			}

			printf(
				'<tr><td><strong>%s</strong></td><td>%s</td>'
					. '<td><label><input type="checkbox" name="%s[CAMPOS][%s][obrigatorio]" value="1" %s> Obrigatório</label></td>'
					. '<td>%s</td><td>%s</td></tr>',
				esc_html( $campo['rotulo'] ),
				esc_html( Leticia_Campos::SECOES[ $campo['secao'] ] ),
				esc_attr( $nome ),
				esc_attr( $chave ),
				checked( true, (bool) $campo['obrigatorio'], false ),
				$arquivo
					? sprintf(
						'<label><input type="checkbox" name="%s[CAMPOS][%s][depois]" value="1" %s> Com link</label>',
						esc_attr( $nome ),
						esc_attr( $chave ),
						checked( true, $depois, false )
					)
					: '<span class="description">—</span>',
				$nota // phpcs:ignore WordPress.Security.EscapeOutput -- etiqueta fixa
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Estimativa usada no texto da cota: ramo e serviços sempre, mais
	 * reperguntas, dúvidas e o que a economia não poupou. Eram 8 quando os seis
	 * campos que comentam chamavam sempre.
	 */
	const CHAMADAS_POR_BRIEFING = 4;

	/** O bloco de situação no topo da configuração: cota, pasta e base. */
	/**
	 * A chave salva, sem a chave: tamanho e os últimos quatro caracteres, para
	 * comparar com a que a pessoa tem. E o aviso quando não tem cara de chave.
	 */
	private static function resumo_chave() {
		$r = Leticia_Config::resumo_chave();
		if ( 0 === $r['tamanho'] ) {
			return '';
		}
		$texto = sprintf( 'Em uso: %d caracteres, termina em <code>%s</code>.', (int) $r['tamanho'], esc_html( $r['fim'] ) );
		if ( ! $r['formato_ok'] ) {
			$texto .= ' <strong>Não parece uma chave do Gemini</strong>: tem caractere que chave não tem, ou é curta demais. Confira se não foi colado algo a mais ou a menos.';
		}
		return '<p class="description">' . $texto . '</p>';
	}

	/** Quantas chamadas a economia evitou hoje — respostas que a reação escrita resolveu. */
	private static function nota_poupadas() {
		$poupadas = Leticia_Limites::poupadas_hoje();
		return $poupadas > 0 ? '<br>' . sprintf( 1 === $poupadas ? '%d chamada poupada.' : '%d chamadas poupadas.', $poupadas ) : '';
	}

	/** "em 3 minutos", "em 2 horas": quanto falta para uma pausa acabar. */
	private static function ha_quanto_falta( $ate ) {
		$s = max( 0, (int) $ate - time() );
		if ( $s < 90 ) {
			return 'em ' . max( 1, $s ) . ' segundos';
		}
		if ( $s < 5400 ) {
			return 'em ' . (int) round( $s / 60 ) . ' minutos';
		}
		return 'em ' . (int) round( $s / 3600 ) . ' horas';
	}

	private static function situacao() {
		$teto    = Leticia_Config::teto_diario();
		$usadas  = Leticia_Limites::usadas_hoje();
		$pasta   = self::situacao_pasta();
		$base    = Leticia_Base::info();
		$percent = $teto > 0 ? min( 100, (int) round( $usadas / $teto * 100 ) ) : 0;

		echo '<div class="leticia-cartoes">';

		self::cartao(
			Leticia_Config::pode_comentar() ? 'bom' : 'atento',
			'Conversa',
			Leticia_Config::pode_comentar() ? 'Com IA' : 'Só coletando',
			Leticia_Config::pode_comentar()
				? 'Modelo <code>' . esc_html( Leticia_Config::modelo() ) . '</code>'
				: 'O briefing envia normalmente, sem comentário.'
		);

		if ( $teto > 0 ) {
			self::cartao(
				$percent >= 90 ? 'ruim' : ( $percent >= 60 ? 'atento' : 'bom' ),
				'Chamadas hoje',
				$usadas . ' / ' . $teto,
				sprintf( '<span class="leticia-barra"><span style="width:%d%%"></span></span>Zera na virada do dia (UTC).', $percent ) . self::nota_poupadas()
			);
		} else {
			self::cartao( 'neutro', 'Chamadas hoje', (string) $usadas, 'Sem teto diário. Uns ' . (int) round( $usadas / self::CHAMADAS_POR_BRIEFING ) . ' briefings conversados hoje.' . self::nota_poupadas() );
		}

		// Os arquivos não moram no servidor: a pasta é só onde eles esperam o
		// e-mail sair. O que importa aqui é se o upload consegue gravar.
		$nota_pasta = sprintf( 'Vão anexados no e-mail (até %s por e-mail) e saem do servidor depois da entrega. Enquanto esperam: ', esc_html( size_format( Leticia_Config::teto_email() ) ) )
			. '<code>' . esc_html( $pasta['caminho'] ) . '</code>';
		if ( $pasta['publica'] && ! $pasta['protegida'] ) {
			$nota_pasta .= '<br>A pasta fica dentro da parte pública do site e ainda não foi fechada — o primeiro upload fecha.';
		}
		self::cartao(
			$pasta['gravavel'] ? 'bom' : 'ruim',
			'Arquivos',
			$pasta['gravavel'] ? 'Por e-mail' : 'Upload não grava',
			$nota_pasta,
			true
		);

		self::cartao(
			$base['ok'] ? 'bom' : 'ruim',
			'Base de textos',
			$base['ok'] ? 'Carregada' : 'Ausente',
			$base['ok']
				? 'Editada em ' . esc_html( self::data( $base['modificado'] ) ) . '.'
				: '<code>' . esc_html( $base['caminho'] ) . '</code>',
			true
		);

		echo '</div>';
	}

	/**
	 * Onde os arquivos de cliente estão, e se o servidor consegue gravar lá.
	 *
	 * @return array
	 */
	public static function situacao_pasta() {
		$caminho = Leticia_Arquivos::pasta_base();
		$existe  = '' !== $caminho && is_dir( $caminho );
		$raiz    = defined( 'ABSPATH' ) ? rtrim( str_replace( '\\', '/', (string) realpath( ABSPATH ) ), '/' ) : '';
		$real    = $existe ? str_replace( '\\', '/', (string) realpath( $caminho ) ) : str_replace( '\\', '/', $caminho );

		return array(
			'caminho'      => $caminho,
			'do_wp_config' => '' !== Leticia_Config::pasta_definida(),
			'existe'       => $existe,
			// Pasta que ainda não existe é gravável se o pai for: ela nasce no
			// primeiro upload.
			'gravavel'     => $existe ? is_writable( $caminho ) : ( '' !== $caminho && is_writable( dirname( $caminho ) ) ),
			'protegida'    => $existe && file_exists( trailingslashit( $caminho ) . '.htaccess' ),
			'publica'      => '' !== $raiz && 0 === strpos( $real, $raiz . '/' ),
			'livre'        => $existe && function_exists( 'disk_free_space' ) ? @disk_free_space( $caminho ) : null, // phpcs:ignore WordPress.PHP.NoSilencedErrors
		);
	}

	// ------------------------------------------------------------ pedaços

	private static function linha( $rotulo, $html, $chave = '', array $travado = array() ) {
		if ( '' !== $chave && in_array( $chave, $travado, true ) ) {
			// Valor vindo de fora (o .env do ambiente local): editar aqui não
			// teria efeito, então a tela diz isso em vez de fingir que salvou.
			$html = '<fieldset disabled class="leticia-travado">' . $html . '</fieldset><p class="description">Definido fora do painel (<code>.env</code>). Edite lá.</p>';
		}
		printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $rotulo ), $html ); // phpcs:ignore WordPress.Security.EscapeOutput -- montado com esc_* acima
	}

	private static function texto( $chave, array $c, $classe = 'regular-text', $tipo = 'text' ) {
		return sprintf(
			'<input type="%s" class="%s" name="%s[%s]" value="%s">',
			esc_attr( $tipo ),
			esc_attr( $classe ),
			esc_attr( Leticia_Config::OPCAO ),
			esc_attr( $chave ),
			esc_attr( (string) $c[ $chave ] )
		);
	}

	public static function cartao( $estado, $rotulo, $valor, $nota = '', $menor = false ) {
		printf(
			'<div class="leticia-cartao e-%s"><span class="rotulo">%s</span><span class="valor%s">%s</span>%s</div>',
			esc_attr( $estado ),
			esc_html( $rotulo ),
			$menor ? ' menor' : '',
			esc_html( $valor ),
			$nota ? '<span class="nota">' . wp_kses_post( $nota ) . '</span>' : ''
		);
	}

	/** Data no fuso do site, quando houver WordPress. */
	public static function data( $quando, $formato = 'd/m/Y H:i' ) {
		$quando = (int) $quando;
		if ( $quando < 1 ) {
			return '—';
		}
		return function_exists( 'wp_date' ) ? wp_date( $formato, $quando ) : gmdate( $formato, $quando );
	}

	/** "há 3 horas", sem a precisão que ninguém pediu. */
	public static function ha_quanto( $quando ) {
		$s = max( 0, time() - (int) $quando );
		if ( $s < 3600 ) {
			$m = max( 1, (int) round( $s / 60 ) );
			return 'há ' . $m . ( 1 === $m ? ' minuto' : ' minutos' );
		}
		if ( $s < 86400 ) {
			$h = (int) round( $s / 3600 );
			return 'há ' . $h . ( 1 === $h ? ' hora' : ' horas' );
		}
		$d = (int) round( $s / 86400 );
		return 'há ' . $d . ( 1 === $d ? ' dia' : ' dias' );
	}
}
