<?php
/**
 * A tela: o shortcode `[leticia]`.
 *
 *   [leticia]                      ocupa o corpo da página
 *   [leticia modo="bloco"]         fica dentro do fluxo, na coluna onde está
 *   [leticia formulario="site-express"]
 *
 * **Os arquivos só carregam na página que tem o shortcode.** Enfileirar em
 * `wp_enqueue_scripts` sem conferir põe 40 KB de CSS e JS em toda página do
 * site para servir uma.
 *
 * **Um shortcode por página.** O segundo é ignorado: dois briefings na mesma
 * tela é uma decisão que o cliente não deveria ter que tomar.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Tela {

	/** @var bool já imprimiu nesta requisição? */
	private static $impressa = false;

	public static function iniciar() {
		add_shortcode( 'leticia', array( __CLASS__, 'render' ) );
	}

	public static function render( $atributos = array() ) {
		if ( self::$impressa ) {
			return '';
		}

		$attrs = shortcode_atts(
			array(
				'modo'       => 'pagina',
				'formulario' => 'site-express',
				'tema'       => 'claro',
			),
			$atributos,
			'leticia'
		);

		$modo = 'bloco' === $attrs['modo'] ? 'bloco' : 'pagina';

		self::$impressa = true;
		self::silenciar_livia();
		self::carregar_arquivos( $modo );

		ob_start();
		?>
		<div class="leticia-raiz" id="lt-raiz" data-fase="conversa" data-modo="<?php echo esc_attr( $modo ); ?>">

			<header class="lt-topo">
				<div class="lt-topo-interno">
					<div class="lt-topo-linha">
						<p class="lt-marca"><?php echo self::simbolo(); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG fixo, sem dado de fora. ?><?php echo esc_html( Leticia_Config::nome() ); ?><span> · Site Express da JoinVix</span></p>
						<p class="lt-contagem" id="lt-contagem"></p>
					</div>
					<div class="lt-barra" id="lt-barra" role="progressbar" aria-valuemin="0" aria-valuemax="15" aria-valuenow="0" aria-label="Campos respondidos"></div>
				</div>
			</header>

			<main class="lt-corpo">
				<?php // Fora da coluna: abrir o histórico não pode empurrar a pergunta. ?>
				<div class="lt-historico-ancora">
					<div class="lt-ancora-linha">
						<button type="button" class="lt-abrir-historico" id="lt-abrir" hidden aria-expanded="false" aria-controls="lt-historico"></button>
						<button type="button" class="lt-abrir-continuar" id="lt-continuar" hidden aria-expanded="false" aria-controls="lt-continuar-painel">Continuar depois</button>
					</div>
					<div class="lt-historico" id="lt-historico" hidden></div>
					<div class="lt-continuar" id="lt-continuar-painel" hidden></div>
				</div>
				<div class="lt-coluna">
					<div id="lt-retomada"></div>
					<div id="lt-palco"></div>
				</div>
			</main>

			<?php // Fora do corpo: o corpo centraliza a conversa, e isto fica no pé da tela. ?>
			<footer class="lt-pe">
				<details class="lt-aviso-ia" id="lt-aviso-ia" hidden>
					<summary>Esta conversa usa inteligência artificial</summary>
					<p></p>
				</details>
				<p class="lt-rodape">
					<a href="https://joinvix.com.br" target="_blank" rel="noopener"><?php echo self::simbolo(); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG fixo. ?><b>JoinVix</b><span>Desenvolvimento e hospedagem</span></a>
				</p>
			</footer>

			<p class="lt-invisivel" aria-live="polite" id="lt-anuncio"></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * O símbolo da JoinVix, desenhado aqui e não carregado como imagem.
	 *
	 * Três polígonos, redesenhados do PNG da marca: em SVG ele fica nítido em
	 * qualquer tela e não custa uma requisição. As cores são as da marca e não
	 * seguem o acento configurável — logo não muda de cor com o tema.
	 */
	private static function simbolo() {
		return '<svg class="lt-simbolo" viewBox="6 16 193 183" aria-hidden="true" focusable="false">'
			. '<polygon class="lt-simbolo-claro" points="8,129 74,89 124,112 124,197"/>'
			. '<polygon class="lt-simbolo-escuro" points="124,60 195,18 196,155 124,112"/>'
			. '<polygon class="lt-simbolo-medio" points="124,112 196,156 124,197"/>'
			. '</svg>';
	}

	/**
	 * A cor da marca entra como variável, não como regra nova.
	 *
	 * É o único valor de configuração que acaba dentro de um atributo de
	 * estilo, e "cor" é um jeito clássico de tentar injetar outra coisa — daí
	 * o `sanitize_hex_color` na origem, em Leticia_Config.
	 */
	private static function carregar_arquivos( $modo ) {
		wp_enqueue_style( 'leticia', LETICIA_URL . 'public/leticia.css', array(), LETICIA_VERSAO );
		wp_enqueue_script( 'leticia', LETICIA_URL . 'public/leticia.js', array(), LETICIA_VERSAO, true );

		wp_add_inline_style(
			'leticia',
			sprintf( '.leticia-raiz{--lt-acento:%s;}', Leticia_Config::cor() )
		);

		wp_localize_script(
			'leticia',
			'LETICIA',
			array(
				'rotas' => esc_url_raw( rest_url( Leticia_Rest::NAMESPACE_API ) ),
				// O nonce não é exigido — as rotas são públicas —, mas mandá-lo
				// quando existe faz o WordPress reconhecer quem está logado, e
				// evita que um admin testando a tela apareça como anônimo.
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'modo'  => $modo,
				'nome'  => Leticia_Config::nome(),
				'pagina' => esc_url_raw( self::url_atual() ),
				'classico' => esc_url_raw( Leticia_Config::formulario_classico() ),
				'pedaco'   => Leticia_Arquivos::PEDACO,
			)
		);
	}

	/**
	 * Na página do briefing, a LivIA não aparece.
	 *
	 * Elas são produtos diferentes e normalmente nem se encontram — a LivIA
	 * entra por shortcode dela, em outra página. Mas se alguém puser as duas
	 * juntas, quem tira dúvida ali é a LetícIA: duas atendentes na mesma tela é
	 * o cliente tendo que escolher com qual falar.
	 */
	private static function silenciar_livia() {
		add_filter(
			'do_shortcode_tag',
			function ( $saida, $tag ) {
				return 'livia' === $tag ? '' : $saida;
			},
			10,
			2
		);
	}

	private static function url_atual() {
		if ( function_exists( 'get_permalink' ) && function_exists( 'get_queried_object_id' ) ) {
			$link = get_permalink( get_queried_object_id() );
			if ( $link ) {
				return $link;
			}
		}
		return home_url( add_query_arg( array() ) );
	}
}
