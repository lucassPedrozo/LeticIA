<?php
/**
 * As rotas que o navegador chama.
 *
 *   POST /leticia/v1/sessao             abre ou retoma o briefing
 *   POST /leticia/v1/responder          grava um campo e devolve o próximo
 *   POST /leticia/v1/pular              opcional em branco — não vai ao modelo
 *   POST /leticia/v1/voltar             reabre um campo para corrigir
 *   POST /leticia/v1/arquivo/iniciar    abre um envio em pedaços
 *   POST /leticia/v1/arquivo/pedaco     um pedaço, no corpo cru
 *   POST /leticia/v1/arquivo/concluir   remonta, valida e guarda
 *   POST /leticia/v1/arquivo/remover    tira um arquivo antes de continuar
 *   POST /leticia/v1/enviar             fecha o briefing e avisa a equipe
 *   POST /leticia/v1/descartar          "não é você? começar do zero"
 *   POST /leticia/v1/anexo/abrir        o link de "mandar a logo depois"
 *   POST /leticia/v1/anexo/concluir     fecha a pendência e avisa a equipe
 *   POST /leticia/v1/voz                a resposta falada, no corpo cru — volta texto para conferir
 *   POST /leticia/v1/proposta           usar, ajustar ou dispensar o rascunho que ela escreveu
 *   GET  /leticia/v1/saude              estado, para monitoramento externo
 *
 * Rota pública, sem login — a página do briefing pode estar em cache e não há
 * usuário para autenticar. A defesa não é autenticação: é o teto de entrada, o
 * limite de ritmo, o disjuntor e o fato de que **nada que o navegador manda
 * decide coisa alguma**. O token assinado só amarra as chamadas a uma sessão
 * que saiu daqui; ele não é credencial.
 *
 * Toda rota devolve o mesmo formato — o estado da tela inteiro. Um formato só
 * significa um renderizador só no navegador, e significa que voltar, pular,
 * responder e retomar não podem divergir na forma como deixam a tela.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Rest {

	const NAMESPACE_API = 'leticia/v1';

	/** Onde ficam os arquivos que subiram mas cujo campo ainda não fechou. */
	const PREFIXO_GAVETA = 'leticia_gaveta_';

	public static function iniciar() {
		add_action( 'rest_api_init', array( __CLASS__, 'registrar' ) );
	}

	public static function registrar() {
		$publico = '__return_true';

		$rotas = array(
			'/sessao'            => 'abrir',
			'/responder'         => 'responder',
			'/pular'             => 'pular',
			'/voltar'            => 'voltar',
			'/arquivo/iniciar'   => 'arquivo_iniciar',
			'/arquivo/pedaco'    => 'arquivo_pedaco',
			'/arquivo/concluir'  => 'arquivo_concluir',
			'/arquivo/remover'   => 'arquivo_remover',
			'/enviar'            => 'enviar',
			'/descartar'         => 'descartar',
			'/anexo/abrir'       => 'anexo_abrir',
			'/anexo/concluir'    => 'anexo_concluir',
			'/voz'               => 'ouvir',
			'/proposta'          => 'proposta',
			'/continuar'         => 'continuar',
			'/sugerir'           => 'sugerir',
		);

		foreach ( $rotas as $caminho => $metodo ) {
			register_rest_route(
				self::NAMESPACE_API,
				$caminho,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $metodo ),
					'permission_callback' => $publico,
				)
			);
		}

		register_rest_route(
			self::NAMESPACE_API,
			'/saude',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'saude' ),
				'permission_callback' => $publico,
			)
		);
	}

	// ------------------------------------------------------------- abertura

	/**
	 * Abre um briefing, ou retoma o que o token apontar.
	 *
	 * O navegador manda o token que guardou no localStorage. Se ele conferir e
	 * houver rascunho, a resposta já vem com a barra de retomada montada — e
	 * com a saída, porque computador compartilhado existe.
	 */
	public static function abrir( $req ) {
		if ( ! Leticia_Limites::pode_chamar() ) {
			return self::recusa( 'muitas_aberturas', 'Muita gente está abrindo o briefing daqui ao mesmo tempo. Pode tentar de novo em alguns minutos?', 429 );
		}

		$token  = (string) $req->get_param( 'token' );
		$sessao = '';
		$estado = null;
		$resumo = null;
		$aviso  = '';

		// O link de "continuar depois" vale mais que o token do navegador:
		// quem clicou nele quer aquele briefing, não o que este aparelho tinha.
		$retomar = (string) $req->get_param( 'retomar' );
		if ( '' !== $retomar ) {
			$aberta = Leticia_Retomada::abrir( $retomar );
			if ( is_wp_error( $aberta ) ) {
				$aviso = $aberta->get_error_message();
			} else {
				$guardado = Leticia_Rascunho::carregar( $aberta );
				if ( $guardado ) {
					$sessao = $aberta;
					$estado = $guardado;
					$resumo = Leticia_Rascunho::resumo( $sessao );
					Leticia_Registro::contar_uso( $sessao, 'abriu_link' );
					// Link da equipe aberto pela primeira vez: é agora que o
					// cliente começa, e o relógio do primeiro campo também.
					$linha = Leticia_Registro::briefing( $sessao );
					if ( Leticia_Links::nao_aberto( $linha ) ) {
						$roteiro              = $linha['roteiro'];
						$roteiro['aberto_em'] = time();
						$roteiro['atividade'] = time();
						Leticia_Registro::armazem()->gravar_briefing( $sessao, array( 'roteiro' => $roteiro ) );
					}
				}
			}
		}

		if ( '' === $sessao && '' !== $token ) {
			$conferido = Leticia_Rascunho::conferir( $token );
			if ( ! is_wp_error( $conferido ) ) {
				$guardado = Leticia_Rascunho::carregar( $conferido );
				if ( $guardado ) {
					$sessao = $conferido;
					$estado = $guardado;
					$resumo = Leticia_Rascunho::resumo( $sessao );
				}
			}
		}

		if ( '' === $sessao ) {
			$sessao = Leticia_Rascunho::nova_sessao();
			$estado = Leticia_Roteiro::novo( Leticia_Rascunho::semente( $sessao ) );
		}

		$saida               = self::tela( $sessao, $estado );
		$saida['retomada']   = $resumo;
		$saida['aviso_link'] = $aviso;

		return rest_ensure_response( $saida );
	}

	/**
	 * Cabe propor uma lista ao abrir este campo?
	 *
	 * Só no campo que pede (`sugere_lista`), com o modelo disponível, com o
	 * ramo já respondido — é dele que a lista sai —, antes de qualquer
	 * resposta e uma vez por briefing.
	 */
	private static function pode_sugerir( $sessao, array $estado, array $campo ) {
		if ( empty( $campo['sugere_lista'] ) || ! Leticia_Config::pode_comentar() || Leticia_Limites::disjuntor_aberto() ) {
			return false;
		}
		if ( isset( $estado['respostas'][ $campo['chave'] ] ) || ! empty( $estado['propostas'] ) ) {
			return false;
		}
		if ( empty( $estado['respostas']['ramo']['valor'] ) ) {
			return false;
		}
		return ! Leticia_Registro::ja_sugerido( $sessao, $campo['chave'] );
	}

	/**
	 * A lista sugerida, pedida pela tela ao abrir o campo de serviços.
	 *
	 * Vira um rascunho de ajuda: o campo continua aberto até a pessoa usar,
	 * ajustar ou dispensar — exatamente como quando ela pede "me dá uma
	 * ideia". Sem modelo, ou se ele não escrever nada, a tela fica como
	 * estava: a pergunta, esperando resposta.
	 */
	public static function sugerir( $req ) {
		$ctx = self::preparar( $req );
		if ( is_wp_error( $ctx ) ) {
			return self::do_erro( $ctx );
		}

		list( $sessao, $estado ) = array( $ctx['sessao'], $ctx['estado'] );

		$campo = Leticia_Campos::por_chave( (string) $req->get_param( 'campo' ) );
		$atual = Leticia_Roteiro::proximo( $estado );
		if ( ! $campo || ! $atual || $atual['chave'] !== $campo['chave'] || ! self::pode_sugerir( $sessao, $estado, $campo ) ) {
			return rest_ensure_response( self::tela( $sessao, $estado ) );
		}

		// Marca antes de chamar: dois pedidos seguidos — duas abas, um
		// recarregar no meio — não viram duas chamadas.
		Leticia_Registro::marcar_sugerido( $sessao, $campo['chave'] );

		$comeco   = microtime( true );
		$consulta = Leticia_Modelo::consultar( $campo, '', self::contexto( $estado ), array( 'sessao' => $sessao, 'sugerir' => true ) );
		self::anotar_turno( $sessao, $campo['chave'], '[lista sugerida ao abrir o campo]', $consulta, microtime( true ) - $comeco );

		if ( $consulta && ! $consulta['degradado'] && null !== $consulta['proposta'] ) {
			$fala   = Leticia_Base::escolher( 'proposta-sugestao-lista', (int) $estado['semente'], Leticia_Roteiro::valores( $estado ) );
			$intro  = $fala ? trim( $fala['titulo'] . ' ' . $fala['detalhe'] ) : '';
			$estado = Leticia_Roteiro::guardar_proposta( $estado, $campo['chave'], $consulta['proposta'], 'ajuda', $intro );
			Leticia_Rascunho::salvar( $sessao, $estado, self::extra( $req ) );
			Leticia_Registro::contar_uso( $sessao, 'lista_sugerida' );
		}

		return rest_ensure_response( self::tela( $sessao, $estado ) );
	}

	/**
	 * "Continuar depois": o link deste briefing, e os jeitos de guardá-lo.
	 *
	 * Devolve o link e o que a tela pode oferecer — o WhatsApp e o e-mail que
	 * a própria pessoa deu. Com `enviar = email`, manda o link para esse
	 * e-mail; nunca para um endereço que venha na requisição.
	 */
	public static function continuar( $req ) {
		$ctx = self::preparar( $req );
		if ( is_wp_error( $ctx ) ) {
			return self::do_erro( $ctx );
		}

		list( $sessao, $estado ) = array( $ctx['sessao'], $ctx['estado'] );

		if ( Leticia_Roteiro::quantos_resolvidos( $estado ) < 1 ) {
			return self::recusa( 'nada_para_guardar', 'Responda a primeira pergunta, e aí o link já guarda o que você escreveu.', 400 );
		}

		$pagina = self::extra( $req )['pagina'];
		// Grava a página junto: é nela que o link abre, e o lembrete e o
		// painel também geram links a partir dela.
		Leticia_Rascunho::salvar( $sessao, $estado, self::extra( $req ) );

		Leticia_Registro::contar_uso( $sessao, 'email' === (string) $req->get_param( 'enviar' ) ? 'continuar_email' : 'continuar_link' );

		$saida = array(
			'link'     => Leticia_Retomada::link( $sessao, $pagina ),
			'whatsapp' => Leticia_Retomada::numero_whatsapp( $estado['respostas'] ),
			'email'    => Leticia_Retomada::mascarar( Leticia_Retomada::email( $estado['respostas'] ) ),
			'enviado'  => false,
			'erro'     => '',
		);

		if ( 'email' === (string) $req->get_param( 'enviar' ) ) {
			$foi = Leticia_Retomada::mandar_link( $sessao, $estado, $pagina );
			if ( is_wp_error( $foi ) ) {
				$saida['erro'] = $foi->get_error_message();
			} else {
				$saida['enviado'] = true;
			}
		}

		return rest_ensure_response( $saida );
	}

	// ------------------------------------------------------------- resposta

	/**
	 * A resposta de um campo.
	 *
	 * É a única rota que pode chamar o modelo, e mesmo assim só chama quando o
	 * campo comenta ou quando a mensagem parece pergunta. Clique em botão,
	 * pulo, volta e upload não gastam cota.
	 */
	public static function responder( $req ) {
		$ctx = self::preparar( $req );
		if ( is_wp_error( $ctx ) ) {
			return self::do_erro( $ctx );
		}

		list( $sessao, $estado ) = array( $ctx['sessao'], $ctx['estado'] );

		$chave = (string) $req->get_param( 'campo' );
		$campo = Leticia_Campos::por_chave( $chave );

		if ( ! $campo ) {
			return self::recusa( 'campo_invalido', 'Esse campo não existe neste briefing.', 400 );
		}

		// Responder deixa de lado o rascunho que estava esperando decisão.
		$estado = Leticia_Roteiro::esquecer_propostas( $estado );

		// Campo de arquivo fecha com o que estiver na gaveta, não com texto.
		if ( 'arquivo' === $campo['tipo'] ) {
			return self::fechar_arquivo( $sessao, $estado, $campo, $req );
		}

		$bruto = (string) $req->get_param( 'texto' );

		$validado = Leticia_Validacao::checar( $campo, $bruto );

		// "Me dá uma ideia" vai ao modelo mesmo onde "não sei" não iria: é
		// pedido de ajuda, e quem ajuda é ela. Sem modelo, cai na fala escrita
		// de conduzir — nunca é gravado como resposta.
		$pede_ajuda = Leticia_Validacao::pede_ajuda( $bruto );
		$ajuda_vai  = $pede_ajuda && Leticia_Config::pode_comentar();

		// Pergunta no lugar da resposta — "o que é domínio?" — também passa
		// pela validação de formato antes de ser recusada: ela reprova, mas
		// quem responde a pergunta é o modelo. Antes, a dúvida no domínio, no
		// e-mail e no telefone ganhava "isso não parece um endereço" e ficava
		// sem resposta.
		$pergunta_vai = ( $pede_ajuda || Leticia_Validacao::parece_duvida( $bruto ) ) && Leticia_Config::pode_comentar();

		// "Não sei" num obrigatório: ela responde, a pessoa fica no campo, e o
		// que ela escreveu não volta para a caixa — não era o que faltava.
		if ( ! $validado['ok'] && ! empty( $validado['conduzir'] ) && ! $ajuda_vai ) {
			self::anotar_turno( $sessao, $chave, $bruto, null, 0 );
			$saida              = self::tela( $sessao, $estado );
			$saida['dela']      = array( 'tipo' => 'resposta', 'comentario' => null, 'repergunta' => $validado['erro'], 'resposta_duvida' => null );
			$saida['permanece'] = true;
			return rest_ensure_response( $saida );
		}

		if ( ! $validado['ok'] && empty( $validado['conduzir'] ) && ! $pergunta_vai ) {
			// Erro de formato não vai ao modelo e não gasta nada: a pessoa
			// continua no mesmo campo, com o texto dela na tela.
			$saida             = self::tela( $sessao, $estado );
			$saida['erro']     = $validado['erro'];
			$saida['permanece'] = true;
			return rest_ensure_response( $saida );
		}

		$consulta = null;
		$comeco   = microtime( true );

		// Curta demais para o que o campo precisa — "bolo", "mapa" no ramo — e
		// ainda cabe pedir mais: ela não avança. Quem decide é esta conta, não
		// o modelo; ele só escreve o pedido.
		$anterior = isset( $estado['anteriores'][ $chave ] ) ? (string) $estado['anteriores'][ $chave ] : '';
		$curto    = $validado['ok'] && ! $pede_ajuda && Leticia_Validacao::curto( $campo, $bruto, $anterior )
			&& Leticia_Roteiro::pode_reperguntar( $estado, $chave );

		// "Não tenho" já foi entendido aqui: não precisa de modelo para isso.
		$poupar = Leticia_Modelo::motivo_para_poupar( $campo, $bruto, $estado, $validado );
		if ( '' !== $poupar && ! in_array( $poupar, array( 'negado', 'nao_comenta' ), true ) ) {
			// Só conta o que antes teria sido chamada: campo que comenta.
			Leticia_Limites::registrar_poupada();
		}

		if ( '' === $poupar ) {
			$consulta = Leticia_Modelo::consultar(
				$campo,
				$bruto,
				self::contexto( $estado ),
				array(
					'sessao'        => $sessao,
					'reperguntando' => ! Leticia_Roteiro::pode_reperguntar( $estado, $chave ),
					'curto'         => $curto,
					'ultima_ponte'  => $estado['ultima_ponte'],
					'anterior'      => $anterior,
				)
			);
		}

		self::anotar_turno( $sessao, $chave, $bruto, $consulta, microtime( true ) - $comeco );

		// Pediu ajuda e o modelo não estava lá (ou não entendeu o pedido): a
		// mensagem não pode virar a resposta do campo.
		if ( $pede_ajuda && ( ! $consulta || $consulta['degradado'] || ( 'resposta' === $consulta['tipo'] && ! $validado['ok'] ) ) ) {
			$saida              = self::tela( $sessao, $estado );
			$saida['dela']      = array( 'tipo' => 'resposta', 'comentario' => null, 'repergunta' => Leticia_Validacao::fala_de_conducao( $chave ), 'resposta_duvida' => null );
			$saida['permanece'] = true;
			return rest_ensure_response( $saida );
		}

		// Pedido de ajuda: com rascunho, a tela mostra o rascunho e o campo
		// continua aberto até a pessoa decidir; sem, ela conduz com perguntas.
		if ( $consulta && 'ajuda' === $consulta['tipo'] ) {
			if ( null !== $consulta['proposta'] ) {
				// A apresentação é uma frase curta. Mais que isso, o modelo está
				// repetindo o rascunho em prosa, e a tela vira dois textos iguais.
				$intro = self::uma_frase( (string) $consulta['resposta_duvida'] );
				if ( mb_strlen( $intro, 'UTF-8' ) > 110 ) {
					$fala  = Leticia_Base::escolher( 'proposta-intro-ajuda', (int) $estado['semente'], Leticia_Roteiro::valores( $estado ) );
					$intro = $fala ? trim( $fala['titulo'] . ' ' . $fala['detalhe'] ) : '';
				}
				$estado = Leticia_Roteiro::guardar_proposta( $estado, $chave, $consulta['proposta'], 'ajuda', $intro );
				Leticia_Rascunho::salvar( $sessao, $estado );
				return rest_ensure_response( self::tela( $sessao, $estado ) );
			}
			Leticia_Rascunho::salvar( $sessao, $estado );
			$saida              = self::tela( $sessao, $estado );
			$saida['dela']      = self::dela( $consulta );
			$saida['permanece'] = true;
			return rest_ensure_response( $saida );
		}

		// Parecia pergunta, foi ao modelo, e não era dúvida — ou o modelo não
		// estava lá: o formato continua reprovado, e a resposta é o erro de
		// formato de sempre, não um texto inválido gravado.
		if ( ! $validado['ok'] && ( ! $consulta || $consulta['degradado'] || 'resposta' === $consulta['tipo'] ) ) {
			$saida              = self::tela( $sessao, $estado );
			$saida['permanece'] = true;
			if ( ! empty( $validado['conduzir'] ) ) {
				$saida['dela'] = array( 'tipo' => 'resposta', 'comentario' => null, 'repergunta' => $validado['erro'], 'resposta_duvida' => null );
			} else {
				$saida['erro'] = $validado['erro'];
			}
			return rest_ensure_response( $saida );
		}

		// Dúvida e fora de escopo mantêm a pessoa no mesmo campo: ela perguntou
		// alguma coisa em vez de responder.
		if ( $consulta && in_array( $consulta['tipo'], array( 'duvida', 'fora_de_escopo' ), true ) ) {
			$saida              = self::tela( $sessao, $estado );
			$saida['dela']      = self::dela( $consulta );
			$saida['permanece'] = true;
			return rest_ensure_response( $saida );
		}

		// Curta demais: a pessoa fica no campo, com o texto dela de volta para
		// completar. O pedido é o do modelo quando ele escreveu um; senão, o
		// escrito na base.
		if ( $curto ) {
			Leticia_Registro::contar_uso( $sessao, 'curto_' . $chave );
			$fala = $consulta && ! $consulta['degradado'] && 'resposta' === $consulta['tipo'] && $consulta['repergunta']
				? $consulta['repergunta']
				: Leticia_Roteiro::fala_curta( $estado, $chave );
			$estado             = Leticia_Roteiro::marcar_repergunta( $estado, $chave );
			$estado             = Leticia_Roteiro::guardar_anterior( $estado, $chave, trim( $anterior . "\n" . $bruto ) );
			$saida              = self::tela( $sessao, $estado );
			$saida['dela']      = array( 'tipo' => 'resposta', 'comentario' => null, 'repergunta' => $fala, 'resposta_duvida' => null );
			$saida['permanece'] = true;
			$saida['eco']       = $bruto;
			Leticia_Rascunho::salvar( $sessao, $estado );
			return rest_ensure_response( $saida );
		}

		// A repergunta — uma por campo, salvo quando o campo pede mais.
		if ( $consulta && false === $consulta['suficiente'] && Leticia_Roteiro::pode_reperguntar( $estado, $chave ) ) {
			$estado             = Leticia_Roteiro::marcar_repergunta( $estado, $chave );
			$estado             = Leticia_Roteiro::guardar_anterior( $estado, $chave, trim( $anterior . "\n" . $bruto ) );
			$saida              = self::tela( $sessao, $estado );
			$saida['dela']      = self::dela( $consulta );
			$saida['permanece'] = true;
			$saida['eco']       = $bruto;   // o texto volta no campo, para completar
			Leticia_Rascunho::salvar( $sessao, $estado );
			return rest_ensure_response( $saida );
		}

		$r = Leticia_Roteiro::responder(
			$estado,
			$chave,
			$bruto,
			array( 'limpo' => $consulta ? $consulta['valor_limpo'] : '' )
		);

		if ( '' !== $r['erro'] ) {
			return self::recusa( 'recusado', $r['erro'], 400 );
		}

		$estado = $r['estado'];

		// A resposta bastou e ela escreveu um rascunho: a resposta já está
		// gravada, e a próxima tela é o rascunho, não a próxima pergunta. O
		// comentário dela vira a frase que apresenta o texto.
		if ( $consulta && 'resposta' === $consulta['tipo'] && null !== $consulta['proposta'] ) {
			$intro                  = $consulta['comentario'] ? self::uma_frase( $consulta['comentario'] ) : '';
			$estado                 = Leticia_Roteiro::guardar_proposta( $estado, $chave, $consulta['proposta'], 'resposta', $intro );
			$estado['ultima_ponte'] = $intro;
			Leticia_Rascunho::salvar( $sessao, $estado, self::extra( $req ) );

			$saida              = self::tela( $sessao, $estado );
			$saida['conferido'] = ! empty( $r['validacao']['conferido'] );
			return rest_ensure_response( $saida );
		}

		$ponte                  = self::ponte( $estado, $chave, $consulta );
		$estado['ultima_ponte'] = $ponte;
		Leticia_Rascunho::salvar( $sessao, $estado, self::extra( $req ) );

		$saida              = self::tela( $sessao, $estado );
		$saida['dela']      = self::dela( $consulta );
		$saida['ponte']     = $ponte;
		$saida['conferido'] = ! empty( $r['validacao']['conferido'] );

		return rest_ensure_response( $saida );
	}

	public static function pular( $req ) {
		$ctx = self::preparar( $req );
		if ( is_wp_error( $ctx ) ) {
			return self::do_erro( $ctx );
		}

		$r = Leticia_Roteiro::pular( Leticia_Roteiro::esquecer_propostas( $ctx['estado'] ), (string) $req->get_param( 'campo' ) );
		if ( '' !== $r['erro'] ) {
			return self::recusa( 'recusado', $r['erro'], 400 );
		}

		Leticia_Rascunho::salvar( $ctx['sessao'], $r['estado'] );

		$saida          = self::tela( $ctx['sessao'], $r['estado'] );
		$saida['ponte'] = self::ponte( $r['estado'], (string) $req->get_param( 'campo' ), null );
		return rest_ensure_response( $saida );
	}

	/**
	 * Reabre um campo.
	 *
	 * Não reprocessa nada com o modelo e não apaga o que veio depois: a
	 * resposta antiga continua gravada até ser reescrita, que é o que faz a
	 * barra não andar para trás.
	 */
	public static function voltar( $req ) {
		$ctx = self::preparar( $req );
		if ( is_wp_error( $ctx ) ) {
			return self::do_erro( $ctx );
		}

		$chave = (string) $req->get_param( 'campo' );
		$r     = Leticia_Roteiro::voltar_para( $ctx['estado'], $chave );

		if ( '' !== $r['erro'] ) {
			return self::recusa( 'recusado', $r['erro'], 400 );
		}

		$anterior          = isset( $r['estado']['respostas'][ $chave ] ) ? $r['estado']['respostas'][ $chave ] : array();
		$saida             = self::tela( $ctx['sessao'], $r['estado'], $chave );
		$saida['eco']      = isset( $anterior['bruto'] ) && '' !== $anterior['bruto']
			? $anterior['bruto']
			: ( isset( $anterior['valor'] ) ? $anterior['valor'] : '' );
		$saida['permanece'] = true;

		return rest_ensure_response( $saida );
	}

	// ------------------------------------------------------------- arquivos

	public static function arquivo_iniciar( $req ) {
		$ctx = self::preparar_arquivo( $req );
		if ( is_wp_error( $ctx ) ) {
			return self::do_erro( $ctx );
		}

		$r = Leticia_Arquivos::iniciar(
			$ctx['sessao'],
			(string) $req->get_param( 'campo' ),
			(string) $req->get_param( 'nome' ),
			(int) $req->get_param( 'tamanho' ),
			(int) $req->get_param( 'pedacos' )
		);

		return is_wp_error( $r ) ? self::do_erro( $r, 400 ) : rest_ensure_response( $r );
	}

	/**
	 * Um pedaço, no corpo cru da requisição.
	 *
	 * Corpo cru e não multipart de propósito: o navegador manda uma fatia de
	 * Blob, e embrulhá-la em multipart só acrescenta base64 e 33% de tráfego
	 * para o servidor desembrulhar em seguida.
	 */
	public static function arquivo_pedaco( $req ) {
		$ctx = self::preparar_arquivo( $req );
		if ( is_wp_error( $ctx ) ) {
			return self::do_erro( $ctx );
		}

		$r = Leticia_Arquivos::receber(
			$ctx['sessao'],
			(string) $req->get_param( 'id' ),
			(int) $req->get_param( 'indice' ),
			$req->get_body()
		);

		return is_wp_error( $r ) ? self::do_erro( $r, 400 ) : rest_ensure_response( $r );
	}

	/**
	 * Remonta e valida. O arquivo vai para a gaveta da sessão, não para o
	 * estado: a pessoa ainda pode mandar outro, ou tirar este.
	 */
	public static function arquivo_concluir( $req ) {
		$ctx = self::preparar_arquivo( $req );
		if ( is_wp_error( $ctx ) ) {
			return self::do_erro( $ctx );
		}

		$meta = Leticia_Arquivos::concluir( $ctx['sessao'], (string) $req->get_param( 'id' ) );
		if ( is_wp_error( $meta ) ) {
			return self::do_erro( $meta, 400 );
		}

		$gaveta                       = self::gaveta( $ctx['sessao'] );
		$gaveta[ $meta['campo'] ][]   = $meta;
		self::guardar_gaveta( $ctx['sessao'], $gaveta );

		return rest_ensure_response(
			array(
				'arquivo'  => self::arquivo_publico( $meta ),
				'arquivos' => array_map( array( __CLASS__, 'arquivo_publico' ), $gaveta[ $meta['campo'] ] ),
			)
		);
	}

	public static function arquivo_remover( $req ) {
		$ctx = self::preparar_arquivo( $req );
		if ( is_wp_error( $ctx ) ) {
			return self::do_erro( $ctx );
		}

		$id     = (string) $req->get_param( 'id' );
		$gaveta = self::gaveta( $ctx['sessao'] );

		foreach ( $gaveta as $campo => $arquivos ) {
			foreach ( $arquivos as $i => $meta ) {
				if ( $meta['id'] !== $id ) {
					continue;
				}
				Leticia_Arquivos::remover( $ctx['sessao'], $meta );
				unset( $gaveta[ $campo ][ $i ] );
				$gaveta[ $campo ] = array_values( $gaveta[ $campo ] );
				self::guardar_gaveta( $ctx['sessao'], $gaveta );

				return rest_ensure_response( array( 'arquivos' => array_map( array( __CLASS__, 'arquivo_publico' ), $gaveta[ $campo ] ) ) );
			}
		}

		return self::recusa( 'nao_achei', 'Esse arquivo não está mais aqui.', 404 );
	}

	private static function fechar_arquivo( $sessao, array $estado, array $campo, $req ) {
		$gaveta   = self::gaveta( $sessao );
		$arquivos = isset( $gaveta[ $campo['chave'] ] ) ? $gaveta[ $campo['chave'] ] : array();
		$pendente = (bool) $req->get_param( 'pendente' );
		$link     = trim( (string) $req->get_param( 'link' ) );

		if ( ! $arquivos && '' === $link && ! $pendente ) {
			if ( $campo['obrigatorio'] && empty( $campo['pode_ficar_pendente'] ) ) {
				return self::recusa( 'sem_arquivo', 'Preciso do arquivo aqui para seguir.', 400 );
			}
			// Opcional sem arquivo é o mesmo que pular.
			$r = Leticia_Roteiro::pular( $estado, $campo['chave'] );
			if ( '' === $r['erro'] ) {
				Leticia_Rascunho::salvar( $sessao, $r['estado'] );
				$saida          = self::tela( $sessao, $r['estado'] );
				$saida['ponte'] = self::ponte( $r['estado'], $campo['chave'], null );
				return rest_ensure_response( $saida );
			}
			return self::recusa( 'recusado', $r['erro'], 400 );
		}

		$r = Leticia_Roteiro::responder(
			$estado,
			$campo['chave'],
			'',
			array(
				'valor'    => implode( ', ', wp_list_pluck( $arquivos, 'nome' ) ),
				'arquivos' => $arquivos,
				'link'     => $link,
				'pendente' => $pendente,
			)
		);

		if ( '' !== $r['erro'] ) {
			return self::recusa( 'recusado', $r['erro'], 400 );
		}

		unset( $gaveta[ $campo['chave'] ] );
		self::guardar_gaveta( $sessao, $gaveta );

		Leticia_Rascunho::salvar( $sessao, $r['estado'], array( 'arquivos' => $arquivos ) );

		$saida          = self::tela( $sessao, $r['estado'] );
		$saida['ponte'] = self::ponte( $r['estado'], $campo['chave'], null );
		return rest_ensure_response( $saida );
	}

	// ------------------------------------------------------------ rascunho

	/**
	 * A decisão sobre o rascunho que ela escreveu.
	 *
	 *   acao = usar       grava o texto guardado no servidor
	 *   acao = ajustar    grava o texto que a pessoa editou (param texto)
	 *   acao = dispensar  segue sem texto aprovado — ou, se era pedido de
	 *                     ajuda, volta ao campo para a pessoa escrever
	 *
	 * Não chama o modelo. A reação que abre a próxima pergunta é escrita.
	 */
	public static function proposta( $req ) {
		$ctx = self::preparar( $req );
		if ( is_wp_error( $ctx ) ) {
			return self::do_erro( $ctx );
		}

		list( $sessao, $estado ) = array( $ctx['sessao'], $ctx['estado'] );

		$chave = (string) $req->get_param( 'campo' );
		$acao  = (string) $req->get_param( 'acao' );
		if ( ! in_array( $acao, array( 'usar', 'ajustar', 'dispensar' ), true ) ) {
			return self::recusa( 'acao_invalida', 'Não entendi o que fazer com a sugestão.', 400 );
		}

		// Segundo clique, ou a aba que ficou aberta: já foi decidido. A tela
		// de agora é a resposta, não um erro.
		if ( ! isset( $estado['propostas'][ $chave ] ) ) {
			return rest_ensure_response( self::tela( $sessao, $estado ) );
		}

		$r = Leticia_Roteiro::decidir_proposta( $estado, $chave, $acao, (string) $req->get_param( 'texto' ) );
		if ( '' !== $r['erro'] ) {
			return self::recusa( 'recusado', $r['erro'], 400 );
		}

		$estado     = $r['estado'];
		$volta      = 'ajuda' === $r['origem'] && 'dispensar' === $acao;
		$reacoes    = array( 'usar' => 'proposta-usada', 'ajustar' => 'proposta-ajustada', 'dispensar' => 'proposta-dispensada' );
		$ponte      = '';
		if ( ! $volta ) {
			$fala  = Leticia_Base::escolher( $reacoes[ $acao ], (int) $estado['semente'], Leticia_Roteiro::valores( $estado ) );
			$ponte = $fala ? trim( $fala['titulo'] . ' ' . $fala['detalhe'] ) : '';
			$estado['ultima_ponte'] = $ponte;
		}

		Leticia_Rascunho::salvar( $sessao, $estado, self::extra( $req ) );

		// Para o painel: quantos rascunhos são usados como vieram, ajustados ou
		// dispensados é a medida de se ela está escrevendo bem.
		do_action( 'leticia_proposta_decidida', $chave, $acao, $r['origem'] );
		Leticia_Registro::contar_uso( $sessao, 'rascunho_' . $chave . '_' . $acao );

		$saida          = self::tela( $sessao, $estado );
		$saida['ponte'] = $ponte;
		if ( $volta ) {
			$saida['permanece'] = true;
		}
		return rest_ensure_response( $saida );
	}

	// ----------------------------------------------------------------- voz

	/**
	 * A resposta falada.
	 *
	 * Devolve o texto para a pessoa conferir, e não grava nada: o estado do
	 * briefing sai daqui igual entrou. Confirmado, o texto volta por
	 * `/responder` como se tivesse sido digitado.
	 *
	 * O áudio vem no corpo cru, como o pedaço de arquivo, e token e campo na
	 * URL. Base64 dentro de JSON seria um terço a mais de tráfego no 4G.
	 */
	public static function ouvir( $req ) {
		$ctx = self::preparar( $req );
		if ( is_wp_error( $ctx ) ) {
			return self::do_erro( $ctx );
		}

		$campo = Leticia_Campos::por_chave( (string) $req->get_param( 'campo' ) );
		if ( ! $campo || ! Leticia_Voz::aceita_campo( $campo ) ) {
			return self::recusa( 'campo_invalido', 'Este campo não se responde por voz.', 400 );
		}

		$pode = Leticia_Limites::pode_ouvir( $ctx['sessao'] );
		if ( 'desligada' === $pode ) {
			// 503 e código próprio: o navegador esconde o microfone pelo resto
			// da visita, em vez de oferecer de novo o que não vai funcionar.
			return self::recusa( 'voz_indisponivel', 'A resposta por voz não está disponível agora. Pode escrever aqui?', 503 );
		}
		if ( 'ritmo' === $pode ) {
			return self::recusa( 'voz_ritmo', 'Foram muitas gravações seguidas. Pode escrever esta resposta?', 429 );
		}

		$ouvido = Leticia_Voz::interpretar( $campo, $req->get_body(), self::contexto( $ctx['estado'] ), $ctx['sessao'] );
		if ( is_wp_error( $ouvido ) ) {
			return $ouvido;
		}
		Leticia_Registro::contar_uso( $ctx['sessao'], 'voz' );

		return rest_ensure_response(
			array(
				'token' => Leticia_Rascunho::assinar( $ctx['sessao'] ),
				'voz'   => $ouvido,
			)
		);
	}

	// --------------------------------------------------------------- envio

	/**
	 * Fecha o briefing.
	 *
	 * Grava primeiro, avisa depois — e nunca devolve erro por causa do aviso.
	 * Se o e-mail falhar, o briefing já está no banco, a falha entrou na fila e
	 * a tela continua dizendo "recebido", porque foi recebido.
	 */
	public static function enviar( $req ) {
		$ctx = self::preparar( $req, true );
		if ( is_wp_error( $ctx ) ) {
			return self::do_erro( $ctx );
		}

		list( $sessao, $estado ) = array( $ctx['sessao'], $ctx['estado'] );

		/*
		 * O briefing já enviado é a primeira coisa conferida, antes do aceite e
		 * antes da completude.
		 *
		 * Não é otimização: `Leticia_Rascunho::carregar()` devolve null para um
		 * briefing enviado, de propósito — retomar um enviado faria a pessoa
		 * preencher de novo o que a equipe já recebeu. Só que isso deixa o
		 * segundo clique em "enviar", e o recarregar da página de sucesso, com
		 * um estado vazio: sem esta checagem aqui em cima, os dois respondiam
		 * "ainda falta campo obrigatório" para quem tinha acabado de enviar
		 * tudo.
		 */
		$ja = Leticia_Registro::briefing( $sessao );
		if ( $ja && (int) $ja['enviado_em'] > 0 ) {
			$guardado = Leticia_Roteiro::sanear(
				array( 'respostas' => $ja['respostas'], 'enviado' => true )
			);
			$saida          = self::tela( $sessao, $guardado );
			$saida['anexo'] = Leticia_Entrega::link_anexo( $sessao, $guardado, $ja['pagina'] );
			return rest_ensure_response( $saida );
		}

		if ( ! $req->get_param( 'consentimento' ) ) {
			return self::recusa( 'sem_aceite', 'Falta marcar a caixa de aceite logo abaixo.', 400 );
		}

		if ( ! Leticia_Roteiro::pode_enviar( $estado ) ) {
			$falta = Leticia_Roteiro::proximo( $estado );
			return self::recusa(
				'incompleto',
				$falta ? 'Ainda falta o campo "' . $falta['rotulo'] . '".' : 'Ainda falta responder uma pergunta obrigatória.',
				400
			);
		}

		$estado = Leticia_Roteiro::marcar_enviado( $estado );
		Leticia_Registro::salvar( $sessao, $estado, self::extra( $req ) );

		$entrega = Leticia_Entrega::enviar( $sessao, $estado, self::extra( $req ) );

		$saida            = self::tela( $sessao, $estado );
		$saida['avisada'] = $entrega['ok'];
		$saida['anexo']   = Leticia_Entrega::link_anexo( $sessao, $estado, self::extra( $req )['pagina'] );

		return rest_ensure_response( $saida );
	}

	/** "Não é você? Começar do zero." */
	public static function descartar( $req ) {
		$ctx = self::preparar( $req );
		if ( is_wp_error( $ctx ) ) {
			return self::do_erro( $ctx );
		}

		Leticia_Rascunho::descartar( $ctx['sessao'] );
		delete_transient( self::PREFIXO_GAVETA . $ctx['sessao'] );

		$sessao = Leticia_Rascunho::nova_sessao();
		return rest_ensure_response( self::tela( $sessao, Leticia_Roteiro::novo( Leticia_Rascunho::semente( $sessao ) ) ) );
	}

	// --------------------------------------------------------------- anexo

	/**
	 * Abre o link de "mandar a logo depois".
	 *
	 * Não devolve o briefing: devolve o campo que falta e o mínimo para a tela
	 * chamar a pessoa pelo nome. Quem tem o link não precisa ver as outras
	 * respostas para mandar um arquivo.
	 */
	public static function anexo_abrir( $req ) {
		if ( ! Leticia_Limites::pode_chamar() ) {
			return self::recusa( 'ritmo', 'Espere um instante e tente de novo, por favor.', 429 );
		}

		$aberto = Leticia_Anexo::abrir( (string) $req->get_param( 'anexo' ) );
		if ( is_wp_error( $aberto ) ) {
			return self::do_erro( $aberto, 403 );
		}

		return rest_ensure_response( self::tela_anexo( $aberto ) );
	}

	/** Fecha a pendência com o que subiu, e avisa a equipe. */
	public static function anexo_concluir( $req ) {
		$token  = (string) $req->get_param( 'anexo' );
		$aberto = Leticia_Anexo::abrir( $token );
		if ( is_wp_error( $aberto ) ) {
			return self::do_erro( $aberto, 403 );
		}

		$chave = (string) $req->get_param( 'campo' );
		if ( ! in_array( $chave, $aberto['pendentes'], true ) ) {
			// Segundo clique, ou a aba que ficou aberta: já chegou, e a
			// resposta é a tela de "chegou", não um erro.
			return rest_ensure_response( self::tela_anexo( $aberto, true ) );
		}

		$sessao   = $aberto['sessao'];
		$gaveta   = self::gaveta( $sessao );
		$arquivos = isset( $gaveta[ $chave ] ) ? $gaveta[ $chave ] : array();

		$estado = Leticia_Anexo::fechar( $sessao, $aberto['linha'], $chave, $arquivos );
		if ( is_wp_error( $estado ) ) {
			return self::do_erro( $estado, 400 );
		}

		unset( $gaveta[ $chave ] );
		self::guardar_gaveta( $sessao, $gaveta );

		Leticia_Entrega::avisar_anexo( $sessao, $estado, $chave, $aberto['linha']['pagina'] );

		return rest_ensure_response( self::tela_anexo( Leticia_Anexo::abrir( $token ), true ) );
	}

	/**
	 * A tela do link de anexo: o campo pendente, ou o "chegou".
	 *
	 * Mesmo desenho do briefing — pergunta, detalhe e área de arquivo — para a
	 * pessoa reconhecer onde está. Só o texto é outro.
	 */
	private static function tela_anexo( array $aberto, $chegou = false ) {
		$respostas = $aberto['linha']['respostas'];
		$nome      = isset( $respostas['responsavel']['valor'] ) ? Leticia_Base::primeiro_nome( $respostas['responsavel']['valor'] ) : '';
		$empresa   = isset( $respostas['empresa']['valor'] ) ? $respostas['empresa']['valor'] : '';
		$chave     = $aberto['pendentes'] ? $aberto['pendentes'][0] : '';
		$campo     = '' !== $chave ? Leticia_Campos::por_chave( $chave ) : null;

		$saida = array(
			'fase'    => $campo ? 'anexo' : 'anexo-fim',
			'chegou'  => $chegou,
			'nome'    => $nome,
			'empresa' => $empresa,
			'campo'   => null,
		);

		if ( ! $campo ) {
			// Com o domínio ainda pendente, as 72 horas não começam: dizer que
			// começaram seria prometer um prazo que a equipe não vai cumprir.
			$texto    = ! empty( $aberto['linha']['pendencias'] ) ? 'anexo-recebido-com-pendencia' : 'anexo-recebido';
			$recebido = Leticia_Base::escolher( $texto, Leticia_Rascunho::semente( $aberto['sessao'] ), array( 'nome' => $nome, 'empresa' => $empresa ) );
			if ( $recebido ) {
				$saida['titulo']   = $recebido['titulo'];
				$saida['mensagem'] = $recebido['detalhe'];
			}
		}

		if ( $campo ) {
			$estado         = Leticia_Roteiro::sanear( array( 'respostas' => $respostas, 'semente' => Leticia_Rascunho::semente( $aberto['sessao'] ) ) );
			$publico        = self::campo_publico( $campo, $estado );
			$valores        = array(
				'nome'     => $nome,
				'empresa'  => $empresa,
				'pendente' => isset( $campo['chamado'] ) ? $campo['chamado'] : mb_strtolower( $campo['rotulo'], 'UTF-8' ),
			);
			$pergunta       = Leticia_Base::escolher( 'anexo-pergunta', $estado['semente'], $valores );

			if ( null !== $pergunta ) {
				$publico['pergunta'] = $pergunta['titulo'];
				$publico['detalhe']  = $pergunta['detalhe'];
			}
			// Por aqui não existe "mando depois": o link já é o depois.
			$publico['pode_ficar_pendente'] = false;
			$saida['campo']                 = $publico;
		}

		return $saida;
	}

	// --------------------------------------------------------------- saúde

	public static function saude( $req ) {
		$pronta = Leticia_Config::pode_atender();

		return rest_ensure_response(
			array(
				'ok'         => $pronta,
				'versao'     => LETICIA_VERSAO,
				// "Coleta" e "conversa" são estados diferentes, e é a diferença
				// que importa para quem monitora: o briefing continua sendo
				// coletado mesmo com o modelo fora do ar.
				'coletando'  => true,
				'conversando' => Leticia_Config::pode_comentar() && ! Leticia_Limites::disjuntor_aberto() && Leticia_Modelos::algum_disponivel(),
				'restantes'  => Leticia_Limites::restantes_hoje(),
				// Para o monitor externo: qual modelo falhou na última checagem
				// e qual está pausado agora. Sem mensagem de erro — ela pode
				// trazer detalhe da conta, e a rota é pública.
				'modelos'    => array_map(
					function ( $m ) {
						return array( 'papel' => $m['papel'], 'checado_ok' => $m['checado_ok'], 'pausado' => $m['pausado_ate'] > 0 );
					},
					Leticia_Modelos::estado()
				),
			)
		);
	}

	// ----------------------------------------------------------- as guardas

	/**
	 * As guardas, num lugar só.
	 *
	 * Toda rota de sessão passa por aqui. Ficarem juntas é o que evita uma
	 * defesa existir num caminho e faltar no outro — foi assim que a LivIA
	 * descobriu que tinha dois lugares decidindo a mesma coisa.
	 *
	 * @return array|WP_Error
	 */
	private static function preparar( $req, $aceita_enviado = false ) {
		$sessao = Leticia_Rascunho::conferir( (string) $req->get_param( 'token' ) );
		if ( is_wp_error( $sessao ) ) {
			return $sessao;
		}

		if ( ! Leticia_Limites::pode_chamar( $sessao ) ) {
			return new WP_Error( 'ritmo', 'Espere um instante e tente de novo, por favor.' );
		}

		$estado = Leticia_Rascunho::carregar( $sessao );

		if ( ! $estado && ! $aceita_enviado ) {
			/*
			 * Briefing enviado não aceita mais resposta.
			 *
			 * `carregar()` devolve null para ele, e sem esta checagem o null
			 * virava "briefing novo": a segunda aba, que ficou aberta no campo
			 * 9 enquanto a primeira enviava, gravava por cima um estado com uma
			 * resposta só — e o briefing que a equipe tinha acabado de receber
			 * ficava vazio no banco.
			 */
			$linha = Leticia_Registro::briefing( $sessao );
			if ( $linha && (int) $linha['enviado_em'] > 0 ) {
				return new WP_Error( 'ja_enviado', 'Este briefing já foi enviado e está com a equipe. Se quiser mudar alguma coisa, é só falar com eles pelo WhatsApp.' );
			}
		}

		if ( ! $estado ) {
			// Sessão sem rascunho é briefing novo, não é erro: o token pode ser
			// legítimo e o rascunho ter expirado ou sido descartado.
			$estado = Leticia_Roteiro::novo( Leticia_Rascunho::semente( $sessao ) );
		}

		return array( 'sessao' => $sessao, 'estado' => $estado );
	}

	/**
	 * As guardas das rotas de arquivo, que servem a dois donos.
	 *
	 * No briefing, é o token de rascunho de sempre. No link de "mandar a logo
	 * depois", é o token de anexo — e aí só vale para briefing enviado, só
	 * enquanto houver campo pendente e só para esse campo.
	 *
	 * @return array|WP_Error
	 */
	private static function preparar_arquivo( $req ) {
		$anexo = (string) $req->get_param( 'anexo' );
		if ( '' === $anexo ) {
			return self::preparar( $req );
		}

		$aberto = Leticia_Anexo::abrir( $anexo );
		if ( is_wp_error( $aberto ) ) {
			return $aberto;
		}
		if ( ! Leticia_Limites::pode_chamar( $aberto['sessao'] ) ) {
			return new WP_Error( 'ritmo', 'Espere um instante e tente de novo, por favor.' );
		}
		if ( ! $aberto['pendentes'] ) {
			return new WP_Error( 'anexo_nada', 'Esse arquivo já chegou na equipe.' );
		}

		$campo = $req->get_param( 'campo' );
		if ( null !== $campo && ! in_array( (string) $campo, $aberto['pendentes'], true ) ) {
			return new WP_Error( 'campo_invalido', 'Por este link, só dá para mandar o que ficou pendente.' );
		}

		return array( 'sessao' => $aberto['sessao'], 'estado' => null );
	}

	/**
	 * O estado da tela — o mesmo formato em toda rota.
	 *
	 * Um formato só significa um renderizador só do outro lado, e significa que
	 * responder, voltar, pular e retomar não podem deixar a tela em estados
	 * sutilmente diferentes.
	 */
	private static function tela( $sessao, array $estado, $forcar_campo = '' ) {
		$campo = '' !== $forcar_campo
			? Leticia_Campos::por_chave( $forcar_campo )
			: Leticia_Roteiro::proximo( $estado );

		$saida = array(
			'token'      => Leticia_Rascunho::assinar( $sessao ),
			'fase'       => $estado['enviado'] ? 'fim' : ( $campo ? 'conversa' : 'revisao' ),
			'progresso'  => Leticia_Roteiro::progresso( $estado ),
			'respostas'  => self::respostas_publicas( $estado ),
			'pendencias' => Leticia_Roteiro::pendencias( $estado ),
			// O texto de cada pendência, na voz do cliente. Vem pronto porque
			// qual campo pode ficar pendente é configuração, e o JS não sabe.
			'pendencias_texto' => Leticia_Entrega::pendencias_texto( $estado, true ),
			'campo'      => null,
			'falas'      => array(),
			'sugestao'   => '',
			'dela'       => null,
			// A reação à resposta anterior, dita antes da próxima pergunta.
			'ponte'      => '',
			'permanece'  => false,
			'eco'        => '',
			'erro'       => '',
			'conferido'  => false,
			'degradado'  => ! Leticia_Config::pode_comentar() || Leticia_Limites::disjuntor_aberto(),
			// Vai mesmo com o disjuntor aberto: a pausa é de minutos, e o que
			// a pessoa já respondeu passou pelo modelo.
			'aviso_ia'   => Leticia_Config::aviso_ia(),
			// A tela de apresentação, antes da primeira pergunta. Só existe
			// enquanto nada foi respondido: quem volta a um rascunho já passou
			// por ela.
			'apresentacao' => self::apresentacao( $sessao, $estado ),
			// Segundos por gravação; 0 é sem microfone. Quem decide se o
			// navegador consegue gravar é o navegador — aqui só se diz se há
			// quem ouça.
			'voz'        => Leticia_Config::voz_ligada() && ! Leticia_Limites::disjuntor_aberto() ? Leticia_Config::voz_segundos() : 0,
			// O rascunho esperando decisão. Quando existe, a tela mostra ele
			// antes da próxima pergunta.
			'proposta'   => '' === $forcar_campo ? self::proposta_publica( $estado ) : null,
		);

		if ( $campo ) {
			$saida['campo']    = self::campo_publico( $campo, $estado );
			$saida['falas']    = Leticia_Roteiro::falas_antes( $estado, $campo );
			// A tela usa estes dois, separados: a etapa vira uma etiqueta, e o
			// aviso uma nota embaixo da pergunta — em vez de dois parágrafos
			// empilhados em cima dela.
			$saida['etapa']    = Leticia_Roteiro::primeiro_da_secao( $estado, $campo )
				? array(
					'numero'   => (int) $campo['secao'],
					'total'    => count( Leticia_Campos::SECOES ),
					'nome'     => Leticia_Campos::SECOES[ $campo['secao'] ],
					'abertura' => Leticia_Roteiro::abertura( $estado, $campo ),
				)
				: null;
			$saida['aviso']    = Leticia_Roteiro::aviso( $estado, $campo );
			$saida['sugestao'] = Leticia_Roteiro::sugestao( $estado, $campo );
			// A tela pede a lista sugerida depois de mostrar a pergunta: a
			// chamada ao modelo não atrasa a troca de campo.
			$saida['sugerir']  = '' === $forcar_campo && self::pode_sugerir( $sessao, $estado, $campo ) ? $campo['chave'] : '';

			// Dois opcionais na mesma tela. Só no caminho de ida: quem volta
			// para corrigir um deles corrige só aquele.
			$saida['junto'] = null;
			if ( '' === $forcar_campo && ! empty( $campo['junto'] ) && ! Leticia_Roteiro::resolvido( $estado, $campo['junto'] ) ) {
				$par = Leticia_Campos::por_chave( $campo['junto'] );
				if ( $par ) {
					$saida['junto'] = self::campo_publico( $par, $estado );
					$grupo          = Leticia_Base::escolher( 'pergunta-' . $campo['chave'] . '-junto', (int) $estado['semente'], Leticia_Roteiro::valores( $estado ) );
					if ( $grupo ) {
						$saida['campo']['pergunta'] = $grupo['titulo'];
						$saida['campo']['detalhe']  = $grupo['detalhe'];
					}
				}
			}
		} else {
			$saida['consentimento'] = Leticia_Config::consentimento();
			$fecho                  = Leticia_Base::escolher( 'abertura-revisao', (int) $estado['semente'], Leticia_Roteiro::valores( $estado ) );
			if ( $fecho && ! $estado['enviado'] ) {
				$saida['falas'] = array( trim( $fecho['titulo'] . ' ' . $fecho['detalhe'] ) );
			}
		}

		return $saida;
	}

	/**
	 * A apresentação dela: a abertura da primeira etapa, em título e detalhe.
	 *
	 * É o mesmo texto que a linha de comando mostra antes da primeira
	 * pergunta; na tela ele ganha uma página própria, com o aviso de IA junto,
	 * em vez de ocupar o espaço acima da pergunta.
	 */
	private static function apresentacao( $sessao, array $estado ) {
		if ( ! empty( $estado['enviado'] ) ) {
			return null;
		}
		// Veio do link da equipe e o cliente ainda não respondeu nada: é a
		// primeira vez dele aqui, mesmo com respostas — as da equipe.
		$preenchido = ! empty( $estado['respostas'] ) && Leticia_Links::so_preenchido( $sessao, $estado );
		if ( ! empty( $estado['respostas'] ) && ! $preenchido ) {
			return null;
		}
		$fala = Leticia_Base::escolher( $preenchido ? 'apresentacao-preenchida' : 'abertura-secao-1', (int) $estado['semente'], Leticia_Roteiro::valores( $estado ) );
		return $fala ? array( 'titulo' => $fala['titulo'], 'detalhe' => $fala['detalhe'] ) : null;
	}

	/**
	 * O campo, como o navegador precisa dele.
	 *
	 * A ajuda vai junto — é o "por que perguntamos" que o botão de interrogação
	 * abre. Ela viaja com o campo de propósito: abrir a explicação não pode
	 * custar uma ida ao servidor, e muito menos uma chamada de modelo.
	 */
	private static function campo_publico( array $campo, array $estado ) {
		$publico = array(
			'chave'       => $campo['chave'],
			'rotulo'      => $campo['rotulo'],
			'tipo'        => $campo['tipo'],
			'secao'       => $campo['secao'],
			'obrigatorio' => (bool) $campo['obrigatorio'],
			'dica'        => isset( $campo['dica'] ) ? $campo['dica'] : '',
			'pergunta'    => '',
			'detalhe'     => '',
			// Só o porquê. Com o exemplo junto, o balão do "?" virava um parágrafo
			// de oito linhas — o exemplo bom e o ruim ficam para a condução dela.
			'ajuda'       => $campo['ajuda']['porque'],
			'voz'         => Leticia_Voz::aceita_campo( $campo ),
			// A frase que oferece o microfone nos campos de conteúdo.
			'fala'        => isset( $campo['fala'] ) ? $campo['fala'] : '',
			// O nome curto, para quando dois campos dividem a tela.
			'rotulo_curto' => isset( $campo['rotulo_curto'] ) ? $campo['rotulo_curto'] : $campo['rotulo'],
		);

		$partes              = Leticia_Campos::pergunta_partes( $campo['chave'], $estado['semente'], Leticia_Roteiro::valores( $estado ) );
		$publico['pergunta'] = $partes['titulo'];
		$publico['detalhe']  = $partes['detalhe'];

		if ( 'escolha' === $campo['tipo'] ) {
			$publico['opcoes'] = $campo['opcoes'];
		}

		if ( 'arquivo' === $campo['tipo'] ) {
			$publico['aceita']       = $campo['aceita'];
			// O teto vai junto porque o motivo dele é visível: o arquivo vai por
			// e-mail. Descobrir o limite depois de esperar o upload é pior.
			$publico['aceita_texto'] = $campo['aceita_texto'] . ' · até ' . size_format( Leticia_Config::teto_arquivo() );
			$publico['multiplo']     = ! empty( $campo['multiplo'] );
			$publico['aceita_link']  = ! empty( $campo['aceita_link'] );
			$publico['foto']         = isset( $campo['foto'] ) ? $campo['foto'] : '';
			$publico['pode_ficar_pendente'] = ! empty( $campo['pode_ficar_pendente'] );
			$publico['pedaco']       = Leticia_Arquivos::PEDACO;
		}

		return $publico;
	}

	/** O rascunho, como a tela precisa dele. */
	private static function proposta_publica( array $estado ) {
		$pendente = Leticia_Roteiro::proposta_pendente( $estado );
		if ( ! $pendente ) {
			return null;
		}
		$campo   = Leticia_Campos::por_chave( $pendente['chave'] );
		$p       = $pendente['proposta'];
		$valores = Leticia_Roteiro::valores( $estado );

		$intro = $p['intro'];
		if ( '' === $intro ) {
			$fala  = Leticia_Base::escolher( 'proposta-intro', (int) $estado['semente'], $valores );
			$intro = $fala ? trim( $fala['titulo'] . ' ' . $fala['detalhe'] ) : '';
		}
		$convite = Leticia_Base::escolher( 'proposta-convite', (int) $estado['semente'], $valores );

		return array(
			'campo'   => $pendente['chave'],
			'rotulo'  => $campo['rotulo'],
			'titulo'  => ! empty( $campo['propoe']['titulo'] ) ? $campo['propoe']['titulo'] : 'Uma sugestão de texto',
			'tipo'    => ! empty( $campo['propoe']['tipo'] ) ? $campo['propoe']['tipo'] : 'texto',
			'texto'   => $p['texto'],
			'intro'   => $intro,
			'convite' => $convite ? trim( $convite['titulo'] . ' ' . $convite['detalhe'] ) : '',
			'origem'  => $p['origem'],
		);
	}

	/** O que a tela mostra do histórico e da revisão. */
	private static function respostas_publicas( array $estado ) {
		$saida = array();

		foreach ( Leticia_Campos::todos() as $campo ) {
			$chave = $campo['chave'];
			if ( ! isset( $estado['respostas'][ $chave ] ) ) {
				continue;
			}
			$r     = $estado['respostas'][ $chave ];
			$valor = $r['valor'];
			// Botão aparece na revisão com o texto do botão — "Sim, podem usar",
			// e não o "sim" que vai gravado.
			if ( 'escolha' === $campo['tipo'] ) {
				foreach ( $campo['opcoes'] as $opcao ) {
					if ( $opcao['valor'] === $valor ) {
						$valor = $opcao['texto'];
					}
				}
			}

			$saida[ $chave ] = array(
				'rotulo'   => $campo['rotulo'],
				'valor'    => $valor,
				'bruto'    => $r['bruto'],
				'pulado'   => (bool) $r['pulado'],
				'pendente' => (bool) $r['pendente'],
				'link'     => $r['link'],
				'arquivos' => array_map( array( __CLASS__, 'arquivo_publico' ), (array) $r['arquivos'] ),
				'texto_site' => isset( $r['texto_site'] ) ? $r['texto_site'] : '',
			);
		}

		return $saida;
	}

	/**
	 * O arquivo, sem o caminho em disco.
	 *
	 * `pasta` e `disco` ficam de fora: são o que monta o caminho no servidor, e
	 * o navegador não tem o que fazer com eles.
	 */
	public static function arquivo_publico( array $meta ) {
		return array(
			'id'      => isset( $meta['id'] ) ? $meta['id'] : '',
			'nome'    => isset( $meta['nome'] ) ? $meta['nome'] : '',
			'tamanho' => isset( $meta['tamanho'] ) ? (int) $meta['tamanho'] : 0,
			'ext'     => isset( $meta['ext'] ) ? $meta['ext'] : '',
			'aviso'   => isset( $meta['aviso'] ) ? $meta['aviso'] : '',
		);
	}

	/**
	 * A ponte até a próxima pergunta.
	 *
	 * O comentário do modelo, quando houve um: ele leu a resposta e tem algo
	 * específico a dizer. Sem ele, a reação estática da base. Nunca os dois —
	 * duas reações seguidas à mesma resposta soam como eco.
	 */
	private static function ponte( array $estado, $chave, $consulta ) {
		// Pendência tem reação escrita, e ela carrega o que a pessoa precisa
		// saber — que o registro do domínio fica fora das 72 horas. O modelo
		// dizia "a equipe registra pra você" e parava aí.
		if ( ! empty( $estado['respostas'][ $chave ]['pendente'] ) || ! empty( $estado['respostas'][ $chave ]['negado'] ) ) {
			return Leticia_Roteiro::ponte( $estado, $chave );
		}
		if ( $consulta && ! empty( $consulta['comentario'] ) ) {
			return self::uma_frase( $consulta['comentario'] );
		}
		return Leticia_Roteiro::ponte( $estado, $chave );
	}

	/**
	 * A ponte do modelo, curta.
	 *
	 * A ponte é uma linha antes da pergunta. O prompt pede uma frase; quando o
	 * modelo escreve mais, fica a primeira — a tela não é lugar de parágrafo.
	 */
	private static function uma_frase( $texto ) {
		$texto = trim( (string) $texto );
		if ( mb_strlen( $texto, 'UTF-8' ) <= 140 ) {
			return $texto;
		}
		if ( preg_match( '/^.{20,140}?[.!?](?=\s|$)/us', $texto, $m ) ) {
			return $m[0];
		}
		return $texto;
	}

	private static function dela( $consulta ) {
		if ( ! $consulta ) {
			return null;
		}
		return array(
			'tipo'            => $consulta['tipo'],
			'comentario'      => $consulta['comentario'],
			'repergunta'      => $consulta['repergunta'],
			'resposta_duvida' => $consulta['resposta_duvida'],
		);
	}

	private static function contexto( array $estado ) {
		$pega = function ( $chave ) use ( $estado ) {
			return isset( $estado['respostas'][ $chave ]['valor'] ) ? $estado['respostas'][ $chave ]['valor'] : '';
		};
		return array(
			'nome'     => Leticia_Base::primeiro_nome( $pega( 'responsavel' ) ),
			'empresa'  => $pega( 'empresa' ),
			'ramo'     => $pega( 'ramo' ),
			'servicos' => $pega( 'servicos' ),
		);
	}

	/** O que vai para o rodapé do e-mail e para o registro. */
	private static function extra( $req ) {
		return array(
			'pagina' => esc_url_raw( (string) $req->get_param( 'pagina' ) ),
			'agente' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
			'ip'     => Leticia_Limites::ip(),
		);
	}

	private static function anotar_turno( $sessao, $chave, $bruto, $consulta, $segundos ) {
		Leticia_Registro::turno(
			$sessao,
			array(
				'campo'          => $chave,
				'papel'          => 'cliente',
				'texto'          => $bruto,
				'tipo'           => $consulta ? $consulta['tipo'] : '',
				'suficiente'     => $consulta && false === $consulta['suficiente'] ? 0 : 1,
				'bloqueio'       => $consulta && $consulta['bloqueio'] ? $consulta['bloqueio'] : '',
				'degradado'      => $consulta && $consulta['degradado'] ? 1 : 0,
				'ms'             => (int) round( $segundos * 1000 ),
			)
		);
	}

	// --------------------------------------------------------------- gaveta

	private static function gaveta( $sessao ) {
		$dados = get_transient( self::PREFIXO_GAVETA . $sessao );
		return is_array( $dados ) ? $dados : array();
	}

	private static function guardar_gaveta( $sessao, array $gaveta ) {
		set_transient( self::PREFIXO_GAVETA . $sessao, $gaveta, DAY_IN_SECONDS );
	}

	// ---------------------------------------------------------------- erros

	private static function recusa( $codigo, $mensagem, $status = 400 ) {
		return new WP_Error( $codigo, $mensagem, array( 'status' => $status ) );
	}

	private static function do_erro( $erro, $status = 403 ) {
		$codigo = $erro->get_error_code();
		if ( 'ritmo' === $codigo ) {
			$status = 429;
		}
		if ( 'ja_enviado' === $codigo ) {
			$status = 409;
		}
		return new WP_Error( $codigo, $erro->get_error_message(), array( 'status' => $status ) );
	}
}
