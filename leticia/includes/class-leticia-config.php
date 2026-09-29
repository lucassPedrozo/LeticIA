<?php
/**
 * Configuração da LetícIA.
 *
 * Mesma forma da tela da LivIA, com uma diferença que não é detalhe: **a chave
 * da API é outra**. A LetícIA gasta cerca de dez chamadas por briefing; a LivIA,
 * de três a seis por atendimento. Dividindo a mesma chave, um dia movimentado
 * de uma derruba a outra — e quem cai primeiro é sempre a que o cliente estava
 * usando naquela hora.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Config {

	const OPCAO = 'leticia_config';
	const DIAG  = 'leticia_diagnostico';

	/** Definindo no wp-config.php, a chave nunca toca o banco. */
	const CONSTANTE_CHAVE = 'LETICIA_GEMINI_API_KEY';

	/**
	 * A pasta dos arquivos também vem do wp-config.php, e só dele.
	 *
	 * Não é campo da tela de propósito: escolher onde o servidor grava arquivo
	 * de cliente é decisão de infraestrutura, e um campo de texto no wp-admin
	 * que aceita caminho de disco é um convite a apontar para dentro da raiz
	 * pública sem perceber.
	 */
	const CONSTANTE_PASTA = 'LETICIA_PASTA_ARQUIVOS';

	public static function padroes() {
		return array(
			'GEMINI_API_KEY'       => '',
			'GEMINI_MODEL'         => 'gemini-3.5-flash-lite',
			'GEMINI_MODEL_RESERVA' => '',

			// Desligada, a LetícIA não some: ela vira formulário guiado sem
			// comentário e continua enviando. É o modo degradado, e ele é
			// requisito — não é uma avaria que se tolera.
			'ATIVA'                => '1',

			// Chamadas por dia antes de o disjuntor abrir. 0 é sem teto — o
			// padrão por enquanto, por decisão da equipe. Com teto, cada
			// briefing gasta perto de 8 chamadas.
			'TETO_DIARIO'          => '0',

			// Responder falando. Só aparece com a conversa ligada: é o mesmo
			// modelo que ouve, e sem ele não há quem transcreva.
			'VOZ'                  => '1',

			// O teto de uma gravação. É ele, e não a compressão, que segura o
			// gasto: o Gemini cobra 32 tokens por segundo de áudio seja qual for
			// o formato — 60 s são 1.920 tokens de entrada.
			'VOZ_SEGUNDOS'         => '60',

			// Quem dispara. Em branco, o wp_mail usa o remetente do próprio
			// site. O endereço da equipe fica na aba Configuração, e não aqui:
			// este código é público, e caixa de e-mail em código aberto vira
			// endereço de robô de spam em uma semana.
			'REMETENTE'            => '',
			'REMETENTE_NOME'       => 'Formulário do site',

			// Quem recebe. Um por linha, também pela aba Configuração. Em
			// branco vale o administrador do site — ver destino().
			'DESTINO'              => '',

			// O prefixo é byte a byte o do e-mail de hoje: filtro e marcador
			// que a equipe tenha no Gmail continuam pegando.
			'ASSUNTO'              => '[SITE EXPRESS] Novo briefing - [DOMINIO]',

			// O assunto de quando o cliente manda, pelo link, o que tinha ficado
			// para depois.
			'ASSUNTO_CONTINUACAO'  => '[CONTINUAÇÃO] - [DOMINIO]',

			// Obrigatoriedade por campo, e quais arquivos podem ficar para
			// depois (com link). Vazio vale o padrão de Leticia_Campos.
			//   array( 'logo' => array( 'obrigatorio' => '1', 'depois' => '0' ), ... )
			'CAMPOS'               => array(),

			// A página onde abre o link de "mandar a logo depois". Vazio, é a
			// própria página do briefing — que já tem o shortcode e é o lugar
			// mais óbvio para a pessoa cair.
			'LINK_ANEXO_DEPOIS'    => '',

			// O texto do aceite. Vazio, vale o da base (`campos.md`), que é o do
			// formulário no ar. Está aqui porque é texto jurídico: quem muda é a
			// equipe, sem depender de alguém editar arquivo do plugin.
			'CONSENTIMENTO'        => '',

			// O aviso de que as respostas passam por IA, embaixo da conversa.
			// Mesma lógica do aceite: vazio vale o da base.
			'AVISO_IA'             => '',

			// Os arquivos vão anexados no e-mail e saem do servidor depois da
			// entrega — não há pasta permanente. Este é o teto de anexo por
			// e-mail, e por isso também o teto de cada arquivo. 15 MB porque o
			// base64 do anexo cresce um terço, e o Gmail recusa mensagem acima
			// de 25 MB. O que não cabe num e-mail vai no seguinte.
			'TETO_EMAIL_MB'        => '15',

			// Ao excluir o plugin, apagar também as tabelas de briefings. Padrão
			// não: excluir para reinstalar uma versão nova não pode levar o
			// registro do que os clientes mandaram.
			'APAGAR_AO_DESINSTALAR' => '0',

			// Um e-mail, uma vez por parada, para quem começou o briefing,
			// deixou e-mail e parou entre um dia e uma semana atrás.
			'LEMBRETE'             => '1',

			// Aparência.
			'NOME'                 => 'LetícIA',
			'COR'                  => '#1f5eff',

			// O formulário de sempre, para quando nada mais funcionar. A
			// LetícIA nunca é o único caminho.
			'FORMULARIO_CLASSICO'  => 'https://formularios.joinvix.com.br/site-express/',
		);
	}

	public static function tudo() {
		$salvo = get_option( self::OPCAO, array() );
		if ( ! is_array( $salvo ) ) {
			$salvo = array();
		}
		return array_merge( self::padroes(), $salvo );
	}

	public static function api_key() {
		if ( self::chave_vem_de_constante() ) {
			return self::limpar_chave( constant( self::CONSTANTE_CHAVE ) );
		}
		$tudo = self::tudo();
		return self::limpar_chave( $tudo['GEMINI_API_KEY'] );
	}

	/**
	 * A chave como o Google espera: sem nada em volta.
	 *
	 * O erro mais comum ao passar a chave do .env para o painel é levar junto o
	 * que não é chave — a linha inteira (`LETICIA_GEMINI_API_KEY=...`), as
	 * aspas, um espaço ou um caractere invisível do copiar e colar. O Google
	 * responde só "API key not valid", e ninguém enxerga a diferença olhando.
	 */
	public static function limpar_chave( $bruto ) {
		$chave = (string) $bruto;
		$chave = preg_replace( '/^\s*(?:export\s+)?(?:LETICIA_)?GEMINI_API_KEY\s*[=:]\s*/i', '', $chave );
		$chave = preg_replace( '/[\s\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u', '', $chave );
		return trim( $chave, "\"'`" );
	}

	/**
	 * O que dá para mostrar da chave sem mostrar a chave: de onde vem, o
	 * tamanho, os últimos quatro caracteres e se tem cara de chave do Gemini.
	 * É o bastante para comparar com a que a pessoa tem guardada.
	 *
	 * @return array array( 'origem', 'tamanho', 'fim', 'formato_ok' )
	 */
	public static function resumo_chave() {
		$chave = self::api_key();
		return array(
			'origem'     => '' === $chave ? '' : ( self::chave_vem_de_constante() ? 'wp-config.php' : 'painel' ),
			'tamanho'    => strlen( $chave ),
			'fim'        => strlen( $chave ) >= 8 ? substr( $chave, -4 ) : '',
			// Só o que toda chave do Google tem em comum: letras, números, ponto,
			// hífen e sublinhado, sem espaço. O formato em si mudou — as chaves
			// antigas começam com AIza e têm 39 caracteres, as novas não —, então
			// prefixo e tamanho exatos não servem de regra.
			'formato_ok' => (bool) preg_match( '/^[0-9A-Za-z._\-]{30,200}$/', $chave ),
		);
	}

	public static function chave_vem_de_constante() {
		return defined( self::CONSTANTE_CHAVE ) && '' !== trim( (string) constant( self::CONSTANTE_CHAVE ) );
	}

	public static function modelo() {
		$tudo = self::tudo();
		return self::normalizar_modelo( $tudo['GEMINI_MODEL'] );
	}

	public static function modelo_reserva() {
		$tudo = self::tudo();
		return self::normalizar_modelo( $tudo['GEMINI_MODEL_RESERVA'] );
	}

	public static function esta_ativa() {
		$tudo = self::tudo();
		return '1' === (string) $tudo['ATIVA'];
	}

	/** Chamadas por dia. 0 quer dizer sem teto. */
	public static function teto_diario() {
		$tudo = self::tudo();
		return max( 0, (int) $tudo['TETO_DIARIO'] );
	}

	public static function tem_teto_diario() {
		return self::teto_diario() > 0;
	}

	/** Responder por voz está ligado — e há modelo para ouvir. */
	/** O lembrete por e-mail para quem parou no meio. Ligado por padrão. */
	public static function lembrete_ligado() {
		$tudo = self::tudo();
		return '1' === (string) ( isset( $tudo['LEMBRETE'] ) ? $tudo['LEMBRETE'] : '1' );
	}

	public static function voz_ligada() {
		$tudo = self::tudo();
		return self::pode_comentar() && '1' === (string) ( isset( $tudo['VOZ'] ) ? $tudo['VOZ'] : '1' );
	}

	/** Segundos por gravação, entre 10 e 120. */
	public static function voz_segundos() {
		$tudo = self::tudo();
		$s    = isset( $tudo['VOZ_SEGUNDOS'] ) ? (int) $tudo['VOZ_SEGUNDOS'] : 0;
		if ( $s < 1 ) {
			$s = (int) self::padroes()['VOZ_SEGUNDOS'];
		}
		return max( 10, min( 120, $s ) );
	}

	public static function nome() {
		$tudo = self::tudo();
		$nome = trim( (string) $tudo['NOME'] );
		return '' !== $nome ? $nome : 'LetícIA';
	}

	public static function cor() {
		$tudo = self::tudo();
		$cor  = sanitize_hex_color( $tudo['COR'] );
		if ( ! $cor ) {
			$cor = self::padroes()['COR'];
		}
		return $cor;
	}

	/**
	 * Para quem vai o briefing, como lista.
	 *
	 * Um endereço por linha no campo da configuração, porque é o formato que
	 * alguém consegue editar sem errar vírgula — e errar vírgula aqui é o
	 * briefing não chegar em ninguém.
	 */
	public static function destino() {
		$tudo   = self::tudo();
		$linhas = preg_split( '/[
,;]+/', (string) $tudo['DESTINO'] );
		$saida  = array();

		foreach ( (array) $linhas as $linha ) {
			$linha = trim( $linha );
			if ( '' !== $linha && is_email( $linha ) ) {
				$saida[] = $linha;
			}
		}

		if ( ! $saida ) {
			// Sem destino válido configurado, o administrador do site. É melhor
			// o briefing chegar no lugar errado do que não chegar em lugar
			// nenhum.
			$admin = (string) get_option( 'admin_email', '' );
			if ( '' !== $admin ) {
				$saida[] = $admin;
			}
		}

		return $saida;
	}

	/** O remetente, no formato que o wp_mail espera no cabeçalho From. */
	public static function remetente() {
		$tudo  = self::tudo();
		$email = trim( (string) $tudo['REMETENTE'] );
		$nome  = trim( (string) $tudo['REMETENTE_NOME'] );

		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}
		return '' !== $nome ? $nome . ' <' . $email . '>' : $email;
	}

	public static function assunto() {
		$tudo = self::tudo();
		return trim( (string) $tudo['ASSUNTO'] );
	}

	public static function assunto_continuacao() {
		$tudo    = self::tudo();
		$assunto = isset( $tudo['ASSUNTO_CONTINUACAO'] ) ? trim( (string) $tudo['ASSUNTO_CONTINUACAO'] ) : '';
		return '' !== $assunto ? $assunto : self::padroes()['ASSUNTO_CONTINUACAO'];
	}

	/**
	 * O que o painel mudou na obrigatoriedade dos campos.
	 *
	 * @return array chave => array( 'obrigatorio' => bool|null, 'depois' => bool|null )
	 */
	public static function campos() {
		$tudo  = self::tudo();
		$saida = array();
		foreach ( (array) ( isset( $tudo['CAMPOS'] ) ? $tudo['CAMPOS'] : array() ) as $chave => $regra ) {
			if ( ! is_array( $regra ) ) {
				continue;
			}
			$saida[ $chave ] = array(
				'obrigatorio' => isset( $regra['obrigatorio'] ) ? '1' === (string) $regra['obrigatorio'] : null,
				'depois'      => isset( $regra['depois'] ) ? '1' === (string) $regra['depois'] : null,
			);
		}
		return $saida;
	}

	/** Quanto de anexo cabe num e-mail, em bytes. */
	public static function teto_email() {
		$tudo = self::tudo();
		$mb   = isset( $tudo['TETO_EMAIL_MB'] ) ? (int) $tudo['TETO_EMAIL_MB'] : 0;
		if ( $mb < 1 ) {
			$mb = (int) self::padroes()['TETO_EMAIL_MB'];
		}
		return min( 50, $mb ) * 1024 * 1024;
	}

	/**
	 * Teto por arquivo, em bytes: o mesmo do e-mail.
	 *
	 * Arquivo que não cabe sozinho num e-mail não tem como chegar à equipe,
	 * então é recusado na hora, com a saída do link de pasta — e não aceito para
	 * sumir na entrega.
	 */
	public static function teto_arquivo() {
		return self::teto_email();
	}

	public static function link_anexo_depois() {
		$tudo = self::tudo();
		return trim( (string) $tudo['LINK_ANEXO_DEPOIS'] );
	}

	/** O texto que o cliente aceita antes de enviar. */
	public static function consentimento() {
		$tudo  = self::tudo();
		$texto = trim( (string) ( isset( $tudo['CONSENTIMENTO'] ) ? $tudo['CONSENTIMENTO'] : '' ) );
		return '' !== $texto ? $texto : Leticia_Base::texto( 'consentimento' );
	}

	/**
	 * O aviso de que a conversa passa por IA.
	 *
	 * Vazio quando não há modelo: sem chave, ou com a IA desligada no painel,
	 * nada do que a pessoa escreve sai do servidor — e o aviso diria uma
	 * coisa que não está acontecendo.
	 */
	public static function aviso_ia() {
		if ( ! self::pode_comentar() ) {
			return '';
		}
		$tudo  = self::tudo();
		$texto = trim( (string) ( isset( $tudo['AVISO_IA'] ) ? $tudo['AVISO_IA'] : '' ) );
		$texto = '' !== $texto ? $texto : Leticia_Base::texto( 'aviso-ia' );
		return str_replace( '{assistente}', self::nome(), $texto );
	}

	/** O caminho que o wp-config.php definiu, ou '' quando não definiu. */
	public static function pasta_definida() {
		return defined( self::CONSTANTE_PASTA ) ? trim( (string) constant( self::CONSTANTE_PASTA ) ) : '';
	}

	public static function formulario_classico() {
		$tudo = self::tudo();
		return trim( (string) $tudo['FORMULARIO_CLASSICO'] );
	}

	/**
	 * Tem tudo para conversar com o modelo.
	 *
	 * Repare que a base dos campos NÃO entra aqui. Sem a base a LetícIA ainda
	 * pergunta, valida e envia — só perde as palavras boas. Sem chave ela perde
	 * os comentários. Nenhum dos dois impede um briefing de chegar à equipe, e
	 * é por isso que "configurado" é uma pergunta diferente de "funciona".
	 */
	public static function esta_configurado() {
		return '' !== self::api_key() && '' !== self::modelo();
	}

	/** Configurada e ligada: é o que decide se a LetícIA conversa ou só coleta. */
	public static function pode_comentar() {
		return self::esta_ativa() && self::esta_configurado();
	}

	/**
	 * O briefing sempre pode ser preenchido e enviado.
	 *
	 * Existe como função, e não como `true` espalhado pelo código, para que
	 * fique escrito em algum lugar que isto é uma decisão: nada desliga a
	 * coleta. O interruptor desliga o modelo, não o formulário.
	 */
	public static function pode_atender() {
		return true;
	}

	public static function normalizar_modelo( $modelo ) {
		$modelo = strtolower( trim( (string) $modelo ) );
		$modelo = preg_replace( '#^models/#', '', $modelo );
		return preg_replace( '/[^a-z0-9._\-]/', '', $modelo );
	}

	public static function sanitizar( $bruto ) {
		$atual   = self::tudo();
		$bruto   = is_array( $bruto ) ? $bruto : array();
		$padroes = self::padroes();

		// Campo em branco significa "mantenha a chave salva": assim ela nunca
		// precisa ser reimpressa numa página do wp-admin para ser mantida.
		$chave = isset( $bruto['GEMINI_API_KEY'] ) ? self::limpar_chave( sanitize_text_field( $bruto['GEMINI_API_KEY'] ) ) : '';
		if ( '' === $chave ) {
			$chave = $atual['GEMINI_API_KEY'];
		}

		$modelo = isset( $bruto['GEMINI_MODEL'] ) ? self::normalizar_modelo( $bruto['GEMINI_MODEL'] ) : '';
		if ( '' === $modelo ) {
			$modelo = $padroes['GEMINI_MODEL'];
		}

		$reserva = isset( $bruto['GEMINI_MODEL_RESERVA'] ) ? self::normalizar_modelo( $bruto['GEMINI_MODEL_RESERVA'] ) : '';
		if ( $reserva === $modelo ) {
			$reserva = '';   // reserva igual ao principal não é reserva
		}

		// 0 é escolha legítima: sem teto.
		$teto = isset( $bruto['TETO_DIARIO'] ) ? max( 0, (int) $bruto['TETO_DIARIO'] ) : (int) $atual['TETO_DIARIO'];
		$teto = min( 50000, $teto );

		$voz_segundos = isset( $bruto['VOZ_SEGUNDOS'] ) ? (int) $bruto['VOZ_SEGUNDOS'] : (int) $atual['VOZ_SEGUNDOS'];
		$voz_segundos = max( 10, min( 120, $voz_segundos ) );

		// Campo de várias linhas: sanitize_text_field come a quebra, então é
		// linha a linha, e o que sobra é remontado com quebras de verdade.
		$destinos = array();
		if ( isset( $bruto['DESTINO'] ) ) {
			foreach ( (array) preg_split( '/[
,;]+/', (string) $bruto['DESTINO'] ) as $linha ) {
				$linha = trim( sanitize_text_field( $linha ) );
				if ( '' !== $linha && is_email( $linha ) ) {
					$destinos[] = $linha;
				}
			}
		}
		// Lista vazia não substitui uma lista boa: um erro de digitação não
		// pode desligar a entrega sem ninguém perceber.
		$destino = $destinos ? implode( "
", $destinos ) : $atual['DESTINO'];

		$remetente = isset( $bruto['REMETENTE'] ) ? trim( sanitize_text_field( $bruto['REMETENTE'] ) ) : '';
		if ( '' === $remetente || ! is_email( $remetente ) ) {
			$remetente = $atual['REMETENTE'];
		}
		$remetente_nome = isset( $bruto['REMETENTE_NOME'] )
			? trim( sanitize_text_field( wp_strip_all_tags( $bruto['REMETENTE_NOME'] ) ) )
			: $atual['REMETENTE_NOME'];

		$assunto = isset( $bruto['ASSUNTO'] ) ? trim( sanitize_text_field( wp_strip_all_tags( $bruto['ASSUNTO'] ) ) ) : '';
		if ( '' === $assunto ) {
			$assunto = $padroes['ASSUNTO'];
		}

		$link_anexo = isset( $bruto['LINK_ANEXO_DEPOIS'] ) ? esc_url_raw( trim( $bruto['LINK_ANEXO_DEPOIS'] ) ) : '';

		$assunto_cont = isset( $bruto['ASSUNTO_CONTINUACAO'] ) ? trim( sanitize_text_field( wp_strip_all_tags( $bruto['ASSUNTO_CONTINUACAO'] ) ) ) : '';
		if ( '' === $assunto_cont ) {
			$assunto_cont = isset( $atual['ASSUNTO_CONTINUACAO'] ) && '' !== $atual['ASSUNTO_CONTINUACAO'] ? $atual['ASSUNTO_CONTINUACAO'] : $padroes['ASSUNTO_CONTINUACAO'];
		}

		// A tabela de campos só é lida quando veio do formulário dela: caixa de
		// marcar desmarcada não é enviada, e sem a marca "CAMPOS_ENVIADO" não dá
		// para saber se desmarcaram ou se o formulário nem tinha a tabela.
		$campos = isset( $atual['CAMPOS'] ) && is_array( $atual['CAMPOS'] ) ? $atual['CAMPOS'] : array();
		if ( ! empty( $bruto['CAMPOS_ENVIADO'] ) ) {
			$marcados = isset( $bruto['CAMPOS'] ) && is_array( $bruto['CAMPOS'] ) ? $bruto['CAMPOS'] : array();
			$campos   = array();
			foreach ( Leticia_Campos::estrutura() as $campo ) {
				$chave  = $campo['chave'];
				$regra  = array( 'obrigatorio' => ! empty( $marcados[ $chave ]['obrigatorio'] ) ? '1' : '0' );
				if ( 'arquivo' === $campo['tipo'] ) {
					$regra['depois'] = ! empty( $marcados[ $chave ]['depois'] ) ? '1' : '0';
				}
				$campos[ $chave ] = $regra;
			}
		}

		// Uma linha só: o aceite é uma frase ao lado de uma caixa de marcar.
		$consentimento = isset( $bruto['CONSENTIMENTO'] ) ? trim( sanitize_text_field( wp_strip_all_tags( $bruto['CONSENTIMENTO'] ) ) ) : '';
		if ( $consentimento === Leticia_Base::texto( 'consentimento' ) ) {
			// Igual ao da base é o mesmo que vazio: assim, se a base mudar, quem
			// nunca personalizou acompanha.
			$consentimento = '';
		}

		$aviso_ia = isset( $bruto['AVISO_IA'] ) ? trim( sanitize_text_field( wp_strip_all_tags( $bruto['AVISO_IA'] ) ) ) : '';
		if ( $aviso_ia === Leticia_Base::texto( 'aviso-ia' ) ) {
			$aviso_ia = '';
		}

		$teto_email = isset( $bruto['TETO_EMAIL_MB'] ) ? (int) $bruto['TETO_EMAIL_MB'] : 0;
		if ( $teto_email < 1 ) {
			$teto_email = (int) $padroes['TETO_EMAIL_MB'];
		}
		$teto_email = min( 50, $teto_email );

		$classico = isset( $bruto['FORMULARIO_CLASSICO'] ) ? esc_url_raw( trim( $bruto['FORMULARIO_CLASSICO'] ) ) : '';
		if ( '' === $classico ) {
			$classico = $padroes['FORMULARIO_CLASSICO'];
		}

		$nome = isset( $bruto['NOME'] ) ? trim( sanitize_text_field( $bruto['NOME'] ) ) : '';
		$cor  = isset( $bruto['COR'] ) ? sanitize_hex_color( $bruto['COR'] ) : '';

		Leticia_Base::limpar_cache();
		Leticia_Campos::limpar_cache();
		delete_transient( self::DIAG );

		return array(
			'GEMINI_API_KEY'       => $chave,
			'GEMINI_MODEL'         => $modelo,
			'GEMINI_MODEL_RESERVA' => $reserva,
			'ATIVA'                => ! empty( $bruto['ATIVA'] ) ? '1' : '0',
			'TETO_DIARIO'          => (string) $teto,
			'VOZ'                  => ! empty( $bruto['VOZ'] ) ? '1' : '0',
			'VOZ_SEGUNDOS'         => (string) $voz_segundos,
			'REMETENTE'            => $remetente,
			'REMETENTE_NOME'       => $remetente_nome,
			'DESTINO'              => $destino,
			'ASSUNTO'              => $assunto,
			'ASSUNTO_CONTINUACAO'  => $assunto_cont,
			'CAMPOS'               => $campos,
			'LINK_ANEXO_DEPOIS'    => $link_anexo,
			'CONSENTIMENTO'        => $consentimento,
			'AVISO_IA'             => $aviso_ia,
			'TETO_EMAIL_MB'        => (string) $teto_email,
			'APAGAR_AO_DESINSTALAR' => ! empty( $bruto['APAGAR_AO_DESINSTALAR'] ) ? '1' : '0',
			'LEMBRETE'             => ! empty( $bruto['LEMBRETE'] ) ? '1' : '0',
			'FORMULARIO_CLASSICO'  => $classico,
			'NOME'                 => '' !== $nome ? $nome : $padroes['NOME'],
			'COR'                  => $cor ? $cor : $padroes['COR'],
		);
	}

	public static function salvar( array $valores ) {
		return update_option( self::OPCAO, self::sanitizar( $valores ), false );
	}

	public static function ao_ativar() {
		delete_transient( self::DIAG );
		Leticia_Base::limpar_cache();
	}
}
