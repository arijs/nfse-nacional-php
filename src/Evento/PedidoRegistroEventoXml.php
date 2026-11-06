<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Evento;

use Arijs\NfseNacional\Ambiente;
use Arijs\NfseNacional\Dps\DpsXml;
use Arijs\NfseNacional\Excecao\DadosExcecao;
use Arijs\NfseNacional\Util\Formato;

/**
 * XML do Pedido de Registro de Evento (pedRegEvento_v1.01.xsd).
 *
 * Estrutura: <pedRegEvento versao><infPedReg Id="PRE{chave}{tipo}">
 *   tpAmb, verAplic, dhEvento, CNPJAutor|CPFAutor, chNFSe, e{tipo}{...}
 * </infPedReg><Signature/></pedRegEvento>
 *
 * O Id tem 59 posições: "PRE" + chave de acesso (50) + código do evento (6).
 */
final class PedidoRegistroEventoXml
{
	public const CANCELAMENTO = '101101';

	/** Motivos de cancelamento (TSCodJustCanc). */
	public const MOTIVO_ERRO_NA_EMISSAO = 1;
	public const MOTIVO_SERVICO_NAO_PRESTADO = 2;
	public const MOTIVO_OUTROS = 9;

	/**
	 * Evento e101101 — Cancelamento de NFS-e.
	 *
	 * @param int $cMotivo 1 erro na emissão, 2 serviço não prestado, 9 outros
	 * @param string $xMotivo justificativa, 15 a 255 caracteres
	 */
	public static function cancelamento(
		Ambiente $ambiente,
		string $verAplic,
		string $cnpjOuCpfAutor,
		string $chaveAcesso,
		int $cMotivo,
		string $xMotivo,
		?\DateTimeInterface $dhEvento = null,
	): string {
		if (!\in_array($cMotivo, [self::MOTIVO_ERRO_NA_EMISSAO, self::MOTIVO_SERVICO_NAO_PRESTADO, self::MOTIVO_OUTROS], true)) {
			throw new DadosExcecao("Motivo de cancelamento inválido: {$cMotivo} (1, 2 ou 9).");
		}
		$xMotivo = Formato::texto($xMotivo, 255);
		if (mb_strlen($xMotivo) < 15) {
			throw new DadosExcecao('A justificativa do cancelamento precisa ter pelo menos 15 caracteres.');
		}

		return self::gerar($ambiente, $verAplic, $cnpjOuCpfAutor, $chaveAcesso, self::CANCELAMENTO, [
			'xDesc' => 'Cancelamento de NFS-e',
			'cMotivo' => (string) $cMotivo,
			'xMotivo' => $xMotivo,
		], $dhEvento);
	}

	/**
	 * Forma genérica: qualquer evento do leiaute, com o grupo específico já
	 * montado (chaves na ordem do XSD).
	 *
	 * @param array<string,mixed> $grupoEvento
	 */
	public static function gerar(
		Ambiente $ambiente,
		string $verAplic,
		string $cnpjOuCpfAutor,
		string $chaveAcesso,
		string $tipoEvento,
		array $grupoEvento,
		?\DateTimeInterface $dhEvento = null,
	): string {
		$chave = Formato::somenteDigitos($chaveAcesso);
		if (50 !== \strlen($chave)) {
			throw new DadosExcecao("Chave de acesso deve ter 50 dígitos, recebido '{$chaveAcesso}'.");
		}
		if (!preg_match('/^\d{6}$/', $tipoEvento)) {
			throw new DadosExcecao("Tipo de evento deve ter 6 dígitos, recebido '{$tipoEvento}'.");
		}
		$autor = Formato::somenteDigitos($cnpjOuCpfAutor);
		$tagAutor = match (\strlen($autor)) {
			14 => 'CNPJAutor',
			11 => 'CPFAutor',
			default => throw new DadosExcecao("Autor do evento deve ser CNPJ (14) ou CPF (11), recebido '{$cnpjOuCpfAutor}'."),
		};
		$dhEvento ??= new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));

		$dom = new \DOMDocument('1.0', 'UTF-8');
		$dom->preserveWhiteSpace = false;
		$raiz = $dom->createElementNS(DpsXml::NAMESPACE, 'pedRegEvento');
		$raiz->setAttribute('versao', DpsXml::VERSAO_LEIAUTE);
		$dom->appendChild($raiz);
		$inf = $dom->createElementNS(DpsXml::NAMESPACE, 'infPedReg');
		$inf->setAttribute('Id', 'PRE'.$chave.$tipoEvento);
		$raiz->appendChild($inf);

		$el = static function (string $nome, string $texto) use ($dom): \DOMElement {
			$e = $dom->createElementNS(DpsXml::NAMESPACE, $nome);
			$e->appendChild($dom->createTextNode($texto));

			return $e;
		};
		$inf->appendChild($el('tpAmb', (string) $ambiente->value));
		$inf->appendChild($el('verAplic', Formato::texto($verAplic, 20)));
		$inf->appendChild($el('dhEvento', Formato::dataHora($dhEvento)));
		$inf->appendChild($el($tagAutor, $autor));
		$inf->appendChild($el('chNFSe', $chave));
		$grupo = $dom->createElementNS(DpsXml::NAMESPACE, 'e'.$tipoEvento);
		$inf->appendChild($grupo);
		foreach ($grupoEvento as $nome => $valor) {
			if (null === $valor) {
				continue;
			}
			$grupo->appendChild($el($nome, \is_string($valor) ? $valor : (string) $valor));
		}

		$xml = $dom->saveXML();
		if (false === $xml) {
			throw new DadosExcecao('Falha ao serializar o pedido de registro de evento.');
		}

		return $xml;
	}
}
