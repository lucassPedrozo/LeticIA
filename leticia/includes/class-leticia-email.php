<?php
/**
 * O desenho dos e-mails.
 *
 * O primeiro formato era o texto corrido do formulário do Elementor: quinze
 * linhas "Pergunta longa: resposta", com o que importava misturado ao rodapé
 * técnico. Este separa o que a equipe lê em três passadas:
 *
 *   1. o que segura o prazo (em destaque, no topo);
 *   2. as respostas, em blocos — contato, negócio, arquivos;
 *   3. o técnico (IP, agente, id), pequeno e no fim.
 *
 * HTML simples e com estilo em linha, em tabelas: é o que Gmail, Outlook e
 * celular desenham igual. Nada de imagem, fonte externa ou CSS em <style> —
 * o Gmail descarta e o Outlook ignora.
 *
 * Tudo que vem do cliente passa por esc_html aqui dentro. Quem monta o e-mail
 * entrega texto puro; só esta classe escreve marcação.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Email {

	const COR_TEXTO  = '#16181D';
	const COR_SUAVE  = '#6B7280';
	const COR_BORDA  = '#E7E8EC';
	const COR_FUNDO  = '#F6F7F9';
	const COR_ACENTO = '#1F5EFF';

	const TONS = array(
		'atencao' => array( '#FFF7ED', '#B45309' ),
		'bom'     => array( '#ECFDF5', '#047857' ),
		'nota'    => array( '#F1F5FF', '#1F5EFF' ),
	);

	/** O documento inteiro, com a coluna de 640 px centralizada. */
	public static function documento( $titulo, $subtitulo, array $blocos ) {
		$html  = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>';
		$html .= '<body style="margin:0;padding:0;background:' . self::COR_FUNDO . ';">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::COR_FUNDO . ';"><tr><td align="center" style="padding:24px 12px;">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#FFFFFF;border:1px solid ' . self::COR_BORDA . ';border-radius:12px;font-family:Arial,Helvetica,sans-serif;color:' . self::COR_TEXTO . ';">';
		$html .= '<tr><td style="padding:24px 28px 8px;">';
		$html .= '<div style="font-size:20px;line-height:1.3;font-weight:bold;">' . esc_html( $titulo ) . '</div>';
		if ( '' !== $subtitulo ) {
			$html .= '<div style="font-size:14px;line-height:1.5;color:' . self::COR_SUAVE . ';margin-top:4px;">' . esc_html( $subtitulo ) . '</div>';
		}
		$html .= '</td></tr>';
		foreach ( $blocos as $bloco ) {
			if ( '' !== $bloco ) {
				$html .= '<tr><td style="padding:12px 28px;">' . $bloco . '</td></tr>';
			}
		}
		$html .= '<tr><td style="padding:8px 28px 24px;"></td></tr>';
		$html .= '</table></td></tr></table></body></html>';
		return $html;
	}

	/**
	 * Uma caixa de destaque: o que segura o prazo, ou a confirmação de que não
	 * segura mais nada.
	 *
	 * @param string[] $itens
	 */
	public static function aviso( $tom, $titulo, array $itens = array(), $rodape = '' ) {
		$cores = isset( self::TONS[ $tom ] ) ? self::TONS[ $tom ] : self::TONS['nota'];
		$html  = '<div style="background:' . $cores[0] . ';border-left:4px solid ' . $cores[1] . ';border-radius:8px;padding:12px 16px;">';
		$html .= '<div style="font-size:15px;font-weight:bold;color:' . $cores[1] . ';">' . esc_html( $titulo ) . '</div>';
		if ( $itens ) {
			$html .= '<ul style="margin:8px 0 0;padding-left:18px;font-size:14px;line-height:1.55;">';
			foreach ( $itens as $item ) {
				$html .= '<li style="margin:2px 0;">' . esc_html( $item ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( '' !== $rodape ) {
			$html .= '<div style="font-size:14px;line-height:1.55;margin-top:8px;">' . $rodape . '</div>';
		}
		return $html . '</div>';
	}

	/**
	 * Uma seção de respostas: título e linhas rótulo/valor.
	 *
	 * @param array $linhas rótulo => valor (texto puro; vazio vira "não informado")
	 */
	public static function secao( $titulo, array $linhas ) {
		$html  = '<div style="font-size:12px;font-weight:bold;letter-spacing:.06em;text-transform:uppercase;color:' . self::COR_SUAVE . ';margin:4px 0 6px;">' . esc_html( $titulo ) . '</div>';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid ' . self::COR_BORDA . ';">';
		foreach ( $linhas as $rotulo => $valor ) {
			$vazio = '' === trim( (string) $valor );
			$html .= '<tr>';
			$html .= '<td valign="top" style="width:36%;padding:9px 12px 9px 0;border-bottom:1px solid ' . self::COR_BORDA . ';font-size:13px;line-height:1.5;color:' . self::COR_SUAVE . ';">' . esc_html( $rotulo ) . '</td>';
			$html .= '<td valign="top" style="padding:9px 0;border-bottom:1px solid ' . self::COR_BORDA . ';font-size:14px;line-height:1.5;' . ( $vazio ? 'color:' . self::COR_SUAVE . ';font-style:italic;' : '' ) . '">'
				. ( $vazio ? 'não informado' : nl2br( esc_html( $valor ) ) ) . '</td>';
			$html .= '</tr>';
		}
		return $html . '</table>';
	}

	public static function paragrafo( $texto, $suave = false ) {
		return '<div style="font-size:15px;line-height:1.6;' . ( $suave ? 'color:' . self::COR_SUAVE . ';' : '' ) . '">' . esc_html( $texto ) . '</div>';
	}

	/** Um botão de verdade para o link principal, e o endereço escrito embaixo para quem copiar. */
	public static function botao( $texto, $url ) {
		return '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:' . self::COR_ACENTO . ';color:#FFFFFF;text-decoration:none;font-size:14px;font-weight:bold;padding:10px 16px;border-radius:8px;">' . esc_html( $texto ) . '</a>'
			. '<div style="font-size:12px;line-height:1.5;color:' . self::COR_SUAVE . ';margin-top:6px;word-break:break-all;">' . esc_html( $url ) . '</div>';
	}

	/** O técnico: pequeno, cinza e no fim. */
	public static function rodape( array $pares ) {
		$partes = array();
		foreach ( $pares as $rotulo => $valor ) {
			if ( '' === (string) $valor ) {
				continue;
			}
			$conteudo = preg_match( '#^https?://#', (string) $valor )
				? '<a href="' . esc_url( $valor ) . '" style="color:' . self::COR_SUAVE . ';">' . esc_html( $valor ) . '</a>'
				: esc_html( $valor );
			$partes[] = '<strong>' . esc_html( $rotulo ) . ':</strong> ' . $conteudo;
		}
		return '<div style="border-top:1px solid ' . self::COR_BORDA . ';padding-top:12px;font-size:12px;line-height:1.7;color:' . self::COR_SUAVE . ';word-break:break-word;">' . implode( '<br>', $partes ) . '</div>';
	}

	/**
	 * O e-mail em texto, para o terminal e para ler nos testes.
	 *
	 * Não é o que sai para a equipe; é uma leitura do HTML sem marcação.
	 */
	public static function texto( $html ) {
		$texto = preg_replace( '#<(br|/div|/tr|/li|/ul)[^>]*>#i', "\n", (string) $html );
		$texto = preg_replace( '#<li[^>]*>#i', '- ', $texto );
		$texto = preg_replace( '#</td>#i', '  ', $texto );
		$texto = html_entity_decode( strip_tags( $texto ), ENT_QUOTES, 'UTF-8' );
		$texto = preg_replace( "/[ \t]+\n/", "\n", $texto );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $texto ) );
	}
}
