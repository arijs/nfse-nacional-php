<?php declare(strict_types=1);

namespace Arijs\NfseNacional;

use Arijs\NfseNacional\Assinatura\PerfilAssinatura;
use Arijs\NfseNacional\Dps\DpsXml;
use Arijs\NfseNacional\Excecao\DadosExcecao;
use Arijs\NfseNacional\Sefin\RespostaSefin;

/**
 * Descobre, em homologação, qual perfil de assinatura a Sefin aceita: emite
 * uma DPS de teste com cada perfil e classifica a resposta.
 *
 * Roda uma vez na implantação (`nfse perfil dps.json`); o resultado vai para
 * NFSE_PERFIL_ASSINATURA e o código de produção fica sem tentativa e erro.
 * Cada tentativa usa um número de DPS diferente (nDPS, nDPS+1...), porque uma
 * DPS aceita ocuparia o número e a tentativa seguinte seria recusada por
 * duplicidade, não por assinatura. Recusa-se a rodar em produção: lá as
 * notas valem.
 *
 * @phpstan-type Resultado array{perfil:PerfilAssinatura,aceito:bool,assinatura_recusada:bool,resposta:RespostaSefin,nDPS:string}
 */
final class DescobertaPerfil
{
	public function __construct(private readonly Configuracao $config, private readonly ?\Closure $fabricaNfse = null)
	{
	}

	/**
	 * @param array<string,mixed> $infDps DPS de teste completa (sem tpAmb/dhEmi, que são preenchidos)
	 * @param list<PerfilAssinatura>|null $perfis ordem das tentativas; padrão: o configurado primeiro
	 *
	 * @return list<Resultado>
	 */
	public function executar(array $infDps, ?array $perfis = null): array
	{
		if (Ambiente::Homologacao !== $this->config->ambiente) {
			throw new DadosExcecao('A descoberta de perfil só roda em homologação (NFSE_AMBIENTE=2): em produção cada tentativa aceita seria uma nota real.');
		}
		$perfis ??= array_values(array_unique([$this->config->perfilAssinatura, ...PerfilAssinatura::cases()], \SORT_REGULAR));
		$nDps = ltrim(preg_replace('/\D+/', '', (string) ($infDps['nDPS'] ?? '')) ?? '', '0');
		if ('' === $nDps) {
			throw new DadosExcecao('Informe nDPS na DPS de teste; as tentativas usam nDPS, nDPS+1...');
		}

		$resultados = [];
		foreach ($perfis as $i => $perfil) {
			$tentativa = $infDps;
			$tentativa['nDPS'] = self::somar($nDps, $i);
			$nfse = $this->nfse($this->config->comPerfil($perfil)->comFallbackAssinatura(false));
			$resposta = $nfse->emitir($nfse->prepararDps($tentativa, $perfil));
			$resultados[] = [
				'perfil' => $perfil,
				'aceito' => $resposta->sucesso(),
				'assinatura_recusada' => $resposta->temErro(Nfse::ERRO_ASSINATURA),
				'resposta' => $resposta,
				'nDPS' => $tentativa['nDPS'],
			];
		}

		return $resultados;
	}

	/** @param list<Resultado> $resultados */
	public static function recomendacao(array $resultados): ?PerfilAssinatura
	{
		foreach ($resultados as $r) {
			if ($r['aceito']) {
				return $r['perfil'];
			}
		}
		// Nenhum aceito: se só um deles NÃO caiu em E0714, a assinatura dele
		// está certa e o problema é outro (dados da DPS). Vale recomendá-lo.
		$semErroDeAssinatura = array_values(array_filter($resultados, static fn(array $r) => !$r['assinatura_recusada']));

		return 1 === \count($semErroDeAssinatura) ? $semErroDeAssinatura[0]['perfil'] : null;
	}

	private static function somar(string $nDps, int $incremento): string
	{
		if (\strlen($nDps) > 15 || \PHP_INT_SIZE < 8) {
			throw new DadosExcecao('nDPS da DPS de teste precisa ter até 15 dígitos (e PHP 64 bits).');
		}

		return (string) ((int) $nDps + $incremento);
	}

	private function nfse(Configuracao $config): Nfse
	{
		return null !== $this->fabricaNfse ? ($this->fabricaNfse)($config) : new Nfse($config);
	}
}
