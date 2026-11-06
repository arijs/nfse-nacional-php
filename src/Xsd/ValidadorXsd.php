<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Xsd;

use Arijs\NfseNacional\Excecao\XsdExcecao;
use Arijs\NfseNacional\Leiaute;

/**
 * Validação contra os esquemas oficiais em `resources/xsd/<pasta>/`.
 *
 * A pasta é a do {@see Leiaute} (`1.01`, `1.01-nt009`); os arquivos dentro
 * dela levam o sufixo da versão do XML (`DPS_v1.01.xsd`) nos dois casos. Uma
 * string continua aceita: o valor de um Leiaute, ou o nome de uma pasta cujos
 * arquivos usam o mesmo sufixo.
 *
 * A Sefin valida o XML contra o mesmo XSD antes das regras de negócio (erro
 * E1235 — "Falha no esquema XML do DF-e"), então validar localmente antes de
 * enviar poupa uma ida à rede e devolve a linha exata do problema.
 */
final class ValidadorXsd
{
	public const VERSAO_PADRAO = '1.01';

	public function __construct(private readonly ?string $diretorioXsd = null)
	{
	}

	public static function diretorioPadrao(): string
	{
		return \dirname(__DIR__, 2).'/resources/xsd';
	}

	/** @param string $esquema nome do arquivo sem sufixo de versão: DPS, NFSe, pedRegEvento, evento */
	public function caminho(string $esquema, string|Leiaute $versao = self::VERSAO_PADRAO): string
	{
		$leiaute = $versao instanceof Leiaute ? $versao : Leiaute::tryFrom($versao);
		$pasta = $leiaute?->pastaXsd() ?? $versao;
		$sufixo = $leiaute?->versaoXml() ?? $versao;
		$dir = ($this->diretorioXsd ?? self::diretorioPadrao()).'/'.$pasta;
		$arquivo = "{$dir}/{$esquema}_v{$sufixo}.xsd";
		if (!is_file($arquivo)) {
			throw new XsdExcecao("Esquema XSD não encontrado: {$arquivo}");
		}

		return $arquivo;
	}

	/**
	 * @return list<string> erros encontrados (vazio quando válido)
	 */
	public function validar(string $xml, string $esquema = 'DPS', string|Leiaute $versao = self::VERSAO_PADRAO): array
	{
		$xsd = $this->caminho($esquema, $versao);

		$anterior = libxml_use_internal_errors(true);
		libxml_clear_errors();
		try {
			$dom = new \DOMDocument();
			$dom->preserveWhiteSpace = false;
			if (!$dom->loadXML($xml, \LIBXML_NONET)) {
				return array_merge(['XML mal formado.'], self::coletarErros());
			}
			if ($dom->schemaValidate($xsd, \LIBXML_SCHEMA_CREATE)) {
				return [];
			}

			return self::coletarErros();
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($anterior);
		}
	}

	public function validarOuLancar(string $xml, string $esquema = 'DPS', string|Leiaute $versao = self::VERSAO_PADRAO): void
	{
		$erros = $this->validar($xml, $esquema, $versao);
		if ([] !== $erros) {
			$nome = $versao instanceof Leiaute ? $versao->value : $versao;
			throw new XsdExcecao("XML inválido contra {$esquema} (leiaute {$nome}):\n - ".implode("\n - ", $erros));
		}
	}

	/** @return list<string> */
	private static function coletarErros(): array
	{
		$erros = [];
		foreach (libxml_get_errors() as $e) {
			$erros[] = sprintf('linha %d: %s', $e->line, trim($e->message));
		}

		return $erros;
	}
}
