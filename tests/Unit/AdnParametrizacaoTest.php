<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Tests\Unit;

use Arijs\NfseNacional\Adn\AdnParametrizacao;
use Arijs\NfseNacional\Ambiente;
use Arijs\NfseNacional\Configuracao;
use Arijs\NfseNacional\Nfse;
use Arijs\NfseNacional\Tests\Support\CertificadoTeste;
use Arijs\NfseNacional\Tests\Support\ClienteHttpFalso;
use PHPUnit\Framework\TestCase;

/** Os parâmetros municipais vivem no ADN, em /parametrizacao (não na Sefin). */
final class AdnParametrizacaoTest extends TestCase
{
	public function testRotasNoHostDoAdn(): void
	{
		$http = new ClienteHttpFalso();
		foreach (range(1, 7) as $_) {
			$http->responder(200, ['ok' => true]);
		}
		$p = new AdnParametrizacao(Ambiente::Producao, $http);

		$p->convenio('3550308');
		$p->historicoAliquotas('3550308', '010701001');
		$p->aliquota('3550308', '01.07.01.001', '2026-11-08');
		$p->regimesEspeciais('3550308', '010701001', '2026-11-08');
		$p->retencoes('3550308', '2026-11-08');
		$p->beneficio('3550308', '35503080100001', '2026-11-08');
		$r = $p->consultar('3550308/010701000/historicoaliquotas');

		self::assertSame([
			'https://adn.nfse.gov.br/parametrizacao/3550308/convenio',
			'https://adn.nfse.gov.br/parametrizacao/3550308/010701001/historicoaliquotas',
			'https://adn.nfse.gov.br/parametrizacao/3550308/01.07.01.001/2026-11-08/aliquota',
			'https://adn.nfse.gov.br/parametrizacao/3550308/010701001/2026-11-08/regimes_especiais',
			'https://adn.nfse.gov.br/parametrizacao/3550308/2026-11-08/retencoes',
			'https://adn.nfse.gov.br/parametrizacao/3550308/35503080100001/2026-11-08/beneficio',
			'https://adn.nfse.gov.br/parametrizacao/3550308/010701000/historicoaliquotas',
		], array_column($http->chamadas, 'url'));
		self::assertSame(['GET'], array_values(array_unique(array_column($http->chamadas, 'metodo'))));
		self::assertSame('parametrizacao', $r->operacao);
		self::assertTrue($r->sucesso());
	}

	public function testProducaoRestritaEUrlDoAdnDaConfiguracao(): void
	{
		$http = (new ClienteHttpFalso())->responder(404, '')->responder(200, []);
		(new AdnParametrizacao(Ambiente::Homologacao, $http))->convenio('3550308');
		self::assertSame('https://adn.producaorestrita.nfse.gov.br/parametrizacao/3550308/convenio', $http->chamadas[0]['url']);

		$nfse = new Nfse(new Configuracao(ambiente: Ambiente::Producao, certificado: CertificadoTeste::certificado(), urlAdn: 'https://proxy.local/adn/'), $http);
		$nfse->parametrizacao()->historicoAliquotas('3550308', '010701001');
		self::assertSame('https://proxy.local/adn/parametrizacao/3550308/010701001/historicoaliquotas', $http->chamadas[1]['url']);
	}
}
